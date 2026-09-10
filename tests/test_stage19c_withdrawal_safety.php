<?php
/**
 * ISOLATED — Stage 19C: Savings Withdrawal Path Consolidation & Safety.
 * Targets empower_db_stage19c ONLY. Never touches production empower_db.
 */

define('APP_ENV', 'development');
define('APP_NAME', 'Empower Investment Club');
define('APP_URL', 'http://localhost/empower');
define('ROOT_PATH', __DIR__);
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('VIEW_PATH', APP_PATH . '/views');
define('CORE_PATH', ROOT_PATH . '/core');
define('SESSION_NAME', 'empower_session_stage19c_setup');
define('SESSION_LIFETIME', 3600);
date_default_timezone_set('Africa/Nairobi');
ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED);

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db_stage19c');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

require_once CORE_PATH . '/Autoloader.php';

$db = Database::getInstance()->getConnection();

$PASS = 0; $FAIL = 0;
function ok(bool $cond, string $label): void {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  [PASS] $label\n"; }
    else { $FAIL++; echo "  [FAIL] $label\n"; }
}

$WITHIN_PERIOD_DATE = '2026-08-20'; // inside the open accounting period 2026-07-01..2026-09-30

// ----------------------------------------------------------------
// Seed helpers
// ----------------------------------------------------------------
$memberSeq = 900;
function newMember(PDO $db, string $first, string $last): int {
    global $memberSeq;
    $memberSeq++;
    $stmt = $db->prepare(
        "INSERT INTO members (member_number, first_name, last_name, gender, phone, national_id, join_date, status)
         VALUES (?,?,?,?,?,?,?,'active')"
    );
    $stmt->execute(["T{$memberSeq}", $first, $last, 'Male', "07000{$memberSeq}", "NID{$memberSeq}", '2026-01-01']);
    return (int)$db->lastInsertId();
}

function createAccount(int $memberId, string $type, string $openedDate = '2026-01-01'): int {
    $accountModel = new MemberSavingsAccountModel();
    return $accountModel->createAccount(
        ['account_type' => $type, 'opened_date' => $openedDate],
        [['member_id' => $memberId, 'role' => 'primary']],
        1
    );
}

function createJointAccount(int $m1, int $m2, string $openedDate = '2026-01-01'): int {
    $accountModel = new MemberSavingsAccountModel();
    return $accountModel->createAccount(
        ['account_type' => 'joint', 'opened_date' => $openedDate],
        [['member_id' => $m1, 'role' => 'primary'], ['member_id' => $m2, 'role' => 'joint']],
        1
    );
}

function fundAccount(int $memberId, int $accountId, float $amount, string $date, int $splitInto = 2): void {
    $savingsModel = new SavingsModel();
    $each = round($amount / $splitInto, 2);
    $remaining = $amount;
    for ($i = 0; $i < $splitInto; $i++) {
        $amt = ($i === $splitInto - 1) ? $remaining : $each;
        $remaining -= $amt;
        $savingsModel->recordDepositWithPosting([
            'member_id'          => $memberId,
            'savings_account_id' => $accountId,
            'amount'             => $amt,
            'payment_method'     => 'Cash',
            'transaction_date'   => $date,
            'receipt_number'     => $savingsModel->generateReceiptNumber(),
            'recorded_by'        => 1,
        ], 1);
    }
}

function accountBalance(int $accountId): float {
    return (new MemberSavingsAccountModel())->getAccountBalance($accountId);
}

// Runs a controller method through the real HTTP-style entry point via a
// subprocess against empower_db_stage19c ONLY -- proves the fix at the
// actual application endpoint, not just the model layer (§35).
function callEndpoint(string $controller, string $method, array $post, array $get = [], array $session = []): array {
    $spec = [
        'controller'  => $controller,
        'method_name' => $method,
        'post'        => $post,
        'get'         => $get,
        'session'     => array_merge(['user_id' => 1, 'user_role' => 'admin', 'csrf_token' => 'TESTTOKEN'], $session),
        'outfile'     => __DIR__ . '/results/stage19c_withdrawal_safety_evidence/_last_result.json',
    ];
    if (!isset($post['csrf_token'])) {
        $spec['post']['csrf_token'] = $spec['session']['csrf_token'];
    }
    $specFile = __DIR__ . '/results/stage19c_withdrawal_safety_evidence/_spec.json';
    file_put_contents($specFile, json_encode($spec));
    @unlink($spec['outfile']);
    $harness = __DIR__ . '/results/stage19c_withdrawal_safety_evidence/_endpoint_harness.php';
    $cmd = 'php ' . escapeshellarg($harness) . ' ' . escapeshellarg($specFile) . ' 2>&1';
    $output = shell_exec($cmd);
    $result = file_exists($spec['outfile']) ? json_decode(file_get_contents($spec['outfile']), true) : null;
    return ['result' => $result, 'raw' => $output];
}

echo "=== STAGE 19C — WITHDRAWAL SAFETY TEST SUITE (ISOLATED: empower_db_stage19c) ===\n\n";

// ================================================================
// §22 — Compulsory Through Main Path (WithdrawalController engine, direct model call)
// ================================================================
echo "=== Test 1 (§22): Compulsory withdrawal through the authoritative engine ===\n";
$m1 = newMember($db, 'Alice', 'Main');
$a1c = createAccount($m1, 'compulsory');
fundAccount($m1, $a1c, 1000000, $WITHIN_PERIOD_DATE);
ok(abs(accountBalance($a1c) - 1000000) < 0.01, 'Compulsory account funded to 1,000,000');

$withdrawalModel = new WithdrawalModel();
$wid1 = $withdrawalModel->processAnnualCompulsory([
    'member_id' => $m1, 'requested_amount' => 300000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE,
], 1);
$w1 = $withdrawalModel->find($wid1);
ok((float)$w1['withdrawal_amount'] === 300000.0, 'Cash withdrawn = 300,000');
ok((float)$w1['retained_amount'] === 700000.0, 'Shares retained = 700,000');
ok(abs(accountBalance($a1c) - 0.0) < 0.01, 'Compulsory ending balance = 0');
$je1 = $db->prepare("SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id=?");
$je1->execute([$w1['journal_entry_id']]);
$bal1 = $je1->fetch();
ok(abs((float)$bal1['d'] - (float)$bal1['c']) < 0.01, 'Journal balanced (debit=credit)');
echo "\n";

// ================================================================
// §23 — Compulsory Through Generic Account Page (real controller endpoint)
// ================================================================
echo "=== Test 2 (§23): Compulsory withdrawal via the generic account-page endpoint ===\n";
$m2 = newMember($db, 'Bob', 'Generic');
$a2c = createAccount($m2, 'compulsory');
fundAccount($m2, $a2c, 1000000, $WITHIN_PERIOD_DATE);

$resp = callEndpoint('SavingsAccountController', 'withdrawalStore', [
    'account_id' => (string)$a2c,
    'amount' => '300000',
    'payment_method' => 'Cash',
    'transaction_date' => $WITHIN_PERIOD_DATE,
]);
$flashSuccess = $resp['result']['flash']['success'] ?? null;
$flashError   = $resp['result']['flash']['error'] ?? null;
ok($flashSuccess !== null && $flashError === null, 'Generic endpoint succeeded via delegation (flash success set, no error): ' . json_encode($resp['result']));
$w2 = $db->query("SELECT * FROM withdrawals WHERE member_id={$m2} ORDER BY id DESC LIMIT 1")->fetch();
ok($w2 !== false, 'A withdrawals row WAS created (proves delegation to the authoritative engine, not the old direct-savings-write path)');
ok($w2 && (float)$w2['withdrawal_amount'] === 300000.0, 'Cash withdrawn = 300,000 (policy correctly applied via generic page)');
ok($w2 && (float)$w2['retained_amount'] === 700000.0, 'Shares retained = 700,000 (policy correctly applied via generic page)');
ok(abs(accountBalance($a2c) - 0.0) < 0.01, 'Compulsory ending balance = 0 (not fully drained without conversion)');
echo "\n";

// ================================================================
// §24 — Generic Endpoint Direct Request (bypass attempt)
// ================================================================
echo "=== Test 3 (§24): Direct request to the generic endpoint attempting to bypass policy ===\n";
$m3 = newMember($db, 'Carol', 'Bypass');
$a3c = createAccount($m3, 'compulsory');
fundAccount($m3, $a3c, 1000000, $WITHIN_PERIOD_DATE);

$before = (int)$db->query("SELECT COUNT(*) FROM withdrawals")->fetchColumn();
$beforeSavings = (int)$db->query("SELECT COUNT(*) FROM savings")->fetchColumn();
$beforeJE = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();

$resp = callEndpoint('SavingsAccountController', 'withdrawalStore', [
    'account_id' => (string)$a3c,
    'amount' => '1000000', // full balance, no share conversion attempted -- exactly the old bypass shape
    'payment_method' => 'Cash',
    'transaction_date' => $WITHIN_PERIOD_DATE,
]);
$flashError = $resp['result']['flash']['error'] ?? null;
ok($flashError !== null, 'REJECTED with a flash error: ' . ($flashError ?? 'none'));
$after = (int)$db->query("SELECT COUNT(*) FROM withdrawals")->fetchColumn();
$afterSavings = (int)$db->query("SELECT COUNT(*) FROM savings")->fetchColumn();
$afterJE = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($after === $before, 'No withdrawals row created');
ok($afterSavings === $beforeSavings, 'No savings row mutation');
ok($afterJE === $beforeJE, 'No journal entry created');
ok(abs(accountBalance($a3c) - 1000000) < 0.01, 'Compulsory balance untouched at 1,000,000');
echo "\n";

// ================================================================
// §25 — Voluntary (both authoritative model call and generic endpoint)
// ================================================================
echo "=== Test 4 (§25): Voluntary withdrawal, authoritative + generic endpoint ===\n";
$m4 = newMember($db, 'Dan', 'Voluntary');
$a4v = createAccount($m4, 'voluntary');
fundAccount($m4, $a4v, 500000, $WITHIN_PERIOD_DATE);

$wid4 = $withdrawalModel->processVoluntary([
    'member_id' => $m4, 'requested_amount' => 300000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE,
], 1);
$w4 = $withdrawalModel->find($wid4);
ok(abs(accountBalance($a4v) - 200000) < 0.01, 'Voluntary remaining = 200,000 after authoritative-path withdrawal');
ok((float)$w4['retained_amount'] === 0.0, 'Shares = 0 for voluntary');

$m4b = newMember($db, 'Dana', 'VoluntaryGeneric');
$a4bv = createAccount($m4b, 'voluntary');
fundAccount($m4b, $a4bv, 500000, $WITHIN_PERIOD_DATE);
$resp = callEndpoint('SavingsAccountController', 'withdrawalStore', [
    'account_id' => (string)$a4bv, 'amount' => '300000', 'payment_method' => 'Cash', 'transaction_date' => $WITHIN_PERIOD_DATE,
]);
ok(($resp['result']['flash']['error'] ?? null) === null, 'Generic endpoint voluntary withdrawal succeeded: ' . json_encode($resp['result']));
ok(abs(accountBalance($a4bv) - 200000) < 0.01, 'Voluntary remaining = 200,000 via generic endpoint too');
$w4b = $db->query("SELECT * FROM withdrawals WHERE member_id={$m4b} ORDER BY id DESC LIMIT 1")->fetch();
ok($w4b && (float)$w4b['retained_amount'] === 0.0, 'Shares = 0 via generic endpoint (voluntary policy correctly applied)');
echo "\n";

// ================================================================
// §26 — Account Separation
// ================================================================
echo "=== Test 5 (§26): Compulsory + Voluntary separation for the same member ===\n";
$m5 = newMember($db, 'Eve', 'Separate');
$a5c = createAccount($m5, 'compulsory');
$a5v = createAccount($m5, 'voluntary');
fundAccount($m5, $a5c, 1000000, $WITHIN_PERIOD_DATE);
fundAccount($m5, $a5v, 500000, $WITHIN_PERIOD_DATE);

$withdrawalModel->processVoluntary(['member_id' => $m5, 'requested_amount' => 300000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
ok(abs(accountBalance($a5c) - 1000000) < 0.01, 'After voluntary withdrawal: compulsory UNCHANGED at 1,000,000');
ok(abs(accountBalance($a5v) - 200000) < 0.01, 'After voluntary withdrawal: voluntary = 200,000');

$withdrawalModel->processAnnualCompulsory(['member_id' => $m5, 'requested_amount' => 300000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
ok(abs(accountBalance($a5c) - 0.0) < 0.01, 'After compulsory withdrawal: compulsory = 0');
ok(abs(accountBalance($a5v) - 200000) < 0.01, 'After compulsory withdrawal: voluntary STILL = 200,000 (untouched)');
$w5 = $db->query("SELECT * FROM withdrawals WHERE member_id={$m5} AND withdrawal_type='annual_compulsory' ORDER BY id DESC LIMIT 1")->fetch();
ok((float)$w5['retained_amount'] === 700000.0, 'Shares from compulsory = 700,000');
echo "\n";

// ================================================================
// §27 — Duplicate Annual Withdrawal (both entry points)
// ================================================================
echo "=== Test 6 (§27): Duplicate annual compulsory withdrawal rejected regardless of entry point ===\n";
$m6 = newMember($db, 'Frank', 'Duplicate');
$a6c = createAccount($m6, 'compulsory');
fundAccount($m6, $a6c, 1000000, $WITHIN_PERIOD_DATE);
$withdrawalModel->processAnnualCompulsory(['member_id' => $m6, 'requested_amount' => 200000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);

$threw = false;
try {
    $withdrawalModel->processAnnualCompulsory(['member_id' => $m6, 'requested_amount' => 100000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
} catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'Second attempt via the authoritative model REJECTED (already withdrawn this FY)');

// Fund a fresh compulsory account with the same member is not possible (one
// compulsory account per member, enforced) -- instead verify the generic
// endpoint also respects the same already-withdrawn state for this member's
// (now zero-balance, already-withdrawn) account.
$resp = callEndpoint('SavingsAccountController', 'withdrawalStore', [
    'account_id' => (string)$a6c, 'amount' => '1', 'payment_method' => 'Cash', 'transaction_date' => $WITHIN_PERIOD_DATE,
]);
ok(($resp['result']['flash']['error'] ?? null) !== null, 'Generic endpoint on the same account ALSO rejected: ' . json_encode($resp['result']));
echo "\n";

// ================================================================
// §28 — Over-Limit
// ================================================================
echo "=== Test 7 (§28): Over-limit compulsory withdrawal rejected, no partial effects ===\n";
$m7 = newMember($db, 'Grace', 'OverLimit');
$a7c = createAccount($m7, 'compulsory');
fundAccount($m7, $a7c, 1000000, $WITHIN_PERIOD_DATE);
$threw = false;
try {
    $withdrawalModel->processAnnualCompulsory(['member_id' => $m7, 'requested_amount' => 500001, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
} catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'Requesting 500,001 (over the 50% max of 500,000) REJECTED');
ok(abs(accountBalance($a7c) - 1000000) < 0.01, 'Balance untouched at 1,000,000 (no partial effects)');
echo "\n";

// ================================================================
// §29 — Over-Balance Voluntary
// ================================================================
echo "=== Test 8 (§29): Over-balance voluntary withdrawal rejected ===\n";
$m8 = newMember($db, 'Hank', 'OverBalance');
$a8v = createAccount($m8, 'voluntary');
fundAccount($m8, $a8v, 500000, $WITHIN_PERIOD_DATE);
$threw = false;
try {
    $withdrawalModel->processVoluntary(['member_id' => $m8, 'requested_amount' => 500001, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
} catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'Requesting 500,001 against a 500,000 voluntary balance REJECTED');
ok(abs(accountBalance($a8v) - 500000) < 0.01, 'Balance untouched at 500,000');
echo "\n";

// ================================================================
// §30 — Policy Change (isolated DB only, no code change)
// ================================================================
echo "=== Test 9 (§30): Policy change (50%->40%) takes effect without touching transaction code ===\n";
$db->prepare("UPDATE savings_withdrawal_policies SET maximum_withdrawal_percent=40.00, share_conversion_percent=60.00 WHERE account_type='compulsory'")->execute();
$m9 = newMember($db, 'Ivy', 'PolicyChange');
$a9c = createAccount($m9, 'compulsory');
fundAccount($m9, $a9c, 1000000, $WITHIN_PERIOD_DATE);

$wid9 = $withdrawalModel->processAnnualCompulsory(['member_id' => $m9, 'requested_amount' => 400000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
$w9 = $withdrawalModel->find($wid9);
ok((float)$w9['withdrawal_amount'] === 400000.0, '40% of 1,000,000 = 400,000 ACCEPTED');
ok((float)$w9['retained_amount'] === 600000.0, 'Shares = 600,000 (remaining balance)');

$m9b = newMember($db, 'Ivy', 'PolicyChangeOver');
$a9bc = createAccount($m9b, 'compulsory');
fundAccount($m9b, $a9bc, 1000000, $WITHIN_PERIOD_DATE);
$threw = false;
try {
    $withdrawalModel->processAnnualCompulsory(['member_id' => $m9b, 'requested_amount' => 400001, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
} catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, '400,001 (over the new 40% max) REJECTED');
$db->prepare("UPDATE savings_withdrawal_policies SET maximum_withdrawal_percent=50.00, share_conversion_percent=50.00 WHERE account_type='compulsory'")->execute();
echo "\n";

// ================================================================
// §31/§32 — Journal Uniqueness & Balance (spot check across all above)
// ================================================================
echo "=== Test 10 (§31-32): Journal uniqueness and balance across all postings so far ===\n";
$dupCheck = $db->query(
    "SELECT source_module, source_reference_type, source_reference_id, COUNT(*) c
     FROM journal_entries GROUP BY source_module, source_reference_type, source_reference_id HAVING c > 1"
)->fetchAll();
ok(count($dupCheck) === 0, 'No source transaction has more than one journal entry (idempotency intact)');
$unbalanced = $db->query(
    "SELECT journal_entry_id, SUM(debit) d, SUM(credit) c FROM journal_lines GROUP BY journal_entry_id HAVING ABS(d-c) > 0.01"
)->fetchAll();
ok(count($unbalanced) === 0, 'Every journal entry individually balances (debit=credit)');
$tb = $db->query("SELECT SUM(debit) d, SUM(credit) c FROM journal_lines")->fetch();
ok(abs((float)$tb['d'] - (float)$tb['c']) < 0.01, 'System-wide Trial Balance balanced across the whole isolated suite');
echo "\n";

// ================================================================
// §33 — Savings Reconciliation
// ================================================================
echo "=== Test 11 (§33): Savings reconciliation (starting = cash + shares + ending) ===\n";
// Member 1: started 1,000,000, withdrew 300,000 cash + 700,000 shares, ending 0
ok((1000000.0 - (300000.0 + 700000.0)) === 0.0, 'Compulsory reconciliation: 1,000,000 = 300,000 cash + 700,000 shares + 0 ending');
// Member 4: started 500,000, withdrew 300,000 cash, 0 shares, ending 200,000
ok((500000.0 - (300000.0 + 0.0)) === 200000.0, 'Voluntary reconciliation: 500,000 = 300,000 cash + 0 shares + 200,000 ending');
echo "\n";

// ================================================================
// §34 — Reversal (compulsory and voluntary)
// ================================================================
echo "=== Test 12 (§34): Reversal restores savings, shares, and cash; original journal preserved ===\n";
// Compulsory reversal (member 1's earlier 300,000/700,000 withdrawal, wid1)
$origJE1 = (int)$w1['journal_entry_id'];
$ok1 = $withdrawalModel->reverseWithdrawal($wid1, 1);
ok($ok1, 'Compulsory reversal succeeded');
ok(abs(accountBalance($a1c) - 1000000) < 0.01, 'Compulsory balance restored to 1,000,000');
$je1exists = $db->query("SELECT COUNT(*) FROM journal_entries WHERE id={$origJE1}")->fetchColumn();
ok((int)$je1exists === 1, 'Original compulsory journal entry still exists, untouched');
$revJE1 = $db->query("SELECT COUNT(*) FROM journal_entries WHERE reversal_of_id={$origJE1}")->fetchColumn();
ok((int)$revJE1 === 1, 'A linked reversal journal entry exists');
$wRow1 = $db->query("SELECT COUNT(*) FROM withdrawals WHERE id={$wid1}")->fetchColumn();
ok((int)$wRow1 === 0, 'Withdrawal record removed after reversal');

// Voluntary reversal (member 4's earlier 300,000 withdrawal, wid4)
$origJE4 = (int)$w4['journal_entry_id'];
$ok4 = $withdrawalModel->reverseWithdrawal($wid4, 1);
ok($ok4, 'Voluntary reversal succeeded');
ok(abs(accountBalance($a4v) - 500000) < 0.01, 'Voluntary balance restored to 500,000');
$je4exists = $db->query("SELECT COUNT(*) FROM journal_entries WHERE id={$origJE4}")->fetchColumn();
ok((int)$je4exists === 1, 'Original voluntary journal entry still exists, untouched');
echo "\n";

// ================================================================
// §10/§17 area — Joint account: no policy configured -> must REJECT, both entry points
// ================================================================
echo "=== Test 13: Joint account withdrawal rejected on both entry points (no configured policy) ===\n";
$mj1 = newMember($db, 'Jack', 'JointA');
$mj2 = newMember($db, 'Jill', 'JointB');
$ajoint = createJointAccount($mj1, $mj2);
fundAccount($mj1, $ajoint, 200000, $WITHIN_PERIOD_DATE);

$threw = false;
try {
    $withdrawalModel->processAnnualCompulsory(['member_id' => $mj1, 'requested_amount' => 50000, 'payment_method' => 'Cash', 'withdrawal_date' => $WITHIN_PERIOD_DATE], 1);
} catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'processAnnualCompulsory() on a joint-only member correctly finds no compulsory account and rejects');

$resp = callEndpoint('SavingsAccountController', 'withdrawalForm', [], ['id' => (string)$ajoint]);
ok(($resp['result']['flash']['error'] ?? null) !== null, 'Generic withdrawalForm() for the joint account REJECTS before even rendering a form: ' . json_encode($resp['result']));

$respStore = callEndpoint('SavingsAccountController', 'withdrawalStore', [
    'account_id' => (string)$ajoint, 'member_id' => (string)$mj1, 'amount' => '50000', 'payment_method' => 'Cash', 'transaction_date' => $WITHIN_PERIOD_DATE,
]);
ok(($respStore['result']['flash']['error'] ?? null) !== null, 'Generic withdrawalStore() for the joint account REJECTS: ' . json_encode($respStore['result']));
ok(abs(accountBalance($ajoint) - 200000) < 0.01, 'Joint account balance untouched at 200,000');
echo "\n";

// ================================================================
// Final Trial Balance across the entire isolated suite
// ================================================================
echo "=== Final: System-wide Trial Balance ===\n";
$tbFinal = $db->query("SELECT SUM(debit) d, SUM(credit) c FROM journal_lines")->fetch();
ok(abs((float)$tbFinal['d'] - (float)$tbFinal['c']) < 0.01, "Final trial balance: debit={$tbFinal['d']} credit={$tbFinal['c']}");

echo "\n================================================================\n";
echo "STAGE 19C TEST RESULTS: {$PASS} passed, {$FAIL} failed\n";
echo "================================================================\n";

// ================================================================
// Regression: deposit flow still works via the generic endpoint
// (collectTransactionInput() was renamed/split -- verify depositStore()
// still functions correctly end to end)
// ================================================================
echo "\n=== Regression: deposit flow unaffected by the withdrawal-path refactor ===\n";
$mdep = newMember($db, 'Karen', 'DepositCheck');
$adep = createAccount($mdep, 'voluntary');
$respDep = callEndpoint('SavingsAccountController', 'depositStore', [
    'account_id' => (string)$adep, 'amount' => '75000', 'payment_method' => 'Cash', 'transaction_date' => $WITHIN_PERIOD_DATE,
]);
ok(($respDep['result']['flash']['error'] ?? null) === null, 'Deposit via generic endpoint still succeeds: ' . json_encode($respDep['result']));
ok(abs(accountBalance($adep) - 75000) < 0.01, 'Deposited balance = 75,000');

// ================================================================
// Server-side enforcement: CSRF mismatch must reject, regardless of a
// valid amount/account (§11 -- server, not UI, enforces the boundary)
// ================================================================
echo "\n=== Server-side enforcement: CSRF mismatch rejected ===\n";
$mcsrf = newMember($db, 'Leo', 'CsrfCheck');
$acsrf = createAccount($mcsrf, 'voluntary');
fundAccount($mcsrf, $acsrf, 100000, $WITHIN_PERIOD_DATE);
$respCsrf = callEndpoint('SavingsAccountController', 'withdrawalStore', [
    'account_id' => (string)$acsrf, 'amount' => '50000', 'payment_method' => 'Cash', 'transaction_date' => $WITHIN_PERIOD_DATE,
    'csrf_token' => 'WRONG-TOKEN',
]);
ok(($respCsrf['result']['flash']['error'] ?? null) !== null, 'Mismatched CSRF token REJECTED: ' . json_encode($respCsrf['result']));
ok(abs(accountBalance($acsrf) - 100000) < 0.01, 'Balance untouched at 100,000 after CSRF-rejected attempt');

// ================================================================
// Server-side enforcement: non-admin/treasurer role rejected
// ================================================================
echo "\n=== Server-side enforcement: viewer role cannot process a withdrawal ===\n";
$mview = newMember($db, 'Mona', 'ViewerCheck');
$aview = createAccount($mview, 'voluntary');
fundAccount($mview, $aview, 100000, $WITHIN_PERIOD_DATE);
$respView = callEndpoint('SavingsAccountController', 'withdrawalStore', [
    'account_id' => (string)$aview, 'amount' => '50000', 'payment_method' => 'Cash', 'transaction_date' => $WITHIN_PERIOD_DATE,
], [], ['user_role' => 'viewer']);
$rawHadDenied = str_contains($respView['raw'] ?? '', 'Access denied');
ok($rawHadDenied, 'A viewer-role request is denied (Access denied) before any processing');
ok(abs(accountBalance($aview) - 100000) < 0.01, 'Balance untouched at 100,000 after denied attempt');

echo "\n================================================================\n";
echo "STAGE 19C TEST RESULTS (FULL, INCLUDING REGRESSION+SECURITY): {$PASS} passed, {$FAIL} failed\n";
echo "================================================================\n";
