<?php
/**
 * ISOLATED — Account-level savings statement tests (2026-09).
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Audit finding this stage was built on: SavingsAccountController::
 * statement() was ALREADY savings_account_id-scoped (confirmed by reading
 * MemberSavingsAccountModel::getAccountTransactions()'s WHERE clause) --
 * the separate StatementController ("Member Statements") is a distinct,
 * intentional cross-account financial-profile feature (savings+shares+
 * loans by member_id) and is out of scope here, left untouched. This
 * stage added: date-range/period support with a real opening-balance-
 * as-of calculation (replacing an approximation that only worked by
 * accident within the old 100-row cap), fixed_monthly in the type
 * labels, and direct "Statement" links from the account list tables.
 * Zero schema changes.
 *
 * 2026-09 update: the fixed_monthly account type was fully removed
 * (explicit user decision) after this test was originally written --
 * the third test account below now uses 'voluntary' instead, since this
 * test was never actually exercising fixed_monthly-specific behavior,
 * just using it as a convenient third distinguishable account.
 */
define('DB_NAME', 'empower_db_ivms_test');
chdir(__DIR__);
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}

$pdo = Database::getInstance()->getConnection();
$accountModel = new MemberSavingsAccountModel();
$savingsModel = new SavingsModel();

function renderStatement(int $accountId, array $get = [], string $role = 'admin'): string {
    $_GET = array_merge(['page' => 'savings-account-statement', 'id' => (string)$accountId], $get);
    Session::set('user_role', $role);
    ob_start();
    try {
        (new SavingsAccountController())->statement();
    } catch (Throwable $e) {
        ob_end_clean();
        return 'EXCEPTION: ' . $e->getMessage();
    }
    return ob_get_clean();
}

function deposit(SavingsModel $m, int $memberId, int $accountId, float $amount, string $date): void {
    $input = [
        'member_id' => $memberId, 'savings_account_id' => $accountId, 'amount' => $amount,
        'transaction_type' => 'deposit', 'payment_method' => 'Cash', 'reference_number' => null,
        'transaction_date' => $date, 'notes' => null,
    ];
    $input['receipt_number'] = $m->generateReceiptNumber();
    $input['recorded_by'] = 1;
    $input['financial_year'] = (int)date('Y', strtotime($date));
    $m->recordDepositWithPosting($input, 1);
}

Session::start();
Session::set('user_id', 1);
Session::set('user_name', 'Test admin');
Session::set('last_activity', time());

// A test member with THREE savings accounts (all voluntary -- the third
// stands in for what used to be a fixed_monthly account before that
// type was removed; nothing here ever tested fixed_monthly-specific
// behavior, just used it as a convenient third distinguishable account)
// -- deliberately distinguishable transaction amounts on each, per the
// spec's Section 24 test-data convention.
$memberId = (int)$pdo->query("SELECT id FROM members ORDER BY id LIMIT 1")->fetchColumn();

echo "=== SECTION A: Test fixtures ===\n";
$accA = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-01-01'], [['member_id' => $memberId, 'role' => 'primary']], 1);
$accB = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-01-01'], [['member_id' => $memberId, 'role' => 'primary']], 1);
$accFM = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-01-01'], [['member_id' => $memberId, 'role' => 'primary']], 1);
ok($accA > 0 && $accB > 0 && $accFM > 0, 'Test member has 3 distinct savings accounts created', "A=$accA B=$accB FM=$accFM");

// Distinguishable deposits per account -- values chosen so no individual
// amount or running total on one account can coincidentally collide with
// (or appear as a substring of) any figure on another account's
// statement, which would otherwise produce a false failure below.
deposit($savingsModel, $memberId, $accA, 111111, '2026-07-05');
deposit($savingsModel, $memberId, $accA, 224444, '2026-07-15');
deposit($savingsModel, $memberId, $accB, 366222, '2026-07-10');
deposit($savingsModel, $memberId, $accFM, 100000, '2026-07-10');
deposit($savingsModel, $memberId, $accFM, 150000, '2026-08-10');
deposit($savingsModel, $memberId, $accFM, 100000, '2026-09-10');

echo "\n=== SECTION B: Account isolation ===\n";
$htmlA = renderStatement($accA, ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']);
ok(str_contains($htmlA, '111,111.00') && str_contains($htmlA, '224,444.00'), 'Account A statement shows its own deposits');
ok(!str_contains($htmlA, '366,222.00'), 'Account A statement does NOT show Account B\'s deposit');
ok(!str_contains($htmlA, '100,000.00'), 'Account A statement does NOT show the third account\'s deposit');

$htmlB = renderStatement($accB, ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']);
ok(str_contains($htmlB, '366,222.00'), 'Account B statement shows its own deposit');
ok(!str_contains($htmlB, '111,111.00') && !str_contains($htmlB, '224,444.00'), 'Account B statement does NOT show Account A\'s deposits');

$htmlFM = renderStatement($accFM, ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']);
ok(str_contains($htmlFM, 'Voluntary Savings'), 'Third test account statement identifies itself as Voluntary Savings');
ok(!str_contains($htmlFM, '111,111.00') && !str_contains($htmlFM, '366,222.00'), 'Third account statement does NOT show Account A/B transactions');

echo "\n=== SECTION C: Member summary still aggregates all 3 accounts ===\n";
$summary = $accountModel->getMemberSavingsSummary($memberId);
$ids = array_column($summary['accounts'], 'id');
ok(in_array($accA, $ids) && in_array($accB, $ids) && in_array($accFM, $ids), 'Member savings summary lists all 3 accounts');
// Sum only this test's 3 accounts (the shared test member may carry other
// fixture accounts left over from earlier, unrelated test runs) --
// confirms the summary's per-account balances are individually correct
// and additive, without depending on the member's grand total being
// pristine.
$byId = array_column($summary['accounts'], 'balance', 'id');
$ourTotal = ($byId[$accA] ?? 0) + ($byId[$accB] ?? 0) + ($byId[$accFM] ?? 0);
$expectedTotal = 111111 + 224444 + 366222 + 100000 + 150000 + 100000;
ok(abs($ourTotal - $expectedTotal) < 0.01, 'Member savings summary correctly sums this test\'s 3 accounts', "expected={$expectedTotal} got={$ourTotal}");

echo "\n=== SECTION D: Balances (opening/closing/running, period-scoped) ===\n";
// Before any FM deposit.
$html = renderStatement($accFM, ['date_from' => '2026-06-01', 'date_to' => '2026-06-30']);
ok(str_contains($html, 'No transactions in this period'), 'Period before any deposit shows no transactions');
preg_match('/Opening Balance<\/td>\s*<td class="text-right amount-balance">Shs ([\d,]+\.\d+)/', $html, $m);
ok(($m[1] ?? '') === '0.00', 'Opening balance before any activity is 0.00', $m[1] ?? 'NONE');

// July only: opening 0, one 100k deposit, closing 100k.
$html = renderStatement($accFM, ['date_from' => '2026-07-01', 'date_to' => '2026-07-31']);
preg_match('/Opening Balance<\/td>\s*<td class="text-right amount-balance">Shs ([\d,]+\.\d+)/', $html, $mOpen);
preg_match('/Closing Balance<\/td>\s*<td class="text-right amount-balance">Shs ([\d,]+\.\d+)/', $html, $mClose);
ok(($mOpen[1] ?? '') === '0.00', 'July opening balance is 0.00 (nothing before July)', $mOpen[1] ?? 'NONE');
ok(($mClose[1] ?? '') === '100,000.00', 'July closing balance is 100,000.00', $mClose[1] ?? 'NONE');

// August only: opening should carry forward July's closing (100k), plus August's 150k = 250k closing.
$html = renderStatement($accFM, ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);
preg_match('/Opening Balance<\/td>\s*<td class="text-right amount-balance">Shs ([\d,]+\.\d+)/', $html, $mOpen);
preg_match('/Closing Balance<\/td>\s*<td class="text-right amount-balance">Shs ([\d,]+\.\d+)/', $html, $mClose);
ok(($mOpen[1] ?? '') === '100,000.00', 'August opening balance correctly carries forward July\'s closing balance', $mOpen[1] ?? 'NONE');
ok(($mClose[1] ?? '') === '250,000.00', 'August closing balance = opening + August deposit', $mClose[1] ?? 'NONE');

// Full history: closing = live account balance. Uses a fixed future end
// date (not date('Y-m-d')/"today") since this test's synthetic deposits
// are dated ahead of the sandbox's current system date.
$liveBalance = $accountModel->getAccountBalance($accFM);
$html = renderStatement($accFM, ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']);
preg_match('/Closing Balance<\/td>\s*<td class="text-right amount-balance">Shs ([\d,]+\.\d+)/', $html, $mClose);
ok(number_format($liveBalance, 2) === ($mClose[1] ?? ''), 'Full-history closing balance matches the account\'s live balance', "live={$liveBalance} shown=" . ($mClose[1] ?? 'NONE'));

echo "\n=== SECTION E: Date boundaries ===\n";
// A deposit dated exactly on the range boundary must be included (BETWEEN
// is inclusive). Uses 2026-09-30 -- still inside the only currently-open
// accounting period (Q3 2026, 2026-07-01 to 2026-09-30) in this test DB.
deposit($savingsModel, $memberId, $accA, 599995, '2026-09-30');
$html = renderStatement($accA, ['date_from' => '2026-09-30', 'date_to' => '2026-09-30']);
ok(str_contains($html, '599,995.00'), 'A transaction dated exactly on the from/to boundary is included');
$html = renderStatement($accA, ['date_from' => '2026-07-01', 'date_to' => '2026-09-29']);
ok(!str_contains($html, '599,995.00'), 'A transaction one day after the range end is excluded');

echo "\n=== SECTION F: Default (no period given) ===\n";
$htmlNoParams = renderStatement($accFM);
ok(!str_contains($htmlNoParams, 'EXCEPTION'), 'Statement with no date params renders without error (defaults to opened_date..today)');
ok(str_contains($htmlNoParams, '100,000.00') && str_contains($htmlNoParams, '150,000.00'), 'Default (no period) view shows full account history');

echo "\n=== SECTION G: Invalid input handling ===\n";
$htmlInvalid = renderStatement($accFM, ['date_from' => 'not-a-date', 'date_to' => 'also-not-a-date']);
ok(!str_contains($htmlInvalid, 'EXCEPTION') && !str_contains($htmlInvalid, 'Fatal error'), 'Invalid date params fall back safely instead of erroring');

// statement() calls die() for an unknown account id, which would
// terminate this whole script if called in-process -- run it in its own
// subprocess, matching this repo's established pattern for any
// redirect()/die()-calling action.
$subFile = __DIR__ . '/tmp_stmt_404_check.php';
file_put_contents($subFile, <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', 1);
Session::set('user_role', 'admin');
\$_GET = ['page' => 'savings-account-statement', 'id' => '999999999'];
(new SavingsAccountController())->statement();
PHP
);
$out404 = shell_exec('"' . PHP_BINARY . '" "' . $subFile . '" 2>&1');
@unlink($subFile);
ok(str_contains($out404 ?? '', 'Savings account not found') && !str_contains($out404 ?? '', 'Fatal error'),
    'A nonexistent account id is rejected cleanly (404), not a crash', $out404 ?? '');

echo "\n=== SECTION H: Role access (matches the controller's existing broad staff gate, unchanged) ===\n";
foreach (['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'office_admin'] as $role) {
    $html = renderStatement($accFM, ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $role);
    ok(str_contains($html, 'Voluntary Savings') && !str_contains($html, 'EXCEPTION'), "$role can view the account statement (unchanged access)", substr($html, 0, 150));
}

echo "\n=== SECTION I: Overview / member-summary carry a direct Statement link ===\n";
$overviewSrc = file_get_contents(__DIR__ . '/app/views/savings-accounts/overview.php');
ok(str_contains($overviewSrc, 'savings-account-statement'), 'Savings Accounts overview table links directly to the account statement');
$summarySrc = file_get_contents(__DIR__ . '/app/views/savings-accounts/member-summary.php');
ok(str_contains($summarySrc, 'savings-account-statement'), 'Member savings summary table links directly to the account statement');

echo "\n=== SECTION J: Regression -- other account types unaffected ===\n";
ok(str_contains($htmlA, 'Voluntary Savings'), 'A plain Voluntary account statement still correctly identifies its type');
$balA = $accountModel->getAccountBalance($accA);
ok(abs($balA - (111111 + 224444 + 599995)) < 0.01, 'Voluntary account balance still correctly derived from its own ledger', (string)$balA);

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
