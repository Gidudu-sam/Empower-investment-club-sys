<?php
/**
 * One-off follow-up: imports specific named rows the admin explicitly
 * asked for after reviewing the duplicate report, per instruction:
 *   "apart from the two duplicates [Evelyn Nakibuuka 9&46, Shamim
 *   Namukwaya 35&100 -- real same-person-twice cases, left untouched],
 *   add walusimbi and max-care it was intended, add david and walukga i
 *   will fix it from the other side even rovine and beatrice."
 *
 * Handling per row:
 *  - Akram Walusimbi (36): real distinct person, his own real NIN -- OK.
 *  - Max-care Drug Centre (108): shares Akram's NIN, and national_id is
 *    NOT NULL UNIQUE at the DB level -- cannot be created without a
 *    distinct real NIN. EXCLUDED, reported, not fabricated.
 *  - David Magunda (41) / Walukagga Jovan (44): share account number
 *    105105/25 in the file. Per "I will fix it from the other side",
 *    David keeps the file's literal number; Walukagga gets a
 *    system-generated placeholder (flagged) until the admin assigns his
 *    real one.
 *  - Rovine Namuleme (55) / Beatrice Auma (61): same pattern, sharing
 *    107805/25 -- Rovine keeps the literal number, Beatrice gets a
 *    placeholder.
 *  - Ambiguous DOB/join dates on these specific rows are resolved with
 *    the same per-column convention as the stragglers pass (DOB=M/D/Y,
 *    Join Date=D/M/Y).
 *  - David Magunda and Rovine Namuleme both also have NO usable phone
 *    number (Excel-corrupted, already blanked) -- phone is a required
 *    field and was NOT part of what the admin asked to fix here, so
 *    both are left blocked and reported rather than silently waived.
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

$csvPath = $argv[1] ?? '';
$dryRun  = in_array('--dry-run', $argv, true);
if ($csvPath === '' || !is_file($csvPath)) {
    fwrite(STDERR, "Usage: php run_smart_import_named_rows.php <csv-path> [--dry-run]\n");
    exit(1);
}
echo "=== TARGET DATABASE: " . DB_NAME . " (PRODUCTION) ===\n";
echo $dryRun ? "*** DRY RUN ***\n" : "*** LIVE RUN ***\n";

$controller = new MemberImportController();
$ref = new ReflectionClass($controller);
function call($controller, ReflectionClass $ref, string $method, array $args) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($controller, $args);
}
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

$mapped = [];
foreach ($rawRows as $r) $mapped[$r['row_num']] = call($controller, $ref, 'mapColumns', [$r]);

// Target rows only: 36 Akram Walusimbi, 108 Max-care (excluded, reported),
// 41 David Magunda, 44 Walukagga Jovan, 55 Rovine Namuleme, 61 Beatrice Auma
$targets = [36, 41, 44, 55, 61]; // 108 deliberately excluded -- see header comment
$rows = [];
foreach ($targets as $rn) {
    if (!isset($mapped[$rn])) { fwrite(STDERR, "Row {$rn} not found in file!\n"); exit(1); }
    $row = $mapped[$rn];
    $row['date_of_birth'] = forceResolveDate($row['date_of_birth'], 'mdy');
    $row['join_date']     = forceResolveDate($row['join_date'], 'dmy');
    $rows[$rn] = $row;
}

// The "other side" pair: the file's literal account number stays with
// the first-listed row of each colliding pair (David, Rovine); the
// second (Walukagga, Beatrice) is blanked here so the commit step below
// generates a fresh, real, unique number for them -- clearly flagged in
// the output as a placeholder the admin should replace once the real
// assignment is settled.
$rows[44]['account_number'] = '';
$rows[61]['account_number'] = '';

$validated = call($controller, $ref, 'validateRows', [array_values($rows)]);

$db = Database::getInstance()->getConnection();
$userId = 1;
$imported = 0; $stillBlocked = 0; $failed = 0;
$importedNames = []; $stillBlockedLog = []; $failedLog = [];

$db->beginTransaction();
try {
    foreach ($validated['rows'] as $row) {
        $flaggedPlaceholder = in_array($row['row_num'], [44, 61], true);

        if (($row['status'] ?? '') !== 'ready') {
            $stillBlocked++;
            $stillBlockedLog[] = "Row {$row['row_num']} ({$row['first_name']} {$row['last_name']}): {$row['error']}";
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
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> would use {$data['member_number']} (Acct {$data['account_number']}" . ($flaggedPlaceholder ? ' [PLACEHOLDER -- needs real number]' : '') . ")";
            continue;
        }

        $db->exec('SAVEPOINT import_row');
        try {
            $result = $memberModel->createWithCompulsoryAccount($data, $userId);
            $db->exec('RELEASE SAVEPOINT import_row');
            $imported++;
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> {$data['member_number']} (Acct {$data['account_number']}" . ($flaggedPlaceholder ? ' [PLACEHOLDER -- needs real number]' : '') . ", Savings {$result['account_number']})";
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
        (new MemberModel())->log($userId, 'members_imported', "Smart Import named-rows pass: {$imported} imported, {$stillBlocked} still blocked, {$failed} failed.");
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "FATAL, rolled back entirely: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n=== RESULT ===\n";
echo "Imported:      {$imported}\n";
echo "Still blocked: {$stillBlocked}\n";
echo "Failed:        {$failed}\n\n";
echo "--- Imported ---\n" . implode("\n", $importedNames) . "\n\n";
if ($stillBlockedLog) echo "--- Still blocked ---\n" . implode("\n", $stillBlockedLog) . "\n";
if ($failedLog) echo "--- Failed ---\n" . implode("\n", $failedLog) . "\n";

echo "\n--- Max-care Drug Centre (row 108) -- deliberately not attempted ---\n";
echo "Shares National ID CM0003210MV45H with Akram Walusimbi (row 36). national_id is\n";
echo "a NOT NULL UNIQUE column -- the database cannot hold two members with the same\n";
echo "NIN. Provide a distinct real NIN for Max-care and it can be added.\n";
