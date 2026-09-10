<?php
/**
 * Stage 21-D — controller authorization/CSRF/workflow/hardening
 * regression suite. Self-dispatching: the orchestrator (no argv) builds
 * one disposable schema, then re-invokes THIS SAME FILE as subprocesses
 * (php.exe test_stage21d_....php <schema> <user_id> <action> ...) for
 * every scenario that needs its own process, because a role-gate denial
 * calls die(), which would otherwise terminate the whole suite. This is
 * the exact technique manually proven scenario-by-scenario during Stage
 * 21-D's own development; consolidated here into one reproducible file.
 */

require 'app/config/config.php';

if (isset($argv[1]) && $argv[1] !== '') {
    // ---- SUBPROCESS MODE: run exactly one controller action ----
    [$self, $schema, $userId, $action, $runId, $csrfMode] = array_pad($argv, 6, null);
    define('DB_NAME', $schema);
    chdir(__DIR__ . '/..');
    require 'tests/test_safety_guard.php';
    require 'core/Database.php';
    require 'core/Autoloader.php';

    Session::start();
    $_SESSION['user_id'] = (int)$userId;
    $_SESSION['last_activity'] = time();
    if ($csrfMode === 'valid') { Session::set('csrf_token', 'KNOWNTOKEN123'); }

    require_once 'app/models/UserModel.php';
    require_once 'app/models/AccountModel.php';
    require_once 'app/models/JournalEntryModel.php';
    require_once 'app/models/JournalLineModel.php';
    require_once 'app/services/JournalService.php';
    require_once 'app/services/LoanProvisioningService.php';
    require_once 'app/controllers/LoanProvisioningController.php';

    $controller = new LoanProvisioningController(); // constructor enforces the view gate
    switch ($action) {
        case 'view_index': $controller->index(); break;
        case 'calculate_form': $controller->calculateForm(); break;
        case 'finalize':
            $_POST['run_id'] = (int)$runId;
            $_POST['csrf_token'] = $csrfMode === 'valid' ? 'KNOWNTOKEN123' : ($csrfMode === 'invalid' ? 'WRONG-TOKEN' : '');
            $controller->finalize();
            break;
        case 'review':
            $_POST['run_id'] = (int)$runId;
            $_POST['csrf_token'] = $csrfMode === 'valid' ? 'KNOWNTOKEN123' : ($csrfMode === 'invalid' ? 'WRONG-TOKEN' : '');
            $controller->review();
            break;
        case 'report':
            $method = $runId; // reused arg slot: report method name
            ob_start(); $controller->$method(); $out = ob_get_clean();
            echo 'REPORT_BYTES:' . strlen($out) . "\n";
            break;
    }
    echo "SUBPROCESS_COMPLETED_NO_DENIAL\n";
    exit(0);
}

// ---- ORCHESTRATOR MODE ----
define('DB_NAME', 'stage21d_orchestrator_placeholder');
require 'tests/test_safety_guard.php';
require 'core/Database.php';
require 'core/Autoloader.php';

echo "=== STAGE 21-D PROVISIONING UI/CONTROLLER/HARDENING REGRESSION SUITE ===\n\n";
$pass = 0; $fail = 0;
function chk($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label" . ($detail ? " ($detail)" : '') . "\n"; $pass++; }
    else       { echo "  FAIL: $label" . ($detail ? " ($detail)" : '') . "\n"; $fail++; }
}

$phpBin = 'C:\\xampp\\php\\php.exe';
$thisFile = __FILE__;
function run(string $phpBin, string $thisFile, array $args): string {
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($thisFile);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
    $out = shell_exec($cmd . ' 2>&1');
    return $out ?? ('[NO OUTPUT FROM: ' . $cmd . ']');
}

$testSchema = 'stage21d_suite_' . date('His');
$pdoNoDb = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
$pdoNoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdoNoDb->exec("DROP DATABASE IF EXISTS `$testSchema`");
    $pdoNoDb->exec("CREATE DATABASE `$testSchema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mainConn = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testSchema . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $mainConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $prodPdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=empower_db;charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $prodPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mainConn->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['users','roles','members','loans','loan_installments','loan_repayments','accounts',
              'financial_years','accounting_periods','journal_entries','journal_lines',
              'journal_number_sequences','journal_entry_audit'] as $t) {
        $ddl = $prodPdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
        $mainConn->exec($ddl);
    }
    $mainConn->exec('SET FOREIGN_KEY_CHECKS=1');

    foreach (['database/loan_provisioning_stage21.sql', 'database/loan_provisioning_stage21d_hardening.sql'] as $file) {
        $rawSql = str_replace('USE `empower_db`;', '', file_get_contents($file));
        $clean = [];
        foreach (explode("\n", $rawSql) as $line) { if (preg_match('/^\s*--/', $line)) continue; $clean[] = $line; }
        foreach (array_filter(array_map('trim', explode(';', implode("\n", $clean)))) as $stmt) { if ($stmt !== '') $mainConn->exec($stmt); }
    }
    echo "Schema + provisioning migration + Stage 21-D hardening applied.\n\n";

    $roles = ['admin','treasurer','member','cashier','viewer','chairman','loans_officer','office_admin','system_admin'];
    foreach ($roles as $i => $r) {
        $mainConn->prepare("INSERT INTO roles (id, name, label) VALUES (?,?,?)")->execute([$i + 1, $r, ucfirst($r)]);
    }
    $uid = [];
    foreach ($roles as $i => $r) {
        $uid[$r] = 100 + $i;
        $mainConn->prepare("INSERT INTO users (id, role_id, full_name, email, password_hash, is_active) VALUES (?,?,?,?,?,1)")
            ->execute([$uid[$r], $i + 1, ucfirst($r), $r . '@t.local', 'x']);
    }
    $mainConn->exec("INSERT INTO users (id, role_id, full_name, email, password_hash, is_active) VALUES (200,1,'Second Admin','a2@t.local','x',1)");
    $mainConn->exec("INSERT INTO members (id, member_number, first_name, last_name, status) VALUES (1,'M001','T','M','active')");
    $mainConn->exec("INSERT INTO financial_years (id, name, start_date, end_date, status) VALUES (1,'FY','2026-01-01','2026-12-31','active')");
    $mainConn->exec("INSERT INTO accounting_periods (id, financial_year_id, name, start_date, end_date, status) VALUES (1,1,'Open Q','2026-07-01','2026-09-30','open'),(2,1,'Closed Q','2026-04-01','2026-06-30','closed')");
    $mainConn->exec("INSERT INTO accounts (id, code, name, type, subtype, normal_balance, is_system, is_active) VALUES (1,'1185','Allowance','asset','contra_asset','credit',1,1),(2,'5300','Provision Expense','expense','operating_expense','debit',1,1)");
    $mainConn->exec("INSERT INTO journal_number_sequences (prefix, last_number) VALUES ('JE',0)");
    $mainConn->exec("INSERT INTO loans (id, loan_number, member_id, loan_amount, total_payable, outstanding, issue_date, due_date, status) VALUES (1,'LNA',1,5000000,5000000,5000000,'2026-01-01','2027-01-01','active')");
    $mainConn->exec("INSERT INTO loan_installments (id, loan_id, installment_no, due_date, principal_due, interest_due, amount_due, amount_paid, remaining, status, is_grace_period) VALUES (1,1,1,'2026-08-16',500000,0,500000,0,500000,'overdue',0)");

    require_once 'app/models/AccountModel.php';
    require_once 'app/models/JournalEntryModel.php';
    require_once 'app/models/JournalLineModel.php';
    require_once 'app/services/JournalService.php';
    require_once 'app/services/LoanProvisioningService.php';
    $db2 = $mainConn; // reuse for a direct-service pre-population (no controller involved)
    // Temporarily point the app's Database singleton at this schema for the direct service call below.
    // (We are still in orchestrator process, DB_NAME was the placeholder; use a raw approach instead.)

    // Build fixture runs directly via SQL calls into the app service by
    // spawning one more short-lived subprocess dedicated to fixture setup,
    // reusing the exact same schema (avoids needing the singleton hack).
    // Written to a temp FILE rather than `php -r "..."` -- Windows shell
    // quoting through shell_exec() cannot reliably survive an inline
    // string containing nested double quotes.
    $fixtureScript = sys_get_temp_dir() . '/stage21d_fixture_' . uniqid() . '.php';
    file_put_contents($fixtureScript, "<?php\n"
        . "require 'app/config/config.php'; define('DB_NAME','$testSchema');\n"
        . "chdir(" . var_export(dirname(__DIR__), true) . ");\n"
        . "require 'tests/test_safety_guard.php'; require 'core/Database.php'; require 'core/Autoloader.php';\n"
        . "require_once 'app/models/AccountModel.php'; require_once 'app/models/JournalEntryModel.php';\n"
        . "require_once 'app/models/JournalLineModel.php'; require_once 'app/services/JournalService.php';\n"
        . "require_once 'app/services/LoanProvisioningService.php';\n"
        . "\$svc = new LoanProvisioningService(); \$db = Database::getInstance()->getConnection();\n"
        . "\$r1 = \$svc->calculateRun(1, '2026-09-30', 100);\n"
        . "\$db->exec(\"INSERT INTO loans (id, loan_number, member_id, loan_amount, total_payable, outstanding, issue_date, due_date, status) VALUES (2,'LNB',1,3000000,3000000,3000000,'2026-01-01','2027-01-01','active')\");\n"
        . "\$r2 = \$svc->calculateRun(1, '2026-09-30', 100); \$svc->finalizeRun(\$r2, 200);\n"
        . "\$db->exec(\"INSERT INTO loans (id, loan_number, member_id, loan_amount, total_payable, outstanding, issue_date, due_date, status) VALUES (3,'LNC',1,2000000,2000000,2000000,'2026-01-01','2027-01-01','active')\");\n"
        . "\$r3 = \$svc->calculateRun(1, '2026-09-30', 100);\n"
        . "echo json_encode(['r1'=>\$r1,'r2'=>\$r2,'r3'=>\$r3]);\n"
    );
    $fixtureOut = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($fixtureScript) . ' 2>&1');
    @unlink($fixtureScript);
    $fixture = json_decode(trim(substr($fixtureOut, strpos($fixtureOut, '{'))), true);
    chk('Fixture runs created (r1 calculated, r2 finalized, r3 calculated)', is_array($fixture) && isset($fixture['r1'], $fixture['r2'], $fixture['r3']), $fixtureOut);
    $r1 = $fixture['r1']; $r2 = $fixture['r2']; $r3 = $fixture['r3'];

    // ================================================================
    // AUTHORIZATION
    // ================================================================
    echo "\n--- Authorization ---\n";
    chk('cashier denied index view', str_contains(run($phpBin, $thisFile, [$testSchema, 103, 'view_index']), 'Access denied'));
    chk('loans_officer allowed index view', !str_contains(run($phpBin, $thisFile, [$testSchema, 106, 'view_index']), 'Access denied'));
    chk('cashier denied calculate form', str_contains(run($phpBin, $thisFile, [$testSchema, 103, 'calculate_form']), 'Access denied'));
    chk('loans_officer allowed calculate form', !str_contains(run($phpBin, $thisFile, [$testSchema, 106, 'calculate_form']), 'Access denied'));
    chk('loans_officer denied review', str_contains(run($phpBin, $thisFile, [$testSchema, 106, 'review', $r1, 'valid']), 'Access denied'));
    chk('CRITICAL: loans_officer denied finalize', str_contains(run($phpBin, $thisFile, [$testSchema, 106, 'finalize', $r1, 'valid']), 'Access denied'));

    // ================================================================
    // REVIEW WORKFLOW + MAKER-CHECKER + CSRF + DUPLICATE PREVENTION
    // ================================================================
    echo "\n--- Workflow / maker-checker / CSRF ---\n";
    run($phpBin, $thisFile, [$testSchema, 105, 'review', $r1, 'valid']); // chairman reviews r1
    $st = $mainConn->prepare("SELECT status,reviewed_by FROM loan_provisioning_runs WHERE id=?"); $st->execute([$r1]); $row = $st->fetch(PDO::FETCH_ASSOC);
    chk('chairman review succeeded (r1 -> reviewed)', $row['status'] === 'reviewed' && (int)$row['reviewed_by'] === 105);

    run($phpBin, $thisFile, [$testSchema, 100, 'finalize', $r1, 'valid']); // same actor as calculator (100)
    $st->execute([$r1]); $row = $st->fetch(PDO::FETCH_ASSOC);
    chk('maker-checker: same-actor finalize blocked (r1 still reviewed, not finalized)', $row['status'] === 'reviewed');

    run($phpBin, $thisFile, [$testSchema, 200, 'finalize', $r1, 'valid']); // different actor
    $st->execute([$r1]); $row = $st->fetch(PDO::FETCH_ASSOC);
    chk('different-actor finalize succeeded (r1 -> finalized)', $row['status'] === 'finalized');

    run($phpBin, $thisFile, [$testSchema, 101, 'finalize', $r1, 'valid']); // duplicate attempt
    $st->execute([$r1]); $row2 = $st->fetch(PDO::FETCH_ASSOC);
    chk('duplicate finalization prevented (r1 state unchanged)', $row2 === $row);

    run($phpBin, $thisFile, [$testSchema, 200, 'finalize', $r3, 'missing']);
    $st->execute([$r3]); $row = $st->fetch(PDO::FETCH_ASSOC);
    chk('missing CSRF rejected (r3 still calculated)', $row['status'] === 'calculated');

    run($phpBin, $thisFile, [$testSchema, 200, 'finalize', $r3, 'invalid']);
    $st->execute([$r3]); $row = $st->fetch(PDO::FETCH_ASSOC);
    chk('invalid CSRF rejected (r3 still calculated)', $row['status'] === 'calculated');

    run($phpBin, $thisFile, [$testSchema, 200, 'finalize', $r3, 'valid']);
    $st->execute([$r3]); $row = $st->fetch(PDO::FETCH_ASSOC);
    chk('valid CSRF accepted (r3 -> finalized)', $row['status'] === 'finalized');

    // ================================================================
    // REPORTS render without error, each in its own process
    // ================================================================
    echo "\n--- Reports ---\n";
    foreach (['reportSummary','reportByBucket','reportLoanLevel','reportMovement'] as $m) {
        $out = run($phpBin, $thisFile, [$testSchema, 100, 'report', $m]);
        chk("$m renders without error", str_contains($out, 'REPORT_BYTES:') && !str_contains($out, 'Access denied'), trim($out));
    }

    // ================================================================
    // ACTIVE POLICY UNIQUENESS HARDENING
    // ================================================================
    echo "\n--- Active policy uniqueness (Stage 21-C Finding 1 hardening) ---\n";
    try {
        $mainConn->exec("INSERT INTO loan_provisioning_policies (version, status, effective_date, approved_by_name, approved_by_role, authorization_reference, approval_date) VALUES ('PROV-002','active','2026-10-01','T','T','T',NOW())");
        chk('second active policy rejected by DB constraint', false, 'insert unexpectedly succeeded');
    } catch (PDOException $e) {
        chk('second active policy rejected by DB constraint', str_contains($e->getMessage(), 'uk_policy_active_singleton'), $e->getMessage());
    }
    $mainConn->exec("INSERT INTO loan_provisioning_policies (version, status, effective_date, approved_by_name, approved_by_role, authorization_reference, approval_date) VALUES ('PROV-000-DRAFT','draft','2026-01-01','T','T','T',NOW())");
    chk('inactive/draft policy insert still allowed', (int)$mainConn->query("SELECT COUNT(*) FROM loan_provisioning_policies WHERE status='draft'")->fetchColumn() === 1);
    chk('PROV-001 remains the sole active policy', $mainConn->query("SELECT COUNT(*) FROM loan_provisioning_policies WHERE status='active'")->fetchColumn() == 1);

    // ================================================================
    // FINALIZE ROW-LOCK CONCURRENCY (Stage 21-C Finding 2 hardening)
    // ================================================================
    echo "\n--- finalizeRun() row-lock concurrency (Stage 21-C Finding 2 hardening) ---\n";
    $connA = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testSchema . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $connA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connB = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testSchema . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $connB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connB->exec('SET SESSION innodb_lock_wait_timeout = 2');
    $connA->beginTransaction();
    $connA->query("SELECT * FROM loan_provisioning_runs WHERE id=$r2 FOR UPDATE")->fetch();
    $blocked = false;
    try {
        $connB->beginTransaction();
        $connB->query("SELECT * FROM loan_provisioning_runs WHERE id=$r2 FOR UPDATE")->fetch();
        $connB->rollBack();
    } catch (PDOException $e) {
        $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        if ($connB->inTransaction()) $connB->rollBack();
    }
    chk('a second connection is genuinely blocked while finalizeRun()-style lock is held', $blocked);
    $connA->rollBack();

    echo "\n=== RESULTS: $pass passed, $fail failed ===\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    try { $pdoNoDb->exec("DROP DATABASE IF EXISTS `$testSchema`"); echo "Disposable schema $testSchema dropped.\n"; } catch (Throwable $e2) {}
}
