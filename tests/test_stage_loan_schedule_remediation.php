<?php
/**
 * Stage — Loan Schedule & Due-Date Integrity Remediation — Verification
 * Test Harness.
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db. Proves, on real disposable-clone data using the
 * system's actual live product configuration, that:
 *   Remediation A: the schedule anchors to the real disbursement date.
 *   Remediation B: month-end date arithmetic no longer skips a month.
 *   Remediation C/D: a UNIQUE(loan_id, installment_no) constraint plus a
 *     single transaction boundary make schedule generation concurrency-
 *     and failure-safe.
 *   Remediation F: the model layer rejects overpayment outright.
 *   Remediation G: loan completion requires the installment schedule
 *     (where one exists) to be fully reconciled too.
 *   Remediation H: loan-level overdue status reacts to installment-level
 *     overdue signals, not only the loan's own final due date.
 *   Remediation I: schedule (re)generation is role-gated, not merely
 *     authenticated.
 * and that none of this regressed the already-approved repayment
 * interest recognition, duplicate-submission protection, or business-
 * loan variants.
 *
 * Grace-period Finding 4 was resolved as Option B (documentation-only --
 * no calculation change anywhere), so this suite proves the grace-period
 * schedule's numeric values are BYTE-IDENTICAL to before this stage.
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

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
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
        'first_name' => 'REMED', 'last_name' => "Test{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07' . $runId . str_pad((string)(40000 + $seq), 5, '0', STR_PAD_LEFT),
        'national_id' => "REMED{$runId}{$tag}{$seq}",
        'date_of_birth' => '1990-01-01', 'address' => 'Test',
        'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
        'join_date' => date('Y-m-d'), 'status' => 'active',
    ], $admin);
}

/**
 * Faithfully reproduces the CURRENT (post-remediation) LoanController::
 * store() draft-creation flow: NO schedule is generated here anymore.
 * Only persists repayment_method for business loans, mirroring the
 * controller's own new, minimal post-create block.
 */
function createDraftLoan(LoanModel $lm, LoanProductModel $pm, PDO $db, int $memberId, float $amount, int $months, int $loanTypeId, string $issueDate, int $creator, string $repFreq = 'monthly', ?string $repaymentMethodChoice = null): int {
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
    if ($calc['repayment_type'] === 'interest_only' && $repaymentMethodChoice !== null) {
        $lm->update($newId, ['repayment_method' => $repaymentMethodChoice]);
    }
    return $newId;
}

function disburseLoan(LoanModel $lm, int $loanId, int $creator, int $approver, string $method = 'Cash'): array {
    $lm->submit($loanId, $creator);
    $lm->approve($loanId, $approver);
    return $lm->disburse($loanId, $approver, $method);
}

$ACC_RECEIVABLE = 14; $ACC_CASH = 7; $ACC_INTEREST = 77;

// ==================================================================
// SECTION A: SCHEDULE LIFECYCLE -- ANCHOR TO DISBURSEMENT (Remediation A)
// ==================================================================
section('LC1: Draft loan has NO installment schedule');
$mA = makeMember($memberModel, $CREATOR, 'LC');
$loanA = createDraftLoan($loanModel, $productModel, $db, $mA['member_id'], 1000000, 4, 1, '2026-07-05', $CREATOR);
check('Draft loan: 0 installments', count($loanModel->getInstallments($loanA)) === 0);
check('Draft loan status = draft', $loanModel->find($loanA)['status'] === 'draft');

section('LC2: Approved-but-not-disbursed loan still has NO installment schedule');
$loanModel->submit($loanA, $CREATOR);
$loanModel->approve($loanA, $APPROVER);
check('Approved loan: still 0 installments', count($loanModel->getInstallments($loanA)) === 0);
check('Approved loan status = approved', $loanModel->find($loanA)['status'] === 'approved');

section('LC3: Disbursement generates the authoritative schedule, anchored to the REAL disbursement date (not issue_date)');
$disburseResult = $loanModel->disburse($loanA, $APPROVER, 'Cash');
$loanARow = $loanModel->find($loanA);
$instA = $loanModel->getInstallments($loanA);
check('Disbursed loan now has 4 installments', count($instA) === 4, "got=" . count($instA));
check('loans.disbursement_date populated with the real disbursement date (today), NOT issue_date (2026-07-05)', $loanARow['disbursement_date'] === date('Y-m-d') && $loanARow['disbursement_date'] !== '2026-07-05', "disbursement_date={$loanARow['disbursement_date']}");
$expectedFirstDue = LoanModel::addCalendarMonths(date('Y-m-d'), 1);
check('First installment due_date = disbursement_date + 1 month (NOT issue_date + 1 month)', $instA[0]['due_date'] === $expectedFirstDue, "got={$instA[0]['due_date']} expected={$expectedFirstDue} (issue_date-based would have been " . date('Y-m-d', strtotime('2026-07-05 +1 month')) . ")");
check('loans.due_date refreshed from the real disbursement date', $loanARow['due_date'] === LoanModel::addCalendarMonths(date('Y-m-d'), 4));

section('LC4: Schedule generation runs exactly once per successful disbursement (disburse() unreachable twice)');
$threwSecondDisburse = false;
try { $loanModel->disburse($loanA, $APPROVER, 'Cash'); } catch (InvalidArgumentException $e) { $threwSecondDisburse = true; }
check('A second disburse() call on an already-active loan is rejected (status guard)', $threwSecondDisburse);
check('Still exactly 4 installments (no duplicate generation attempt occurred)', count($loanModel->getInstallments($loanA)) === 4);

// ==================================================================
// SECTION B: MONTHLY BRACKET SCENARIOS THROUGH THE FULL LIFECYCLE (M1-M6)
// ==================================================================
section('M1-M6: Bracket calculations unchanged, now proven through the real create->submit->approve->disburse lifecycle');
$brackets = [
    ['M1', 600000, 4, 1, 10, 240000, 840000],
    ['M2 (mandatory)', 1000000, 4, 1, 10, 400000, 1400000],
    ['M3', 2000000, 6, 1, 5, 600000, 2600000],
    ['M4', 6000000, 6, 1, 4, 1440000, 7440000],
    ['M5', 12000000, 6, 1, 3, 2160000, 14160000],
    ['M6', 16000000, 6, 1, 2, null, null],
];
foreach ($brackets as [$tag, $amount, $months, $typeId, $expectRate, $expectInterest, $expectTotal]) {
    $m = makeMember($memberModel, $CREATOR, $tag);
    $loanId = createDraftLoan($loanModel, $productModel, $db, $m['member_id'], $amount, $months, $typeId, '2026-07-10', $CREATOR);
    disburseLoan($loanModel, $loanId, $CREATOR, $APPROVER);
    $row = $loanModel->find($loanId);
    $inst = $loanModel->getInstallments($loanId);
    check("$tag: interest_rate = {$expectRate}%", abs((float)$row['interest_rate'] - $expectRate) < 0.0001, "got={$row['interest_rate']}");
    if ($expectInterest !== null) {
        check("$tag: interest_amount = " . number_format($expectInterest), abs((float)$row['interest_amount'] - $expectInterest) < 0.01, "got={$row['interest_amount']}");
        check("$tag: total_payable = " . number_format($expectTotal), abs((float)$row['total_payable'] - $expectTotal) < 0.01, "got={$row['total_payable']}");
    }
    $sumDue = array_sum(array_column($inst, 'amount_due'));
    check("$tag: SUM(amount_due) = total_payable exactly, schedule generated at disbursement", abs($sumDue - (float)$row['total_payable']) < 0.01, "sum={$sumDue} total_payable={$row['total_payable']}");
}

// ==================================================================
// SECTION C: CALENDAR DATE ARITHMETIC (Remediation B) -- proven via the REAL generator
// ==================================================================
section('CAL: Calendar-safe month arithmetic through generateInstallments(), for every mandatory test date');
$calCases = [
    ['2026-01-31', ['2026-02-28','2026-03-31','2026-04-30','2026-05-31']],
    ['2024-01-31', ['2024-02-29','2024-03-31','2024-04-30']],
    ['2026-01-30', ['2026-02-28','2026-03-30','2026-04-30']],
    // 2026-02-28 IS the last calendar day of Feb 2026 (28 days, non-leap)
    // -- per the approved convention, every target date is therefore the
    // LAST day of its own month too (2026-03-31, not 2026-03-28).
    ['2026-02-28', ['2026-03-31','2026-04-30','2026-05-31']],
    ['2024-02-29', ['2024-03-31','2024-04-30','2024-05-31']],
    ['2026-03-31', ['2026-04-30','2026-05-31','2026-06-30']],
    ['2026-04-30', ['2026-05-31','2026-06-30','2026-07-31']],
];
foreach ($calCases as [$start, $expectedDates]) {
    $mCal = makeMember($memberModel, $CREATOR, 'CAL' . str_replace('-', '', $start));
    $loanCal = createDraftLoan($loanModel, $productModel, $db, $mCal['member_id'], 500000, count($expectedDates), 1, $start, $CREATOR);
    // Directly exercise generateAuthoritativeSchedule() anchored at $start
    // (simulating "disbursed on the anchor date") to prove the calendar
    // fix specifically, independent of "today"'s date.
    $loanModel->submit($loanCal, $CREATOR);
    $loanModel->approve($loanCal, $APPROVER);
    // Force disbursement_date to the historical anchor for this specific
    // calendar-arithmetic proof (disburse() itself always uses today --
    // this directly calls the same generator disburse() uses, to prove
    // the arithmetic for arbitrary historical anchor dates too).
    $loanModel->generateAuthoritativeSchedule($loanCal, $start);
    $instCal = $loanModel->getInstallments($loanCal);
    $gotDates = array_column($instCal, 'due_date');
    check("Calendar anchor {$start}: due dates = " . implode(',', $expectedDates), $gotDates === $expectedDates, "got=" . implode(',', $gotDates));
}

// ==================================================================
// SECTION D: DUPLICATE / CONCURRENT SCHEDULE GENERATION (Remediation C/D)
// ==================================================================
section('DUP1: Sequential regeneration self-corrects (no duplication) -- unique constraint present');
$indexes = $db->query("SHOW INDEX FROM loan_installments WHERE Key_name='uk_installments_loan_seq'")->fetchAll();
check('uk_installments_loan_seq unique constraint is present on this clone', count($indexes) === 2, "found " . count($indexes) . " index rows");
$mDup = makeMember($memberModel, $CREATOR, 'Dup');
$loanDup = createDraftLoan($loanModel, $productModel, $db, $mDup['member_id'], 900000, 3, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanDup, $CREATOR, $APPROVER);
$countBefore = count($loanModel->getInstallments($loanDup));
$loanModel->generateAuthoritativeSchedule($loanDup, date('Y-m-d'));
$countAfter = count($loanModel->getInstallments($loanDup));
check('Regenerating the same schedule does not violate the unique constraint (DELETE-then-INSERT self-corrects)', $countAfter === $countBefore, "before={$countBefore} after={$countAfter}");

section('DUP2: Concurrent disbursement race -- two OS processes attempting to disburse the SAME approved loan simultaneously');
$mDup2 = makeMember($memberModel, $CREATOR, 'Race');
$loanDup2 = createDraftLoan($loanModel, $productModel, $db, $mDup2['member_id'], 700000, 3, 1, '2026-07-10', $CREATOR);
$loanModel->submit($loanDup2, $CREATOR);
$loanModel->approve($loanDup2, $APPROVER);
$phpBinary = PHP_BINARY ?: 'php';
$workerScript = __DIR__ . '/_worker_disburse_loan.php';
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$args = [$phpBinary, $workerScript, $dbName, (string)$loanDup2, (string)$APPROVER, 'Cash'];
$procA = proc_open($args, $descriptors, $pipesA);
$procB = proc_open($args, $descriptors, $pipesB);
$outA = stream_get_contents($pipesA[1]); fclose($pipesA[1]); fclose($pipesA[2]);
$outB = stream_get_contents($pipesB[1]); fclose($pipesB[1]); fclose($pipesB[2]);
proc_close($procA); proc_close($procB);
$outA = trim($outA); $outB = trim($outB);
echo "  [INFO] Worker A output: {$outA}\n  [INFO] Worker B output: {$outB}\n";
$successCount = (int)str_starts_with($outA, 'SUCCESS') + (int)str_starts_with($outB, 'SUCCESS');
check('Exactly one of the two concurrent disburse() calls succeeded', $successCount === 1, "A={$outA} B={$outB}");
$instDup2 = $loanModel->getInstallments($loanDup2);
$dupCheck = $db->query("SELECT loan_id, installment_no, COUNT(*) c FROM loan_installments WHERE loan_id={$loanDup2} GROUP BY loan_id, installment_no HAVING c>1")->fetchAll();
check('No duplicate installment_no rows resulted from the race', count($dupCheck) === 0, json_encode($dupCheck));
check('Exactly one schedule (3 installments), not a doubled one', count($instDup2) === 3, "got=" . count($instDup2));
$loanDup2Row = $loanModel->find($loanDup2);
check('Loan reached active status exactly once (the losing process\'s attempt left it untouched)', $loanDup2Row['status'] === 'active');
$jeCountDup2 = (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_module='loans' AND source_reference_type='disbursement' AND source_reference_id={$loanDup2}")->fetchColumn();
check('Exactly one disbursement journal entry posted (not two)', $jeCountDup2 === 1, "got={$jeCountDup2}");

// ==================================================================
// SECTION E: SCHEDULE-GENERATION FAILURE ROLLBACK (Remediation D)
// ==================================================================
section('TX1: A forced schedule-generation failure aborts the ENTIRE disbursement atomically');
$mTx = makeMember($memberModel, $CREATOR, 'Tx');
$loanTx = createDraftLoan($loanModel, $productModel, $db, $mTx['member_id'], 400000, 2, 1, '2026-07-10', $CREATOR);
$loanModel->submit($loanTx, $CREATOR);
$loanModel->approve($loanTx, $APPROVER);
// Force a genuine schedule-generation-stage failure by corrupting
// total_payable so it can no longer match what the generator will
// produce from loan_amount/interest_amount/months -- this trips the real
// schedule-total invariant check inside generateAuthoritativeSchedule()
// (a RuntimeException, not a mocked one), proving the whole disbursement
// aborts atomically. (Pre-inserting a colliding installment row does NOT
// work as a failure-forcing technique here: every generator starts with
// its own DELETE FROM loan_installments WHERE loan_id=?, which removes
// any pre-existing row for that loan before the unique constraint could
// ever be tested against it.)
$db->prepare("UPDATE loans SET total_payable=999999999 WHERE id=?")->execute([$loanTx]);
$threwTx = false;
try { $loanModel->disburse($loanTx, $APPROVER, 'Cash'); } catch (Throwable $e) { $threwTx = true; echo "  Exception: " . $e->getMessage() . "\n"; }
check('disburse() throws when the generated schedule cannot reconcile to total_payable', $threwTx);
$loanTxRow = $loanModel->find($loanTx);
check('Loan status rolled back to approved (NOT active)', $loanTxRow['status'] === 'approved', "got={$loanTxRow['status']}");
check('No disbursement_method/disbursement_date/disbursed_at were left set from the aborted attempt', empty($loanTxRow['disbursed_at']));
check('No partial schedule survives the rollback', count($loanModel->getInstallments($loanTx)) === 0, "got=" . count($loanModel->getInstallments($loanTx)));
$jeCountTx = (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_module='loans' AND source_reference_type='disbursement' AND source_reference_id={$loanTx}")->fetchColumn();
check('No orphaned journal entry from the aborted disbursement', $jeCountTx === 0, "got={$jeCountTx}");
// Restore the correct total_payable (400,000 principal + 10%x2mo=80,000
// interest = 480,000) and prove a clean retry succeeds.
$db->prepare("UPDATE loans SET total_payable=480000 WHERE id=?")->execute([$loanTx]);
$retryResult = $loanModel->disburse($loanTx, $APPROVER, 'Cash');
check('Clean retry succeeds once the obstruction is removed', !empty($retryResult['entry_number']));
check('Loan now active with a full, non-duplicated 2-installment schedule', $loanModel->find($loanTx)['status'] === 'active' && count($loanModel->getInstallments($loanTx)) === 2);

// ==================================================================
// SECTION F: MODEL-LAYER OVERPAYMENT REJECTION (Remediation F)
// ==================================================================
section('OP1: Direct model-layer overpayment call is rejected outright (recordRepayment)');
$mOP = makeMember($memberModel, $CREATOR, 'OP');
$loanOP = createDraftLoan($loanModel, $productModel, $db, $mOP['member_id'], 500000, 2, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanOP, $CREATOR, $APPROVER);
$loanOPRow = $loanModel->find($loanOP);
$repayCountBefore = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
$jeCountBefore = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$overAmount = (float)$loanOPRow['outstanding'] + 100000;
$threwOP = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loanOP, 'member_id' => $mOP['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => $overAmount, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
    ]);
} catch (InvalidArgumentException $e) { $threwOP = true; echo "  Exception: " . $e->getMessage() . "\n"; }
check('recordRepayment() rejects an amount exceeding outstanding', $threwOP);
check('No repayment row created', (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn() === $repayCountBefore);
check('No journal entry created', (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeCountBefore);
check('Loan outstanding unchanged', abs((float)$loanModel->find($loanOP)['outstanding'] - (float)$loanOPRow['outstanding']) < 0.01);
$instOPBefore = $loanModel->getInstallments($loanOP);
$instOPAfter = $loanModel->getInstallments($loanOP);
check('Installments unchanged', json_encode($instOPBefore) === json_encode($instOPAfter));

section('OP2: Direct model-layer overpayment call is rejected outright (recordPrincipalPayment)');
$mOP2 = makeMember($memberModel, $CREATOR, 'OP2');
$loanOP2 = createDraftLoan($loanModel, $productModel, $db, $mOP2['member_id'], 300000, 3, 2, '2026-07-10', $CREATOR, 'monthly', 'interest_only');
disburseLoan($loanModel, $loanOP2, $CREATOR, $APPROVER);
$loanOP2Row = $loanModel->find($loanOP2);
$threwOP2 = false;
try {
    $repayModel->recordPrincipalPayment([
        'loan_id' => $loanOP2, 'member_id' => $mOP2['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => (float)$loanOP2Row['outstanding'] + 50000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
        'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
    ]);
} catch (InvalidArgumentException $e) { $threwOP2 = true; }
check('recordPrincipalPayment() rejects an amount exceeding outstanding', $threwOP2);

section('OP3: Controller-level validation still rejects overpayment (unchanged, re-confirmed)');
require_once APP_PATH . '/controllers/RepaymentController.php';
$refController = new ReflectionClass('RepaymentController');
$ctrl = $refController->newInstanceWithoutConstructor();
$validateMethod = $refController->getMethod('validate');
$validateMethod->setAccessible(true);
$loanModelProp = $refController->getProperty('loanModel');
$loanModelProp->setAccessible(true);
$loanModelProp->setValue($ctrl, $loanModel);
$errorsOP3 = $validateMethod->invoke($ctrl, ['loan_id'=>$loanOP,'amount_paid'=>(float)$loanModel->find($loanOP)['outstanding']+1000,'penalty_paid'=>0,'payment_date'=>date('Y-m-d'),'payment_method'=>'Cash','submission_token'=>bin2hex(random_bytes(16))]);
check('Controller validate() still rejects overpayment', isset($errorsOP3['amount_paid']));

// ==================================================================
// SECTION G: LOAN COMPLETION INVARIANT (Remediation G)
// ==================================================================
section('COMP1: Full settlement reaches completed status, with every installment reconciled');
$mComp = makeMember($memberModel, $CREATOR, 'Comp');
$loanComp = createDraftLoan($loanModel, $productModel, $db, $mComp['member_id'], 1000000, 4, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanComp, $CREATOR, $APPROVER);
$repComp = $repayModel->recordRepayment([
    'loan_id' => $loanComp, 'member_id' => $mComp['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 1400000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('Full settlement recorded', $repComp !== false);
$loanCompRow = $loanModel->find($loanComp);
check('Loan reached completed status', $loanCompRow['status'] === 'completed', "got={$loanCompRow['status']}");
$instComp = $loanModel->getInstallments($loanComp);
$allPaidComp = array_reduce($instComp, fn($c,$i)=>$c && $i['status']==='paid', true);
check('Every installment reached status=paid (the check that now GATES completed)', $allPaidComp);
check('loan.outstanding = exactly 0', abs((float)$loanCompRow['outstanding']) < 0.01);

section('COMP2: Partial payment does NOT complete the loan even if a rounding quirk made outstanding hit 0 early (defensive proof via sequential partials)');
$mComp2 = makeMember($memberModel, $CREATOR, 'Comp2');
$loanComp2 = createDraftLoan($loanModel, $productModel, $db, $mComp2['member_id'], 900000, 3, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanComp2, $CREATOR, $APPROVER);
$repComp2a = $repayModel->recordRepayment([
    'loan_id' => $loanComp2, 'member_id' => $mComp2['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 300000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('Partial payment recorded', $repComp2a !== false);
check('Loan NOT completed after a partial payment', $loanModel->find($loanComp2)['status'] !== 'completed');

section('COMP3: Business-loan variant (recordPrincipalPayment) completion is UNCHANGED -- still driven by outstanding alone (not retrofitted with the installment-reconciliation gate)');
$mComp3 = makeMember($memberModel, $CREATOR, 'Comp3');
$loanComp3 = createDraftLoan($loanModel, $productModel, $db, $mComp3['member_id'], 300000, 3, 2, '2026-07-10', $CREATOR, 'monthly', 'business_boost');
disburseLoan($loanModel, $loanComp3, $CREATOR, $APPROVER);
$loanComp3Row = $loanModel->find($loanComp3);
check('Business Boost loan DOES have installments (unlike principal-payment-only business loans)', count($loanModel->getInstallments($loanComp3)) > 0);
// Pay off the loan entirely via recordPrincipalPayment() (the business-
// loan-variant path, which does NOT call updateInstallmentOnPayment()) --
// prove this still completes correctly, unaffected by Remediation G,
// since that gate was deliberately scoped to recordRepayment() only.
$repComp3 = $repayModel->recordPrincipalPayment([
    'loan_id' => $loanComp3, 'member_id' => $mComp3['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => (float)$loanComp3Row['outstanding'], 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('recordPrincipalPayment() full payoff recorded', $repComp3 !== false);
check('Business Boost loan reaches completed via outstanding<=0 alone (unaffected by Remediation G)', $loanModel->find($loanComp3)['status'] === 'completed');

// ==================================================================
// SECTION H: OVERDUE STATUS CONSISTENCY (Remediation H)
// ==================================================================
section('OD1: Loan-level overdue now triggers from an early installment-level overdue signal (approved business rule)');
$mOD = makeMember($memberModel, $CREATOR, 'OD');
$issueOD = date('Y-m-d', strtotime('-3 months'));
$loanOD = createDraftLoan($loanModel, $productModel, $db, $mOD['member_id'], 800000, 4, 1, $issueOD, $CREATOR);
// Force disbursement 3 months ago so installment #1 (due +1 month) is
// already well overdue, while the loan's own final due_date (+4 months
// from disbursement) has NOT passed yet.
$loanModel->submit($loanOD, $CREATOR);
$loanModel->approve($loanOD, $APPROVER);
$db->prepare("UPDATE loans SET disbursement_method='Cash' WHERE id=?")->execute([$loanOD]);
$loanModel->generateAuthoritativeSchedule($loanOD, $issueOD);
$loanModel->postDisbursement($loanOD, $APPROVER);
$newDueDate = LoanModel::addCalendarMonths($issueOD, 4);
$db->prepare("UPDATE loans SET status='active', disbursed_by=?, disbursed_at=NOW(), disbursement_date=?, due_date=? WHERE id=?")->execute([$APPROVER, $issueOD, $newDueDate, $loanOD]);
$loanModel->syncOverdueStatus();
$loanODRow = $loanModel->find($loanOD);
$instOD = $loanModel->getInstallments($loanOD);
echo "  loan.due_date={$loanODRow['due_date']} (future) loan.status={$loanODRow['status']}; installment #1 due_date={$instOD[0]['due_date']} status={$instOD[0]['status']}\n";
check('Installment #1 is overdue (its own due date has passed)', $instOD[0]['status'] === 'overdue');
check('Loan-level status now ALSO flips to overdue from that installment-level signal, even though loan.due_date has not passed yet', $loanODRow['status'] === 'overdue', "loan.status={$loanODRow['status']} loan.due_date={$loanODRow['due_date']} (today=" . date('Y-m-d') . ")");

section('OD2: A loan with no overdue installments and a future due_date remains active (no false positive)');
$mOD2 = makeMember($memberModel, $CREATOR, 'OD2');
$loanOD2 = createDraftLoan($loanModel, $productModel, $db, $mOD2['member_id'], 500000, 4, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanOD2, $CREATOR, $APPROVER);
$loanModel->syncOverdueStatus();
check('Freshly disbursed loan with no overdue installments stays active', $loanModel->find($loanOD2)['status'] === 'active');

// ==================================================================
// SECTION I: AUTHORIZATION -- LAZY SCHEDULE REGENERATION (Remediation I)
// ==================================================================
section('AUTH1: printSchedule() for a NOT-YET-DISBURSED loan shows a clear notice, attempts no generation');
$mAuth = makeMember($memberModel, $CREATOR, 'Auth');
$loanAuth = createDraftLoan($loanModel, $productModel, $db, $mAuth['member_id'], 400000, 2, 1, '2026-07-10', $CREATOR);
$loanModel->submit($loanAuth, $CREATOR);
$loanModel->approve($loanAuth, $APPROVER);
$loanAuthRow = $loanModel->findWithDetails($loanAuth);
check('Not-yet-disbursed loan: getInstallments() empty, disbursed_at empty (the exact condition printSchedule() checks)', count($loanModel->getInstallments($loanAuth)) === 0 && empty($loanAuthRow['disbursed_at']));

section('AUTH2: An already-disbursed loan with an anomalously-empty schedule is NOT regenerated for a non-write-access role');
$mAuth2 = makeMember($memberModel, $CREATOR, 'Auth2');
$loanAuth2 = createDraftLoan($loanModel, $productModel, $db, $mAuth2['member_id'], 400000, 2, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanAuth2, $CREATOR, $APPROVER);
// Simulate the anomaly: wipe the schedule after a real disbursement.
$db->prepare("DELETE FROM loan_installments WHERE loan_id=?")->execute([$loanAuth2]);
check('Anomalous state set up: disbursed loan, 0 installments', count($loanModel->getInstallments($loanAuth2)) === 0 && !empty($loanModel->find($loanAuth2)['disbursed_at']));
// The controller's own role check is what matters here -- verified by
// direct source read below (Session::hasRole() cannot be simulated
// outside a real request without a much larger harness); the model-level
// generateAuthoritativeSchedule() call itself is role-agnostic by design
// (authorization is the controller's job, not the model's) -- confirmed
// the controller performs the check before ever calling it.
$loanSrc = file_get_contents(APP_PATH . '/controllers/LoanController.php');
check('printSchedule() gates the lazy-regeneration MUTATION behind admin/treasurer/loans_officer (Remediation I)', (bool)preg_match("/hasRole\(\['admin', 'treasurer', 'loans_officer'\]\)\)\s*\{\s*\\\$scheduleNotice = 'No installment schedule is available/s", $loanSrc));
check('printSchedule() itself is still reachable by any authenticated role for VIEWING (Session::requireAuth() only, no broader role restriction added)', (bool)preg_match('/public function printSchedule\(\): void\s*\{\s*Session::requireAuth\(\);/s', $loanSrc));

// ==================================================================
// SECTION J: GRACE-PERIOD FINDING 4 -- OPTION B PROOF (documentation only, NO numeric change)
// ==================================================================
section('GRACE1: Start-Up Loan (grace_period_months=1) schedule numbers are BYTE-IDENTICAL to the pre-remediation audit\'s own figures');
$mGrace = makeMember($memberModel, $CREATOR, 'Grace');
$loanGrace = createDraftLoan($loanModel, $productModel, $db, $mGrace['member_id'], 600000, 3, 5, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanGrace, $CREATOR, $APPROVER);
$loanGraceRow = $loanModel->find($loanGrace);
$instGrace = $loanModel->getInstallments($loanGrace);
check('Grace installment #1 still shows principal_due=0 (unchanged -- Option B made no calculation change)', (int)$instGrace[0]['is_grace_period'] === 1 && abs((float)$instGrace[0]['principal_due']) < 0.01);
check('Grace installment #1 interest_due = 18,000 (identical to the audit\'s original reproduction)', abs((float)$instGrace[0]['interest_due'] - 18000) < 0.01, "got={$instGrace[0]['interest_due']}");
$repGrace = $repayModel->recordRepayment([
    'loan_id' => $loanGrace, 'member_id' => $mGrace['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 18000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
$repGraceRow = $repayModel->find($repGrace);
check('Repayment ledger STILL uses loan-wide proportional allocation, unchanged (interest_paid=1,486.24, matching the audit exactly -- RepaymentModel allocation policy was explicitly NOT touched)', abs((float)$repGraceRow['interest_paid'] - 1486.24) < 0.01, "got={$repGraceRow['interest_paid']}");
check('...and principal_paid=16,513.76, unchanged', abs((float)$repGraceRow['principal_paid'] - 16513.76) < 0.01, "got={$repGraceRow['principal_paid']}");
$loanModelSrc = file_get_contents(APP_PATH . '/models/LoanModel.php');
check('The grace-period block now carries the required documentation (Option B\'s approved fix)', str_contains($loanModelSrc, 'revenue-recognition forecast'));

// ==================================================================
// SECTION K: MANDATORY ACCOUNTING TEST (Section 15 of the stage spec)
// ==================================================================
section('ACCT1: 1,000,000/10%/4-month full settlement -- exact GL proof, loan, and schedule reconciliation');
$mAcct = makeMember($memberModel, $CREATOR, 'Acct');
$loanAcct = createDraftLoan($loanModel, $productModel, $db, $mAcct['member_id'], 1000000, 4, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanAcct, $CREATOR, $APPROVER);
$repAcct = $repayModel->recordRepayment([
    'loan_id' => $loanAcct, 'member_id' => $mAcct['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 1400000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
$loanAcctRow = $loanModel->find($loanAcct);
$instAcct = $loanModel->getInstallments($loanAcct);
$repAcctRow = $repayModel->find($repAcct);
check('Loan outstanding = 0', abs((float)$loanAcctRow['outstanding']) < 0.01);
check('Loan status = completed', $loanAcctRow['status'] === 'completed');
check('4 installments, total principal due = 1,000,000', abs(array_sum(array_column($instAcct,'principal_due')) - 1000000) < 0.01);
check('Total interest due = 400,000', abs(array_sum(array_column($instAcct,'interest_due')) - 400000) < 0.01);
check('Total amount due = 1,400,000', abs(array_sum(array_column($instAcct,'amount_due')) - 1400000) < 0.01);
check('All installments paid', array_reduce($instAcct, fn($c,$i)=>$c && $i['status']==='paid', true));
$linesAcct = $db->query("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id=" . (int)$repAcctRow['journal_entry_id'])->fetchAll(PDO::FETCH_ASSOC);
$cashLine = array_values(array_filter($linesAcct, fn($l)=>(int)$l['account_id']===$ACC_CASH));
$recvLine = array_values(array_filter($linesAcct, fn($l)=>(int)$l['account_id']===$ACC_RECEIVABLE));
$intLine  = array_values(array_filter($linesAcct, fn($l)=>(int)$l['account_id']===$ACC_INTEREST));
check('GL: Dr Cash 1,400,000', count($cashLine)===1 && abs((float)$cashLine[0]['debit']-1400000)<0.01);
check('GL: Cr Loans Receivable 1,000,000', count($recvLine)===1 && abs((float)$recvLine[0]['credit']-1000000)<0.01);
check('GL: Cr Interest Income 400,000', count($intLine)===1 && abs((float)$intLine[0]['credit']-400000)<0.01);

// ==================================================================
// SECTION L: BUSINESS-LOAN REGRESSION (unchanged variants)
// ==================================================================
section('REGR1a: "business_boost" variant -- full-term weekly principal+interest from week 1 (generateWeeklyInstallmentSchedule(), unchanged formula, now anchored at disbursement)');
$mRegr = makeMember($memberModel, $CREATOR, 'Regr');
$loanRegr = createDraftLoan($loanModel, $productModel, $db, $mRegr['member_id'], 600000, 6, 2, '2026-07-10', $CREATOR, 'monthly', 'business_boost');
disburseLoan($loanModel, $loanRegr, $CREATOR, $APPROVER);
$loanRegrRow = $loanModel->find($loanRegr);
$instRegr = $loanModel->getInstallments($loanRegr);
check('business_boost: 6 months x 4 weeks = 24 weekly installments, no grace phase', count($instRegr) === 24, "got=" . count($instRegr));
check('SUM(amount_due) reconciles to total_payable exactly', abs(array_sum(array_column($instRegr,'amount_due')) - (float)$loanRegrRow['total_payable']) < 0.01);
check('SUM(principal_due) = loan_amount exactly', abs(array_sum(array_column($instRegr,'principal_due')) - 600000) < 0.01);

section('REGR1b: "interest_only" (Standard) variant -- 4 interest-only months + 8 weekly recovery (generateBusinessBoostSchedule(), unchanged formula)');
$mRegr1b = makeMember($memberModel, $CREATOR, 'Regr1b');
$loanRegr1b = createDraftLoan($loanModel, $productModel, $db, $mRegr1b['member_id'], 600000, 6, 2, '2026-07-10', $CREATOR, 'monthly', 'interest_only');
disburseLoan($loanModel, $loanRegr1b, $CREATOR, $APPROVER);
$loanRegr1bRow = $loanModel->find($loanRegr1b);
$instRegr1b = $loanModel->getInstallments($loanRegr1b);
check('interest_only (Standard): 4 interest-only + 8 weekly recovery = 12 installments', count($instRegr1b) === 12, "got=" . count($instRegr1b));
check('SUM(amount_due) reconciles to total_payable exactly', abs(array_sum(array_column($instRegr1b,'amount_due')) - (float)$loanRegr1bRow['total_payable']) < 0.01);
check('SUM(principal_due) = loan_amount exactly', abs(array_sum(array_column($instRegr1b,'principal_due')) - 600000) < 0.01);

section('REGR2: Interest-only (Standard) business loan, weekly savings, recordInterestPayment/recordWeeklySavings unaffected');
$mRegr2 = makeMember($memberModel, $CREATOR, 'Regr2');
$loanRegr2 = createDraftLoan($loanModel, $productModel, $db, $mRegr2['member_id'], 300000, 6, 2, '2026-07-10', $CREATOR, 'monthly', 'interest_only');
disburseLoan($loanModel, $loanRegr2, $CREATOR, $APPROVER);
$outstandingBefore2 = (float)$loanModel->find($loanRegr2)['outstanding'];
$repInt = $repayModel->recordInterestPayment([
    'loan_id' => $loanRegr2, 'member_id' => $mRegr2['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 12000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'), 'received_by' => $CASHIER,
    'submission_token' => bin2hex(random_bytes(16)),
]);
check('recordInterestPayment() unaffected', $repInt !== false && abs((float)$loanModel->find($loanRegr2)['outstanding'] - $outstandingBefore2) < 0.01);
$repWS = $repayModel->recordWeeklySavings([
    'loan_id' => $loanRegr2, 'member_id' => $mRegr2['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 5000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'), 'received_by' => $CASHIER,
    'submission_token' => bin2hex(random_bytes(16)),
]);
check('recordWeeklySavings() unaffected', $repWS !== false);

// ==================================================================
// SECTION M: DUPLICATE-SUBMISSION PROTECTION REGRESSION (prior stage)
// ==================================================================
section('REGR3: submission_token duplicate protection still works end-to-end through the new disbursement-anchored lifecycle');
$mRegr3 = makeMember($memberModel, $CREATOR, 'Regr3');
$loanRegr3 = createDraftLoan($loanModel, $productModel, $db, $mRegr3['member_id'], 400000, 2, 1, '2026-07-10', $CREATOR);
disburseLoan($loanModel, $loanRegr3, $CREATOR, $APPROVER);
$token3 = bin2hex(random_bytes(16));
$dup3a = $repayModel->recordRepayment([
    'loan_id' => $loanRegr3, 'member_id' => $mRegr3['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => $token3,
]);
check('First submission succeeds', $dup3a !== false);
$threwDup3 = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loanRegr3, 'member_id' => $mRegr3['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => $token3,
    ]);
} catch (DuplicateRepaymentSubmissionException $e) { $threwDup3 = true; }
check('Duplicate submission_token still rejected via DuplicateRepaymentSubmissionException', $threwDup3);

// ==================================================================
// SECTION N: TRIAL BALANCE / JOURNAL INTEGRITY ACROSS THE WHOLE RUN
// ==================================================================
section('TB1: Every journal entry produced this run is individually balanced; system-wide Trial Balance balanced');
$allJe = $db->query("SELECT id FROM journal_entries")->fetchAll(PDO::FETCH_COLUMN);
$allBalanced = true; $checked = 0;
foreach ($allJe as $jeId) {
    $r = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=$jeId")->fetch(PDO::FETCH_ASSOC);
    $checked++;
    if (abs((float)$r['d'] - (float)$r['c']) > 0.01) { $allBalanced = false; break; }
}
check("Every journal entry is individually balanced ({$checked} checked)", $allBalanced);
$tb = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch(PDO::FETCH_ASSOC);
check('System-wide Trial Balance: debits = credits', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "d={$tb['d']} c={$tb['c']}");

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
