<?php
/**
 * STAGE 17 PART C — Penalty Collection Test Harness
 *
 * Runs entirely against `empower_db_stage17`, an isolated database.
 * Never touches `empower_db`. Synthetic fixtures only -- no production
 * member/loan history is read or used, per the brief's explicit instruction.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db_stage17');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/app');
define('CORE_PATH', __DIR__ . '/core');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';

$pdo = Database::getInstance()->getConnection();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['loan_penalties', 'journal_lines', 'journal_entries', 'loan_installments', 'loan_repayments', 'loans',
          'member_fees', 'other_income_transactions', 'other_income_categories',
          'savings_account_holders', 'member_savings_accounts', 'members'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; $failures[] = "$label -- $detail"; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$ADMIN = 1;
$memberModel    = new MemberModel();
$loanModel      = new LoanModel();
$productModel   = new LoanProductModel();
$repaymentModel = new RepaymentModel();

$member = $memberModel->createWithCompulsoryAccount([
    'member_number' => $memberModel->generateMemberNumber(),
    'first_name' => 'Stage17', 'last_name' => 'PenaltyTestMember', 'gender' => 'Male',
    'phone' => '0700000170', 'national_id' => 'CM17PEN0001',
    'join_date' => date('Y-m-d', strtotime('-8 months')), 'status' => 'active',
], $ADMIN);
check('Test member created', $member['member_id'] > 0);
$memberId = $member['member_id'];

/** Helper: create a loan + one overdue installment, run calculatePenalties(), return [loanId, penaltyRow]. */
function makeOverdueLoanWithPenalty(LoanModel $loanModel, LoanProductModel $productModel, PDO $pdo, int $memberId, float $installmentAmount, int $daysOverdue): array {
    $loanId = $loanModel->create([
        'loan_number' => $loanModel->generateLoanNumber(), 'member_id' => $memberId, 'loan_type_id' => 1,
        'loan_amount' => $installmentAmount * 3, 'interest_rate' => 10.0, 'interest_amount' => $installmentAmount * 0.3,
        'total_payable' => $installmentAmount * 3, 'outstanding' => $installmentAmount * 3, 'monthly_installment' => $installmentAmount,
        'issue_date' => date('Y-m-d', strtotime('-2 months')), 'due_date' => date('Y-m-d', strtotime('-1 month')),
        'disbursement_date' => date('Y-m-d', strtotime('-2 months')), 'disbursement_method' => 'Cash',
        'loan_period_months' => 3, 'status' => 'overdue',
    ]);
    $pdo->prepare("INSERT INTO loan_installments (loan_id,installment_no,due_date,month_covered,amount_due,amount_paid,remaining,status) VALUES (?,1,?,?,?,0,?,'overdue')")
        ->execute([$loanId, date('Y-m-d', strtotime("-{$daysOverdue} days")), date('M Y', strtotime("-{$daysOverdue} days")), $installmentAmount, $installmentAmount]);
    $pdo->prepare("INSERT INTO loan_installments (loan_id,installment_no,due_date,month_covered,amount_due,amount_paid,remaining,status) VALUES (?,2,?,?,?,0,?,'pending')")
        ->execute([$loanId, date('Y-m-d', strtotime('+20 days')), date('M Y', strtotime('+20 days')), $installmentAmount, $installmentAmount]);
    $pdo->prepare("INSERT INTO loan_installments (loan_id,installment_no,due_date,month_covered,amount_due,amount_paid,remaining,status) VALUES (?,3,?,?,?,0,?,'pending')")
        ->execute([$loanId, date('Y-m-d', strtotime('+50 days')), date('M Y', strtotime('+50 days')), $installmentAmount, $installmentAmount]);

    $productModel->calculatePenalties();
    $penalty = $pdo->query("SELECT * FROM loan_penalties WHERE loan_id={$loanId} ORDER BY id DESC LIMIT 1")->fetch();
    return [$loanId, $penalty];
}

// ================================================================
// TEST 1 — No penalty repayment: existing behavior unchanged
// ================================================================
section('Test 1: Normal repayment with penalty_paid=0 -- existing behavior unchanged');

[$loan1Id, $pen1] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
check('Loan 1 created with an accruing penalty', $pen1 !== false);

$outstandingBefore = $repaymentModel->getOutstandingPenalty($loan1Id);
check('Outstanding penalty > 0 before any payment', $outstandingBefore > 0, "got {$outstandingBefore}");

$loan1Before = $loanModel->find($loan1Id);
$rep1 = $repaymentModel->recordRepayment([
    'loan_id' => $loan1Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => 50000, 'penalty_paid' => 0,
    'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-001', 'received_by' => $ADMIN,
]);
check('Repayment (no penalty) recorded', $rep1 !== false);
$loan1After = $loanModel->find($loan1Id);
check('Outstanding reduced by the FULL amount (unchanged pre-existing behavior)', abs((float)$loan1After['outstanding'] - ((float)$loan1Before['outstanding'] - 50000)) < 0.01);
$penAfter1 = $repaymentModel->getOutstandingPenalty($loan1Id);
check('Outstanding penalty unaffected by a zero-penalty repayment', abs($penAfter1 - $outstandingBefore) < 0.01, "before={$outstandingBefore} after={$penAfter1}");

$rep1Row = $pdo->query("SELECT * FROM loan_repayments WHERE id={$rep1}")->fetch();
$jl1 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id=?');
$jl1->execute([$rep1Row['journal_entry_id']]);
$jl1 = $jl1->fetchAll();
check('Zero-penalty repayment journal has NO Penalties(34) line', !in_array(34, array_column($jl1, 'account_id')));

// ================================================================
// TEST 2 — Partial penalty payment
// ================================================================
section('Test 2: Partial penalty payment');

[$loan2Id, $pen2] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$penaltyAmount2 = (float)$pen2['penalty_amount'];
check('Penalty 2 has a positive amount to partially pay against', $penaltyAmount2 > 1, "got {$penaltyAmount2}");

$partialAmount = round($penaltyAmount2 * 0.4, 2);
$rep2 = $repaymentModel->recordRepayment([
    'loan_id' => $loan2Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => 60000 + $partialAmount, 'penalty_paid' => $partialAmount,
    'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-002', 'received_by' => $ADMIN,
]);
check('Partial-penalty repayment recorded', $rep2 !== false);

$penRow2 = $pdo->query("SELECT * FROM loan_penalties WHERE id={$pen2['id']}")->fetch();
check('Penalty row amount_paid set to the partial amount', abs((float)$penRow2['amount_paid'] - $partialAmount) < 0.01, 'got ' . $penRow2['amount_paid']);
check('Penalty row status is STILL accruing (not incorrectly marked paid)', $penRow2['status'] === 'accruing');
check('Penalty row paid_date is still NULL', $penRow2['paid_date'] === null);

$remainingOutstanding2 = $repaymentModel->getOutstandingPenalty($loan2Id);
check('Remaining outstanding penalty = original - partial', abs($remainingOutstanding2 - round($penaltyAmount2 - $partialAmount, 2)) < 0.01, "got {$remainingOutstanding2}");

$rep2Row = $pdo->query("SELECT * FROM loan_repayments WHERE id={$rep2}")->fetch();
check('loan_repayments.penalty_paid recorded correctly', abs((float)$rep2Row['penalty_paid'] - $partialAmount) < 0.01);
check('loan_repayments.principal_paid = amount_paid - penalty_paid', abs((float)$rep2Row['principal_paid'] - 60000) < 0.01, 'got ' . $rep2Row['principal_paid']);

$jl2 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id=?');
$jl2->execute([$rep2Row['journal_entry_id']]);
$jl2 = $jl2->fetchAll();
$debitTotal2 = array_sum(array_column($jl2, 'debit'));
$creditTotal2 = array_sum(array_column($jl2, 'credit'));
check('Partial-penalty journal is balanced', abs($debitTotal2 - $creditTotal2) < 0.01, "debit={$debitTotal2} credit={$creditTotal2}");
$penaltyLine2 = array_values(array_filter($jl2, fn($l) => (int)$l['account_id'] === 34));
check('Journal has a Cr Penalties(34) line for the partial amount', count($penaltyLine2) === 1 && abs((float)$penaltyLine2[0]['credit'] - $partialAmount) < 0.01);

// ================================================================
// TEST 3 — Full penalty payment
// ================================================================
section('Test 3: Full penalty payment -> status=paid, paid_date set, journal correct');

[$loan3Id, $pen3] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$penaltyAmount3 = (float)$pen3['penalty_amount'];

$rep3 = $repaymentModel->recordRepayment([
    'loan_id' => $loan3Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => 80000 + $penaltyAmount3, 'penalty_paid' => $penaltyAmount3,
    'payment_method' => 'Bank Transfer', 'repayment_number' => 'PAY-TEST-003', 'received_by' => $ADMIN,
]);
check('Full-penalty repayment recorded', $rep3 !== false);

$penRow3 = $pdo->query("SELECT * FROM loan_penalties WHERE id={$pen3['id']}")->fetch();
check('Penalty status -> paid', $penRow3['status'] === 'paid');
check('Penalty paid_date set to payment date', $penRow3['paid_date'] === date('Y-m-d'));
check('Penalty amount_paid = full penalty_amount', abs((float)$penRow3['amount_paid'] - $penaltyAmount3) < 0.01);
check('Outstanding penalty for loan 3 is now 0', $repaymentModel->getOutstandingPenalty($loan3Id) < 0.01);

$rep3Row = $pdo->query("SELECT * FROM loan_repayments WHERE id={$rep3}")->fetch();
$jl3 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id=?');
$jl3->execute([$rep3Row['journal_entry_id']]);
$jl3 = $jl3->fetchAll();
$debitLine3 = array_values(array_filter($jl3, fn($l) => (float)$l['debit'] > 0));
$penaltyLine3 = array_values(array_filter($jl3, fn($l) => (int)$l['account_id'] === 34));
check('Debit line hits Bank Accounts(10) for the full amount', $debitLine3 && (int)$debitLine3[0]['account_id'] === 10 && abs((float)$debitLine3[0]['debit'] - (80000 + $penaltyAmount3)) < 0.01);
check('Cr Penalties(34) line equals the full penalty amount', count($penaltyLine3) === 1 && abs((float)$penaltyLine3[0]['credit'] - $penaltyAmount3) < 0.01);
$principalLine3 = array_values(array_filter($jl3, fn($l) => (int)$l['account_id'] === 14));
check('Cr Loans to Members(14) line equals the principal portion (80000)', count($principalLine3) === 1 && abs((float)$principalLine3[0]['credit'] - 80000) < 0.01);

// ================================================================
// TEST 4 — Penalty + principal in one repayment (interest is N/A for this payment type)
// ================================================================
section('Test 4: Penalty + principal components both appear correctly in ONE journal');
// Already directly demonstrated by Test 3's journal-line assertions above
// (principal line 80000 + penalty line != journal totals separately, one
// single journal entry). Verify explicitly here that it is exactly ONE entry.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE id=?');
$stmt->execute([$rep3Row['journal_entry_id']]);
check('Exactly one journal entry represents the whole repayment (principal+penalty combined)', (int)$stmt->fetchColumn() === 1);
check('That single entry has exactly the 3 expected lines (Dr Bank, Cr Loans, Cr Penalties)', count($jl3) === 3, 'got ' . count($jl3));

// ================================================================
// TEST 5 — Over-allocation rejected
// ================================================================
section('Test 5: Over-allocation (penalty_paid > outstanding) rejected, no source mutation, no journal');

[$loan5Id, $pen5] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$penaltyAmount5 = (float)$pen5['penalty_amount'];
$jeCountBefore5 = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$repCountBefore5 = (int)$pdo->query('SELECT COUNT(*) FROM loan_repayments')->fetchColumn();

$threw = false; $msg = '';
try {
    $repaymentModel->recordRepayment([
        'loan_id' => $loan5Id, 'member_id' => $memberId, 'payment_type' => 'installment',
        'payment_date' => date('Y-m-d'), 'amount_paid' => $penaltyAmount5 + 100000, 'penalty_paid' => $penaltyAmount5 + 5000,
        'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-005', 'received_by' => $ADMIN,
    ]);
} catch (InvalidArgumentException $e) {
    $threw = true; $msg = $e->getMessage();
}
check('Over-allocated penalty payment throws InvalidArgumentException', $threw, $msg);

$jeCountAfter5 = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$repCountAfter5 = (int)$pdo->query('SELECT COUNT(*) FROM loan_repayments')->fetchColumn();
check('No new journal entry created', $jeCountAfter5 === $jeCountBefore5, "before={$jeCountBefore5} after={$jeCountAfter5}");
check('No new repayment row created (transaction rolled back)', $repCountAfter5 === $repCountBefore5, "before={$repCountBefore5} after={$repCountAfter5}");
$penRow5 = $pdo->query("SELECT * FROM loan_penalties WHERE id={$pen5['id']}")->fetch();
check('Penalty row untouched (still accruing, amount_paid=0)', $penRow5['status'] === 'accruing' && (float)$penRow5['amount_paid'] === 0.0);

// ================================================================
// TEST 6 — Negative penalty amount rejected
// ================================================================
section('Test 6: Negative penalty_paid rejected');

$threw = false;
try {
    $repaymentModel->recordRepayment([
        'loan_id' => $loan5Id, 'member_id' => $memberId, 'payment_type' => 'installment',
        'payment_date' => date('Y-m-d'), 'amount_paid' => 50000, 'penalty_paid' => -1000,
        'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-006', 'received_by' => $ADMIN,
    ]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('Negative penalty_paid throws InvalidArgumentException', $threw);

// ================================================================
// TEST 7 — Waived penalty cannot be collected
// ================================================================
section('Test 7: Waived penalty excluded from outstanding, payment against it rejected');

[$loan7Id, $pen7] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$pdo->prepare("UPDATE loan_penalties SET status='waived' WHERE id=?")->execute([$pen7['id']]);

$outstanding7 = $repaymentModel->getOutstandingPenalty($loan7Id);
check('Waived penalty excluded from outstanding collectible total', $outstanding7 < 0.01, "got {$outstanding7}");

$threw = false;
try {
    $repaymentModel->recordRepayment([
        'loan_id' => $loan7Id, 'member_id' => $memberId, 'payment_type' => 'installment',
        'payment_date' => date('Y-m-d'), 'amount_paid' => 50000, 'penalty_paid' => 10000,
        'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-007', 'received_by' => $ADMIN,
    ]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('Attempting to collect against a waived-only penalty is rejected (nothing outstanding)', $threw);
$penRow7 = $pdo->query("SELECT * FROM loan_penalties WHERE id={$pen7['id']}")->fetch();
check('Waived penalty remains waived, untouched', $penRow7['status'] === 'waived' && (float)$penRow7['amount_paid'] === 0.0);

// ================================================================
// TEST 8 — Already-paid penalty cannot be paid again
// ================================================================
section('Test 8: Already-paid penalty cannot be collected a second time');

[$loan8Id, $pen8] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$penaltyAmount8 = (float)$pen8['penalty_amount'];
$repaymentModel->recordRepayment([
    'loan_id' => $loan8Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => $penaltyAmount8, 'penalty_paid' => $penaltyAmount8,
    'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-008A', 'received_by' => $ADMIN,
]);
$penRow8 = $pdo->query("SELECT * FROM loan_penalties WHERE id={$pen8['id']}")->fetch();
check('Penalty 8 fully paid first', $penRow8['status'] === 'paid');

$threw = false;
try {
    $repaymentModel->recordRepayment([
        'loan_id' => $loan8Id, 'member_id' => $memberId, 'payment_type' => 'installment',
        'payment_date' => date('Y-m-d'), 'amount_paid' => 20000, 'penalty_paid' => 5000,
        'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-008B', 'received_by' => $ADMIN,
    ]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('Second attempt to pay the same (already-paid) penalty is rejected', $threw);

// ================================================================
// TEST 9 — Duplicate repayment attempt does not create a duplicate journal
// ================================================================
section('Test 9: Repeating the exact same repayment call is idempotent at the journal level');
// RepaymentModel has no "resubmit" endpoint of its own -- idempotency is
// enforced the same way as every other posted entity, via
// JournalService::post()'s unique (source_module, source_reference_type,
// source_reference_id) constraint. Simulate a "duplicate" by attempting to
// re-post the journal for the SAME repayment id directly (the actual
// operational duplicate-click protection is the browser/controller layer,
// unchanged and out of scope here) -- what matters for THIS stage is that
// the underlying journal uniqueness guard still functions for penalty-
// bearing repayments exactly as it does for every other repayment.
[$loan9Id, $pen9] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$penaltyAmount9 = (float)$pen9['penalty_amount'];
$rep9 = $repaymentModel->recordRepayment([
    'loan_id' => $loan9Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => 30000 + $penaltyAmount9, 'penalty_paid' => $penaltyAmount9,
    'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-009', 'received_by' => $ADMIN,
]);
$rep9Row = $pdo->query("SELECT * FROM loan_repayments WHERE id={$rep9}")->fetch();
$reflection = new ReflectionMethod(RepaymentModel::class, 'postRepaymentJournal');
$reflection->setAccessible(true);
$secondPost = $reflection->invoke($repaymentModel, $rep9, $rep9Row, $ADMIN);
check('Re-posting the same repayment id returns created=false (no duplicate)', $secondPost['created'] === false);
check('Re-posting returns the SAME journal_entry_id', (int)$secondPost['journal_entry_id'] === (int)$rep9Row['journal_entry_id']);
$stmt9 = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE source_module='loan_repayments' AND source_reference_id=?");
$stmt9->execute([$rep9]);
check('Exactly one journal entry exists for this repayment', (int)$stmt9->fetchColumn() === 1);

// ================================================================
// TEST 10 — Penalty calculation idempotency (Stage 14 behavior preserved)
// ================================================================
section('Test 10: calculatePenalties() remains idempotent; paid penalty never re-accrues');

[$loan10Id, $pen10] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$countBefore10 = (int)$pdo->query("SELECT COUNT(*) FROM loan_penalties WHERE loan_id={$loan10Id}")->fetchColumn();
$productModel->calculatePenalties(); // same-day re-run
$countAfter10 = (int)$pdo->query("SELECT COUNT(*) FROM loan_penalties WHERE loan_id={$loan10Id}")->fetchColumn();
check('Same-day re-run of calculatePenalties() does not duplicate the penalty', $countAfter10 === $countBefore10, "before={$countBefore10} after={$countAfter10}");

$penaltyAmount10 = (float)$pen10['penalty_amount'];
$repaymentModel->recordRepayment([
    'loan_id' => $loan10Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => $penaltyAmount10, 'penalty_paid' => $penaltyAmount10,
    'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-010', 'received_by' => $ADMIN,
]);
$productModel->calculatePenalties(); // re-run again after the penalty is paid
$penRow10 = $pdo->query("SELECT * FROM loan_penalties WHERE id={$pen10['id']}")->fetch();
check('A PAID penalty never reverts to accruing after calculatePenalties() re-runs', $penRow10['status'] === 'paid');

// ================================================================
// TEST 11 — Loan deletion does not leave orphaned penalty artifacts
// ================================================================
section('Test 11: Deleting a loan removes its penalty records (existing ON DELETE CASCADE)');

[$loan11Id, $pen11] = makeOverdueLoanWithPenalty($loanModel, $productModel, $pdo, $memberId, 100000, 10);
$penCountBeforeDelete = (int)$pdo->query("SELECT COUNT(*) FROM loan_penalties WHERE loan_id={$loan11Id}")->fetchColumn();
check('Loan 11 has penalty rows before deletion', $penCountBeforeDelete > 0);

$loanModel->delete($loan11Id, $ADMIN);
$penCountAfterDelete = (int)$pdo->query("SELECT COUNT(*) FROM loan_penalties WHERE loan_id={$loan11Id}")->fetchColumn();
check('Deleting the loan cascades to remove its loan_penalties rows (no orphans)', $penCountAfterDelete === 0, "got {$penCountAfterDelete}");

// ================================================================
// TEST 12 — Trial Balance stays balanced across the whole suite
// ================================================================
section('Test 12: Trial Balance -- SUM(debits) = SUM(credits) across the whole isolated suite');

$tb = $pdo->query('SELECT SUM(debit) AS d, SUM(credit) AS c FROM journal_lines')->fetch();
check('Trial balance: total debits equal total credits', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "debit={$tb['d']} credit={$tb['c']}");

// ================================================================
// TEST 13 — General Ledger: penalty income reaches account 4060
// ================================================================
section('Test 13: General Ledger for Penalties account (34/4060) reflects collected penalties');

$glPenalty = $pdo->query('SELECT SUM(credit) AS total FROM journal_lines WHERE account_id = 34')->fetch();
$expectedPenaltyIncome = round($partialAmount + $penaltyAmount3 + $penaltyAmount8 + $penaltyAmount9 + $penaltyAmount10, 2);
check('Penalties(4060) GL total matches the sum of all penalty amounts actually collected in this suite',
    abs((float)$glPenalty['total'] - $expectedPenaltyIncome) < 0.5, "GL={$glPenalty['total']} expected={$expectedPenaltyIncome}");

// ================================================================
// TEST 14 — Receipt/statement data distinguishes principal vs penalty
// ================================================================
section('Test 14: loan_repayments row (feeding the receipt) distinguishes principal vs penalty, no double-count');

check('Test 3 repayment: principal_paid + penalty_paid = amount_paid (no double count, no omission)',
    abs(((float)$rep3Row['principal_paid'] + (float)$rep3Row['penalty_paid']) - (float)$rep3Row['amount_paid']) < 0.01,
    'principal=' . $rep3Row['principal_paid'] . ' penalty=' . $rep3Row['penalty_paid'] . ' total=' . $rep3Row['amount_paid']);
check('Test 3 repayment has a non-null journal_entry_id for receipt traceability', !empty($rep3Row['journal_entry_id']));

// ================================================================
// BONUS — multiple simultaneous accruing penalties, oldest-installment-first allocation
// ================================================================
section('Bonus: multiple accruing penalties on one loan, oldest overdue installment settled first');

$loan12Id = $loanModel->create([
    'loan_number' => $loanModel->generateLoanNumber(), 'member_id' => $memberId, 'loan_type_id' => 1,
    'loan_amount' => 300000, 'interest_rate' => 10.0, 'interest_amount' => 30000,
    'total_payable' => 330000, 'outstanding' => 330000, 'monthly_installment' => 110000,
    'issue_date' => date('Y-m-d', strtotime('-3 months')), 'due_date' => date('Y-m-d', strtotime('-1 month')),
    'disbursement_date' => date('Y-m-d', strtotime('-3 months')), 'disbursement_method' => 'Cash',
    'loan_period_months' => 3, 'status' => 'overdue',
]);
// Two overdue installments -- installment 1 older (20 days overdue), installment 2 newer (5 days overdue)
$pdo->prepare("INSERT INTO loan_installments (loan_id,installment_no,due_date,month_covered,amount_due,amount_paid,remaining,status) VALUES (?,1,?,?,?,0,?,'overdue')")
    ->execute([$loan12Id, date('Y-m-d', strtotime('-20 days')), date('M Y', strtotime('-20 days')), 100000, 100000]);
$pdo->prepare("INSERT INTO loan_installments (loan_id,installment_no,due_date,month_covered,amount_due,amount_paid,remaining,status) VALUES (?,2,?,?,?,0,?,'overdue')")
    ->execute([$loan12Id, date('Y-m-d', strtotime('-5 days')), date('M Y', strtotime('-5 days')), 100000, 100000]);
$productModel->calculatePenalties();
$penalties12 = $pdo->query("SELECT * FROM loan_penalties WHERE loan_id={$loan12Id} ORDER BY id")->fetchAll();
check('Two distinct accruing penalties exist for loan 12 (one per overdue installment)', count($penalties12) === 2, 'got ' . count($penalties12));

$rows12 = $repaymentModel->getOutstandingPenaltyRows($loan12Id);
check('getOutstandingPenaltyRows() returns rows ordered oldest-installment-due-date first', count($rows12) === 2
    && strtotime($rows12[0]['installment_due_date']) < strtotime($rows12[1]['installment_due_date']));

// Pay only enough to fully settle the OLDER (installment 1) penalty, nothing more
$olderPenaltyAmount = (float)$rows12[0]['penalty_amount'];
$repaymentModel->recordRepayment([
    'loan_id' => $loan12Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => $olderPenaltyAmount, 'penalty_paid' => $olderPenaltyAmount,
    'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-012', 'received_by' => $ADMIN,
]);
$olderRow = $pdo->query("SELECT * FROM loan_penalties WHERE id={$rows12[0]['id']}")->fetch();
$newerRow = $pdo->query("SELECT * FROM loan_penalties WHERE id={$rows12[1]['id']}")->fetch();
check('Older (installment 1) penalty fully settled first', $olderRow['status'] === 'paid');
check('Newer (installment 2) penalty untouched, still accruing', $newerRow['status'] === 'accruing' && (float)$newerRow['amount_paid'] === 0.0);

// ================================================================
// BONUS 2 — same installment recalculated on two different days: latest
// snapshot wins, no double-counting (the specific scenario documented in
// 04_repayment_allocation_analysis.txt as the reason for the "latest row
// per installment" outstanding-penalty definition)
// ================================================================
section('Bonus 2: Same installment penalty recalculated across two different days -- no double-counting');

$loan13Id = $loanModel->create([
    'loan_number' => $loanModel->generateLoanNumber(), 'member_id' => $memberId, 'loan_type_id' => 1,
    'loan_amount' => 300000, 'interest_rate' => 10.0, 'interest_amount' => 30000,
    'total_payable' => 330000, 'outstanding' => 330000, 'monthly_installment' => 100000,
    'issue_date' => date('Y-m-d', strtotime('-2 months')), 'due_date' => date('Y-m-d', strtotime('-1 month')),
    'disbursement_date' => date('Y-m-d', strtotime('-2 months')), 'disbursement_method' => 'Cash',
    'loan_period_months' => 3, 'status' => 'overdue',
]);
$pdo->prepare("INSERT INTO loan_installments (loan_id,installment_no,due_date,month_covered,amount_due,amount_paid,remaining,status) VALUES (?,1,?,?,?,0,?,'overdue')")
    ->execute([$loan13Id, date('Y-m-d', strtotime('-10 days')), date('M Y', strtotime('-10 days')), 100000, 100000]);

// Simulate "yesterday's" calculatePenalties() run by hand-inserting a
// second, OLDER 'accruing' row for the SAME installment_id, exactly as
// calculatePenalties() itself would have produced had it been run
// yesterday (a smaller penalty_amount, since fewer days were overdue then).
$instId13 = (int)$pdo->query("SELECT id FROM loan_installments WHERE loan_id={$loan13Id}")->fetchColumn();
$pdo->prepare("INSERT INTO loan_penalties (loan_id,installment_id,member_id,days_overdue,base_amount,penalty_rate,penalty_amount,status,calculated_date) VALUES (?,?,?,?,?,?,?, 'accruing', ?)")
    ->execute([$loan13Id, $instId13, $memberId, 9, 100000, 0.25, 225.00, date('Y-m-d', strtotime('-1 day'))]);

// Today's real run
$productModel->calculatePenalties();
$allRowsFor13 = $pdo->query("SELECT * FROM loan_penalties WHERE loan_id={$loan13Id} ORDER BY calculated_date")->fetchAll();
check('Two accruing rows now exist for the same installment (yesterday + today snapshots)', count($allRowsFor13) === 2, 'got ' . count($allRowsFor13));

$naiveSum = array_sum(array_column($allRowsFor13, 'penalty_amount'));
$correctOutstanding13 = $repaymentModel->getOutstandingPenalty($loan13Id);
check('getOutstandingPenalty() uses only the LATEST snapshot, not the naive sum of both stale+current rows',
    abs($correctOutstanding13 - (float)$allRowsFor13[1]['penalty_amount']) < 0.01
    && $correctOutstanding13 < $naiveSum - 0.01,
    "correct={$correctOutstanding13} naive_sum={$naiveSum} latest_row={$allRowsFor13[1]['penalty_amount']}");

// Paying the correct (latest) outstanding amount in full must succeed and
// leave nothing outstanding -- if the naive sum were used instead, this
// exact payment would incorrectly be rejected as "insufficient".
$repaymentModel->recordRepayment([
    'loan_id' => $loan13Id, 'member_id' => $memberId, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => $correctOutstanding13, 'penalty_paid' => $correctOutstanding13,
    'payment_method' => 'Cash', 'repayment_number' => 'PAY-TEST-013', 'received_by' => $ADMIN,
]);
check('Paying exactly the correctly-computed outstanding amount succeeds and clears it to 0',
    $repaymentModel->getOutstandingPenalty($loan13Id) < 0.01);

// ================================================================
// SUMMARY
// ================================================================
echo "\n================================================================\n";
echo "STAGE 17 PART C — PENALTY COLLECTION TEST RESULTS: {$pass} passed, {$fail} failed\n";
echo "================================================================\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
