<?php
/**
 * STAGE E — Combined "Record Fee & Mark Paid" — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db.
 *
 * Verifies FeeModel::chargeAndMarkPaid() (charges then immediately marks
 * paid, atomically) without changing manualCharge()/markPaid() at all,
 * and that the plain "charge only, pay later" path is completely
 * unaffected. Also re-verifies Stage A/D regressions.
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

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (disposable clone -- never empower_db) ===\n";
$pdo = Database::getInstance()->getConnection();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$ADMIN = 1;
$memberModel = new MemberModel();
$feeModel = new FeeModel();

function makeMember(MemberModel $mm, int $admin, string $tag): int {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'StageE', 'last_name' => "M{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000005' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "SE{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'active', 'status_source' => 'automatic',
    ], $admin);
    return $m['member_id'];
}
function feeRow(PDO $pdo, int $id): array|false {
    return $pdo->query("SELECT * FROM member_fees WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
}

// fee_id=2 is Annual Subscription Fee (one_time Registration Fee, fee_id=1,
// gets auto-charged at member creation, so a fresh member already has a
// pending Registration Fee charge -- using Annual Subscription avoids that
// collision for the "charge only" scenarios below).
$FEE_ANNUAL = 2;

// ================================================================
// A. Plain "charge only" path -- completely unaffected
// ================================================================
section('A. Plain charge-only path (no payment method) unaffected');
$memberA = makeMember($memberModel, $ADMIN, 'A');
$chargeIdA = $feeModel->manualCharge($memberA, $FEE_ANNUAL, $ADMIN);
$rA = feeRow($pdo, $chargeIdA);
check('A: status=pending', $rA['status'] === 'pending');
check('A: no journal_entry_id', $rA['journal_entry_id'] === null);
check('A: no payment_method', $rA['payment_method'] === null);

// ================================================================
// B. chargeAndMarkPaid() -- happy path, Cash, with external reference
// ================================================================
section('B. chargeAndMarkPaid() -- Cash + external reference');
$memberB = makeMember($memberModel, $ADMIN, 'B');
$jeBefore = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$mfBefore = (int)$pdo->query('SELECT COUNT(*) FROM member_fees')->fetchColumn();
$posted = $feeModel->chargeAndMarkPaid($memberB, $FEE_ANNUAL, $ADMIN, 'Cash', 'MTN987654321');
$rB = feeRow($pdo, $posted['member_fee_id']);
check('B: exactly one new member_fees row created', (int)$pdo->query('SELECT COUNT(*) FROM member_fees')->fetchColumn() === $mfBefore + 1);
check('B: status=paid immediately', $rB['status'] === 'paid', $rB['status']);
check('B: payment_method=Cash', $rB['payment_method'] === 'Cash');
check('B: external_reference stored', $rB['external_reference'] === 'MTN987654321', $rB['external_reference'] ?? 'NULL');
check('B: journal_entry_id populated', $rB['journal_entry_id'] !== null);
check('B: paid_date populated', $rB['paid_date'] !== null);
check('B: cash_reference_number is CHA-###### (Annual Subscription prefix)', preg_match('/^CHA-\d{6}$/', $rB['cash_reference_number'] ?? '') === 1, $rB['cash_reference_number'] ?? 'NULL');
check('B: exactly one new journal entry', (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $jeBefore + 1);

$lines = $pdo->query("SELECT jl.debit, jl.credit, a.code FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id={$rB['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
check('B: exactly 2 journal lines', count($lines) === 2, (string)count($lines));
$debit = array_sum(array_column($lines, 'debit'));
$credit = array_sum(array_column($lines, 'credit'));
check('B: journal balances', abs($debit - $credit) < 0.01, "{$debit} vs {$credit}");

// ================================================================
// B2. chargeAndMarkPaid() on Registration Fee (one_time, CHR- prefix) --
//     a genuinely new member has no prior Registration Fee charge, so
//     this exercises the exact fee type shown in the real screenshots.
//     (createWithCompulsoryAccount() does NOT itself auto-charge
//     Registration Fee -- that only happens via MemberController's own
//     handleSave(), not exercised by this model-level test helper -- so
//     a fresh member here genuinely has no pending Registration Fee yet.)
// ================================================================
section('B2. chargeAndMarkPaid() on Registration Fee (one_time)');
$memberB2 = makeMember($memberModel, $ADMIN, 'B2');
$postedB2 = $feeModel->chargeAndMarkPaid($memberB2, 1, $ADMIN, 'Cash', 'BANK-REF-001');
$rB2 = feeRow($pdo, $postedB2['member_fee_id']);
check('B2: status=paid', $rB2['status'] === 'paid');
check('B2: cash_reference_number is CHR-###### (Registration Fee prefix)', preg_match('/^CHR-\d{6}$/', $rB2['cash_reference_number'] ?? '') === 1, $rB2['cash_reference_number'] ?? 'NULL');
check('B2: reference_number (FEE-######) untouched by the new external_reference field', preg_match('/^FEE-\d{6}$/', $rB2['reference_number'] ?? '') === 1, $rB2['reference_number'] ?? 'NULL');

// ================================================================
// C. chargeAndMarkPaid() -- no external reference (optional)
// ================================================================
section('C. chargeAndMarkPaid() -- Bank Transfer, no external reference');
$memberC = makeMember($memberModel, $ADMIN, 'C');
$postedC = $feeModel->chargeAndMarkPaid($memberC, $FEE_ANNUAL, $ADMIN, 'Bank Transfer');
$rC = feeRow($pdo, $postedC['member_fee_id']);
check('C: status=paid', $rC['status'] === 'paid');
check('C: external_reference NULL when omitted', $rC['external_reference'] === null);
check('C: no cash reference for Bank Transfer', $rC['cash_reference_number'] === null);

// ================================================================
// D. Failure atomicity -- invalid payment method rolls back the WHOLE
//    operation, no orphaned pending charge left behind
// ================================================================
section('D. Failure atomicity -- invalid payment method');
$memberD = makeMember($memberModel, $ADMIN, 'D');
$mfBeforeD = (int)$pdo->query('SELECT COUNT(*) FROM member_fees')->fetchColumn();
$jeBeforeD = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$threw = false; $msg = '';
try { $feeModel->chargeAndMarkPaid($memberD, $FEE_ANNUAL, $ADMIN, 'Bitcoin'); }
catch (InvalidArgumentException $e) { $threw = true; $msg = $e->getMessage(); }
check('D: invalid payment method throws', $threw, $msg);
check('D: NO member_fees row was left behind (rolled back)', (int)$pdo->query('SELECT COUNT(*) FROM member_fees')->fetchColumn() === $mfBeforeD, 'row count changed');
check('D: NO journal entry was created', (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $jeBeforeD);

// Duplicate-fee failure atomicity too (member already has this fee charged)
$memberD2 = makeMember($memberModel, $ADMIN, 'D2');
$feeModel->manualCharge($memberD2, $FEE_ANNUAL, $ADMIN); // pre-existing charge for this fee
$mfBeforeD2 = (int)$pdo->query('SELECT COUNT(*) FROM member_fees')->fetchColumn();
$threw2 = false;
try { $feeModel->chargeAndMarkPaid($memberD2, $FEE_ANNUAL, $ADMIN, 'Cash'); }
catch (InvalidArgumentException $e) { $threw2 = true; }
check('D2: duplicate-fee rejection still applies inside chargeAndMarkPaid()', $threw2);
check('D2: no second member_fees row created', (int)$pdo->query('SELECT COUNT(*) FROM member_fees')->fetchColumn() === $mfBeforeD2);

// ================================================================
// E. Real HTTP-style controller flow (both branches)
// ================================================================
function renderAs(string $dbName, int $userId, string $roleLabel, string $class, string $method, array $get = [], array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stageE_subproc_' . $counter . '.php';
    $getLines = ''; foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $postLines = '';
    if ($post) {
        $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n";
        foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    }
    $body = <<<PHP
<?php
chdir(__DIR__ . '/..');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', '{$dbName}');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
require 'app/config/config.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', {$userId});
Session::set('user_role', '{$roleLabel}');
Session::set('last_activity', time());
Session::set('csrf_token', 'skip');
{$getLines}
{$postLines}
try {
    (new {$class}())->{$method}();
    echo "\\nRESULT:REACHED";
} catch (Throwable \$e) {
    echo "RESULT:EXCEPTION:" . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}
function crashed(string $out): bool { return str_contains($out, 'RESULT:EXCEPTION'); }

section('E. Real HTTP chargeStore() -- with and without payment method');
$memberE1 = makeMember($memberModel, $ADMIN, 'E1');
$out = renderAs($dbName, $ADMIN, 'admin', 'FeeController', 'chargeStore', [], [
    'member_id' => $memberE1, 'fee_id' => $FEE_ANNUAL, 'csrf_token' => 'skip',
    'payment_method' => 'Cash', 'external_reference' => 'HTTPTEST1',
]);
check('E1: combined charge+pay via real HTTP action did not crash', !crashed($out), $out);
$rows1 = $pdo->query("SELECT status, external_reference FROM member_fees WHERE member_id={$memberE1}")->fetch(PDO::FETCH_ASSOC);
check('E1: resulting charge is paid with external reference stored', $rows1 && $rows1['status'] === 'paid' && $rows1['external_reference'] === 'HTTPTEST1', json_encode($rows1));

$memberE2 = makeMember($memberModel, $ADMIN, 'E2');
$out2 = renderAs($dbName, $ADMIN, 'admin', 'FeeController', 'chargeStore', [], [
    'member_id' => $memberE2, 'fee_id' => $FEE_ANNUAL, 'csrf_token' => 'skip',
]); // no payment_method at all -- must behave exactly like before
check('E2: charge-only via real HTTP action did not crash', !crashed($out2), $out2);
$rows2 = $pdo->query("SELECT status, journal_entry_id FROM member_fees WHERE member_id={$memberE2}")->fetch(PDO::FETCH_ASSOC);
check('E2: resulting charge remains pending, no journal', $rows2 && $rows2['status'] === 'pending' && $rows2['journal_entry_id'] === null, json_encode($rows2));

section('F. Authorization + CSRF unaffected on the combined path');
$out3 = renderAs($dbName, $ADMIN, 'admin', 'FeeController', 'chargeStore', [], [
    'member_id' => $memberE1, 'fee_id' => $FEE_ANNUAL, 'payment_method' => 'Cash',
]); // missing csrf_token
check('F: missing CSRF did not crash', !crashed($out3), $out3);

$roleUserIds = [
    'loans_officer' => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='loans_officer' AND u.is_active=1 LIMIT 1")->fetchColumn(),
];
if ($roleUserIds['loans_officer'] === 0) {
    $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='loans_officer'")->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO users (role_id, full_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$roleId, 'StageE Synthetic LO', 'stagee.synthetic.lo@test.invalid', password_hash('x', PASSWORD_DEFAULT)]);
    $roleUserIds['loans_officer'] = (int)$pdo->lastInsertId();
}
$memberF = makeMember($memberModel, $ADMIN, 'F');
$out4 = renderAs($dbName, $roleUserIds['loans_officer'], 'loans_officer', 'FeeController', 'chargeStore', [], [
    'member_id' => $memberF, 'fee_id' => $FEE_ANNUAL, 'payment_method' => 'Cash', 'csrf_token' => 'skip',
]);
check('F: loans_officer did not crash', !crashed($out4), $out4);
$rowsF = $pdo->query("SELECT COUNT(*) FROM member_fees WHERE member_id={$memberF}")->fetchColumn();
check('F: loans_officer BLOCKED -- no charge created at all', (int)$rowsF === 0, (string)$rowsF);

// ================================================================
// G. Stage A / D regression
// ================================================================
section('G. Stage A / D regression');
$feeCtrlSrc = file_get_contents(__DIR__ . '/../app/controllers/FeeController.php');
check('G: F1 -- charges() still retrieves error flash', str_contains($feeCtrlSrc, "'error'        => Session::flash('error')"));
$feeModelSrc = file_get_contents(__DIR__ . '/../app/models/FeeModel.php');
check('G: F2 -- searchCharges() still deterministic order', str_contains($feeModelSrc, 'ORDER BY mf.charged_date DESC, mf.id DESC'));
check('G: markPaid() signature unchanged (4 params, externalReference optional)', str_contains($feeModelSrc, 'function markPaid(int $memberFeeId, string $paymentMethod, int $userId, ?string $externalReference = null)'));
check('G: manualCharge() signature unchanged', str_contains($feeModelSrc, 'function manualCharge(int $memberId, int $feeId, ?int $createdBy = null)'));
$sessionSrc = file_get_contents(__DIR__ . '/../core/Session.php');
check("G: Session::has() flash fix intact", str_contains($sessionSrc, "isset(\$_SESSION[\$key]) || isset(\$_SESSION['_flash'][\$key])"));

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
