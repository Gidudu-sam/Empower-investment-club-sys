<?php
/**
 * User Model
 */
class UserModel extends Model
{
    protected string $table      = 'users';
    protected string $primaryKey = 'id';

    /**
     * Find a user by their email address.
     */
    public function findByEmail(string $email): array|false
    {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS role_name, r.label AS role_label
            FROM `users` u
            JOIN `roles` r ON r.id = u.role_id
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->execute([strtolower(trim($email))]);
        return $stmt->fetch();
    }

    /**
     * Stage 13-C (H-3): the minimal, per-request re-validation query used by
     * Session::requireAuth() to detect a deactivation or role change made
     * to a user while their session is already active. Deliberately a
     * single-row, small-column lookup by primary key (not findByEmail()'s
     * SELECT u.*) since this runs on every authenticated request.
     *
     * Stage 14-B: also returns member_id, so the same revalidation pass
     * that catches a role change also catches a member-mapping change
     * (linked/unlinked/relinked) -- one authoritative resync point rather
     * than a second, separately-timed mechanism.
     */
    public function authState(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT u.is_active, u.member_id, u.force_password_change, r.name AS role_name
            FROM `users` u
            JOIN `roles` r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Stage 14-B: is a member already linked to a portal account? Used
     * both for the pre-insert UX check ("this member already has an
     * account") and to look up who holds an existing mapping when
     * relinking. The database's own UNIQUE KEY on member_id is the actual
     * race-condition-proof guarantee; this is the friendlier first check.
     */
    public function findByMemberId(int $memberId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM `users` WHERE `member_id` = ? LIMIT 1");
        $stmt->execute([$memberId]);
        return $stmt->fetch();
    }

    /**
     * Stage 14-B: used exclusively by the member portal's own
     * change-password action (the forced first-login change). Clears
     * force_password_change in the same statement as the password
     * update so the two can never end up out of sync.
     */
    public function updatePasswordAndClearForceChange(int $userId, string $passwordHash): void
    {
        $stmt = $this->db->prepare(
            "UPDATE `users` SET `password_hash` = ?, `force_password_change` = 0 WHERE `id` = ?"
        );
        $stmt->execute([$passwordHash, $userId]);
    }

    /**
     * Verify a plain-text password against the stored hash.
     */
    public function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * Update the last_login_at timestamp for a user.
     */
    public function touchLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE `users` SET `last_login_at` = NOW() WHERE `id` = ?"
        );
        $stmt->execute([$userId]);
    }

    /**
     * Record an activity log entry.
     */
    public function logActivity(int $userId, string $action, string $description = ''): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $this->db->prepare("
            INSERT INTO `activity_logs` (`user_id`, `action`, `description`, `ip_address`)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $action, $description, $ip]);
    }
}
