<?php
/**
 * Concurrency-test worker process for
 * tests/test_stage_loan_repayment_interest_integrity.php (Scenario 26).
 *
 * Launched as a separate PHP CLI process (via proc_open) so its
 * transaction genuinely overlaps a sibling process's transaction at the
 * database level -- something a single-threaded PHP script cannot
 * simulate against its own single PDO connection. Attempts to record a
 * repayment carrying a submission_token that a sibling worker is
 * attempting AT THE SAME TIME, and reports the outcome on stdout so the
 * parent test process can assert exactly one of the two actually won.
 *
 * argv: dbName loanId memberId receivedBy token amount paymentMethod repaymentNumber
 *
 * repaymentNumber is supplied by the caller (rather than generated here
 * via RepaymentModel::generateRepaymentNumber()) deliberately: that
 * generator's own MAX(seq)+1 read is a separate, pre-existing race
 * unrelated to this stage's submission_token mechanism (out of scope --
 * see Section 17 of the stage spec, which already anticipates
 * repayment_number cannot serve as the dedup key). Supplying distinct
 * numbers isolates the one guarantee this test exists to prove:
 * uk_repayments_submission_token specifically.
 */
[, $dbName, $loanId, $memberId, $receivedBy, $token, $amount, $paymentMethod, $repaymentNumber] = $argv;

if ($dbName === '' || $dbName === 'empower_db') {
    fwrite(STDOUT, "ERROR:refused-empty-or-production-db\n");
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
require_once APP_PATH  . '/models/RepaymentModel.php';
require_once APP_PATH  . '/services/JournalService.php';

$repayModel = new RepaymentModel();

try {
    $id = $repayModel->recordRepayment([
        'loan_id' => (int)$loanId, 'member_id' => (int)$memberId,
        'repayment_number' => $repaymentNumber,
        'amount_paid' => (float)$amount, 'penalty_paid' => 0,
        'payment_method' => $paymentMethod, 'payment_date' => date('Y-m-d'),
        'payment_type' => 'installment', 'received_by' => (int)$receivedBy,
        'submission_token' => $token,
    ]);
    fwrite(STDOUT, "SUCCESS:{$id}\n");
    exit(0);
} catch (DuplicateRepaymentSubmissionException $e) {
    fwrite(STDOUT, "DUPLICATE\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, "ERROR:" . $e->getMessage() . "\n");
    exit(1);
}
