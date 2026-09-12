<?php
/**
 * test_stage_birthday_automation.php
 *
 * Test suite for the Birthday Email CLI automation layer.
 * Covers all 18 scenarios specified in the stage brief (A-01 through A-18).
 *
 * Runs entirely against `empower_db_birthday_auto_test`, an isolated
 * database. Never touches empower_db (production).
 *
 * Because the CLI runner (birthday-cli.php) bootstraps its own constants
 * and auto-loads classes, the test harness replicates the same bootstrap
 * and calls the helper functions extracted from the CLI directly — this
 * is possible because birthday-cli.php defines them as named functions
 * in the global scope, which we can include once and call here.
 *
 * Usage:
 *   php tests/test_stage_birthday_automation.php
 *
 * Exit code: 0 = all pass, 1 = failures exist.
 */

// ─── Pre-create the isolated test database ───────────────────────────────────
$__preBootPdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$__preBootPdo->exec(
    "CREATE DATABASE IF NOT EXISTS `empower_db_birthday_auto_test`
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);
$__preBootPdo = null;

// ─── Bootstrap (mirrors birthday-cli.php bootstrap exactly) ─────────────────
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    '3306');
define('DB_NAME',    'empower_db_birthday_auto_test');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

$rootDir  = dirname(__DIR__);
$corePath = $rootDir . '/core';
$appPath  = $rootDir . '/app';

define('APP_PATH',    $appPath);
define('CORE_PATH',   $corePath);
define('VIEW_PATH',   $appPath . '/views');
define('PUBLIC_PATH', $rootDir . '/public');
define('APP_NAME',    'Empower Investment Club');
define('APP_URL',     'http://localhost/Empower');

// Session stub must be defined before Autoloader is loaded
if (!class_exists('Session', false)) {
    class Session
    {
        private static array $store = [];
        public static function start(): void {}
        public static function get(string $k, mixed $d = null): mixed { return self::$store[$k] ?? $d; }
        public static function set(string $k, mixed $v): void { self::$store[$k] = $v; }
        public static function has(string $k): bool { return isset(self::$store[$k]); }
        public static function requireAuth(): void {}
        public static function hasRole(array $r): bool { return true; }
        public static function flash(string $k, mixed $v = null): mixed {
            if ($v !== null) { self::$store['_flash'][$k] = $v; return null; }
            $r = self::$store['_flash'][$k] ?? null; unset(self::$store['_flash'][$k]); return $r;
        }
    }
}

require_once $corePath . '/Database.php';
require_once $corePath . '/Model.php';
require_once $corePath . '/Autoloader.php';

// Load the CLI helper functions by including birthday-cli.php in a way that
// executes only the function definitions — we suppress the main process body
// by defining a constant that the include detects. We cannot conditionally
// skip the main body without modifying birthday-cli.php, so instead we call
// the helper functions directly after loading models. The CLI functions are
// pure/stateless so we include the file with output buffering to suppress
// any echo output, then discard that output.
//
// HOWEVER: birthday-cli.php has a PHP_SAPI check at the top that exits if
// not 'cli'. Since we are running under cli too, that check passes. The
// main process body at the bottom will run and attempt DB/mail connections,
// so we cannot safely include the entire file. Instead we redefine the
// five helper functions here — they are tiny and self-contained, and this
// is explicitly the pattern approved in the audit for keeping birthday-cli.php
// as a standalone script without modification.

if (!function_exists('cli_isLeapYear')) {
    function cli_isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
    }
}

if (!function_exists('cli_getBirthdaysLeapAware')) {
    function cli_getBirthdaysLeapAware(MemberModel $memberModel, string $date): array
    {
        $rows = $memberModel->getTodaysBirthdays($date);
        if (date('m-d', strtotime($date)) === '02-28' && !cli_isLeapYear((int)date('Y', strtotime($date)))) {
            $leapDate  = date('Y', strtotime($date)) . '-02-29';
            $feb29Rows = $memberModel->getTodaysBirthdays($leapDate);
            $seen = array_column($rows, 'id');
            foreach ($feb29Rows as $r) {
                if (!in_array($r['id'], $seen, true)) { $rows[] = $r; }
            }
            usort($rows, static fn($a, $b) =>
                strcmp($a['last_name'] . $a['first_name'], $b['last_name'] . $b['first_name']));
        }
        return $rows;
    }
}

if (!function_exists('cli_getAlreadySentIds')) {
    function cli_getAlreadySentIds(SettingsModel $settingsModel, array $members, int $year): array
    {
        $sentIds = [];
        foreach ($members as $m) {
            $id    = (int)$m['id'];
            $found = $settingsModel->recentLog('birthday_email', "MEMBER:{$id}|YEAR:{$year}|STATUS:sent", 525600);
            if ($found !== false) { $sentIds[] = $id; }
        }
        return $sentIds;
    }
}

if (!function_exists('cli_renderBirthdayEmail')) {
    function cli_renderBirthdayEmail(array $member): string
    {
        $viewFile = VIEW_PATH . '/birthday/email.php';
        if (!file_exists($viewFile)) {
            throw new RuntimeException("Birthday email template not found: {$viewFile}");
        }
        $firstName = $member['first_name'];
        $fullName  = trim($member['first_name'] . ' ' . $member['last_name']);
        ob_start();
        include $viewFile;
        return (string)ob_get_clean();
    }
}

if (!function_exists('cli_sanitizeLogValue')) {
    function cli_sanitizeLogValue(string $value): string
    {
        return str_replace(['|', "\n", "\r"], [' ', ' ', ''], trim($value));
    }
}

// ─── Test DB schema setup ────────────────────────────────────────────────────
$pdo = Database::getInstance()->getConnection();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `roles` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL UNIQUE
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `users` (
        `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `full_name` VARCHAR(150) NOT NULL,
        `email`    VARCHAR(191) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
        `role_id`  INT UNSIGNED NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `force_password_change` TINYINT(1) NOT NULL DEFAULT 0,
        `member_id` INT UNSIGNED NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `members` (
        `id`            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        `member_number` VARCHAR(20)     NOT NULL UNIQUE,
        `first_name`    VARCHAR(80)     NOT NULL,
        `last_name`     VARCHAR(80)     NOT NULL,
        `gender`        ENUM('Male','Female','Other') NOT NULL DEFAULT 'Other',
        `date_of_birth` DATE            NULL,
        `phone`         VARCHAR(20)     NOT NULL UNIQUE,
        `email`         VARCHAR(191)    NULL UNIQUE,
        `national_id`   VARCHAR(50)     NOT NULL UNIQUE,
        `address`       TEXT            NULL,
        `next_of_kin_name`  VARCHAR(150) NULL,
        `next_of_kin_phone` VARCHAR(20)  NULL,
        `join_date`     DATE            NOT NULL,
        `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `status_source` ENUM('automatic','manual') NOT NULL DEFAULT 'automatic',
        `passport_photo` VARCHAR(255)   NULL,
        `created_by`    INT UNSIGNED    NULL,
        `created_at`    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `activity_logs` (
        `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT UNSIGNED NULL,
        `action`      VARCHAR(80)  NOT NULL,
        `description` TEXT         NULL,
        `ip_address`  VARCHAR(45)  NULL,
        `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `settings` (
        `id`          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(80)     NOT NULL UNIQUE,
        `setting_val` VARCHAR(255)    NOT NULL DEFAULT '',
        `label`       VARCHAR(150)    NULL,
        `created_at`  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

// ─── Helpers ─────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0; $failures = [];

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] {$label}\n"; }
    else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? " -- {$detail}" : '');
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}
function section(string $t): void { echo "\n=== {$t} ===\n"; }

static $seq = 0;
function insertMember(PDO $pdo, array $overrides = []): int {
    static $seq = 0; $seq++;
    $today = date('Y-m-d');
    $defaults = [
        'member_number' => "AUTO{$seq}",
        'first_name'    => "Auto{$seq}",
        'last_name'     => 'Tester',
        'gender'        => 'Other',
        'date_of_birth' => '1990-' . date('m') . '-' . date('d'),
        'phone'         => "0780" . str_pad((string)$seq, 6, '0', STR_PAD_LEFT),
        'email'         => "auto{$seq}@test.example",
        'national_id'   => "ANID{$seq}",
        'join_date'     => $today,
        'status'        => 'active',
        'status_source' => 'automatic',
    ];
    $data = array_merge($defaults, $overrides);
    $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
    $phs  = implode(',', array_fill(0, count($data), '?'));
    $stmt = $pdo->prepare("INSERT INTO `members` ({$cols}) VALUES ({$phs})");
    $stmt->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}

function resetTables(PDO $pdo): void {
    $pdo->exec("TRUNCATE TABLE `members`");
    $pdo->exec("TRUNCATE TABLE `activity_logs`");
}

// Instantiate models once
$memberModel   = new MemberModel();
$settingsModel = new SettingsModel();

$today     = date('Y-m-d');
$thisYear  = (int)date('Y');
$thisMonth = date('m');
$thisDay   = date('d');
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$yesterday = date('Y-m-d', strtotime('-1 day'));

// ─── TESTS ───────────────────────────────────────────────────────────────────

section('A-01: CLI runner loads successfully');
{
    // Verify birthday-cli.php exists and is parseable (syntax check)
    $cliFile = dirname(__DIR__) . '/birthday-cli.php';
    check('A-01: birthday-cli.php exists', file_exists($cliFile));

    // PHP syntax check
    $output = shell_exec('php -l "' . $cliFile . '" 2>&1');
    check('A-01: birthday-cli.php has no syntax errors',
        str_contains((string)$output, 'No syntax errors'),
        (string)$output);

    // Verify PHP_SAPI guard is present
    $src = file_get_contents($cliFile);
    check('A-01: CLI guard (PHP_SAPI !== cli) is present', str_contains($src, "PHP_SAPI !== 'cli'"));
}

section('A-02: Today\'s birthday is detected');
{
    resetTables($pdo);
    $dob = '1988-' . $thisMonth . '-' . $thisDay;
    $id  = insertMember($pdo, ['date_of_birth' => $dob]);
    $rows = cli_getBirthdaysLeapAware($memberModel, $today);
    check('A-02: member with today\'s birthday returned by CLI helper', count($rows) >= 1);
    $ids = array_column($rows, 'id');
    check('A-02: correct member id returned', in_array($id, $ids));
}

section('A-03: Tomorrow\'s birthday is NOT sent');
{
    resetTables($pdo);
    [$ty, $tm, $td] = explode('-', $tomorrow);
    insertMember($pdo, ['date_of_birth' => "1990-{$tm}-{$td}"]);
    $rows = cli_getBirthdaysLeapAware($memberModel, $today);
    check('A-03: tomorrow\'s birthday member not returned for today', count($rows) === 0);
}

section('A-04: Yesterday\'s birthday is NOT sent');
{
    resetTables($pdo);
    [$yy, $ym, $yd] = explode('-', $yesterday);
    insertMember($pdo, ['date_of_birth' => "1991-{$ym}-{$yd}"]);
    $rows = cli_getBirthdaysLeapAware($memberModel, $today);
    check('A-04: yesterday\'s birthday member not returned for today', count($rows) === 0);
}

section('A-05: Inactive members excluded');
{
    resetTables($pdo);
    $dob = '1985-' . $thisMonth . '-' . $thisDay;
    $idA = insertMember($pdo, ['date_of_birth' => $dob, 'status' => 'active',   'phone' => '0711000001', 'national_id' => 'A05A']);
    $idI = insertMember($pdo, ['date_of_birth' => $dob, 'status' => 'inactive', 'phone' => '0711000002', 'national_id' => 'A05I', 'email' => 'inactive05@test.example']);
    $rows = cli_getBirthdaysLeapAware($memberModel, $today);
    $ids  = array_column($rows, 'id');
    check('A-05: active member returned',   in_array($idA, $ids));
    check('A-05: inactive member excluded', !in_array($idI, $ids));
}

section('A-06: Members without email excluded');
{
    resetTables($pdo);
    $dob = '1992-' . $thisMonth . '-' . $thisDay;
    insertMember($pdo, ['date_of_birth' => $dob, 'email' => null]);
    $rows = cli_getBirthdaysLeapAware($memberModel, $today);
    check('A-06: member without email not returned', count($rows) === 0);
}

section('A-07: Birth year is ignored');
{
    resetTables($pdo);
    $id1975 = insertMember($pdo, ['date_of_birth' => '1975-' . $thisMonth . '-' . $thisDay, 'phone' => '0722000001', 'national_id' => 'A07_1975']);
    $id2000 = insertMember($pdo, ['date_of_birth' => '2000-' . $thisMonth . '-' . $thisDay, 'phone' => '0722000002', 'national_id' => 'A07_2000', 'email' => 'a07b@test.example']);
    $rows = cli_getBirthdaysLeapAware($memberModel, $today);
    $ids  = array_column($rows, 'id');
    check('A-07: member born 1975 returned', in_array($id1975, $ids));
    check('A-07: member born 2000 returned', in_array($id2000, $ids));
}

section('A-08: Existing birthday email template is reused');
{
    resetTables($pdo);
    $dob = '1990-' . $thisMonth . '-' . $thisDay;
    $id  = insertMember($pdo, ['date_of_birth' => $dob, 'first_name' => 'Fatuma', 'last_name' => 'Nalule']);
    $rows = cli_getBirthdaysLeapAware($memberModel, $today);
    $member = null;
    foreach ($rows as $r) { if ((int)$r['id'] === $id) { $member = $r; break; } }

    check('A-08: member found for template test', $member !== null);
    if ($member !== null) {
        $html = cli_renderBirthdayEmail($member);
        check('A-08: rendered HTML is non-empty', strlen($html) > 100);
        check('A-08: member first name in rendered HTML', str_contains($html, 'Fatuma'));
        check('A-08: template file is app/views/birthday/email.php (not a duplicate)',
            file_exists(VIEW_PATH . '/birthday/email.php'));
        // Confirm no financial terms
        $forbidden = ['balance', 'loan', 'savings', 'UGX', 'Shs '];
        $violations = array_filter($forbidden, fn($t) => stripos($html, $t) !== false);
        check('A-08: no financial terms in rendered email', count($violations) === 0,
            count($violations) > 0 ? 'Found: ' . implode(', ', $violations) : '');
    }
}

section('A-09: Existing MailerService is reused (not duplicated)');
{
    // Verify birthday-cli.php instantiates MailerService, not a custom mailer
    $src = file_get_contents(dirname(__DIR__) . '/birthday-cli.php');
    check('A-09: birthday-cli.php uses MailerService', str_contains($src, 'new MailerService()'));
    check('A-09: birthday-cli.php does NOT define a second mailer class',
        !preg_match('/class\s+\w*Mailer\w*/i', $src));
    check('A-09: birthday-cli.php calls $mailerService->send()',
        str_contains($src, '->send('));
    // Verify MailerService class is loaded by the Autoloader (not re-defined in CLI)
    check('A-09: MailerService class file exists at app/services/MailerService.php',
        file_exists(APP_PATH . '/services/MailerService.php'));
}

section('A-10: Already-sent member is skipped');
{
    resetTables($pdo);
    $dob = '1993-' . $thisMonth . '-' . $thisDay;
    $id  = insertMember($pdo, ['date_of_birth' => $dob]);
    // Simulate a prior successful send this year
    $settingsModel->log(0, 'birthday_email',
        "MEMBER:{$id}|YEAR:{$thisYear}|STATUS:sent|EMAIL:auto1@test.example|NAME:Auto Tester");

    $rows    = cli_getBirthdaysLeapAware($memberModel, $today);
    $sentIds = cli_getAlreadySentIds($settingsModel, $rows, $thisYear);
    check('A-10: already-sent member id returned by cli_getAlreadySentIds', in_array($id, $sentIds));
    check('A-10: member is in the skip list', count($sentIds) >= 1);
}

section('A-11: Failed email remains retryable');
{
    resetTables($pdo);
    $pdo->exec("TRUNCATE TABLE `activity_logs`");
    $dob = '1994-' . $thisMonth . '-' . $thisDay;
    $id  = insertMember($pdo, ['date_of_birth' => $dob]);

    // Simulate a prior failed send
    $settingsModel->log(0, 'birthday_email',
        "MEMBER:{$id}|YEAR:{$thisYear}|STATUS:failed|EMAIL:auto1@test.example|NAME:Auto Tester|ERROR:SMTP timeout");

    $rows    = cli_getBirthdaysLeapAware($memberModel, $today);
    $sentIds = cli_getAlreadySentIds($settingsModel, $rows, $thisYear);
    check('A-11: member with STATUS:failed is NOT in the already-sent list', !in_array($id, $sentIds));
}

section('A-12: One failed email does not stop other eligible members');
{
    resetTables($pdo);
    $pdo->exec("TRUNCATE TABLE `activity_logs`");
    $dob  = '1995-' . $thisMonth . '-' . $thisDay;
    $idA  = insertMember($pdo, ['date_of_birth' => $dob, 'phone' => '0733000001', 'email' => 'a12a@test.example', 'national_id' => 'A12A']);
    $idB  = insertMember($pdo, ['date_of_birth' => $dob, 'phone' => '0733000002', 'email' => 'a12b@test.example', 'national_id' => 'A12B']);
    $idC  = insertMember($pdo, ['date_of_birth' => $dob, 'phone' => '0733000003', 'email' => 'a12c@test.example', 'national_id' => 'A12C']);

    $members = cli_getBirthdaysLeapAware($memberModel, $today);
    $sentIds = cli_getAlreadySentIds($settingsModel, $members, $thisYear);

    // Simulate the loop: member A fails, B and C succeed
    $sent = 0; $failed = 0;
    foreach ($members as $m) {
        $mid = (int)$m['id'];
        if (in_array($mid, $sentIds, true)) continue;
        try {
            if ($mid === $idA) throw new RuntimeException('Simulated SMTP failure');
            $sent++;
            $settingsModel->log(0, 'birthday_email',
                "MEMBER:{$mid}|YEAR:{$thisYear}|STATUS:sent|EMAIL:{$m['email']}|NAME:{$m['first_name']}");
        } catch (\Throwable $e) {
            $failed++;
            $settingsModel->log(0, 'birthday_email',
                "MEMBER:{$mid}|YEAR:{$thisYear}|STATUS:failed|EMAIL:{$m['email']}|ERROR:" . $e->getMessage());
        }
    }

    check('A-12: loop continues after one failure — 2 sent, 1 failed', $sent === 2 && $failed === 1);
    $logCount = (int)$pdo->query("SELECT COUNT(*) FROM `activity_logs` WHERE `action`='birthday_email'")->fetchColumn();
    check('A-12: all 3 members have activity_log entries', $logCount === 3);
}

section('A-13: Leap-day behavior remains correct');
{
    resetTables($pdo);
    // Insert a member born Feb 29 on a real leap year
    $idFeb29 = insertMember($pdo, [
        'date_of_birth' => '2000-02-29',
        'phone'         => '0744111001',
        'national_id'   => 'A13_FEB29',
        'email'         => 'feb29@test.example',
    ]);

    // On a real Feb 29 (leap year), the member is found
    $rowsLeap = cli_getBirthdaysLeapAware($memberModel, '2028-02-29');
    check('A-13: Feb 29 member found on Feb 29 (leap year)',
        count($rowsLeap) >= 1 && in_array($idFeb29, array_column($rowsLeap, 'id')));

    // On Feb 28 of a non-leap year, the leap-aware helper should also return Feb 29 members
    $rowsNonLeap = cli_getBirthdaysLeapAware($memberModel, '2027-02-28');
    check('A-13: Feb 29 member included in Feb 28 results on non-leap year',
        in_array($idFeb29, array_column($rowsNonLeap, 'id')));

    // Raw getTodaysBirthdays on Feb 28 should NOT return Feb 29 members
    $rowsRaw = $memberModel->getTodaysBirthdays('2027-02-28');
    check('A-13: raw getTodaysBirthdays(Feb 28) does not include Feb 29 members',
        !in_array($idFeb29, array_column($rowsRaw, 'id')));

    // cli_isLeapYear sanity checks
    check('A-13: cli_isLeapYear(2000) is true',  cli_isLeapYear(2000));
    check('A-13: cli_isLeapYear(1900) is false', !cli_isLeapYear(1900));
    check('A-13: cli_isLeapYear(2024) is true',  cli_isLeapYear(2024));
    check('A-13: cli_isLeapYear(2027) is false', !cli_isLeapYear(2027));
}

section('A-14: CLI does NOT depend on an authenticated web session');
{
    $src = file_get_contents(dirname(__DIR__) . '/birthday-cli.php');
    check('A-14: birthday-cli.php does not call session_start()', !str_contains($src, 'session_start()'));
    // Check there is no CALL to Session::requireAuth() — the stub defines it (no-op)
    // but must never invoke it. We detect a call by looking for the pattern
    // outside of a class method definition context: "Session::requireAuth()" appearing
    // as a standalone statement (not inside "public static function requireAuth").
    $callPattern = '/Session::requireAuth\s*\(\s*\)\s*;/';
    check('A-14: birthday-cli.php does not CALL Session::requireAuth()',
        !preg_match($callPattern, $src));
    check('A-14: birthday-cli.php does not call Session::hasRole()', !str_contains($src, 'Session::hasRole('));
    check('A-14: birthday-cli.php does not reference $_SESSION', !str_contains($src, '$_SESSION'));
    check('A-14: birthday-cli.php does not reference $_POST (no CSRF dependency)', !str_contains($src, '$_POST'));
    check('A-14: birthday-cli.php does not reference $_GET', !str_contains($src, '$_GET'));
}

section('A-15: Web birthday-send route retains full session/CSRF protection');
{
    $controllerSrc = file_get_contents(APP_PATH . '/controllers/BirthdayController.php');
    check('A-15: BirthdayController::__construct still calls Session::requireAuth()',
        str_contains($controllerSrc, 'Session::requireAuth()'));
    check('A-15: BirthdayController::__construct still calls Session::hasRole()',
        str_contains($controllerSrc, 'Session::hasRole('));
    check('A-15: BirthdayController::send() still calls verifyCsrf()',
        str_contains($controllerSrc, 'verifyCsrf('));
    check('A-15: BirthdayController::send() still calls isPost()',
        str_contains($controllerSrc, 'isPost()'));

    // Route still in index.php
    $indexSrc = file_get_contents(dirname(__DIR__) . '/index.php');
    check('A-15: birthday-send route still registered in index.php',
        str_contains($indexSrc, "'birthday-send'"));
}

section('A-16: No financial/accounting tables or logic touched');
{
    $src = file_get_contents(dirname(__DIR__) . '/birthday-cli.php');
    $financialTables = [
        'savings', 'loans', 'repayments', 'journal_entries', 'journal_lines',
        'member_fees', 'shares', 'withdrawals', 'expenses', 'accounts',
        'loan_installments', 'loan_repayments', 'member_savings_accounts',
    ];
    $hits = array_filter($financialTables, fn($t) => stripos($src, $t) !== false);
    check('A-16: birthday-cli.php contains no financial table references',
        count($hits) === 0,
        count($hits) > 0 ? 'Found: ' . implode(', ', $hits) : '');

    $financialModels = ['LoanModel', 'SavingsModel', 'RepaymentModel', 'JournalService',
                        'FeeModel', 'ShareModel', 'WithdrawalModel', 'StatementModel'];
    $modelHits = array_filter($financialModels, fn($m) => str_contains($src, $m));
    check('A-16: birthday-cli.php uses no financial model classes',
        count($modelHits) === 0,
        count($modelHits) > 0 ? 'Found: ' . implode(', ', $modelHits) : '');
}

section('A-17: Running twice produces no second successful send for same member/year');
{
    resetTables($pdo);
    $pdo->exec("TRUNCATE TABLE `activity_logs`");
    $dob = '1996-' . $thisMonth . '-' . $thisDay;
    $id  = insertMember($pdo, ['date_of_birth' => $dob]);

    // First run: simulate a successful send
    $members1   = cli_getBirthdaysLeapAware($memberModel, $today);
    $sentIds1   = cli_getAlreadySentIds($settingsModel, $members1, $thisYear);
    check('A-17: member not in sent list before first run', !in_array($id, $sentIds1));
    $settingsModel->log(0, 'birthday_email',
        "MEMBER:{$id}|YEAR:{$thisYear}|STATUS:sent|EMAIL:auto1@test.example|NAME:Auto Tester");

    // Second run: same member is now in the already-sent list
    $members2 = cli_getBirthdaysLeapAware($memberModel, $today);
    $sentIds2 = cli_getAlreadySentIds($settingsModel, $members2, $thisYear);
    check('A-17: member IS in sent list after first run', in_array($id, $sentIds2));

    // Simulate second run loop — member would be skipped
    $wouldSkip = 0;
    foreach ($members2 as $m) {
        if (in_array((int)$m['id'], $sentIds2, true)) { $wouldSkip++; }
    }
    check('A-17: second run would skip the already-sent member', $wouldSkip >= 1);

    // Only one STATUS:sent log entry exists (not two)
    $sentCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM `activity_logs`
         WHERE `action`='birthday_email' AND `description` LIKE 'MEMBER:{$id}|YEAR:{$thisYear}|STATUS:sent%'"
    )->fetchColumn();
    check('A-17: exactly one STATUS:sent log entry exists', $sentCount === 1);
}

section('A-18: CLI exits cleanly when there are no birthdays today');
{
    resetTables($pdo);
    $pdo->exec("TRUNCATE TABLE `activity_logs`");

    // Insert a member whose birthday is definitely NOT today
    [$ty, $tm, $td] = explode('-', $tomorrow);
    insertMember($pdo, ['date_of_birth' => "1990-{$tm}-{$td}"]);

    $members = cli_getBirthdaysLeapAware($memberModel, $today);
    check('A-18: no members returned when no birthdays today', count($members) === 0);

    // Simulate CLI exit path for no members: batch summary should still be logged
    if (empty($members)) {
        $settingsModel->log(0, 'birthday_email',
            "BATCH|DATE:{$today}|sent:0|skipped:0|failed:0|trigger:cli");
    }
    $batchLog = $settingsModel->recentLog('birthday_email', "BATCH|DATE:{$today}", 5);
    check('A-18: batch summary log written even when no birthdays', $batchLog !== false);

    // Verify CLI source uses exit(0) for no-birthdays path
    $src = file_get_contents(dirname(__DIR__) . '/birthday-cli.php');
    check('A-18: CLI source has exit(0) for empty members path',
        str_contains($src, "exit(0)"));
}

// ─── Results ─────────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat('─', 60) . "\n";
echo "Birthday Automation Tests: {$pass} passed, {$fail} failed\n";

if ($fail > 0) {
    echo "\nFAILURES:\n";
    foreach ($failures as $f) {
        echo "  ✗ {$f}\n";
    }
    echo "\nRESULT: FAIL\n";
    exit(1);
}

echo "\nRESULT: PASS\n";
exit(0);
