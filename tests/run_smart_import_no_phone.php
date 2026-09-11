<?php
/**
 * One-off follow-up: adds David Magunda (row 41) and Rovine Namuleme
 * (row 55), whose real Mobile No. was permanently lost to Excel's
 * scientific-notation export corruption. Per instruction ("you add them
 * am going to fix them from the browser"), each is posted with a
 * distinct placeholder in the phone field instead of a real number.
 *
 * `members.phone` is VARCHAR(20) NOT NULL UNIQUE -- unlike Namutebi's
 * blank NIN (a genuine "does not have one"), phone can't be left blank
 * for BOTH of these two without a uniqueness collision, and NULL isn't
 * allowed at all. Each gets a distinct, obviously-fake, traceable
 * placeholder ("NEEDS-PHONE-R41" / "NEEDS-PHONE-R55") that satisfies the
 * constraint and is unmistakable in the members list until corrected via
 * Edit Member in the browser.
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
    fwrite(STDERR, "Usage: php run_smart_import_no_phone.php <csv-path> [--dry-run]\n");
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

$handle = fopen($csvPath, 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);
$headers = fgetcsv($handle);
$headers = array_map(fn($h) => strtolower(trim($h)), $headers);
$mapped = [];
$rowNum = 1;
while (($data = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($data) < 3 || empty(array_filter($data))) continue;
    $row = ['row_num' => $rowNum];
    foreach ($headers as $i => $h) { $row[$h] = trim($data[$i] ?? ''); }
    $mapped[$rowNum] = call($controller, $ref, 'mapColumns', [$row]);
}
fclose($handle);

$targets = [41 => 'NEEDS-PHONE-R41', 55 => 'NEEDS-PHONE-R55'];
$db = Database::getInstance()->getConnection();
$userId = 1;
$imported = 0; $failed = 0;
$importedNames = []; $failedLog = [];

$db->beginTransaction();
try {
    foreach ($targets as $rn => $placeholderPhone) {
        $row = $mapped[$rn];
        $row['phone'] = $placeholderPhone; // bypass "Phone missing" -- explicit, per-row exception

        $coerceMethod = $ref->getMethod('coerceRow'); $coerceMethod->setAccessible(true);
        $coerced = $coerceMethod->invoke($controller, $row);
        $sanitizeMethod = $ref->getMethod('sanitizeRow'); $sanitizeMethod->setAccessible(true);
        $clean = $sanitizeMethod->invoke($controller, $coerced);

        $memberModel = new MemberModel();
        $data = [
            'member_number'        => $memberModel->generateMemberNumber(),
            'account_number'       => $clean['account_number'] ?: $memberModel->generateAccountNumber(),
            'first_name'           => $clean['first_name'],
            'last_name'            => $clean['last_name'],
            'gender'               => $clean['gender'],
            'date_of_birth'        => $clean['date_of_birth'] ?: null,
            'phone'                => $placeholderPhone,
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
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> would use {$data['member_number']} (Acct {$data['account_number']}, Phone {$data['phone']} [PLACEHOLDER])";
            continue;
        }

        $db->exec('SAVEPOINT import_row');
        try {
            $result = $memberModel->createWithCompulsoryAccount($data, $userId);
            $db->exec('RELEASE SAVEPOINT import_row');
            $imported++;
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> {$data['member_number']} (Acct {$data['account_number']}, Phone {$data['phone']} [PLACEHOLDER], Savings {$result['account_number']})";
        } catch (Throwable $e) {
            $db->exec('ROLLBACK TO SAVEPOINT import_row');
            $failed++;
            $failedLog[] = "Row {$rn} ({$data['first_name']} {$data['last_name']}): {$e->getMessage()}";
        }
    }

    if ($dryRun) {
        $db->rollBack();
    } else {
        $db->commit();
        (new MemberModel())->log($userId, 'members_imported', "Smart Import no-phone pass: {$imported} imported with placeholder phone, {$failed} failed.");
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "FATAL, rolled back entirely: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n=== RESULT ===\nImported: {$imported}\nFailed: {$failed}\n\n";
echo "--- Imported ---\n" . implode("\n", $importedNames) . "\n";
if ($failedLog) echo "\n--- Failed ---\n" . implode("\n", $failedLog) . "\n";
