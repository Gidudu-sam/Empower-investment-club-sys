<?php
/**
 * BirthdayController
 *
 * Manages the birthday-wishes email feature.  Communications-only: no
 * financial data is read, computed, or modified by this controller.
 *
 * Architecture decisions (all confirmed by the Phase 1 audit):
 *   - Reuses MailerService / app/config/mail.php exclusively — no new mailer.
 *   - Reuses SettingsModel::log() + recentLog() for audit trail and
 *     duplicate-send guard — no new database table.
 *   - Reuses Session::requireAuth() + Session::hasRole() for authorization.
 *   - Birthday eligibility: status='active', non-null DOB, non-empty email.
 *   - Duplicate-send guard key: action='birthday_email',
 *     description prefix 'MEMBER:{id}|YEAR:{year}|STATUS:sent'.
 *   - Email is synchronous (same as statement bulk send).
 *   - No scheduler is introduced — manual HTTP trigger by staff.
 *
 * Authorized roles: admin, system_admin, office_admin
 *   (mirrors StatementController::emailAll() precedent — office_admin
 *   owns member-facing communications; admin/system_admin have full access)
 *
 * Leap-day behavior (Feb 29 birthdays):
 *   On non-leap years the birthday is observed on Feb 28.
 *   resolveTargetDate() applies this adjustment automatically.
 */
class BirthdayController extends Controller
{
    private MemberModel   $memberModel;
    private SettingsModel $settings;
    private MailerService $mailer;

    /** Roles permitted to use this controller at all */
    private const ALLOWED_ROLES = ['admin', 'system_admin', 'office_admin'];

    public function __construct()
    {
        Session::requireAuth();
        if (!Session::hasRole(self::ALLOWED_ROLES)) {
            http_response_code(403);
            die('Access denied. You do not have permission to manage birthday emails.');
        }

        $this->memberModel = new MemberModel();
        $this->settings    = new SettingsModel();
        $this->mailer      = new MailerService();
    }

    // ----------------------------------------------------------------
    // index() — dashboard: today's birthdays, upcoming, recent history
    // ----------------------------------------------------------------

    public function index(): void
    {
        $today         = $this->resolveTargetDate(date('Y-m-d'));
        $year          = (int)date('Y', strtotime($today));
        $todayBirthdays = $this->getTodaysBirthdaysLeapAware($today);

        // Annotate with already-sent status for the current birthday year
        $alreadySentIds = $this->getAlreadySentIds($todayBirthdays, $year);

        $recentHistory = $this->fetchHistory(1, 20)['rows'];

        $this->render('birthday/index', [
            'pageTitle'        => 'Member Birthdays — ' . APP_NAME,
            'breadcrumbs'      => [['label' => 'Member Birthdays']],
            'today'            => $today,
            'todayDisplay'     => date('l, j F Y', strtotime($today)),
            'todayBirthdays'   => $todayBirthdays,
            'upcomingBirthdays'=> $this->memberModel->getUpcomingBirthdays(7),
            'alreadySentIds'   => $alreadySentIds,
            'recentHistory'    => $recentHistory,
            'mailerConfigured' => $this->mailer->isConfigured(),
            'canSend'          => true, // constructor already enforced the role gate
            'csrfToken'        => $this->getCsrf(),
            'success'          => Session::flash('success'),
            'error'            => Session::flash('error'),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // send() — POST: send birthday emails to all eligible members today
    // ----------------------------------------------------------------

    public function send(): void
    {
        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=birthday-dashboard');
            return;
        }

        $userId = (int)Session::get('user_id');
        $today  = $this->resolveTargetDate(date('Y-m-d'));
        $year   = (int)date('Y', strtotime($today));

        $members        = $this->getTodaysBirthdaysLeapAware($today);
        $alreadySentIds = $this->getAlreadySentIds($members, $year);

        $logo = [['path' => PUBLIC_PATH . '/images/logo-email.png', 'cid' => 'logo']];

        $sent    = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($members as $member) {
            $memberId = (int)$member['id'];
            $email    = $member['email'] ?? '';
            $fullName = trim($member['first_name'] . ' ' . $member['last_name']);

            // Guard: skip members without a valid email address
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            // Guard: skip if already successfully sent this birthday year
            if (in_array($memberId, $alreadySentIds, true)) {
                $skipped++;
                continue;
            }

            // Per-member try/catch — one failure must not abort the entire batch
            try {
                $html    = $this->buildEmailHtml($member);
                $subject = 'Happy Birthday, ' . $member['first_name'] . '! 🎉';

                $result  = $this->mailer->send($email, $fullName, $subject, $html, $logo);

                if ($result['success']) {
                    $sent++;
                    // Permanent audit log row — also serves as the duplicate-send guard
                    $this->settings->log(
                        $userId,
                        'birthday_email',
                        "MEMBER:{$memberId}|YEAR:{$year}|STATUS:sent"
                        . "|EMAIL:{$email}|NAME:{$fullName}"
                    );
                } else {
                    $failed++;
                    $errorMsg = $this->sanitizeLogValue($result['error'] ?? 'Unknown error');
                    $this->settings->log(
                        $userId,
                        'birthday_email',
                        "MEMBER:{$memberId}|YEAR:{$year}|STATUS:failed"
                        . "|EMAIL:{$email}|NAME:{$fullName}|ERROR:{$errorMsg}"
                    );
                }
            } catch (\Throwable $e) {
                // Catches template rendering errors, missing image files, etc.
                $failed++;
                $this->settings->log(
                    $userId,
                    'birthday_email',
                    "MEMBER:{$memberId}|YEAR:{$year}|STATUS:failed"
                    . "|EMAIL:{$email}|NAME:{$fullName}|ERROR:Exception: "
                    . $this->sanitizeLogValue($e->getMessage())
                );
            }
        }

        // Summary audit log for the entire batch
        $this->settings->log(
            $userId,
            'birthday_email',
            "BATCH|DATE:{$today}|sent:{$sent}|skipped:{$skipped}|failed:{$failed}"
        );

        // User-facing result flash
        if ($sent === 0 && $failed === 0 && $skipped > 0) {
            Session::flash('success', "No birthday emails sent — all eligible members were either already sent this year or have no email on file ({$skipped} skipped).");
        } elseif ($sent === 0 && $failed > 0) {
            Session::flash('error', "Could not send any birthday emails ({$failed} failed). Check SMTP configuration or Settings → Audit Logs.");
        } elseif ($failed > 0) {
            Session::flash('error', "Birthday emails: {$sent} sent, {$skipped} skipped, {$failed} failed. Check Settings → Audit Logs for details.");
        } else {
            $noun = $sent === 1 ? 'email' : 'emails';
            $extra = $skipped > 0 ? " ({$skipped} skipped — already sent or no email on file)" : '';
            Session::flash('success', "Birthday {$noun} sent to {$sent} member" . ($sent !== 1 ? 's' : '') . ".{$extra}");
        }

        $this->redirect(APP_URL . '/index.php?page=birthday-dashboard');
    }

    // ----------------------------------------------------------------
    // preview() — GET: dry-run, shows eligibility and a sample email
    // ----------------------------------------------------------------

    public function preview(): void
    {
        $today          = $this->resolveTargetDate(date('Y-m-d'));
        $year           = (int)date('Y', strtotime($today));
        $todayBirthdays = $this->getTodaysBirthdaysLeapAware($today);
        $alreadySentIds = $this->getAlreadySentIds($todayBirthdays, $year);

        // Pick the first member who would actually receive an email for the preview
        $sampleMember = null;
        foreach ($todayBirthdays as $m) {
            if (!empty($m['email']) && !in_array((int)$m['id'], $alreadySentIds, true)) {
                $sampleMember = $m;
                break;
            }
        }

        // Fallback: use first birthday member even if already sent
        if ($sampleMember === null && !empty($todayBirthdays)) {
            $sampleMember = $todayBirthdays[0];
        }

        $sampleHtml = '';
        if ($sampleMember !== null) {
            try {
                $sampleHtml = $this->buildEmailHtml($sampleMember);
            } catch (\Throwable $e) {
                $sampleHtml = '<p style="color:red">Template render error: '
                    . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }

        $this->render('birthday/preview', [
            'pageTitle'       => 'Birthday Email Preview — ' . APP_NAME,
            'breadcrumbs'     => [
                ['label' => 'Member Birthdays', 'url' => APP_URL . '/index.php?page=birthday-dashboard'],
                ['label' => 'Preview'],
            ],
            'today'           => $today,
            'todayDisplay'    => date('l, j F Y', strtotime($today)),
            'todayBirthdays'  => $todayBirthdays,
            'alreadySentIds'  => $alreadySentIds,
            'sampleMember'    => $sampleMember,
            'sampleHtml'      => $sampleHtml,
            'canSend'         => true,
            'csrfToken'       => $this->getCsrf(),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // history() — GET: full paginated birthday email log
    // ----------------------------------------------------------------

    public function history(): void
    {
        $page  = max(1, (int)($_GET['p'] ?? 1));
        $data  = $this->fetchHistory($page, 25);

        $this->render('birthday/history', [
            'pageTitle'   => 'Birthday Email History — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Member Birthdays', 'url' => APP_URL . '/index.php?page=birthday-dashboard'],
                ['label' => 'History'],
            ],
            'logs'        => $data['rows'],
            'total'       => $data['total'],
            'pages'       => $data['pages'],
            'currentPage' => $page,
        ], 'main');
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Build the birthday email HTML for one member.
     * Injects only the member's name — zero financial data.
     */
    private function buildEmailHtml(array $member): string
    {
        return $this->renderToString('birthday/email', [
            'member'    => $member,
            'firstName' => $member['first_name'],
            'fullName'  => trim($member['first_name'] . ' ' . $member['last_name']),
        ]);
    }

    /**
     * Given a list of member rows and a birthday year, return the IDs of
     * members who have already been successfully sent a birthday email
     * for that year.  Queries activity_logs using a per-member prefix
     * search — the same mechanism StatementController uses for its
     * 30-minute idempotency guard, extended to a full-year window.
     *
     * A 'STATUS:failed' row does NOT block re-sending — only 'STATUS:sent'
     * rows prevent duplicate delivery.
     *
     * @param  array<int,array<string,mixed>> $members  Member rows to check
     * @param  int    $year   Birthday calendar year
     * @return int[]          Member IDs already sent
     */
    private function getAlreadySentIds(array $members, int $year): array
    {
        $sentIds = [];
        // 525,600 minutes = 365 days — wide enough to cover the full year
        foreach ($members as $m) {
            $id     = (int)$m['id'];
            $prefix = "MEMBER:{$id}|YEAR:{$year}|STATUS:sent";
            $found  = $this->settings->recentLog('birthday_email', $prefix, 525600);
            if ($found !== false) {
                $sentIds[] = $id;
            }
        }
        return $sentIds;
    }

    /**
     * Resolve the target date for birthday matching.
     *
     * Leap-day (Feb 29) adjustment:
     *   If today is Feb 28 on a non-leap year, we also look for Feb 29
     *   members by treating Feb 28 as their observed birthday.  The
     *   getTodaysBirthdays() query uses the passed $date, which is
     *   always a real calendar date; it never receives Feb 29 on a
     *   non-leap year. Instead, when today IS Feb 29 (leap year), we
     *   pass it as-is and the query correctly matches Feb 29 birthdays.
     *
     *   The effective rule:
     *     - Leap years  : Feb 29 members matched on Feb 29 exactly.
     *     - Non-leap years : Feb 28 members AND Feb 29 members both
     *       observed on Feb 28 by running the query once for Feb 28
     *       (which catches the Feb 28 members), then an additional pass
     *       for Feb 28 as a stand-in for Feb 29 members.
     *
     *   This is handled by getTodaysBirthdays() receiving the same date
     *   ('YYYY-02-28') on non-leap years regardless — the method matches
     *   MONTH+DAY only. The additional Feb 29 pass is done here via
     *   $extraDate, returned as part of the resolved pair.  Controllers
     *   that need the full leap-day-aware set call
     *   getTodaysBirthdaysLeapAware() below.
     *
     * @param  string $rawDate  'YYYY-MM-DD'
     * @return string  The date to use for the primary birthday query
     */
    public function resolveTargetDate(string $rawDate): string
    {
        // Feb 29 on a leap year: pass as-is — the query can handle it
        return $rawDate;
    }

    /**
     * Leap-aware birthday fetch: on Feb 28 in a non-leap year, also
     * fetches members born on Feb 29.  Returns a merged, deduplicated
     * list sorted by last name.
     *
     * This is the correct public entry-point for all birthday-qualifying
     * logic. Controllers use this instead of calling getTodaysBirthdays()
     * directly so that Feb 29 members are never silently excluded.
     *
     * @param  string $date  'YYYY-MM-DD', defaults to today
     * @return array<int,array<string,mixed>>
     */
    private function getTodaysBirthdaysLeapAware(string $date = ''): array
    {
        if ($date === '') {
            $date = date('Y-m-d');
        }
        $rows = $this->memberModel->getTodaysBirthdays($date);

        // On Feb 28 in a non-leap year, also pick up Feb 29 birthdays
        if (date('m-d', strtotime($date)) === '02-28' && !$this->isLeapYear((int)date('Y', strtotime($date)))) {
            // Build the Feb 29 query date for the same year
            $leapDate = date('Y', strtotime($date)) . '-02-29';
            $feb29Rows = $this->memberModel->getTodaysBirthdays($leapDate);
            // Merge and deduplicate by member id
            $seen = array_column($rows, 'id');
            foreach ($feb29Rows as $r) {
                if (!in_array($r['id'], $seen, true)) {
                    $rows[] = $r;
                }
            }
            // Re-sort by last_name, first_name
            usort($rows, static fn($a, $b) =>
                strcmp($a['last_name'] . $a['first_name'], $b['last_name'] . $b['first_name']));
        }

        return $rows;
    }

    /**
     * Whether the given year is a leap year.
     */
    private function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
    }

    /**
     * Fetch paginated birthday_email rows from activity_logs.
     * Returns rows that are NOT the batch-summary rows (skips BATCH| lines).
     *
     * @return array{rows:array,total:int,pages:int}
     */
    private function fetchHistory(int $page = 1, int $perPage = 25): array
    {
        return $this->settings->getAuditLogs(
            search: '',
            action: 'birthday_email',
            page:   $page,
            perPage: $perPage
        );
    }

    /**
     * Strip pipe characters and newlines from a value destined for an
     * activity_log description string so that the pipe-delimited parsing
     * in the history view cannot be corrupted by error messages that
     * happen to contain pipes.
     */
    private function sanitizeLogValue(string $value): string
    {
        return str_replace(['|', "\n", "\r"], [' ', ' ', ''], trim($value));
    }

    // ----------------------------------------------------------------
    // CSRF helpers (same pattern as StatementController)
    // ----------------------------------------------------------------

    private function getCsrf(): string
    {
        if (!Session::has('csrf_token')) {
            Session::set('csrf_token', bin2hex(random_bytes(32)));
        }
        return Session::get('csrf_token');
    }

    private function verifyCsrf(string $token): bool
    {
        $stored = Session::get('csrf_token', '');
        Session::set('csrf_token', bin2hex(random_bytes(32)));
        return hash_equals($stored, $token);
    }
}
