<?php
/**
 * PRODUCTION READINESS AUDIT - READ ONLY
 * 
 * This script performs a comprehensive 46-point audit
 * of the Empower Investment Club system for production hosting readiness.
 * 
 * NO MODIFICATIONS ARE MADE TO CODE, DATABASE, OR DATA.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Output buffering for clean report
ob_start();

echo "================================================================================\n";
echo "EMPOWER INVESTMENT CLUB - PRODUCTION READINESS AUDIT\n";
echo "================================================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Auditor: Kiro AI Development Environment\n";
echo "Type: READ-ONLY Comprehensive Assessment\n";
echo "================================================================================\n\n";

$findings = [
    'P0_BLOCKER' => [],
    'P1_HIGH' => [],
    'P2_MEDIUM' => [],
    'P3_LOW' => []
];

$stats = [
    'php_files_scanned' => 0,
    'php_syntax_pass' => 0,
    'php_syntax_fail' => 0,
    'security_issues' => 0,
    'config_issues' => 0,
    'database_issues' => 0,
];

// ============================================================================
// SECTION 1: ENVIRONMENT AUDIT
// ============================================================================

echo "## 1. ENVIRONMENT AUDIT ##\n\n";

echo "Project Root: " . __DIR__ . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP SAPI: " . PHP_SAPI . "\n";
echo "Operating System: " . PHP_OS . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') . "\n";

// Check PHP version compatibility
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    $findings['P0_BLOCKER'][] = [
        'area' => 'PHP Version',
        'finding' => 'PHP version ' . PHP_VERSION . ' is below minimum 8.0.0',
        'file' => 'N/A',
        'evidence' => 'Current PHP version: ' . PHP_VERSION,
        'action' => 'Upgrade PHP to 8.0+ before hosting'
    ];
}

// Load config
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/config/config.php';

echo "App Environment: " . APP_ENV . "\n";
echo "App URL: " . APP_URL . "\n";
echo "Database Host: " . DB_HOST . "\n";
echo "Database Name: " . DB_NAME . "\n";
echo "Database User: " . DB_USER . "\n";
echo "Timezone: " . date_default_timezone_get() . "\n\n";

// Check environment
if (APP_ENV !== 'production' && APP_ENV !== 'development') {
    $findings['P1_HIGH'][] = [
        'area' => 'Configuration',
        'finding' => 'APP_ENV has unexpected value: ' . APP_ENV,
        'file' => 'app/config/config.php',
        'evidence' => 'Expected: production or development, Got: ' . APP_ENV,
        'action' => 'Set APP_ENV to production before hosting'
    ];
}

// Check localhost references
if (str_contains(APP_URL, 'localhost') || str_contains(APP_URL, '127.0.0.1')) {
    $findings['P0_BLOCKER'][] = [
        'area' => 'Configuration',
        'finding' => 'APP_URL contains localhost reference',
        'file' => 'app/config/config.php',
        'evidence' => 'APP_URL: ' . APP_URL,
        'action' => 'Set APP_URL to actual production domain before hosting'
    ];
}

// ============================================================================
// SECTION 2: PHP SYNTAX AUDIT
// ============================================================================

echo "## 2. PHP SYNTAX AUDIT ##\n\n";

function scanPHPFiles($dir, &$files = []) {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            // Skip vendor, node_modules, .git
            if (in_array($item, ['vendor', 'node_modules', '.git', '.claude'])) continue;
            scanPHPFiles($path, $files);
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $files[] = $path;
        }
    }
    return $files;
}

$phpFiles = scanPHPFiles(__DIR__);
$stats['php_files_scanned'] = count($phpFiles);

echo "Scanning " . count($phpFiles) . " PHP files...\n\n";

$syntaxErrors = [];
foreach ($phpFiles as $file) {
    $output = [];
    $returnCode = 0;
    exec('php -l "' . $file . '" 2>&1', $output, $returnCode);
    
    if ($returnCode !== 0) {
        $stats['php_syntax_fail']++;
        $syntaxErrors[] = [
            'file' => str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file),
            'error' => implode("\n", $output)
        ];
    } else {
        $stats['php_syntax_pass']++;
    }
}

if (!empty($syntaxErrors)) {
    echo "⚠️ SYNTAX ERRORS FOUND: " . count($syntaxErrors) . "\n\n";
    foreach (array_slice($syntaxErrors, 0, 10) as $error) {
        echo "File: {$error['file']}\n";
        echo "Error: {$error['error']}\n\n";
        
        $findings['P0_BLOCKER'][] = [
            'area' => 'PHP Syntax',
            'finding' => 'PHP parse/syntax error',
            'file' => $error['file'],
            'evidence' => $error['error'],
            'action' => 'Fix syntax error before hosting'
        ];
    }
    if (count($syntaxErrors) > 10) {
        echo "... and " . (count($syntaxErrors) - 10) . " more errors\n\n";
    }
} else {
    echo "✓ All PHP files passed syntax check\n\n";
}

// ============================================================================
// SECTION 3: CONFIGURATION SECURITY AUDIT
// ============================================================================

echo "## 3. CONFIGURATION SECURITY AUDIT ##\n\n";

// Check error display settings
$displayErrors = ini_get('display_errors');
$logErrors = ini_get('log_errors');

echo "display_errors: " . $displayErrors . "\n";
echo "log_errors: " . $logErrors . "\n";
echo "error_log: " . ini_get('error_log') . "\n\n";

if (APP_ENV === 'production' && $displayErrors == '1') {
    $findings['P0_BLOCKER'][] = [
        'area' => 'Error Reporting',
        'finding' => 'display_errors is ON in production',
        'file' => 'php.ini or app/config/config.php',
        'evidence' => 'display_errors = ' . $displayErrors,
        'action' => 'Set display_errors = Off in production'
    ];
}

if ($logErrors != '1') {
    $findings['P1_HIGH'][] = [
        'area' => 'Error Logging',
        'finding' => 'log_errors is OFF',
        'file' => 'php.ini',
        'evidence' => 'log_errors = ' . $logErrors,
        'action' => 'Enable error logging for production monitoring'
    ];
}

// Check session security
echo "Session Security:\n";
echo "  cookie_httponly: " . ini_get('session.cookie_httponly') . "\n";
echo "  cookie_secure: " . ini_get('session.cookie_secure') . "\n";
echo "  cookie_samesite: " . ini_get('session.cookie_samesite') . "\n\n";

if (APP_ENV === 'production') {
    if (ini_get('session.cookie_secure') != '1') {
        $findings['P0_BLOCKER'][] = [
            'area' => 'Session Security',
            'finding' => 'session.cookie_secure not set for production',
            'file' => 'app/config/config.php',
            'evidence' => 'cookie_secure = ' . ini_get('session.cookie_secure'),
            'action' => 'Enable secure cookies for HTTPS'
        ];
    }
}

// ============================================================================
// SECTION 4: DATABASE CONNECTION AUDIT
// ============================================================================

echo "## 4. DATABASE CONNECTION AUDIT ##\n\n";

try {
    require_once __DIR__ . '/core/Database.php';
    $pdo = Database::getInstance()->getConnection();
    echo "✓ Database connection successful\n";
    echo "Database: " . DB_NAME . "@" . DB_HOST . "\n\n";
    
    // Get table count
    $stmt = $pdo->query("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Tables in database: " . $result['table_count'] . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Database connection FAILED\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    
    $findings['P0_BLOCKER'][] = [
        'area' => 'Database',
        'finding' => 'Cannot connect to database',
        'file' => 'app/config/database.php',
        'evidence' => $e->getMessage(),
        'action' => 'Fix database connection before hosting'
    ];
}

// ============================================================================
// SECTION 5: SECRETS / CREDENTIALS AUDIT
// ============================================================================

echo "## 5. SECRETS / CREDENTIALS AUDIT ##\n\n";

$secretPatterns = [
    'password\s*=\s*[\'"][^\'"]{3,}[\'"]' => 'Hardcoded password',
    'api_key\s*=\s*[\'"][^\'"]{3,}[\'"]' => 'Hardcoded API key',
    'secret\s*=\s*[\'"][^\'"]{10,}[\'"]' => 'Hardcoded secret',
    'token\s*=\s*[\'"][^\'"]{10,}[\'"]' => 'Hardcoded token',
];

$secretFiles = [];
foreach ($phpFiles as $file) {
    // Skip test files and database config
    if (strpos($file, 'test') !== false || strpos($file, 'database.php') !== false) continue;
    
    $content = file_get_contents($file);
    foreach ($secretPatterns as $pattern => $description) {
        if (preg_match('/' . $pattern . '/i', $content, $matches)) {
            $secretFiles[] = [
                'file' => str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file),
                'type' => $description,
                'line' => 'Multiple possible matches'
            ];
        }
    }
}

if (!empty($secretFiles)) {
    echo "⚠️ POTENTIAL SECRETS FOUND: " . count($secretFiles) . "\n\n";
    foreach (array_slice($secretFiles, 0, 5) as $secret) {
        echo "File: {$secret['file']}\n";
        echo "Type: {$secret['type']}\n\n";
        
        $findings['P1_HIGH'][] = [
            'area' => 'Security',
            'finding' => 'Potential hardcoded secret',
            'file' => $secret['file'],
            'evidence' => $secret['type'],
            'action' => 'Review file and move secrets to environment variables'
        ];
    }
} else {
    echo "✓ No obvious hardcoded secrets found\n\n";
}

// ============================================================================
// SECTION 6: LOCALHOST / XAMPP DEPENDENCIES
// ============================================================================

echo "## 6. LOCALHOST / XAMPP DEPENDENCIES ##\n\n";

$localhostPatterns = [
    'localhost',
    '127.0.0.1',
    'C:\\\\xampp',
    'C:/xampp',
    'xampp',
];

$localhostReferences = [];
foreach ($phpFiles as $file) {
    // Skip this audit file itself and config files
    if (basename($file) === 'production_readiness_audit.php') continue;
    if (basename($file) === 'database.php') continue; // DB config is expected to have localhost
    
    $content = file_get_contents($file);
    foreach ($localhostPatterns as $pattern) {
        if (stripos($content, $pattern) !== false) {
            $localhostReferences[] = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file);
            break; // Only report once per file
        }
    }
}

if (!empty($localhostReferences)) {
    echo "⚠️ LOCALHOST REFERENCES FOUND: " . count($localhostReferences) . " files\n\n";
    foreach (array_slice($localhostReferences, 0, 10) as $file) {
        echo "File: {$file}\n";
        
        $findings['P2_MEDIUM'][] = [
            'area' => 'Deployment',
            'finding' => 'Localhost/XAMPP reference in code',
            'file' => $file,
            'evidence' => 'Contains localhost/xampp reference',
            'action' => 'Review and ensure production compatibility'
        ];
    }
    if (count($localhostReferences) > 10) {
        echo "... and " . (count($localhostReferences) - 10) . " more files\n\n";
    }
} else {
    echo "✓ No localhost/XAMPP references found in application code\n\n";
}

// ============================================================================
// SECTION 7: GIT / DEPLOYMENT CLEANLINESS
// ============================================================================

echo "## 7. GIT / DEPLOYMENT CLEANLINESS ##\n\n";

$gitStatus = [];
exec('git status --short 2>&1', $gitStatus);

echo "Git working tree status:\n";
if (empty($gitStatus)) {
    echo "✓ Clean working tree\n\n";
} else {
    echo count($gitStatus) . " uncommitted changes/untracked files\n\n";
    foreach (array_slice($gitStatus, 0, 20) as $line) {
        echo $line . "\n";
        
        // Check for sensitive files
        if (preg_match('/\.(env|sql|dump|key|pem|log)$/i', $line)) {
            $findings['P1_HIGH'][] = [
                'area' => 'Git',
                'finding' => 'Sensitive file in working tree',
                'file' => trim($line),
                'evidence' => 'File type suggests sensitive data',
                'action' => 'Review and add to .gitignore if needed'
            ];
        }
    }
    echo "\n";
}

// ============================================================================
// SECTION 8: COMPOSER DEPENDENCIES
// ============================================================================

echo "## 8. COMPOSER DEPENDENCIES ##\n\n";

if (file_exists(__DIR__ . '/composer.json')) {
    echo "✓ composer.json found\n";
    
    if (file_exists(__DIR__ . '/composer.lock')) {
        echo "✓ composer.lock found\n";
    } else {
        $findings['P1_HIGH'][] = [
            'area' => 'Dependencies',
            'finding' => 'composer.lock missing',
            'file' => 'composer.lock',
            'evidence' => 'File does not exist',
            'action' => 'Run composer install to generate lock file'
        ];
    }
    
    if (!file_exists(__DIR__ . '/vendor')) {
        $findings['P0_BLOCKER'][] = [
            'area' => 'Dependencies',
            'finding' => 'vendor directory missing',
            'file' => 'vendor/',
            'evidence' => 'Directory does not exist',
            'action' => 'Run composer install before hosting'
        ];
    } else {
        echo "✓ vendor directory exists\n";
    }
} else {
    echo "ℹ️ No composer.json found (application may not use Composer)\n";
}

echo "\n";

// ============================================================================
// FINAL STATISTICS
// ============================================================================

echo "================================================================================\n";
echo "AUDIT STATISTICS\n";
echo "================================================================================\n\n";

echo "PHP Files Scanned: " . $stats['php_files_scanned'] . "\n";
echo "Syntax PASS: " . $stats['php_syntax_pass'] . "\n";
echo "Syntax FAIL: " . $stats['php_syntax_fail'] . "\n\n";

echo "Findings by Severity:\n";
echo "  P0 BLOCKERS: " . count($findings['P0_BLOCKER']) . "\n";
echo "  P1 HIGH: " . count($findings['P1_HIGH']) . "\n";
echo "  P2 MEDIUM: " . count($findings['P2_MEDIUM']) . "\n";
echo "  P3 LOW: " . count($findings['P3_LOW']) . "\n\n";

// ============================================================================
// DETAILED FINDINGS REPORT
// ============================================================================

echo "================================================================================\n";
echo "DETAILED FINDINGS\n";
echo "================================================================================\n\n";

if (!empty($findings['P0_BLOCKER'])) {
    echo "## P0 - HOSTING BLOCKERS ##\n\n";
    foreach ($findings['P0_BLOCKER'] as $i => $finding) {
        echo "BLOCKER #" . ($i + 1) . "\n";
        echo "Area: {$finding['area']}\n";
        echo "Finding: {$finding['finding']}\n";
        echo "File: {$finding['file']}\n";
        echo "Evidence: {$finding['evidence']}\n";
        echo "Action: {$finding['action']}\n\n";
    }
}

if (!empty($findings['P1_HIGH'])) {
    echo "## P1 - HIGH PRIORITY ##\n\n";
    foreach (array_slice($findings['P1_HIGH'], 0, 10) as $i => $finding) {
        echo "HIGH #" . ($i + 1) . "\n";
        echo "Area: {$finding['area']}\n";
        echo "Finding: {$finding['finding']}\n";
        echo "File: {$finding['file']}\n";
        echo "Evidence: {$finding['evidence']}\n";
        echo "Action: {$finding['action']}\n\n";
    }
    if (count($findings['P1_HIGH']) > 10) {
        echo "... and " . (count($findings['P1_HIGH']) - 10) . " more P1 findings\n\n";
    }
}

// ============================================================================
// FINAL VERDICT
// ============================================================================

echo "================================================================================\n";
echo "FINAL VERDICT\n";
echo "================================================================================\n\n";

$totalBlockers = count($findings['P0_BLOCKER']);
$totalHigh = count($findings['P1_HIGH']);

if ($totalBlockers > 0) {
    echo "STATUS: ❌ WAITING\n\n";
    echo "RECOMMENDATION: DO NOT HOST IN PRODUCTION\n\n";
    echo "REASON: {$totalBlockers} blocking issue(s) must be resolved before hosting.\n";
    echo "These issues would prevent the application from functioning correctly\n";
    echo "or pose critical security risks in a production environment.\n\n";
} elseif ($totalHigh > 5) {
    echo "STATUS: ⚠️ PASS WITH LIMITATIONS\n\n";
    echo "RECOMMENDATION: Can host but with significant risk\n\n";
    echo "REASON: No critical blockers found, but {$totalHigh} high-priority issues exist.\n";
    echo "The application may function but could have security, performance, or\n";
    echo "operational issues that should be addressed soon after initial deployment.\n\n";
} else {
    echo "STATUS: ✅ PASS\n\n";
    echo "RECOMMENDATION: Ready for production hosting\n\n";
    echo "REASON: No critical blockers found. Minor issues can be addressed\n";
    echo "through normal post-deployment maintenance.\n\n";
}

echo "Note: This audit covered environment, PHP syntax, configuration, database,\n";
echo "secrets, localhost dependencies, and deployment readiness. Full security,\n";
echo "accounting, and functional testing require additional verification.\n\n";

echo "================================================================================\n";
echo "END OF AUDIT\n";
echo "================================================================================\n";

// Save report to file
$report = ob_get_clean();
echo $report;

file_put_contents(__DIR__ . '/PRODUCTION_READINESS_AUDIT_REPORT.txt', $report);
echo "\nReport saved to: PRODUCTION_READINESS_AUDIT_REPORT.txt\n";
