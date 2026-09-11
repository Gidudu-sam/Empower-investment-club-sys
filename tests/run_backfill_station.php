<?php
/**
 * One-off repair: MemberImportController::mapColumns() had a bug (fixed
 * in the same commit as this script) where the Station field's substring
 * fallback list was hardcoded to an empty array instead of
 * self::FIELD_MATCHERS['station'][1] -- since the file's actual header
 * ("Station (Workplace/School/Organization)") never exact-matched the
 * plain "station" synonym, every single row's Station came back blank,
 * for every import pass run so far. This backfills it for every member
 * already created by this import, matched back to their original CSV
 * row primarily by National ID (falls back to account number, then
 * phone, for the handful of rows without a usable NIN match), updating
 * ONLY the station column, and ONLY where it's currently blank -- never
 * overwriting a value already present (e.g. anything corrected by hand
 * in the browser since).
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
    fwrite(STDERR, "Usage: php run_backfill_station.php <csv-path> [--dry-run]\n");
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
$byNinPhone = []; $byNin = []; $byAccount = []; $byPhone = [];
$rowNum = 1;
while (($data = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($data) < 3 || empty(array_filter($data))) continue;
    $row = ['row_num' => $rowNum];
    foreach ($headers as $i => $h) { $row[$h] = trim($data[$i] ?? ''); }
    $mapped = call($controller, $ref, 'mapColumns', [$row]);
    $coerced = call($controller, $ref, 'coerceRow', [$mapped]);
    $station = trim($coerced['station']);
    if ($station === '') continue; // nothing to backfill for this row anyway
    // NIN+phone composite first -- disambiguates the two confirmed
    // same-person-two-accounts pairs (Akram/Max-care, Acen Vicky/Beauty
    // Store) that intentionally share one NIN but have distinct phones;
    // plain-NIN keys below are a last-resort fallback only (last row
    // wins on a real collision, same limitation as before this fix).
    if ($coerced['national_id'] !== '') {
        $byNinPhone[$coerced['national_id'] . '|' . $coerced['phone']] = $station;
        $byNin[$coerced['national_id']] = $station;
    }
    if ($coerced['account_number'] !== '') $byAccount[$coerced['account_number']] = $station;
    if ($coerced['phone'] !== '') $byPhone[$coerced['phone']] = $station;
}
fclose($handle);

$db = Database::getInstance()->getConnection();
$members = $db->query("SELECT id, member_number, national_id, account_number, phone, station FROM members WHERE member_number >= 'EMP0020'")->fetchAll();

$updated = 0; $alreadySet = 0; $noMatch = 0;
$updates = [];
foreach ($members as $m) {
    if (trim((string)$m['station']) !== '') { $alreadySet++; continue; }

    $ninPhoneKey = $m['national_id'] . '|' . $m['phone'];
    $station = $byNinPhone[$ninPhoneKey] ?? $byNin[$m['national_id']] ?? $byAccount[$m['account_number']] ?? $byPhone[$m['phone']] ?? null;
    if ($station === null) { $noMatch++; echo "No CSV match for {$m['member_number']} (NIN '{$m['national_id']}')\n"; continue; }

    $updates[] = ['id' => (int)$m['id'], 'member_number' => $m['member_number'], 'station' => $station];
}

echo "\nMembers checked: " . count($members) . "\n";
echo "Already had a station (untouched): {$alreadySet}\n";
echo "To update: " . count($updates) . "\n";
echo "No matching CSV row found: {$noMatch}\n\n";

if ($dryRun) {
    foreach ($updates as $u) echo "{$u['member_number']}: would set station = \"{$u['station']}\"\n";
    exit(0);
}

$stmt = $db->prepare("UPDATE members SET station = ? WHERE id = ?");
foreach ($updates as $u) {
    $stmt->execute([$u['station'], $u['id']]);
    $updated++;
}
echo "Updated {$updated} member records.\n";
