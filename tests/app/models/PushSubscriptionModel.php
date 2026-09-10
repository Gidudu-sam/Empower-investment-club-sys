<?php
/**
 * PushSubscriptionModel — Stage 12-D.
 *
 * Every method here is either explicitly scoped to a caller-supplied
 * $userId (never trusted from the browser -- always resolved from the
 * session by the controller) or is the internal delivery-side lookup
 * used only by PushDeliveryService. There is no method that returns or
 * mutates a subscription without a $userId (or, for delivery, a
 * specific known notification's owning user_id) constraining it.
 */
class PushSubscriptionModel extends Model
{
    protected string $table      = 'push_subscriptions';
    protected string $primaryKey = 'id';

    /**
     * Register or refresh a subscription for $userId. Idempotent by
     * endpoint (the natural identity of a browser subscription): a
     * second registration of the same endpoint updates the stored keys
     * and reactivates it rather than creating a duplicate row -- this
     * also correctly handles the browser silently rotating an
     * endpoint's keys, and covers "duplicate subscription is handled
     * idempotently" without inventing separate insert/update call sites.
     */
    public function register(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): int
    {
        $hash = hash('sha256', $endpoint);
        $existing = $this->db->prepare("SELECT id, user_id FROM `push_subscriptions` WHERE endpoint_hash = ?");
        $existing->execute([$hash]);
        $row = $existing->fetch();

        if ($row) {
            // Re-subscribing under a different user_id (e.g. a shared
            // kiosk browser where a different staff member logs in) --
            // the endpoint moves to the new owner rather than silently
            // staying attributed to whoever registered it first.
            $stmt = $this->db->prepare(
                "UPDATE `push_subscriptions`
                 SET user_id=?, p256dh_key=?, auth_key=?, user_agent=?, is_active=1,
                     last_failure_reason=NULL, last_failure_at=NULL
                 WHERE id=?"
            );
            $stmt->execute([$userId, $p256dh, $auth, $userAgent, $row['id']]);
            return (int)$row['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `push_subscriptions` (user_id, endpoint, endpoint_hash, p256dh_key, auth_key, user_agent, is_active)
             VALUES (?,?,?,?,?,?,1)"
        );
        $stmt->execute([$userId, $endpoint, $hash, $p256dh, $auth, $userAgent]);
        return (int)$this->db->lastInsertId();
    }

    /** Deactivates (never deletes) the caller's own subscription for a
     *  given endpoint -- ownership enforced by requiring $userId to
     *  match, exactly like every other notification mutation in this
     *  app. Retained for audit, matching §8's "do not permanently delete
     *  useful audit information" instruction. */
    public function deactivateForUser(string $endpoint, int $userId): bool
    {
        $hash = hash('sha256', $endpoint);
        $stmt = $this->db->prepare(
            "UPDATE `push_subscriptions` SET is_active=0 WHERE endpoint_hash=? AND user_id=?"
        );
        $stmt->execute([$hash, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** All of one user's own active subscriptions -- used both by
     *  delivery (given a notification's concrete user_id) and by a
     *  future "your devices" UI; never queried without a user_id. */
    public function activeForUser(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `push_subscriptions` WHERE user_id=? AND is_active=1");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function hasActiveSubscription(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `push_subscriptions` WHERE user_id=? AND is_active=1");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function markSuccess(int $id): void
    {
        $this->db->prepare(
            "UPDATE `push_subscriptions` SET last_used_at=NOW(), last_success_at=NOW() WHERE id=?"
        )->execute([$id]);
    }

    public function markFailure(int $id, string $reason): void
    {
        $this->db->prepare(
            "UPDATE `push_subscriptions` SET last_used_at=NOW(), last_failure_at=NOW(), last_failure_reason=? WHERE id=?"
        )->execute([substr($reason, 0, 255), $id]);
    }

    /** A 404/410 (or equivalent permanent-invalid) response means the
     *  browser has permanently discarded this subscription -- reusing
     *  it again would just keep failing, so it is deactivated, never
     *  deleted (audit trail preserved, per §8/§21). */
    public function deactivate(int $id, string $reason): void
    {
        $this->db->prepare(
            "UPDATE `push_subscriptions` SET is_active=0, last_used_at=NOW(), last_failure_at=NOW(), last_failure_reason=? WHERE id=?"
        )->execute([substr($reason, 0, 255), $id]);
    }
}
