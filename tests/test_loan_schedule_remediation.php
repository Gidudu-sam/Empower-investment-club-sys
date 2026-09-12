<?php
/**
 * Stage — Loan Schedule & Due-Date Integrity Remediation
 * Test matrix + Remediation C concurrency proof.
 *
 * Clone setup: entirely via PDO on a single connection with no database context.
 * Uses explicit database.table notation throughout — never switches DB context,
 * never calls USE, never uses mysqldump. This avoids the MariaDB metadata-lock
 * hang that occurred when switching database context with open connections.
 */
if (getenv('EMPOWER_ALLOW_PROD_TEST')) { die("ABORT: EMPOWER_ALLOW_PROD_TEST set.\n"); }
ini_set('display_errors','1');
error_reporting(E_ALL);
set_time_limit(300);

$root   = dirname(__DIR__);
$phpExe = 'C:\\xampp\\php\\php.exe';

// ─── Harness ──────────────────────────────────────────────────────────────────
$passed = $failed = 0;
$allResults = [];
function ok(string $l, bool $v, string $e=''): void {
    global $passed,$failed,$allResults;
    if($v){$passed++;echo "  [PASS] {$l}\n";}
    else{$failed++;echo "  [FAIL] {$l}".($e?" | {$e}":'')."\n";}
    $allResults[]=['label'=>$l,'pass'=>$v];
}
function eq(string $l, mixed $exp, mixed $got, string $e=''): void {
    $v=(is_string($exp)&&is_string($got))?$exp===$got:(abs((float)$exp-(float)$got)<0.02);
    ok($l,$v,$e?:('expected='.var_export($exp,true).' got='.var_export($got,true)));
}
function throws(string $l, callable $fn, string $cls='Throwable'): void {
    global $passed,$failed,$allResults;
    try{$fn();$failed++;echo "  [FAIL] {$l} — no exception\n";$allResults[]=['label'=>$l,'pass'=>false];}
    catch(Throwable $ex){
        if($ex instanceof $cls){$passed++;echo "  [PASS] {$l}\n";$allResults[]=['label'=>$l,'pass'=>true];}
        else{$failed++;echo "  [FAIL] {$l} — ".get_class($ex).": {$ex->getMessage()}\n";$allResults[]=['label'=>$l,'pass'=>false];}
    }
}
function sec(string $t): void { echo "\n=== {$t} ===\n"; }

// ─── Clone name ───────────────────────────────────────────────────────────────
$cloneDb = 'loansched_rem_'.date('Ymd_His');
echo "Clone: {$cloneDb}\n";

// ─── Open ONE PDO connection — no database selected ───────────────────────────
// This connection is used for schema collection only.
// A SEPARATE connection to the clone is opened after CREATE DATABASE.
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
    'root', '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]
);

// ─── Create clone DB ──────────────────────────────────────────────────────────
sec('Clone Setup');

// ─── STEP A: Collect all schema + ref data from empower_db FIRST ─────────────
// This must happen before CREATE DATABASE so we use the no-dbname connection
// exclusively for empower_db reads, then close it.

function stripFKs(string $sql): string {
    return preg_replace(
        '/,\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY\s+\([^)]+\)\s+REFERENCES\s+`[^`]+`\s*\([^)]+\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+\w+(?:\s+\w+)?){0,2}/i',
        '', $sql
    );
}

$schemaTables = [
    'roles','accounts','financial_years','fees','accounting_periods',
    'users','members','loan_applications',
    'loan_types','loan_product_settings','loan_product_rules','loan_interest_brackets',
    'journal_number_sequences','journal_entries','journal_lines','journal_entry_audit',
    'loans','loan_installments','loan_repayments','loan_penalties',
    'loan_weekly_interest','loan_weekly_savings',
    'business_loan_interest_payments','business_loan_weekly_savings',
    'member_savings_accounts','savings','activity_logs','notifications','member_fees',
];

$refTables = [
    'roles','accounts','financial_years','fees','accounting_periods',
    'loan_types','loan_product_settings','loan_product_rules','loan_interest_brackets',
    'journal_number_sequences',
];

// Collect CREATE TABLE statements
$createSQLs = [];
foreach ($schemaTables as $t) {
    try {
        $r   = $pdo->query("SHOW CREATE TABLE `empower_db`.`{$t}`")->fetch();
        $sql = $r['Create Table'] ?? ($r[1] ?? null);
        if (!$sql) continue;
        $sql = stripFKs($sql);
        $sql = preg_replace('/^CREATE TABLE `([^`]+)`/', "CREATE TABLE IF NOT EXISTS `\\1`", $sql);
        $createSQLs[$t] = $sql;
    } catch (PDOException $ex) { /* skip */ }
}
echo "  Collected " . count($createSQLs) . " CREATE TABLE statements.\n";

// Collect reference data as INSERT IGNORE statements
$insertSQLs = [];
foreach ($refTables as $t) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `empower_db`.`{$t}`")->fetchAll(PDO::FETCH_COLUMN);
        $rows = $pdo->query("SELECT * FROM `empower_db`.`{$t}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $vals = array_map(fn($v) => $v===null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            $insertSQLs[] = "INSERT IGNORE INTO `{$t}` VALUES (" . implode(',', $vals) . ")";
        }
    } catch (PDOException $ex) { /* skip */ }
}
echo "  Collected " . count($insertSQLs) . " reference data rows.\n";

// ─── STEP B: Create the clone DB ──────────────────────────────────────────────
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cloneDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "  DB created.\n";

// Close the no-database connection — completely done with empower_db
unset($pdo);

// ─── STEP C: Open a fresh connection directly to the clone ────────────────────
// Using dbname= in the DSN avoids the USE statement which triggers a
// global DDL lock in MariaDB 10.4 when open transactions exist.
$pdo = new PDO(
    "mysql:host=127.0.0.1;port=3306;dbname={$cloneDb};charset=utf8mb4",
    'root', '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]
);
echo "  Connected to clone.\n";

// ─── STEP D: Create schema ────────────────────────────────────────────────────
$pdo->exec("SET SESSION foreign_key_checks=0");
$created = 0;
foreach ($createSQLs as $t => $sql) {
    try { $pdo->exec($sql); $created++; }
    catch (PDOException $ex) { echo "  ! {$t}: " . $ex->getMessage() . "\n"; }
}
$pdo->exec("SET SESSION foreign_key_checks=1");
echo "  Tables created: {$created}/" . count($schemaTables) . "\n";

// ─── STEP E: Seed reference data ─────────────────────────────────────────────
$pdo->exec("SET SESSION foreign_key_checks=0");
foreach ($insertSQLs as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $ex) { /* dup/FK skip */ }
}
$pdo->exec("INSERT IGNORE INTO `users` (id,role_id,full_name,email,password_hash,is_active) VALUES (1,1,'Test Admin','admin@test.local','x',1)");
$pdo->exec("INSERT IGNORE INTO `members` (id,first_name,last_name,member_number,status) VALUES (1,'Test','Member','MEM-000001','active')");
$pdo->exec("SET SESSION foreign_key_checks=1");

// ─── Verify ───────────────────────────────────────────────────────────────────
$loansOk = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA='{$cloneDb}' AND TABLE_NAME='loans'")->fetchColumn();
$acctRows = (int)$pdo->query("SELECT COUNT(*) FROM `accounts`")->fetchColumn();
if (!$loansOk || $acctRows === 0) {
    die("ABORT: Clone missing critical tables or data (loans={$loansOk}, accounts={$acctRows})\n");
}
echo "  Clone ready (accounts={$acctRows} rows).\n";

// ─── Wire app models to clone ─────────────────────────────────────────────────
define('DB_HOST','127.0.0.1'); define('DB_PORT','3306');
define('DB_NAME',$cloneDb);    define('DB_CHARSET','utf8mb4');
define('DB_USER','root');       define('DB_PASS','');
define('APP_PATH',$root.'/app');

require_once $root.'/core/Database.php';
require_once $root.'/core/Model.php';

// Inject the clone PDO into the Database singleton so all models use it.
// The clone PDO has dbname= in its DSN — bare table names resolve correctly.
$dbSingle = Database::getInstance();
$refl = new ReflectionClass($dbSingle);
$pp = $refl->getProperty('pdo'); $pp->setAccessible(true); $pp->setValue($dbSingle, $pdo);

require_once $root.'/app/models/AccountModel.php';
require_once $root.'/app/models/JournalEntryModel.php';
require_once $root.'/app/models/JournalLineModel.php';
require_once $root.'/app/models/LoanProductModel.php';
require_once $root.'/app/services/JournalService.php';
require_once $root.'/app/models/LoanModel.php';
require_once $root.'/app/models/RepaymentModel.php';

$lm = new LoanModel();
$rm = new RepaymentModel();

// ─── Helpers ──────────────────────────────────────────────────────────────────
function makeLoan(PDO $pdo, LoanModel $lm, array $ov=[]): array {
    static $n=0; $n++;
    $d=array_merge([
        'loan_number'=>'TST-'.$n.'-'.uniqid(),
        'member_id'=>1,'loan_type_id'=>1,
        'loan_amount'=>1000000.,'interest_rate'=>10.,
        'interest_amount'=>400000.,'monthly_installment'=>350000.,
        'total_payable'=>1400000.,'outstanding'=>1400000.,'amount_paid'=>0.,
        'loan_period_months'=>4,'grace_period_months'=>0,
        'issue_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d',strtotime('+4 months')),
        'status'=>'approved','repayment_frequency'=>'monthly',
        'interest_mode'=>'percentage','repayment_method'=>'standard',
        'recorded_by'=>1,'processing_fee'=>0.,'fixed_interest_amount'=>0.,
        'interest_only_months'=>0,'principal_recovery_weeks'=>0,
    ],$ov);
    $cols=implode(',',array_map(fn($c)=>"`{$c}`",array_keys($d)));
    $ph=implode(',',array_fill(0,count($d),'?'));
    $pdo->prepare("INSERT INTO `loans` ({$cols}) VALUES ({$ph})")->execute(array_values($d));
    $id=(int)$pdo->lastInsertId();
    try{$r=$lm->disburse($id,1,'Cash');return['id'=>$id,'err'=>null,'loan'=>$lm->find($id)];}
    catch(Throwable $e){return['id'=>$id,'err'=>$e->getMessage(),'loan'=>$lm->find($id)];}
}
function pay(RepaymentModel $rm,int $lid,float $amt,string $type='installment'): int|false {
    return $rm->recordRepayment([
        'loan_id'=>$lid,'member_id'=>1,'amount_paid'=>$amt,
        'payment_date'=>date('Y-m-d'),'payment_method'=>'Cash','received_by'=>1,
        'payment_type'=>$type,'submission_token'=>bin2hex(random_bytes(16)),'penalty_paid'=>0,
    ]);
}

$disbDate = date('Y-m-d');

// ══════════════════════════════════════════════════════════════════════
sec('M1-M6: Standard Monthly Schedules');
$mScen=['M1'=>[600000,10,4,240000,840000],'M2'=>[1000000,10,4,400000,1400000],
        'M3'=>[2000000,5,6,600000,2600000],'M4'=>[6000000,4,6,1440000,7440000],
        'M5'=>[12000000,3,6,2160000,14160000],'M6'=>[16000000,2,6,1920000,17920000]];
foreach($mScen as $id=>[$pri,$rate,$mos,$expI,$expT]){
    static $ms=0;$ms++;
    $mInst=round($expT/$mos,2); $mInte=round($pri*($rate/100),2);
    $pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,
        interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,
        grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,
        repayment_method,recorded_by,processing_fee,fixed_interest_amount,
        interest_only_months,principal_recovery_weeks)
        VALUES(?,1,1,?,?,?,?,?,?,0,?,0,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? MONTH),
        'approved','monthly','percentage','standard',1,0,0,0,0)")
        ->execute(["TST-{$id}-{$ms}",$pri,$rate,$expI,$mInst,$expT,$expT,$mos,$mos]);
    $lid=(int)$pdo->lastInsertId();
    $lm->generateInstallments($lid,$mInst,$mos,$disbDate,0,$mInte,$pri);
    $rows=$pdo->query("SELECT * FROM loan_installments WHERE loan_id={$lid}")->fetchAll();
    eq("{$id}: count",$mos,count($rows));
    eq("{$id}: SUM(amount_due)",$expT,round(array_sum(array_column($rows,'amount_due')),2));
    eq("{$id}: SUM(principal_due)",$pri,round(array_sum(array_column($rows,'principal_due')),2));
    eq("{$id}: SUM(interest_due)",$expI,round(array_sum(array_column($rows,'interest_due')),2));
    $bad=count(array_filter(array_slice($rows,0,-1),fn($r)=>abs((float)$r['principal_due']+(float)$r['interest_due']-(float)$r['amount_due'])>0.02));
    eq("{$id}: per-row p+i=amount",0,$bad);
}

// ══════════════════════════════════════════════════════════════════════
sec('DD7-DD13: addCalendarMonths() — Remediation B');
$calCases=[
    ['2026-01-31',1,'2026-02-28','DD7'],  ['2026-01-31',2,'2026-03-31','DD7b'],
    ['2026-01-31',3,'2026-04-30','DD7c'], ['2026-01-31',4,'2026-05-31','DD7d'],
    ['2024-01-31',1,'2024-02-29','DD8'],  ['2024-01-31',2,'2024-03-31','DD8b'],
    ['2024-01-31',3,'2024-04-30','DD8c'], ['2026-01-30',1,'2026-02-28','DD9'],
    ['2026-01-30',2,'2026-03-30','DD9b'], ['2026-01-30',3,'2026-04-30','DD9c'],
    ['2026-02-28',1,'2026-03-28','DD10'], ['2024-02-29',1,'2024-03-29','DD11'],
    ['2024-02-29',2,'2024-04-29','DD11b'],['2026-03-31',1,'2026-04-30','DD12'],
    ['2026-03-31',2,'2026-05-31','DD12b'],['2026-04-30',1,'2026-05-30','DD13'],
    ['2026-04-30',2,'2026-06-30','DD13b'],['2026-01-15',1,'2026-02-15','DD_a'],
    ['2026-01-15',2,'2026-03-15','DD_b'], ['2026-01-15',3,'2026-04-15','DD_c'],
];
foreach($calCases as[$start,$n,$exp,$lbl]){ eq($lbl,$exp,LoanModel::addCalendarMonths($start,$n)); }

$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,
    interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,
    grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,
    repayment_method,recorded_by,processing_fee,fixed_interest_amount,
    interest_only_months,principal_recovery_weeks)
    VALUES(?,1,1,1000000,10,400000,350000,1400000,1400000,0,4,0,'2026-01-31','2026-05-31',
    'approved','monthly','percentage','standard',1,0,0,0,0)")
    ->execute(['TST-DD-'.uniqid()]);
$ddId=(int)$pdo->lastInsertId();
$lm->generateInstallments($ddId,350000,4,'2026-01-31',0,100000,1000000);
$ddD=$pdo->query("SELECT due_date FROM loan_installments WHERE loan_id={$ddId} ORDER BY installment_no")->fetchAll(PDO::FETCH_COLUMN);
eq('DD/schedule inst1=2026-02-28','2026-02-28',$ddD[0]??'?');
eq('DD/schedule inst2=2026-03-31','2026-03-31',$ddD[1]??'?');
eq('DD/schedule inst3=2026-04-30','2026-04-30',$ddD[2]??'?');
eq('DD/schedule inst4=2026-05-31','2026-05-31',$ddD[3]??'?');
$skip=false;
for($i=1;$i<count($ddD);$i++){
    $gap=(date('Y',$a=strtotime($ddD[$i]))*12+date('n',$a))-(date('Y',$b=strtotime($ddD[$i-1]))*12+date('n',$b));
    if($gap>1){$skip=true;break;}
}
ok('DD/schedule: no month skipped',!$skip);

// ══════════════════════════════════════════════════════════════════════
sec('SL14-SL20: Schedule Lifecycle — Disbursement Anchor');
$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,
    interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,
    grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,
    repayment_method,recorded_by,processing_fee,fixed_interest_amount,
    interest_only_months,principal_recovery_weeks)
    VALUES(?,1,1,1000000,10,400000,350000,1400000,1400000,0,4,0,CURDATE(),
    DATE_ADD(CURDATE(),INTERVAL 4 MONTH),'draft','monthly','percentage','standard',1,0,0,0,0)")
    ->execute(['TST-SL14-'.uniqid()]);
$draftId=(int)$pdo->lastInsertId();
eq('SL14: draft=0 installments',0,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$draftId}")->fetchColumn());

$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,
    interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,
    grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,
    repayment_method,recorded_by,processing_fee,fixed_interest_amount,
    interest_only_months,principal_recovery_weeks)
    VALUES(?,1,1,1000000,10,400000,350000,1400000,1400000,0,4,0,CURDATE(),
    DATE_ADD(CURDATE(),INTERVAL 4 MONTH),'approved','monthly','percentage','standard',1,0,0,0,0)")
    ->execute(['TST-SL15-'.uniqid()]);
$aprvId=(int)$pdo->lastInsertId();
eq('SL15: approved-not-disbursed=0 installments',0,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$aprvId}")->fetchColumn());

$sl16=makeLoan($pdo,$lm);
ok('SL16: disbursement succeeds',$sl16['err']===null,$sl16['err']??'');
eq('SL16: 4 installments after disburse',4,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$sl16['id']}")->fetchColumn());
$firstDue=$pdo->query("SELECT due_date FROM loan_installments WHERE loan_id={$sl16['id']} ORDER BY installment_no LIMIT 1")->fetchColumn();
eq('SL17: first due=disburse+1m',LoanModel::addCalendarMonths($disbDate,1),$firstDue);

$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,
    interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,
    grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,
    repayment_method,recorded_by,processing_fee,fixed_interest_amount,
    interest_only_months,principal_recovery_weeks)
    VALUES(?,1,1,1000000,10,400000,350000,9999999,9999999,0,4,0,CURDATE(),
    DATE_ADD(CURDATE(),INTERVAL 4 MONTH),'approved','monthly','percentage','standard',1,0,0,0,0)")
    ->execute(['TST-SL18-'.uniqid()]);
$badId=(int)$pdo->lastInsertId();
$threw18=false; try{$lm->disburse($badId,1,'Cash');}catch(Throwable $e){$threw18=true;}
ok('SL18: mismatch total_payable throws',$threw18);
eq('SL18: rollback=0 installments',0,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$badId}")->fetchColumn());
eq('SL18: status still approved','approved',$pdo->query("SELECT status FROM loans WHERE id={$badId}")->fetchColumn());
ok('SL18: disbursed_at is NULL',empty($pdo->query("SELECT disbursed_at FROM loans WHERE id={$badId}")->fetchColumn()));

$lm->generateInstallments($sl16['id'],350000,4,$disbDate,0,100000,1000000);
$lm->generateInstallments($sl16['id'],350000,4,$disbDate,0,100000,1000000);
eq('SL19: double-regen=4 rows',4,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$sl16['id']}")->fetchColumn());
eq('SL19: no dups',0,(int)$pdo->query("SELECT COUNT(*) FROM (SELECT installment_no,COUNT(*) c FROM loan_installments WHERE loan_id={$sl16['id']} GROUP BY installment_no HAVING c>1) x")->fetchColumn());

// ══════════════════════════════════════════════════════════════════════
sec('PP21-PP26: Payment Scenarios');
$pL=makeLoan($pdo,$lm); ok('PP/setup',$pL['err']===null); $pid=$pL['id'];
$p1=pay($rm,$pid,200000); ok('PP21: partial succeeds',is_int($p1)&&$p1>0);
eq('PP21: outstanding=1.2M',1200000,(float)$lm->find($pid)['outstanding']);
$i1=$pdo->query("SELECT * FROM loan_installments WHERE loan_id={$pid} AND installment_no=1")->fetch();
eq('PP21: inst#1=partial','partial',$i1['status']); eq('PP21: inst#1 paid=200k',200000,(float)$i1['amount_paid']);
pay($rm,$pid,300000);
eq('PP22: outstanding=900k',900000,(float)$lm->find($pid)['outstanding']);
$i1b=$pdo->query("SELECT status FROM loan_installments WHERE loan_id={$pid} AND installment_no=1")->fetchColumn();
eq('PP23: inst#1=paid after 350k','paid',$i1b);
pay($rm,$pid,900000,'settlement');
$lf=$lm->find($pid);
eq('PP24: outstanding=0',0,(float)$lf['outstanding']); eq('PP24: completed','completed',$lf['status']);
eq('PP25: all paid',(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$pid}")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$pid} AND status='paid'")->fetchColumn());
eq('PP25: SUM repayments=1.4M',1400000,(float)$pdo->query("SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id={$pid}")->fetchColumn());
$ovL=makeLoan($pdo,$lm,['loan_amount'=>100000,'interest_amount'=>20000,'monthly_installment'=>120000,'total_payable'=>120000,'outstanding'=>120000,'loan_period_months'=>1]);
ok('PP26/setup',$ovL['err']===null);
throws('PP26: model rejects overpayment',fn()=>$rm->recordRepayment(['loan_id'=>$ovL['id'],'member_id'=>1,'amount_paid'=>999999,'payment_date'=>date('Y-m-d'),'payment_method'=>'Cash','received_by'=>1,'payment_type'=>'installment','submission_token'=>bin2hex(random_bytes(16)),'penalty_paid'=>0]),'InvalidArgumentException');
eq('PP26b: no row after rejection',0,(int)$pdo->query("SELECT COUNT(*) FROM loan_repayments WHERE loan_id={$ovL['id']}")->fetchColumn());
eq('PP26c: outstanding unchanged',(float)$ovL['loan']['outstanding'],(float)$lm->find($ovL['id'])['outstanding']);

// ══════════════════════════════════════════════════════════════════════
sec('IA27-IA30: Installment Accounting & GL (M2 mandatory scenario)');
$ia=makeLoan($pdo,$lm,['loan_amount'=>1000000,'interest_amount'=>400000,'monthly_installment'=>350000,'total_payable'=>1400000,'outstanding'=>1400000,'loan_period_months'=>4]);
ok('IA/setup',$ia['err']===null);
$iR=$pdo->query("SELECT * FROM loan_installments WHERE loan_id={$ia['id']} ORDER BY installment_no")->fetchAll();
eq('IA27: 4 rows',4,count($iR));
eq('IA27: SUM amount_due=1.4M',1400000,round(array_sum(array_column($iR,'amount_due')),2));
eq('IA27: SUM principal_due=1M',1000000,round(array_sum(array_column($iR,'principal_due')),2));
eq('IA27: SUM interest_due=400k',400000,round(array_sum(array_column($iR,'interest_due')),2));
ok('IA28: principal_paid=0 (Remediation E deferred)',array_sum(array_column($iR,'principal_paid'))==0);
ok('IA28: interest_paid_amt=0 (Remediation E deferred)',array_sum(array_column($iR,'interest_paid_amt'))==0);
pay($rm,$ia['id'],1400000,'settlement');
$iaF=$lm->find($ia['id']);
eq('IA29: outstanding=0',0,(float)$iaF['outstanding']); eq('IA29: completed','completed',$iaF['status']);
eq('IA30: all paid',(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$ia['id']}")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$ia['id']} AND status='paid'")->fetchColumn());
$gl=$pdo->query("SELECT a.code,SUM(jl.debit) dr,SUM(jl.credit) cr FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.source_reference_id={$ia['id']} AND je.source_module='loans' GROUP BY a.code")->fetchAll();
$byCode=array_column($gl,null,'code');
eq('IA30/GL: Loans Rec Dr=1M',1000000,(float)($byCode['1180']['dr']??0));
eq('IA30/GL: Loans Rec Cr=1M',1000000,(float)($byCode['1180']['cr']??0));
eq('IA30/GL: Interest Inc Cr=400k',400000,(float)($byCode['4035']['cr']??0));

// ══════════════════════════════════════════════════════════════════════
sec('WK31-WK34: Business Loan & Weekly Schedules');
$pm=new LoanProductModel();
$bbc=$pm->calculateBusinessBoost(600000,10,6,4,8);
$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,repayment_method,recorded_by,processing_fee,fixed_interest_amount,interest_only_months,principal_recovery_weeks) VALUES(?,1,2,600000,10,?,?,?,?,0,6,0,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 6 MONTH),'approved','monthly','percentage','business_boost',1,0,0,0,0)")
    ->execute(['TST-BB-'.uniqid(),$bbc['total_interest'],$bbc['total_payable'],$bbc['total_payable'],$bbc['total_payable']]);
$bbId=(int)$pdo->lastInsertId();
$pm->generateBusinessBoostSchedule($bbId,600000,10,6,4,8,$disbDate);
$bbR=$pdo->query("SELECT * FROM loan_installments WHERE loan_id={$bbId} ORDER BY installment_no")->fetchAll();
eq('WK31: BB=12 rows',12,count($bbR));
eq('WK31: SUM=total_payable',round($bbc['total_payable'],2),round(array_sum(array_column($bbR,'amount_due')),2));
eq('WK31: SUM principal=600k',600000,round(array_sum(array_column($bbR,'principal_due')),2));
$wkR=array_values(array_filter($bbR,fn($r)=>($r['period_type']??'')==='weekly'));
$wkOk=true; for($i=1;$i<count($wkR);$i++){if((int)((strtotime($wkR[$i]['due_date'])-strtotime($wkR[$i-1]['due_date']))/86400)!==7){$wkOk=false;break;}}
ok('WK32: recovery rows 7 days apart',$wkOk);
$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,repayment_method,recorded_by,processing_fee,fixed_interest_amount,interest_only_months,principal_recovery_weeks) VALUES(?,1,1,600000,10,240000,52500,840000,840000,0,4,0,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 4 MONTH),'approved','weekly','percentage','standard',1,0,0,0,0)")->execute(['TST-WK33-'.uniqid()]);
$wkId=(int)$pdo->lastInsertId();
$lm->generateWeeklyInstallmentSchedule($wkId,600000,240000,840000,4,$disbDate);
$wkD=$pdo->query("SELECT due_date FROM loan_installments WHERE loan_id={$wkId} ORDER BY installment_no")->fetchAll(PDO::FETCH_COLUMN);
eq('WK33: 16 rows',16,count($wkD));
$w7ok=true; for($i=1;$i<count($wkD);$i++){if((int)((strtotime($wkD[$i])-strtotime($wkD[$i-1]))/86400)!==7){$w7ok=false;break;}}
ok('WK33: all rows 7d apart',$w7ok);
$wkSum=round(array_sum($pdo->query("SELECT amount_due FROM loan_installments WHERE loan_id={$wkId}")->fetchAll(PDO::FETCH_COLUMN)),2);
eq('WK33: SUM=840k',840000,$wkSum);
eq('WK34: 0 savings-type rows',0,count(array_filter($pdo->query("SELECT installment_type FROM loan_installments WHERE loan_id={$wkId}")->fetchAll(PDO::FETCH_COLUMN),fn($t)=>$t==='savings')));

// ══════════════════════════════════════════════════════════════════════
sec('AUTH35-AUTH37: printSchedule() authorization');
$src=file_get_contents($root.'/app/controllers/LoanController.php');
$psS=strpos($src,'public function printSchedule()');
$psE=strpos($src,'public function statement()',$psS);
$psB=substr($src,$psS,$psE-$psS);
ok("AUTH35: role gate",str_contains($psB,"hasRole(['admin', 'treasurer', 'loans_officer'])"));
ok("AUTH36: non-write gets notice",str_contains($psB,'No installment schedule is available'));
ok('AUTH37: read path before role check',strpos($psB,'empty($installments)')<(strpos($psB,'hasRole(')?:PHP_INT_MAX));

// ══════════════════════════════════════════════════════════════════════
sec('REG38-REG42: Regression');
eq('REG38: disbursement GL exists',1,(int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_module='loans' AND source_reference_type='disbursement' AND source_reference_id={$ia['id']}")->fetchColumn());
$rep1=$pdo->query("SELECT principal_paid,interest_paid,amount_paid FROM loan_repayments WHERE loan_id={$pid} ORDER BY id LIMIT 1")->fetch();
ok('REG39: interest split proportional',abs((float)$rep1['interest_paid']-round(200000*(400000/1400000),2))<=1.0);
ok('REG39: p+i=amount_paid',abs((float)$rep1['principal_paid']+(float)$rep1['interest_paid']-(float)$rep1['amount_paid'])<0.02);
$dupL=makeLoan($pdo,$lm); $tok=bin2hex(random_bytes(16));
pay($rm,$dupL['id'],100000);
$dThrow=false;
try{
    $rm->recordRepayment(['loan_id'=>$dupL['id'],'member_id'=>1,'amount_paid'=>100000,'payment_date'=>date('Y-m-d'),'payment_method'=>'Cash','received_by'=>1,'payment_type'=>'installment','submission_token'=>$tok,'penalty_paid'=>0]);
    $rm->recordRepayment(['loan_id'=>$dupL['id'],'member_id'=>1,'amount_paid'=>100000,'payment_date'=>date('Y-m-d'),'payment_method'=>'Cash','received_by'=>1,'payment_type'=>'installment','submission_token'=>$tok,'penalty_paid'=>0]);
}catch(Throwable $e){$dThrow=true;}
ok('REG40: dup token rejected',$dThrow);
eq('REG41: all GL balanced',0,(int)$pdo->query("SELECT COUNT(*) FROM (SELECT journal_entry_id,ABS(SUM(debit)-SUM(credit)) d FROM journal_lines GROUP BY journal_entry_id HAVING d>0.01) x")->fetchColumn());
$r42=makeLoan($pdo,$lm,['loan_amount'=>2000000,'interest_amount'=>600000,'monthly_installment'=>round(2600000/6,2),'total_payable'=>2600000,'outstanding'=>2600000,'loan_period_months'=>6]);
ok('REG42: M3 variant disbursed',$r42['err']===null);
eq('REG42: 6 installments',6,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$r42['id']}")->fetchColumn());

// ══════════════════════════════════════════════════════════════════════
sec('COMP: Completion Invariant (Remediation G)');
$cL=makeLoan($pdo,$lm); pay($rm,$cL['id'],1400000,'settlement');
$cF=$lm->find($cL['id']);
eq('COMP: completed','completed',$cF['status']);
eq('COMP: all installed paid',(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$cL['id']}")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$cL['id']} AND status='paid'")->fetchColumn());
$pL2=makeLoan($pdo,$lm); pay($rm,$pL2['id'],700000);
$pA=$lm->find($pL2['id']);
ok('COMP: partial does not complete',$pA['status']!=='completed');
ok('COMP: partial outstanding>0',(float)$pA['outstanding']>0);

// ══════════════════════════════════════════════════════════════════════
sec('GRACE: Grace-Period Option B');
$gMI=round(600000*0.03,2); $gTI=round($gMI*6,2); $gTP=600000+$gTI;
$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,repayment_method,recorded_by,processing_fee,fixed_interest_amount,interest_only_months,principal_recovery_weeks) VALUES(?,1,5,600000,3,?,?,?,?,0,6,1,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 6 MONTH),'approved','monthly','percentage','standard',1,0,0,0,0)")->execute(['TST-GRACE-'.uniqid(),$gTI,round($gMI+600000/5,2),$gTP,$gTP]);
$gId=(int)$pdo->lastInsertId();
$lm->generateInstallments($gId,round($gMI+600000/5,2),6,$disbDate,1,$gMI,600000);
$gR=$pdo->query("SELECT * FROM loan_installments WHERE loan_id={$gId} ORDER BY installment_no")->fetchAll();
eq('GRACE: 6 rows',6,count($gR));
eq('GRACE: inst#1 grace=1',1,(int)$gR[0]['is_grace_period']);
eq('GRACE: inst#1 principal=0',0,(float)$gR[0]['principal_due']);
eq('GRACE: inst#1 interest=18k',$gMI,(float)$gR[0]['interest_due']);
ok('GRACE: recovery rows principal>0',min(array_map(fn($r)=>(float)$r['principal_due'],array_slice($gR,1)))>0);
eq('GRACE: SUM=total_payable',$gTP,round(array_sum(array_column($gR,'amount_due')),2));
$pdo->prepare("UPDATE loans SET status='active' WHERE id=?")->execute([$gId]);
$gRep=$rm->recordRepayment(['loan_id'=>$gId,'member_id'=>1,'amount_paid'=>$gMI,'payment_date'=>date('Y-m-d'),'payment_method'=>'Cash','received_by'=>1,'payment_type'=>'installment','submission_token'=>bin2hex(random_bytes(16)),'penalty_paid'=>0]);
ok('GRACE: payment succeeds',is_int($gRep)&&$gRep>0);
$gRow=$pdo->query("SELECT principal_paid,interest_paid FROM loan_repayments WHERE id={$gRep}")->fetch();
ok('GRACE/OptionB: principal_paid>0 (proportional)',(float)$gRow['principal_paid']>0);
ok('GRACE/OptionB: interest within 1 UGX',abs((float)$gRow['interest_paid']-round($gMI*($gTI/$gTP),2))<=1.0);
echo "  [INFO] Option B confirmed: billing=interest-only; GL recognition=proportional.\n";

// ══════════════════════════════════════════════════════════════════════
sec('CONC/REMEDC: Remediation C — UNIQUE KEY');
$ukB=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{$cloneDb}' AND TABLE_NAME='loan_installments' AND NON_UNIQUE=0 AND INDEX_NAME='uk_loan_installments_loan_seq'")->fetchColumn();
eq('CONC/BEFORE: UK absent',0,$ukB);
$dupB=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT loan_id,installment_no,COUNT(*) c FROM loan_installments GROUP BY loan_id,installment_no HAVING c>1) x")->fetchColumn();
eq('CONC/BEFORE: 0 dups',0,$dupB);

$pdo->exec("ALTER TABLE `loan_installments` ADD CONSTRAINT `uk_loan_installments_loan_seq` UNIQUE KEY (`loan_id`,`installment_no`)");
$ukA=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{$cloneDb}' AND TABLE_NAME='loan_installments' AND NON_UNIQUE=0 AND INDEX_NAME='uk_loan_installments_loan_seq'")->fetchColumn();
ok('CONC/APPLY: UK applied',$ukA>0);

$cN=makeLoan($pdo,$lm); ok('CONC/NORMAL: disburse works',$cN['err']===null);
eq('CONC/NORMAL: 4 rows',4,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$cN['id']}")->fetchColumn());
$lm->generateInstallments($cN['id'],350000,4,$disbDate,0,100000,1000000);
$lm->generateInstallments($cN['id'],350000,4,$disbDate,0,100000,1000000);
eq('CONC/REGEN: 4 rows after double-regen',4,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$cN['id']}")->fetchColumn());
eq('CONC/REGEN: 0 dups',0,(int)$pdo->query("SELECT COUNT(*) FROM (SELECT installment_no,COUNT(*) c FROM loan_installments WHERE loan_id={$cN['id']} GROUP BY installment_no HAVING c>1) x")->fetchColumn());

// Concurrency race
$pdo->prepare("INSERT INTO `loans` (loan_number,member_id,loan_type_id,loan_amount,interest_rate,interest_amount,monthly_installment,total_payable,outstanding,amount_paid,loan_period_months,grace_period_months,issue_date,due_date,status,repayment_frequency,interest_mode,repayment_method,recorded_by,processing_fee,fixed_interest_amount,interest_only_months,principal_recovery_weeks) VALUES(?,1,1,600000,10,240000,210000,840000,840000,0,4,0,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 4 MONTH),'approved','monthly','percentage','standard',1,0,0,0,0)")->execute(['TST-RACE-'.uniqid()]);
$raceId=(int)$pdo->lastInsertId();

$cs=sys_get_temp_dir().DIRECTORY_SEPARATOR."disburse_race_{$raceId}.php";
file_put_contents($cs,'<?php
set_time_limit(20);
define(\'DB_HOST\',\'127.0.0.1\');define(\'DB_PORT\',\'3306\');
define(\'DB_NAME\',\''.addslashes($cloneDb).'\');define(\'DB_CHARSET\',\'utf8mb4\');
define(\'DB_USER\',\'root\');define(\'DB_PASS\',\'\');
define(\'APP_PATH\',\''.addslashes($root).'/app\');
require_once \''.addslashes($root).'/core/Database.php\';
require_once \''.addslashes($root).'/core/Model.php\';
require_once \''.addslashes($root).'/app/models/AccountModel.php\';
require_once \''.addslashes($root).'/app/models/JournalEntryModel.php\';
require_once \''.addslashes($root).'/app/models/JournalLineModel.php\';
require_once \''.addslashes($root).'/app/models/LoanProductModel.php\';
require_once \''.addslashes($root).'/app/services/JournalService.php\';
require_once \''.addslashes($root).'/app/models/LoanModel.php\';
try{$r=(new LoanModel())->disburse('.$raceId.',1,\'Cash\');echo \'OK:\'.$r[\'entry_number\'];}
catch(Throwable $e){echo \'FAIL:\'.$e->getMessage();}
');
$d=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$p1=proc_open('"'.$phpExe.'" "'.$cs.'"',$d,$pi1);
usleep(40000);
$p2=proc_open('"'.$phpExe.'" "'.$cs.'"',$d,$pi2);
$o1=trim(stream_get_contents($pi1[1])); $o2=trim(stream_get_contents($pi2[1]));
fclose($pi1[1]);fclose($pi2[1]);proc_close($p1);proc_close($p2);
@unlink($cs);
echo "  Race P1: {$o1}\n  Race P2: {$o2}\n";
$wins=(int)str_starts_with($o1,'OK')+(int)str_starts_with($o2,'OK');
$fls=(int)str_starts_with($o1,'FAIL')+(int)str_starts_with($o2,'FAIL');
eq('CONC/RACE: 1 succeeded',1,$wins);
eq('CONC/RACE: 1 failed',1,$fls);
eq('CONC/RACE: 4 rows after race',4,(int)$pdo->query("SELECT COUNT(*) FROM loan_installments WHERE loan_id={$raceId}")->fetchColumn());
eq('CONC/RACE: 0 dups',0,(int)$pdo->query("SELECT COUNT(*) FROM (SELECT installment_no,COUNT(*) c FROM loan_installments WHERE loan_id={$raceId} GROUP BY installment_no HAVING c>1) x")->fetchColumn());

// ══════════════════════════════════════════════════════════════════════
sec('PROD: Production Read-Only Verification');
$prodPdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=empower_db;charset=utf8mb4','root','',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
eq('PROD: loans=0',0,(int)$prodPdo->query('SELECT COUNT(*) FROM loans')->fetchColumn());
eq('PROD: loan_installments=0',0,(int)$prodPdo->query('SELECT COUNT(*) FROM loan_installments')->fetchColumn());
eq('PROD: loan_repayments=0',0,(int)$prodPdo->query('SELECT COUNT(*) FROM loan_repayments')->fetchColumn());
eq('PROD: UK absent from production',0,(int)$prodPdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='empower_db' AND TABLE_NAME='loan_installments' AND NON_UNIQUE=0 AND INDEX_NAME='uk_loan_installments_loan_seq'")->fetchColumn());
eq('PROD: 0 dups (loan_id,installment_no)',0,(int)$prodPdo->query("SELECT COUNT(*) FROM (SELECT loan_id,installment_no,COUNT(*) c FROM loan_installments GROUP BY loan_id,installment_no HAVING c>1) x")->fetchColumn());
eq('PROD: all GL balanced',0,(int)$prodPdo->query("SELECT COUNT(*) FROM (SELECT journal_entry_id,ABS(SUM(debit)-SUM(credit)) d FROM journal_lines GROUP BY journal_entry_id HAVING d>0.01) x")->fetchColumn());
unset($prodPdo);

// ─── Summary ──────────────────────────────────────────────────────────────────
sec('FINAL SUMMARY');
echo "\n  Total:  ".($passed+$failed)."\n  PASSED: {$passed}\n  FAILED: {$failed}\n";
file_put_contents($root.'/tests/.loan_schedule_rem_results.json',
    json_encode(['clone'=>$cloneDb,'passed'=>$passed,'failed'=>$failed,'total'=>$passed+$failed,
        'results'=>$allResults,'timestamp'=>date('Y-m-d H:i:s')],JSON_PRETTY_PRINT));

// Drop clone
unset($pdo);
(new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]))->exec("DROP DATABASE IF EXISTS `{$cloneDb}`");
echo "\nClone {$cloneDb} dropped.\n";
echo "\n".($failed===0?"ALL TESTS PASSED":"FAILURES — see above")."\n";
exit($failed>0?1:0);
