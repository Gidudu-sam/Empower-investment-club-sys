<?php
/**
 * Stage — Loan Repayment Interest Recognition & Submission Integrity —
 * Implementation Verification Test Harness.
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db. Proves, from actual journal_lines and loan_repayments
 * rows -- not merely from source-code inspection -- that:
 *   Fix 1: standard-loan repayments correctly split principal/interest and
 *          recognize Interest Income without over-crediting Loans Receivable.
 *   Fix 2: payment_method can no longer silently default to Cash.
 *   Fix 3: an identical repayment submission cannot be recorded twice.
 * and that none of this broke the three business-loan repayment variants,
 * penalty allocation, atomicity, authorization, or journal integrity.
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

$pass = 0; $fail = 0; $scenario = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function scenario(int $n, string $t): void { global $scenario; $scenario = $n; echo "\n=== SCENARIO $n: $t ===\n"; }

$CREATOR = 303; $APPROVER = 296; $CASHIER = 298;
if (!$db->query("SELECT id FROM users WHERE id={$CREATOR}")->fetch()) { $CREATOR = 1; }
if (!$db->query("SELECT id FROM users WHERE id={$APPROVER}")->fetch()) { $APPROVER = 1; }
if (!$db->query("SELECT id FROM users WHERE id={$CASHIER}")->fetch()) { $CASHIER = 1; }

$memberModel = new MemberModel();
$loanModel   = new LoanModel();
$repayModel  = new RepaymentModel();

function makeMember(MemberModel $mm, int $admin, string $tag): array {
    static $seq = 0; $seq++;
    static $runId = null; if ($runId === null) { $runId = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT); }
    return $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'REPINT', 'last_name' => "Test{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07' . $runId . str_pad((string)(20000 + $seq), 5, '0', STR_PAD_LEFT),
        'national_id' => "REPINT{$runId}{$tag}{$seq}",
        'date_of_birth' => '1990-01-01', 'address' => 'Test',
        'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
        'join_date' => date('Y-m-d'), 'status' => 'active',
    ], $admin);
}

function baseLoanInput(LoanModel $m, int $memberId, float $amount, float $interest, string $date, int $creator): array {
    return [
        'loan_number' => $m->generateLoanNumber(),
        'member_id' => $memberId, 'loan_type_id' => 1,
        'loan_amount' => $amount, 'interest_rate' => round(($interest / $amount) * 100, 4),
        'interest_amount' => $interest,
        'total_payable' => round($amount + $interest, 2),
        'outstanding' => round($amount + $interest, 2), 'amount_paid' => 0,
        'issue_date' => $date, 'due_date' => date('Y-m-d', strtotime($date . ' +1 month')),
        'disbursement_date' => $date,
        // Stage — Loan Schedule & Due-Date Integrity Remediation:
        // disburse() now generates the authoritative installment schedule
        // itself and requires it to reconcile to total_payable, exactly
        // like every real loan already has these fields set (via
        // LoanProductModel::calculateLoan()). A single one-month
        // installment matches this suite's existing due_date assumption
        // and doesn't change anything this suite actually tests
        // (repayment principal/interest allocation, GL posting).
        'loan_period_months' => 1,
        'monthly_installment' => round($amount + $interest, 2),
        'repayment_frequency' => 'monthly',
        'interest_mode' => 'percentage',
        'status' => 'draft', 'recorded_by' => $creator,
    ];
}

function makeDisbursedLoan(LoanModel $m, int $memberId, float $amount, float $interest, string $date, int $creator, int $approver, string $fundMethod = 'Cash'): int {
    $id = $m->create(baseLoanInput($m, $memberId, $amount, $interest, $date, $creator));
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

function journalLines(PDO $db, int $journalEntryId): array {
    $stmt = $db->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id=? ORDER BY id");
    $stmt->execute([$journalEntryId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$ACC_RECEIVABLE = 14; $ACC_CASH = 7; $ACC_MOMO = 8; $ACC_BANK = 10; $ACC_INTEREST = 77; $ACC_PENALTY = 34;

// ==================================================================
// SCENARIOS 1-7: Standard loan -- Fix 1, the mandatory Section 22 test
// ==================================================================
scenario(1, 'Mandatory interest test -- loan 1,000,000 / interest 100,000 / total 1,100,000, full repayment 1,100,000, Cash, penalty 0');
$m1 = makeMember($memberModel, $CREATOR, 'Mand');
$loan1 = makeDisbursedLoan($loanModel, $m1['member_id'], 1000000, 100000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$rep1 = $repayModel->recordRepayment([
    'loan_id' => $loan1, 'member_id' => $m1['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 1100000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('Full repayment recorded', $rep1 !== false);

scenario(2, 'Loan outstanding returns to exactly 0');
$loan1Row = $loanModel->find($loan1);
check('Loan outstanding = 0', abs((float)$loan1Row['outstanding']) < 0.01, "outstanding={$loan1Row['outstanding']}");
check('Loan status completed', $loan1Row['status'] === 'completed');

scenario(3, 'loan_repayments.principal_paid = exactly 1,000,000');
$rep1Row = $repayModel->find($rep1);
check('principal_paid = 1,000,000 exactly', abs((float)$rep1Row['principal_paid'] - 1000000) < 0.01, "principal_paid={$rep1Row['principal_paid']}");

scenario(4, 'loan_repayments.interest_paid = exactly 100,000');
check('interest_paid = 100,000 exactly', abs((float)$rep1Row['interest_paid'] - 100000) < 0.01, "interest_paid={$rep1Row['interest_paid']}");

scenario(5, 'Journal line: Dr Cash 1,100,000, from actual journal_lines');
$je1 = (int)$rep1Row['journal_entry_id'];
$lines1 = journalLines($db, $je1);
$cashLine = array_values(array_filter($lines1, fn($l) => (int)$l['account_id'] === $ACC_CASH));
check('Exactly one Cash line, Dr 1,100,000', count($cashLine) === 1 && abs((float)$cashLine[0]['debit'] - 1100000) < 0.01 && abs((float)$cashLine[0]['credit']) < 0.01,
    json_encode($cashLine));

scenario(6, 'Journal line: Cr Loans Receivable exactly 1,000,000 (NOT 1,100,000)');
$recvLine = array_values(array_filter($lines1, fn($l) => (int)$l['account_id'] === $ACC_RECEIVABLE));
check('Exactly one Loans Receivable line, Cr 1,000,000 exactly', count($recvLine) === 1 && abs((float)$recvLine[0]['credit'] - 1000000) < 0.01 && abs((float)$recvLine[0]['debit']) < 0.01,
    json_encode($recvLine));

scenario(7, 'Journal line: Cr Loan Interest Income exactly 100,000; journal has exactly 3 lines and is balanced');
$intLine = array_values(array_filter($lines1, fn($l) => (int)$l['account_id'] === $ACC_INTEREST));
check('Exactly one Interest Income line, Cr 100,000 exactly', count($intLine) === 1 && abs((float)$intLine[0]['credit'] - 100000) < 0.01 && abs((float)$intLine[0]['debit']) < 0.01,
    json_encode($intLine));
check('Journal has exactly 3 lines (no penalty line, since penalty=0)', count($lines1) === 3, "line count=" . count($lines1));
$sumD = array_sum(array_column($lines1, 'debit')); $sumC = array_sum(array_column($lines1, 'credit'));
check('Journal is balanced: total debits = total credits = 1,100,000', abs($sumD - $sumC) < 0.01 && abs($sumD - 1100000) < 0.01, "d={$sumD} c={$sumC}");

// ==================================================================
// SCENARIOS 8-11: Partial repayment convergence
// ==================================================================
scenario(8, 'First partial payment proportionally splits principal/interest (loan 500,000/50,000, pay 200,000)');
$m8 = makeMember($memberModel, $CREATOR, 'Part');
$loan8 = makeDisbursedLoan($loanModel, $m8['member_id'], 500000, 50000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$rep8a = $repayModel->recordRepayment([
    'loan_id' => $loan8, 'member_id' => $m8['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 200000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
$rep8aRow = $repayModel->find($rep8a);
$expectedInterest8a = round(200000 * (50000 / 550000), 2); // 18,181.82
$expectedPrincipal8a = round(200000 - $expectedInterest8a, 2);
check('Payment 1 interest_paid matches proportional ratio', abs((float)$rep8aRow['interest_paid'] - $expectedInterest8a) < 0.01, "got={$rep8aRow['interest_paid']} expected={$expectedInterest8a}");
check('Payment 1 principal_paid = amount - interest_paid', abs((float)$rep8aRow['principal_paid'] - $expectedPrincipal8a) < 0.01, "got={$rep8aRow['principal_paid']} expected={$expectedPrincipal8a}");

scenario(9, 'Second partial payment continues proportional split using live SUM() of prior payments');
$rep8b = $repayModel->recordRepayment([
    'loan_id' => $loan8, 'member_id' => $m8['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 200000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
$rep8bRow = $repayModel->find($rep8b);
$expectedInterest8b = round(200000 * (50000 / 550000), 2);
check('Payment 2 interest_paid also matches proportional ratio', abs((float)$rep8bRow['interest_paid'] - $expectedInterest8b) < 0.01, "got={$rep8bRow['interest_paid']} expected={$expectedInterest8b}");

scenario(10, 'Final payoff payment caps interest at the exact remaining contractual amount (never exceeds interest_amount)');
$loan8Row = $loanModel->find($loan8);
$finalPay8 = (float)$loan8Row['outstanding']; // whatever remains, e.g. 150,000
$rep8c = $repayModel->recordRepayment([
    'loan_id' => $loan8, 'member_id' => $m8['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => $finalPay8, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('Final payoff succeeds', $rep8c !== false);
check('Loan 8 fully completed, outstanding=0', abs((float)$loanModel->find($loan8)['outstanding']) < 0.01);

scenario(11, 'Cumulative principal_paid/interest_paid across all 3 payments equal loan_amount/interest_amount EXACTLY');
$sums8 = $db->query("SELECT COALESCE(SUM(principal_paid),0) p, COALESCE(SUM(interest_paid),0) i FROM loan_repayments WHERE loan_id={$loan8}")->fetch(PDO::FETCH_ASSOC);
check('Cumulative principal_paid = 500,000 exactly (rounding absorbed by principal)', abs((float)$sums8['p'] - 500000) < 0.01, "sum principal_paid={$sums8['p']}");
check('Cumulative interest_paid = 50,000 exactly (never exceeds contractual interest)', abs((float)$sums8['i'] - 50000) < 0.01, "sum interest_paid={$sums8['i']}");

// ==================================================================
// SCENARIOS 12-15: Penalty + repayment interaction
// ==================================================================
scenario(12, 'Penalty allocated first, unaffected by Fix 1 (penalty portion excluded from principal/interest split)');
$m12 = makeMember($memberModel, $CREATOR, 'Pen');
$loan12 = makeDisbursedLoan($loanModel, $m12['member_id'], 200000, 20000, date('Y-m-d', strtotime('-2 months')), $CREATOR, $APPROVER, 'Cash');
$db->prepare("INSERT INTO loan_penalties (loan_id, installment_id, member_id, days_overdue, base_amount, penalty_rate, penalty_amount, amount_paid, status, calculated_date) VALUES (?, NULL, ?, 30, 200000, 0.25, 15000, 0, 'accruing', CURDATE())")->execute([$loan12, $m12['member_id']]);
check('Outstanding penalty readable = 15,000', abs($repayModel->getOutstandingPenalty($loan12) - 15000) < 0.01);

scenario(13, 'Remaining non-penalty amount correctly split between principal/interest per Fix 1');
$rep12 = $repayModel->recordRepayment([
    'loan_id' => $loan12, 'member_id' => $m12['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 235000, 'penalty_paid' => 15000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('Repayment with penalty recorded', $rep12 !== false);
$rep12Row = $repayModel->find($rep12);
// non-penalty portion = 220,000; loan 200,000/20,000 => interest ratio 20000/220000
$expectedInterest12 = round(220000 * (20000 / 220000), 2); // = 20,000 (full remaining interest, capped)
check('Non-penalty portion (220,000) correctly split: interest_paid = 20,000 (full contractual interest)', abs((float)$rep12Row['interest_paid'] - 20000) < 0.01, "interest_paid={$rep12Row['interest_paid']}");
check('principal_paid = 200,000 (remaining 220,000 - 20,000 interest)', abs((float)$rep12Row['principal_paid'] - 200000) < 0.01, "principal_paid={$rep12Row['principal_paid']}");
check('penalty_paid = 15,000, tracked separately from principal/interest split', abs((float)$rep12Row['penalty_paid'] - 15000) < 0.01);

scenario(14, 'Cr Penalties account credited exactly 15,000 (journal proof)');
$je12 = (int)$rep12Row['journal_entry_id'];
$lines12 = journalLines($db, $je12);
$penLine = array_values(array_filter($lines12, fn($l) => (int)$l['account_id'] === $ACC_PENALTY));
check('Exactly one Penalties line, Cr 15,000 exactly', count($penLine) === 1 && abs((float)$penLine[0]['credit'] - 15000) < 0.01, json_encode($penLine));

scenario(15, 'Full 4-line journal (Cash/Receivable/Interest/Penalty) balanced');
check('Journal has exactly 4 lines', count($lines12) === 4, "line count=" . count($lines12));
$sumD12 = array_sum(array_column($lines12, 'debit')); $sumC12 = array_sum(array_column($lines12, 'credit'));
check('Journal balanced: debits = credits = 235,000', abs($sumD12 - $sumC12) < 0.01 && abs($sumD12 - 235000) < 0.01, "d={$sumD12} c={$sumC12}");

// ==================================================================
// SCENARIOS 16-20: Payment source -- Fix 2
// ==================================================================
require_once APP_PATH . '/controllers/RepaymentController.php';
$refController = new ReflectionClass('RepaymentController');
$ctrl = $refController->newInstanceWithoutConstructor();
$collectMethod = $refController->getMethod('collectInput');
$collectMethod->setAccessible(true);
$validateMethod = $refController->getMethod('validate');
$validateMethod->setAccessible(true);
$loanModelProp = $refController->getProperty('loanModel');
$loanModelProp->setAccessible(true);
$loanModelProp->setValue($ctrl, $loanModel);

scenario(16, 'A missing payment_method in the POST is NOT silently defaulted to Cash by collectInput()');
$_POST = ['loan_id' => '1', 'amount_paid' => '50000']; // payment_method deliberately omitted
$collected16 = $collectMethod->invoke($ctrl);
check('collectInput() returns empty payment_method (not Cash) when omitted', $collected16['payment_method'] === '', "got='" . $collected16['payment_method'] . "'");
$_POST = [];

scenario(17, 'A missing payment_method fails validate() -- request is rejected, not silently completed');
$m17 = makeMember($memberModel, $CREATOR, 'Src');
$loan17 = makeDisbursedLoan($loanModel, $m17['member_id'], 100000, 10000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$badInput17 = ['loan_id' => $loan17, 'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_date' => date('Y-m-d'), 'payment_method' => '', 'submission_token' => bin2hex(random_bytes(16))];
$errors17 = $validateMethod->invoke($ctrl, $badInput17);
check('validate() rejects a blank payment_method', isset($errors17['payment_method']));

scenario(18, 'A valid payment_method (Bank Transfer) is accepted by validate()');
$goodInput18 = ['loan_id' => $loan17, 'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_date' => date('Y-m-d'), 'payment_method' => 'Bank Transfer', 'submission_token' => bin2hex(random_bytes(16))];
$errors18 = $validateMethod->invoke($ctrl, $goodInput18);
check('validate() accepts Bank Transfer with no payment_method error', !isset($errors18['payment_method']));

scenario(19, 'An arbitrary/invalid payment source (Crypto) is rejected by validate()');
$badInput19 = ['loan_id' => $loan17, 'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_date' => date('Y-m-d'), 'payment_method' => 'Crypto', 'submission_token' => bin2hex(random_bytes(16))];
$errors19 = $validateMethod->invoke($ctrl, $badInput19);
check("Invalid payment_method ('Crypto') is rejected", isset($errors19['payment_method']));

scenario(20, 'All six existing payment methods still map to their original, unchanged GL accounts');
$m20 = makeMember($memberModel, $CREATOR, 'AllM');
$loan20 = makeDisbursedLoan($loanModel, $m20['member_id'], 900000, 90000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
foreach ([['Cash', $ACC_CASH], ['Airtel Money', $ACC_MOMO], ['MTN Mobile Money', $ACC_MOMO], ['Bank Transfer', $ACC_BANK], ['Cheque', $ACC_BANK], ['Other', $ACC_CASH]] as [$method, $acc]) {
    $before = glMovement($db, $acc);
    $r = $repayModel->recordRepayment([
        'loan_id' => $loan20, 'member_id' => $m20['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_method' => $method, 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
    ]);
    $after = glMovement($db, $acc);
    check("Payment method '$method' still Dr's account $acc by 10,000 (mapping unchanged)", $r !== false && abs(($after['d'] - $before['d']) - 10000) < 0.01);
}

// ==================================================================
// SCENARIOS 21-25: Duplicate protection -- Fix 3
// ==================================================================
scenario(21, 'Mandatory duplicate test -- same submission_token submitted twice at the model layer');
$m21 = makeMember($memberModel, $CREATOR, 'Dup');
$loan21 = makeDisbursedLoan($loanModel, $m21['member_id'], 400000, 40000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$token21 = bin2hex(random_bytes(16));
$repayCountBefore21 = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
$jeCountBefore21 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$outstandingBefore21 = (float)$loanModel->find($loan21)['outstanding'];
$dup21a = $repayModel->recordRepayment([
    'loan_id' => $loan21, 'member_id' => $m21['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => $token21,
]);
check('First submission succeeds', $dup21a !== false);
$threw21 = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loan21, 'member_id' => $m21['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => $token21,
    ]);
} catch (DuplicateRepaymentSubmissionException $e) { $threw21 = true; }
check('Second submission with the SAME token is rejected via DuplicateRepaymentSubmissionException', $threw21);

scenario(22, 'Exactly one loan_repayments row created for this token (not two)');
$rowsForToken21 = (int)$db->query("SELECT COUNT(*) FROM loan_repayments WHERE submission_token=" . $db->quote($token21))->fetchColumn();
check('Exactly 1 loan_repayments row for this submission_token', $rowsForToken21 === 1, "count={$rowsForToken21}");

scenario(23, 'Exactly one journal entry / one asset movement / one loan-balance reduction resulted');
$repayCountAfter21 = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
$jeCountAfter21 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$outstandingAfter21 = (float)$loanModel->find($loan21)['outstanding'];
check('Exactly 1 new loan_repayments row total', ($repayCountAfter21 - $repayCountBefore21) === 1, "delta=" . ($repayCountAfter21 - $repayCountBefore21));
check('Exactly 1 new journal entry', ($jeCountAfter21 - $jeCountBefore21) === 1, "delta=" . ($jeCountAfter21 - $jeCountBefore21));
check('Loan balance reduced by exactly one payment (50,000), not two', abs(($outstandingBefore21 - $outstandingAfter21) - 50000) < 0.01, "reduction=" . ($outstandingBefore21 - $outstandingAfter21));

scenario(24, 'findBySubmissionToken() correctly returns the single winning repayment');
$found24 = $repayModel->findBySubmissionToken($token21);
check('findBySubmissionToken() returns the winning row', $found24 !== null && (int)$found24['id'] === (int)$dup21a);

scenario(25, 'A genuinely separate, legitimate repeat payment (same loan/amount/date/method, DIFFERENT token) is NOT blocked');
$token25 = bin2hex(random_bytes(16));
$legit25 = $repayModel->recordRepayment([
    'loan_id' => $loan21, 'member_id' => $m21['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => $token25,
]);
check('A second, genuinely distinct payment with a fresh token succeeds (not treated as a duplicate)', $legit25 !== false);

// ==================================================================
// SCENARIOS 26-27: Concurrency / repeat-submission race
// ==================================================================
scenario(26, 'Two near-simultaneous processes racing to submit the SAME submission_token -- only one may win');
$m26 = makeMember($memberModel, $CREATOR, 'Race');
$loan26 = makeDisbursedLoan($loanModel, $m26['member_id'], 300000, 30000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$token26 = bin2hex(random_bytes(16));
$phpBinary = PHP_BINARY ?: 'php';
$workerScript = __DIR__ . '/_worker_submit_repayment.php';
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
// repayment_number is pre-supplied and deliberately DIFFERENT per worker --
// generateRepaymentNumber()'s own MAX(seq)+1 read is a separate, pre-existing,
// out-of-scope race (see the worker script's docblock, and Section 17 of the
// stage spec, which already anticipates repayment_number cannot serve as the
// dedup key). Giving each worker a distinct test-only number isolates the
// one guarantee under test here: that the SAME submission_token cannot be
// recorded twice, via uk_repayments_submission_token.
$rnA = 'RACE-A-' . substr($token26, 0, 8);
$rnB = 'RACE-B-' . substr($token26, 0, 8);
$argsA = [$phpBinary, $workerScript, $dbName, (string)$loan26, (string)$m26['member_id'], (string)$CASHIER, $token26, '50000', 'Cash', $rnA];
$argsB = [$phpBinary, $workerScript, $dbName, (string)$loan26, (string)$m26['member_id'], (string)$CASHIER, $token26, '50000', 'Cash', $rnB]; // same token, different repayment_number -- isolates the token race
$procA = proc_open($argsA, $descriptors, $pipesA);
$procB = proc_open($argsB, $descriptors, $pipesB);
$outA = stream_get_contents($pipesA[1]); fclose($pipesA[1]); fclose($pipesA[2]);
$outB = stream_get_contents($pipesB[1]); fclose($pipesB[1]); fclose($pipesB[2]);
proc_close($procA); proc_close($procB);
$outA = trim($outA); $outB = trim($outB);
echo "  [INFO] Worker A output: {$outA}\n  [INFO] Worker B output: {$outB}\n";
$successCount26 = (int)(str_starts_with($outA, 'SUCCESS')) + (int)(str_starts_with($outB, 'SUCCESS'));
$duplicateCount26 = (int)($outA === 'DUPLICATE') + (int)($outB === 'DUPLICATE');
check('Exactly one of the two concurrent processes succeeded', $successCount26 === 1, "A={$outA} B={$outB}");
check('The other was safely rejected as a duplicate (DB-level uk_repayments_submission_token, not merely an app-level pre-check)', $duplicateCount26 === 1, "A={$outA} B={$outB}");

scenario(27, 'No double financial event resulted from the race: exactly 1 repayment row for the raced token, loan balance reduced exactly once');
$rowsForToken26 = (int)$db->query("SELECT COUNT(*) FROM loan_repayments WHERE submission_token=" . $db->quote($token26))->fetchColumn();
check('Exactly 1 loan_repayments row for the raced token', $rowsForToken26 === 1, "count={$rowsForToken26}");
$loan26Row = $loanModel->find($loan26);
check('Loan 26 outstanding reduced by exactly 50,000 (one payment, not two)', abs((330000 - (float)$loan26Row['outstanding']) - 50000) < 0.01, "outstanding={$loan26Row['outstanding']}");

// ==================================================================
// SCENARIOS 28-30: Business-loan regression (must be unaffected by Fix 1)
// ==================================================================
scenario(28, 'recordPrincipalPayment() unaffected by Fix 1 -- principal_paid=full amount, interest_paid=0');
$m28 = makeMember($memberModel, $CREATOR, 'Prin');
$loan28 = makeDisbursedLoan($loanModel, $m28['member_id'], 300000, 30000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$rep28 = $repayModel->recordPrincipalPayment([
    'loan_id' => $loan28, 'member_id' => $m28['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 100000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'), 'received_by' => $CASHIER,
    'submission_token' => bin2hex(random_bytes(16)),
]);
$rep28Row = $repayModel->find($rep28);
check('recordPrincipalPayment(): principal_paid = 100,000 exactly (not proportionally split)', abs((float)$rep28Row['principal_paid'] - 100000) < 0.01);
check('recordPrincipalPayment(): interest_paid = 0 (unchanged behaviour)', abs((float)$rep28Row['interest_paid']) < 0.01);

scenario(29, 'recordInterestPayment() unaffected -- interest_paid=full amount, principal_paid=0, outstanding unchanged');
$m29 = makeMember($memberModel, $CREATOR, 'Int');
$loan29 = makeDisbursedLoan($loanModel, $m29['member_id'], 300000, 30000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$outstandingBefore29 = (float)$loanModel->find($loan29)['outstanding'];
$rep29 = $repayModel->recordInterestPayment([
    'loan_id' => $loan29, 'member_id' => $m29['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 30000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'), 'received_by' => $CASHIER,
    'submission_token' => bin2hex(random_bytes(16)),
]);
$rep29Row = $repayModel->find($rep29);
check('recordInterestPayment(): interest_paid = 30,000 exactly (unchanged behaviour)', abs((float)$rep29Row['interest_paid'] - 30000) < 0.01);
check('recordInterestPayment(): principal_paid = 0', abs((float)$rep29Row['principal_paid']) < 0.01);
check('recordInterestPayment(): loan outstanding unchanged (interest does not reduce principal)', abs((float)$loanModel->find($loan29)['outstanding'] - $outstandingBefore29) < 0.01);

scenario(30, 'recordWeeklySavings() unaffected -- interest_paid=full amount, outstanding unchanged, week_covered set');
$m30 = makeMember($memberModel, $CREATOR, 'Wk');
$loan30 = makeDisbursedLoan($loanModel, $m30['member_id'], 300000, 30000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$outstandingBefore30 = (float)$loanModel->find($loan30)['outstanding'];
$rep30 = $repayModel->recordWeeklySavings([
    'loan_id' => $loan30, 'member_id' => $m30['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 5000, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'), 'received_by' => $CASHIER,
    'submission_token' => bin2hex(random_bytes(16)),
]);
$rep30Row = $repayModel->find($rep30);
check('recordWeeklySavings(): interest_paid = 5,000 exactly (unchanged behaviour)', abs((float)$rep30Row['interest_paid'] - 5000) < 0.01);
check('recordWeeklySavings(): outstanding unchanged', abs((float)$loanModel->find($loan30)['outstanding'] - $outstandingBefore30) < 0.01);
check('recordWeeklySavings(): week_covered populated', !empty($rep30Row['week_covered']));

// ==================================================================
// SCENARIOS 31-35: Failure safety / atomicity
// ==================================================================
scenario(31, 'Posting against an inactive funding account throws and rolls back');
$m31 = makeMember($memberModel, $CREATOR, 'Atom');
$loan31 = makeDisbursedLoan($loanModel, $m31['member_id'], 250000, 25000, date('Y-m-d'), $CREATOR, $APPROVER, 'Cash');
$outstandingBefore31 = (float)$loanModel->find($loan31)['outstanding'];
$repayCountBefore31 = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
$jeCountBefore31 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$db->exec("UPDATE accounts SET is_active=0 WHERE id={$ACC_CASH}");
$threw31 = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loan31, 'member_id' => $m31['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
    ]);
} catch (Throwable $e) { $threw31 = true; }
$db->exec("UPDATE accounts SET is_active=1 WHERE id={$ACC_CASH}");
check('Posting against an inactive funding account throws', $threw31);

scenario(32, 'No partial loan_repayments row survives the forced failure');
check('loan_repayments count unchanged', (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn() === $repayCountBefore31);

scenario(33, 'No orphan journal entry survives the forced failure');
check('journal_entries count unchanged', (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeCountBefore31);
check('Loan outstanding unchanged by the failed attempt', abs((float)$loanModel->find($loan31)['outstanding'] - $outstandingBefore31) < 0.01);

scenario(34, 'Clean retry succeeds once the account is reactivated (a fresh token, since the failed attempt never inserted a row)');
$retry34 = $repayModel->recordRepayment([
    'loan_id' => $loan31, 'member_id' => $m31['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
    'amount_paid' => 50000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d'),
    'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
]);
check('Clean retry succeeds', $retry34 !== false);

scenario(35, 'Repayment dated outside any open accounting period is rejected');
require_once APP_PATH . '/models/AccountingPeriodModel.php';
$closedPeriodId = (new AccountingPeriodModel())->createPeriod([
    'financial_year_id' => 2, 'name' => 'TEST Closed Period (Repayment Interest Integrity)',
    'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'closed',
]);
$threw35 = false;
try {
    $repayModel->recordRepayment([
        'loan_id' => $loan31, 'member_id' => $m31['member_id'], 'repayment_number' => $repayModel->generateRepaymentNumber(),
        'amount_paid' => 10000, 'penalty_paid' => 0, 'payment_method' => 'Cash', 'payment_date' => '2026-02-15',
        'payment_type' => 'installment', 'received_by' => $CASHIER, 'submission_token' => bin2hex(random_bytes(16)),
    ]);
} catch (Throwable $e) { $threw35 = true; }
check('Repayment dated in a closed/no-open period is rejected', $threw35);

// ==================================================================
// SCENARIOS 36-40: Existing integrity, unbroken by this stage
// ==================================================================
scenario(36, 'Journal linkage: loan_repayments.journal_entry_id correctly cross-references journal_entries');
$rep1RowCheck = $repayModel->find($rep1);
check('journal_entry_id populated', !empty($rep1RowCheck['journal_entry_id']));
$stmt36 = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE id=? AND source_module='loan_repayments' AND source_reference_type='repayment' AND source_reference_id=?");
$stmt36->execute([$rep1RowCheck['journal_entry_id'], $rep1]);
check('journal_entries row correctly cross-references the repayment', (int)$stmt36->fetchColumn() === 1);

scenario(37, 'Every individual loan/repayment journal entry created this run is balanced');
$allJe = $db->query("SELECT id FROM journal_entries WHERE source_module IN ('loan_repayments','loans')")->fetchAll(PDO::FETCH_COLUMN);
$allBalanced = true; $checkedCount = 0;
foreach ($allJe as $jeId) {
    $r = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=$jeId")->fetch(PDO::FETCH_ASSOC);
    $checkedCount++;
    if (abs((float)$r['d'] - (float)$r['c']) > 0.01) { $allBalanced = false; break; }
}
check("Every individual loan/repayment journal entry is balanced ({$checkedCount} entries checked)", $allBalanced);

scenario(38, 'System-wide Trial Balance: total debits = total credits');
$tb = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch(PDO::FETCH_ASSOC);
check('System-wide Trial Balance balanced', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "d={$tb['d']} c={$tb['c']}");

scenario(39, 'Authorization gates unchanged by this stage');
$src = file_get_contents(APP_PATH . '/controllers/RepaymentController.php');
check("add() still gates on admin, treasurer, cashier, loans_officer, office_admin",
    (bool)preg_match("/function add\(\).*?hasRole\(\['admin', 'treasurer', 'cashier', 'loans_officer', 'office_admin'\]\)/s", $src));
check("delete() (the only correction path) is still admin-only",
    (bool)preg_match("/function delete\(\): void.*?user_role'\) !== 'admin'/s", $src));

scenario(40, 'Existing repayment history remains correct and queryable per member');
$stmt40 = $db->prepare("SELECT COUNT(*) FROM loan_repayments WHERE member_id=?");
$stmt40->execute([$m1['member_id']]);
check('Member 1 repayment history shows exactly 1 repayment (the mandatory scenario)', (int)$stmt40->fetchColumn() === 1);
$stmt40b = $db->prepare("SELECT COUNT(*) FROM loan_repayments WHERE member_id=?");
$stmt40b->execute([$m8['member_id']]);
check('Member 8 repayment history shows exactly 3 repayments (the partial-payment sequence)', (int)$stmt40b->fetchColumn() === 3);

echo "\n=== SUMMARY: $pass passed, $fail failed (40 scenarios) ===\n";
exit($fail > 0 ? 1 : 0);
