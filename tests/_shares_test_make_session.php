<?php
/**
 * Helper for test_shares_stage1_foundation.php ONLY: creates one real PHP
 * session file with a given (pre-existing, non-financial) user id/role,
 * exactly as AuthController's login would populate it, and prints the
 * session id. Run as a fresh CLI process per call so PHP's session module
 * never sees more than one session_start() per process (avoids the
 * "headers already sent" restriction that a session_id() change hits when
 * called a second time in the same long-running process).
 */
$userId = (int)($argv[1] ?? 0);
$role   = $argv[2] ?? '';

$sid = bin2hex(random_bytes(16));
session_id($sid);
session_name('empower_session');
session_start();
$_SESSION['user_id']       = $userId;
$_SESSION['user_role']     = $role;
$_SESSION['last_activity'] = time();
$_SESSION['_initiated']    = true;
session_write_close();

echo $sid;
