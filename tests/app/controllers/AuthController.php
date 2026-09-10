<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/UserModel.php';

/**
 * Auth Controller — handles login and logout.
 */
class AuthController extends Controller
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
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

    /** GET — Logout */
    public function logout(): void
    {
        $userId = Session::get('user_id');
        if ($userId) {
            $this->userModel->logActivity($userId, 'logout', 'User logged out.');
        }

        Session::destroy();
        $this->redirect(APP_URL . '/index.php?page=login');
    }
}
