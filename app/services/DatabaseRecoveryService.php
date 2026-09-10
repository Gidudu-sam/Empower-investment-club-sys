<?php
/**
 * DatabaseRecoveryService — SA-6 (Database Recovery & Restore, 2026-09).
 *
 * The ONLY code path in this stage that touches a database destructively
 * (DROP/CREATE DATABASE, table import) -- and it is hard-restricted to
 * exactly one fixed database name, never derived from any request input.
 *
 * Absolute safety gate (SA-6 brief Section 2/9/28): every mutating method
 * calls assertIsolatedTarget() first, which throws unless the target
 * equals self::ISOLATED_TEST_DB exactly. There is no parameter, route, or
 * code path anywhere in this class that can make that target
 * `empower_db` or any other string -- the constant is never interpolated
 * from a variable that traces back to user input.
 *
 * Backups are only ever resolved through BackupInventoryService::getById()
 * -- a fixed id, never a raw filesystem path from the browser. The mysql
 * CLI client is invoked with escapeshellarg() around the only two dynamic
 * values (the fixed database name constant and the resolved absolute
 * backup path), never with any other request-controlled string.
 *
 * SystemIntegrityService (SA-3) is run against the restored database
 * WITHOUT modifying it: this class spawns a fresh PHP CLI subprocess that
 * defines DB_NAME as the isolated test database BEFORE bootstrapping --
 * the exact same pattern this project's own test_sa*.php suites already
 * use against empower_db_ivms_test. SystemIntegrityService's source is
 * untouched.
 *
 * Final SA-6 hardening (2026-09-10): this class no longer authenticates
 * as MySQL root anywhere. Every mysql.exe invocation and the isolated
 * PDO connection below all use a dedicated "empower_recovery" account,
 * restricted by MySQL itself to SELECT/INSERT/UPDATE/DELETE/CREATE/DROP/
 * ALTER/INDEX/REFERENCES/CREATE TEMPORARY TABLES/LOCK TABLES on the one
 * named schema self::ISOLATED_TEST_DB -- never empower_db, never mysql,
 * never any other schema (verified directly: this account cannot connect
 * to either, and cannot create any other database). The credential is
 * not hardcoded in this file: the mysql.exe shell-outs authenticate via
 * --defaults-extra-file pointing at a MySQL options file stored outside
 * the web-reachable document root, so the password never appears on the
 * command line/process list either; the PDO connection loads the same
 * account's credential from a sibling file in that same external
 * location. If that location is unreachable, connection/shell-outs fail
 * loudly (no silent fallback to any other account, root included).
 */
class DatabaseRecoveryService
{
    public const ISOLATED_TEST_DB = 'empower_db_sa6_restore_test';
    public const PRODUCTION_DB = 'empower_db';
    private const MYSQL_BIN = 'C:\\xampp\\mysql\\bin\\mysql.exe';
    private const RECOVERY_CREDENTIALS_FILE = 'C:\\xampp\\empower_secrets\\recovery_credentials.php';
    private const RECOVERY_OPTIONS_FILE = 'C:\\xampp\\empower_secrets\\recovery.cnf';

    private PDO $productionDb;
    private BackupInventoryService $inventory;
    private DatabaseRecoveryModel $model;

    public function __construct()
    {
        $this->productionDb = Database::getInstance()->getConnection();
        $this->inventory = new BackupInventoryService();
        $this->model = new DatabaseRecoveryModel();
    }

    /** Throws unless $target is EXACTLY the one approved isolated database -- fails closed. */
    private function assertIsolatedTarget(string $target): void
    {
        if ($target !== self::ISOLATED_TEST_DB) {
            throw new RuntimeException("Refused: target database '{$target}' is not the approved SA-6 isolated database.");
        }
        if ($target === self::PRODUCTION_DB) {
            // Unreachable given the check above (the constants differ),
            // kept as an explicit, independent second assertion per the
            // brief's own "target_database !== production_database" gate.
            throw new RuntimeException('Refused: target database must never be the production database.');
        }
    }

    private function runProcess(string $cmd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['exit_code' => -1, 'output' => 'Failed to start process.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        return ['exit_code' => $exitCode, 'output' => trim($stdout . "\n" . $stderr)];
    }

    /**
     * Restores one backup (by inventory id, never a raw path) into the
     * fixed isolated test database. Recreates that database first (safe:
     * restricted to the isolated target only via assertIsolatedTarget()).
     */
    public function restoreBackup(string $backupId, int $userId): array
    {
        if (!file_exists(self::RECOVERY_OPTIONS_FILE)) {
            throw new RuntimeException('SA-6 recovery credential file is missing: ' . self::RECOVERY_OPTIONS_FILE . '. Refusing to fall back to any other account.');
        }

        $backup = $this->inventory->getById($backupId);
        if (!$backup) {
            throw new InvalidArgumentException('Unknown backup id.');
        }
        if ($backup['structural_status'] === 'INVALID') {
            $recoveryId = $this->model->record($backup, self::ISOLATED_TEST_DB, 'rejected', null, null, 'Backup failed static structural validation -- restore not attempted.', $userId);
            return ['status' => 'rejected', 'recovery_id' => $recoveryId, 'backup' => $backup];
        }

        $target = self::ISOLATED_TEST_DB;
        $this->assertIsolatedTarget($target);

        // Recreate the isolated database fresh -- restricted to $target,
        // which assertIsolatedTarget() above already proved is the one
        // approved SA-6 database, never production. --defaults-extra-file
        // authenticates as the dedicated empower_recovery account (never
        // root) without the password ever appearing on the command line.
        $defaultsFlag = '--defaults-extra-file=' . escapeshellarg(self::RECOVERY_OPTIONS_FILE);
        $recreate = $this->runProcess(
            '"' . self::MYSQL_BIN . '" ' . $defaultsFlag . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$target}`; CREATE DATABASE `{$target}`;") . ' 2>&1'
        );
        if ($recreate['exit_code'] !== 0) {
            $recoveryId = $this->model->record($backup, $target, 'failed', $recreate['exit_code'], $recreate['output'], 'Failed to (re)create the isolated test database.', $userId);
            return ['status' => 'failed', 'recovery_id' => $recoveryId, 'output' => $recreate['output']];
        }

        $import = $this->runProcess(
            '"' . self::MYSQL_BIN . '" ' . $defaultsFlag . ' ' . escapeshellarg($target) . ' < ' . escapeshellarg($backup['path']) . ' 2>&1'
        );

        $status = $import['exit_code'] === 0 ? 'restore_tested' : 'failed';
        $recoveryId = $this->model->record($backup, $target, $status, $import['exit_code'], $import['output'], $status === 'failed' ? 'mysql import returned a non-zero exit code.' : null, $userId);

        return ['status' => $status, 'recovery_id' => $recoveryId, 'output' => $import['output'], 'backup' => $backup, 'target' => $target];
    }

    /**
     * Opens a SEPARATE PDO connection to the isolated test database --
     * never the production singleton, and never DB_USER/DB_PASS (the
     * application's own account, scoped to SELECT/INSERT/UPDATE/DELETE on
     * empower_db only -- self::ISOLATED_TEST_DB is a different schema
     * outside that grant by design).
     *
     * Final SA-6 hardening (2026-09-10): no longer hardcoded to root
     * either. Loads the dedicated empower_recovery account's credential
     * from a file outside the web-reachable document root (the same
     * account the two mysql.exe shell-outs above authenticate as via
     * --defaults-extra-file). If that file is missing, this throws rather
     * than silently falling back to any other account.
     */
    private function isolatedConnection(): PDO
    {
        if (!file_exists(self::RECOVERY_CREDENTIALS_FILE)) {
            throw new RuntimeException('SA-6 recovery credential file is missing: ' . self::RECOVERY_CREDENTIALS_FILE . '. Refusing to fall back to any other account.');
        }
        require_once self::RECOVERY_CREDENTIALS_FILE;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, self::ISOLATED_TEST_DB, DB_CHARSET);
        return new PDO($dsn, EMPOWER_RECOVERY_DB_USER, EMPOWER_RECOVERY_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    /**
     * Independent verification of whatever is currently restored into
     * the isolated test database -- schema, data metrics, recovery point,
     * and (via subprocess) SystemIntegrityService's own diagnostics.
     */
    public function verifyRestoredDatabase(int $recoveryId): array
    {
        try {
            $db = $this->isolatedConnection();
        } catch (Throwable $e) {
            $this->model->updateVerification($recoveryId, 'failed', ['error' => 'Could not connect to the isolated database: ' . $e->getMessage()]);
            throw new RuntimeException('Could not connect to the isolated restored database: ' . $e->getMessage());
        }

        $result = ['schema' => [], 'data' => [], 'recovery_point' => [], 'integrity' => null];

        // -- Schema --
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $result['schema']['table_count'] = count($tables);
        $criticalPresent = [];
        $criticalMissing = [];
        foreach (['members', 'savings', 'member_savings_accounts', 'loans', 'loan_repayments', 'withdrawals', 'member_fees', 'journal_entries', 'journal_lines', 'accounts', 'users', 'roles', 'financial_years'] as $t) {
            if (in_array($t, $tables, true)) { $criticalPresent[] = $t; } else { $criticalMissing[] = $t; }
        }
        $result['schema']['critical_tables_present'] = $criticalPresent;
        $result['schema']['critical_tables_missing'] = $criticalMissing;

        $fkCount = (int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn();
        $result['schema']['foreign_key_count'] = $fkCount;
        $pkCount = (int)$db->query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'")->fetchColumn();
        $result['schema']['tables_with_primary_key'] = $pkCount;

        // -- Data metrics (row counts only where the table exists) --
        foreach (['members', 'savings', 'loans', 'loan_repayments', 'withdrawals', 'member_fees', 'journal_entries', 'journal_lines'] as $t) {
            if (in_array($t, $tables, true)) {
                $result['data'][$t] = (int)$db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            }
        }
        if (in_array('journal_lines', $tables, true)) {
            $totals = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch();
            $result['data']['journal_total_debit'] = (float)$totals['d'];
            $result['data']['journal_total_credit'] = (float)$totals['c'];
            $result['data']['journal_balanced'] = abs((float)$totals['d'] - (float)$totals['c']) < 0.01;
        }

        // -- Recovery point: latest transaction date per module, where present --
        $pointQueries = [
            'latest_journal_date' => "SELECT MAX(entry_date) FROM journal_entries",
            'latest_savings_date' => "SELECT MAX(transaction_date) FROM savings",
            'latest_loan_issue_date' => "SELECT MAX(issue_date) FROM loans",
            'latest_repayment_date' => "SELECT MAX(payment_date) FROM loan_repayments",
            'latest_withdrawal_date' => "SELECT MAX(withdrawal_date) FROM withdrawals",
        ];
        foreach ($pointQueries as $label => $sql) {
            $tableName = preg_replace('/.*FROM\s+(\w+)/i', '$1', $sql);
            if (!in_array($tableName, $tables, true)) { $result['recovery_point'][$label] = null; continue; }
            try {
                $result['recovery_point'][$label] = $db->query($sql)->fetchColumn() ?: null;
            } catch (Throwable $e) { $result['recovery_point'][$label] = null; }
        }

        // -- SA-5 correction infrastructure presence (informational only --
        //    a pre-SA-5 backup legitimately won't have it; not a defect) --
        $result['data']['sa5_corrections_table_present'] = in_array('corrections', $tables, true);

        // -- SystemIntegrityService, run against the isolated DB via a
        //    fresh subprocess (SA-3's own source is never touched) --
        $result['integrity'] = $this->runIntegrityCheckAgainstIsolatedDb();

        $overallStatus = empty($criticalMissing) && $result['data']['journal_balanced'] !== false ? 'verified' : 'failed';
        $this->model->updateVerification($recoveryId, $overallStatus, $result);

        return $result;
    }

    /** Spawns a CLI subprocess with DB_NAME redefined to the isolated database, exactly like this project's own test_sa*.php suites. */
    public function runIntegrityCheckAgainstIsolatedDb(): array
    {
        $script = ROOT_PATH . '/tmp_sa6_integrity_probe_' . uniqid() . '.php';
        $isolatedDb = addslashes(self::ISOLATED_TEST_DB);
        $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', '{$isolatedDb}');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
try {
    \$svc = new SystemIntegrityService();
    echo json_encode(['ok' => true, 'results' => \$svc->runAll()]);
} catch (Throwable \$e) {
    echo json_encode(['ok' => false, 'error' => \$e->getMessage()]);
}
PHP;
        file_put_contents($script, $body);
        $phpBinary = PHP_BINARY;
        $out = shell_exec('"' . $phpBinary . '" ' . escapeshellarg($script) . ' 2>&1');
        @unlink($script);

        $decoded = json_decode((string)$out, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Could not run SystemIntegrityService against the isolated database.', 'raw' => substr((string)$out, 0, 500)];
        }
        return $decoded;
    }

    public function inventory(): array { return $this->inventory->scan(); }
    public function recentRecoveries(int $limit = 50): array { return $this->model->recent($limit); }
    public function findRecovery(int $id): array|false { return $this->model->find($id); }
}
