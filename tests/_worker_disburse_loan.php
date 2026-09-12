<?php
/**
 * Concurrency-test worker process for
 * tests/test_stage_loan_schedule_remediation.php (Scenario DUP2).
 *
 * Launched as a separate PHP CLI process (via proc_open) so its
 * transaction genuinely overlaps a sibling process's transaction at the
 * database level -- proving LoanModel::disburse()'s schedule generation
 * (now wrapped in one transaction with the journal posting and status
 * change, backed by uk_installments_loan_seq) is actually race-safe, not
 * merely safe against two sequential calls from the same process.
 *
 * argv: dbName loanId approverId disbursementMethod
 */
[, $dbName, $loanId, $approverId, $method] = $argv;

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
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/LoanProductModel.php';
require_once APP_PATH  . '/services/JournalService.php';

$loanModel = new LoanModel();

try {
    $result = $loanModel->disburse((int)$loanId, (int)$approverId, $method);
    fwrite(STDOUT, "SUCCESS:" . $result['entry_number'] . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, "REJECTED:" . $e->getMessage() . "\n");
    exit(0);
}
