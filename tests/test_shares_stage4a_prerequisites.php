<?php
/**
 * STAGE 4-A — Share Reporting & Reference Prerequisite Remediation — Test Harness
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
$reportModel  = new ReportModel();
$statementModel = new StatementModel();
$settingsModel = new SettingsModel();

$pdo->prepare("UPDATE settings SET setting_val = '20000' WHERE setting_key = 'share_value'")->execute();

function makeMember(MemberModel $mm, int $admin, string $tag): int {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage4aREM', 'last_name' => "Member{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000005' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "CM4aREM{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'active',
    ], $admin);
    return $m['member_id'];
}

// ================================================================
// Database
// ================================================================
section('Database — external_reference column');
$col = $pdo->query("SHOW COLUMNS FROM share_transactions WHERE Field='external_reference'")->fetch(PDO::FETCH_ASSOC);
check('external_reference column exists', $col !== false);
check('external_reference type is varchar(100)', ($col['Type'] ?? '') === 'varchar(100)', $col['Type'] ?? 'MISSING');
check('external_reference is nullable', ($col['Null'] ?? '') === 'YES');
$expectedCols = ['id','member_id','transaction_type','transaction_date','quantity','share_value','amount',
    'payment_method','reference_number','external_reference','source_reference_type','source_reference_id',
    'journal_entry_id','processed_by','created_at','updated_at'];
$actualCols = array_column($pdo->query("SHOW COLUMNS FROM share_transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
check('all pre-existing columns remain, in addition to external_reference', count(array_diff($expectedCols, $actualCols)) === 0, implode(',', array_diff($expectedCols, $actualCols)));
check('exactly one new column added (16 total, was 15)', count($actualCols) === 16, (string)count($actualCols));

section('Database — SHR sequence row');
$shrRows = $pdo->query("SELECT * FROM journal_number_sequences WHERE prefix='SHR'")->fetchAll(PDO::FETCH_ASSOC);
check('SHR exists exactly once', count($shrRows) === 1);
check('SHR.last_number = 0', count($shrRows) === 1 && (int)$shrRows[0]['last_number'] === 0);
$otherSeqCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_number_sequences WHERE prefix != 'SHR'")->fetchColumn();
check('every other existing sequence prefix is untouched (still 22)', $otherSeqCount === 22, (string)$otherSeqCount);

// ================================================================
// Historical compatibility (Stage 3 must still work identically)
// ================================================================
section('Historical compatibility — Stage 3 structure intact');
$mid1 = makeMember($memberModel, $ADMIN, 'HIST');
$idR = $shareModel->createHistoricalRetained(['member_id'=>$mid1,'transaction_date'=>'2025-04-30','retained_amount'=>45000], $ADMIN);
$rowR = $pdo->query("SELECT * FROM share_transactions WHERE id=$idR")->fetch(PDO::FETCH_ASSOC);
check('historical retained: quantity=2.2500 (fractional, unchanged)', (string)$rowR['quantity'] === '2.2500', $rowR['quantity']);
check('historical retained: journal_entry_id still NULL (no posting)', $rowR['journal_entry_id'] === null);
check('historical retained: external_reference is NULL (not populated by Stage 3 code)', $rowR['external_reference'] === null);

$idP = $shareModel->createHistoricalPurchase(['member_id'=>$mid1,'transaction_date'=>'2025-04-30','quantity'=>5], $ADMIN);
$rowP = $pdo->query("SELECT * FROM share_transactions WHERE id=$idP")->fetch(PDO::FETCH_ASSOC);
check('historical purchase: amount=100000.00 (unchanged)', (float)$rowP['amount'] === 100000.00);
check('historical purchase: journal_entry_id still NULL (no posting)', $rowP['journal_entry_id'] === null);

// Historical value preservation, re-verified end to end through this stage's changes.
$pdo->prepare("UPDATE settings SET setting_val = '30000' WHERE setting_key = 'share_value'")->execute();
$rowRAfter = $pdo->query("SELECT * FROM share_transactions WHERE id=$idR")->fetch(PDO::FETCH_ASSOC);
check('stored historical share_value unchanged after a settings change (still 20000.00)', (string)$rowRAfter['share_value'] === '20000.00');
check('stored historical quantity unchanged after a settings change (still 2.2500)', (string)$rowRAfter['quantity'] === '2.2500');
$pdo->prepare("UPDATE settings SET setting_val = '20000' WHERE setting_key = 'share_value'")->execute();

// ================================================================
// Reporting — Share Report (ReportModel::getShareReport())
// ================================================================
section('Reporting — Share Report now includes share_transactions');
$mid2 = makeMember($memberModel, $ADMIN, 'REPORT');
$shareModel->createHistoricalRetained(['member_id'=>$mid2,'transaction_date'=>'2025-04-30','retained_amount'=>50000], $ADMIN);
$report = $reportModel->getShareReport();
check('total_share_capital includes the historical entry (>= 50,000)', $report['total_share_capital'] >= 50000, (string)$report['total_share_capital']);
$foundInTop = false;
foreach ($report['top_shareholders'] as $sh) {
    if ($sh['member_number'] === $memberModel->find($mid2)['member_number']) { $foundInTop = true; break; }
}
check('the historical-entry member appears in top_shareholders', $foundInTop);
check('top_shareholders rows still use the original "total_shares" key (view compatibility preserved)', isset($report['top_shareholders'][0]['total_shares']) || empty($report['top_shareholders']));

// ================================================================
// Reporting — Member Statement (StatementModel)
// ================================================================
section('Reporting — Member Statement now includes share_transactions');
$mid3 = makeMember($memberModel, $ADMIN, 'STMT');
$shareModel->createHistoricalRetained(['member_id'=>$mid3,'transaction_date'=>'2025-04-30','retained_amount'=>40000], $ADMIN);
$shareModel->createHistoricalPurchase(['member_id'=>$mid3,'transaction_date'=>'2025-04-30','quantity'=>3], $ADMIN);
$currentFY = (int)date('Y');
$sharePos = $statementModel->sharePosition($mid3, $currentFY, 20000);
check('sharePosition() total_retained = 40,000 + 60,000 = 100,000', abs($sharePos['total_retained'] - 100000) < 0.005, (string)$sharePos['total_retained']);
check('sharePosition() count = floor(100000/20000) = 5 (existing floor() display convention preserved, unchanged)', $sharePos['count'] === 5);
$ledger = $statementModel->shareLedger($mid3, $currentFY);
check('shareLedger() has exactly 2 rows for this member', count($ledger) === 2, (string)count($ledger));
$descriptions = array_column($ledger, 'description');
$hasRetainedDesc = false; $hasPurchaseDesc = false;
foreach ($descriptions as $d) {
    if (str_contains($d, 'Retained Savings')) $hasRetainedDesc = true;
    if (str_contains($d, 'Bought Shares')) $hasPurchaseDesc = true;
}
check('ledger description distinguishes "Opening — Retained Savings"', $hasRetainedDesc, implode('|', $descriptions));
check('ledger description distinguishes "Opening — Bought Shares"', $hasPurchaseDesc, implode('|', $descriptions));
check('ledger running balance ends at 100,000', abs(end($ledger)['balance'] - 100000) < 0.005);

// ================================================================
// Withdrawal mirror / double-counting protection
// ================================================================
section('Withdrawal mirror / double-counting protection');
$policyModel = new WithdrawalPolicyModel();
$accountModel = new MemberSavingsAccountModel();
$savingsModel = new SavingsModel();
$wdlModel = new WithdrawalModel();
$pdo->exec("DELETE FROM savings_withdrawal_policies");
$policyModel->createPolicy(['account_type'=>'compulsory','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>50,'share_conversion_percent'=>50,'frequency'=>'once_per_financial_year','effective_from'=>'2026-01-01'], $ADMIN);
$policyModel->createPolicy(['account_type'=>'voluntary','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>100,'share_conversion_percent'=>0,'frequency'=>'any_time','effective_from'=>'2026-01-01'], $ADMIN);

$mid4 = makeMember($memberModel, $ADMIN, 'MIRROR');
$acctId = $accountModel->getMemberAccounts($mid4)[0]['id'];
$savingsModel->recordDepositWithPosting(['member_id'=>$mid4,'savings_account_id'=>$acctId,'transaction_type'=>'deposit','amount'=>20000,'payment_method'=>'Cash','transaction_date'=>'2026-07-05','receipt_number'=>$savingsModel->generateReceiptNumber(),'recorded_by'=>$ADMIN], $ADMIN);
$savingsModel->recordDepositWithPosting(['member_id'=>$mid4,'savings_account_id'=>$acctId,'transaction_type'=>'deposit','amount'=>80000,'payment_method'=>'Cash','transaction_date'=>'2026-07-10','receipt_number'=>$savingsModel->generateReceiptNumber(),'recorded_by'=>$ADMIN], $ADMIN);
$accountModel->recordQualification($acctId);
$w = $wdlModel->processAnnualCompulsory(['member_id'=>$mid4,'requested_amount'=>25000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$wRow = $wdlModel->find($w);
// balance=100,000; ceiling=100%; retained = 100,000 - 25,000 = 75,000 (Model A formula, re-derived)
check('retained amount = 75,000 (sanity check on the fixture)', (float)$wRow['retained_amount'] === 75000.0);

$statementFY = (int)date('Y', strtotime('2026-07-20'));
$totalViaStatement = $statementModel->totalRetainedShares($mid4, $statementFY);
check('Statement total = 75,000 exactly once (not double-counted across withdrawals+share_transactions)', abs($totalViaStatement - 75000) < 0.005, (string)$totalViaStatement);
$totalViaShareModel = $shareModel->memberCapital($mid4);
check('ShareModel total agrees exactly with Statement total (single source of truth)', abs($totalViaShareModel - $totalViaStatement) < 0.005);

$reportAfterMirror = $reportModel->getShareReport();
$mirrorMemberFound = false;
foreach ($reportAfterMirror['top_shareholders'] as $sh) {
    if ($sh['member_number'] === $memberModel->find($mid4)['member_number']) {
        check("Share Report shows this member's total as 75,000 exactly (not doubled)", abs($sh['total_shares'] - 75000) < 0.005, (string)$sh['total_shares']);
        $mirrorMemberFound = true;
    }
}
check('mirrored-withdrawal member correctly appears in the Share Report', $mirrorMemberFound);

$mirrorLedger = $statementModel->shareLedger($mid4, $statementFY);
check('Statement share ledger has exactly 1 row for this withdrawal (not 2)', count($mirrorLedger) === 1, (string)count($mirrorLedger));
check('the single row description reads "Shares Retained (WDL-...)"', str_contains($mirrorLedger[0]['description'], 'Shares Retained'));

// ================================================================
// Accounting safety
// ================================================================
section('Accounting safety');
$jeCountA = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$mid5 = makeMember($memberModel, $ADMIN, 'ACCTSAFE');
$shareModel->createHistoricalRetained(['member_id'=>$mid5,'transaction_date'=>'2025-04-30','retained_amount'=>20000], $ADMIN);
$reportModel->getShareReport();
$statementModel->sharePosition($mid5, $currentFY, 20000);
$statementModel->shareLedger($mid5, $currentFY);
$jeCountB = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('reading reports/statements creates NO journal entries (before='.$jeCountA.', after='.$jeCountB.')', $jeCountA === $jeCountB);

echo "\n=== RESULTS: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
