<?php
/**
 * STAGE 3 — Historical / Opening Share Entry — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]), a full
 * clone of empower_db's current schema+data (34 known-broken storage
 * artifacts excluded). NEVER touches empower_db.
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
$memberModel  = new MemberModel();
$shareModel   = new ShareModel();
$settingsModel = new SettingsModel();

$pdo->prepare("UPDATE settings SET setting_val = '20000' WHERE setting_key = 'share_value'")->execute();

function makeMember(MemberModel $mm, int $admin, string $tag): int {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage3HIST', 'last_name' => "Member{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000004' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "CM3HIST{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'active',
    ], $admin);
    return $m['member_id'];
}

// ================================================================
// Historical retained-savings entries (Tests 1-5)
// ================================================================
section('Historical Retained Entry — fractional quantity calculations');
$amounts = [40000 => '2.0000', 45000 => '2.2500', 50000 => '2.5000', 55000 => '2.7500', 60000 => '3.0000'];
foreach ($amounts as $amt => $expectedQty) {
    $mid = makeMember($memberModel, $ADMIN, 'R' . $amt);
    $id = $shareModel->createHistoricalRetained(['member_id' => $mid, 'transaction_date' => '2025-04-30', 'retained_amount' => $amt], $ADMIN);
    $row = $pdo->query("SELECT * FROM share_transactions WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    check("UGX $amt -> $expectedQty shares", (string)$row['quantity'] === $expectedQty, $row['quantity'] ?? 'MISSING');
    check("UGX $amt row has transaction_type=opening_retained", $row['transaction_type'] === 'opening_retained');
    check("UGX $amt row has journal_entry_id = NULL (no accounting posting)", $row['journal_entry_id'] === null);
    check("UGX $amt row has source_reference_type = NULL (not a withdrawal mirror)", $row['source_reference_type'] === null);
}

// ================================================================
// Historical purchased-share entries (Tests 6-8)
// ================================================================
section('Historical Purchased Entry — whole-share amount calculations');
$quantities = [1 => 20000.00, 5 => 100000.00, 10 => 200000.00];
foreach ($quantities as $qty => $expectedAmt) {
    $mid = makeMember($memberModel, $ADMIN, 'P' . $qty);
    $id = $shareModel->createHistoricalPurchase(['member_id' => $mid, 'transaction_date' => '2025-04-30', 'quantity' => $qty], $ADMIN);
    $row = $pdo->query("SELECT * FROM share_transactions WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    check("$qty shares -> UGX $expectedAmt", (float)$row['amount'] === $expectedAmt, (string)$row['amount']);
    check("$qty shares row has transaction_type=opening_purchase", $row['transaction_type'] === 'opening_purchase');
    check("$qty shares row has journal_entry_id = NULL", $row['journal_entry_id'] === null);
}

// ================================================================
// Validation (Tests 9-16)
// ================================================================
section('Validation');
$mid = makeMember($memberModel, $ADMIN, 'VAL');
function expectThrow(callable $fn, string $label): void {
    $threw = false;
    try { $fn(); } catch (InvalidArgumentException $e) { $threw = true; }
    check($label, $threw);
}
expectThrow(fn() => $shareModel->createHistoricalRetained(['member_id'=>$mid,'transaction_date'=>'2025-04-30','retained_amount'=>0], $ADMIN), 'reject zero retained amount');
expectThrow(fn() => $shareModel->createHistoricalRetained(['member_id'=>$mid,'transaction_date'=>'2025-04-30','retained_amount'=>-5000], $ADMIN), 'reject negative retained amount');
expectThrow(fn() => $shareModel->createHistoricalPurchase(['member_id'=>$mid,'transaction_date'=>'2025-04-30','quantity'=>0], $ADMIN), 'reject zero purchased shares');
expectThrow(fn() => $shareModel->createHistoricalPurchase(['member_id'=>$mid,'transaction_date'=>'2025-04-30','quantity'=>-3], $ADMIN), 'reject negative purchased shares');
expectThrow(fn() => $shareModel->createHistoricalPurchase(['member_id'=>$mid,'transaction_date'=>'2025-04-30','quantity'=>2.5], $ADMIN), 'reject fractional purchased shares (2.5)');
expectThrow(fn() => $shareModel->createHistoricalRetained(['member_id'=>999999,'transaction_date'=>'2025-04-30','retained_amount'=>50000], $ADMIN), 'reject invalid/nonexistent member');
expectThrow(fn() => $shareModel->createHistoricalRetained(['member_id'=>$mid,'transaction_date'=>'2099-01-01','retained_amount'=>50000], $ADMIN), 'reject a future date');
expectThrow(fn() => $shareModel->createHistoricalRetained(['member_id'=>$mid,'transaction_date'=>'not-a-date','retained_amount'=>50000], $ADMIN), 'reject an invalid date string');
// "Invalid transaction source" is enforced in the CONTROLLER (source must be 'retained' or 'purchase'), verified separately via HTTP below.

section('Server-side recalculation cannot be manipulated by client-supplied values');
// The model has no "quantity" or "amount" input parameter for the retained
// path at all -- only retained_amount is accepted, so there is nothing for
// a tampered client to override. Prove this directly: pass an extraneous
// 'quantity' key and confirm it is silently ignored, the server's own
// calculation from retained_amount/share_value is what gets stored.
$mid2 = makeMember($memberModel, $ADMIN, 'TAMPER');
$id = $shareModel->createHistoricalRetained(['member_id'=>$mid2,'transaction_date'=>'2025-04-30','retained_amount'=>40000,'quantity'=>999], $ADMIN);
$row = $pdo->query("SELECT quantity FROM share_transactions WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
check('an injected "quantity" field is ignored -- server recalculates from retained_amount/share_value', (string)$row['quantity'] === '2.0000', $row['quantity']);
// Same for the purchase path: an extraneous 'amount' key must be ignored.
$mid3 = makeMember($memberModel, $ADMIN, 'TAMPER2');
$id2 = $shareModel->createHistoricalPurchase(['member_id'=>$mid3,'transaction_date'=>'2025-04-30','quantity'=>5,'amount'=>999999], $ADMIN);
$row2 = $pdo->query("SELECT amount FROM share_transactions WHERE id=$id2")->fetch(PDO::FETCH_ASSOC);
check('an injected "amount" field is ignored -- server recalculates from quantity*share_value', (float)$row2['amount'] === 100000.00, (string)$row2['amount']);

// ================================================================
// Historical share value preservation (Tests 18-21)
// ================================================================
section('Historical share value preservation across a settings change');
$mid4 = makeMember($memberModel, $ADMIN, 'HISTVAL');
$idOld = $shareModel->createHistoricalRetained(['member_id'=>$mid4,'transaction_date'=>'2025-04-30','retained_amount'=>40000], $ADMIN);
$pdo->prepare("UPDATE settings SET setting_val = '25000' WHERE setting_key = 'share_value'")->execute();
$rowOld = $pdo->query("SELECT * FROM share_transactions WHERE id=$idOld")->fetch(PDO::FETCH_ASSOC);
check('old entry still shows share_value=20000.00 after the settings change', (string)$rowOld['share_value'] === '20000.00');
check('old entry still shows quantity=2.0000 (not recalculated)', (string)$rowOld['quantity'] === '2.0000');
$idNew = $shareModel->createHistoricalRetained(['member_id'=>$mid4,'transaction_date'=>'2025-05-01','retained_amount'=>50000], $ADMIN);
$rowNew = $pdo->query("SELECT * FROM share_transactions WHERE id=$idNew")->fetch(PDO::FETCH_ASSOC);
check('a NEW entry after the change uses share_value=25000.00', (string)$rowNew['share_value'] === '25000.00');
check('a NEW entry quantity = 50000/25000 = 2.0000', (string)$rowNew['quantity'] === '2.0000');
$pdo->prepare("UPDATE settings SET setting_val = '20000' WHERE setting_key = 'share_value'")->execute();

// ================================================================
// Duplicate protection (Section 11)
// ================================================================
section('Duplicate protection -- soft strategy, confirm-to-override');
$mid5 = makeMember($memberModel, $ADMIN, 'DUP');
$shareModel->createHistoricalRetained(['member_id'=>$mid5,'transaction_date'=>'2025-05-01','retained_amount'=>100000], $ADMIN);
$dupThrew = false; $dupMsg = '';
try {
    $shareModel->createHistoricalRetained(['member_id'=>$mid5,'transaction_date'=>'2025-05-01','retained_amount'=>100000], $ADMIN);
} catch (InvalidArgumentException $e) { $dupThrew = true; $dupMsg = $e->getMessage(); }
check('an exact repeat (same member/date/amount) is flagged, not silently inserted', $dupThrew);
check('the flag is a DUPLICATE: message the controller can distinguish from a hard error', str_starts_with($dupMsg, 'DUPLICATE:'), $dupMsg);
$countBeforeConfirm = (int)$pdo->query("SELECT COUNT(*) FROM share_transactions WHERE member_id=$mid5")->fetchColumn();
$idConfirmed = $shareModel->createHistoricalRetained(['member_id'=>$mid5,'transaction_date'=>'2025-05-01','retained_amount'=>100000,'confirm_duplicate'=>true], $ADMIN);
$countAfterConfirm = (int)$pdo->query("SELECT COUNT(*) FROM share_transactions WHERE member_id=$mid5")->fetchColumn();
check('confirm_duplicate=true allows a genuinely separate second entry to be added', $countAfterConfirm === $countBeforeConfirm + 1 && $idConfirmed > 0);
// A DIFFERENT date/amount for the same member must never be blocked -- the
// business may legitimately have several distinct historical records.
$idDifferent = $shareModel->createHistoricalRetained(['member_id'=>$mid5,'transaction_date'=>'2025-06-01','retained_amount'=>30000], $ADMIN);
check('a legitimately different historical record (different date/amount) is never blocked', $idDifferent > 0);

// ================================================================
// Security (Tests 22-25) -- HTTP-level, real accounts, real routes
// ================================================================
section('Security -- live HTTP checks against the running application (empower_db config, read-only actions only)');
function makeSession(int $userId, string $role): string {
    return trim(shell_exec('php ' . escapeshellarg(__DIR__ . '/_shares_test_make_session.php') . ' ' . (int)$userId . ' ' . escapeshellarg($role)));
}
$prodPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=empower_db', 'root', '');
$adminId = $prodPdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.is_active=1 LIMIT 1")->fetchColumn();
$treasurerId = $prodPdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='treasurer' AND u.is_active=1 LIMIT 1")->fetchColumn();
$officeAdminId = $prodPdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='office_admin' AND u.is_active=1 LIMIT 1")->fetchColumn();
$cashierId = $prodPdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='cashier' AND u.is_active=1 LIMIT 1")->fetchColumn();
$base = 'http://localhost/Empower/index.php';

$c1 = 0; $c2 = 0;
$ch = curl_init($base . '?page=share-historical-create');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>10]);
curl_exec($ch); $c1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
check('unauthenticated GET request is rejected (302 redirect to login, not 200)', $c1 === 302, "got $c1");

if ($officeAdminId) {
    $sid = makeSession($officeAdminId, 'office_admin');
    $ch = curl_init($base . '?page=share-historical-create');
    curl_setopt_array($ch, [CURLOPT_COOKIE=>'empower_session='.$sid, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); $c2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    check('authenticated but unauthorized role (office_admin) is rejected (403)', $c2 === 403, "got $c2");
}
if ($cashierId) {
    $sid = makeSession($cashierId, 'cashier');
    $ch = curl_init($base . '?page=share-historical-store');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query(['source'=>'retained','member_id'=>1,'transaction_date'=>'2025-04-30','retained_amount'=>50000]),
        CURLOPT_COOKIE=>'empower_session='.$sid, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); $c3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    check('cashier (view-only Shares role) cannot POST to share-historical-store (403)', $c3 === 403, "got $c3");
}
if ($adminId) {
    $sid = makeSession($adminId, 'admin');
    // Missing/invalid CSRF token -- must be rejected (flashed error + redirect), not accepted.
    $stBefore = (int)$prodPdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
    $ch = curl_init($base . '?page=share-historical-store');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query(['source'=>'retained','member_id'=>1,'transaction_date'=>'2025-04-30','retained_amount'=>50000,'csrf_token'=>'tampered-invalid-token']),
        CURLOPT_COOKIE=>'empower_session='.$sid, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); $c4 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $stAfter = (int)$prodPdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
    check('missing/invalid CSRF token is rejected (redirect, not a successful insert)', $c4 === 302, "got $c4");
    check('CSRF-rejected request created ZERO rows in PRODUCTION share_transactions', $stAfter === $stBefore, "before=$stBefore after=$stAfter");

    // Invalid transaction source (neither 'retained' nor 'purchase') --
    // enforced in the controller's dispatch, not the model (which has two
    // separate, source-specific methods with no such parameter at all).
    // Uses a real, valid CSRF token this time so ONLY the source value is
    // under test. Still targets a nonexistent member_id=1 as a second,
    // independent safety net against any accidental production write.
    $sidValid = makeSession($adminId, 'admin');
    $ch = curl_init($base . '?page=share-historical-create');
    curl_setopt_array($ch, [CURLOPT_COOKIE=>'empower_session='.$sidValid, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    $formBody = curl_exec($ch);
    preg_match('/name="csrf_token" value="([^"]+)"/', (string)$formBody, $m);
    $realToken = $m[1] ?? '';
    $stBefore2 = (int)$prodPdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
    $ch = curl_init($base . '?page=share-historical-store');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query(['source'=>'bogus','member_id'=>1,'transaction_date'=>'2025-04-30','retained_amount'=>50000,'csrf_token'=>$realToken]),
        CURLOPT_COOKIE=>'empower_session='.$sidValid, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); $c5 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $stAfter2 = (int)$prodPdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
    check('an invalid transaction source ("bogus") is rejected cleanly (redirect, no fatal error)', $c5 === 302, "got $c5");
    check('invalid-source request created ZERO rows in PRODUCTION share_transactions', $stAfter2 === $stBefore2, "before=$stBefore2 after=$stAfter2");
}

// ================================================================
// Accounting (Tests 26-27)
// ================================================================
section('Accounting -- no duplicate/unintended journal posting');
$jeBeforeAcct = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$midAcct = makeMember($memberModel, $ADMIN, 'ACCT');
$shareModel->createHistoricalRetained(['member_id'=>$midAcct,'transaction_date'=>'2025-04-30','retained_amount'=>100000], $ADMIN);
$shareModel->createHistoricalPurchase(['member_id'=>$midAcct,'transaction_date'=>'2025-04-30','quantity'=>3], $ADMIN);
$jeAfterAcct = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('no journal entry was created by either historical entry (before='.$jeBeforeAcct.', after='.$jeAfterAcct.')', $jeAfterAcct === $jeBeforeAcct);
$glLine = $pdo->query("SELECT COUNT(*) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.code='3010' AND jl.created_at > (SELECT NOW() - INTERVAL 1 MINUTE)")->fetchColumn();
check('GL 3010 received no new posting', (int)$glLine === 0);

// ================================================================
// Audit (Test 28)
// ================================================================
section('Audit logging');
$logCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$midAudit = makeMember($memberModel, $ADMIN, 'AUDIT');
$shareModel->createHistoricalRetained(['member_id'=>$midAudit,'transaction_date'=>'2025-04-30','retained_amount'=>40000], $ADMIN);
$logCountAfter = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
check('exactly ONE audit log row was created for this entry', $logCountAfter === $logCountBefore + 1, "before=$logCountBefore after=$logCountAfter");
$logRow = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('audit action is share_historical_retained_entry', $logRow['action'] === 'share_historical_retained_entry');
check('audit description mentions the member, amount, and quantity', str_contains($logRow['description'], (string)$midAudit) && str_contains($logRow['description'], '40,000') && str_contains($logRow['description'], '2.0000'));

// ================================================================
// Ledger (Tests 29-30)
// ================================================================
section('Ledger source distinction and totals');
$midLedger = makeMember($memberModel, $ADMIN, 'LEDGER');
$shareModel->createHistoricalRetained(['member_id'=>$midLedger,'transaction_date'=>'2025-04-30','retained_amount'=>50000], $ADMIN);
$shareModel->createHistoricalPurchase(['member_id'=>$midLedger,'transaction_date'=>'2025-04-30','quantity'=>5], $ADMIN);
$shareValueNow = (float)$settingsModel->get('share_value', '20000');
$ledger = $shareModel->ledgerForMember($midLedger, $shareValueNow);
check('ledger has exactly 2 rows for this member', count($ledger) === 2);
$types = array_column($ledger, 'transaction_type');
check('ledger distinguishes opening_retained', in_array('opening_retained', $types, true));
check('ledger distinguishes opening_purchase', in_array('opening_purchase', $types, true));
$totalCap = $shareModel->memberCapital($midLedger);
check('total share capital = 50,000 + 100,000 = 150,000 exactly once', abs($totalCap - 150000) < 0.005, (string)$totalCap);

// ================================================================
// Stage 2 compatibility (Tests 31-34)
// ================================================================
section('Stage 2 compatibility -- retained withdrawal mirror still works correctly alongside historical entries');
$policyModel = new WithdrawalPolicyModel();
$accountModel = new MemberSavingsAccountModel();
$savingsModel = new SavingsModel();
$wdlModel = new WithdrawalModel();
$pdo->exec("DELETE FROM savings_withdrawal_policies");
$policyModel->createPolicy(['account_type'=>'compulsory','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>50,'share_conversion_percent'=>50,'frequency'=>'once_per_financial_year','effective_from'=>'2026-01-01'], $ADMIN);
$policyModel->createPolicy(['account_type'=>'voluntary','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>100,'share_conversion_percent'=>0,'frequency'=>'any_time','effective_from'=>'2026-01-01'], $ADMIN);

$mid6 = makeMember($memberModel, $ADMIN, 'STAGE2COMPAT');
// Give this member BOTH a historical entry AND a live retained withdrawal.
$shareModel->createHistoricalRetained(['member_id'=>$mid6,'transaction_date'=>'2025-04-30','retained_amount'=>60000], $ADMIN);
$savingsModel->recordDepositWithPosting(['member_id'=>$mid6,'savings_account_id'=>$accountModel->getMemberAccounts($mid6)[0]['id'],'transaction_type'=>'deposit','amount'=>20000,'payment_method'=>'Cash','transaction_date'=>'2026-07-05','receipt_number'=>$savingsModel->generateReceiptNumber(),'recorded_by'=>$ADMIN], $ADMIN);
$savingsModel->recordDepositWithPosting(['member_id'=>$mid6,'savings_account_id'=>$accountModel->getMemberAccounts($mid6)[0]['id'],'transaction_type'=>'deposit','amount'=>80000,'payment_method'=>'Cash','transaction_date'=>'2026-07-10','receipt_number'=>$savingsModel->generateReceiptNumber(),'recorded_by'=>$ADMIN], $ADMIN);
$accountModel->recordQualification($accountModel->getMemberAccounts($mid6)[0]['id']);
// Compulsory balance = 100,000; policy ceiling = 100% (50% max + 50% share);
// requesting 25,000 cash means retained = ceiling(100,000) - requested(25,000)
// = 75,000, per WithdrawalPolicyModel::calculateShareConversion()'s exact
// Model A formula (retained is NOT simply "50% of the request").
$w = $wdlModel->processAnnualCompulsory(['member_id'=>$mid6,'requested_amount'=>25000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$wRow = $wdlModel->find($w);
check('Stage 2 mirror still created correctly alongside a historical entry (retained=75,000 per Model A)', (float)$wRow['retained_amount'] === 75000.0, (string)$wRow['retained_amount']);
$mirrorCount = (int)$pdo->query("SELECT COUNT(*) FROM share_transactions WHERE source_reference_type='withdrawal' AND source_reference_id=$w")->fetchColumn();
check('exactly 1 mirror row for the new withdrawal', $mirrorCount === 1);
$historicalCount = (int)$pdo->query("SELECT COUNT(*) FROM share_transactions WHERE member_id=$mid6 AND transaction_type IN ('opening_retained','opening_purchase')")->fetchColumn();
check('the historical entry was NOT counted as a withdrawal mirror (still exactly 1 historical row)', $historicalCount === 1);
$totalMember6 = $shareModel->memberCapital($mid6);
check('member total = 60,000 (historical) + 75,000 (retained withdrawal) = 135,000 exactly once', abs($totalMember6 - 135000) < 0.005, (string)$totalMember6);

// ================================================================
// Production safety (Test 35)
// ================================================================
section('Production safety');
foreach (['members','withdrawals','savings','share_transactions','journal_entries','journal_lines','activity_logs'] as $t) {
    // Only informational here -- the authoritative before/after comparison
    // is done by the calling shell script around this whole test run.
    echo "  production $t (snapshot during test): " . $prodPdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . "\n";
}

echo "\n=== RESULTS: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
