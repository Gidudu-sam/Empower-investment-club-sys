<?php
/**
 * One-off data fix: uppercases first_name/last_name for every existing
 * member, per explicit instruction ("make the first name and last name
 * all appear in capital letters for uniformity ... do this for all
 * names in the system"). Going forward, MemberController::collectInput()
 * and MemberImportController::sanitizeRow() both now uppercase these
 * fields at write time (single Add/Edit form and bulk import
 * respectively), so this script only needs to run once for data that
 * already existed before that change.
 */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/../app');
define('CORE_PATH', __DIR__ . '/../core');

require_once CORE_PATH . '/Database.php';

$dryRun = in_array('--dry-run', $argv, true);
echo "=== TARGET DATABASE: " . DB_NAME . " (PRODUCTION) ===\n";
echo $dryRun ? "*** DRY RUN ***\n" : "*** LIVE RUN ***\n";

$db = Database::getInstance()->getConnection();
$rows = $db->query("SELECT id, member_number, first_name, last_name FROM members")->fetchAll();

$changed = 0; $unchanged = 0;
$stmt = $db->prepare("UPDATE members SET first_name = ?, last_name = ? WHERE id = ?");

foreach ($rows as $r) {
    $newFirst = mb_strtoupper($r['first_name'], 'UTF-8');
    $newLast  = mb_strtoupper($r['last_name'], 'UTF-8');
    if ($newFirst === $r['first_name'] && $newLast === $r['last_name']) { $unchanged++; continue; }

    $changed++;
    if ($dryRun) {
        echo "{$r['member_number']}: \"{$r['first_name']} {$r['last_name']}\" -> \"{$newFirst} {$newLast}\"\n";
        continue;
    }
    $stmt->execute([$newFirst, $newLast, $r['id']]);
}

echo "\nTotal members: " . count($rows) . "\n";
echo "Changed: {$changed}\n";
echo "Already uppercase: {$unchanged}\n";
