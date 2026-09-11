<?php
/**
 * STAGE D — Registration Fee External Reference — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]), a full
 * clone of empower_db's current schema+data (34 known-broken storage
 * artifacts excluded) with the Stage D migration
 * (database/stageD_registration_fee_external_reference.sql) already
 * applied. NEVER touches empower_db.
 *
 * Verifies: FeeModel::markPaid()'s new optional $externalReference
 * parameter persists correctly (or NULL when omitted), FEE-###### /
 * CHR-###### generation and the double-payment guard are unaffected,
 * the accounting posting (JournalService, Dr/Cr, balance) is unchanged,
 * authorization/CSRF on the real HTTP route are unaffected, and Stage A
 * (F1/F2) plus the Session flash fix remain intact.
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

function makeMemberWithCharge(MemberModel $mm, FeeModel $fm, int $admin, string $tag): array {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'StageD', 'last_name' => "M{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000006' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "SD{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'active', 'status_source' => 'automatic',
    ], $admin);
    $memberId = $m['member_id'];
    $chargeId = $fm->manualCharge($memberId, 1, $admin); // fee_id=1 is Registration Fee in this data
    return [$memberId, $chargeId];
}

function row(PDO $pdo, int $id): array {
    return $pdo->query("SELECT * FROM member_fees WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
}

// ================================================================
// SCHEMA
// ================================================================
section('Schema');
$col = $pdo->query("SHOW COLUMNS FROM member_fees WHERE Field='external_reference'")->fetch(PDO::FETCH_ASSOC);
check('external_reference column exists', $col !== false);
check("external_reference is varchar(100)", $col && $col['Type'] === 'varchar(100)', $col['Type'] ?? '');
check('external_reference is nullable', $col && $col['Null'] === 'YES');
check('external_reference has no default', $col && $col['Default'] === null);
check('external_reference has no key/index', $col && $col['Key'] === '');
$existingNull = (int)$pdo->query("SELECT COUNT(*) FROM member_fees WHERE external_reference IS NULL")->fetchColumn();
$existingTotal = (int)$pdo->query("SELECT COUNT(*) FROM member_fees")->fetchColumn();
check('every pre-existing member_fees row has external_reference=NULL', $existingNull === $existingTotal, "{$existingNull}/{$existingTotal}");
check("member_fees.reference_number column untouched (still varchar(30) UNIQUE)",
    (function() use ($pdo) { $c = $pdo->query("SHOW COLUMNS FROM member_fees WHERE Field='reference_number'")->fetch(PDO::FETCH_ASSOC); return $c['Type']==='varchar(30)' && $c['Key']==='UNI'; })());

// ================================================================
// A. Pending fee stays fully NULL on payment-related fields
// ================================================================
section('A. Pending fee (no payment made)');
[$memberA, $chargeA] = makeMemberWithCharge($memberModel, $feeModel, $ADMIN, 'A');
$rA = row($pdo, $chargeA);
check('A: status=pending', $rA['status'] === 'pending');
check('A: journal_entry_id NULL', $rA['journal_entry_id'] === null);
check('A: paid_date NULL', $rA['paid_date'] === null);
check('A: payment_method NULL', $rA['payment_method'] === null);
check('A: cash_reference_number NULL', $rA['cash_reference_number'] === null);
check('A: external_reference NULL', $rA['external_reference'] === null);

// ================================================================
// B. Mark Paid WITH external reference (Cash -> also expects CHR-######)
// ================================================================
section('B. Mark Paid with external reference (Cash)');
[$memberB, $chargeB] = makeMemberWithCharge($memberModel, $feeModel, $ADMIN, 'B');
$jeBefore = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$posted = $feeModel->markPaid($chargeB, 'Cash', $ADMIN, '  MTN123456789  '); // deliberately padded to prove trimming
$rB = row($pdo, $chargeB);
check('B: status=paid', $rB['status'] === 'paid');
check('B: payment_method=Cash', $rB['payment_method'] === 'Cash');
check('B: external_reference trimmed correctly', $rB['external_reference'] === 'MTN123456789', $rB['external_reference'] ?? 'NULL');
check('B: journal_entry_id populated', $rB['journal_entry_id'] !== null);
check('B: paid_date populated', $rB['paid_date'] !== null);
check('B: cash_reference_number is CHR-######', preg_match('/^CHR-\d{6}$/', $rB['cash_reference_number'] ?? '') === 1, $rB['cash_reference_number'] ?? 'NULL');
$jeAfter = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('B: exactly one new journal entry', $jeAfter === $jeBefore + 1);

// ================================================================
// C. Mark Paid WITHOUT external reference
// ================================================================
section('C. Mark Paid without external reference');
[$memberC, $chargeC] = makeMemberWithCharge($memberModel, $feeModel, $ADMIN, 'C');
$feeModel->markPaid($chargeC, 'Bank Transfer', $ADMIN); // old 3-arg call form, backward-compat check
$rC = row($pdo, $chargeC);
check('C: status=paid (old 3-arg call signature still works)', $rC['status'] === 'paid');
check('C: external_reference IS NULL when omitted', $rC['external_reference'] === null);
check('C: no cash_reference for Bank Transfer', $rC['cash_reference_number'] === null);

// Also confirm an explicit empty-string external reference normalizes to NULL, not ''
[$memberC2, $chargeC2] = makeMemberWithCharge($memberModel, $feeModel, $ADMIN, 'C2');
$feeModel->markPaid($chargeC2, 'Other', $ADMIN, '   '); // whitespace-only
$rC2 = row($pdo, $chargeC2);
check('C2: whitespace-only external reference normalizes to NULL', $rC2['external_reference'] === null, var_export($rC2['external_reference'], true));

// ================================================================
// D. Accounting -- balanced Dr/Cr, correct accounts
// ================================================================
section('D. Accounting verification');
$lines = $pdo->query("SELECT jl.account_id, a.code, a.name, jl.debit, jl.credit FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.id={$rB['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
check('D: exactly 2 journal lines for the Cash payment', count($lines) === 2, (string)count($lines));
$totalDebit = array_sum(array_column($lines, 'debit'));
$totalCredit = array_sum(array_column($lines, 'credit'));
check('D: journal balances (debit=credit)', abs($totalDebit - $totalCredit) < 0.01, "{$totalDebit} vs {$totalCredit}");
$debitLine = null; $creditLine = null;
foreach ($lines as $l) { if ((float)$l['debit'] > 0) $debitLine = $l; if ((float)$l['credit'] > 0) $creditLine = $l; }
check('D: Dr Cash at Hand (1110)', $debitLine && $debitLine['code'] === '1110', $debitLine['code'] ?? 'none');
check('D: Cr Membership/Registration Fees (4090)', $creditLine && $creditLine['code'] === '4090', $creditLine['code'] ?? 'none');
check('D: amount matches fee amount (50000)', (float)$debitLine['debit'] === 50000.0 && (float)$creditLine['credit'] === 50000.0);

// ================================================================
// E. Duplicate payment rejected
// ================================================================
section('E. Duplicate payment protection');
$jeBeforeDup = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$threw = false; $msg = '';
try { $feeModel->markPaid($chargeB, 'Cash', $ADMIN, 'SHOULD-NOT-APPLY'); }
catch (InvalidArgumentException $e) { $threw = true; $msg = $e->getMessage(); }
check('E: second Mark Paid on the same charge is rejected', $threw, $msg);
$rBAfter = row($pdo, $chargeB);
check('E: original external_reference unchanged after rejected re-payment', $rBAfter['external_reference'] === 'MTN123456789', $rBAfter['external_reference'] ?? 'NULL');
$jeAfterDup = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('E: no additional journal entry created by the rejected attempt', $jeAfterDup === $jeBeforeDup, "{$jeBeforeDup} -> {$jeAfterDup}");

// ================================================================
// F/G. CSRF + Authorization (real HTTP-style POST, subprocess)
// ================================================================
function renderAs(string $dbName, int $userId, string $roleLabel, string $class, string $method, array $get = [], array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stageD_subproc_' . $counter . '.php';
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
Session::set('user_name', 'Probe {$roleLabel}');
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

section('F. CSRF protection on the real fee-mark-paid HTTP action');
[$memberF, $chargeF] = makeMemberWithCharge($memberModel, $feeModel, $ADMIN, 'F');
$out = renderAs($dbName, $ADMIN, 'admin', 'FeeController', 'markPaid', [], ['id' => $chargeF, 'payment_method' => 'Cash', 'external_reference' => 'X']); // no csrf_token
check('F: missing CSRF did not crash', !crashed($out), $out);
check('F: missing CSRF -- charge remains pending', row($pdo, $chargeF)['status'] === 'pending');

[$memberF2, $chargeF2] = makeMemberWithCharge($memberModel, $feeModel, $ADMIN, 'F2');
$out = renderAs($dbName, $ADMIN, 'admin', 'FeeController', 'markPaid', [], ['id' => $chargeF2, 'payment_method' => 'Cash', 'external_reference' => 'X', 'csrf_token' => 'skip']);
check('F2: correct CSRF token allowed', !crashed($out), $out);
check('F2: correct CSRF -- charge becomes paid with external_reference stored', row($pdo, $chargeF2)['status'] === 'paid' && row($pdo, $chargeF2)['external_reference'] === 'X');

section('G. Authorization on the real fee-mark-paid HTTP action');
$roleUserIds = [
    'chairman'      => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='chairman' AND u.is_active=1 LIMIT 1")->fetchColumn(),
    'loans_officer' => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='loans_officer' AND u.is_active=1 LIMIT 1")->fetchColumn(),
];
function makeSyntheticUser(PDO $pdo, string $roleName, string $tag): int {
    $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='{$roleName}'")->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO users (role_id, full_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$roleId, "StageD Synthetic {$tag}", "staged.synthetic.{$tag}@test.invalid", password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}
if ($roleUserIds['loans_officer'] === 0) { $roleUserIds['loans_officer'] = makeSyntheticUser($pdo, 'loans_officer', 'LO'); }
foreach (['chairman', 'loans_officer'] as $role) {
    if ($roleUserIds[$role] === 0) { check("{$role}: skipped, no account available", true); continue; }
    [$memberG, $chargeG] = makeMemberWithCharge($memberModel, $feeModel, $ADMIN, "G{$role}");
    $out = renderAs($dbName, $roleUserIds[$role], $role, 'FeeController', 'markPaid', [], ['id' => $chargeG, 'payment_method' => 'Cash', 'external_reference' => 'X', 'csrf_token' => 'skip']);
    check("{$role} did not crash", !crashed($out), $out);
    check("{$role} BLOCKED from marking a fee paid", row($pdo, $chargeG)['status'] === 'pending', row($pdo, $chargeG)['status']);
}

// ================================================================
// H. Stage A regression
// ================================================================
section('H. Stage A regression');
$feeCtrlSrc = file_get_contents(__DIR__ . '/../app/controllers/FeeController.php');
check("H: F1 -- charges() still retrieves error flash", str_contains($feeCtrlSrc, "'error'        => Session::flash('error')"));
$chargesViewSrc = file_get_contents(__DIR__ . '/../app/views/fees/charges.php');
check("H: F1 -- charges.php still has the error alert block", str_contains($chargesViewSrc, "!empty(\$error)"));
$feeModelSrc = file_get_contents(__DIR__ . '/../app/models/FeeModel.php');
check("H: F2 -- searchCharges() still orders by charged_date DESC, id DESC", str_contains($feeModelSrc, 'ORDER BY mf.charged_date DESC, mf.id DESC'));

// ================================================================
// I. Session flash regression
// ================================================================
section('I. Session flash regression');
$sessionSrc = file_get_contents(__DIR__ . '/../core/Session.php');
check("I: Session::has() still checks both plain and flash storage", str_contains($sessionSrc, "isset(\$_SESSION[\$key]) || isset(\$_SESSION['_flash'][\$key])"));

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
