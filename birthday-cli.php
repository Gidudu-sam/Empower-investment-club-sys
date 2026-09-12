<?php
/**
 * birthday-cli.php — Automated Birthday Email CLI Runner
 *
 * Triggers the existing birthday email feature from the command line,
 * enabling daily automation via Windows Task Scheduler without any HTTP
 * session, CSRF token, or authenticated browser session.
 *
 * USAGE
 *   php birthday-cli.php
 *   php birthday-cli.php --dry-run          (list eligible members, send nothing)
 *   php birthday-cli.php --date=2026-09-12  (override target date; for testing)
 *
 * EXIT CODES
 *   0 — completed normally (0 or more sent, failures counted but non-fatal)
 *   1 — bootstrap or configuration failure (no emails attempted)
 *   2 — all sends failed (every eligible member resulted in a failure)
 *
 * SECURITY
 *   - Must be run from the project root: php birthday-cli.php
 *   - Never accepts a --send-to or --email flag; target addresses come
 *     exclusively from the members table via the existing eligibility query.
 *   - Does not expose member email addresses in console output.
 *   - Reads SMTP and DB credentials from the same external secrets files
 *     used by the web application (C:\xampp\empower_secrets\).
 *   - The web birthday-send route retains its full session/CSRF protection;
 *     this script is a separate, parallel trigger that does not weaken it.
 *
 * IDEMPOTENCY
 *   Running this script multiple times on the same day is safe.
 *   The existing activity_logs duplicate-send guard
 *   (MEMBER:{id}|YEAR:{year}|STATUS:sent) prevents re-delivery regardless
 *   of how many times the process executes.  Failed sends remain retryable.
 *
 * LEAP-DAY BEHAVIOR
 *   On Feb 28 of a non-leap year, members born on Feb 29 also qualify
 *   (observed on Feb 28).  This mirrors the web controller's behavior
 *   exactly (see cli_getBirthdaysLeapAware() below).
 *
 * AUDIT TRAIL
 *   Writes the same activity_logs rows as the web controller:
 *     action:      'birthday_email'
 *     description: 'MEMBER:{id}|YEAR:{year}|STATUS:sent|EMAIL:{email}|NAME:{name}'
 *                  'MEMBER:{id}|YEAR:{year}|STATUS:failed|...|ERROR:{msg}'
 *                  'BATCH|DATE:{date}|sent:{n}|skipped:{n}|failed:{n}|trigger:cli'
 *
 * SCHEDULER (Windows Task Scheduler)
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\Empower\birthday-cli.php
 *   Start in:  C:\xampp\htdocs\Empower
 *   Trigger:   Daily at 07:00
 *   Run as:    The Windows account with read access to empower_secrets\
 */

// ── CLI guard ────────────────────────────────────────────────────────────────
// Refuse to run if invoked through a web server to prevent accidental
// exposure of the CLI process via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.' . PHP_EOL);
}

// ── Parse arguments ──────────────────────────────────────────────────────────
$dryRun      = false;
$dateOverride = null;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $dateOverride = $m[1];
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}" . PHP_EOL);
        fwrite(STDERR, "Usage: php birthday-cli.php [--dry-run] [--date=YYYY-MM-DD]" . PHP_EOL);
        exit(1);
    }
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
// Load the application's own config/database/mail constants — identical to
// index.php's bootstrap sequence, so timezone, paths, and credentials match
// the web application exactly.

$rootDir = __DIR__;

require_once $rootDir . '/app/config/config.php';    // APP_NAME, VIEW_PATH, timezone, APP_URL
require_once $rootDir . '/app/config/database.php';  // DB_*, loads external DB credential
require_once $rootDir . '/app/config/mail.php';      // SMTP_*, loads external SMTP credential

if (file_exists($rootDir . '/vendor/autoload.php')) {
    require_once $rootDir . '/vendor/autoload.php';  // PHPMailer (Composer)
}

require_once $rootDir . '/core/Database.php';
require_once $rootDir . '/core/Model.php';
require_once $rootDir . '/core/Autoloader.php';  // spl_autoload_register for all classes

// Minimal Session stub — the Session class file will be found by the autoloader
// when referenced indirectly (e.g. via class inheritance scanning), but the
// stub ensures that if any code path calls Session::requireAuth() it is a
// safe no-op rather than a die() in CLI context.
// We define the stub only if the real Session has not already been loaded.
if (!class_exists('Session', false)) {
    class Session
    {
        private static array $store = [];
        public static function start(): void {}
        public static function get(string $k, mixed $d = null): mixed { return self::$store[$k] ?? $d; }
        public static function set(string $k, mixed $v): void { self::$store[$k] = $v; }
        public static function has(string $k): bool { return isset(self::$store[$k]); }
        public static function requireAuth(): void {} // no-op in CLI
        public static function hasRole(array $r): bool { return true; } // no-op in CLI
        public static function flash(string $k, mixed $v = null): mixed
        {
            if ($v !== null) { self::$store['_flash'][$k] = $v; return null; }
            $r = self::$store['_flash'][$k] ?? null;
            unset(self::$store['_flash'][$k]);
            return $r;
        }
    }
}

// ── Resolve target date ──────────────────────────────────────────────────────
// config.php already called date_default_timezone_set('Africa/Nairobi').
// All date() calls below are in the application's configured timezone.

$today = $dateOverride ?? date('Y-m-d');
$year  = (int)date('Y', strtotime($today));

// ── Instantiate models and services ─────────────────────────────────────────
$memberModel  = new MemberModel();
$settingsModel = new SettingsModel();
$mailerService = new MailerService();

// CLI user_id = 0 (system/automated process, no human operator session).
// activity_logs.user_id is nullable, so 0 is safe and distinguishes CLI
// sends from staff-triggered sends in the audit log.
$cliUserId = 0;

// ── Helpers (mirrors of BirthdayController private methods) ─────────────────
// These replicate the controller's private helpers exactly — same logic, same
// key formats.  Any change to the controller's duplicate-guard key or leap-day
// logic must be reflected here too.  Each function is documented with a
// reference to its counterpart in BirthdayController.

/**
 * Mirror of BirthdayController::isLeapYear().
 */
function cli_isLeapYear(int $year): bool
{
    return ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
}

/**
 * Mirror of BirthdayController::getTodaysBirthdaysLeapAware().
 *
 * On Feb 28 of a non-leap year, also fetches Feb 29 members (observed on Feb 28).
 */
function cli_getBirthdaysLeapAware(MemberModel $memberModel, string $date): array
{
    $rows = $memberModel->getTodaysBirthdays($date);

    if (date('m-d', strtotime($date)) === '02-28' && !cli_isLeapYear((int)date('Y', strtotime($date)))) {
        $leapDate  = date('Y', strtotime($date)) . '-02-29';
        $feb29Rows = $memberModel->getTodaysBirthdays($leapDate);
        $seen      = array_column($rows, 'id');
        foreach ($feb29Rows as $r) {
            if (!in_array($r['id'], $seen, true)) {
                $rows[] = $r;
            }
        }
        usort($rows, static fn($a, $b) =>
            strcmp($a['last_name'] . $a['first_name'], $b['last_name'] . $b['first_name']));
    }

    return $rows;
}

/**
 * Mirror of BirthdayController::getAlreadySentIds().
 *
 * Returns member IDs that already have a STATUS:sent log for this birthday year.
 * Only STATUS:sent blocks resending — STATUS:failed is retryable.
 *
 * @param  array<int,array<string,mixed>> $members
 * @return int[]
 */
function cli_getAlreadySentIds(SettingsModel $settingsModel, array $members, int $year): array
{
    $sentIds = [];
    foreach ($members as $m) {
        $id     = (int)$m['id'];
        $prefix = "MEMBER:{$id}|YEAR:{$year}|STATUS:sent";
        $found  = $settingsModel->recentLog('birthday_email', $prefix, 525600);
        if ($found !== false) {
            $sentIds[] = $id;
        }
    }
    return $sentIds;
}

/**
 * Mirror of BirthdayController::buildEmailHtml() + Controller::renderToString().
 *
 * Renders app/views/birthday/email.php with the member's name.
 * No financial data is injected.
 */
function cli_renderBirthdayEmail(array $member): string
{
    $viewFile = VIEW_PATH . '/birthday/email.php';
    if (!file_exists($viewFile)) {
        throw new RuntimeException("Birthday email template not found: {$viewFile}");
    }
    $firstName = $member['first_name'];
    $fullName  = trim($member['first_name'] . ' ' . $member['last_name']);
    // $member itself is also available inside the template
    ob_start();
    include $viewFile;
    return (string)ob_get_clean();
}

/**
 * Mirror of BirthdayController::sanitizeLogValue().
 *
 * Strips pipe chars and newlines from log strings to keep the pipe-delimited
 * description format intact and parseable in the history view.
 */
function cli_sanitizeLogValue(string $value): string
{
    return str_replace(['|', "\n", "\r"], [' ', ' ', ''], trim($value));
}

// ── Main process ─────────────────────────────────────────────────────────────

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Empower Birthday Email CLI" . PHP_EOL;
echo "[{$timestamp}] Target date : {$today}" . ($dateOverride ? ' (override)' : '') . PHP_EOL;
echo "[{$timestamp}] Mode        : " . ($dryRun ? 'DRY-RUN (no emails will be sent)' : 'LIVE') . PHP_EOL;

// Check SMTP configuration before doing any work
if (!$dryRun && !$mailerService->isConfigured()) {
    fwrite(STDERR, "[{$timestamp}] ERROR: SMTP is not configured (missing credentials)." . PHP_EOL);
    fwrite(STDERR, "               Check C:\\xampp\\empower_secrets\\mail_credentials.php" . PHP_EOL);
    exit(1);
}

// Fetch eligible members (active, non-null DOB, non-null email, today's birthday)
$members = cli_getBirthdaysLeapAware($memberModel, $today);
echo "[{$timestamp}] Eligible    : " . count($members) . " member(s) with today's birthday" . PHP_EOL;

if (empty($members)) {
    echo "[{$timestamp}] Nothing to do. Exiting." . PHP_EOL;
    // Still write a batch summary so the audit log shows the process ran
    $settingsModel->log($cliUserId, 'birthday_email',
        "BATCH|DATE:{$today}|sent:0|skipped:0|failed:0|trigger:cli");
    exit(0);
}

// Determine which members already received their email this year
$alreadySentIds = cli_getAlreadySentIds($settingsModel, $members, $year);
echo "[{$timestamp}] Already sent: " . count($alreadySentIds) . " (will skip)" . PHP_EOL;

$logo    = [['path' => PUBLIC_PATH . '/images/logo-email.png', 'cid' => 'logo']];
$sent    = 0;
$skipped = 0;
$failed  = 0;

foreach ($members as $member) {
    $memberId = (int)$member['id'];
    $email    = $member['email'] ?? '';
    $fullName = trim($member['first_name'] . ' ' . $member['last_name']);

    // Guard: skip members without a valid email address (should not happen —
    // getTodaysBirthdays() already filters on email IS NOT NULL AND != '' —
    // but validated here as a defence-in-depth check)
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped++;
        echo "[{$timestamp}]   SKIP   [{$memberId}] {$fullName} — invalid/missing email" . PHP_EOL;
        continue;
    }

    // Guard: skip if already successfully sent this birthday year
    if (in_array($memberId, $alreadySentIds, true)) {
        $skipped++;
        echo "[{$timestamp}]   SKIP   [{$memberId}] {$fullName} — already sent {$year}" . PHP_EOL;
        continue;
    }

    if ($dryRun) {
        echo "[{$timestamp}]   DRY    [{$memberId}] {$fullName} — would send" . PHP_EOL;
        $sent++; // counted as "would send" in dry-run summary
        continue;
    }

    // Per-member try/catch — one failure must not abort the entire batch
    try {
        $html    = cli_renderBirthdayEmail($member);
        $subject = 'Happy Birthday, ' . $member['first_name'] . '! 🎉';
        $result  = $mailerService->send($email, $fullName, $subject, $html, $logo);

        if ($result['success']) {
            $sent++;
            echo "[{$timestamp}]   SENT   [{$memberId}] {$fullName}" . PHP_EOL;
            $settingsModel->log(
                $cliUserId,
                'birthday_email',
                "MEMBER:{$memberId}|YEAR:{$year}|STATUS:sent|EMAIL:{$email}|NAME:{$fullName}"
            );
        } else {
            $failed++;
            $errorMsg = cli_sanitizeLogValue($result['error'] ?? 'Unknown error');
            echo "[{$timestamp}]   FAIL   [{$memberId}] {$fullName} — {$errorMsg}" . PHP_EOL;
            $settingsModel->log(
                $cliUserId,
                'birthday_email',
                "MEMBER:{$memberId}|YEAR:{$year}|STATUS:failed|EMAIL:{$email}|NAME:{$fullName}|ERROR:{$errorMsg}"
            );
        }
    } catch (\Throwable $e) {
        $failed++;
        $errorMsg = cli_sanitizeLogValue($e->getMessage());
        echo "[{$timestamp}]   FAIL   [{$memberId}] {$fullName} — Exception: {$errorMsg}" . PHP_EOL;
        $settingsModel->log(
            $cliUserId,
            'birthday_email',
            "MEMBER:{$memberId}|YEAR:{$year}|STATUS:failed|EMAIL:{$email}|NAME:{$fullName}|ERROR:Exception: {$errorMsg}"
        );
    }
}

// Batch summary audit log (always written, even in dry-run — prefixed to indicate)
$trigger = $dryRun ? 'cli-dryrun' : 'cli';
$settingsModel->log($cliUserId, 'birthday_email',
    "BATCH|DATE:{$today}|sent:{$sent}|skipped:{$skipped}|failed:{$failed}|trigger:{$trigger}");

// ── Summary output ───────────────────────────────────────────────────────────
$ts2 = date('Y-m-d H:i:s');
echo PHP_EOL;
echo "[{$ts2}] ── Summary ──────────────────────────────────" . PHP_EOL;
echo "[{$ts2}] Date     : {$today}" . PHP_EOL;
echo "[{$ts2}] Eligible : " . count($members) . PHP_EOL;
echo "[{$ts2}] Sent     : {$sent}" . ($dryRun ? ' (dry-run — not actually sent)' : '') . PHP_EOL;
echo "[{$ts2}] Skipped  : {$skipped} (already sent or invalid email)" . PHP_EOL;
echo "[{$ts2}] Failed   : {$failed}" . PHP_EOL;

if ($failed > 0 && $sent === 0 && !$dryRun) {
    echo "[{$ts2}] STATUS   : ALL FAILED — check SMTP config and audit logs" . PHP_EOL;
    exit(2);
}

if ($failed > 0) {
    echo "[{$ts2}] STATUS   : COMPLETED WITH FAILURES — check audit logs" . PHP_EOL;
    exit(0); // non-zero only if ALL failed; partial failure is still exit 0
}

echo "[{$ts2}] STATUS   : OK" . PHP_EOL;
exit(0);
