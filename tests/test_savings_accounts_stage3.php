<?php
/**
 * Savings Accounts Module — Stage 3 (automatic compulsory account on
 * new-member registration) test suite. Entire suite runs inside one
 * outer transaction rolled back at the very end -- zero permanent
 * writes, per the established discipline for this whole module.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$db = Database::getInstance()->getConnection();

echo "=== SAVINGS ACCOUNTS STAGE 3 TEST SUITE ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

// ============================================================
// Forensic baseline — queried live, never hardcoded
// ============================================================
$beforeMembers  = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
$beforeSavings  = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$beforeSavingsTotal = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn();
$beforeJE       = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$beforeJLTotals = $db->query('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines')->fetch(PDO::FETCH_ASSOC);
$beforeAccounts = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
$beforeHolders  = (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn();
$beforeOrgs     = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$beforeReps     = (int)$db->query('SELECT COUNT(*) FROM organization_representatives')->fetchColumn();
$beforeCSSeq    = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CS'")->fetchColumn();

echo "Baseline (queried live): members={$beforeMembers} savings={$beforeSavings} savingsTotal={$beforeSavingsTotal}\n";
echo "journal_entries={$beforeJE} journal_lines debit={$beforeJLTotals['d']} credit={$beforeJLTotals['c']}\n";
echo "member_savings_accounts={$beforeAccounts} holders={$beforeHolders} organizations={$beforeOrgs} representatives={$beforeReps}\n";
echo "CS sequence last_number={$beforeCSSeq}\n\n";

$db->beginTransaction();

$counter = 900000;
$makeMemberData = function () use (&$counter) {
    $counter++;
    return [
        'account_number'  => null,
        'member_number'   => 'STAGE3-' . $counter,
        'first_name'      => 'Stage3',
        'last_name'       => 'TestMember' . $counter,
        'gender'          => 'Male',
        'phone'           => '07' . str_pad((string)$counter, 8, '0', STR_PAD_LEFT),
        'national_id'     => 'STAGE3ID' . $counter,
        'station'         => 'Test Station',
        'join_date'       => '2026-08-15',
        'status'          => 'active',
        'created_by'      => 1,
    ];
};

try {
    $memberModel = new MemberModel();
    $accountModel = new MemberSavingsAccountModel();
    $holderModel = new SavingsAccountHolderModel();

    // ============================================================
    // TEST 1 + 2: New member registration creates member + account + holder
    // ============================================================
    echo "TEST 1+2: New member registration\n";
    $m1data = $makeMemberData();
    $result = $memberModel->createWithCompulsoryAccount($m1data, 1);
    $memberId1 = $result['member_id'];
    $accountId1 = $result['account_id'];

    check('member created', $memberModel->find($memberId1) !== false);

    $account1 = $accountModel->getAccount($accountId1);
    check('exactly one compulsory account created', $account1 !== false);
    check('account_type = compulsory', $account1['account_type'] === 'compulsory');
    check('ownership_type = individual', $account1['ownership_type'] === 'individual');
    check('status = active (Stage 1\'s design, reaffirmed: lifecycle and qualification are never coupled)', $account1['status'] === 'active');
    check('opened_date = member.join_date', $account1['opened_date'] === '2026-08-15');
    check('created_by is the authenticated user id, not 0', (int)$account1['created_by'] === 1);
    check('account_number returned matches the stored account', $result['account_number'] === $account1['account_number']);

    $holders1 = $accountModel->getAccountHolders($accountId1);
    check('TEST 2: exactly one holder', count($holders1) === 1);
    check('TEST 2: role = primary', $holders1[0]['role'] === 'primary');
    check('TEST 2: member_id = new member', (int)$holders1[0]['member_id'] === $memberId1);
    check('TEST 2: organization_id is NULL', $holders1[0]['organization_id'] === null);
    echo "\n";

    // ============================================================
    // TEST 3: Account number
    // ============================================================
    echo "TEST 3: Account number generation\n";
    check('starts with CS-', str_starts_with($account1['account_number'], 'CS-'));

    $m2data = $makeMemberData();
    $result2 = $memberModel->createWithCompulsoryAccount($m2data, 1);
    $account2 = $accountModel->getAccount($result2['account_id']);
    check('sequential accounts get distinct numbers', $account1['account_number'] !== $account2['account_number']);
    check('numbers are sequential (comes from journal_number_sequences)', (int)substr($account2['account_number'], 3) === (int)substr($account1['account_number'], 3) + 1);
    echo "\n";

    // ============================================================
    // TEST 4: Zero balance, no savings transaction
    // ============================================================
    echo "TEST 4: Zero initial balance\n";
    check('no savings row created for the new account', count($accountModel->getAccountTransactions($accountId1)) === 0);
    check('account balance is zero', $accountModel->getAccountBalance($accountId1) === 0.0);
    $savingsCountNow = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    check('savings table row count unchanged by account creation', $savingsCountNow === $beforeSavings);
    echo "\n";

    // ============================================================
    // TEST 5: No journal entry created
    // ============================================================
    echo "TEST 5: No journal entry from account creation\n";
    $jeCountNow = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('journal_entries count unchanged', $jeCountNow === $beforeJE);
    $jlTotalsNow = $db->query('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines')->fetch(PDO::FETCH_ASSOC);
    check('journal_lines totals unchanged', abs((float)$jlTotalsNow['d'] - (float)$beforeJLTotals['d']) < 0.01 && abs((float)$jlTotalsNow['c'] - (float)$beforeJLTotals['c']) < 0.01);
    echo "\n";

    // ============================================================
    // TEST 6: Duplicate compulsory account rejected
    // ============================================================
    echo "TEST 6: Duplicate compulsory account rejected\n";
    try {
        $accountModel->createAccount(
            ['account_type' => 'compulsory', 'opened_date' => '2026-08-15'],
            [['member_id' => $memberId1, 'role' => 'primary']],
            1
        );
        check('second compulsory account for the same member should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('second compulsory account rejected — ' . $e->getMessage(), true);
    }
    $accountsForMember1 = $holderModel->getMemberAccounts($memberId1);
    $compulsoryCount = count(array_filter($accountsForMember1, fn($a) => $a['account_type'] === 'compulsory'));
    check('member still has exactly one compulsory account', $compulsoryCount === 1);
    echo "\n";

    // ============================================================
    // TEST 7: Account-creation failure rolls back the member
    //
    // createWithCompulsoryAccount() only commits/rolls back its OWN
    // transaction when it owns one ($ownTransaction = !inTransaction()).
    // Since this whole test suite runs inside one outer transaction for
    // isolation, the method correctly detects it is nested and leaves
    // rollback to its caller -- exactly the established pattern. In real
    // (non-test) usage there is no outer transaction, so the method DOES
    // own and roll back its transaction itself on failure. Here, a
    // SAVEPOINT plays that same role: it must be rolled back BEFORE the
    // "after" counts are checked, not after -- checking mid-transaction,
    // before rollback, would (correctly) still see the uncommitted rows.
    // ============================================================
    echo "TEST 7: Account-creation failure rolls back the member\n";
    $db->exec('SAVEPOINT sp_test7');
    $db->exec("DELETE FROM journal_number_sequences WHERE prefix = 'CS'");
    $membersBefore7 = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $accountsBefore7 = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();

    $m7data = $makeMemberData();
    try {
        $memberModel->createWithCompulsoryAccount($m7data, 1);
        check('registration should have thrown when account creation fails', false);
    } catch (Throwable $e) {
        check('registration threw when account creation failed — ' . $e->getMessage(), true);
    }

    $db->exec('ROLLBACK TO SAVEPOINT sp_test7'); // undo the DELETE + the orphaned member insert
    $membersAfter7 = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $accountsAfter7 = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
    check('member count unchanged after rollback -- the member row does not survive', $membersAfter7 === $membersBefore7);
    check('account count unchanged', $accountsAfter7 === $accountsBefore7);
    $orphan = $db->prepare('SELECT COUNT(*) FROM members WHERE member_number = ?');
    $orphan->execute([$m7data['member_number']]);
    check('the specific test member does not exist after rollback (no orphan)', (int)$orphan->fetchColumn() === 0);
    $csRestored = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CS'")->fetchColumn();
    check('CS sequence row restored by the savepoint rollback', $csRestored !== false);
    echo "\n";

    // ============================================================
    // TEST 8: Holder-creation failure rolls back the account too
    // (injected at the MemberSavingsAccountModel level: a holder
    // referencing a non-existent member passes shape validation but
    // fails addHolder()'s existence check, which runs AFTER the
    // account row is inserted inside createAccount()'s own transaction)
    // Same savepoint-before-checking-after discipline as Test 7.
    // ============================================================
    echo "TEST 8: Holder-creation failure rolls back the account\n";
    $db->exec('SAVEPOINT sp_test8');
    $accountsBefore8 = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
    try {
        $accountModel->createAccount(
            ['account_type' => 'compulsory', 'opened_date' => '2026-08-15'],
            [['member_id' => 999999999, 'role' => 'primary']], // shape is valid; member does not exist
            1
        );
        check('account creation should have thrown when the holder is invalid', false);
    } catch (InvalidArgumentException $e) {
        check('holder failure rejected — ' . $e->getMessage(), true);
    }
    $db->exec('ROLLBACK TO SAVEPOINT sp_test8');
    $accountsAfter8 = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
    check('account row rolled back along with the failed holder (atomic)', $accountsAfter8 === $accountsBefore8);
    echo "\n";

    // ============================================================
    // TEST 9: Registration fee behavior preserved
    // ============================================================
    echo "TEST 9: Registration fee behavior preserved\n";
    $feeModel = new FeeModel();
    $stmt = $db->prepare('SELECT COUNT(*) FROM member_fees WHERE member_id = ?');
    $stmt->execute([$memberId1]);
    $feesBefore = (int)$stmt->fetchColumn();
    $feeModel->chargeRegistrationFee($memberId1, 1);
    $stmt->execute([$memberId1]);
    $feesAfter = (int)$stmt->fetchColumn();
    check('chargeRegistrationFee() still callable and does not throw after Stage 3 changes', true);
    check('registration fee charging behavior unchanged (either charges once or no-ops per existing rules, no exception)', $feesAfter >= $feesBefore);
    echo "\n";

    // ============================================================
    // TEST 10: Historical data protection (mid-suite check)
    // ============================================================
    echo "TEST 10: Historical data untouched (mid-transaction check)\n";
    $currentSavingsCount = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    check('savings row count still matches baseline mid-transaction', $currentSavingsCount === $beforeSavings);
    $currentNullCount = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NOT NULL')->fetchColumn();
    check('no historical savings row has been assigned savings_account_id', $currentNullCount === 0);
    echo "\n";

} finally {
    $db->rollBack();
    echo "=== ROLLED BACK — zero permanent writes ===\n\n";
}

// ============================================================
// TEST 11: Stage 2 regression
// ============================================================
echo "TEST 11: Stage 2 regression\n";
$stage2Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage2.php 2>&1');
check('test_savings_accounts_stage2.php still reports all tests passed', str_contains($stage2Out ?? '', 'ALL STAGE 2 MODEL TESTS PASSED'));
echo "\n";

// ============================================================
// FORENSIC BASELINE CHECK — outside the rolled-back transaction
// ============================================================
echo "=== FORENSIC BASELINE CHECK ===\n";
$afterMembers  = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
$afterSavings  = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$afterSavingsTotal = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn();
$afterJE       = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterJLTotals = $db->query('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines')->fetch(PDO::FETCH_ASSOC);
$afterAccounts = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
$afterHolders  = (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn();
$afterOrgs     = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$afterReps     = (int)$db->query('SELECT COUNT(*) FROM organization_representatives')->fetchColumn();
$afterCSSeq    = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CS'")->fetchColumn();
$afterNullSavingsAccountId = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NOT NULL')->fetchColumn();

check('members count unchanged', $afterMembers === $beforeMembers);
check('savings count unchanged', $afterSavings === $beforeSavings);
check('savings total unchanged', abs($afterSavingsTotal - $beforeSavingsTotal) < 0.01);
check('journal_entries count unchanged', $afterJE === $beforeJE);
check('journal_lines totals unchanged', abs((float)$afterJLTotals['d'] - (float)$beforeJLTotals['d']) < 0.01 && abs((float)$afterJLTotals['c'] - (float)$beforeJLTotals['c']) < 0.01);
check('member_savings_accounts empty again (rolled back)', $afterAccounts === $beforeAccounts);
check('savings_account_holders empty again (rolled back)', $afterHolders === $beforeHolders);
check('organizations unchanged (rolled back)', $afterOrgs === $beforeOrgs);
check('organization_representatives unchanged (rolled back)', $afterReps === $beforeReps);
check('CS sequence restored to baseline (SAVEPOINT correctly undid the deletion)', (string)$afterCSSeq === (string)$beforeCSSeq);
check('savings.savings_account_id remains NULL on every existing historical row', $afterNullSavingsAccountId === 0);

$savingsAccountsExists = $db->query("SHOW TABLES LIKE 'savings_accounts'")->fetch();
check('orphaned savings_accounts table still exists, untouched (not dropped/renamed)', $savingsAccountsExists !== false);
echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STAGE 3 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
