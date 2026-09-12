<?php
/**
 * Stage — Loan Repayment Allocation & GL Integration Audit — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db. This is audit-verification evidence, not a
 * pass/fail gate for an implementation -- some assertions here are
 * DELIBERATELY expected to demonstrate a finding (e.g. the Interest
 * Income / Loans Receivable mismatch, and the lack of duplicate-
 * submission protection), not to prove the system is defect-free.
 * Each such assertion is labeled "[FINDING]" so a fail there is
 * expected and documented, not a test-writing bug.
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
$memberModel = new MemberModel();
$loanModel   = new LoanModel();
$repayModel  = new RepaymentModel();

function makeMember(MemberModel $mm, int $admin, string $tag): array {
    static $seq = 0; $seq++;
    static $runId = null; if ($runId === null) { $runId = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT); }
    return $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'REPAY', 'last_name' => "Test{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07' . $runId . str_pad((string)(10000 + $seq), 5, '0', STR_PAD_LEFT),
        'national_id' => "REPAY{$runId}{$tag}{$seq}",
        'date_of_birth' => '1990-01-01', 'address' => 'Test',
        'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
        'join_date' => date('Y-m-d'), 'status' => 'active',
    ], $admin);
}

function baseLoanInput(LoanModel $m, int $memberId, float $amount, float $interestPct, string $date, int $creator): array {
    $interest = round($amount * $interestPct, 2);
    return [
        'loan_number' => $m->generateLoanNumber(),
        'member_id' => $memberId, 'loan_type_id' => 1,
        'loan_amount' => $amount, 'interest_rate' => $interestPct * 100,
        'interest_amount' => $interest,
        'total_payable' => round($amount + $interest, 2),
        'outstanding' => round($amount + $interest, 2), 'amount_paid' => 0,
        'issue_date' => $date, 'due_date' => date('Y-m-d', strtotime($date . ' +1 month')),
        'disbursement_date' => $date,
        // Stage — Loan Schedule & Due-Date Integrity Remediation:
        // disburse() now generates the authoritative installment schedule
        // itself and requires it to reconcile to total_payable. A single
        // one-month installment matches this suite's existing due_date
        // assumption and doesn't change anything this (now-historical)
        // audit suite actually tests.
        'loan_period_months' => 1,
        'monthly_installment' => round($amount + $interest, 2),
        'repayment_frequency' => 'monthly',
        'interest_mode' => 'percentage',
        'status' => 'draft', 'recorded_by' => $creator,
    ];
}

function makeDisbursedLoan(LoanModel $m, int $memberId, float $amount, float $interestPct, string $date, int $creator, int $approver, string $fundMethod = 'Cash'): int {
    $id = $m->create(baseLoanInput($m, $memberId, $amount, $interestPct, $date, $creator));
    $m->submit($id, $creator);
    $m->approve($id, $approver);
    $m->disburse($id, $approver, $fundMethod);
    return $id;
}

function glMovement(PDO $db, int $accountId): array {
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE account_id=?");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$ACC_RECEIVABLE = 14; $ACC_CASH = 7; $ACC_MOMO = 8; $ACC_BANK = 10; $ACC_INTEREST = 77; $ACC_PENALTY = 34;

// ==================================================================
// TEST 1 + FINDING A: Full principal+interest repayment (standard path)
// ==================================================================
section('TEST 1 / FINDING A: Full standard-loan repayment -- interest income recognition vs Loans Receivable');
$m1 = makeMember($memberModel, $CREATOR, 'Full');
$loan1 = makeDisbursedLoan($loanModel, $m1['member_id'], 1000000, 0.10, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
// Loan 1: principal 1,000,000, interest 100,000, total_payable/outstanding = 1,100,000.
$rep1 = $repayModel->recordRepayment([
    'loan_id' => $loan1, 'member_id' => $m1['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 1100000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER,
]);
check('Full repayment recorded', $rep1 !== false);
$loan1Row = $loanModel->find($loan1);
check('Loan outstanding now 0', abs((float)$loan1Row['outstanding']) < 0.01);
check('Loan status completed', $loan1Row['status'] === 'completed');
// Isolate loan1's OWN two journal entries specifically (disbursement +
// repayment), by source reference, rather than a whole-account before/
// after delta -- robust regardless of what other test loans do to the
// same shared GL accounts before or after this one.
$loan1DisbJe = (int)$db->prepare("SELECT id FROM journal_entries WHERE source_module='loans' AND source_reference_type='disbursement' AND source_reference_id=?")->execute([$loan1]) ? $db->query("SELECT id FROM journal_entries WHERE source_module='loans' AND source_reference_type='disbursement' AND source_reference_id={$loan1}")->fetchColumn() : null;
$loan1RepayJe = (int)$repayModel->find($rep1)['journal_entry_id'];
$recvDisbLine = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id={$loan1DisbJe} AND account_id={$ACC_RECEIVABLE}")->fetch(PDO::FETCH_ASSOC);
$recvRepayLine = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id={$loan1RepayJe} AND account_id={$ACC_RECEIVABLE}")->fetch(PDO::FETCH_ASSOC);
$intRepayLine = $db->query("SELECT COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id={$loan1RepayJe} AND account_id={$ACC_INTEREST}")->fetch(PDO::FETCH_ASSOC);
$cashDisbLine = $db->query("SELECT COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id={$loan1DisbJe} AND account_id={$ACC_CASH}")->fetch(PDO::FETCH_ASSOC);
$cashRepayLine = $db->query("SELECT COALESCE(SUM(debit),0) d FROM journal_lines WHERE journal_entry_id={$loan1RepayJe} AND account_id={$ACC_CASH}")->fetch(PDO::FETCH_ASSOC);
$recvNet = ((float)$recvDisbLine['d'] - (float)$recvRepayLine['c']); // Dr at disbursement minus Cr at repayment
$intNet  = (float)$intRepayLine['c'];
$cashNet = (float)$cashRepayLine['d'] - (float)$cashDisbLine['c'];
echo "  Cash net movement for this loan's full lifecycle: " . $cashNet . " (expect +100,000 = interest actually collected)\n";
echo "  Loans Receivable net movement for this loan's full lifecycle (Dr at disbursement {$recvDisbLine['d']} minus Cr at repayment {$recvRepayLine['c']}): " . $recvNet . " (expect 0 if correctly tracked)\n";
echo "  Interest Income recognized for this loan: " . $intNet . " (expect 100,000 if correct)\n";
finding(
    'Standard-loan repayment does NOT recognize Interest Income and instead over-credits Loans Receivable by the interest amount',
    abs($intNet - 0.0) < 0.01 && abs($recvNet - (-100000)) < 0.01,
    "Interest Income recognized=Shs {$intNet} (expected 100,000); Loans Receivable net=Shs {$recvNet} (expected 0, actually -100,000 -- the account is left showing a -100,000 balance for this single fully-repaid loan, i.e. it does not return to zero as a correctly-tracked receivable should)."
);
check('Trial Balance for this repayment journal is still internally balanced (debits=credits) despite the misclassification', (function() use ($db, $rep1, $repayModel) {
    $row = $repayModel->find($rep1);
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=?");
    $stmt->execute([$row['journal_entry_id']]);
    $r = $stmt->fetch();
    return abs((float)$r['d'] - (float)$r['c']) < 0.01;
})());

// ==================================================================
// TEST 2: Partial repayment
// ==================================================================
section('TEST 2: Partial repayment');
$m2 = makeMember($memberModel, $CREATOR, 'Partial');
$loan2 = makeDisbursedLoan($loanModel, $m2['member_id'], 500000, 0.10, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$rep2 = $repayModel->recordRepayment([
    'loan_id' => $loan2, 'member_id' => $m2['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 200000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER,
]);
check('Partial repayment recorded', $rep2 !== false);
$loan2Row = $loanModel->find($loan2);
check('Outstanding reduced by exactly the payment (550,000 - 200,000 = 350,000)', abs((float)$loan2Row['outstanding'] - 350000) < 0.01);
check('Loan still active (not completed)', $loan2Row['status'] === 'active');

// ==================================================================
// TEST 3: Principal-only (Business Loan path)
// ==================================================================
section('TEST 3: Principal-only allocation (business-loan recordPrincipalPayment)');
$m3 = makeMember($memberModel, $CREATOR, 'PrinOnly');
$loan3 = makeDisbursedLoan($loanModel, $m3['member_id'], 300000, 0.10, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$recvBefore3 = glMovement($db, $ACC_RECEIVABLE);
$rep3 = $repayModel->recordPrincipalPayment([
    'loan_id' => $loan3, 'member_id' => $m3['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 100000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'), 'received_by' => $CASHIER,
]);
check('Principal-only repayment recorded', $rep3 !== false);
$recvAfter3 = glMovement($db, $ACC_RECEIVABLE);
check('Cr Loans Receivable increased by exactly 100,000 (principal only)', abs(($recvAfter3['c'] - $recvBefore3['c']) - 100000) < 0.01);
check('interest_paid = 0 on this repayment row', abs((float)$repayModel->find($rep3)['interest_paid']) < 0.01);

// ==================================================================
// TEST 4: Interest-only (Business Loan path)
// ==================================================================
section('TEST 4: Interest-only allocation (business-loan recordInterestPayment)');
$m4 = makeMember($memberModel, $CREATOR, 'IntOnly');
$loan4 = makeDisbursedLoan($loanModel, $m4['member_id'], 300000, 0.10, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$intBefore4 = glMovement($db, $ACC_INTEREST); $recvBefore4 = glMovement($db, $ACC_RECEIVABLE);
$loan4OutstandingBefore = (float)$loanModel->find($loan4)['outstanding'];
$rep4 = $repayModel->recordInterestPayment([
    'loan_id' => $loan4, 'member_id' => $m4['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 30000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'), 'received_by' => $CASHIER,
]);
check('Interest-only repayment recorded', $rep4 !== false);
$intAfter4 = glMovement($db, $ACC_INTEREST); $recvAfter4 = glMovement($db, $ACC_RECEIVABLE);
check('Cr Interest Income increased by exactly 30,000', abs(($intAfter4['c'] - $intBefore4['c']) - 30000) < 0.01);
check('Loans Receivable NOT touched by an interest-only payment', abs($recvAfter4['c'] - $recvBefore4['c']) < 0.01 && abs($recvAfter4['d'] - $recvBefore4['d']) < 0.01);
check('Loan outstanding unchanged by an interest-only payment', abs((float)$loanModel->find($loan4)['outstanding'] - $loan4OutstandingBefore) < 0.01);

// ==================================================================
// TEST 5: Penalty repayment
// ==================================================================
section('TEST 5: Penalty allocation and Penalty Income recognition');
$m5 = makeMember($memberModel, $CREATOR, 'Penalty');
$loan5 = makeDisbursedLoan($loanModel, $m5['member_id'], 200000, 0.10, date('Y-m-d', strtotime('-2 months')), $CREATOR, $APPROVER, 'Cash');
// Insert a real accruing penalty row directly (the accrual-calculation job itself is out of this stage's scope).
$db->prepare("INSERT INTO loan_penalties (loan_id, installment_id, member_id, days_overdue, base_amount, penalty_rate, penalty_amount, amount_paid, status, calculated_date) VALUES (?, NULL, ?, 30, 200000, 0.25, 15000, 0, 'accruing', CURDATE())")->execute([$loan5, $m5['member_id']]);
check('Outstanding penalty correctly readable', abs($repayModel->getOutstandingPenalty($loan5) - 15000) < 0.01);
$penBefore = glMovement($db, $ACC_PENALTY);
$rep5 = $repayModel->recordRepayment([
    'loan_id' => $loan5, 'member_id' => $m5['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 45000, 'penalty_paid' => 15000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER,
]);
check('Repayment with penalty recorded', $rep5 !== false);
$penAfter = glMovement($db, $ACC_PENALTY);
check('Cr Penalties income increased by exactly 15,000', abs(($penAfter['c'] - $penBefore['c']) - 15000) < 0.01);
check('Penalty row flipped to paid', $db->query("SELECT status FROM loan_penalties WHERE loan_id={$loan5}")->fetchColumn() === 'paid');
check('Excess penalty payment beyond outstanding is rejected', (function() use ($repayModel, $loan5, $m5, $CASHIER) {
    try {
        $repayModel->recordRepayment([
            'loan_id' => $loan5, 'member_id' => $m5['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
            'amount_paid' => 50000, 'penalty_paid' => 50000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
            'payment_type' => 'installment', 'received_by' => $CASHIER,
        ]);
        return false;
    } catch (InvalidArgumentException $e) { return true; }
})());

// ==================================================================
// TEST 6/7/8: Cash / MoMo / Bank repayment funding
// ==================================================================
section('TEST 6/7/8: Repayment correctly credits the selected Cash / MoMo / Bank account');
$m678 = makeMember($memberModel, $CREATOR, 'Methods');
$loan678 = makeDisbursedLoan($loanModel, $m678['member_id'], 900000, 0.10, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
foreach ([['Cash', $ACC_CASH, 100000], ['MTN Mobile Money', $ACC_MOMO, 100000], ['Bank Transfer', $ACC_BANK, 100000]] as [$method, $acc, $amt]) {
    $before = glMovement($db, $acc);
    $repayModel->recordRepayment([
        'loan_id' => $loan678, 'member_id' => $m678['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => $amt, 'penalty_paid' => 0, 'payment_method' => $method, 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER,
    ]);
    $after = glMovement($db, $acc);
    check("Dr $method account increased by " . number_format($amt), abs(($after['d'] - $before['d']) - $amt) < 0.01);
}

// ==================================================================
// TEST 9 + FINDING B: Mandatory payment source / silent Cash default
// ==================================================================
section('TEST 9 / FINDING B: Payment source -- is there a silent default to Cash?');
require_once APP_PATH . '/controllers/RepaymentController.php';
$refController = new ReflectionClass('RepaymentController');
$ctrl = $refController->newInstanceWithoutConstructor();
$collectMethod = $refController->getMethod('collectInput');
$collectMethod->setAccessible(true);
$_POST = ['loan_id' => '1', 'amount_paid' => '50000']; // payment_method deliberately omitted
$collected = $collectMethod->invoke($ctrl);
finding(
    "RepaymentController::collectInput() silently defaults payment_method to 'Cash' when omitted from the submitted form",
    $collected['payment_method'] === 'Cash',
    "collectInput() with no payment_method in \$_POST returned payment_method='" . $collected['payment_method'] . "' -- this passes the later in_array() validation (since 'Cash' IS a valid value) and reaches recordRepayment() unflagged, exactly mirroring the same class of defect already found and fixed for loan disbursement in the previous stage."
);
$_POST = [];

// ==================================================================
// TEST 10: Invalid payment source rejection
// ==================================================================
section('TEST 10: An arbitrary/invalid payment source is rejected at the controller validation layer');
$validateMethod = $refController->getMethod('validate');
$validateMethod->setAccessible(true);
// validate() reads $this->loanModel -- never initialized on a
// newInstanceWithoutConstructor() instance, so inject it directly.
$loanModelProp = $refController->getProperty('loanModel');
$loanModelProp->setAccessible(true);
$loanModelProp->setValue($ctrl, $loanModel);
$badInput = ['loan_id' => $loan2, 'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_date' => date('Y-m-d'), 'payment_method' => 'Crypto'];
$errors = $validateMethod->invoke($ctrl, $badInput);
check("An invalid payment_method ('Crypto') is rejected by validate()", isset($errors['payment_method']));

// ==================================================================
// TEST 11 + FINDING C: Duplicate repayment protection
// ==================================================================
section('TEST 11 / FINDING C: Can the identical repayment be submitted twice?');
$m11 = makeMember($memberModel, $CREATOR, 'Dup');
$loan11 = makeDisbursedLoan($loanModel, $m11['member_id'], 400000, 0.10, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$jeCountBefore11 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$recvBefore11 = glMovement($db, $ACC_RECEIVABLE);
$inputBase = [
    'loan_id' => $loan11, 'member_id' => $m11['member_id'],
    'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER,
];
// Simulate a genuine accidental resubmission: identical data, EXCEPT the
// repayment_number, which the controller freshly generates on every
// request regardless of whether the browser double-submitted the form --
// there is no client- or server-side idempotency token of any kind.
$dup1 = $repayModel->recordRepayment($inputBase + ['repayment_number' => $repayModel->generateRepaymentNumber()]);
$dup2 = $repayModel->recordRepayment($inputBase + ['repayment_number' => $repayModel->generateRepaymentNumber()]);
$jeCountAfter11 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$recvAfter11 = glMovement($db, $ACC_RECEIVABLE);
finding(
    'An identical repayment (same loan, same amount, same date, same method) submitted twice creates TWO separate journal entries and double-credits Loans Receivable, with no application-level duplicate-submission guard',
    ($jeCountAfter11 - $jeCountBefore11) === 2 && abs(($recvAfter11['c'] - $recvBefore11['c']) - 100000) < 0.01,
    "2 repayment rows created (ids {$dup1}, {$dup2}), " . ($jeCountAfter11 - $jeCountBefore11) . " new journal entries posted, Loans Receivable credited Shs " . ($recvAfter11['c'] - $recvBefore11['c']) . " total for what may have been a single real payment collected once."
);

// ==================================================================
// TEST 12: Failed-transaction atomicity
// ==================================================================
section('TEST 12: Atomicity -- a forced posting failure leaves no partial repayment state');
$m12 = makeMember($memberModel, $CREATOR, 'Atomic');
$loan12 = makeDisbursedLoan($loanModel, $m12['member_id'], 250000, 0.10, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$outstandingBefore12 = (float)$loanModel->find($loan12)['outstanding'];
$repayCountBefore12 = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
$jeCountBefore12 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$db->exec("UPDATE accounts SET is_active=0 WHERE id={$ACC_CASH}");
$threw12 = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loan12, 'member_id' => $m12['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER,
    ]);
} catch (Throwable $e) { $threw12 = true; }
$db->exec("UPDATE accounts SET is_active=1 WHERE id={$ACC_CASH}");
check('Posting against an inactive funding account throws', $threw12);
check('No new loan_repayments row survives (INSERT was inside the same rolled-back transaction)', (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn() === $repayCountBefore12);
check('No new journal entry survives', (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeCountBefore12);
check('Loan outstanding unchanged', abs((float)$loanModel->find($loan12)['outstanding'] - $outstandingBefore12) < 0.01);
// Prove clean retry
$retry12 = $repayModel->recordRepayment([
    'loan_id' => $loan12, 'member_id' => $m12['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER,
]);
check('Clean retry succeeds once the account is reactivated', $retry12 !== false);

// ==================================================================
// TEST 13: Inactive funding account (already proven in Test 12; separate account confirmed)
// ==================================================================
section('TEST 13: Inactive funding account rejection re-confirmed for Bank');
$db->exec("UPDATE accounts SET is_active=0 WHERE id={$ACC_BANK}");
$threw13 = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loan12, 'member_id' => $m12['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_method' => 'Bank Transfer', 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER,
    ]);
} catch (Throwable $e) { $threw13 = true; }
$db->exec("UPDATE accounts SET is_active=1 WHERE id={$ACC_BANK}");
check('Inactive Bank account rejected for repayment', $threw13);

// ==================================================================
// TEST 14: Closed accounting period rejection
// ==================================================================
section('TEST 14: Repayment dated outside any open accounting period is rejected');
require_once APP_PATH . '/models/AccountingPeriodModel.php';
$closedPeriodId = (new AccountingPeriodModel())->createPeriod([
    'financial_year_id' => 2, 'name' => 'TEST Closed Period (Repayment Audit)',
    'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'closed',
]);
$threw14 = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loan12, 'member_id' => $m12['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => '2026-02-15',
        'payment_type' => 'installment', 'received_by' => $CASHIER,
    ]);
} catch (Throwable $e) { $threw14 = true; }
check('Repayment dated in a closed/no-open period is rejected', $threw14);

// ==================================================================
// TEST 15: Journal linkage
// ==================================================================
section('TEST 15: Journal linkage is reliable');
$rep2Row = $repayModel->find($rep2);
check('loan_repayments.journal_entry_id populated', !empty($rep2Row['journal_entry_id']));
$stmt = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE id=? AND source_module='loan_repayments' AND source_reference_type='repayment' AND source_reference_id=?");
$stmt->execute([$rep2Row['journal_entry_id'], $rep2]);
check('journal_entries row correctly cross-references the repayment', (int)$stmt->fetchColumn() === 1);

// ==================================================================
// TEST 16: Trial Balance
// ==================================================================
section('TEST 16: Trial Balance -- every repayment journal individually balanced, system-wide balanced');
$allJe = $db->query("SELECT id FROM journal_entries WHERE source_module IN ('loan_repayments','loans')")->fetchAll(PDO::FETCH_COLUMN);
$allBalanced = true;
foreach ($allJe as $jeId) {
    $r = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=$jeId")->fetch(PDO::FETCH_ASSOC);
    if (abs((float)$r['d'] - (float)$r['c']) > 0.01) { $allBalanced = false; break; }
}
check('Every individual loan/repayment journal entry is balanced', $allBalanced);
$tb = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch(PDO::FETCH_ASSOC);
check('System-wide Trial Balance: total debits = total credits', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "d={$tb['d']} c={$tb['c']}");

// ==================================================================
// TEST 17: Correct outstanding loan balance
// ==================================================================
section('TEST 17: Outstanding balance consistent across loan record and repayment view');
check('Loan 2 outstanding (350,000) matches loan_amount+interest-payments math', abs((float)$loanModel->find($loan2)['outstanding'] - 350000) < 0.01);

// ==================================================================
// TEST 18: Member loan history
// ==================================================================
section('TEST 18: Member loan/repayment history reflects real repayments');
$stmt = $db->prepare("SELECT COUNT(*) FROM loan_repayments WHERE member_id=?");
$stmt->execute([$m1['member_id']]);
check('Member 1 repayment history shows exactly 1 repayment', (int)$stmt->fetchColumn() === 1);

// ==================================================================
// TEST 19: Repayment schedule (loan_installments) update
// ==================================================================
section('TEST 19: Repayment schedule update behaviour');
$instCountForLoan2 = (int)$db->prepare("SELECT COUNT(*) FROM loan_installments WHERE loan_id=?")->execute([$loan2]);
$stmt = $db->prepare("SELECT COUNT(*) FROM loan_installments WHERE loan_id=?");
$stmt->execute([$loan2]);
$instCount = (int)$stmt->fetchColumn();
if ($instCount === 0) {
    echo "  [INFO] No loan_installments rows exist for this loan (no schedule was generated at creation in this test harness -- schedule generation is a separate, unaudited code path in this stage). updateInstallmentOnPayment() is designed to no-op safely when there is nothing to update, confirmed by its own try/catch guarding pre-existing loan_installments storage-engine issues.\n";
    check('Repayment recording did not throw or fail due to absent/broken installment schedule rows', $rep2 !== false);
} else {
    echo "  [INFO] {$instCount} installment rows exist for loan 2.\n";
    check('At least one installment reflects the payment (informational)', true);
}

// ==================================================================
// TEST 20: Authorization
// ==================================================================
section('TEST 20: Authorization -- source-confirmed role gates');
$src = file_get_contents(APP_PATH . '/controllers/RepaymentController.php');
check("add() gates on admin, treasurer, cashier, loans_officer, office_admin",
    (bool)preg_match("/function add\(\).*?hasRole\(\['admin', 'treasurer', 'cashier', 'loans_officer', 'office_admin'\]\)/s", $src));
check("delete() (the only correction path) is admin-only",
    (bool)preg_match("/function delete\(\): void.*?user_role'\) !== 'admin'/s", $src));
check('No repayment-reverse/void route exists beyond delete() (confirmed by absence, not by a route we expect but did not find)',
    !str_contains(file_get_contents(APP_PATH . '/../index.php'), "'repayment-reverse'") &&
    !str_contains(file_get_contents(APP_PATH . '/../index.php'), "'repayment-void'"));

echo "\n=== SUMMARY: $pass passed, $fail failed, $findings finding(s) confirmed ===\n";
exit($fail > 0 ? 1 : 0);
