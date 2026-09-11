<?php
/**
 * One-off follow-up: resolves the remaining ~28 rows with corrupted
 * phone numbers / bad emails, plus Max-care Drug Centre (now unblocked
 * by the National-ID-uniqueness policy relaxation just applied to
 * MemberImportController::validateRows()).
 *
 * Phone cleanup: many raw cells contain TWO real phone numbers separated
 * by "/", ",", ":" or the word "or" (e.g. "0703 929543/0777503237") --
 * the first one is extracted, nothing is invented. Cells that are
 * genuinely unrecoverable (Excel scientific-notation corruption, already
 * blanked in the corrected CSV) get the same explicit, traceable
 * placeholder pattern already used for David Magunda / Rovine Namuleme
 * ("NEEDS-PHONE-R<row>"), reported clearly, never silently guessed.
 *
 * Email cleanup: fixes mechanical typos (missing "@" before a known
 * domain, "gamil.com" -> "gmail.com", a stray "www." prefix, a doubled
 * domain from a copy-paste error) via pattern matching, not guessing.
 * Anything that still isn't a plausible email afterwards (e.g. a phone
 * number was typed into the email column) is blanked -- email has always
 * been an optional field, so this needs no rule relaxation at all.
 *
 * Explicitly excluded (per "ignore the duplicates", unchanged from
 * before): Evelyn Nakibuuka (rows 9, 46) and Shamim Namukwaya (rows 35,
 * 100) -- genuine same-person-twice duplicates.
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
    fwrite(STDERR, "Usage: php run_smart_import_cleanup2.php <csv-path> [--dry-run]\n");
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
function extractFirstPhone(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $parts = preg_split('/\s*(?:\/|,|;|\bor\b|:)\s*/i', $raw);
    foreach ($parts as $p) {
        $p = trim($p);
        if (preg_match('/^[+0-9][\d\s\-().]{6,19}$/', $p)) return $p;
    }
    return $raw;
}
function fixEmail(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $raw = preg_replace('/^www\.+/i', '', $raw);
    $raw = preg_replace('/gamil\.com$/i', 'gmail.com', $raw);
    if (preg_match('/^(.+?@(?:gmail|yahoo|hotmail)\.com)/i', $raw, $m)) $raw = $m[1];
    if (!str_contains($raw, '@') && preg_match('/^(.+?)(gmail\.com|yahoo\.com|hotmail\.com)$/i', $raw, $m)) {
        $raw = $m[1] . '@' . $m[2];
    }
    return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : '';
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

// Same-person-twice duplicates, left alone throughout (never addressed
// by the admin): Evelyn Nakibuuka (9,46), Shamim Namukwaya (35,100).
// Acen Vicky Eyomu / "Beauty Store" (81,83) is now CONFIRMED as an
// intentional two-accounts case, same as Akram/Max-care -- no longer
// excluded.
$EXCLUDED_ROWS = [9, 46, 35, 100];
$mapped = [];
foreach ($rawRows as $r) {
    if (in_array($r['row_num'], $EXCLUDED_ROWS, true)) continue;
    $mapped[$r['row_num']] = call($controller, $ref, 'mapColumns', [$r]);
}

// ---- Apply mechanical fixes (never fabricating data): dates, phones, emails ----
$candidateRows = [];
foreach ($mapped as $rn => $row) {
    $row['date_of_birth']  = forceResolveDate($row['date_of_birth'], 'mdy');
    $row['join_date']      = forceResolveDate($row['join_date'], 'dmy');
    $row['phone']          = extractFirstPhone($row['phone']);
    $row['next_of_kin_phone'] = extractFirstPhone($row['next_of_kin_phone']);
    $row['email']          = fixEmail($row['email']);
    $candidateRows[$rn] = $row;
}

// Both confirmed same-person-two-accounts pairs share an email with
// their first account -- email is optional, so the second account's
// copy is blanked rather than extending any uniqueness relaxation to
// email too.
if (isset($candidateRows[108])) $candidateRows[108]['email'] = ''; // Max-care <- Akram Walusimbi
if (isset($candidateRows[83]))  $candidateRows[83]['email']  = ''; // Beauty Store <- Acen Vicky Eyomu

$validated = call($controller, $ref, 'validateRows', [array_values($candidateRows)]);
$byRow = [];
foreach ($validated['rows'] as $r) $byRow[$r['row_num']] = $r;

// Rows whose ONLY remaining problem is "Phone missing" get the same
// explicit placeholder treatment already used for David/Rovine -- their
// number is unrecoverable (Excel corruption), not unresolved by choice.
foreach ($byRow as $rn => $r) {
    if (($r['status'] ?? '') !== 'ready' && trim($r['error']) === 'Phone missing') {
        $byRow[$rn]['phone'] = "NEEDS-PHONE-R{$rn}";
        $byRow[$rn]['status'] = 'ready_with_placeholder_phone';
    }
}

$db = Database::getInstance()->getConnection();
$userId = 1;
$imported = 0; $stillBlocked = 0; $failed = 0;
$importedNames = []; $stillBlockedLog = []; $failedLog = [];

$db->beginTransaction();
try {
    foreach ($byRow as $rn => $row) {
        if (!in_array($row['status'] ?? '', ['ready', 'ready_with_placeholder_phone'], true)) {
            $stillBlocked++;
            $stillBlockedLog[] = "Row {$rn} ({$row['first_name']} {$row['last_name']}): {$row['error']}";
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

        $flag = ($row['status'] === 'ready_with_placeholder_phone') ? ' [PLACEHOLDER PHONE]' : '';

        if ($dryRun) {
            $imported++;
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> would use {$data['member_number']} (Phone {$data['phone']}{$flag}, Email " . ($data['email'] ?: '(none)') . ")";
            continue;
        }

        $db->exec('SAVEPOINT import_row');
        try {
            $result = $memberModel->createWithCompulsoryAccount($data, $userId);
            $db->exec('RELEASE SAVEPOINT import_row');
            $imported++;
            $importedNames[] = "{$data['first_name']} {$data['last_name']} -> {$data['member_number']} (Phone {$data['phone']}{$flag}, Email " . ($data['email'] ?: '(none)') . ", Savings {$result['account_number']})";
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
        (new MemberModel())->log($userId, 'members_imported', "Smart Import cleanup pass 2: {$imported} imported, {$stillBlocked} still blocked, {$failed} failed.");
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "FATAL, rolled back entirely: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n=== RESULT ===\nImported: {$imported}\nStill blocked: {$stillBlocked}\nFailed: {$failed}\n\n";
echo "--- Imported ---\n" . implode("\n", $importedNames) . "\n\n";
if ($stillBlockedLog) echo "--- Still blocked ---\n" . implode("\n", $stillBlockedLog) . "\n";
if ($failedLog) echo "\n--- Failed ---\n" . implode("\n", $failedLog) . "\n";
