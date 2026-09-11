<?php
/**
 * One-off follow-up run: resolves the 71 rows skipped by the first Smart
 * Import pass, per explicit instruction:
 *   - "fix them yourself esp the date" -> ambiguous dates (both day/month
 *     <=12, no ordinal to disambiguate) are now force-resolved instead of
 *     left blank, using the convention each column already demonstrated
 *     in its own UNAMBIGUOUS cells in this same file: Date of Birth is
 *     M/D/Y (confirmed by e.g. "12/25/2001" only parsing as Dec 25), Join
 *     Date is D/M/Y (confirmed by the many ordinal-suffixed "13th.06.25"
 *     style entries). This is a one-off, explicit, per-column inference
 *     for THIS file only -- MemberImportController::normalizeDate()
 *     itself is NOT changed, so a future admin's ambiguous dates still
 *     get flagged for a human, not silently guessed.
 *   - "ignore the duplicates" -> any row whose only other problem(s)
 *     involve a duplicate/already-registered phone, NIN, or account
 *     number, or any NON-date validation problem (bad phone/email
 *     format, missing phone, field-too-long) is left alone entirely,
 *     not attempted here.
 *   - "the one missing a NIN just post her like that, she doesn't have
 *     one" -> Namutebi Mbaliisa (row 54) is special-cased: national_id
 *     is written as '' (the column is NOT NULL UNIQUE, so NULL isn't
 *     possible -- an empty string is the closest "no NIN" representation
 *     and is safe here because no other row currently holds '').
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
    fwrite(STDERR, "Usage: php run_smart_import_stragglers.php <csv-path> [--dry-run]\n");
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

// Forces a d1/d2/year date string into a specific day-month order,
// unlike the shipped normalizeDate() which deliberately refuses to guess.
// Only used here, for this one-off cleanup pass.
function forceResolveDate(string $raw, string $order): string {
    $raw = trim($raw);
    if ($raw === '' || DateTime::createFromFormat('Y-m-d', $raw) !== false) return $raw;
    $clean = preg_replace('/(\d)(st|nd|rd|th)/i', '$1', $raw);
    $clean = str_replace(['.', '-', ' '], '/', trim($clean));
    $clean = trim(preg_replace('#/+#', '/', $clean), '/');
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $clean, $m)) return $raw;
    [, $a, $b, $year] = $m;
    $a = (int)$a; $b = (int)$b;
    $year = strlen($year) === 2 ? (int)(((int)$year <= 30 ? '20' : '19') . $year) : (int)$year;
    [$month, $day] = $order === 'mdy' ? [$a, $b] : [$b, $a];
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) return $raw;
    $dt = DateTime::createFromFormat('!Y-n-j', "{$year}-{$month}-{$day}");
    if (!$dt || (int)$dt->format('j') !== $day) return $raw;
    return $dt->format('Y-m-d');
}

// ---- Parse the same corrected CSV, same as the first pass ----
$handle = fopen($csvPath, 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);
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

$mapped = array_map(fn($r) => call($controller, $ref, 'mapColumns', [$r]), $rawRows);

// ---- First, find out which rows are already 'ready' or already
// imported (skip those -- this script only targets the 71 stragglers) ----
$firstPass = call($controller, $ref, 'validateRows', [$mapped]);
$byRow = [];
foreach ($firstPass['rows'] as $r) $byRow[$r['row_num']] = $r;

$nonDateReasons = ['Duplicate', 'already registered', 'Phone missing', 'Invalid phone format',
    'Invalid email format', 'too long', 'Email already', 'NIN too', 'Invalid account number',
    'Account number already', 'First name', 'Last name', 'Name missing', 'Gender'];

$candidates = [];
$leftAlone = [];
foreach ($mapped as $row) {
    $status = $byRow[$row['row_num']]['status'] ?? '';
    if ($status === 'ready') continue; // already succeeded in the first pass

    $error = $byRow[$row['row_num']]['error'] ?? '';
    $isNamutebi = stripos($row['last_name'] ?? '', 'Namutebi Mbaliisa') !== false;

    $hasNonDateReason = false;
    foreach ($nonDateReasons as $reason) {
        if (str_contains($error, $reason)) { $hasNonDateReason = true; break; }
    }
    // The one explicit exception: Namutebi's "NIN missing" is allowed
    // through even though it matches no reason above by coincidence --
    // NIN missing isn't in $nonDateReasons, so this check is actually
    // for clarity/documentation, not a functional gate.
    if ($hasNonDateReason) { $leftAlone[] = "Row {$row['row_num']} ({$row['first_name']} {$row['last_name']}): left alone -- {$error}"; continue; }

    $candidates[] = ['row' => $row, 'isNamutebi' => $isNamutebi];
}

echo "Candidates for date-fix retry: " . count($candidates) . "\n";
echo "Left alone (duplicates / non-date issues, per instruction): " . count($leftAlone) . "\n\n";

// ---- Apply forced date resolution, then re-validate for real ----
$fixedRows = [];
foreach ($candidates as $c) {
    $row = $c['row'];
    $row['date_of_birth'] = forceResolveDate($row['date_of_birth'], 'mdy');
    $row['join_date']     = forceResolveDate($row['join_date'], 'dmy');
    $fixedRows[] = $row;
}

$revalidated = call($controller, $ref, 'validateRows', [$fixedRows]);

$db = Database::getInstance()->getConnection();
$userId = 1;
$imported = 0; $stillBlocked = 0; $failed = 0;
$importedNames = []; $stillBlockedLog = []; $failedLog = [];

$db->beginTransaction();
try {
    foreach ($revalidated['rows'] as $row) {
        $isNamutebi = stripos($row['last_name'] ?? '', 'Namutebi Mbaliisa') !== false;

        if (($row['status'] ?? '') !== 'ready') {
            // Namutebi's only remaining problem should be "NIN missing" --
            // that is the one exception we explicitly override per
            // instruction. Anything else that's still blocking her, or
            // any other still-blocked row, is left alone and reported.
            if ($isNamutebi && trim(str_replace('NIN missing', '', $row['error']), '; ') === '') {
                // falls through to import below with national_id forced to ''
            } else {
                $stillBlocked++;
                $stillBlockedLog[] = "Row {$row['row_num']} ({$row['first_name']} {$row['last_name']}): {$row['error']}";
                continue;
            }
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
            'national_id'          => $isNamutebi ? '' : $clean['national_id'],
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
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> would use {$data['member_number']} (DOB {$data['date_of_birth']}, Joined {$data['join_date']}, NIN '{$data['national_id']}')";
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
        (new MemberModel())->log($userId, 'members_imported', "Smart Import stragglers pass: {$imported} imported, {$stillBlocked} still blocked, {$failed} failed.");
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "FATAL, rolled back entirely: " . $e->getMessage() . "\n");
    exit(1);
}

echo "=== RESULT ===\n";
echo "Imported:        {$imported}\n";
echo "Still blocked:   {$stillBlocked}\n";
echo "Failed at write: {$failed}\n\n";

echo "--- Imported ---\n" . implode("\n", $importedNames) . "\n\n";
if ($stillBlockedLog) echo "--- Still blocked (real, non-date problem remains) ---\n" . implode("\n", $stillBlockedLog) . "\n\n";
if ($failedLog)  echo "--- Failed (write-time) ---\n" . implode("\n", $failedLog) . "\n\n";
if ($leftAlone)  echo "--- Left alone per instruction (duplicates / other) ---\n" . implode("\n", $leftAlone) . "\n";
