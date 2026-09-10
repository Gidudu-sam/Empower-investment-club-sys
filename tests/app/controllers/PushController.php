<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/PushSubscriptionModel.php';

/**
 * PushController — Stage 12-D. Subscription lifecycle only. Ownership
 * is always resolved from the session, exactly like every other
 * controller in this app; a posted user_id, if any, is ignored.
 */
class PushController extends Controller
{
    private PushSubscriptionModel $model;

    public function __construct()
    {
        Session::requireAuth();
        $this->model = new PushSubscriptionModel();
    }

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

    /** The VAPID public key is not secret -- this is the one thing the
     *  browser is meant to receive. The private key is never referenced
     *  anywhere in this controller or any view/JS. */
    public function vapidPublicKey(): void
    {
        $this->json(['publicKey' => VAPID_PUBLIC_KEY]);
    }

    public function subscribe(): void
    {
        $userId = (int)Session::get('user_id');

        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            $this->json(['success' => false, 'error' => 'Security token mismatch.'], 400);
            return;
        }

        $raw = $_POST['subscription'] ?? '';
        $data = json_decode($raw, true);

        // Reject anything that doesn't look like a real browser
        // PushSubscription -- never store an arbitrary blob.
        if (!is_array($data)
            || empty($data['endpoint']) || !is_string($data['endpoint'])
            || !str_starts_with($data['endpoint'], 'https://')
            || strlen($data['endpoint']) > 512
            || empty($data['keys']['p256dh']) || !is_string($data['keys']['p256dh'])
            || empty($data['keys']['auth']) || !is_string($data['keys']['auth'])
        ) {
            $this->json(['success' => false, 'error' => 'Invalid subscription payload.'], 400);
            return;
        }

        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null;

        try {
            $id = $this->model->register($userId, $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $userAgent);
            $this->json(['success' => true, 'id' => $id]);
        } catch (Throwable $e) {
            $this->json(['success' => false, 'error' => 'Could not save subscription.'], 500);
        }
    }

    public function unsubscribe(): void
    {
        $userId = (int)Session::get('user_id');

        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            $this->json(['success' => false, 'error' => 'Security token mismatch.'], 400);
            return;
        }

        $endpoint = $_POST['endpoint'] ?? '';
        if (!is_string($endpoint) || $endpoint === '') {
            $this->json(['success' => false, 'error' => 'Missing endpoint.'], 400);
            return;
        }

        $this->model->deactivateForUser($endpoint, $userId);
        $this->json(['success' => true]);
    }

    public function status(): void
    {
        $userId = (int)Session::get('user_id');
        $this->json([
            'enabled'   => $this->model->hasActiveSubscription($userId),
            'csrfToken' => $this->getCsrf(),
        ]);
    }
}
