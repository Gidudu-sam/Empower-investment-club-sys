<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/UserModel.php';
require_once APP_PATH  . '/models/RememberTokenModel.php';

/**
 * Auth Controller — handles login and logout.
 */
class AuthController extends Controller
{
    private UserModel $userModel;
    private RememberTokenModel $rememberTokenModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->rememberTokenModel = new RememberTokenModel();
    }

    /** GET / POST — Login page */
    public function login(): void
    {
        // Already logged in? Send to dashboard
        Session::redirectIfAuth();

        $error  = Session::flash('login_error');
        $reason = $_GET['reason'] ?? null;

        if ($this->isPost()) {
            $this->handleLogin();
            return;
        }

        $this->render('auth/login', [
            'pageTitle' => 'Login — ' . APP_NAME,
            'error'     => $error,
            'reason'    => $reason,
        ], null); // no layout for login
    }

    /** POST handler for login form */
    private function handleLogin(): void
    {
        $email    = $this->sanitize($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && $_POST['remember'] === 'on';

        // Basic validation
        if (empty($email) || empty($password)) {
            Session::flash('login_error', 'Email and password are required.');
            $this->redirect(APP_URL . '/index.php?page=login');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('login_error', 'Please enter a valid email address.');
            $this->redirect(APP_URL . '/index.php?page=login');
            return;
        }

        $user = $this->userModel->findByEmail($email);

        if (!$user || !$this->userModel->verifyPassword($password, $user['password_hash'])) {
            Session::flash('login_error', 'Invalid email or password.');
            $this->redirect(APP_URL . '/index.php?page=login');
            return;
        }

        if (!$user['is_active']) {
            Session::flash('login_error', 'Your account has been deactivated. Contact the administrator.');
            $this->redirect(APP_URL . '/index.php?page=login');
            return;
        }

        // Regenerate session ID on privilege escalation (login)
        session_regenerate_id(true);

        // Store user data in session
        Session::set('user_id',     $user['id']);
        Session::set('user_name',   $user['full_name']);
        Session::set('user_email',  $user['email']);
        Session::set('user_role',   $user['role_name']);
        Session::set('user_avatar', $user['avatar']);
        Session::set('last_activity', time());
        // Stage 14-B: the member identity link. Every subsequent request
        // keeps both of these current via Session::revalidateAuthorization()
        // (Stage 13-C's per-request resync) -- set once here just to seed
        // the very first request after login, before that pass has run.
        Session::set('member_id', $user['member_id'] !== null ? (int)$user['member_id'] : null);
        Session::set('force_password_change', (int)$user['force_password_change'] === 1);

        // Handle "Remember Me" functionality
        if ($remember) {
            $this->createRememberMeCookie($user['id']);
        } else {
            // If user unchecked "remember me", clear any existing cookie
            $this->clearRememberMeCookie();
        }

        // Update last login timestamp and log the activity
        $this->userModel->touchLastLogin($user['id']);
        $this->userModel->logActivity($user['id'], 'login', 'User logged in successfully.');

        // Stage 14-B: a member account lands in the member portal, never
        // the staff dashboard -- the dashboard has nothing for this role
        // to do (every sensitive controller already excludes it).
        if ($user['role_name'] === 'member') {
            $this->redirect(APP_URL . '/index.php?page=portal-home');
            return;
        }

        $this->redirect(APP_URL . '/index.php?page=dashboard');
    }
    
    /**
     * Create a persistent "Remember Me" cookie
     * 
     * @param int $userId The user ID to remember
     */
    private function createRememberMeCookie(int $userId): void
    {
        // Generate secure token
        $tokenData = $this->rememberTokenModel->createToken($userId);
        
        // Set cookie for 90 days
        $expiryTimestamp = strtotime($tokenData['expires_at']);
        
        setcookie(
            'remember_me',
            $tokenData['cookie_value'],
            [
                'expires' => $expiryTimestamp,
                'path' => '/',
                'domain' => '', // Current domain
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // HTTPS only in production
                'httponly' => true, // Prevent JavaScript access (XSS protection)
                'samesite' => 'Lax' // CSRF protection
            ]
        );
    }
    
    /**
     * Clear the "Remember Me" cookie
     */
    private function clearRememberMeCookie(): void
    {
        if (isset($_COOKIE['remember_me'])) {
            // Get selector from cookie to revoke token from database
            $parts = explode(':', $_COOKIE['remember_me'], 2);
            if (count($parts) === 2) {
                $this->rememberTokenModel->revokeBySelector($parts[0]);
            }
            
            // Clear cookie
            setcookie(
                'remember_me',
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
            
            unset($_COOKIE['remember_me']);
        }
    }

    /** GET — Logout */
    public function logout(): void
    {
        $userId = Session::get('user_id');
        if ($userId) {
            $this->userModel->logActivity($userId, 'logout', 'User logged out.');
            
            // Revoke all remember me tokens for this user for security
            $this->rememberTokenModel->revokeAllUserTokens($userId);
        }
        
        // Clear remember me cookie
        $this->clearRememberMeCookie();

        Session::destroy();
        $this->redirect(APP_URL . '/index.php?page=login');
    }
}
