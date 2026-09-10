<?php
/**
 * STAGE 4-C — Current Share Transaction Recording — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]), a full
 * clone of empower_db's current schema+data (34 known-broken storage
 * artifacts excluded). NEVER touches empower_db for financial writes.
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
$reportModel  = new ReportModel();
$statementModel = new StatementModel();
$settingsModel = new SettingsModel();

$pdo->prepare("UPDATE settings SET setting_val = '20000' WHERE setting_key = 'share_value'")->execute();
$pdo->exec("DELETE FROM savings_withdrawal_policies");
$policyModel = new WithdrawalPolicyModel();
$policyModel->createPolicy(['account_type'=>'compulsory','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>50,'share_conversion_percent'=>50,'frequency'=>'once_per_financial_year','effective_from'=>'2026-01-01'], $ADMIN);
$policyModel->createPolicy(['account_type'=>'voluntary','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>100,'share_conversion_percent'=>0,'frequency'=>'any_time','effective_from'=>'2026-01-01'], $ADMIN);

function makeMember(MemberModel $mm, int $admin, string $tag): int {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage4cCT', 'last_name' => "Member{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000006' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "CM4cCT{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'active',
    ], $admin);
    return $m['member_id'];
}

// ================================================================
// A. Schema
// ================================================================
section('A. Schema');
$col = array_column($pdo->query("SHOW COLUMNS FROM share_transactions")->fetchAll(PDO::FETCH_ASSOC), null, 'Field');
check('external_reference exists, varchar(100)', ($col['external_reference']['Type'] ?? '') === 'varchar(100)');
check('reference_number remains internal, varchar(20)', ($col['reference_number']['Type'] ?? '') === 'varchar(20)');
$shrRows = $pdo->query("SELECT * FROM journal_number_sequences WHERE prefix='SHR'")->fetchAll(PDO::FETCH_ASSOC);
check('SHR sequence exists exactly once', count($shrRows) === 1);
check('SHR starts at 0', count($shrRows) === 1 && (int)$shrRows[0]['last_number'] === 0);

// ================================================================
// B/C. Successful transaction + calculation
// ================================================================
section('B/C. Successful current transaction + calculation');
$mB = makeMember($memberModel, $ADMIN, 'B');
$jeBefore = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$result = $shareModel->createCurrentTransaction([
    'member_id' => $mB, 'transaction_date' => '2026-07-20', 'amount' => 100000,
    'payment_method' => 'Cash', 'external_reference' => 'MM123456',
], $ADMIN);
$row = $pdo->query("SELECT * FROM share_transactions WHERE id={$result['id']}")->fetch(PDO::FETCH_ASSOC);
check('row created', $row !== false);
check('transaction_type = direct_purchase', $row['transaction_type'] === 'direct_purchase');
check('correct member', (int)$row['member_id'] === $mB);
check('correct date', $row['transaction_date'] === '2026-07-20');
check('correct amount', (float)$row['amount'] === 100000.0);
check('correct payment method', $row['payment_method'] === 'Cash');
check('quantity = 5.0000 (100,000/20,000)', (string)$row['quantity'] === '5.0000', $row['quantity']);
check('share_value = 20000.00', (string)$row['share_value'] === '20000.00');
check('external_reference stored', $row['external_reference'] === 'MM123456');
check('reference_number matches SHR-000001 format', preg_match('/^SHR-\d{6}$/', $row['reference_number']) === 1, $row['reference_number']);
check('source_reference_type IS NULL', $row['source_reference_type'] === null);
check('source_reference_id IS NULL', $row['source_reference_id'] === null);
check('processed_by correct', (int)$row['processed_by'] === $ADMIN);
check('journal_entry_id IS NOT NULL', $row['journal_entry_id'] !== null);
$jeAfter = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('exactly one new journal entry created', $jeAfter === $jeBefore + 1);

// Fractional
$mC = makeMember($memberModel, $ADMIN, 'C');
$resultFrac = $shareModel->createCurrentTransaction([
    'member_id' => $mC, 'transaction_date' => '2026-07-20', 'amount' => 45000, 'payment_method' => 'Cash',
], $ADMIN);
$rowFrac = $pdo->query("SELECT quantity FROM share_transactions WHERE id={$resultFrac['id']}")->fetch(PDO::FETCH_ASSOC);
check('fractional: 45,000/20,000 = 2.2500', (string)$rowFrac['quantity'] === '2.2500', $rowFrac['quantity']);

// ================================================================
// D. Server-side tampering
// ================================================================
section('D. Server-side tampering cannot override authoritative values');
$mD = makeMember($memberModel, $ADMIN, 'D');
$tamperedResult = $shareModel->createCurrentTransaction([
    'member_id' => $mD, 'transaction_date' => '2026-07-20', 'amount' => 100000, 'payment_method' => 'Cash',
    // Every one of these is NOT a real parameter of createCurrentTransaction() --
    // proving the model has no code path that could read them even if injected.
    'quantity' => 999, 'share_value' => 1, 'transaction_type' => 'opening_purchase',
    'reference_number' => 'FAKE-123', 'journal_entry_id' => 999999, 'processed_by' => 999,
], $ADMIN);
$tamperedRow = $pdo->query("SELECT * FROM share_transactions WHERE id={$tamperedResult['id']}")->fetch(PDO::FETCH_ASSOC);
check('injected quantity=999 ignored (server-derived 5.0000 stored)', (string)$tamperedRow['quantity'] === '5.0000', $tamperedRow['quantity']);
check('injected share_value=1 ignored (server-read 20000.00 stored)', (string)$tamperedRow['share_value'] === '20000.00', $tamperedRow['share_value']);
check('injected transaction_type=opening_purchase ignored (direct_purchase stored)', $tamperedRow['transaction_type'] === 'direct_purchase');
check('injected reference_number=FAKE-123 ignored (real SHR-###### stored)', $tamperedRow['reference_number'] !== 'FAKE-123' && preg_match('/^SHR-\d{6}$/', $tamperedRow['reference_number']) === 1);
check('injected journal_entry_id=999999 ignored (real posted entry id stored)', (int)$tamperedRow['journal_entry_id'] !== 999999);
check('injected processed_by=999 ignored (real $ADMIN=1 stored)', (int)$tamperedRow['processed_by'] === $ADMIN);

// ================================================================
// E/F. Payment methods
// ================================================================
section('E/F. Payment methods -- correct debit account, GL 3010 always credited; invalid method rejected');
$expectedAccounts = ['Cash'=>7, 'MTN Mobile Money'=>8, 'Airtel Money'=>8, 'Bank Transfer'=>10, 'Cheque'=>10, 'Other'=>7];
foreach ($expectedAccounts as $method => $acctId) {
    $m = makeMember($memberModel, $ADMIN, 'PM' . str_replace(' ', '', $method));
    $r = $shareModel->createCurrentTransaction(['member_id'=>$m,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>$method], $ADMIN);
    $row2 = $pdo->query("SELECT journal_entry_id FROM share_transactions WHERE id={$r['id']}")->fetch(PDO::FETCH_ASSOC);
    $lines = $pdo->query("SELECT jl.account_id, jl.debit, jl.credit FROM journal_lines jl WHERE jl.journal_entry_id={$row2['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
    $debitLine = null; $creditLine = null;
    foreach ($lines as $l) { if ((float)$l['debit'] > 0) $debitLine = $l; if ((float)$l['credit'] > 0) $creditLine = $l; }
    check("$method debits account id $acctId", $debitLine && (int)$debitLine['account_id'] === $acctId, $debitLine ? (string)$debitLine['account_id'] : 'MISSING');
    check("$method credits GL 3010 (account id 24)", $creditLine && (int)$creditLine['account_id'] === 24);
    check("$method debit amount = 20000.00", $debitLine && (float)$debitLine['debit'] === 20000.0);
    check("$method credit amount = 20000.00", $creditLine && (float)$creditLine['credit'] === 20000.0);
}
$mInvalid = makeMember($memberModel, $ADMIN, 'INVPM');
$stBeforeInvalid = (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
$jeBeforeInvalid = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$shrBeforeInvalid = (int)$pdo->query("SELECT last_number FROM journal_number_sequences WHERE prefix='SHR'")->fetchColumn();
$threwInvalidPM = false;
try {
    $shareModel->createCurrentTransaction(['member_id'=>$mInvalid,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Bitcoin'], $ADMIN);
} catch (InvalidArgumentException $e) { $threwInvalidPM = true; }
check('invalid payment method rejected', $threwInvalidPM);
check('no share row created for invalid payment method', (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn() === $stBeforeInvalid);
check('no journal entry created for invalid payment method', (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $jeBeforeInvalid);
check('SHR sequence NOT consumed for invalid payment method', (int)$pdo->query("SELECT last_number FROM journal_number_sequences WHERE prefix='SHR'")->fetchColumn() === $shrBeforeInvalid);

// ================================================================
// G. External reference handling
// ================================================================
section('G. External reference handling');
$mG1 = makeMember($memberModel, $ADMIN, 'EXTOMIT');
$r1 = $shareModel->createCurrentTransaction(['member_id'=>$mG1,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Cash'], $ADMIN);
$row1 = $pdo->query("SELECT external_reference FROM share_transactions WHERE id={$r1['id']}")->fetch(PDO::FETCH_ASSOC);
check('omitted external_reference stored as NULL', $row1['external_reference'] === null);

$mG2 = makeMember($memberModel, $ADMIN, 'EXTBLANK');
$r2 = $shareModel->createCurrentTransaction(['member_id'=>$mG2,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Cash','external_reference'=>'   '], $ADMIN);
$row2b = $pdo->query("SELECT external_reference FROM share_transactions WHERE id={$r2['id']}")->fetch(PDO::FETCH_ASSOC);
check('blank/whitespace external_reference stored as NULL', $row2b['external_reference'] === null);

$mG3 = makeMember($memberModel, $ADMIN, 'EXTTRIM');
$r3 = $shareModel->createCurrentTransaction(['member_id'=>$mG3,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Cash','external_reference'=>'  MM999  '], $ADMIN);
$row3 = $pdo->query("SELECT external_reference FROM share_transactions WHERE id={$r3['id']}")->fetch(PDO::FETCH_ASSOC);
check('surrounding whitespace trimmed', $row3['external_reference'] === 'MM999', $row3['external_reference']);

$mG4 = makeMember($memberModel, $ADMIN, 'EXTMAX');
$exactly100 = str_repeat('X', 100);
$r4 = $shareModel->createCurrentTransaction(['member_id'=>$mG4,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Cash','external_reference'=>$exactly100], $ADMIN);
$row4 = $pdo->query("SELECT external_reference FROM share_transactions WHERE id={$r4['id']}")->fetch(PDO::FETCH_ASSOC);
check('exactly 100 chars stored in full', strlen($row4['external_reference']) === 100);

$mG5 = makeMember($memberModel, $ADMIN, 'EXTOVER');
$over120 = str_repeat('Y', 120);
$r5 = $shareModel->createCurrentTransaction(['member_id'=>$mG5,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Cash','external_reference'=>$over120], $ADMIN);
$row5 = $pdo->query("SELECT external_reference FROM share_transactions WHERE id={$r5['id']}")->fetch(PDO::FETCH_ASSOC);
check('over-limit input safely truncated to 100 chars, no error', strlen($row5['external_reference']) === 100);

// ================================================================
// H. Base duplicate warning
// ================================================================
section('H. Duplicate warning (member+type+date+amount)');
$mH = makeMember($memberModel, $ADMIN, 'DUP');
$shareModel->createCurrentTransaction(['member_id'=>$mH,'transaction_date'=>'2026-07-25','amount'=>60000,'payment_method'=>'Cash'], $ADMIN);
$dupThrew = false; $dupMsg = '';
try {
    $shareModel->createCurrentTransaction(['member_id'=>$mH,'transaction_date'=>'2026-07-25','amount'=>60000,'payment_method'=>'Cash'], $ADMIN);
} catch (InvalidArgumentException $e) { $dupThrew = true; $dupMsg = $e->getMessage(); }
check('exact repeat triggers duplicate warning', $dupThrew);
check('warning message prefixed DUPLICATE:', str_starts_with($dupMsg, 'DUPLICATE:'), $dupMsg);
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM share_transactions WHERE member_id=$mH")->fetchColumn();
$confirmed = $shareModel->createCurrentTransaction(['member_id'=>$mH,'transaction_date'=>'2026-07-25','amount'=>60000,'payment_method'=>'Cash','confirm_duplicate'=>true], $ADMIN);
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM share_transactions WHERE member_id=$mH")->fetchColumn();
check('confirm_duplicate=true allows the second, confirmed entry', $countAfter === $countBefore + 1 && $confirmed['id'] > 0);

// ================================================================
// I. External-reference duplicate
// ================================================================
section('I. External-reference duplicate (stronger signal)');
$mI = makeMember($memberModel, $ADMIN, 'EXTDUP');
$shareModel->createCurrentTransaction(['member_id'=>$mI,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Cash','external_reference'=>'MMDUP001'], $ADMIN);
$extDupThrew = false; $extDupMsg = '';
try {
    // Different date/amount -- would NOT trigger the base check -- but the SAME external_reference for the SAME member.
    $shareModel->createCurrentTransaction(['member_id'=>$mI,'transaction_date'=>'2026-08-01','amount'=>40000,'payment_method'=>'Cash','external_reference'=>'MMDUP001'], $ADMIN);
} catch (InvalidArgumentException $e) { $extDupThrew = true; $extDupMsg = $e->getMessage(); }
check('repeated external_reference for the same member is flagged even with a different date/amount', $extDupThrew);
check('warning message prefixed EXTERNAL_DUPLICATE:', str_starts_with($extDupMsg, 'EXTERNAL_DUPLICATE:'), $extDupMsg);
$confirmedExt = $shareModel->createCurrentTransaction(['member_id'=>$mI,'transaction_date'=>'2026-08-01','amount'=>40000,'payment_method'=>'Cash','external_reference'=>'MMDUP001','confirm_external_duplicate'=>true], $ADMIN);
check('confirm_external_duplicate=true allows the confirmed entry', $confirmedExt['id'] > 0);

// ================================================================
// J. SHR sequence
// ================================================================
section('J. SHR sequence format, uniqueness, increment discipline');
$allRefs = array_column($pdo->query("SELECT reference_number FROM share_transactions WHERE transaction_type='direct_purchase'")->fetchAll(PDO::FETCH_ASSOC), 'reference_number');
$allValid = true; foreach ($allRefs as $ref) { if (!preg_match('/^SHR-\d{6}$/', $ref)) { $allValid = false; break; } }
check('every direct_purchase row has a valid SHR-###### reference', $allValid);
check('every SHR reference is unique', count($allRefs) === count(array_unique($allRefs)), 'count=' . count($allRefs) . ' unique=' . count(array_unique($allRefs)));
$shrNow = (int)$pdo->query("SELECT last_number FROM journal_number_sequences WHERE prefix='SHR'")->fetchColumn();
check('SHR.last_number equals the count of successful current transactions', $shrNow === count($allRefs), "last_number=$shrNow count=" . count($allRefs));

// ================================================================
// K. Journal correctness (re-verified precisely for one transaction)
// ================================================================
section('K. Journal correctness');
$mK = makeMember($memberModel, $ADMIN, 'JRNL');
$rK = $shareModel->createCurrentTransaction(['member_id'=>$mK,'transaction_date'=>'2026-07-20','amount'=>80000,'payment_method'=>'Bank Transfer'], $ADMIN);
$jeRow = $pdo->query("SELECT * FROM journal_entries WHERE id={$rK['journal_entry_id']}")->fetch(PDO::FETCH_ASSOC);
check('source_module = shares', $jeRow['source_module'] === 'shares');
check('source_reference_type = direct_purchase', $jeRow['source_reference_type'] === 'direct_purchase');
check('source_reference_id = the share_transactions row id', (int)$jeRow['source_reference_id'] === $rK['id']);
$linesK = $pdo->query("SELECT * FROM journal_lines WHERE journal_entry_id={$rK['journal_entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
check('exactly 2 journal lines (one debit, one credit)', count($linesK) === 2);
$totalDebit = array_sum(array_column($linesK, 'debit'));
$totalCredit = array_sum(array_column($linesK, 'credit'));
check('journal is balanced (debit total = credit total = 80,000)', (float)$totalDebit === 80000.0 && (float)$totalCredit === 80000.0);

// ================================================================
// L. Journal idempotency (JournalService's own built-in guarantee)
// ================================================================
section('L. Journal idempotency (reused, not reimplemented)');
$js = new JournalService();
$jeCountBeforeIdem = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$cashAcct = 7; $sharesAcct = 24;
$periodRow = $pdo->query("SELECT id FROM accounting_periods WHERE status='open' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$post1 = $js->post([
    'entry_date' => '2026-07-20', 'description' => 'Idempotency probe', 'source_module' => 'shares',
    'source_reference_type' => 'direct_purchase', 'source_reference_id' => 999999,
    'accounting_period_id' => $periodRow['id'] ?? null, 'created_by' => $ADMIN,
    'lines' => [['account_id'=>$cashAcct,'debit'=>1000,'credit'=>0,'description'=>'test'],['account_id'=>$sharesAcct,'debit'=>0,'credit'=>1000,'description'=>'test']],
]);
$post2 = $js->post([
    'entry_date' => '2026-07-20', 'description' => 'Idempotency probe (retry)', 'source_module' => 'shares',
    'source_reference_type' => 'direct_purchase', 'source_reference_id' => 999999,
    'accounting_period_id' => $periodRow['id'] ?? null, 'created_by' => $ADMIN,
    'lines' => [['account_id'=>$cashAcct,'debit'=>1000,'credit'=>0,'description'=>'test'],['account_id'=>$sharesAcct,'debit'=>0,'credit'=>1000,'description'=>'test']],
]);
check('a second post() with the same source identifiers returns the SAME entry, not a new one', $post1['id'] === $post2['id'] && $post2['created'] === false);
$jeCountAfterIdem = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('exactly one new journal entry created by the two calls combined', $jeCountAfterIdem === $jeCountBeforeIdem + 1);

// ================================================================
// M. Atomic rollback
// ================================================================
section('M. Atomic rollback on failure');
$mM = makeMember($memberModel, $ADMIN, 'ROLLBACK');
$stBeforeM = (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
$jeBeforeM = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$shrBeforeM = (int)$pdo->query("SELECT last_number FROM journal_number_sequences WHERE prefix='SHR'")->fetchColumn();
$logBeforeM = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
// Force a guaranteed post-insert failure: deactivate GL 3010 so JournalService::post() throws AFTER the share row is inserted.
$pdo->exec("UPDATE accounts SET is_active=0 WHERE id=24");
$threwM = false;
try {
    $shareModel->createCurrentTransaction(['member_id'=>$mM,'transaction_date'=>'2026-07-20','amount'=>30000,'payment_method'=>'Cash'], $ADMIN);
} catch (Throwable $e) { $threwM = true; }
$pdo->exec("UPDATE accounts SET is_active=1 WHERE id=24");
check('the operation throws when GL 3010 is inactive', $threwM);
check('NO share_transactions row was left behind', (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn() === $stBeforeM);
check('NO journal entry was left behind', (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $jeBeforeM);
check('SHR sequence UNCHANGED (the increment rolled back too)', (int)$pdo->query("SELECT last_number FROM journal_number_sequences WHERE prefix='SHR'")->fetchColumn() === $shrBeforeM);
check('NO success activity log was left behind', (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn() === $logBeforeM);

// ================================================================
// Audit logging
// ================================================================
section('Audit logging');
$logBefore = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$mLog = makeMember($memberModel, $ADMIN, 'AUDIT');
$rLog = $shareModel->createCurrentTransaction(['member_id'=>$mLog,'transaction_date'=>'2026-07-20','amount'=>20000,'payment_method'=>'Cash'], $ADMIN);
$logAfter = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
check('exactly one audit log row created', $logAfter === $logBefore + 1);
$logRow = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('action = share_current_transaction_recorded', $logRow['action'] === 'share_current_transaction_recorded');
check('description mentions the SHR reference', str_contains($logRow['description'], $rLog['reference_number']));

// ================================================================
// Q. Reporting
// ================================================================
section('Q. Reporting -- Share Report / Member Statement / Ledger / Position');
$currentFY = (int)date('Y', strtotime('2026-07-20'));
$reportRows = $reportModel->getShareReport();
$found = false;
foreach ($reportRows['top_shareholders'] as $sh) {
    if ($sh['member_number'] === $memberModel->find($mLog)['member_number']) { $found = true; break; }
}
check('current transaction appears in Share Report top_shareholders', $found);
$sharePos = $statementModel->sharePosition($mLog, $currentFY, 20000);
check('Member Statement sharePosition includes the current transaction (20,000)', abs($sharePos['total_retained'] - 20000) < 0.005, (string)$sharePos['total_retained']);
$ledger = $statementModel->shareLedger($mLog, $currentFY);
check('Member Statement shareLedger has 1 row for this member', count($ledger) === 1);
$memberCap = $shareModel->memberCapital($mLog);
check('Share Position (ShareModel::memberCapital) = 20,000', abs($memberCap - 20000) < 0.005);

// ================================================================
// R. Historical regression (Stage 3 untouched)
// ================================================================
section('R. Historical Stage 3 compatibility');
$mHist = makeMember($memberModel, $ADMIN, 'HISTCHECK');
$shrBeforeHist = (int)$pdo->query("SELECT last_number FROM journal_number_sequences WHERE prefix='SHR'")->fetchColumn();
$idHist = $shareModel->createHistoricalRetained(['member_id'=>$mHist,'transaction_date'=>'2025-04-30','retained_amount'=>45000], $ADMIN);
$rowHist = $pdo->query("SELECT * FROM share_transactions WHERE id=$idHist")->fetch(PDO::FETCH_ASSOC);
check('historical entry: quantity=2.2500 unchanged', (string)$rowHist['quantity'] === '2.2500');
check('historical entry: journal_entry_id still NULL', $rowHist['journal_entry_id'] === null);
$shrAfterHist = (int)$pdo->query("SELECT last_number FROM journal_number_sequences WHERE prefix='SHR'")->fetchColumn();
check('historical entry does NOT consume the SHR sequence', $shrAfterHist === $shrBeforeHist, "before=$shrBeforeHist after=$shrAfterHist");

echo "\n=== RESULTS: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
