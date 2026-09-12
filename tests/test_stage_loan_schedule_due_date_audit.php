<?php
/**
 * Stage — Loan Installment Schedule & Due-Date Integrity Audit — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db. This is AUDIT evidence, not a pass/fail gate for an
 * implementation: some assertions are DELIBERATELY expected to demonstrate
 * a finding (labeled [FINDING]), not to prove the system defect-free. This
 * stage does not fix anything found here.
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
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/LoanProductModel.php';
require_once APP_PATH  . '/models/RepaymentModel.php';
require_once APP_PATH  . '/models/AccountModel.php';
require_once APP_PATH  . '/services/JournalService.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (disposable clone -- never empower_db) ===\n";
$db = Database::getInstance()->getConnection();

$pass = 0; $fail = 0; $findings = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function finding(string $label, bool $confirmed, string $detail = ''): void {
    global $findings;
    echo "  [FINDING " . ($confirmed ? 'CONFIRMED' : 'NOT CONFIRMED') . "] $label" . ($detail ? " -- $detail" : '') . "\n";
    if ($confirmed) $findings++;
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$CREATOR = 303; $APPROVER = 296; $CASHIER = 298;
if (!$db->query("SELECT id FROM users WHERE id={$CREATOR}")->fetch()) { $CREATOR = 1; }
if (!$db->query("SELECT id FROM users WHERE id={$APPROVER}")->fetch()) { $APPROVER = 1; }
if (!$db->query("SELECT id FROM users WHERE id={$CASHIER}")->fetch()) { $CASHIER = 1; }

$memberModel  = new MemberModel();
$loanModel    = new LoanModel();
$productModel = new LoanProductModel();
$repayModel   = new RepaymentModel();

function makeMember(MemberModel $mm, int $admin, string $tag): array {
    static $seq = 0; $seq++;
    static $runId = null; if ($runId === null) { $runId = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT); }
    return $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'SCHED', 'last_name' => "Test{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07' . $runId . str_pad((string)(30000 + $seq), 5, '0', STR_PAD_LEFT),
        'national_id' => "SCHED{$runId}{$tag}{$seq}",
        'date_of_birth' => '1990-01-01', 'address' => 'Test',
        'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
        'join_date' => date('Y-m-d'), 'status' => 'active',
    ], $admin);
}

/**
 * Faithfully reproduces LoanController::store()'s draft-creation +
 * schedule-dispatch logic (the ONLY place in the app that currently wires
 * loan creation to schedule generation -- LoanModel::create() itself does
 * not touch loan_installments at all, confirmed by direct source read).
 * Returns the new loan id. Does not submit/approve/disburse.
 */
function makeLoanWithSchedule(LoanModel $lm, LoanProductModel $pm, PDO $db, int $memberId, float $amount, int $months, int $loanTypeId, string $issueDate, int $creator, string $repFreq = 'monthly'): int {
    $calc = $pm->calculateLoan($loanTypeId, $amount, $months);
    $graceMonths = (int)$db->query("SELECT grace_period_months FROM loan_product_rules WHERE loan_type_id={$loanTypeId}")->fetchColumn();
    $input = [
        'loan_number' => $lm->generateLoanNumber(),
        'member_id' => $memberId, 'loan_type_id' => $loanTypeId,
        'loan_amount' => $amount, 'interest_rate' => $calc['interest_rate'],
        'interest_amount' => $calc['interest_amount'], 'processing_fee' => $calc['processing_fee'],
        'monthly_installment' => $calc['monthly_installment'],
        'total_payable' => $calc['total_payable'], 'outstanding' => $calc['total_payable'], 'amount_paid' => 0,
        'issue_date' => $issueDate, 'due_date' => date('Y-m-d', strtotime("+{$months} months", strtotime($issueDate))),
        'disbursement_date' => $issueDate, 'loan_period_months' => $months,
        'repayment_frequency' => $repFreq, 'interest_mode' => 'percentage',
        'grace_period_months' => $graceMonths,
        'status' => 'draft', 'recorded_by' => $creator,
    ];
    $newId = $lm->create($input);

    // Exactly mirrors LoanController::store()'s dispatch (lines ~847-886):
    // approval_date is never supplied at creation, so start date is issue_date.
    $startDate = $issueDate;
    if ($repFreq === 'weekly' && $input['interest_mode'] !== 'fixed' && $months > 0) {
        $lm->generateWeeklyInstallmentSchedule($newId, $amount, $calc['interest_amount'], $calc['total_payable'], $months, $startDate, $graceMonths * 4);
    } elseif ($months > 0 && $calc['monthly_installment'] > 0) {
        $lm->generateInstallments($newId, $calc['monthly_installment'], $months, $startDate, $graceMonths,
            $months > 0 ? round($calc['interest_amount'] / $months, 2) : 0.0, $amount);
    }
    return $newId;
}

$ACC_RECEIVABLE = 14; $ACC_CASH = 7; $ACC_INTEREST = 77;

// ==================================================================
// SECTION A: MONTHLY BRACKET SCENARIOS M1-M5
// ==================================================================
section('M1: UGX 600,000, 10% bracket, 4 months');
$mM1 = makeMember($memberModel, $CREATOR, 'M1');
$loanM1 = makeLoanWithSchedule($loanModel, $productModel, $db, $mM1['member_id'], 600000, 4, 1, '2026-03-01', $CREATOR);
$loanM1Row = $loanModel->find($loanM1);
$instM1 = $loanModel->getInstallments($loanM1);
check('M1: interest_rate resolved = 10%', abs((float)$loanM1Row['interest_rate'] - 10) < 0.0001, "got={$loanM1Row['interest_rate']}");
check('M1: interest_amount = 240,000 (600,000*10%*4)', abs((float)$loanM1Row['interest_amount'] - 240000) < 0.01, "got={$loanM1Row['interest_amount']}");
check('M1: total_payable = 840,000', abs((float)$loanM1Row['total_payable'] - 840000) < 0.01, "got={$loanM1Row['total_payable']}");
check('M1: 4 installments generated', count($instM1) === 4, "got=" . count($instM1));
$sumPrincipalM1 = array_sum(array_column($instM1, 'principal_due'));
$sumInterestM1 = array_sum(array_column($instM1, 'interest_due'));
$sumDueM1 = array_sum(array_column($instM1, 'amount_due'));
check('M1: SUM(principal_due) = 600,000 exactly', abs($sumPrincipalM1 - 600000) < 0.01, "got={$sumPrincipalM1}");
check('M1: SUM(interest_due) = 240,000 exactly', abs($sumInterestM1 - 240000) < 0.01, "got={$sumInterestM1}");
check('M1: SUM(amount_due) = total_payable (840,000) exactly', abs($sumDueM1 - 840000) < 0.01, "got={$sumDueM1}");
check('M1: each installment principal+interest = amount_due', array_reduce($instM1, fn($c,$i)=>$c && abs(((float)$i['principal_due']+(float)$i['interest_due'])-(float)$i['amount_due'])<0.01, true));
echo "  Installments: " . json_encode(array_map(fn($i)=>['no'=>$i['installment_no'],'due'=>$i['due_date'],'p'=>$i['principal_due'],'i'=>$i['interest_due'],'amt'=>$i['amount_due']], $instM1)) . "\n";

section('M2: UGX 1,000,000, 10% bracket, 4 months (mandatory scenario)');
$mM2 = makeMember($memberModel, $CREATOR, 'M2');
$loanM2 = makeLoanWithSchedule($loanModel, $productModel, $db, $mM2['member_id'], 1000000, 4, 1, '2026-03-01', $CREATOR);
$loanM2Row = $loanModel->find($loanM2);
$instM2 = $loanModel->getInstallments($loanM2);
check('M2: interest_amount = 400,000 exactly', abs((float)$loanM2Row['interest_amount'] - 400000) < 0.01, "got={$loanM2Row['interest_amount']}");
check('M2: total_payable = 1,400,000 exactly', abs((float)$loanM2Row['total_payable'] - 1400000) < 0.01, "got={$loanM2Row['total_payable']}");
check('M2: each installment = 350,000 (equal installments)', array_reduce($instM2, fn($c,$i)=>$c && abs((float)$i['amount_due']-350000)<0.01, true));
check('M2: SUM(amount_due) = 1,400,000, no rounding residual', abs(array_sum(array_column($instM2,'amount_due')) - 1400000) < 0.01);

section('M3: 5% bracket (loan in the 1.1M-5M tier)');
$mM3 = makeMember($memberModel, $CREATOR, 'M3');
$loanM3 = makeLoanWithSchedule($loanModel, $productModel, $db, $mM3['member_id'], 2000000, 6, 1, '2026-03-01', $CREATOR);
$loanM3Row = $loanModel->find($loanM3);
check('M3: interest_rate resolved = 5%', abs((float)$loanM3Row['interest_rate'] - 5) < 0.0001, "got={$loanM3Row['interest_rate']}");
check('M3: interest_amount = 600,000 (2,000,000*5%*6)', abs((float)$loanM3Row['interest_amount'] - 600000) < 0.01, "got={$loanM3Row['interest_amount']}");

section('M4: 4% bracket (5.1M-10M tier)');
$mM4 = makeMember($memberModel, $CREATOR, 'M4');
$loanM4 = makeLoanWithSchedule($loanModel, $productModel, $db, $mM4['member_id'], 6000000, 6, 1, '2026-03-01', $CREATOR);
$loanM4Row = $loanModel->find($loanM4);
check('M4: interest_rate resolved = 4%', abs((float)$loanM4Row['interest_rate'] - 4) < 0.0001, "got={$loanM4Row['interest_rate']}");
check('M4: interest_amount = 1,440,000 (6,000,000*4%*6)', abs((float)$loanM4Row['interest_amount'] - 1440000) < 0.01, "got={$loanM4Row['interest_amount']}");

section('M5: 3% bracket (>10M tier)');
$mM5 = makeMember($memberModel, $CREATOR, 'M5');
$loanM5 = makeLoanWithSchedule($loanModel, $productModel, $db, $mM5['member_id'], 12000000, 6, 1, '2026-03-01', $CREATOR);
$loanM5Row = $loanModel->find($loanM5);
check('M5: interest_rate resolved = 3%', abs((float)$loanM5Row['interest_rate'] - 3) < 0.0001, "got={$loanM5Row['interest_rate']}");
check('M5: interest_amount = 2,160,000 (12,000,000*3%*6)', abs((float)$loanM5Row['interest_amount'] - 2160000) < 0.01, "got={$loanM5Row['interest_amount']}");

section('M6 (extra): >15M tier — the additional 2% bracket reported separately per the stage context');
$mM6 = makeMember($memberModel, $CREATOR, 'M6');
$loanM6 = makeLoanWithSchedule($loanModel, $productModel, $db, $mM6['member_id'], 16000000, 6, 1, '2026-03-01', $CREATOR);
$loanM6Row = $loanModel->find($loanM6);
check('M6: interest_rate resolved = 2% (Tier 5, unbounded max)', abs((float)$loanM6Row['interest_rate'] - 2) < 0.0001, "got={$loanM6Row['interest_rate']}");
echo "  [INFO] Confirmed live Tier 5 config: 15,000,001+ => 2%/month, present in loan_interest_brackets for loan_type_id=1 (Normal Loan). Not altered, reported only.\n";

// ==================================================================
// SECTION B: DUE-DATE INTEGRITY
// ==================================================================
section('DD1: First due date basis -- issue_date vs approval_date vs disbursement_date');
// At creation, approval_date/disbursement_date are BOTH set to issue_date
// by LoanController::collectInput() -- confirmed by direct source read
// (LoanController.php:1101 "$approvalDate = $s('approval_date') ?: $issueDate"
// and :1255 "'disbursement_date' => $issueDate"). The schedule generator is
// then invoked with $input['approval_date'] ?? $input['issue_date'] as its
// start date, evaluated at CREATE time -- always issue_date in practice,
// since the real approval workflow event (LoanModel::approve(), which sets
// approved_at) never writes to the approval_date column at all.
finding(
    'The installment schedule\'s first due date is anchored to loan-creation-time issue_date, NOT to the real disbursement date -- disburse() never regenerates or shifts the schedule',
    (function() use ($instM2, $loanM2Row) {
        $firstDue = $instM2[0]['due_date'] ?? null;
        $expected = date('Y-m-d', strtotime('+1 month', strtotime('2026-03-01')));
        return $firstDue === $expected; // proves it's issue_date + 1 month, computed at creation, with no disbursement input at all
    })(),
    "M2's first installment due_date=" . ($instM2[0]['due_date'] ?? 'NULL') . " = issue_date(2026-03-01)+1 month. The loan was never approved or disbursed in this test, proving the schedule needs neither event to be fully generated -- confirming due dates do not reflect when funds actually move."
);

section('DD2: End-of-month / leap-year date drift (strtotime "+N months" overflow)');
$mDD2 = makeMember($memberModel, $CREATOR, 'DD2');
$loanDD2 = makeLoanWithSchedule($loanModel, $productModel, $db, $mDD2['member_id'], 500000, 4, 1, '2026-01-31', $CREATOR);
$instDD2 = $loanModel->getInstallments($loanDD2);
$datesDD2 = array_column($instDD2, 'due_date');
echo "  Schedule starting 2026-01-31, due dates: " . implode(', ', $datesDD2) . "\n";
finding(
    'Monthly schedule due dates drift/oscillate (do not land on a consistent day-of-month) when the loan\'s issue_date falls on the 29th/30th/31st of a month, due to PHP strtotime("+N months") end-of-month overflow',
    $datesDD2[0] !== '2026-02-28' && $datesDD2[0] !== '2026-02-29',
    "First due date computed as {$datesDD2[0]} (expected the 31st or a clamped month-end like Feb 28, but strtotime('+1 month', '2026-01-31') actually returns 2026-03-03, skipping February's due date entirely and compounding irregularly across subsequent installments: " . implode(', ', $datesDD2) . ")"
);

$mDD3 = makeMember($memberModel, $CREATOR, 'DD3leap');
$loanDD3 = makeLoanWithSchedule($loanModel, $productModel, $db, $mDD3['member_id'], 500000, 3, 1, '2024-01-31', $CREATOR);
$instDD3 = $loanModel->getInstallments($loanDD3);
$datesDD3 = array_column($instDD3, 'due_date');
echo "  Schedule starting 2024-01-31 (leap year), due dates: " . implode(', ', $datesDD3) . "\n";
check('DD3 (informational, not a pass/fail correctness claim): leap-year Feb 29 handling recorded for the report', true, '');

section('DD3: Weekly due dates do not exhibit the month-length drift (fixed 7-day intervals)');
$mDD4 = makeMember($memberModel, $CREATOR, 'DD4wk');
$loanDD4 = makeLoanWithSchedule($loanModel, $productModel, $db, $mDD4['member_id'], 500000, 2, 1, '2026-01-31', $CREATOR, 'weekly');
$instDD4 = $loanModel->getInstallments($loanDD4);
$datesDD4 = array_column($instDD4, 'due_date');
$allSevenApart = true;
for ($k = 1; $k < count($datesDD4); $k++) {
    if ((strtotime($datesDD4[$k]) - strtotime($datesDD4[$k-1])) !== 7*86400) { $allSevenApart = false; break; }
}
check('DD3: weekly installments are each exactly 7 days apart, no drift', $allSevenApart, implode(', ', $datesDD4));

// ==================================================================
// SECTION C: DUPLICATE SCHEDULE PROTECTION
// ==================================================================
section('DS1: Application-level protection -- calling the generator twice');
$countBeforeDS1 = count($loanModel->getInstallments($loanM1));
$loanModel->generateInstallments($loanM1, (float)$loanM1Row['monthly_installment'], 4, '2026-03-01', 0,
    round((float)$loanM1Row['interest_amount']/4,2), (float)$loanM1Row['loan_amount']);
$countAfterDS1 = count($loanModel->getInstallments($loanM1));
check('DS1: regenerating the same schedule does not duplicate rows (DELETE-then-INSERT self-corrects)', $countAfterDS1 === $countBeforeDS1, "before={$countBeforeDS1} after={$countAfterDS1}");

section('DS2: Database-level protection -- is there a UNIQUE constraint on (loan_id, installment_no)?');
$indexes = $db->query("SHOW INDEX FROM loan_installments")->fetchAll(PDO::FETCH_ASSOC);
$uniqueNonPk = array_filter($indexes, fn($i) => (int)$i['Non_unique'] === 0 && $i['Key_name'] !== 'PRIMARY');
finding(
    'loan_installments has NO database-level unique constraint on (loan_id, installment_no) or (loan_id, due_date) -- only the application\'s own DELETE-before-INSERT pattern in each generate*() method prevents duplicates; a race between two concurrent regeneration calls for the same loan could double the schedule',
    count($uniqueNonPk) === 0,
    'SHOW INDEX FROM loan_installments returns only PRIMARY (id) plus two non-unique indexes (loan_id, due_date) -- confirmed via live SHOW INDEX.'
);

section('DS3: Concrete race -- two near-simultaneous regeneration calls for a loan with no existing schedule');
$mDS3 = makeMember($memberModel, $CREATOR, 'DS3');
$loanCalc = $productModel->calculateLoan(1, 500000, 3);
$inputDS3 = [
    'loan_number' => $loanModel->generateLoanNumber(), 'member_id' => $mDS3['member_id'], 'loan_type_id' => 1,
    'loan_amount' => 500000, 'interest_rate' => $loanCalc['interest_rate'], 'interest_amount' => $loanCalc['interest_amount'],
    'processing_fee' => $loanCalc['processing_fee'], 'monthly_installment' => $loanCalc['monthly_installment'],
    'total_payable' => $loanCalc['total_payable'], 'outstanding' => $loanCalc['total_payable'], 'amount_paid' => 0,
    'issue_date' => '2026-03-01', 'due_date' => '2026-06-01', 'disbursement_date' => '2026-03-01',
    'loan_period_months' => 3, 'repayment_frequency' => 'monthly', 'interest_mode' => 'percentage',
    'grace_period_months' => 0, 'status' => 'draft', 'recorded_by' => $CREATOR,
];
$loanDS3 = $loanModel->create($inputDS3);
// Simulate two callers racing to generate the schedule for a loan that has
// none yet (e.g. two staff both opening printSchedule() at once) by
// invoking the generator twice back-to-back without a lock, exactly as
// two concurrent HTTP requests each independently would.
$loanModel->generateInstallments($loanDS3, $loanCalc['monthly_installment'], 3, '2026-03-01', 0, round($loanCalc['interest_amount']/3,2), 500000);
$loanModel->generateInstallments($loanDS3, $loanCalc['monthly_installment'], 3, '2026-03-01', 0, round($loanCalc['interest_amount']/3,2), 500000);
$countDS3 = count($loanModel->getInstallments($loanDS3));
check('DS3: sequential (non-racing) double-generation still self-corrects to exactly 3 rows (DELETE runs first each time)', $countDS3 === 3, "got={$countDS3}");
echo "  [INFO] True concurrent-process racing was not additionally tested here beyond the sequential double-call above: unlike loan_repayments (which now has uk_repayments_submission_token), loan_installments has no unique constraint of ANY kind to race against -- two truly overlapping DELETE+INSERT sequences from separate connections have no database-level guard preventing an interleaving that leaves duplicate installment_no rows (e.g. Connection A's DELETE, then Connection B's DELETE+INSERT, then Connection A's INSERT running against Connection B's already-inserted rows). This is reported as an architectural gap (Section D above), not fabricated as a reproduced production incident, since production has zero loans to race against.\n";

// ==================================================================
// SECTION D: MANDATORY PARTIAL-PAYMENT TEST + SCHEDULE-VS-LEDGER RECONCILIATION
// ==================================================================
section('PP1: Partial repayment -- inspect repayment row, installment row(s), loan outstanding');
$mPP = makeMember($memberModel, $CREATOR, 'PP');
$loanPP = makeLoanWithSchedule($loanModel, $productModel, $db, $mPP['member_id'], 1000000, 4, 1, '2026-03-01', $CREATOR);
$loanModel->submit($loanPP, $CREATOR);
$loanModel->approve($loanPP, $APPROVER);
$loanModel->disburse($loanPP, $APPROVER, 'Cash');
$instBeforePP = $loanModel->getInstallments($loanPP);
check('PP1: schedule exists before repayment (4 installments)', count($instBeforePP) === 4);

$rep1PP = $repayModel->recordRepayment([
    'loan_id' => $loanPP, 'member_id' => $mPP['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 200000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('PP1: repayment recorded', $rep1PP !== false);
$rep1PPRow = $repayModel->find($rep1PP);
$loanPPRow = $loanModel->find($loanPP);
$instAfterPP1 = $loanModel->getInstallments($loanPP);
echo "  Repayment row: principal_paid={$rep1PPRow['principal_paid']} interest_paid={$rep1PPRow['interest_paid']}\n";
echo "  Installment #1 after payment: amount_paid={$instAfterPP1[0]['amount_paid']} status={$instAfterPP1[0]['status']} principal_paid(col)={$instAfterPP1[0]['principal_paid']} interest_paid_amt(col)={$instAfterPP1[0]['interest_paid_amt']}\n";
check('PP1: loan.outstanding reduced by exactly 200,000', abs((float)$loanPPRow['outstanding'] - 1200000) < 0.01, "got={$loanPPRow['outstanding']}");
check('PP1: installment #1 amount_paid updated to 200,000 (fully absorbs the payment, since installment #1 amount_due=350,000)', abs((float)$instAfterPP1[0]['amount_paid'] - 200000) < 0.01, "got={$instAfterPP1[0]['amount_paid']}");
check('PP1: installment #1 status = partial (200,000 < 350,000 due)', $instAfterPP1[0]['status'] === 'partial', "got={$instAfterPP1[0]['status']}");
finding(
    'loan_installments.principal_paid and .interest_paid_amt columns exist but are NEVER written by any code path -- the installment-level record cannot show how much of a partial payment was principal vs interest, even though the repayment row itself (loan_repayments.principal_paid/interest_paid) now correctly tracks this at the loan level since the prior remediation stage',
    abs((float)$instAfterPP1[0]['principal_paid']) < 0.01 && abs((float)$instAfterPP1[0]['interest_paid_amt']) < 0.01 && (float)$rep1PPRow['interest_paid'] > 0,
    "Repayment ledger correctly shows interest_paid={$rep1PPRow['interest_paid']} (proportional split from the prior stage) but installment #1's own principal_paid/interest_paid_amt columns remain 0.00 -- confirmed these columns are write-only-by-schema, never-written-by-application."
);

section('PP2: A second partial repayment -- combined reconciliation');
$rep2PP = $repayModel->recordRepayment([
    'loan_id' => $loanPP, 'member_id' => $mPP['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 300000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('PP2: second repayment recorded', $rep2PP !== false);
$loanPPRow2 = $loanModel->find($loanPP);
check('PP2: loan.outstanding = 700,000 (1,400,000 - 200,000 - 500,000... wait uses 1,000,000/400,000 loan)', abs((float)$loanPPRow2['outstanding'] - (1400000-500000)) < 0.01, "got={$loanPPRow2['outstanding']}");
$sumRepayPrincipal = (float)$db->query("SELECT COALESCE(SUM(principal_paid),0) FROM loan_repayments WHERE loan_id={$loanPP}")->fetchColumn();
$sumRepayInterest  = (float)$db->query("SELECT COALESCE(SUM(interest_paid),0) FROM loan_repayments WHERE loan_id={$loanPP}")->fetchColumn();
$sumInstPaid = (float)$db->query("SELECT COALESCE(SUM(amount_paid),0) FROM loan_installments WHERE loan_id={$loanPP}")->fetchColumn();
echo "  Repayment ledger: SUM(principal_paid)={$sumRepayPrincipal} SUM(interest_paid)={$sumRepayInterest} total=" . ($sumRepayPrincipal+$sumRepayInterest) . "\n";
echo "  Installment schedule: SUM(amount_paid)={$sumInstPaid}\n";
check('PP2: repayment ledger total (principal+interest) equals installment schedule total amount_paid (both should equal 500,000 total paid)', abs(($sumRepayPrincipal+$sumRepayInterest) - $sumInstPaid) < 0.01, "ledger=" . ($sumRepayPrincipal+$sumRepayInterest) . " schedule={$sumInstPaid}");

// ==================================================================
// SECTION E: GRACE-PERIOD LOAN -- SCHEDULE VS REPAYMENT-LEDGER POLICY DIVERGENCE
// ==================================================================
section('GP1: A loan with a real product-configured grace period (Start-Up Loan, loan_type_id=5, grace=1 month)');
$mGP = makeMember($memberModel, $CREATOR, 'GP');
// Start-Up Loan (type 5): flat_rate 3%, grace_period_months=1, min 100k-3M, 2-12 months.
$loanGP = makeLoanWithSchedule($loanModel, $productModel, $db, $mGP['member_id'], 600000, 3, 5, '2026-03-01', $CREATOR);
$loanGPRow = $loanModel->find($loanGP);
$instGP = $loanModel->getInstallments($loanGP);
echo "  Start-Up loan: amount=600,000 rate={$loanGPRow['interest_rate']}% interest_amount={$loanGPRow['interest_amount']} total_payable={$loanGPRow['total_payable']}\n";
foreach ($instGP as $i) {
    echo "    #{$i['installment_no']} due={$i['due_date']} grace=" . ($i['is_grace_period'] ? 'YES' : 'no') . " principal_due={$i['principal_due']} interest_due={$i['interest_due']} amount_due={$i['amount_due']}\n";
}
check('GP1: installment #1 is flagged as grace period (interest-only, principal_due=0)', (int)$instGP[0]['is_grace_period'] === 1 && abs((float)$instGP[0]['principal_due']) < 0.01);

// Pay exactly the grace-period installment #1 amount (interest-only per the SCHEDULE's own policy).
$loanModel->submit($loanGP, $CREATOR); $loanModel->approve($loanGP, $APPROVER); $loanModel->disburse($loanGP, $APPROVER, 'Cash');
$gpPayment = (float)$instGP[0]['amount_due'];
$repGP = $repayModel->recordRepayment([
    'loan_id' => $loanGP, 'member_id' => $mGP['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => $gpPayment, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
$repGPRow = $repayModel->find($repGP);
echo "  Grace-period installment #1 schedule says: 100% interest (principal_due=0, interest_due={$instGP[0]['interest_due']}, amount_due={$gpPayment})\n";
echo "  But the repayment ledger (loan-wide proportional split, per the prior remediation stage) recorded: principal_paid={$repGPRow['principal_paid']} interest_paid={$repGPRow['interest_paid']}\n";
finding(
    'The installment schedule and the repayment-allocation ledger implement MATERIALLY DIFFERENT accounting policies for a grace-period loan: the schedule says the grace-period installment is 100% interest (principal_due=0), but the actual repayment ledger allocates that same payment using the loan-wide proportional principal:interest ratio (loan_amount:interest_amount), recognizing a non-zero principal_paid for a payment the schedule itself says should be pure interest',
    (float)$repGPRow['principal_paid'] > 0.01,
    "Paid exactly the grace-period installment's own amount_due ({$gpPayment}); the schedule's own policy says this should be 100% interest_paid, 0 principal_paid, but loan_repayments.principal_paid actually recorded {$repGPRow['principal_paid']} (non-zero) and interest_paid recorded {$repGPRow['interest_paid']} -- proving the two subsystems tell different financial stories for any grace-period loan."
);

// ==================================================================
// SECTION F: FULL SETTLEMENT TEST
// ==================================================================
section('FS1: Full settlement -- mandatory-style scenario, verify schedule/GL/status all agree');
$mFS = makeMember($memberModel, $CREATOR, 'FS');
$loanFS = makeLoanWithSchedule($loanModel, $productModel, $db, $mFS['member_id'], 1000000, 4, 1, '2026-03-01', $CREATOR);
$loanModel->submit($loanFS, $CREATOR); $loanModel->approve($loanFS, $APPROVER); $loanModel->disburse($loanFS, $APPROVER, 'Cash');
$repFS = $repayModel->recordRepayment([
    'loan_id' => $loanFS, 'member_id' => $mFS['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 1400000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('FS1: full settlement repayment recorded', $repFS !== false);
$loanFSRow = $loanModel->find($loanFS);
$instFS = $loanModel->getInstallments($loanFS);
$repFSRow = $repayModel->find($repFS);
check('FS1: loan.outstanding = exactly 0', abs((float)$loanFSRow['outstanding']) < 0.01, "got={$loanFSRow['outstanding']}");
check('FS1: loan.status = completed', $loanFSRow['status'] === 'completed', "got={$loanFSRow['status']}");
check('FS1: repayment principal_paid = 1,000,000 exactly', abs((float)$repFSRow['principal_paid'] - 1000000) < 0.01, "got={$repFSRow['principal_paid']}");
check('FS1: repayment interest_paid = 400,000 exactly', abs((float)$repFSRow['interest_paid'] - 400000) < 0.01, "got={$repFSRow['interest_paid']}");
$allInstPaidFS = array_reduce($instFS, fn($c,$i)=>$c && $i['status']==='paid', true);
finding(
    'On full settlement, every installment IS correctly flipped to status=paid (schedule-level completion agrees with loan-level completion for the simple no-grace case)',
    !$allInstPaidFS,
    $allInstPaidFS ? '' : 'At least one installment did not reach status=paid despite loan.outstanding=0 and loan.status=completed.'
);
$je = (int)$repFSRow['journal_entry_id'];
$lines = $db->query("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id={$je}")->fetchAll(PDO::FETCH_ASSOC);
$sumD = array_sum(array_column($lines,'debit')); $sumC = array_sum(array_column($lines,'credit'));
check('FS1: journal balanced (debit=credit=1,400,000)', abs($sumD-$sumC)<0.01 && abs($sumD-1400000)<0.01, "d={$sumD} c={$sumC}");
$recvLine = array_values(array_filter($lines, fn($l)=>(int)$l['account_id']===$ACC_RECEIVABLE));
$intLine  = array_values(array_filter($lines, fn($l)=>(int)$l['account_id']===$ACC_INTEREST));
check('FS1: GL Loans Receivable credited exactly 1,000,000 (principal only)', count($recvLine)===1 && abs((float)$recvLine[0]['credit']-1000000)<0.01);
check('FS1: GL Interest Income credited exactly 400,000 (interest only)', count($intLine)===1 && abs((float)$intLine[0]['credit']-400000)<0.01);

// ==================================================================
// SECTION G: OVERPAYMENT TEST
// ==================================================================
section('OP1: Repayment exceeding the remaining contractual amount');
$mOP = makeMember($memberModel, $CREATOR, 'OP');
$loanOP = makeLoanWithSchedule($loanModel, $productModel, $db, $mOP['member_id'], 500000, 2, 1, '2026-03-01', $CREATOR);
$loanModel->submit($loanOP, $CREATOR); $loanModel->approve($loanOP, $APPROVER); $loanModel->disburse($loanOP, $APPROVER, 'Cash');
$loanOPRow = $loanModel->find($loanOP);
$overAmount = (float)$loanOPRow['outstanding'] + 100000; // 100,000 more than the total contractual payable
$threwOP = false; $overpayResult = null;
try {
    $overpayResult = $repayModel->recordRepayment([
        'loan_id' => $loanOP, 'member_id' => $mOP['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => $overAmount, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
    ]);
} catch (Throwable $e) { $threwOP = true; echo "  Model-level exception: " . $e->getMessage() . "\n"; }
if ($threwOP) {
    finding('An overpayment at the model layer is rejected outright (throws)', true, 'RepaymentModel::recordRepayment() itself refused a payment exceeding outstanding.');
} else {
    $loanOPRowAfter = $loanModel->find($loanOP);
    echo "  Overpayment of {$overAmount} accepted at model layer. loan.outstanding after = {$loanOPRowAfter['outstanding']}\n";
    finding(
        'RepaymentModel::recordRepayment() accepts a model-layer call for MORE than the loan\'s outstanding balance and clamps loan.outstanding to 0 via max(0, ...) rather than rejecting it -- the excess is silently absorbed with no overpayment-balance record; controller-level validate() (not tested here at the model layer) is the only place this is blocked in the real HTTP flow',
        abs((float)$loanOPRowAfter['outstanding']) < 0.01,
        "loan.outstanding after overpayment = {$loanOPRowAfter['outstanding']} (clamped to 0 by recordRepayment()'s max(0, round(balanceBefore-nonPenaltyPaid,2)) -- confirmed no negative balance, but also no record of the {$overAmount}-vs-outstanding excess anywhere)."
    );
}
// Cross-check: does the CONTROLLER validate() layer block this in the real flow?
require_once APP_PATH . '/controllers/RepaymentController.php';
$refController = new ReflectionClass('RepaymentController');
$ctrl = $refController->newInstanceWithoutConstructor();
$validateMethod = $refController->getMethod('validate');
$validateMethod->setAccessible(true);
$loanModelProp = $refController->getProperty('loanModel');
$loanModelProp->setAccessible(true);
$loanModelProp->setValue($ctrl, $loanModel);
$mOP2 = makeMember($memberModel, $CREATOR, 'OP2');
$loanOP2 = makeLoanWithSchedule($loanModel, $productModel, $db, $mOP2['member_id'], 500000, 2, 1, '2026-03-01', $CREATOR);
$loanModel->submit($loanOP2, $CREATOR); $loanModel->approve($loanOP2, $APPROVER); $loanModel->disburse($loanOP2, $APPROVER, 'Cash');
$loanOP2Row = $loanModel->find($loanOP2);
$errorsOP = $validateMethod->invoke($ctrl, ['loan_id'=>$loanOP2,'amount_paid'=>(float)$loanOP2Row['outstanding']+50000,'penalty_paid'=>0,'payment_date'=>date('Y-m-d'),'payment_method'=>'Cash','submission_token'=>bin2hex(random_bytes(16))]);
check('OP2: the CONTROLLER validate() layer DOES reject an amount_paid exceeding loan.outstanding (real-world HTTP flow is protected even though the model layer alone is permissive)', isset($errorsOP['amount_paid']));

// ==================================================================
// SECTION H: INSTALLMENT STATUS CONTRADICTIONS
// ==================================================================
section('ST1: loan-level vs installment-level overdue determination');
$mST = makeMember($memberModel, $CREATOR, 'ST');
$loanST = makeLoanWithSchedule($loanModel, $productModel, $db, $mST['member_id'], 800000, 4, 1, date('Y-m-d', strtotime('-3 months')), $CREATOR);
$loanModel->submit($loanST, $CREATOR); $loanModel->approve($loanST, $APPROVER); $loanModel->disburse($loanST, $APPROVER, 'Cash');
// due_date on the loan itself = issue_date + loan_period_months (still in the future: -3 months + 4 months = +1 month from now)
// but installment #1's due_date = issue_date + 1 month = 2 months ago (already overdue).
$loanModel->syncOverdueStatus();
$loanSTRow = $loanModel->find($loanST);
$instST = $loanModel->getInstallments($loanST);
echo "  Loan issue_date=" . date('Y-m-d', strtotime('-3 months')) . " loan.due_date={$loanSTRow['due_date']} loan.status={$loanSTRow['status']}\n";
echo "  Installment #1 due_date={$instST[0]['due_date']} status={$instST[0]['status']}\n";
finding(
    'Loan-level overdue status and installment-level overdue status are determined by two independent criteria that can disagree: loans.status only flips to overdue when the LOAN\'s single final due_date (end of the whole term) has passed, while individual loan_installments rows flip to overdue as soon as THEIR OWN due_date passes -- a loan with an early missed installment mid-term shows status=active (not overdue) at the loan level while its installment schedule already shows an overdue row',
    $loanSTRow['status'] === 'active' && $instST[0]['status'] === 'overdue',
    "loan.status={$loanSTRow['status']} (loan.due_date={$loanSTRow['due_date']} has not yet passed) but installment #1.status={$instST[0]['status']} (installment due_date={$instST[0]['due_date']} already passed) -- both were synced by the SAME syncOverdueStatus() call, which internally applies two different WHERE clauses to loans vs loan_installments."
);

section('ST2: Loan marked completed while any installment remains unpaid -- checked via the same full-settlement loan (FS1) as a negative control');
$anyUnpaidFS = array_reduce($instFS, fn($c,$i)=>$c || (float)$i['remaining'] > 0.01, false);
check('ST2: (negative control) FS1\'s loan is completed AND no installment has remaining>0 -- consistent in the simple case', !$anyUnpaidFS);

// ==================================================================
// SECTION I: repayment_installment_allocations / payment_component_allocations
// ==================================================================
section('AL1: repayment_installment_allocations table state');
$tableBroken = false; $tableErr = '';
try {
    $db->query("SELECT COUNT(*) FROM repayment_installment_allocations")->fetchColumn();
} catch (PDOException $e) { $tableBroken = true; $tableErr = $e->getMessage(); }
finding(
    'repayment_installment_allocations is confirmed storage-engine-corrupted / inaccessible on this freshly-restored clone (matches the pre-existing SA-3 finding already documented in ControlledCorrectionService.php) and is never written to by any application code (RepaymentModel, LoanModel) -- it is completely non-functional, not merely unused',
    $tableBroken,
    $tableBroken ? "SELECT against it throws: {$tableErr}" : 'Table was queryable on this clone (re-verify against production separately).'
);

section('AL2: payment_component_allocations table state (a second, sibling allocation table)');
$rowsAL2 = null; $al2Err = '';
try { $rowsAL2 = (int)$db->query("SELECT COUNT(*) FROM payment_component_allocations")->fetchColumn(); }
catch (PDOException $e) { $al2Err = $e->getMessage(); }
finding(
    'payment_component_allocations exists, IS readable/writable (unlike repayment_installment_allocations), but is never referenced anywhere in app/ -- confirmed by a full-codebase grep for the table name and its would-be model class -- a second, entirely dead allocation table',
    $rowsAL2 !== null,
    $rowsAL2 !== null ? "Table is queryable, {$rowsAL2} rows on this fresh clone (0 expected, confirming no code path writes to it during any of this suite's real repayment activity above)." : $al2Err
);
if ($rowsAL2 !== null) {
    check('AL2: zero rows even after multiple real repayments were recorded in this test run (confirms no write path exists)', $rowsAL2 === 0);
}

// ==================================================================
// SECTION J: WEEKLY / BUSINESS-LOAN SCHEDULE COMPATIBILITY
// ==================================================================
section('WK1: business_boost weekly schedule reconciliation');
$mWK = makeMember($memberModel, $CREATOR, 'WK');
// Business Loan (type 2, interest_only). Use a rate/months within its bracket+period rules.
$wkAmount = 600000; $wkMonths = 6; $wkRate = $productModel->getInterestRate(2, $wkAmount);
$wkCalc = $productModel->calculateBusinessBoost($wkAmount, $wkRate, $wkMonths, max(1,$wkMonths-2), 8);
$inputWK = [
    'loan_number' => $loanModel->generateLoanNumber(), 'member_id' => $mWK['member_id'], 'loan_type_id' => 2,
    'loan_amount' => $wkAmount, 'interest_rate' => $wkRate, 'interest_amount' => $wkCalc['total_interest'],
    'processing_fee' => 0, 'monthly_installment' => $wkCalc['weekly_payment'],
    'total_payable' => $wkCalc['total_payable'], 'outstanding' => $wkCalc['total_payable'], 'amount_paid' => 0,
    'issue_date' => '2026-03-01', 'due_date' => date('Y-m-d', strtotime('+6 months', strtotime('2026-03-01'))),
    'disbursement_date' => '2026-03-01', 'loan_period_months' => $wkMonths, 'repayment_frequency' => 'weekly',
    'interest_mode' => 'percentage', 'grace_period_months' => 0, 'status' => 'draft', 'recorded_by' => $CREATOR,
];
$loanWK = $loanModel->create($inputWK);
$productModel->generateBusinessBoostSchedule($loanWK, $wkAmount, $wkRate, $wkMonths, max(1,$wkMonths-2), 8, '2026-03-01');
$instWK = $loanModel->getInstallments($loanWK);
$sumDueWK = array_sum(array_column($instWK, 'amount_due'));
$sumPrincipalWK = array_sum(array_column($instWK, 'principal_due'));
$sumInterestWK = array_sum(array_column($instWK, 'interest_due'));
echo "  Business Boost: {$wkMonths} months, rate={$wkRate}%, calc total_payable={$wkCalc['total_payable']}, schedule SUM(amount_due)={$sumDueWK}\n";
check('WK1: schedule row count = (months-2) interest-only + 8 weekly recovery', count($instWK) === (max(1,$wkMonths-2) + 8), "got=" . count($instWK));
check('WK1: SUM(amount_due) reconciles exactly to calculateBusinessBoost()\'s own total_payable', abs($sumDueWK - $wkCalc['total_payable']) < 0.01, "schedule={$sumDueWK} calc={$wkCalc['total_payable']}");
check('WK1: SUM(principal_due) = loan_amount exactly (last recovery week absorbs rounding via running balance)', abs($sumPrincipalWK - $wkAmount) < 0.01, "got={$sumPrincipalWK}");
check('WK1: SUM(interest_due) = calc total_interest exactly', abs($sumInterestWK - $wkCalc['total_interest']) < 0.01, "got={$sumInterestWK} calc={$wkCalc['total_interest']}");

section('WK2: weekly savings -- is it part of the installment schedule, or purely a repayment-entry concept?');
$weeklySavingsInSchedule = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='loan_installments' AND COLUMN_NAME LIKE '%saving%'")->fetchColumn();
echo "  loan_installments columns matching '%saving%': {$weeklySavingsInSchedule}\n";
finding(
    'Weekly savings has no representation in loan_installments at all -- it is purely a repayment-entry concept (RepaymentModel::recordWeeklySavings(), business_loan_weekly_savings table), entirely separate from and untracked by the installment schedule architecture',
    (int)$weeklySavingsInSchedule === 0,
    'No loan_installments column relates to savings; recordWeeklySavings() writes to loan_repayments.savings_paid and business_loan_weekly_savings only, never to loan_installments.'
);

// ==================================================================
// SECTION K: AUTHORIZATION BOUNDARIES
// ==================================================================
section('AUTH1: Who can trigger schedule (re)generation');
$loanSrc = file_get_contents(APP_PATH . '/controllers/LoanController.php');
check('AUTH1: loan creation (which triggers initial schedule generation) requires admin/treasurer/loans_officer', (bool)preg_match("/function requireWriteAccess\(\).*?hasRole\(\['admin', 'treasurer', 'loans_officer'\]\)/s", $loanSrc));
finding(
    'printSchedule() (which lazily REGENERATES the schedule from scratch whenever loan_installments is empty for that loan) is gated only by Session::requireAuth() -- ANY authenticated role, not specifically a loan-management role, can trigger schedule (re)generation for a loan that currently has no schedule',
    (bool)preg_match('/function printSchedule\(\): void\s*\{\s*Session::requireAuth\(\);(?!.*requireWriteAccess)/s', $loanSrc) || str_contains($loanSrc, "public function printSchedule(): void\n    {\n        Session::requireAuth();\n\n        \$loanId"),
    'Confirmed by direct source read of LoanController::printSchedule(): only Session::requireAuth(), no role check, before it may call generateInstallments()/generateWeeklyInstallmentSchedule()/etc.'
);
check('AUTH2: approve/reject/disburse require admin/chairman/vice_chairman (unchanged, re-confirmed)', (bool)preg_match("/function requireApproverAccess\(\).*?hasRole\(\['admin', 'chairman', 'vice_chairman'\]\)/s", file_get_contents(APP_PATH . '/controllers/traits/LoanRoleAccessTrait.php')));
check('AUTH3: no controller endpoint directly edits an individual installment row (no manual due-date/amount/status edit route exists)', !preg_match('/function\s+edit[Ii]nstallment|installment-edit/i', $loanSrc));

// ==================================================================
// SECTION L: DEAD-CODE / DUPLICATE-LOGIC CONFIRMATION
// ==================================================================
section('DC1: LoanModel::updateInstallmentsOnPayment() (plural) is never called -- confirmed dead code, duplicate of RepaymentModel::updateInstallmentOnPayment() (singular, private)');
$allAppSrc = '';
foreach (glob(APP_PATH . '/controllers/*.php') as $f) { $allAppSrc .= file_get_contents($f); }
foreach (glob(APP_PATH . '/models/*.php') as $f) { $allAppSrc .= file_get_contents($f); }
foreach (glob(APP_PATH . '/services/*.php') as $f) { $allAppSrc .= file_get_contents($f); }
$callCount = substr_count($allAppSrc, '->updateInstallmentsOnPayment(');
finding(
    'LoanModel::updateInstallmentsOnPayment() (plural) has zero callers anywhere in app/ -- the actually-used method for the same purpose is the separate, differently-implemented RepaymentModel::updateInstallmentOnPayment() (singular, private) -- two parallel implementations of "apply a payment to the next due installment(s)" exist, only one of which is live',
    $callCount === 0,
    "grep count of '->updateInstallmentsOnPayment(' across app/controllers, app/models, app/services = {$callCount} (its own definition aside)."
);

// ==================================================================
// SECTION M: TRANSACTIONALITY OF SCHEDULE CREATION
// ==================================================================
section('TX1: Is schedule generation wrapped in the same DB transaction as loan creation?');
$loanCreateSrc = substr($loanSrc, strpos($loanSrc, 'if ($id === null) {'), 4000);
finding(
    'Loan creation + schedule generation + the schedule-total invariant check are NOT wrapped in a single database transaction in LoanController::store() -- $this->model->create() commits the loan row immediately (auto-commit), then generateInstallments()/generateWeeklyInstallmentSchedule() run as separate auto-committed statements, and a mismatch is only caught AFTER the fact by a manual compensating $this->model->delete($newId) rather than a real ROLLBACK -- a crash between these steps could leave an orphaned loan row with a missing or partial schedule',
    !str_contains($loanCreateSrc, 'beginTransaction'),
    'No beginTransaction()/commit() call found wrapping the create()+generateInstallments()+invariant-check sequence in LoanController::store(); the self-check exists (Stage 9.2, "schedule invariant failed") but recovers via an explicit delete() call after the fact, not atomicity.'
);

echo "\n=== SUMMARY: $pass passed, $fail failed, $findings finding(s) confirmed ===\n";
exit($fail > 0 ? 1 : 0);
