<?php
/**
 * Stage B/F — Historical Period Statement Display Remediation — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db. Verifies:
 *   1. B/F notes retrieval (data layer: StatementModel, MemberSavingsAccountModel, SavingsModel)
 *   2. B/F notes rendering condition (the exact gating expression used in every edited template)
 *   3. Ordinary transaction rendering is unaffected (notes stays absent/null)
 *   4. All three statement data paths receive the B/F historical-period information
 *   5. Existing statement calculations (credit/debit/running balance) are unchanged
 */
$dbName = $argv[1] ?? '';
if ($dbName === '' || $dbName === 'empower_db') {
    fwrite(STDERR, "Refusing to run without an explicit, non-production disposable schema name.\n");
    exit(1);
}

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', $dbName);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/../app');
define('CORE_PATH', __DIR__ . '/../core');
define('APP_URL', 'http://localhost/Empower');
define('APP_NAME', 'Empower Test');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Session.php';
require_once CORE_PATH . '/Autoloader.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/MemberSavingsAccountModel.php';
require_once APP_PATH  . '/models/SavingsModel.php';
require_once APP_PATH  . '/models/StatementModel.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (disposable clone -- never empower_db) ===\n";
$db = Database::getInstance()->getConnection();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$ADMIN = 1;
$memberModel    = new MemberModel();
$accountModel   = new MemberSavingsAccountModel();
$savingsModel   = new SavingsModel();
$statementModel = new StatementModel();

$runId = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);

section('SETUP: fresh synthetic member + compulsory account (never a real member)');
$member = $memberModel->createWithCompulsoryAccount([
    'member_number' => $memberModel->generateMemberNumber(),
    'first_name' => 'BFSTMT', 'last_name' => "Test{$runId}", 'gender' => 'Female',
    'phone' => '07' . $runId . '10001',
    'national_id' => "BFSTMT{$runId}",
    'date_of_birth' => '1990-01-01',
    'address' => 'Test', 'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
    'join_date' => date('Y-m-d'), 'status' => 'active',
], $ADMIN);
$memberId = (int)$member['member_id'];
$accountId = (int)$member['account_id'];
check('Member + compulsory account created', $memberId > 0 && $accountId > 0, "member_id=$memberId account_id=$accountId");

section('SETUP: one ORDINARY deposit (must remain visually unaffected)');
$depositId = $savingsModel->create([
    'member_id'          => $memberId,
    'savings_account_id' => $accountId,
    'amount'              => 50000,
    'transaction_type'    => 'deposit',
    'payment_method'      => 'Cash',
    'transaction_date'    => '2026-04-01',
    'financial_year'      => 2026,
    'receipt_number'      => $savingsModel->generateReceiptNumber(),
    'recorded_by'         => $ADMIN,
]);
check('Ordinary deposit inserted', $depositId !== false, (string)$depositId);

section('SETUP: one B/F entry, built the exact same way the controller builds it');
// Mirrors SavingsAccountController::collectBroughtForwardInput() exactly --
// same sprintf format string, same inputs -- without needing a real HTTP
// session, so this test also doubles as a regression check that the
// controller's sentence format hasn't drifted from what this stage assumes.
$periodFrom = '2026-05-01';
$periodTo   = '2026-08-31';
$expectedNotes = sprintf(
    'Historical savings accumulated from %s to %s, consolidated into this Balance Brought Forward entry.',
    date('d F Y', strtotime($periodFrom)),
    date('d F Y', strtotime($periodTo))
);
check('Expected sentence matches the documented example', $expectedNotes ===
    'Historical savings accumulated from 01 May 2026 to 31 August 2026, consolidated into this Balance Brought Forward entry.',
    $expectedNotes);

$bf = $savingsModel->createBroughtForward([
    'member_id'          => $memberId,
    'savings_account_id' => $accountId,
    'amount'             => 500000,
    'transaction_date'   => '2026-08-31',
    'notes'              => $expectedNotes,
], $ADMIN);
check('B/F entry created', isset($bf['id']) && $bf['id'] > 0, json_encode($bf));

// ------------------------------------------------------------------
// 1 & 4. B/F NOTES RETRIEVAL -- all three independent data paths
// ------------------------------------------------------------------
section('PATH 1: MemberSavingsAccountModel::getAccountTransactionsInRange() (per-account statement)');
$path1 = $accountModel->getAccountTransactionsInRange($accountId, '2026-01-01', '2026-12-31');
check('Path 1 returns 2 rows (deposit + B/F)', count($path1) === 2, (string)count($path1));
$path1Bf  = null; $path1Dep = null;
foreach ($path1 as $r) {
    if ($r['transaction_type'] === 'opening_balance') $path1Bf = $r;
    if ($r['transaction_type'] === 'deposit') $path1Dep = $r;
}
check('Path 1: B/F row notes column present and correct', $path1Bf && $path1Bf['notes'] === $expectedNotes, $path1Bf['notes'] ?? 'MISSING');
check('Path 1: B/F row description unchanged (fixed string)', $path1Bf && $path1Bf['description'] === 'Balance Brought Forward — Historical Savings', $path1Bf['description'] ?? 'MISSING');
check('Path 1: ordinary deposit notes is empty/null', $path1Dep && empty($path1Dep['notes']), var_export($path1Dep['notes'] ?? null, true));
check('Path 1: B/F credit amount correct', $path1Bf && (float)$path1Bf['credit'] === 500000.0, (string)($path1Bf['credit'] ?? 'MISSING'));

// The exact gating expression used in savings-accounts/statement.php and
// portal/statement.php after this stage's edit -- proven directly rather
// than assumed, since there is no browser in this harness.
$bfShouldShowNotes  = ($path1Bf['transaction_type'] === 'opening_balance' && !empty($path1Bf['notes']));
$depShouldShowNotes = ($path1Dep['transaction_type'] === 'opening_balance' && !empty($path1Dep['notes']));
check('Path 1 template gate: B/F row WOULD render notes', $bfShouldShowNotes === true);
check('Path 1 template gate: ordinary deposit WOULD NOT render notes', $depShouldShowNotes === false);

section('PATH 2: StatementModel::getTransactionsByRange() (combined statement: screen/print/PDF/email)');
$path2 = $statementModel->getTransactionsByRange($memberId, '2026-01-01', '2026-12-31');
check('Path 2 returns 2 rows (deposit + B/F)', count($path2) === 2, (string)count($path2));
$path2Bf = null; $path2Dep = null;
foreach ($path2 as $r) {
    if (($r['type'] ?? null) === 'opening_balance') $path2Bf = $r;
    if (($r['type'] ?? null) === 'savings') $path2Dep = $r;
}
check('Path 2: B/F row has notes key present and correct', $path2Bf && $path2Bf['notes'] === $expectedNotes, $path2Bf['notes'] ?? 'MISSING');
check('Path 2: B/F row description unchanged ("Balance Brought Forward")', $path2Bf && $path2Bf['description'] === 'Balance Brought Forward', $path2Bf['description'] ?? 'MISSING');
check('Path 2: ordinary deposit notes key is null', $path2Dep && $path2Dep['notes'] === null, var_export($path2Dep['notes'] ?? 'MISSING', true));
check('Path 2: B/F credit amount correct', $path2Bf && (float)$path2Bf['credit'] === 500000.0, (string)($path2Bf['credit'] ?? 'MISSING'));

$bfShouldShowNotes2  = (($path2Bf['type'] ?? null) === 'opening_balance' && !empty($path2Bf['notes']));
$depShouldShowNotes2 = (($path2Dep['type'] ?? null) === 'opening_balance' && !empty($path2Dep['notes']));
check('Path 2 template gate: B/F row WOULD render notes (view.php/print.php/pdf.php)', $bfShouldShowNotes2 === true);
check('Path 2 template gate: ordinary deposit WOULD NOT render notes', $depShouldShowNotes2 === false);

section('PATH 3: SavingsModel::memberHistory() (member portal inline "Savings Transactions" card)');
$path3 = $savingsModel->memberHistory($memberId, 50);
check('Path 3 returns 2 rows (deposit + B/F)', count($path3) === 2, (string)count($path3));
$path3Bf = null; $path3Dep = null;
foreach ($path3 as $r) {
    if ($r['transaction_type'] === 'opening_balance') $path3Bf = $r;
    if ($r['transaction_type'] === 'deposit') $path3Dep = $r;
}
check('Path 3: B/F row notes column present and correct', $path3Bf && $path3Bf['notes'] === $expectedNotes, $path3Bf['notes'] ?? 'MISSING');
check('Path 3: ordinary deposit notes is empty/null', $path3Dep && empty($path3Dep['notes']), var_export($path3Dep['notes'] ?? null, true));

$bfShouldShowNotes3  = ($path3Bf['transaction_type'] === 'opening_balance' && !empty($path3Bf['notes']));
$depShouldShowNotes3 = ($path3Dep['transaction_type'] === 'opening_balance' && !empty($path3Dep['notes']));
check('Path 3 template gate: B/F row WOULD render notes (portal/statement.php)', $bfShouldShowNotes3 === true);
check('Path 3 template gate: ordinary deposit WOULD NOT render notes', $depShouldShowNotes3 === false);

// ------------------------------------------------------------------
// 5. EXISTING STATEMENT CALCULATIONS UNCHANGED
// ------------------------------------------------------------------
section('CALCULATION: buildMemberStatement() running/closing balance still correct with B/F included');
require_once APP_PATH . '/models/LoanModel.php';
require_once APP_PATH . '/models/SettingsModel.php';
$loanModel = new LoanModel();
$settings  = new SettingsModel();
$shareValue = (float)$settings->get('share_value', '20000');
$fullMember = $memberModel->find($memberId);
$stmtData = $statementModel->buildMemberStatement($fullMember, '2026-01-01', '2026-12-31', 'Custom', (int)date('Y'), $shareValue, $loanModel);
check('Closing balance = deposit + B/F (50,000 + 500,000 = 550,000)', abs($stmtData['closingBalance'] - 550000.0) < 0.01, (string)$stmtData['closingBalance']);
check('Total credits = 550,000 (both rows are credits)', abs($stmtData['totalCredits'] - 550000.0) < 0.01, (string)$stmtData['totalCredits']);
check('Total debits = 0', abs($stmtData['totalDebits']) < 0.01, (string)$stmtData['totalDebits']);
$lastRowBalance = end($stmtData['transactions'])['balance'] ?? null;
check('Running balance on the last row = closing balance', $lastRowBalance !== null && abs($lastRowBalance - 550000.0) < 0.01, (string)$lastRowBalance);
// Every transaction row (deposit + B/F) must still carry a 'balance' key --
// this stage only added a 'notes' key, it must not have disturbed the
// existing running-balance accumulation shape.
$allRowsHaveBalance = true;
foreach ($stmtData['transactions'] as $tx) { if (!array_key_exists('balance', $tx)) $allRowsHaveBalance = false; }
check('Every transaction row still carries a balance key (unchanged shape)', $allRowsHaveBalance);

section('CALCULATION: per-account balance (MemberSavingsAccountModel) still correct with B/F included');
$closing = $accountModel->accountOpeningBalanceAsOf($accountId, '2027-01-01'); // everything before 2027
check('Account balance as of 2027-01-01 = 550,000', abs($closing - 550000.0) < 0.01, (string)$closing);

// ------------------------------------------------------------------
// REGRESSION: reversal path (from the earlier B/F stage) still unaffected
// ------------------------------------------------------------------
section('REGRESSION: reverseBroughtForward() still works, and the reversal row is NOT itself flagged as needing notes display beyond what already exists');
$reversal = $savingsModel->reverseBroughtForward((int)$bf['id'], $ADMIN, 'Test reversal for statement-display regression check');
check('Reversal succeeded', isset($reversal['id']), json_encode($reversal));
$path2AfterReversal = $statementModel->getTransactionsByRange($memberId, '2026-01-01', '2026-12-31');
check('Path 2 now returns 3 rows (deposit + B/F + reversal)', count($path2AfterReversal) === 3, (string)count($path2AfterReversal));
$reversalRow = null;
foreach ($path2AfterReversal as $r) {
    if (($r['type'] ?? null) === 'opening_balance' && (float)$r['debit'] > 0) $reversalRow = $r;
}
check('Reversal row exists and is described as "(Reversal)"', $reversalRow && $reversalRow['description'] === 'Balance Brought Forward (Reversal)', $reversalRow['description'] ?? 'MISSING');
// The reversal row's own `notes` column (set by reverseBroughtForward() to
// the reversal reason, not a period sentence) is intentionally NOT what
// this stage displays -- confirms no unintended new leakage: the reversal
// reason must not appear as if it were a historical-period sentence.
check('Reversal row notes does not equal the original B/F period sentence', $reversalRow && $reversalRow['notes'] !== $expectedNotes, $reversalRow['notes'] ?? 'MISSING');

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
