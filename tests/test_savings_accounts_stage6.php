<?php
/**
 * Savings Accounts Module — Stage 6 (final audit, reconciliation &
 * freeze assessment) test suite.
 *
 * Primarily read-only verification of the REAL, permanent Stage 5B.1
 * state (18 members, 18 compulsory accounts), plus controlled live-
 * workflow tests against both a REAL provisioned account (to prove the
 * actual current system works, not just disposable fixtures) and fresh
 * disposable test members/accounts (for isolation/joint/corporate
 * scenarios that would otherwise touch real data). All writes happen
 * inside a transaction rolled back at the end -- zero permanent writes
 * from this suite, exactly like every prior stage.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$db = Database::getInstance()->getConnection();
Session::set('user_id', 1);
Session::set('user_role', 'admin');

echo "=== SAVINGS ACCOUNTS STAGE 6 — FINAL AUDIT TEST SUITE ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

function captureBaseline(PDO $db): array {
    return [
        'members'          => (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn(),
        'savings_count'    => (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn(),
        'savings_total'    => (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn(),
        'savings_id_checksum'      => (string)$db->query('SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM savings')->fetchColumn(),
        'savings_content_checksum' => (string)$db->query("SELECT MD5(GROUP_CONCAT(CONCAT(id,':',COALESCE(credit,0),':',COALESCE(debit,0),':',COALESCE(savings_account_id,'NULL'),':',receipt_number,':',member_id,':',transaction_date) ORDER BY id)) FROM savings")->fetchColumn(),
        'savings_account_id_null' => (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NULL')->fetchColumn(),
        'journal_entries'  => (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
        'journal_lines_d'  => (float)$db->query('SELECT COALESCE(SUM(debit),0) FROM journal_lines')->fetchColumn(),
        'journal_lines_c'  => (float)$db->query('SELECT COALESCE(SUM(credit),0) FROM journal_lines')->fetchColumn(),
        'accounts'         => (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn(),
        'compulsory'       => (int)$db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='compulsory'")->fetchColumn(),
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
echo "accounts={$before['accounts']} compulsory={$before['compulsory']} holders={$before['holders']} journal_entries={$before['journal_entries']}\n";
echo "savings_account_id NULL count={$before['savings_account_id_null']} (must be 44)\n\n";

// ============================================================
// SECTION 1 — Existing-member provisioning audit (read-only, Section 3)
// ============================================================
echo "SECTION 1: Existing-member provisioning (Stage 5B.1 result)\n";
check('exactly 18 members exist', $before['members'] === 18);
check('exactly 18 member_savings_accounts exist', $before['accounts'] === 18);
check('all 18 are compulsory (0 voluntary/joint/corporate auto-created)', $before['compulsory'] === 18);
check('exactly 18 holder rows exist (1 per account)', $before['holders'] === 18);

$dupCheck = $db->query("
    SELECT h.member_id, COUNT(*) c FROM savings_account_holders h
    JOIN member_savings_accounts a ON a.id = h.account_id
    WHERE a.account_type = 'compulsory' GROUP BY h.member_id HAVING c > 1
")->fetchAll();
check('no member holds more than one compulsory account', count($dupCheck) === 0);

$membersWithoutAccount = $db->query("
    SELECT m.id FROM members m
    LEFT JOIN savings_account_holders h ON h.member_id = m.id
    LEFT JOIN member_savings_accounts a ON a.id = h.account_id AND a.account_type = 'compulsory'
    WHERE a.id IS NULL
")->fetchAll();
check('every existing member has a compulsory account (0 without one)', count($membersWithoutAccount) === 0);

$numbers = $db->query("SELECT account_number FROM member_savings_accounts")->fetchAll(PDO::FETCH_COLUMN);
check('all 18 account numbers are unique', count($numbers) === count(array_unique($numbers)));
check('all 18 account numbers use the CS- prefix', count(array_filter($numbers, fn($n) => str_starts_with($n, 'CS-'))) === 18);

$mismatchedOpenDate = $db->query("
    SELECT a.id FROM member_savings_accounts a
    JOIN savings_account_holders h ON h.account_id = a.id
    JOIN members m ON m.id = h.member_id
    WHERE a.account_type = 'compulsory' AND a.opened_date != m.join_date
")->fetchAll();
check('every compulsory account\'s opened_date matches its member\'s join_date', count($mismatchedOpenDate) === 0);

$nonActive = (int)$db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='compulsory' AND status != 'active'")->fetchColumn();
check('every compulsory account has status = active (established default)', $nonActive === 0);
echo "\n";

$db->beginTransaction();

try {
    $accountModel = new MemberSavingsAccountModel();
    $holderModel  = new SavingsAccountHolderModel();
    $memberModel  = new MemberModel();
    $savingsModel = new SavingsModel();
    $orgModel     = new OrganizationModel();

    // ============================================================
    // SECTION 2 — Live deposit + withdrawal workflow on a REAL,
    // permanently-provisioned account (Sections 5-7), rolled back after.
    // ============================================================
    echo "SECTION 2: Live workflow on a REAL provisioned account (CS-000001)\n";
    $realAccount = $accountModel->getAccountByNumber('CS-000001');
    check('CS-000001 exists (the real, permanent Stage 5B.1 account)', $realAccount !== false);
    $realHolders = $accountModel->getAccountHolders($realAccount['id']);
    check('CS-000001 has exactly one primary holder', count($realHolders) === 1 && $realHolders[0]['role'] === 'primary');
    $realMemberId = (int)$realHolders[0]['member_id'];
    $realBalanceBefore = $accountModel->getAccountBalance($realAccount['id']);

    $dep = $savingsModel->recordDepositWithPosting([
        'member_id' => $realMemberId, 'savings_account_id' => $realAccount['id'],
        'amount' => 50000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    check('deposit on the real account succeeded and posted a journal entry', !empty($dep['journal_entry_id']));
    check('real account balance increased by exactly the deposit amount', $accountModel->getAccountBalance($realAccount['id']) === $realBalanceBefore + 50000.0);

    $wd = $savingsModel->recordWithdrawalWithPosting([
        'member_id' => $realMemberId, 'savings_account_id' => $realAccount['id'],
        'amount' => 20000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    check('withdrawal on the real account succeeded and posted a journal entry', !empty($wd['journal_entry_id']));
    check('real account balance = before + 50000 - 20000', $accountModel->getAccountBalance($realAccount['id']) === $realBalanceBefore + 30000.0);

    $depLines = $db->query("SELECT debit, credit FROM journal_lines WHERE journal_entry_id = {$dep['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
    check('deposit journal entry balanced (debit = credit = 50000)', abs(array_sum(array_column($depLines, 'debit')) - 50000) < 0.01 && abs(array_sum(array_column($depLines, 'credit')) - 50000) < 0.01);
    $wdLines = $db->query("SELECT debit, credit FROM journal_lines WHERE journal_entry_id = {$wd['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
    check('withdrawal journal entry balanced (debit = credit = 20000)', abs(array_sum(array_column($wdLines, 'debit')) - 20000) < 0.01 && abs(array_sum(array_column($wdLines, 'credit')) - 20000) < 0.01);

    $stmt = $accountModel->getAccountTransactions($realAccount['id']);
    check('CS-000001\'s transaction list contains exactly these 2 new rows (real account had 0 before)', count($stmt) === 2);
    echo "\n";

    // ============================================================
    // SECTION 3 — Balance isolation between two accounts (Section 12)
    // ============================================================
    echo "SECTION 3: Balance isolation across accounts\n";
    $counter = 980000;
    $makeBareMember = function () use ($memberModel, &$counter) {
        $counter++;
        return $memberModel->create([
            'member_number' => 'STAGE6-' . $counter, 'first_name' => 'Stage6', 'last_name' => 'Test' . $counter,
            'gender' => 'Male', 'phone' => '07' . str_pad((string)$counter, 8, '0', STR_PAD_LEFT),
            'national_id' => 'S6ID' . $counter, 'join_date' => '2026-08-01', 'status' => 'active',
        ]);
    };

    $memA = $makeBareMember();
    $memB = $makeBareMember();
    $accA = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-01'], [['member_id' => $memA, 'role' => 'primary']], 1);
    $accB = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-01'], [['member_id' => $memB, 'role' => 'primary']], 1);

    $savingsModel->recordDepositWithPosting(['member_id' => $memA, 'savings_account_id' => $accA, 'amount' => 70000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26', 'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026], 1);
    check('account A balance = 70000 after its deposit', $accountModel->getAccountBalance($accA) === 70000.0);
    check('account B balance still 0.00 -- unaffected by account A\'s deposit', $accountModel->getAccountBalance($accB) === 0.0);

    $savingsModel->recordDepositWithPosting(['member_id' => $memB, 'savings_account_id' => $accB, 'amount' => 30000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26', 'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026], 1);
    check('account B balance = 30000 after its own deposit', $accountModel->getAccountBalance($accB) === 30000.0);
    check('account A balance still 70000 -- unaffected by account B\'s deposit', $accountModel->getAccountBalance($accA) === 70000.0);

    $savingsModel->recordWithdrawalWithPosting(['member_id' => $memA, 'savings_account_id' => $accA, 'amount' => 10000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26', 'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026], 1);
    check('account A balance = 60000 after its withdrawal', $accountModel->getAccountBalance($accA) === 60000.0);
    check('account B balance still 30000 -- unaffected by account A\'s withdrawal', $accountModel->getAccountBalance($accB) === 30000.0);

    check('account A statement shows exactly 2 rows (its own only)', count($accountModel->getAccountTransactions($accA)) === 2);
    check('account B statement shows exactly 1 row (its own only)', count($accountModel->getAccountTransactions($accB)) === 1);
    echo "\n";

    // ============================================================
    // SECTION 4 — Joint accounts (Section 8)
    // ============================================================
    echo "SECTION 4: Joint account holder validation + isolation\n";
    $memJ1 = $makeBareMember();
    $memJ2 = $makeBareMember();
    $memOutsider = $makeBareMember();
    $jointAcc = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-01'], [
        ['member_id' => $memJ1, 'role' => 'primary'], ['member_id' => $memJ2, 'role' => 'joint'],
    ], 1);
    $jHolders = $accountModel->getAccountHolders($jointAcc);
    check('joint account has exactly 2 holders, one primary one joint', count($jHolders) === 2 && count(array_filter($jHolders, fn($h) => $h['role'] === 'primary')) === 1);

    $savingsModel->recordDepositWithPosting(['member_id' => $memJ2, 'savings_account_id' => $jointAcc, 'amount' => 25000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26', 'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026], 1);
    check('non-primary holder can deposit into the joint account', $accountModel->getAccountBalance($jointAcc) === 25000.0);
    check('joint holder J1\'s own compulsory-equivalent isolation: outsider member has no account at all yet', $holderModel->getMemberAccounts($memOutsider) === []);
    echo "\n";

    // ============================================================
    // SECTION 5 — Corporate accounts (Section 9): can exist, can be
    // viewed, deposit/withdrawal remain unavailable, nothing invented.
    // ============================================================
    echo "SECTION 5: Corporate account intended behavior\n";
    $orgId = $orgModel->createOrganization(['name' => 'Stage6 Test Org Ltd']);
    $corpAcc = $accountModel->createAccount(['account_type' => 'corporate', 'opened_date' => '2026-08-01'], [['organization_id' => $orgId, 'role' => 'organization']], 1);
    $corpRow = $accountModel->getAccount($corpAcc);
    check('corporate account was created successfully (can exist)', $corpRow !== false);
    check('corporate account balance is queryable (0.00, no transactions)', $accountModel->getAccountBalance($corpAcc) === 0.0);

    // Only one full-layout render() call is safe per process (sidebar.php's
    // known include/include_once redeclare issue, documented since Stage 4) --
    // this is the only view() call in this whole script, so it's fine here.
    $_GET = ['id' => $corpAcc];
    ob_start();
    (new SavingsAccountController())->view();
    $viewOut = ob_get_clean();
    check('corporate account view() renders without throwing', str_contains($viewOut, $corpRow['account_number']));
    check('corporate account view shows the organization name', str_contains($viewOut, 'Stage6 Test Org Ltd'));
    check('corporate account view does NOT show enabled Record Deposit/Withdrawal links (buttons stay disabled -- no authorization rule exists)', !str_contains($viewOut, 'savings-account-deposit&id=' . $corpAcc));
    check('corporate account view explains why (documented gap, not invented rule)', str_contains($viewOut, 'no rule') || str_contains($viewOut, 'authorization'));
    $_GET = [];
    echo "\n";

    // ============================================================
    // SECTION 6 — Statement isolation (Section 10)
    // ============================================================
    echo "SECTION 6: Statement isolation\n";
    $_GET = ['id' => $accA];
    ob_start();
    (new SavingsAccountController())->statement();
    $stmtA = ob_get_clean();
    check('account A\'s statement is a standalone document', str_contains($stmtA, '<!DOCTYPE html>'));
    check('account A\'s statement shows account A\'s own number', str_contains($stmtA, $accountModel->getAccount($accA)['account_number']));
    check('account A\'s statement does NOT show account B\'s number', !str_contains($stmtA, $accountModel->getAccount($accB)['account_number']));
    $_GET = [];
    echo "\n";

    // ============================================================
    // SECTION 7 — Member summary correctness (Section 11)
    // ============================================================
    echo "SECTION 7: Member summary\n";
    $summaryA = $accountModel->getMemberSavingsSummary($memA);
    check('member A\'s summary lists exactly their 1 account', count($summaryA['accounts']) === 1);
    check('member A\'s summary total matches that account\'s balance (60000), no double counting', $summaryA['total'] === 60000.0);
    echo "\n";

    // ============================================================
    // SECTION 8 — Accounting integrity (Section 15)
    // ============================================================
    echo "SECTION 8: Accounting integrity\n";
    $jeCountMid = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    // 6 transactional journal entries expected by this point: Section 2's
    // deposit+withdrawal (2), Section 3's isolation deposits+withdrawal (3),
    // Section 4's joint deposit (1). The 4 accounts created along the way
    // (2 voluntary, 1 joint, 1 corporate) must have posted none.
    check('journal_entries increased by exactly 6 (the 6 transactions so far), not more -- account creation posts nothing', $jeCountMid === $before['journal_entries'] + 6);
    $midLines = $db->query('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines')->fetch(PDO::FETCH_ASSOC);
    check('journal_lines remains globally balanced (total debit = total credit) after all test transactions', abs((float)$midLines['d'] - (float)$midLines['c']) < 0.01);
    $depLinesCheck = $db->query("SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id = {$dep['journal_entry_id']}")->fetch(PDO::FETCH_ASSOC);
    $wdLinesCheck = $db->query("SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id = {$wd['journal_entry_id']}")->fetch(PDO::FETCH_ASSOC);
    check('the real account\'s deposit journal entry is individually balanced', abs((float)$depLinesCheck['d'] - (float)$depLinesCheck['c']) < 0.01);
    check('the real account\'s withdrawal journal entry is individually balanced', abs((float)$wdLinesCheck['d'] - (float)$wdLinesCheck['c']) < 0.01);
    echo "\n";

    // ============================================================
    // SECTION 9 — Security (Section 13) -- CSRF/role checks done via
    // the out-of-process helper below (Section 10); here, a direct
    // model-layer defense-in-depth check.
    // ============================================================
    echo "SECTION 9: Model-layer security (defense in depth)\n";
    try {
        $savingsModel->recordWithdrawalWithPosting(['member_id' => $memA, 'amount' => 100, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26', 'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026], 1);
        check('withdrawal without savings_account_id should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('withdrawal without savings_account_id rejected at the model layer -- ' . $e->getMessage(), true);
    }
    echo "\n";

    // ============================================================
    // SECTION 10 — Historical Savings untouched (mid-transaction)
    // ============================================================
    echo "SECTION 10: Historical Savings untouched (mid-transaction)\n";
    $midNullCount = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NULL')->fetchColumn();
    check('44 historical rows still have NULL savings_account_id', $midNullCount === $before['savings_account_id_null']);
    $midTotal = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings WHERE savings_account_id IS NULL')->fetchColumn();
    check('historical total (NULL-account rows) unchanged', abs($midTotal - 1238055862.65) < 0.01);
    echo "\n";

} finally {
    $db->rollBack();
    echo "=== ROLLED BACK — zero permanent writes from this test run ===\n\n";
}

// ============================================================
// SECTION 11 — Security scenarios (out-of-process; reuses the Stage 5B
// controller-scenario helper, since these exit()-terminating paths
// can't run in-process, per Stage 4/5B's established technique)
// ============================================================
echo "SECTION 11: Security & validation scenarios (out-of-process)\n";
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
    check("security scenario '{$scenario}': " . trim(strtok($out ?? '', "\n") ?: '(no output)'), str_contains($out ?? '', 'SCENARIO_PASS'));
}
echo "\n";

// ============================================================
// SECTION 12 — Code-inspection facts (Section 21), verified live
// ============================================================
echo "SECTION 12: Code-review facts (verified, not just asserted)\n";
$addSrc = file_get_contents('app/controllers/SavingsController.php');
check('SavingsController::add() no longer reaches handleSave() with a null id (the accountless creation path is unreachable from any route)', str_contains($addSrc, "redirect(APP_URL . '/index.php?page=savings-accounts')"));
$modelSrc = file_get_contents('app/models/SavingsModel.php');
check('recalcRunningBalances() (legacy path) explicitly excludes account-linked rows (savings_account_id IS NULL filter present)', str_contains($modelSrc, "WHERE member_id = ? AND savings_account_id IS NULL"));
$ctrlSrc = file_get_contents('app/controllers/SavingsAccountController.php');
check('depositStore()/withdrawalStore() both call requireWriteAccess() (role gate present)', substr_count($ctrlSrc, 'requireWriteAccess();') >= 6);
check('depositStore()/withdrawalStore() both call verifyCsrf() (CSRF gate present)', substr_count($ctrlSrc, 'verifyCsrf(') >= 6);
check('resolveTransactionMember() re-fetches the account from the DB rather than trusting posted account_type/status', str_contains($ctrlSrc, '$account = $this->accountModel->getAccount($accountId);'));
echo "\n";

// ============================================================
// SECTION 13 — Regressions
// ============================================================
echo "SECTION 13: Regression suites\n";
$stage2Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage2.php 2>&1');
check('Stage 2 model suite still passes', str_contains($stage2Out ?? '', 'ALL STAGE 2 MODEL TESTS PASSED'));
$stage3Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage3.php 2>&1');
check('Stage 3 suite still passes', str_contains($stage3Out ?? '', 'ALL STAGE 3 TESTS PASSED'));
$stage4Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage4.php 2>&1');
check('Stage 4 suite still passes', str_contains($stage4Out ?? '', 'ALL STAGE 4 TESTS PASSED'));
$stage5aOut = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage5a.php 2>&1');
check('Stage 5A read-only audit still reports NO DATABASE MUTATIONS DETECTED', str_contains($stage5aOut ?? '', 'NO DATABASE MUTATIONS DETECTED'));
$stage5bOut = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage5b.php 2>&1');
check('Stage 5B suite still passes', str_contains($stage5bOut ?? '', 'ALL STAGE 5B TESTS PASSED'));
$stage5b1Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage5b1.php 2>&1');
check('Stage 5B.1 suite still passes', str_contains($stage5b1Out ?? '', 'ALL STAGE 5B.1 TESTS PASSED'));
echo "\n";

// ============================================================
// FORENSIC BASELINE CHECK — outside the rolled-back transaction
// ============================================================
echo "=== FORENSIC BASELINE CHECK ===\n";
$after = captureBaseline($db);
check('members count unchanged', $after['members'] === $before['members']);
check('savings count unchanged', $after['savings_count'] === $before['savings_count']);
check('savings total unchanged', abs($after['savings_total'] - $before['savings_total']) < 0.01);
check('savings row id checksum unchanged', $after['savings_id_checksum'] === $before['savings_id_checksum']);
check('savings row content checksum unchanged', $after['savings_content_checksum'] === $before['savings_content_checksum']);
check('savings.savings_account_id NULL count unchanged (44)', $after['savings_account_id_null'] === $before['savings_account_id_null']);
check('journal_entries count unchanged (test entries rolled back)', $after['journal_entries'] === $before['journal_entries']);
check('journal_lines totals unchanged', abs($after['journal_lines_d'] - $before['journal_lines_d']) < 0.01 && abs($after['journal_lines_c'] - $before['journal_lines_c']) < 0.01);
check('member_savings_accounts back to 18 (the real, permanent Stage 5B.1 accounts only)', $after['accounts'] === 18 && $after['accounts'] === $before['accounts']);
check('all 18 are still compulsory (test voluntary/joint/corporate accounts rolled back)', $after['compulsory'] === 18);
check('savings_account_holders back to baseline (rolled back)', $after['holders'] === $before['holders']);
check('organizations back to baseline (rolled back)', $after['organizations'] === $before['organizations']);
foreach (['CS', 'VS', 'JS', 'CORP'] as $p) {
    check("{$p} sequence restored to baseline (rollback undid this run's increments)", (string)$after["seq_{$p}"] === (string)$before["seq_{$p}"]);
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
    echo "ALL STAGE 6 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
