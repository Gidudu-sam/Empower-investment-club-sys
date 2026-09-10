<?php
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * PushDeliveryService — Stage 12-D.
 *
 * The ONLY thing this class does is deliver an ALREADY-CREATED
 * notification row to its owner's active push subscriptions. It never
 * decides whether an event happened, never resolves a role, never
 * writes a new `notifications` row -- it takes a notification id,
 * reloads that row fresh (so it always sends what is actually in the
 * database, not a value passed by a caller), and pushes to exactly the
 * one concrete `user_id` already on that row. Broadcast rows
 * (`is_broadcast=1`, `user_id` NULL) are deliberately not pushed here --
 * zero real broadcast rows exist in this system today (confirmed during
 * the Stage 12-D audit), and blindly fanning a broadcast out to every
 * subscription is exactly the kind of privacy/operational risk the
 * brief warned against; documented as a deferred dependency, not solved
 * by guessing a policy.
 *
 * Called synchronously, right after the notification row is committed
 * (never inside the same transaction as any financial operation --
 * confirmed by inspection that every existing call site already invokes
 * notification creation *after* its own financial transaction has
 * committed). A push failure here is always caught and swallowed: it
 * can never bubble up and disturb the caller.
 */
class PushDeliveryService
{
    private PDO $db;
    private PushSubscriptionModel $subscriptions;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->subscriptions = new PushSubscriptionModel();
    }

    private function log(int $userId, string $action, string $description): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
            );
            $stmt->execute([$userId ?: null, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {}
    }

    /**
     * @return int Number of subscriptions a push was successfully sent to (0 if none, or on any failure -- never throws).
     */
    public function deliverForNotification(int $notificationId): int
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `notifications` WHERE id = ?");
            $stmt->execute([$notificationId]);
            $notification = $stmt->fetch();
            if (!$notification || $notification['user_id'] === null) {
                return 0; // broadcast or missing row -- not this stage's concern (see class docblock)
            }

            $userId = (int)$notification['user_id'];
            $subs = $this->subscriptions->activeForUser($userId);
            if (empty($subs)) {
                return 0;
            }

            $payload = json_encode([
                'notification_id' => (int)$notification['id'],
                'title'           => $notification['title'],
                'body'            => $this->safeBody($notification),
                'type'            => $notification['type'],
                'priority'        => $notification['priority'] ?? 'normal',
                'action_url'      => $this->safeActionUrl($notification['action_url'] ?? null),
                'reference_type'  => $notification['reference_type'],
                'reference_id'    => $notification['reference_id'],
            ], JSON_UNESCAPED_SLASHES);

            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => VAPID_SUBJECT,
                    'publicKey'  => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ], [], 5); // 5s timeout -- this is a best-effort secondary channel, never worth blocking a request over

            $subsById = [];
            foreach ($subs as $sub) {
                $subsById[$sub['endpoint']] = $sub;
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'keys'     => ['p256dh' => $sub['p256dh_key'], 'auth' => $sub['auth_key']],
                    ]),
                    $payload
                );
            }

            $delivered = 0;
            foreach ($webPush->flush() as $report) {
                $sub = $subsById[$report->getEndpoint()] ?? null;
                if (!$sub) { continue; }

                if ($report->isSuccess()) {
                    $this->subscriptions->markSuccess((int)$sub['id']);
                    $delivered++;
                } elseif ($report->isSubscriptionExpired()) {
                    // 404/410 -- the browser has permanently discarded this
                    // subscription; reusing it would only keep failing.
                    $this->subscriptions->deactivate((int)$sub['id'], $report->getReason());
                    $this->log($userId, 'push_subscription_deactivated', "Subscription id {$sub['id']} deactivated (expired): {$report->getReason()}");
                } else {
                    // Transient failure -- keep the subscription active, just record it.
                    $this->subscriptions->markFailure((int)$sub['id'], $report->getReason());
                }
            }

            if ($delivered > 0) {
                $this->log($userId, 'push_delivered', "Notification #{$notificationId} pushed to {$delivered} device(s).");
            }
            return $delivered;
        } catch (Throwable $e) {
            // Never let a push failure become visible to whatever
            // triggered notification creation.
            $this->log(0, 'push_delivery_failed', "Notification #{$notificationId}: " . $e->getMessage());
            return 0;
        }
    }

    /** Conservative lock-screen wording for anything that isn't a plain
     *  info/success message -- per the brief's explicit instruction not
     *  to put figures like "overdue by 73 days, Shs 6,000,000" on a
     *  device that might be locked/shared. Stage 12-B has no dedicated
     *  sensitivity column (confirmed not present); this derives a safe
     *  rule from the columns that DO exist (type/priority) rather than
     *  redesigning that schema. */
    private function safeBody(array $notification): string
    {
        $isSensitive = in_array($notification['type'], ['warning', 'critical'], true)
            || ($notification['priority'] ?? 'normal') === 'high';
        return $isSensitive
            ? 'Open Empower to review this notification.'
            : $notification['message'];
    }

    /** Only ever push a same-origin, internal application URL -- a push
     *  payload must never be able to turn into an open redirect. */
    private function safeActionUrl(?string $url): ?string
    {
        if (!$url) { return null; }
        $appHost = parse_url(APP_URL, PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        return ($urlHost === null || $urlHost === $appHost) ? $url : null;
    }
}
