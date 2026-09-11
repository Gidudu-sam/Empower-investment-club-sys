<?php
/**
 * Session helper — wraps PHP native sessions with security hardening.
 */
class Session
{
    private static bool $started = false;

    /** Start the session with secure settings */
    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        session_name(SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']), // true on HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        // Regenerate session ID periodically to prevent fixation
        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
        }
    }

    /** Store a value in the session */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /** Retrieve a value from the session */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /** Check whether a session key exists -- including a pending flash
     *  message under that key. flash() stores its value at
     *  $_SESSION['_flash'][$key], not $_SESSION[$key], so a plain
     *  isset($_SESSION[$key]) here always missed it: every
     *  `if (Session::has('error')) { ...Session::flash('error')... }`
     *  view-side check (30 files) silently never rendered, regardless of
     *  whether a controller had actually flashed a message. Checking both
     *  locations is a pure widening -- every existing caller either checks
     *  a genuine top-level key (e.g. 'csrf_token', never stored as a flash
     *  value) or is exactly this broken flash-detection pattern. */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]) || isset($_SESSION['_flash'][$key]);
    }

    /** Remove a key from the session */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /** Destroy the session completely (logout) */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        self::$started = false;
    }

    /** Flash messages — set once, read once */
    public static function flash(string $key, mixed $value = null): mixed
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $msg = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $msg;
    }

    /**
     * Check if a user is authenticated.
     * Logs out and redirects to login if not.
     */
    public static function requireAuth(): void
    {
        self::start();
        if (!self::has('user_id')) {
            header('Location: ' . APP_URL . '/index.php?page=login');
            exit;
        }

        // Session timeout check
        $lastActivity = self::get('last_activity');
        if ($lastActivity && (time() - $lastActivity) > SESSION_LIFETIME) {
            self::destroy();
            header('Location: ' . APP_URL . '/index.php?page=login&reason=timeout');
            exit;
        }

        self::revalidateAuthorization();

        self::set('last_activity', time());
    }

    /**
     * Stage 13-C (H-3): re-check the session's cached authorization state
     * against the database on every authenticated request. Previously,
     * deactivating a user or changing their role had no effect on a
     * session already in progress -- the stale privilege persisted until
     * the session naturally expired, which the sliding timeout above could
     * extend indefinitely under continued activity.
     *
     * Deactivation is treated as a full revocation (session destroyed,
     * same as an expired session bouncing to login) since the account is
     * no longer supposed to be usable at all. A role change is deliberately
     * NOT a forced logout -- that would be a disruptive reaction to a
     * routine administrative action -- instead the session's cached role
     * is resynced to the current value, so the very next
     * Session::hasRole() check already reflects it, whether that's a
     * downgrade (old privileges gone immediately) or an upgrade (new
     * privileges available immediately).
     *
     * A single indexed primary-key lookup (UserModel::authState()) --
     * cheap enough to run on every request at this application's scale.
     *
     * Stage 14-B: the same pass also resyncs the session's cached
     * member_id from the database, using the identical reasoning already
     * established for role changes above -- if an administrator links,
     * unlinks, or relinks a user's member mapping while that user has an
     * active session, the next request must see the current mapping, not
     * whatever was cached at login. This is deliberately the same
     * mechanism, not a second, separately-timed one.
     */
    private static function revalidateAuthorization(): void
    {
        $userId = self::get('user_id');
        if (!$userId) {
            return;
        }

        $state = (new UserModel())->authState((int)$userId);

        if ($state === false || (int)$state['is_active'] !== 1) {
            self::destroy();
            header('Location: ' . APP_URL . '/index.php?page=login&reason=deactivated');
            exit;
        }

        if ($state['role_name'] !== self::get('user_role')) {
            self::set('user_role', $state['role_name']);
        }

        $currentMemberId = $state['member_id'] !== null ? (int)$state['member_id'] : null;
        if ($currentMemberId !== self::get('member_id')) {
            self::set('member_id', $currentMemberId);
        }

        $currentForcePwChange = (int)$state['force_password_change'] === 1;
        if ($currentForcePwChange !== (bool)self::get('force_password_change')) {
            self::set('force_password_change', $currentForcePwChange);
        }
    }

    /**
     * Stage 14-B: the member portal's one and only ownership boundary.
     * Every portal controller action must call this to obtain the member
     * id it is allowed to query -- never $_GET['member_id'], $_POST[...],
     * or any other browser-supplied value. Returns the authenticated
     * member's id, derived exclusively from the session (itself kept
     * current by revalidateAuthorization() on every request, so a
     * deactivation, role change, or member-mapping change already takes
     * effect before this is ever reached). Aborts with 403 -- the same
     * convention every other require*Access() gate in this codebase
     * already uses for an authenticated-but-wrong-privilege request --
     * if the caller is not a member or has no valid mapping.
     *
     * $allowPendingPasswordChange exists for exactly one caller: the
     * portal's own change-password action. Every other portal method
     * must use the default and gets redirected there first if the
     * account still owes a forced password change -- so a newly
     * provisioned member cannot reach any other portal data by
     * navigating directly to its route.
     */
    public static function requireMember(bool $allowPendingPasswordChange = false): int
    {
        self::requireAuth();

        if (self::get('user_role') !== 'member') {
            http_response_code(403);
            die('Access denied. This page is only available to member accounts.');
        }

        $memberId = self::get('member_id');
        if (!is_int($memberId) || $memberId < 1) {
            http_response_code(403);
            die('Access denied. This account is not linked to a member record. Contact an administrator.');
        }

        if (!$allowPendingPasswordChange && self::get('force_password_change')) {
            header('Location: ' . APP_URL . '/index.php?page=portal-change-password');
            exit;
        }

        return $memberId;
    }

    /** Redirect authenticated users away from the login page */
    public static function redirectIfAuth(): void
    {
        self::start();
        if (self::has('user_id')) {
            // Stage 14-B: a member account never lands on the staff
            // dashboard -- matches the same routing decision made right
            // after a fresh login in AuthController::handleLogin().
            $page = self::get('user_role') === 'member' ? 'portal-home' : 'dashboard';
            header('Location: ' . APP_URL . '/index.php?page=' . $page);
            exit;
        }
    }

    /**
     * Check if current user has one of the allowed roles.
     * 
     * @param array $allowedRoles Array of role names to check against
     * @return bool True if user's role matches one of the allowed roles
     */
    public static function hasRole(array $allowedRoles): bool
    {
        self::start();
        $userRole = self::get('user_role');
        return in_array($userRole, $allowedRoles, true);
    }
}
