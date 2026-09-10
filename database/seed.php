<?php
/**
 * Seed script — run once from CLI or browser to create the default admin.
 * Usage (CLI): php database/seed.php
 */

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/core/Database.php';

$db = Database::getInstance()->getConnection();

$password = 'Admin@1234';
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $db->prepare("
    UPDATE `users`
    SET `password_hash` = ?
    WHERE `email` = 'admin@empower.local'
    LIMIT 1
");
$stmt->execute([$hash]);

if ($stmt->rowCount()) {
    echo "Admin password seeded successfully.\n";
    echo "Email   : admin@empower.local\n";
    echo "Password: {$password}\n";
    echo "Change this password immediately after first login!\n";
} else {
    echo "No rows updated — check that schema.sql has been imported first.\n";
}
