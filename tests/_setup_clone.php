<?php
/**
 * Clone Setup Script — Stage Loan Schedule Remediation
 * Creates a disposable test clone using only short-lived CLI processes.
 * No persistent PDO connections left open. Outputs the clone DB name on success.
 *
 * Usage: php _setup_clone.php
 * Outputs: CLONE:<dbname>  on success
 *          ERROR:<msg>     on failure
 */
set_time_limit(60);

$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$mysqlcli  = 'C:\\xampp\\mysql\\bin\\mysql.exe';
$cloneDb   = 'loansched_rem_' . date('Ymd_His');

// Tables we need in the clone (ordered by FK dependency)
$tables = [
    'roles','accounts','financial_years','fees','accounting_periods',
    'users','members','loan_applications',
    'loan_types','loan_product_settings','loan_product_rules','loan_interest_brackets',
    'journal_number_sequences','journal_entries','journal_lines','journal_entry_audit',
    'loans','loan_installments','loan_repayments','loan_penalties',
    'loan_weekly_interest','loan_weekly_savings',
    'business_loan_interest_payments','business_loan_weekly_savings',
    'member_savings_accounts','savings','activity_logs','notifications','member_fees',
];

// Reference tables whose DATA we copy (not just schema)
$refTables = [
    'roles','accounts','financial_years','fees','accounting_periods',
    'loan_types','loan_product_settings','loan_product_rules','loan_interest_brackets',
    'journal_number_sequences',
];

/** Run a CLI process, return [stdout, stderr, exit_code] */
function cli(array $argv, ?string $stdinFile = null): array {
    $desc = [
        0 => $stdinFile ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $desc, $pipes);
    if (!is_resource($proc)) return ['', 'proc_open failed', -1];
    if (!$stdinFile) fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$out, $err, $code];
}

$tmp = sys_get_temp_dir();

// ─── 1. Dump schema for each table individually ───────────────────────────────
$schemaSql = '';
foreach ($tables as $t) {
    [$out, $err, $code] = cli([
        $mysqldump, '--no-defaults',
        '-h', '127.0.0.1', '-P', '3306', '-u', 'root',
        '--no-data', '--skip-lock-tables', '--single-transaction',
        '--compact',   // suppress SET statements to reduce noise
        'empower_db', $t
    ]);
    if ($code !== 0 || strlen(trim($out)) < 20) {
        fwrite(STDERR, "WARN: Could not dump schema for {$t}: {$err}\n");
        continue;
    }
    // Strip FK constraints
    $out = preg_replace(
        '/,\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY\s+\([^)]+\)\s+REFERENCES\s+`[^`]+`\s*\([^)]+\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+\w+(?:\s+\w+)?){0,2}/i',
        '', $out
    );
    $schemaSql .= $out . "\n";
}

// ─── 2. Dump data for reference tables ────────────────────────────────────────
$dataSql = "SET foreign_key_checks=0;\n";
foreach ($refTables as $t) {
    [$out, $err, $code] = cli([
        $mysqldump, '--no-defaults',
        '-h', '127.0.0.1', '-P', '3306', '-u', 'root',
        '--no-create-info', '--skip-lock-tables', '--single-transaction',
        '--compact', '--insert-ignore',
        'empower_db', $t
    ]);
    if ($code !== 0) { fwrite(STDERR, "WARN: Could not dump data for {$t}: {$err}\n"); continue; }
    $dataSql .= $out . "\n";
}
// Minimal test rows
$dataSql .= "INSERT IGNORE INTO `users` (id,role_id,full_name,email,password_hash,is_active) VALUES (1,1,'Test Admin','admin@test.local','x',1);\n";
$dataSql .= "INSERT IGNORE INTO `members` (id,first_name,last_name,member_number,status) VALUES (1,'Test','Member','MEM-000001','active');\n";
$dataSql .= "SET foreign_key_checks=1;\n";

// ─── 3. Create clone DB ───────────────────────────────────────────────────────
[, $err, $code] = cli([
    $mysqlcli, '--no-defaults',
    '-h', '127.0.0.1', '-P', '3306', '-u', 'root',
    '-e', "CREATE DATABASE IF NOT EXISTS `{$cloneDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
]);
if ($code !== 0) { echo "ERROR:CREATE DB failed: {$err}\n"; exit(1); }

// ─── 4. Import schema ─────────────────────────────────────────────────────────
$schemaFile = $tmp . DIRECTORY_SEPARATOR . "{$cloneDb}_schema.sql";
file_put_contents($schemaFile, "SET foreign_key_checks=0;\n" . $schemaSql . "SET foreign_key_checks=1;\n");

[, $err, $code] = cli([
    $mysqlcli, '--no-defaults',
    '-h', '127.0.0.1', '-P', '3306', '-u', 'root', $cloneDb
], $schemaFile);
@unlink($schemaFile);
if ($code !== 0) { echo "ERROR:Schema import failed: {$err}\n"; exit(1); }

// ─── 5. Import reference data ─────────────────────────────────────────────────
$dataFile = $tmp . DIRECTORY_SEPARATOR . "{$cloneDb}_data.sql";
file_put_contents($dataFile, $dataSql);

[, $err, $code] = cli([
    $mysqlcli, '--no-defaults',
    '-h', '127.0.0.1', '-P', '3306', '-u', 'root', $cloneDb
], $dataFile);
@unlink($dataFile);
if ($code !== 0) { echo "ERROR:Data import failed: {$err}\n"; exit(1); }

// ─── 6. Verify ────────────────────────────────────────────────────────────────
[$out, , $code] = cli([
    $mysqlcli, '--no-defaults',
    '-h', '127.0.0.1', '-P', '3306', '-u', 'root',
    '-e', "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$cloneDb}' AND TABLE_NAME='loans'",
    $cloneDb
]);
if ($code !== 0 || !str_contains($out, '1')) {
    echo "ERROR:Verification failed — loans table not found in clone\n"; exit(1);
}

echo "CLONE:{$cloneDb}\n";
exit(0);
