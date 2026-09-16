#!/usr/bin/env php
<?php
/**
 * Create V2.1 Corrected Schema
 * Stage 2.1 — Apply defect corrections
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$schemaFile = 'database/stage2_1_v21_approval_schema_test.sql';

if (!file_exists($schemaFile)) {
    die("ERROR: Schema file not found: {$schemaFile}\n");
}

echo "Loading V2.1 corrected schema...\n";
$sql = file_get_contents($schemaFile);

try {
    $mysqli = new mysqli('127.0.0.1', 'root', '');
    
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }
    
    echo "[✓] Connected to MySQL\n";
    
    // Drop existing V21 database if it exists
    echo "Dropping existing empower_approval_test_v21 database if present...\n";
    $mysqli->query("DROP DATABASE IF EXISTS empower_approval_test_v21");
    echo "[✓] Cleaned up\n\n";
    
    // Parse SQL properly, handling multi-line triggers
    $sql = preg_replace('/--.*$/m', '', $sql); // Remove comments
    
    // Split by semicolon, but handle CREATE TRIGGER specially
    $statements = [];
    $current = '';
    $inTrigger = false;
    
    $lines = explode("\n", $sql);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Detect start of trigger
        if (stripos($line, 'CREATE TRIGGER') !== false) {
            if (!empty($current)) {
                $statements[] = $current;
            }
            $current = $line;
            $inTrigger = true;
            continue;
        }
        
        // Detect end of trigger
        if ($inTrigger && stripos($line, 'END;') !== false) {
            $current .= "\n" . $line;
            $statements[] = $current;
            $current = '';
            $inTrigger = false;
            continue;
        }
        
        // Normal line
        if ($inTrigger) {
            $current .= "\n" . $line;
        } else {
            $current .= ' ' . $line;
            if (substr(rtrim($line), -1) === ';') {
                $statements[] = $current;
                $current = '';
            }
        }
    }
    
    if (!empty($current)) {
        $statements[] = $current;
    }
    
    $successCount = 0;
    $lastOutput = [];
    $useStatement = null;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        // Track USE statements
        if (stripos($statement, 'USE') === 0) {
            $useStatement = $statement;
        }
        
        // Execute statement
        $result = $mysqli->query($statement);
        
        if ($result === false) {
            // If USE failed because DB doesn't exist yet, skip
            if (stripos($statement, 'USE') === 0 && strpos($mysqli->error, 'Unknown database') !== false) {
                continue;
            }
            throw new Exception("SQL Error: " . $mysqli->error . "\nStatement: " . substr($statement, 0, 200));
        }
        
        // After creating database, execute USE
        if (stripos($statement, 'CREATE DATABASE') === 0 && $useStatement) {
            $mysqli->query($useStatement);
        }
        
        // Capture output from SELECT statements
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $lastOutput[] = $row;
            }
            $result->free();
        }
        
        $successCount++;
    }
    
    echo "\n[✓] Executed {$successCount} SQL statements successfully\n";
    
    // Show last output (verification queries)
    if (!empty($lastOutput)) {
        echo "\nVerification Results:\n";
        foreach ($lastOutput as $row) {
            echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
        }
    }
    
    echo "\n[✓] V2.1 schema created successfully\n";
    echo "[✓] Database: empower_approval_test_v21\n";
    
    $mysqli->close();
    
} catch (Exception $e) {
    echo "\n[✗] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
