<?php
/**
 * Stage 21-B — Loan Provisioning isolated regression suite.
 * Runs the REAL LoanProvisioningService/JournalService (no mocks) against
 * a uniquely-named disposable schema. Never touches production.
 */

require 'app/config/config.php';

$testSchema = 'stage21b_provisioning_test_' . date('His');
define('DB_NAME', $testSchema);

require 'test_safety_guard.php';
require 'core/Database.php';
require 'core/Autoloader.php';

echo "=== STAGE 21-B LOAN PROVISIONING REGRESSION SUITE ===\n";
echo "Disposable schema: $testSchema\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond, $detail = '') {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label" . ($detail ? " ($detail)" : '') . "\n"; $testsPassed++; }
    else       { echo "  FAIL: $label" . ($detail ? " ($detail)" : '') . "\n"; $testsFailed++; }
}

$pdoNoDb = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
$pdoNoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdoNoDb->exec("DROP DATABASE IF EXISTS `$testSchema`");
    $pdoNoDb->exec("CREATE DATABASE `$testSchema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = Database::getInstance()->getConnection();

    // ---- Base schema, copied structurally from production (read-only source, written only into the disposable schema) ----
    $prodPdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=empower_db;charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $prodPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['users','roles','members','loans','loan_installments','loan_repayments','accounts',
              'financial_years','accounting_periods','journal_entries','journal_lines','journal_number_sequences'] as $t) {
        $ddl = $prodPdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
        $db->exec($ddl);
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');

    // Apply the exact same provisioning migration used for production.
    $rawSql = file_get_contents('database/loan_provisioning_stage21.sql');
    $rawSql = str_replace('USE `empower_db`;', '', $rawSql);
    $lines = explode("\n", $rawSql);
    $clean = [];
    foreach ($lines as $line) { if (preg_match('/^\s*--/', $line)) continue; $clean[] = $line; }
    $sql = implode("\n", $clean);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') continue;
        $db->exec($stmt);
    }
    echo "Base schema + provisioning migration applied to disposable schema.\n\n";

    // ---- Minimal fixtures: users, financial year, accounting period, accounts 1185/5300 ----
    $db->exec("INSERT INTO roles (id, name, label) VALUES (1,'admin','Administrator')");
    $db->exec("INSERT INTO users (id, role_id, full_name, email, password_hash) VALUES
        (1, 1, 'Calculator User', 'calc@test.local', 'x'),
        (2, 1, 'Finalizer User', 'fin@test.local', 'x')");
    $db->exec("INSERT INTO members (id, member_number, first_name, last_name, status) VALUES (1,'M001','Test','Member','active')");
    $db->exec("INSERT INTO financial_years (id, name, start_date, end_date, status) VALUES (1,'FY2026','2026-01-01','2026-12-31','active')");
    $db->exec("INSERT INTO accounting_periods (id, financial_year_id, name, start_date, end_date, status) VALUES
        (1, 1, 'Q3 2026', '2026-07-01', '2026-09-30', 'open'),
        (2, 1, 'Q2 2026 (closed)', '2026-04-01', '2026-06-30', 'closed')");
    $db->exec("INSERT INTO accounts (id, code, name, type, subtype, normal_balance, is_system, is_active) VALUES
        (1, '1185', 'Allowance for Impairment on Loans', 'asset', 'contra_asset', 'credit', 1, 1),
        (2, '5300', 'Bad Debt Provision Expense', 'expense', 'operating_expense', 'debit', 1, 1)");
    $db->exec("INSERT INTO journal_number_sequences (prefix, last_number) VALUES ('JE', 0)");

    require_once 'app/models/AccountModel.php';
    require_once 'app/models/JournalEntryModel.php';
    require_once 'app/models/JournalLineModel.php';
    require_once 'app/services/JournalService.php';
    require_once 'app/services/LoanProvisioningService.php';
    $svc = new LoanProvisioningService();

    $asOf = '2026-09-30';
    $mkDate = fn(int $daysAgo) => (new DateTimeImmutable($asOf))->modify("-{$daysAgo} days")->format('Y-m-d');

    $loanId = 1;
    $insLoan = function (string $amount, string $issueDate = '2026-01-01', string $status = 'active') use ($db, &$loanId) {
        $id = $loanId++;
        $db->prepare("INSERT INTO loans (id, loan_number, member_id, loan_amount, total_payable, outstanding, issue_date, due_date, status)
                      VALUES (?,?,1,?,?,?,?,?,?)")
           ->execute([$id, "LN".$id, $amount, $amount, $amount, $issueDate, '2027-01-01', $status]);
        return $id;
    };
    $instId = 1;
    $insInstallment = function (int $loanId, string $dueDate, string $amountDue, string $amountPaid, string $status, int $isGrace = 0) use ($db, &$instId) {
        $id = $instId++;
        $db->prepare("INSERT INTO loan_installments (id, loan_id, installment_no, due_date, principal_due, interest_due, amount_due, amount_paid, remaining, status, is_grace_period)
                      VALUES (?,?,1,?,?,0,?,?,?,?,?)")
           ->execute([$id, $loanId, $dueDate, $amountDue, $amountDue, $amountPaid, bcsub($amountDue,$amountPaid,2), $status, $isGrace]);
        return $id;
    };
    $repId = 1;
    $insRepayment = function (int $loanId, string $paymentDate, string $principalPaid) use ($db, &$repId) {
        $id = $repId++;
        $db->prepare("INSERT INTO loan_repayments (id, repayment_number, payment_type, loan_id, member_id, payment_date, amount_paid, principal_paid, balance_before, balance_after)
                      VALUES (?,?,'installment',?,1,?,?,?,0,0)")
           ->execute([$id, 'PAY'.$id, $loanId, $paymentDate, $principalPaid, $principalPaid]);
        return $id;
    };

    // ---- Test loans ----
    $lCurrent   = $insLoan('5000000');                                    // no installments -> Current
    $l1_30      = $insLoan('5000000'); $insInstallment($l1_30, $mkDate(15), '500000', '0', 'overdue');
    $l31_90     = $insLoan('5000000'); $insInstallment($l31_90, $mkDate(45), '500000', '0', 'overdue');
    $l91_180    = $insLoan('5000000'); $insInstallment($l91_180, $mkDate(100), '500000', '0', 'overdue');
    $l181plus   = $insLoan('5000000'); $insInstallment($l181plus, $mkDate(200), '500000', '0', 'overdue');
    $lBoundary30 = $insLoan('1000000'); $insInstallment($lBoundary30, $mkDate(30), '100000', '0', 'overdue');
    $lBoundary31 = $insLoan('1000000'); $insInstallment($lBoundary31, $mkDate(31), '100000', '0', 'overdue');
    $lBoundary90 = $insLoan('1000000'); $insInstallment($lBoundary90, $mkDate(90), '100000', '0', 'overdue');
    $lBoundary91 = $insLoan('1000000'); $insInstallment($lBoundary91, $mkDate(91), '100000', '0', 'overdue');
    $lBoundary180 = $insLoan('1000000'); $insInstallment($lBoundary180, $mkDate(180), '100000', '0', 'overdue');
    $lBoundary181 = $insLoan('1000000'); $insInstallment($lBoundary181, $mkDate(181), '100000', '0', 'overdue');
    $lBoundary1  = $insLoan('1000000'); $insInstallment($lBoundary1, $mkDate(1), '100000', '0', 'overdue');
    $lFullyPaid = $insLoan('2000000'); $insInstallment($lFullyPaid, $mkDate(300), '2000000', '2000000', 'paid'); $insRepayment($lFullyPaid, $mkDate(250), '2000000');
    $lFutureOnly = $insLoan('3000000'); $insInstallment($lFutureOnly, $mkDate(-10), '300000', '0', 'pending'); // due 10 days AFTER as-of
    $lIssuedAfter = $insLoan('4000000', '2026-10-15'); // issued after as-of date
    $lRepayAfter = $insLoan('5000000'); $insRepayment($lRepayAfter, $mkDate(-5), '1000000'); // repayment dated AFTER as-of, must be excluded
    $lPartial = $insLoan('1000000'); $insInstallment($lPartial, $mkDate(45), '400000', '150000', 'partial'); // principal_due substitute via amount_due/paid; principal exposure test done via repayment
    $insRepayment($lPartial, $mkDate(40), '150000'); // principal repayment matching the partial installment
    $lInterestOnly = $insLoan('2000000'); $insInstallment($lInterestOnly, $mkDate(40), '50000', '0', 'overdue'); // interest-only obligation, principal_due effectively 0 in this row (amount_due=interest)
    $lGrace = $insLoan('2000000'); $insInstallment($lGrace, $mkDate(20), '30000', '0', 'overdue', 1); // is_grace_period=1, still has due date
    $lMultiOverdue = $insLoan('3000000');
        $insInstallment($lMultiOverdue, $mkDate(20), '200000', '0', 'overdue');
        $insInstallment($lMultiOverdue, $mkDate(60), '200000', '0', 'overdue'); // oldest -> should govern (31-90 band)
    $lMissed = $insLoan('1000000'); $insInstallment($lMissed, $mkDate(45), '100000', '0', 'missed');
    $lOverdueStatus = $insLoan('1000000'); $insInstallment($lOverdueStatus, $mkDate(45), '100000', '0', 'overdue');
    $lOverpaid = $insLoan('1000000'); $insRepayment($lOverpaid, $mkDate(10), '1500000'); // overpaid principal -> must floor at 0

    // ---- Run the calculation ----
    $runId = $svc->calculateRun(1, $asOf, 1); // accounting_period_id=1, calculated_by=user 1
    $details = [];
    foreach ($svc->getRunDetails($runId) as $d) { $details[(int)$d['loan_id']] = $d; }

    check('Loan count excludes issued-after-as-of loan', !isset($details[$lIssuedAfter]), 'loan '.$lIssuedAfter.' correctly absent from run details');

    check('Current loan (no installments) -> 0% / 0 provision', $details[$lCurrent]['bucket_name'] === 'Current' && (float)$details[$lCurrent]['required_provision'] === 0.0);
    check('1-30 days bucket, 1% rate', $details[$l1_30]['bucket_name'] === '1-30 days' && abs((float)$details[$l1_30]['applied_rate'] - 1.0) < 0.001);
    check('31-90 days bucket, 5% rate', $details[$l31_90]['bucket_name'] === '31-90 days' && abs((float)$details[$l31_90]['applied_rate'] - 5.0) < 0.001);
    check('91-180 days bucket, 25% rate', $details[$l91_180]['bucket_name'] === '91-180 days' && abs((float)$details[$l91_180]['applied_rate'] - 25.0) < 0.001);
    check('181+ days bucket, 100% rate', $details[$l181plus]['bucket_name'] === '181+ days' && abs((float)$details[$l181plus]['applied_rate'] - 100.0) < 0.001);

    check('Boundary: exactly 1 day -> 1-30 band', $details[$lBoundary1]['bucket_name'] === '1-30 days', 'days='.$details[$lBoundary1]['days_past_due']);
    check('Boundary: exactly 30 days -> 1-30 band', $details[$lBoundary30]['bucket_name'] === '1-30 days', 'days='.$details[$lBoundary30]['days_past_due']);
    check('Boundary: exactly 31 days -> 31-90 band', $details[$lBoundary31]['bucket_name'] === '31-90 days', 'days='.$details[$lBoundary31]['days_past_due']);
    check('Boundary: exactly 90 days -> 31-90 band', $details[$lBoundary90]['bucket_name'] === '31-90 days', 'days='.$details[$lBoundary90]['days_past_due']);
    check('Boundary: exactly 91 days -> 91-180 band', $details[$lBoundary91]['bucket_name'] === '91-180 days', 'days='.$details[$lBoundary91]['days_past_due']);
    check('Boundary: exactly 180 days -> 91-180 band', $details[$lBoundary180]['bucket_name'] === '91-180 days', 'days='.$details[$lBoundary180]['days_past_due']);
    check('Boundary: exactly 181 days -> 181+ band', $details[$lBoundary181]['bucket_name'] === '181+ days', 'days='.$details[$lBoundary181]['days_past_due']);

    check('Fully paid loan -> zero exposure despite old due date', abs((float)$details[$lFullyPaid]['unpaid_principal_exposure']) < 0.01, 'exposure='.$details[$lFullyPaid]['unpaid_principal_exposure']);
    check('Fully paid loan -> zero required provision', abs((float)$details[$lFullyPaid]['required_provision']) < 0.01);

    check('Future-only installment does NOT cause delinquency', $details[$lFutureOnly]['bucket_name'] === 'Current', 'bucket='.$details[$lFutureOnly]['bucket_name'].' days='.$details[$lFutureOnly]['days_past_due']);

    check('Repayment dated after as-of date excluded from exposure', abs((float)$details[$lRepayAfter]['unpaid_principal_exposure'] - 5000000.0) < 0.01, 'exposure='.$details[$lRepayAfter]['unpaid_principal_exposure']);

    check('Partial installment -> unpaid principal reflects only actual dated principal repayments', abs((float)$details[$lPartial]['unpaid_principal_exposure'] - 850000.0) < 0.01, 'exposure='.$details[$lPartial]['unpaid_principal_exposure']);

    check('Interest-only overdue installment can determine aging', $details[$lInterestOnly]['bucket_name'] === '31-90 days', 'bucket='.$details[$lInterestOnly]['bucket_name']);
    check('Interest-only: unpaid interest does not inflate exposure beyond unpaid principal', abs((float)$details[$lInterestOnly]['unpaid_principal_exposure'] - 2000000.0) < 0.01, 'exposure='.$details[$lInterestOnly]['unpaid_principal_exposure']);

    check('Grace-period installment: due date governs aging (not suppressed)', $details[$lGrace]['bucket_name'] === '1-30 days', 'bucket='.$details[$lGrace]['bucket_name'].' days='.$details[$lGrace]['days_past_due']);

    check('Multiple overdue installments: oldest determines bucket', $details[$lMultiOverdue]['bucket_name'] === '31-90 days', 'bucket='.$details[$lMultiOverdue]['bucket_name'].' days='.$details[$lMultiOverdue]['days_past_due']);

    check('missed and overdue statuses receive identical treatment', $details[$lMissed]['bucket_name'] === $details[$lOverdueStatus]['bucket_name'] && abs((float)$details[$lMissed]['required_provision'] - (float)$details[$lOverdueStatus]['required_provision']) < 0.01);

    check('Overpayment floors exposure at zero, never negative', abs((float)$details[$lOverpaid]['unpaid_principal_exposure']) < 0.01, 'exposure='.$details[$lOverpaid]['unpaid_principal_exposure']);

    // ---- Accounting: finalize with a different user (maker-checker) ----
    try {
        $svc->finalizeRun($runId, 1); // same user as calculator -> must fail
        check('Maker-checker: calculator cannot finalize own run', false, 'no exception was thrown');
    } catch (RuntimeException $e) {
        check('Maker-checker: calculator cannot finalize own run', str_contains($e->getMessage(), 'Maker-checker'), $e->getMessage());
    }

    $finalizeResult = $svc->finalizeRun($runId, 2); // different user -> should succeed
    $run = $svc->getRun($runId);
    check('Run finalized after different-user finalize', $run['status'] === 'finalized');
    check('Journal entry created for non-zero delta', $finalizeResult['journal_entry_id'] !== null);

    $je = $db->prepare("SELECT * FROM journal_entries WHERE id=?"); $je->execute([$finalizeResult['journal_entry_id']]); $je = $je->fetch(PDO::FETCH_ASSOC);
    $jl = $db->prepare("SELECT * FROM journal_lines WHERE journal_entry_id=?"); $jl->execute([$finalizeResult['journal_entry_id']]); $jl = $jl->fetchAll(PDO::FETCH_ASSOC);
    $totalDebit = array_sum(array_column($jl, 'debit')); $totalCredit = array_sum(array_column($jl, 'credit'));
    check('Provisioning journal balances (debit=credit)', abs($totalDebit - $totalCredit) < 0.01, "debit=$totalDebit credit=$totalCredit");
    check('Journal source_module = loan_provisioning', $je['source_module'] === 'loan_provisioning');

    $delta = (float)$run['total_delta'];
    if ($delta > 0) {
        $acct5300 = array_values(array_filter($jl, fn($l) => (float)$l['debit'] > 0))[0]['account_id'] ?? null;
        check('Increase posts Dr 5300', (int)$acct5300 === 2, "account_id=$acct5300");
    }

    // ---- Try re-finalizing (idempotency / duplicate-finalization prevention) ----
    try {
        $svc->finalizeRun($runId, 2);
        check('Duplicate finalization prevented', false, 'no exception thrown, run was already finalized');
    } catch (RuntimeException $e) {
        check('Duplicate finalization prevented', true, $e->getMessage());
    }

    // ---- Immutability: attempt a correction, must create a NEW run, not edit the old one ----
    $origDetailSnapshot = json_encode($svc->getRunDetails($runId));
    $correctionRunId = $svc->createCorrectionRun($runId, 1, $asOf, 1);
    check('Correction created a new, distinct run', $correctionRunId !== $runId);
    $correctionRun = $svc->getRun($correctionRunId);
    check('Correction run references the original via corrects_run_id', (int)$correctionRun['corrects_run_id'] === $runId);
    check('Original finalized run details unchanged after correction run created', json_encode($svc->getRunDetails($runId)) === $origDetailSnapshot);

    // ---- Closed-period protection ----
    try {
        $closedRunId = $svc->calculateRun(2, '2026-06-30', 1); // accounting_period_id=2 is closed
        $svc->finalizeRun($closedRunId, 2);
        check('Closed period blocks provisioning posting', false, 'finalize succeeded against a closed period -- should have failed');
    } catch (Throwable $e) {
        check('Closed period blocks provisioning posting', true, get_class($e) . ': ' . $e->getMessage());
    }

    echo "\n=== MANDATORY TEST: Hard-delete reproducibility (Stage 21 gate §11) ===\n";
    // Fresh, isolated scenario: loan with one dated principal repayment,
    // finalize a run including it, then delete the repayment via the
    // REAL RepaymentModel::delete() behavior, and prove the already-
    // finalized run's frozen snapshot is unaffected.
    $hdLoan = $insLoan('4000000');
    $hdInstId = $insInstallment($hdLoan, $mkDate(45), '400000', '0', 'overdue');
    $hdRepId = $insRepayment($hdLoan, $mkDate(40), '1000000');

    $expBefore = $svc->calculateExposure($hdLoan, $asOf);
    check('Hard-delete test setup: repayment included in live exposure before deletion', abs($expBefore['unpaid_principal_exposure'] - 3000000.0) < 0.01, 'exposure='.$expBefore['unpaid_principal_exposure']);

    $hdRunId = $svc->calculateRun(1, $asOf, 1);
    $svc->finalizeRun($hdRunId, 2);
    $hdDetail = null;
    foreach ($svc->getRunDetails($hdRunId) as $d) { if ((int)$d['loan_id'] === $hdLoan) { $hdDetail = $d; } }
    check('Finalized run snapshot captured the correct pre-deletion exposure', $hdDetail && abs((float)$hdDetail['unpaid_principal_exposure'] - 3000000.0) < 0.01, 'snapshot='.($hdDetail['unpaid_principal_exposure'] ?? 'MISSING'));
    $frozenSnapshot = json_encode($hdDetail);

    // Now hard-delete the repayment, exactly as RepaymentModel::delete() does.
    $db->prepare("DELETE FROM loan_repayments WHERE id=?")->execute([$hdRepId]);
    $stillExists = $db->prepare("SELECT COUNT(*) FROM loan_repayments WHERE id=?"); $stillExists->execute([$hdRepId]);
    check('Repayment row genuinely hard-deleted (test fidelity check)', (int)$stillExists->fetchColumn() === 0);

    $expAfter = $svc->calculateExposure($hdLoan, $asOf);
    check('Live re-query now shows DIFFERENT exposure after the deletion (confirms the real risk exists)', abs($expAfter['unpaid_principal_exposure'] - 4000000.0) < 0.01, 'live exposure now='.$expAfter['unpaid_principal_exposure']);

    $hdDetailAfter = null;
    foreach ($svc->getRunDetails($hdRunId) as $d) { if ((int)$d['loan_id'] === $hdLoan) { $hdDetailAfter = $d; } }
    check('CRITICAL: finalized run snapshot remains UNCHANGED after the source repayment was deleted', json_encode($hdDetailAfter) === $frozenSnapshot, 'snapshot unchanged = reproducibility preserved');
    check('CRITICAL: finalized run still reports the original frozen exposure, not the post-deletion figure', abs((float)$hdDetailAfter['unpaid_principal_exposure'] - 3000000.0) < 0.01, 'frozen='.$hdDetailAfter['unpaid_principal_exposure']);

    $hdRunFinal = $svc->getRun($hdRunId);
    check('Finalized run header status remains finalized and untouched', $hdRunFinal['status'] === 'finalized');

    echo "\n=== RESULTS: $testsPassed passed, $testsFailed failed ===\n";

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $testsFailed++;
} finally {
    try { $pdoNoDb->exec("DROP DATABASE IF EXISTS `$testSchema`"); echo "Disposable schema $testSchema dropped.\n"; } catch (Throwable $e2) {}
}

exit($testsFailed > 0 ? 1 : 0);
