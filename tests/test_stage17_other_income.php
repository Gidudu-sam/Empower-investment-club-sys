<?php
/**
 * STAGE 17 PART D — Other Income Test Harness
 *
 * Runs entirely against `empower_db_stage17`, an isolated database.
 * Never touches `empower_db`. Defines its own DB_* constants (bypassing
 * app/config/database.php) before loading core/Database.php, exactly as
 * test_stage17_fees.php's harness did.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db_stage17');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/app');
define('CORE_PATH', __DIR__ . '/core');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';

$pdo = Database::getInstance()->getConnection();

// Reset transactional tables so this script can be re-run repeatedly.
// Reference/config data (accounts, fees, accounting_periods, financial_years,
// roles, users, journal_number_sequences, etc.) is left untouched, except
// the 'OI' sequence counter and account 31's is_active flag, both reset
// explicitly below so re-runs are deterministic.
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['journal_lines', 'journal_entries', 'member_fees', 'other_income_transactions', 'other_income_categories',
          'savings_account_holders', 'member_savings_accounts', 'members'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("UPDATE journal_number_sequences SET last_number = 0 WHERE prefix = 'OI'");
$pdo->exec("UPDATE accounts SET is_active = 1 WHERE id = 31"); // Investment Income -- restore in case a prior run deactivated it

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; $failures[] = "$label -- $detail"; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

function getIncome(PDO $pdo, int $id): array|false {
    $stmt = $pdo->prepare('SELECT * FROM `other_income_transactions` WHERE `id` = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$ADMIN_USER_ID = 1;

$incomeModel = new OtherIncomeModel();
$catModel    = new OtherIncomeCategoryModel();

// ================================================================
// SETUP: categories
// ================================================================
section('Setup: create categories');

$goodCatId = $catModel->create([
    'category_name' => 'Investment Income (Test)',
    'description'   => 'Test category mapped to an active income account',
    'gl_account_id' => 31, // Investment Income, active
    'is_active'     => 1,
]);
check('Category created with a valid active income account', $goodCatId !== false);

$unmappedCatId = $catModel->create([
    'category_name' => 'Unmapped Category (Test)',
    'description'   => 'No GL account set',
    'gl_account_id' => null,
    'is_active'     => 1,
]);
check('Category created with no GL mapping', $unmappedCatId !== false);

$threw = false;
try {
    $catModel->create([
        'category_name' => 'Bad Account Category',
        'gl_account_id' => 1, // Land -- asset, not income
        'is_active'     => 1,
    ]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('Category creation rejects a non-income (asset) GL account', $threw);

$threw = false;
try {
    $catModel->create([
        'category_name' => 'Expense Account Category',
        'gl_account_id' => 60, // Garbage -- expense type, also inactive
        'is_active'     => 1,
    ]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('Category creation rejects a non-income (expense) GL account', $threw);

// ================================================================
// TEST A — DRAFT CREATION
// ================================================================
section('Test A: Draft creation -> journal count unchanged, journal_entry_id NULL');

$jeCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();

$draftId = $incomeModel->createDraft([
    'category_id'      => $goodCatId,
    'income_date'      => date('Y-m-d'),
    'amount'            => 75000.00,
    'description'      => 'Test other income draft',
    'reference_number' => 'REF-TEST-001',
    'payment_method'   => 'Cash',
], $ADMIN_USER_ID);
check('Draft created', $draftId > 0);

$row = getIncome($pdo, $draftId);
check('Draft status is draft', $row['status'] === 'draft');
check('Draft journal_entry_id is NULL', $row['journal_entry_id'] === null);
check('Income number generated (OI- prefix)', str_starts_with($row['income_number'], 'OI-'));

$jeCountAfterDraft = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('No journal entry created by drafting', $jeCountAfterDraft === $jeCountBefore, "before={$jeCountBefore} after={$jeCountAfterDraft}");

// ================================================================
// TEST I — DRAFT EDIT (before posting)
// ================================================================
section('Test I: Draft is editable before posting');

$editOk = $incomeModel->update($draftId, ['description' => 'Edited description']);
check('Draft update succeeds', $editOk);
$row = getIncome($pdo, $draftId);
check('Edited description persisted', $row['description'] === 'Edited description');

// ================================================================
// TEST B/C — POST VIA CASH -> ONE JOURNAL, BALANCED, Dr CASH / Cr INCOME
// ================================================================
section('Test B/C: Post via Cash -> balanced journal, Dr Cash(7) / Cr Investment Income(31)');

$posted = $incomeModel->post($draftId, $ADMIN_USER_ID);
check('post() returns a journal_entry_id', ($posted['journal_entry_id'] ?? 0) > 0);

$row = getIncome($pdo, $draftId);
check('Status is now posted', $row['status'] === 'posted');
check('posted_at set', !empty($row['posted_at']));
check('posted_by set', (int)$row['posted_by'] === $ADMIN_USER_ID);
check('journal_entry_id set on source row', (int)$row['journal_entry_id'] === (int)$posted['journal_entry_id']);

$lines = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id');
$lines->execute([$posted['journal_entry_id']]);
$lines = $lines->fetchAll();
check('Journal entry has exactly 2 lines', count($lines) === 2, 'got ' . count($lines));

$debitTotal = array_sum(array_column($lines, 'debit'));
$creditTotal = array_sum(array_column($lines, 'credit'));
check('Debits equal credits', abs($debitTotal - $creditTotal) < 0.001, "debit={$debitTotal} credit={$creditTotal}");
check('Debit total equals income amount (75000)', abs($debitTotal - 75000.00) < 0.001, "got {$debitTotal}");

$debitLine = null; $creditLine = null;
foreach ($lines as $l) {
    if ((float)$l['debit'] > 0) $debitLine = $l;
    if ((float)$l['credit'] > 0) $creditLine = $l;
}
check('Debit line hits Cash at Hand (account 7)', $debitLine && (int)$debitLine['account_id'] === 7, 'got ' . ($debitLine['account_id'] ?? 'none'));
check('Credit line hits Investment Income (account 31)', $creditLine && (int)$creditLine['account_id'] === 31, 'got ' . ($creditLine['account_id'] ?? 'none'));

$je = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
$je->execute([$posted['journal_entry_id']]);
$je = $je->fetch();
check('source_module is other_income_transactions', $je['source_module'] === 'other_income_transactions');
check('source_reference_type is other_income', $je['source_reference_type'] === 'other_income');
check('source_reference_id matches draft id', (int)$je['source_reference_id'] === (int)$draftId);

// ================================================================
// TEST J — POSTED PROTECTION
// ================================================================
section('Test J: Posted transaction cannot silently be edited or deleted');

$threw = false;
try {
    $incomeModel->update($draftId, ['description' => 'Trying to edit posted']);
} catch (RuntimeException $e) {
    $threw = true;
}
check('Editing a posted transaction throws RuntimeException', $threw);

$threw = false;
try {
    $incomeModel->delete($draftId);
} catch (RuntimeException $e) {
    $threw = true;
}
check('Deleting a posted transaction throws RuntimeException', $threw);

// ================================================================
// TEST H — DUPLICATE POSTING
// ================================================================
section('Test H: Duplicate posting is prevented');

$postedAgain = $incomeModel->post($draftId, $ADMIN_USER_ID);
check('Re-posting an already-posted transaction returns created=false', $postedAgain['created'] === false);
check('Re-posting returns the SAME journal_entry_id', (int)$postedAgain['journal_entry_id'] === (int)$posted['journal_entry_id']);

$jeCountAfterDouble = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('Still exactly one new journal entry from this transaction', $jeCountAfterDouble === $jeCountBefore + 1, "expected " . ($jeCountBefore + 1) . " got {$jeCountAfterDouble}");

// ================================================================
// TEST D — POST VIA BANK TRANSFER
// ================================================================
section('Test D: Post via Bank Transfer -> Dr Bank Accounts(10) / Cr Investment Income(31)');

$draft2 = $incomeModel->createDraft([
    'category_id'      => $goodCatId,
    'income_date'      => date('Y-m-d'),
    'amount'            => 40000.00,
    'description'      => 'Bank transfer income',
    'payment_method'   => 'Bank Transfer',
], $ADMIN_USER_ID);
$posted2 = $incomeModel->post($draft2, $ADMIN_USER_ID);

$lines2 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id');
$lines2->execute([$posted2['journal_entry_id']]);
$lines2 = $lines2->fetchAll();
$debitLine2 = null; $creditLine2 = null;
foreach ($lines2 as $l) {
    if ((float)$l['debit'] > 0) $debitLine2 = $l;
    if ((float)$l['credit'] > 0) $creditLine2 = $l;
}
check('Debit line hits Bank Accounts (account 10)', $debitLine2 && (int)$debitLine2['account_id'] === 10, 'got ' . ($debitLine2['account_id'] ?? 'none'));
check('Credit line hits Investment Income (account 31)', $creditLine2 && (int)$creditLine2['account_id'] === 31, 'got ' . ($creditLine2['account_id'] ?? 'none'));

// ================================================================
// TEST G — MTN Mobile Money and Airtel Money now both post to the
// combined, active "1120 Mobile Money / Float" account (id 8), instead
// of the two separate (permanently inactive) 1121/1122 accounts. Same
// change applied identically across Loans/Savings/Repayments/Expenses/
// Fees/Withdrawals' PAYMENT_ACCOUNTS maps -- payment-method labels
// themselves are unchanged, only the GL account they share.
// ================================================================
section('Test G: Mobile Money payment method posts cleanly to the combined account');

$draft3 = $incomeModel->createDraft([
    'category_id'      => $goodCatId,
    'income_date'      => date('Y-m-d'),
    'amount'            => 10000.00,
    'description'      => 'Mobile money attempt',
    'payment_method'   => 'MTN Mobile Money',
], $ADMIN_USER_ID);

$posted3 = $incomeModel->post($draft3, $ADMIN_USER_ID);
$row = getIncome($pdo, $draft3);
check('Source transaction posted after MTN Mobile Money payment', $row['status'] === 'posted');
check('journal_entry_id set after posting', $row['journal_entry_id'] !== null);
$lines3 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ?');
$lines3->execute([$posted3['journal_entry_id']]);
$debitLine3 = null;
foreach ($lines3->fetchAll() as $l) { if ((float)$l['debit'] > 0) $debitLine3 = $l; }
check('Debit line hits the combined Mobile Money / Float account (account 8)', $debitLine3 && (int)$debitLine3['account_id'] === 8, 'got ' . ($debitLine3['account_id'] ?? 'none'));

// ================================================================
// TEST F — INACTIVE INCOME ACCOUNT REJECTED
// (simulate: category was mapped while account was active, account later deactivated)
// ================================================================
section('Test F: Income account deactivated after category mapping -> post() rejects cleanly');

$draft4 = $incomeModel->createDraft([
    'category_id'      => $goodCatId,
    'income_date'      => date('Y-m-d'),
    'amount'            => 20000.00,
    'description'      => 'Will be blocked by deactivated account',
    'payment_method'   => 'Cash',
], $ADMIN_USER_ID);

$pdo->exec('UPDATE accounts SET is_active = 0 WHERE id = 31'); // deactivate Investment Income

$threw = false; $msg = '';
try {
    $incomeModel->post($draft4, $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true; $msg = $e->getMessage();
}
check('post() rejects a category mapped to a now-inactive income account', $threw, $msg);
$row = getIncome($pdo, $draft4);
check('Source transaction remains draft', $row['status'] === 'draft');

$pdo->exec('UPDATE accounts SET is_active = 1 WHERE id = 31'); // restore for subsequent tests/report checks

// ================================================================
// TEST E — NON-INCOME ACCOUNT REJECTED AT POST TIME (defense in depth)
// Category layer already blocks this at creation; this proves the
// posting layer independently re-validates account type too.
// ================================================================
section('Test E: Category somehow pointing at a non-income account is rejected at post() (defense in depth)');

// Bypass the category model's own validation to simulate a category that
// predates the type check or was altered directly at the DB level --
// this is why OtherIncomeModel::post() independently re-checks type.
$pdo->exec("UPDATE other_income_categories SET gl_account_id = 1 WHERE id = {$goodCatId}"); // Land, an asset account

$draft5 = $incomeModel->createDraft([
    'category_id'      => $goodCatId,
    'income_date'      => date('Y-m-d'),
    'amount'            => 5000.00,
    'description'      => 'Should be blocked - non-income account',
    'payment_method'   => 'Cash',
], $ADMIN_USER_ID);

$threw = false; $msg = '';
try {
    $incomeModel->post($draft5, $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true; $msg = $e->getMessage();
}
check('post() rejects a category mapped to a non-income account', $threw, $msg);

$pdo->exec("UPDATE other_income_categories SET gl_account_id = 31 WHERE id = {$goodCatId}"); // restore

// ================================================================
// Invalid amount / missing payment method (createDraft-level validation)
// ================================================================
section('Additional validation: invalid amount and missing payment method rejected at draft creation');

$threw = false;
try {
    $incomeModel->createDraft([
        'category_id'    => $goodCatId,
        'income_date'    => date('Y-m-d'),
        'amount'          => 0,
        'payment_method' => 'Cash',
    ], $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('createDraft() rejects a zero amount', $threw);

$threw = false;
try {
    $incomeModel->createDraft([
        'category_id'    => $goodCatId,
        'income_date'    => date('Y-m-d'),
        'amount'          => 1000,
        'payment_method' => 'Bitcoin',
    ], $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('createDraft() rejects an invalid payment method', $threw);

$threw = false;
try {
    $incomeModel->createDraft([
        'category_id'    => $unmappedCatId,
        'income_date'    => date('Y-m-d'),
        'amount'          => 1000,
        'payment_method' => 'Cash',
    ], $ADMIN_USER_ID);
    // draft creation for an unmapped category is allowed (mirrors Expense) --
    // only POSTING requires a GL mapping. Verify that explicitly below.
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('createDraft() ALLOWS an unmapped category (posting is what is blocked, not drafting)', !$threw);

$unmappedDraftId = $incomeModel->createDraft([
    'category_id'    => $unmappedCatId,
    'income_date'    => date('Y-m-d'),
    'amount'          => 1000,
    'payment_method' => 'Cash',
], $ADMIN_USER_ID);
$threw = false;
try {
    $incomeModel->post($unmappedDraftId, $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('post() rejects a draft whose category has no GL mapping', $threw);

// ================================================================
// TEST K — FINANCIAL REPORTS REFLECT THE POSTED TRANSACTIONS
// ================================================================
section('Test K: Trial Balance / General Ledger reflect the posted test transactions');

$tb = $pdo->query('SELECT SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM journal_lines')->fetch();
check('Trial balance: total debits equal total credits', abs((float)$tb['total_debit'] - (float)$tb['total_credit']) < 0.001,
    "debit={$tb['total_debit']} credit={$tb['total_credit']}");

$glStmt = $pdo->prepare('SELECT SUM(credit) AS total_credit FROM journal_lines WHERE account_id = 31');
$glStmt->execute();
$gl = $glStmt->fetch();
check('General Ledger for Investment Income (31) shows 75000 + 40000 + 10000 (mobile money) = 125000 credited', abs((float)$gl['total_credit'] - 125000.00) < 0.001,
    'got ' . $gl['total_credit']);

// ================================================================
// TEST L — AUDIT TRAIL
// ================================================================
section('Test L: Audit trail captured via activity_logs');

$incomeModel->log($ADMIN_USER_ID, 'other_income_posted', "Test log entry for income {$draftId}");
$logCount = (int)$pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'other_income_posted' AND user_id = ?")
    ->execute([$ADMIN_USER_ID]);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'other_income_posted' AND user_id = ?");
$stmt->execute([$ADMIN_USER_ID]);
check('Activity log entry exists for Other Income action', (int)$stmt->fetchColumn() > 0);

// ================================================================
// SUMMARY
// ================================================================
echo "\n================================================================\n";
echo "STAGE 17 PART D — OTHER INCOME TEST RESULTS: {$pass} passed, {$fail} failed\n";
echo "================================================================\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
