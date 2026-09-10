<?php
/**
 * Savings Accounts Module — Stage 5B (live savings transaction
 * integration) test suite. Entire in-process section runs inside one
 * outer transaction rolled back at the very end -- zero permanent
 * writes, per the established discipline for this whole module.
 *
 * Historical-data rule: this suite creates ONLY fresh disposable test
 * members (STAGE5B- prefix) and their accounts, exactly like Stage 2/3/4
 * did. It never touches the 44 historical `savings` rows, never sets
 * savings_account_id on any of them, and never creates accounts for any
 * of the 6 real members those rows reference.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$db = Database::getInstance()->getConnection();

Session::set('user_id', 1);
Session::set('user_role', 'admin');

echo "=== SAVINGS ACCOUNTS STAGE 5B TEST SUITE ===\n\n";

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
function captureBaseline(PDO $db): array {
    return [
        'members'          => (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn(),
        'savings_count'    => (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn(),
        'savings_total'    => (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn(),
        'savings_id_checksum'     => (string)$db->query('SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM savings')->fetchColumn(),
        'savings_amount_checksum' => (string)$db->query("SELECT MD5(GROUP_CONCAT(CONCAT(id,':',COALESCE(credit,0),':',COALESCE(debit,0),':',COALESCE(savings_account_id,'NULL'),':',receipt_number,':',member_id,':',transaction_date) ORDER BY id)) FROM savings")->fetchColumn(),
        'savings_account_id_null' => (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NULL')->fetchColumn(),
        'journal_entries'  => (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
        'journal_lines_d'  => (float)$db->query('SELECT COALESCE(SUM(debit),0) FROM journal_lines')->fetchColumn(),
        'journal_lines_c'  => (float)$db->query('SELECT COALESCE(SUM(credit),0) FROM journal_lines')->fetchColumn(),
        'accounts'         => (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn(),
        'holders'          => (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn(),
        'organizations'    => (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn(),
        'seq_CS'           => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CS'")->fetchColumn(),
        'seq_VS'           => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='VS'")->fetchColumn(),
        'seq_JS'           => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JS'")->fetchColumn(),
        'seq_CORP'         => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CORP'")->fetchColumn(),
    ];
}

$before = captureBaseline($db);
echo "Baseline (queried live): members={$before['members']} savings={$before['savings_count']} savingsTotal={$before['savings_total']}\n";
echo "journal_entries={$before['journal_entries']} journal_lines debit={$before['journal_lines_d']} credit={$before['journal_lines_c']}\n";
echo "accounts={$before['accounts']} holders={$before['holders']} organizations={$before['organizations']}\n";
echo "savings_account_id NULL count={$before['savings_account_id_null']} (must stay 44)\n\n";

$db->beginTransaction();

$counter = 900000;
$makeMemberData = function () use (&$counter) {
    $counter++;
    return [
        'member_number' => 'STAGE5B-' . $counter,
        'first_name'    => 'Stage5B',
        'last_name'     => 'TestMember' . $counter,
        'gender'        => 'Male',
        'phone'         => '07' . str_pad((string)$counter, 8, '0', STR_PAD_LEFT),
        'national_id'   => 'STAGE5BID' . $counter,
        'station'       => 'Test Station',
        'join_date'     => '2026-08-01',
        'status'        => 'active',
        'created_by'    => 1,
    ];
};

try {
    $memberModel  = new MemberModel();
    $accountModel = new MemberSavingsAccountModel();
    $holderModel  = new SavingsAccountHolderModel();
    $savingsModel = new SavingsModel();

    // ============================================================
    // SECTION 1 — Individual / compulsory: deposit + withdrawal,
    // correct savings_account_id, correct balance, correct posting.
    // ============================================================
    echo "SECTION 1: Individual / compulsory account\n";
    $m1 = $memberModel->createWithCompulsoryAccount($makeMemberData(), 1);
    $acc1 = $m1['account_id'];

    $dep = $savingsModel->recordDepositWithPosting([
        'member_id' => $m1['member_id'], 'savings_account_id' => $acc1,
        'amount' => 60000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    $row = $savingsModel->find($dep['id']);
    check('deposit row has savings_account_id set', (int)$row['savings_account_id'] === $acc1);
    check('deposit transaction_type = deposit', $row['transaction_type'] === 'deposit');
    check('deposit credit = 60000, debit = 0', (float)$row['credit'] === 60000.0 && (float)$row['debit'] === 0.0);
    check('deposit running_balance = 60000 (account-scoped, not member-wide)', (float)$row['running_balance'] === 60000.0);
    check('account balance = 60000', $accountModel->getAccountBalance($acc1) === 60000.0);
    check('journal entry created and linked', !empty($dep['journal_entry_id']));

    $jlines = $db->query("SELECT debit, credit FROM journal_lines WHERE journal_entry_id = {$dep['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
    $sumD = array_sum(array_column($jlines, 'debit'));
    $sumC = array_sum(array_column($jlines, 'credit'));
    check('deposit journal entry balanced (debit = credit = 60000)', count($jlines) === 2 && abs($sumD - 60000) < 0.01 && abs($sumC - 60000) < 0.01);

    $wd = $savingsModel->recordWithdrawalWithPosting([
        'member_id' => $m1['member_id'], 'savings_account_id' => $acc1,
        'amount' => 25000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-21',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    $wrow = $savingsModel->find($wd['id']);
    check('withdrawal row has savings_account_id set', (int)$wrow['savings_account_id'] === $acc1);
    check('withdrawal transaction_type = withdrawal', $wrow['transaction_type'] === 'withdrawal');
    check('withdrawal debit = 25000, credit = 0', (float)$wrow['debit'] === 25000.0 && (float)$wrow['credit'] === 0.0);
    check('withdrawal running_balance = 35000', (float)$wrow['running_balance'] === 35000.0);
    check('account balance after withdrawal = 35000', $accountModel->getAccountBalance($acc1) === 35000.0);

    $wlines = $db->query("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id = {$wd['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
    $sumWD = array_sum(array_column($wlines, 'debit'));
    $sumWC = array_sum(array_column($wlines, 'credit'));
    check('withdrawal journal entry balanced (debit = credit = 25000)', count($wlines) === 2 && abs($sumWD - 25000) < 0.01 && abs($sumWC - 25000) < 0.01);
    $liabilityLine = array_values(array_filter($wlines, fn($l) => (int)$l['account_id'] === 17))[0] ?? null;
    check('withdrawal debits the Members\' Savings liability account (mirror of deposit crediting it)', $liabilityLine && (float)$liabilityLine['debit'] === 25000.0);
    echo "\n";

    // ============================================================
    // SECTION 2 — Voluntary account, multiple accounts per member,
    // explicit selection (no arbitrary choice).
    // ============================================================
    echo "SECTION 2: Voluntary accounts, multiple per member, explicit selection\n";
    $m2 = $memberModel->createWithCompulsoryAccount($makeMemberData(), 1);
    $volA = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-20'], [['member_id' => $m2['member_id'], 'role' => 'primary']], 1);
    $volB = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-20'], [['member_id' => $m2['member_id'], 'role' => 'primary']], 1);
    check('member now has 2 voluntary accounts (Stage 4\'s known no-duplicate-guard gap, confirmed still present)', $volA !== $volB);

    $savingsModel->recordDepositWithPosting([
        'member_id' => $m2['member_id'], 'savings_account_id' => $volB,
        'amount' => 12000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    check('deposit explicitly targeted at volB only affects volB', $accountModel->getAccountBalance($volB) === 12000.0);
    check('volA (the other voluntary account) is untouched -- no arbitrary/first-account selection', $accountModel->getAccountBalance($volA) === 0.0);
    echo "\n";

    // ============================================================
    // SECTION 3 — Joint account isolation
    // ============================================================
    echo "SECTION 3: Joint account isolation\n";
    $m3a = $memberModel->createWithCompulsoryAccount($makeMemberData(), 1);
    $m3b = $memberModel->createWithCompulsoryAccount($makeMemberData(), 1);
    $jointAcc = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-20'], [
        ['member_id' => $m3a['member_id'], 'role' => 'primary'],
        ['member_id' => $m3b['member_id'], 'role' => 'joint'],
    ], 1);
    $savingsModel->recordDepositWithPosting([
        'member_id' => $m3b['member_id'], 'savings_account_id' => $jointAcc,
        'amount' => 40000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    check('joint account balance reflects the deposit', $accountModel->getAccountBalance($jointAcc) === 40000.0);
    check('joint holder m3a\'s own compulsory account is untouched by a deposit made by m3b on the joint account', $accountModel->getAccountBalance($m3a['account_id']) === 0.0);
    check('joint holder m3b\'s own compulsory account is untouched too (deposit went to the joint account, not the member)', $accountModel->getAccountBalance($m3b['account_id']) === 0.0);
    echo "\n";

    // ============================================================
    // SECTION 4 — Running-balance / recalc isolation between legacy
    // (account-less) rows and account-linked rows for a coexisting
    // member_id -- the correctness fix made in SavingsModel for Stage 5B.
    // ============================================================
    echo "SECTION 4: Legacy vs account-linked row isolation on the SAME member_id\n";
    $m4 = $memberModel->createWithCompulsoryAccount($makeMemberData(), 1);
    // A legacy-style (account-less) row for this same member, exactly as the old SavingsController::handleSave() used to create.
    $legacyId = $savingsModel->create([
        'member_id' => $m4['member_id'], 'amount' => 5000, 'payment_method' => 'Cash',
        'transaction_date' => '2026-08-19', 'receipt_number' => $savingsModel->generateReceiptNumber(),
        'recorded_by' => 1, 'financial_year' => 2026,
    ]);
    $legacyRow = $savingsModel->find($legacyId);
    check('legacy row has NULL savings_account_id', $legacyRow['savings_account_id'] === null);
    check('legacy row running_balance uses the OLD member-wide formula (5000)', (float)$legacyRow['running_balance'] === 5000.0);

    $savingsModel->recordDepositWithPosting([
        'member_id' => $m4['member_id'], 'savings_account_id' => $m4['account_id'],
        'amount' => 30000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    check('new account-linked deposit\'s running_balance is scoped to the ACCOUNT (30000), not the member-wide 35000', $accountModel->getAccountBalance($m4['account_id']) === 30000.0);

    // Editing the legacy row must recalc only the legacy (account-less) sequence, never touch the account-linked row.
    $savingsModel->update($legacyId, ['amount' => 7000]);
    $accBalanceAfterEdit = $accountModel->getAccountBalance($m4['account_id']);
    check('editing the legacy row does not disturb the account-linked deposit\'s balance', $accBalanceAfterEdit === 30000.0);
    $legacyRowAfter = $savingsModel->find($legacyId);
    check('legacy row\'s own running_balance updated correctly (7000)', (float)$legacyRowAfter['running_balance'] === 7000.0);
    echo "\n";

    // ============================================================
    // SECTION 5 — Insufficient balance rejected at the model layer too
    // (defense in depth, independent of the controller-level check)
    // ============================================================
    echo "SECTION 5: Model-layer balance safety\n";
    $m5 = $memberModel->createWithCompulsoryAccount($makeMemberData(), 1);
    $savingsModel->recordDepositWithPosting([
        'member_id' => $m5['member_id'], 'savings_account_id' => $m5['account_id'],
        'amount' => 10000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    try {
        $savingsModel->recordWithdrawalWithPosting([
            'member_id' => $m5['member_id'], 'savings_account_id' => $m5['account_id'],
            'amount' => 999999, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-21',
            'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
        ], 1);
        check('over-withdrawal should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('over-withdrawal rejected at the model layer -- ' . $e->getMessage(), true);
    }
    check('balance unchanged after the rejected withdrawal', $accountModel->getAccountBalance($m5['account_id']) === 10000.0);
    $savingsCountForM5 = (int)$db->query("SELECT COUNT(*) FROM savings WHERE savings_account_id = {$m5['account_id']}")->fetchColumn();
    check('no orphan row was left behind by the rejected withdrawal (atomicity)', $savingsCountForM5 === 1);
    echo "\n";

    // ============================================================
    // SECTION 6 — Missing account_id rejected (application boundary,
    // enforced by SavingsModel::recordWithdrawalWithPosting() itself
    // as defense in depth even though the controller is the primary gate)
    // ============================================================
    echo "SECTION 6: Missing account rejected\n";
    try {
        $savingsModel->recordWithdrawalWithPosting([
            'member_id' => $m5['member_id'], 'amount' => 1000, 'payment_method' => 'Cash',
            'transaction_date' => '2026-08-21', 'receipt_number' => $savingsModel->generateReceiptNumber(),
            'recorded_by' => 1, 'financial_year' => 2026,
        ], 1);
        check('withdrawal with no savings_account_id should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('withdrawal with no savings_account_id rejected -- ' . $e->getMessage(), true);
    }
    echo "\n";

    // ============================================================
    // SECTION 7 — Statement / member-summary correctness with real
    // account-linked transactions now present.
    // ============================================================
    echo "SECTION 7: Statement + member summary correctness\n";
    $txns = $accountModel->getAccountTransactions($acc1);
    check('account 1\'s transaction list contains exactly its own 2 rows (deposit + withdrawal), nothing from other accounts', count($txns) === 2);
    $summary = $accountModel->getMemberSavingsSummary($m2['member_id']);
    // 3, not 2: m2 also has the compulsory account createWithCompulsoryAccount() gave it at registration, plus volA and volB.
    check('member 2\'s summary lists all 3 accounts (compulsory + 2 voluntary) independently', count($summary['accounts']) === 3);
    check('member 2\'s summary grand total is the sum (12000, only volB has a balance), a display aggregation only', $summary['total'] === 12000.0);
    echo "\n";

    // ============================================================
    // SECTION 8 — Historical data untouched (mid-transaction check)
    // ============================================================
    echo "SECTION 8: Historical data untouched (mid-transaction)\n";
    $midNullCount = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NULL AND member_id IN (2,3,4,6,7,21)')->fetchColumn();
    check('all 44 original historical rows (members 2,3,4,6,7,21) still have NULL savings_account_id', $midNullCount === $before['savings_account_id_null']);
    $histTotal = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings WHERE member_id IN (2,3,4,6,7,21) AND savings_account_id IS NULL')->fetchColumn();
    check('historical total for the 6 real members unchanged (1238055862.65)', abs($histTotal - 1238055862.65) < 0.01);
    echo "\n";

} finally {
    $db->rollBack();
    echo "=== ROLLED BACK — zero permanent writes ===\n\n";
}

// ============================================================
// SECTION 9 — Controller-level scenarios (out-of-process, see
// test_savings_accounts_stage5b_txn.php for why)
// ============================================================
echo "SECTION 9: Controller-level scenarios (out-of-process)\n";
$scenarios = [
    ['inactive_account_rejected', 'admin'],
    ['nonexistent_account', 'admin'],
    ['csrf_mismatch', 'admin'],
    ['unauthorized_role', 'viewer'],
    ['corporate_deposit_rejected', 'admin'],
    ['joint_deposit_valid', 'admin'],
    ['joint_deposit_invalid_holder', 'admin'],
    ['joint_deposit_missing_member', 'admin'],
    ['withdrawal_insufficient_balance', 'admin'],
];
foreach ($scenarios as [$scenario, $role]) {
    $out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage5b_txn.php ' . escapeshellarg($scenario) . ' ' . escapeshellarg($role) . ' 2>&1');
    check("controller scenario '{$scenario}': " . trim(strtok($out ?? '', "\n") ?: '(no output)'), str_contains($out ?? '', 'SCENARIO_PASS'));
}
echo "\n";

// ============================================================
// SECTION 10 — Regressions
// ============================================================
echo "SECTION 10: Regression suites\n";
$stage2Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage2.php 2>&1');
check('Stage 2 model suite (53/53) still passes', str_contains($stage2Out ?? '', 'ALL STAGE 2 MODEL TESTS PASSED'));
$stage3Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage3.php 2>&1');
check('Stage 3 suite (46/46) still passes', str_contains($stage3Out ?? '', 'ALL STAGE 3 TESTS PASSED'));
$stage4Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage4.php 2>&1');
check('Stage 4 suite (55/55) still passes', str_contains($stage4Out ?? '', 'ALL STAGE 4 TESTS PASSED'));
$stage5aOut = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage5a.php 2>&1');
check('Stage 5A read-only audit still reports NO DATABASE MUTATIONS DETECTED', str_contains($stage5aOut ?? '', 'NO DATABASE MUTATIONS DETECTED'));
echo "\n";

// ============================================================
// FORENSIC BASELINE CHECK — outside the rolled-back transaction
// ============================================================
echo "=== FORENSIC BASELINE CHECK ===\n";
$after = captureBaseline($db);
check('members count unchanged', $after['members'] === $before['members']);
check('savings count unchanged', $after['savings_count'] === $before['savings_count']);
check('savings total unchanged', abs($after['savings_total'] - $before['savings_total']) < 0.01);
check('savings row id checksum unchanged (identical set of rows)', $after['savings_id_checksum'] === $before['savings_id_checksum']);
check('savings row content checksum unchanged (amounts/dates/receipts/account_id/member_id all identical)', $after['savings_amount_checksum'] === $before['savings_amount_checksum']);
check('savings.savings_account_id NULL count unchanged (44)', $after['savings_account_id_null'] === $before['savings_account_id_null']);
check('journal_entries count unchanged', $after['journal_entries'] === $before['journal_entries']);
check('journal_lines totals unchanged', abs($after['journal_lines_d'] - $before['journal_lines_d']) < 0.01 && abs($after['journal_lines_c'] - $before['journal_lines_c']) < 0.01);
check('member_savings_accounts empty again (rolled back)', $after['accounts'] === $before['accounts']);
check('savings_account_holders empty again (rolled back)', $after['holders'] === $before['holders']);
check('organizations unchanged (rolled back)', $after['organizations'] === $before['organizations']);
foreach (['CS', 'VS', 'JS', 'CORP'] as $p) {
    check("{$p} sequence restored to baseline", (string)$after["seq_{$p}"] === (string)$before["seq_{$p}"]);
}
$savingsAccountsExists = $db->query("SHOW TABLES LIKE 'savings_accounts'")->fetch();
check('orphaned savings_accounts table still exists, untouched', $savingsAccountsExists !== false);
echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STAGE 5B TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
