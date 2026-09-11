<?php
/**
 * One-off run: imports the user's real, corrected member spreadsheet into
 * PRODUCTION empower_db, using the exact same code path as the live
 * MemberImportController (mapColumns/coerceRow/sanitizeRow/validateRows,
 * then createWithCompulsoryAccount() per ready row inside a per-row
 * SAVEPOINT) via reflection -- not a reimplementation, the real methods.
 *
 * Safety:
 *  - A full pre-import dump was already taken:
 *    backups/pre_smart_import_full_20260911_133810.sql
 *  - Any row that fails validation (missing/invalid gender, phone, NIN,
 *    duplicate within the file, duplicate against the DB, bad account
 *    number, etc.) is SKIPPED, not guessed or forced through.
 *  - One row is excluded up front by name match: the national_id cell for
 *    "Namutebi Mbaliisa" / "Leticia" is literally the text "I don't have"
 *    -- validateRows() only checks NIN length, not plausibility, so this
 *    would otherwise be accepted with that placeholder text as a
 *    permanent NIN. Excluded here so it doesn't silently get written;
 *    reported at the end so it can be added by hand once the admin has
 *    her real NIN.
 */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/../app');
define('CORE_PATH', __DIR__ . '/../core');
define('APP_URL', 'http://localhost/Empower');
define('APP_NAME', 'Empower');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Session.php';
require_once CORE_PATH . '/Controller.php';
require_once CORE_PATH . '/Autoloader.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/controllers/MemberImportController.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (PRODUCTION -- backup taken beforehand) ===\n";

$csvPath = $argv[1] ?? '';
$dryRun  = in_array('--dry-run', $argv, true);
if ($csvPath === '' || !is_file($csvPath)) {
    fwrite(STDERR, "Usage: php run_smart_import_production.php <csv-path> [--dry-run]\n");
    exit(1);
}
echo $dryRun ? "*** DRY RUN -- no database writes will be committed ***\n" : "*** LIVE RUN -- will write to production ***\n";

$controller = new MemberImportController();
$ref = new ReflectionClass($controller);
function call($controller, ReflectionClass $ref, string $method, array $args) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($controller, $args);
}

// ---- Parse the corrected CSV exactly like parseCSV() does ----
$handle = fopen($csvPath, 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle); // consume BOM if present, else rewind -- see MemberImportController::skipBom()
$headers = fgetcsv($handle);
$headers = array_map(fn($h) => strtolower(trim($h)), $headers);

$rawRows = [];
$rowNum = 1;
while (($data = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($data) < 3 || empty(array_filter($data))) continue;
    $row = ['row_num' => $rowNum];
    foreach ($headers as $i => $h) { $row[$h] = trim($data[$i] ?? ''); }
    $rawRows[] = $row;
}
fclose($handle);
echo "Parsed " . count($rawRows) . " data rows from CSV.\n";

// ---- Map columns (same private method the app uses) ----
$mapped = array_map(fn($r) => call($controller, $ref, 'mapColumns', [$r]), $rawRows);

// ---- Exclude the one known-unfixable row up front (NIN cell is
// literal placeholder text "I don't have" -- validateRows() only checks
// NIN length, not plausibility, so this would otherwise sail through) ----
$excluded = [];
$mapped = array_values(array_filter($mapped, function ($r) use (&$excluded) {
    $nin = strtolower($r['national_id'] ?? '');
    $isBadNin = str_contains($nin, "don't have") || str_contains($nin, 'dont have');
    if ($isBadNin) { $excluded[] = $r; return false; }
    return true;
}));
foreach ($excluded as $r) {
    echo "EXCLUDED before validation (row {$r['row_num']}): {$r['last_name']} {$r['first_name']} -- NIN cell is placeholder text, needs manual entry.\n";
}

// ---- Validate (same private method, same rules the review grid uses) ----
$validated = call($controller, $ref, 'validateRows', [$mapped]);
echo "Validation: {$validated['ready']} ready, {$validated['errors']} errors (of {$validated['total']}).\n\n";

$db = Database::getInstance()->getConnection();
$userId = 1; // System Administrator (confirmed real, active, role=admin)
$imported = 0; $failed = 0; $skippedInvalid = 0;
$importedNames = []; $failedLog = []; $skippedLog = [];

$db->beginTransaction();
try {
    foreach ($validated['rows'] as $row) {
        if (($row['status'] ?? '') !== 'ready') {
            $skippedInvalid++;
            $skippedLog[] = "Row {$row['row_num']} ({$row['first_name']} {$row['last_name']}): {$row['error']}";
            continue;
        }

        $sanitizeMethod = $ref->getMethod('sanitizeRow');
        $sanitizeMethod->setAccessible(true);
        $clean = $sanitizeMethod->invoke($controller, $row);

        $memberModel = new MemberModel();
        $data = [
            'member_number'        => $memberModel->generateMemberNumber(),
            'account_number'       => $clean['account_number'] ?: $memberModel->generateAccountNumber(),
            'first_name'           => $clean['first_name'],
            'last_name'            => $clean['last_name'],
            'gender'               => $clean['gender'],
            'date_of_birth'        => $clean['date_of_birth'] ?: null,
            'phone'                => $clean['phone'],
            'email'                => $clean['email'] ?: null,
            'national_id'          => $clean['national_id'],
            'station'              => $clean['station'] ?: null,
            'present_address'      => $clean['present_address'] ?: null,
            'home_address'         => $clean['home_address'] ?: null,
            'address'              => $clean['present_address'] ?: null,
            'next_of_kin_name'     => $clean['next_of_kin_name'] ?: null,
            'next_of_kin_address'  => $clean['next_of_kin_address'] ?: null,
            'next_of_kin_phone'    => $clean['next_of_kin_phone'] ?: null,
            'next_of_kin_relation' => $clean['next_of_kin_relation'] ?: null,
            'join_date'            => $clean['join_date'] ?: date('Y-m-d'),
            'status'               => 'active',
            'status_source'        => 'automatic',
            'created_by'           => $userId,
        ];

        if ($dryRun) {
            $imported++;
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> would use {$data['member_number']} (Acct {$data['account_number']})";
            continue;
        }

        $db->exec('SAVEPOINT import_row');
        try {
            $result = $memberModel->createWithCompulsoryAccount($data, $userId);
            $db->exec('RELEASE SAVEPOINT import_row');
            $imported++;
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> {$data['member_number']} (Acct {$data['account_number']}, Savings {$result['account_number']})";
        } catch (Throwable $e) {
            $db->exec('ROLLBACK TO SAVEPOINT import_row');
            $failed++;
            $failedLog[] = "Row {$row['row_num']} ({$data['first_name']} {$data['last_name']}): {$e->getMessage()}";
        }
    }

    if ($dryRun) {
        $db->rollBack();
    } else {
        $db->commit();
        (new MemberModel())->log($userId, 'members_imported', "Smart Import (production, one-off script): {$imported} imported, {$skippedInvalid} skipped invalid, {$failed} failed, " . count($excluded) . " excluded up front.");
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "FATAL, rolled back entirely: " . $e->getMessage() . "\n");
    exit(1);
}

echo "=== RESULT ===\n";
echo "Imported:        {$imported}\n";
echo "Skipped invalid: {$skippedInvalid}\n";
echo "Failed at write: {$failed}\n";
echo "Excluded upfront: " . count($excluded) . "\n\n";

echo "--- Imported ---\n" . implode("\n", $importedNames) . "\n\n";
if ($skippedLog) echo "--- Skipped (validation) ---\n" . implode("\n", $skippedLog) . "\n\n";
if ($failedLog)  echo "--- Failed (write-time) ---\n" . implode("\n", $failedLog) . "\n";
