<?php
/**
 * NotificationModel — System Notifications (Stage 12-B: personalized
 * recipient-aware core engine).
 *
 * Every notification is materialized with a CONCRETE recipient user_id
 * at creation time -- there is no "leave user_id NULL to mean everyone"
 * path anymore (that was the Stage 12-A root cause: every read query
 * treated NULL as "visible to all", and almost every write site left it
 * NULL by default). A role-targeted event (e.g. "voucher awaiting
 * approval") is resolved to the actual users holding that role AT THE
 * MOMENT OF CREATION and one row is inserted per recipient, each
 * carrying `recipient_role` as audit metadata only -- never re-resolved
 * at read time, so a later role change can never alter who could already
 * see a historical notification (this was an explicit design
 * requirement for Stage 12-B).
 *
 * The only way a row can still have `user_id IS NULL` is the single,
 * explicit `notifySystemBroadcast()` method -- a deliberate, rare,
 * all-audience announcement, never an accidental default. Ordinary read
 * queries no longer treat a NULL user_id as "visible to everyone";
 * `is_broadcast = 1` is what makes a broadcast row visible to all.
 */
class NotificationModel extends Model
{
    protected string $table      = 'notifications';
    protected string $primaryKey = 'id';

    // ================================================================
    // RECIPIENT RESOLUTION
    // ================================================================

    /** Active users currently holding any of the given roles -- resolved
     *  fresh, right now, from `users`/`roles`. Never cached, never
     *  stored as the ongoing definition of "who gets this" -- only the
     *  ids returned here get materialized into concrete rows. */
    private function resolveActiveUsersByRoles(array $roles): array
    {
        if (empty($roles)) { return []; }
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->db->prepare(
            "SELECT u.id FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE r.name IN ({$placeholders}) AND u.is_active = 1"
        );
        $stmt->execute($roles);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ================================================================
    // CREATE — every path below ends in a concrete recipient
    // ================================================================

    /**
     * The one low-level insert. $uniqueEventKey, when supplied, is the
     * sole source of duplicate protection (a real UNIQUE index, not an
     * application-level pre-check) -- safe under concurrent polling and
     * multiple browser tabs. A collision is treated as "already
     * notified", not an error: returns null rather than throwing.
     */
    private function insertRow(
        ?int $userId, string $title, string $message, string $type,
        ?string $refType, ?int $refId, array $context, bool $isBroadcast, ?string $uniqueEventKey
    ): ?int {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `notifications`
                 (`title`,`message`,`type`,`priority`,`reference_type`,`reference_id`,`user_id`,`recipient_role`,
                  `member_id`,`loan_id`,`savings_account_id`,`action_url`,`event_date`,`unique_event_key`,`is_broadcast`)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $title, $message, $type, $context['priority'] ?? 'normal', $refType, $refId, $userId,
                $context['recipient_role'] ?? null, $context['member_id'] ?? null, $context['loan_id'] ?? null,
                $context['savings_account_id'] ?? null, $context['action_url'] ?? null, $context['event_date'] ?? null,
                $uniqueEventKey, $isBroadcast ? 1 : 0,
            ]);
            $id = (int)$this->db->lastInsertId();

            // Stage 12-D: push is a best-effort secondary delivery channel
            // for an already-committed row -- never inside this INSERT's
            // own success path in a way that could affect it, and never
            // for a broadcast (no single owning subscription list to
            // target; see PushDeliveryService's docblock). A push failure
            // here can never undo or affect the notification row itself,
            // since it happens strictly after the row already exists.
            if (!$isBroadcast && $userId !== null && class_exists('PushDeliveryService')) {
                try { (new PushDeliveryService())->deliverForNotification($id); } catch (Throwable $e) {}
            }

            return $id;
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) { return null; } // duplicate unique_event_key -- already notified
            return null;
        }
    }

    /** Notify exactly one, already-known user. The ordinary building
     *  block for personal events (a preparer, a specific approver, etc). */
    public function notifyUser(
        int $userId, string $title, string $message, string $type = 'info',
        ?string $refType = null, ?int $refId = null, array $context = [], ?string $uniqueEventKey = null
    ): ?int {
        return $this->insertRow($userId, $title, $message, $type, $refType, $refId, $context, false, $uniqueEventKey);
    }

    /**
     * Notify every active user currently holding ANY of the given roles.
     * Materializes one concrete row per resolved user (each with its own
     * is_read/read_at, matching this table's existing per-row shape) --
     * NOT a role column read at query time. If $baseEventKey is given,
     * each recipient's row gets its own deterministic
     * "{$baseEventKey}:user:{$userId}" key, so re-running this for the
     * same event never duplicates any one recipient's copy while still
     * correctly notifying a newly-added user in that role who wasn't
     * there on an earlier run.
     *
     * @return int Number of NEW rows actually created (excludes ones
     *             already-notified via the unique key).
     */
    public function notifyRoles(
        array $roles, string $title, string $message, string $type = 'info',
        ?string $refType = null, ?int $refId = null, array $context = [], ?string $baseEventKey = null
    ): int {
        $recipients = $this->resolveActiveUsersByRoles($roles);
        $created = 0;
        foreach ($recipients as $userId) {
            $rowContext = $context;
            $rowContext['recipient_role'] = $rowContext['recipient_role'] ?? implode('+', $roles);
            $key = $baseEventKey !== null ? "{$baseEventKey}:user:{$userId}" : null;
            if ($this->insertRow($userId, $title, $message, $type, $refType, $refId, $rowContext, false, $key) !== null) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * Deliberate, explicit, all-audience system announcement. This is
     * the ONLY place `user_id` is allowed to be NULL -- a caller must
     * choose this method by name; there is no default parameter path
     * that reaches it by accident.
     */
    public function notifySystemBroadcast(string $title, string $message, string $type = 'info', ?string $uniqueEventKey = null): ?int
    {
        return $this->insertRow(null, $title, $message, $type, 'system', null, [], true, $uniqueEventKey);
    }

    // ================================================================
    // LOAN INSTALLMENT DUE/OVERDUE NOTIFICATIONS
    //
    // Stage 12-A found this previously read `loans.due_date` (a single
    // whole-loan date) instead of the authoritative, per-installment
    // `loan_installments.due_date` Stage 9.x actually maintains -- e.g.
    // loan LNS-000012 had an installment already overdue while its
    // whole-loan due_date sat over 3 months in the future. Fixed here:
    // reuses LoanModel::syncOverdueStatus() (already called on every
    // LoanController/RepaymentController request) to keep
    // loan_installments.status fresh, then reads that table directly.
    // Only ONE installment per loan is ever considered -- the earliest
    // still-unpaid one -- so a loan with several qualifying rows never
    // produces more than one notification per recipient per day (§15).
    // ================================================================

    /** Roles that currently have loan-management authority in this
     *  application -- reused verbatim from LoanController::requireWriteAccess(),
     *  not invented for this stage. */
    private const LOAN_MANAGEMENT_ROLES = ['admin', 'treasurer', 'loans_officer'];

    public function generateLoanNotifications(): void
    {
        try {
            (new LoanModel())->syncOverdueStatus(); // reuses the existing sync — never duplicated here

            $rows = $this->db->query(
                "SELECT li.id AS installment_id, li.due_date, li.remaining, li.status,
                        l.id AS loan_id, l.loan_number, l.member_id,
                        m.first_name, m.last_name
                 FROM `loan_installments` li
                 JOIN `loans` l ON l.id = li.loan_id
                 JOIN `members` m ON m.id = l.member_id
                 WHERE li.status IN ('pending','partial','overdue')
                   AND li.id = (
                       SELECT li2.id FROM `loan_installments` li2
                       WHERE li2.loan_id = li.loan_id AND li2.status IN ('pending','partial','overdue')
                       ORDER BY li2.due_date ASC, li2.installment_no ASC LIMIT 1
                   )
                   AND li.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
            )->fetchAll();

            $today = date('Y-m-d');
            foreach ($rows as $row) {
                $daysLeft = (int)((strtotime($row['due_date']) - strtotime($today)) / 86400);
                $memberName = trim($row['first_name'] . ' ' . $row['last_name']);
                $amount = 'Shs ' . number_format((float)$row['remaining'], 2);
                $context = [
                    'member_id' => (int)$row['member_id'],
                    'loan_id'   => (int)$row['loan_id'],
                    'event_date'=> $row['due_date'],
                    'action_url'=> APP_URL . '/index.php?page=loan-view&id=' . (int)$row['loan_id'],
                ];

                // reference_type/reference_id are kept exactly as before
                // ('loan'/'loan_overdue' + the LOAN id, not the installment
                // id) -- app/views/notifications/index.php's type filter and
                // "View Loan" link both depend on this specific contract,
                // and changing it would silently break an existing, working
                // view. The installment is only used internally, for
                // selecting the right row and building the dedup key.
                if ($daysLeft < 0) {
                    $daysOverdue = -$daysLeft;
                    $title = "Loan {$row['loan_number']} is overdue";
                    $msg = "Loan {$row['loan_number']} for {$memberName} is overdue by {$daysOverdue} day" . ($daysOverdue > 1 ? 's' : '') . ". Outstanding installment: {$amount}.";
                    $tier = 'overdue';
                    $type = 'critical';
                    $refType = 'loan_overdue';
                    $context['priority'] = 'high';
                } elseif ($daysLeft === 0) {
                    $title = "Loan {$row['loan_number']} is due today";
                    $msg = "Loan {$row['loan_number']} for {$memberName} is due today. Amount due: {$amount}.";
                    $tier = 'due_0';
                    $type = 'critical';
                    $refType = 'loan';
                    $context['priority'] = 'high';
                } elseif ($daysLeft <= 3) {
                    $title = "Loan {$row['loan_number']} due in {$daysLeft} day" . ($daysLeft > 1 ? 's' : '');
                    $msg = "Loan {$row['loan_number']} for {$memberName} is due in {$daysLeft} day" . ($daysLeft > 1 ? 's' : '') . ". Amount due: {$amount}.";
                    $tier = "due_{$daysLeft}";
                    $type = 'warning';
                    $refType = 'loan';
                } else {
                    $title = "Loan {$row['loan_number']} due in {$daysLeft} days";
                    $msg = "Loan {$row['loan_number']} for {$memberName} is due in {$daysLeft} days. Amount due: {$amount}.";
                    $tier = "due_{$daysLeft}";
                    $type = 'info';
                    $refType = 'loan';
                }

                // Deterministic per (installment, tier, day) identity -- the
                // date is baked into the key deliberately, since a loan that
                // stays overdue is *intended* to keep reminding daily (the
                // original behavior), not just once ever. Keyed by
                // installment, not loan, so two different installments of
                // the same loan (rare, but possible right after a payment
                // advances the "current" one) are never conflated.
                $baseKey = "loan_installment:{$row['installment_id']}:{$tier}:{$today}";
                $this->notifyRoles(self::LOAN_MANAGEMENT_ROLES, $title, $msg, $type, $refType, (int)$row['loan_id'], $context, $baseKey);
            }
        } catch (PDOException $e) {
            // Stage 12-H: previously swallowed with zero trace anywhere --
            // a real failure here would have looked identical to "no loans
            // are due soon", with no way to tell the two apart. Logged the
            // same way PushDeliveryService already logs its own failures,
            // never allowed to bubble up (this runs on every page load).
            $this->log(0, 'notification_generation_failed', 'generateLoanNotifications: ' . $e->getMessage());
        }
    }

    // ================================================================
    // FIXED DEPOSIT MATURITY REMINDERS (Stage 12-F)
    //
    // Mirrors generateLoanNotifications() exactly: traffic-driven (no
    // scheduler), reads the same syncMaturedFixedDeposits() this
    // controller already calls on every relevant page load (FD-2), tiers
    // by days-to-maturity, and is deterministically deduped per
    // (account, tier, day). Audience is admin/office_admin -- the same
    // roles FD-2's requireFdClosureRequestAccess() already grants closure-
    // request authority to, since the reminder's whole purpose is "someone
    // in that role needs to go request closure now that this has matured."
    // ================================================================

    private const FD_CLOSURE_REQUEST_ROLES = ['admin', 'office_admin'];

    public function generateFixedDepositMaturityNotifications(): void
    {
        try {
            (new MemberSavingsAccountModel())->syncMaturedFixedDeposits(); // reuses the existing FD-2 sync, never duplicated here

            $rows = $this->db->query(
                "SELECT a.id AS account_id, a.account_number, a.maturity_date, a.status,
                        a.principal_amount, a.expected_maturity_amount, h.member_id, m.first_name, m.last_name
                 FROM `member_savings_accounts` a
                 JOIN `savings_account_holders` h ON h.account_id = a.id AND h.role = 'primary'
                 JOIN `members` m ON m.id = h.member_id
                 WHERE a.account_type = 'fixed_deposit'
                   AND a.status IN ('active', 'matured')
                   AND a.maturity_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                   AND NOT EXISTS (
                       SELECT 1 FROM `fixed_deposit_closure_requests` r
                       WHERE r.savings_account_id = a.id AND r.active_marker = 1
                   )"
            )->fetchAll();

            $today = date('Y-m-d');
            foreach ($rows as $row) {
                $daysLeft = (int)((strtotime($row['maturity_date']) - strtotime($today)) / 86400);
                $memberName = trim($row['first_name'] . ' ' . $row['last_name']);
                $amount = 'Shs ' . number_format((float)$row['expected_maturity_amount'], 2);
                $context = [
                    'member_id'  => (int)$row['member_id'],
                    'event_date' => $row['maturity_date'],
                    'action_url' => APP_URL . '/index.php?page=savings-account-view&id=' . (int)$row['account_id'],
                ];

                if ($daysLeft <= 0) {
                    $title = "Fixed Deposit {$row['account_number']} has matured";
                    $msg = "Fixed Deposit {$row['account_number']} for {$memberName} has matured. Maturity amount: {$amount}. Closure can now be requested.";
                    $tier = 'matured';
                    $type = 'success';
                    $context['priority'] = 'high';
                } elseif ($daysLeft === 1) {
                    $title = "Fixed Deposit {$row['account_number']} matures tomorrow";
                    $msg = "Fixed Deposit {$row['account_number']} for {$memberName} matures tomorrow. Maturity amount: {$amount}.";
                    $tier = 'due_1';
                    $type = 'warning';
                } else {
                    $title = "Fixed Deposit {$row['account_number']} matures in {$daysLeft} days";
                    $msg = "Fixed Deposit {$row['account_number']} for {$memberName} matures in {$daysLeft} days. Maturity amount: {$amount}.";
                    $tier = "due_{$daysLeft}";
                    $type = 'info';
                }

                $baseKey = "fd_maturity:{$row['account_id']}:{$tier}:{$today}";
                $this->notifyRoles(self::FD_CLOSURE_REQUEST_ROLES, $title, $msg, $type, 'fixed_deposit', (int)$row['account_id'], $context, $baseKey);
            }
        } catch (PDOException $e) {
            // Stage 12-H: same visibility fix as generateLoanNotifications().
            $this->log(0, 'notification_generation_failed', 'generateFixedDepositMaturityNotifications: ' . $e->getMessage());
        }
    }

    // ================================================================
    // PAYMENT/ACTION NOTIFICATIONS
    //
    // These four had zero callers anywhere in the codebase (confirmed in
    // the Stage 12-A audit) -- upgraded here to the safe recipient API
    // per that report's §26 rather than left with an implicit-null
    // signature that would silently broadcast the moment someone wires
    // them up. Still uncalled; kept compatible for future use.
    // ================================================================

    public function notifyRepayment(int $recipientUserId, string $loanNumber, string $memberName, float $amount, ?int $memberId = null, ?int $loanId = null): void
    {
        $this->notifyUser(
            $recipientUserId, "Loan repayment recorded",
            "Repayment of Shs " . number_format($amount, 2) . " recorded for {$memberName} (Loan: {$loanNumber}).",
            'success', 'repayment', $loanId, ['member_id' => $memberId, 'loan_id' => $loanId]
        );
    }

    public function notifySavings(int $recipientUserId, string $memberName, float $amount, string $receipt, ?int $memberId = null, ?int $savingsAccountId = null): void
    {
        $this->notifyUser(
            $recipientUserId, "Savings deposited",
            "Savings of Shs " . number_format($amount, 2) . " deposited for {$memberName} (Receipt: {$receipt}).",
            'success', 'savings', $savingsAccountId, ['member_id' => $memberId, 'savings_account_id' => $savingsAccountId]
        );
    }

    public function notifyWithdrawal(int $recipientUserId, string $memberName, float $amount, ?int $memberId = null, ?int $savingsAccountId = null): void
    {
        $this->notifyUser(
            $recipientUserId, "Withdrawal processed",
            "Withdrawal of Shs " . number_format($amount, 2) . " processed for {$memberName}.",
            'success', 'withdrawal', $savingsAccountId, ['member_id' => $memberId, 'savings_account_id' => $savingsAccountId]
        );
    }

    /** A genuine cross-cutting system notice -- targets whichever
     *  roles the caller specifies; never defaults to "everyone". */
    public function notifySystemAlert(array $roles, string $title, string $message, string $type = 'warning'): void
    {
        $this->notifyRoles($roles, $title, $message, $type, 'system', null);
    }

    // ================================================================
    // INTERNAL VOUCHER MAKER-CHECKER NOTIFICATIONS
    // ================================================================

    /** Voucher approval authority is `chairman` only, per
     *  InternalVoucherController::requireApproverAccess() -- reused
     *  here exactly, not invented. Previously broadcast to every
     *  authenticated user (Stage 12-A root cause); now reaches only the
     *  users who can actually act on it. */
    private const VOUCHER_APPROVAL_ROLES = ['chairman'];

    public function notifyVoucherSubmitted(int $voucherId, string $voucherNumber, string $preparerName, float $amount): void
    {
        $this->notifyRoles(
            self::VOUCHER_APPROVAL_ROLES,
            "Voucher awaiting approval",
            "{$voucherNumber} (Shs " . number_format($amount, 2) . ") submitted by {$preparerName} needs approval.",
            'warning', 'internal_voucher', $voucherId,
            ['action_url' => APP_URL . '/index.php?page=internal-voucher-view&id=' . $voucherId],
            "voucher_submitted:{$voucherId}"
        );
    }

    /** Targeted at the preparer specifically -- their voucher's outcome. Unchanged targeting, now via the safe primitive. */
    public function notifyVoucherApproved(int $preparerId, int $voucherId, string $voucherNumber): void
    {
        $this->notifyUser(
            $preparerId, "Voucher approved", "Your voucher {$voucherNumber} has been approved.",
            'success', 'internal_voucher', $voucherId,
            ['action_url' => APP_URL . '/index.php?page=internal-voucher-view&id=' . $voucherId],
            "voucher_approved:{$voucherId}:user:{$preparerId}"
        );
    }

    public function notifyVoucherRejected(int $preparerId, int $voucherId, string $voucherNumber, string $reason): void
    {
        $this->notifyUser(
            $preparerId, "Voucher rejected", "Your voucher {$voucherNumber} was rejected: {$reason}",
            'critical', 'internal_voucher', $voucherId,
            ['action_url' => APP_URL . '/index.php?page=internal-voucher-view&id=' . $voucherId],
            "voucher_rejected:{$voucherId}:user:{$preparerId}"
        );
    }

    public function notifyVoucherPosted(int $preparerId, int $voucherId, string $voucherNumber, string $entryNumber): void
    {
        $this->notifyUser(
            $preparerId, "Voucher posted", "Voucher {$voucherNumber} has been posted to the ledger as {$entryNumber}.",
            'success', 'internal_voucher', $voucherId,
            ['action_url' => APP_URL . '/index.php?page=internal-voucher-view&id=' . $voucherId],
            "voucher_posted:{$voucherId}:user:{$preparerId}"
        );
    }

    // ================================================================
    // READ NOTIFICATIONS
    //
    // One consistent visibility rule, used identically by every read
    // method below: a row belonging to this user, OR an explicit
    // broadcast. Never "OR user_id IS NULL" again.
    // ================================================================

    private const VISIBILITY_SQL = '(`user_id` = ? OR `is_broadcast` = 1)';

    public function unreadCount(int $userId): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `notifications` WHERE `is_read` = 0 AND " . self::VISIBILITY_SQL
            );
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function getLatest(int $userId, int $limit = 8): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `notifications` WHERE " . self::VISIBILITY_SQL . "
                 ORDER BY `created_at` DESC, `id` DESC LIMIT ?"
            );
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function search(int $userId, string $filter = '', string $refType = '', int $page = 1, int $perPage = 20): array
    {
        try {
            $where = [self::VISIBILITY_SQL];
            $params = [$userId];

            if ($filter === 'unread') {
                $where[] = '`is_read` = 0';
            } elseif ($filter === 'read') {
                $where[] = '`is_read` = 1';
            }

            if ($refType !== '') {
                $where[] = '`reference_type` = ?';
                $params[] = $refType;
            }

            $whereSQL = 'WHERE ' . implode(' AND ', $where);
            $offset = ($page - 1) * $perPage;

            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `notifications` {$whereSQL}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listStmt = $this->db->prepare(
                "SELECT * FROM `notifications` {$whereSQL} ORDER BY `created_at` DESC, `id` DESC LIMIT ? OFFSET ?"
            );
            $i = 1;
            foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
            $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
            $listStmt->bindValue($i, $offset, PDO::PARAM_INT);
            $listStmt->execute();

            return [
                'rows'  => $listStmt->fetchAll(),
                'total' => $total,
                'pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
            ];
        } catch (PDOException $e) {
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
    }

    // ================================================================
    // OPERATIONAL AUDIT VIEW (Stage 12-H) — read-only, cross-user.
    //
    // Deliberately separate from search(): search() is scoped by
    // VISIBILITY_SQL to one recipient because it powers the personal
    // notification centre. This method has NO such scope -- it exists
    // solely so an authorized operator can answer "what notification
    // events happened, who received them, were they read, was push
    // attempted" across the whole system, which search() structurally
    // cannot do without either weakening personal ownership or adding a
    // parallel table. Never call this from a personal-facing action;
    // gated to admin only at the controller level (see
    // NotificationController::requireAuditAccess() for why it is
    // admin-only, not the broader pair used by some other read-only
    // operational views). Mutates nothing.
    // ================================================================

    public function auditSearch(string $refType = '', int $page = 1, int $perPage = 25): array
    {
        try {
            $where = [];
            $params = [];

            if ($refType !== '') {
                $where[] = 'n.`reference_type` = ?';
                $params[] = $refType;
            }

            $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $offset = ($page - 1) * $perPage;

            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `notifications` n {$whereSQL}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listStmt = $this->db->prepare(
                "SELECT n.*, u.full_name AS recipient_name, r.name AS recipient_role_current
                 FROM `notifications` n
                 LEFT JOIN `users` u ON u.id = n.user_id
                 LEFT JOIN `roles` r ON r.id = u.role_id
                 {$whereSQL}
                 ORDER BY n.`created_at` DESC, n.`id` DESC LIMIT ? OFFSET ?"
            );
            $i = 1;
            foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
            $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
            $listStmt->bindValue($i, $offset, PDO::PARAM_INT);
            $listStmt->execute();
            $rows = $listStmt->fetchAll();

            // Best-effort push-status annotation, derived from
            // PushDeliveryService's own activity_logs entries (it logs
            // 'push_delivered'/'push_delivery_failed' with the
            // notification id embedded in the description -- confirmed by
            // reading that class directly, not guessed). Never a second
            // source of truth for delivery itself, purely diagnostic
            // display; absence of a matching log row means "not
            // attempted" (broadcast rows, or the recipient had no active
            // subscription), not "failed".
            if ($rows) {
                $ids = array_column($rows, 'id');
                // push_delivered logs "Notification #{id} pushed..." (space
                // after the id); push_delivery_failed logs "Notification
                // #{id}: {msg}" (colon after the id) -- confirmed against
                // PushDeliveryService's exact two log() call sites. Both
                // terminators are matched explicitly (never a bare "%#{id}%"
                // substring) so id=1 can never match a logged id=10, 11,
                // 100, etc.
                $likeClauses = [];
                $likeParams  = [];
                foreach ($ids as $id) {
                    $likeClauses[] = 'description LIKE ? OR description LIKE ?';
                    $likeParams[]  = "%Notification #{$id} %";
                    $likeParams[]  = "%Notification #{$id}:%";
                }
                $logStmt = $this->db->prepare(
                    "SELECT action, description FROM `activity_logs`
                     WHERE action IN ('push_delivered','push_delivery_failed')
                       AND (" . implode(' OR ', $likeClauses) . ")"
                );
                $logStmt->execute($likeParams);
                $pushLogs = $logStmt->fetchAll();

                foreach ($rows as &$row) {
                    $row['push_status'] = 'not attempted';
                    foreach ($pushLogs as $pl) {
                        if (str_contains($pl['description'], "Notification #{$row['id']} ")
                            || str_contains($pl['description'], "Notification #{$row['id']}:")
                        ) {
                            $row['push_status'] = $pl['action'] === 'push_delivered' ? 'delivered' : 'failed';
                            break;
                        }
                    }
                }
                unset($row);
            }

            return [
                'rows'  => $rows,
                'total' => $total,
                'pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
            ];
        } catch (PDOException $e) {
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
    }

    // ================================================================
    // MARK AS READ / DELETE — ownership-enforced (Stage 12-B IDOR fix)
    //
    // Stage 12-A found these took a bare id with no ownership check at
    // all. Both now require the acting user's id and only ever affect a
    // row that actually belongs to them -- a broadcast row (user_id
    // NULL) is deliberately excluded from personal mark/delete, since
    // there is no single owner to attribute that action to; it remains
    // visible to everyone via VISIBILITY_SQL but isn't personally
    // dismissible in this stage (documented limitation, not a bug).
    // ================================================================

    public function markRead(int $id, int $userId): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE `notifications` SET `is_read` = 1, `read_at` = NOW() WHERE `id` = ? AND `user_id` = ?"
            );
            $stmt->execute([$id, $userId]);
        } catch (PDOException $e) {}
    }

    public function markAllRead(int $userId): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE `notifications` SET `is_read` = 1, `read_at` = NOW()
                 WHERE `is_read` = 0 AND `user_id` = ?"
            );
            $stmt->execute([$userId]);
        } catch (PDOException $e) {}
    }

    public function deleteNotification(int $id, int $userId): void
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM `notifications` WHERE `id` = ? AND `user_id` = ?");
            $stmt->execute([$id, $userId]);
        } catch (PDOException $e) {}
    }

    // ================================================================
    // ACTIVITY LOG
    // ================================================================

    public function log(int $userId, string $action, string $desc = ''): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`)
                 VALUES (?,?,?,?)"
            );
            // Stage 12-H: activity_logs.user_id has a real FK to users(id) --
            // a literal 0 (the "system" placeholder used by the two new
            // notification-generation-failure call sites below) is not a
            // valid user id and would violate it, silently defeating this
            // very method's own purpose via the catch block right here.
            // Converted to NULL exactly like PushDeliveryService::log()
            // already does for the identical reason.
            $stmt->execute([$userId ?: null, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }
}
