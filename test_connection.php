<?php
// Test database connection with detailed error output
echo "<h2>Database Connection Test</h2>";

// Load the config
require_once __DIR__ . '/app/config/database.php';

echo "<p><strong>Configuration:</strong></p>";
echo "Host: " . DB_HOST . "<br>";
echo "Port: " . DB_PORT . "<br>";
echo "Database: " . DB_NAME . "<br>";
echo "User: " . DB_USER . "<br>";
echo "Password: " . (DB_PASS === '' ? '(empty)' : '(set)') . "<br>";

echo "<hr>";
echo "<p><strong>Testing PDO Connection...</strong></p>";

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color: green; font-weight: bold;'>✓ Connection successful!</p>";
    
    // Test a simple query
    $stmt = $pdo->query("SELECT DATABASE() as current_db, VERSION() as version");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Current Database: <strong>" . htmlspecialchars($result['current_db']) . "</strong></p>";
    echo "<p>MySQL Version: <strong>" . htmlspecialchars($result['version']) . "</strong></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red; font-weight: bold;'>✗ Connection failed!</p>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Error Code:</strong> " . $e->getCode() . "</p>";
}

echo "<hr>";
echo "<p><strong>Testing mysqli Connection...</strong></p>";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($mysqli->connect_error) {
    echo "<p style='color: red; font-weight: bold;'>✗ MySQLi connection failed!</p>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($mysqli->connect_error) . "</p>";
    echo "<p><strong>Error Code:</strong> " . $mysqli->connect_errno . "</p>";
} else {
    echo "<p style='color: green; font-weight: bold;'>✓ MySQLi connection successful!</p>";
    echo "<p>Server Info: " . htmlspecialchars($mysqli->server_info) . "</p>";
    $mysqli->close();
}
