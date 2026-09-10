<?php
/**
 * MemberModel
 * Handles all database operations for the members table.
 */
class MemberModel extends Model
{
    protected string $table      = 'members';
    protected string $primaryKey = 'id';

    // ----------------------------------------------------------------
    // Counts (used by Dashboard + Members list)
    // ----------------------------------------------------------------

    public function countAll(): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `members`");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0; // table doesn't exist yet
        }
    }

    public function countActive(): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `members` WHERE `status` = 'active'");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function countInactive(): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `members` WHERE `status` = 'inactive'");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function countNewThisMonth(): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `members`
                 WHERE MONTH(`join_date`) = MONTH(CURDATE()) AND YEAR(`join_date`) = YEAR(CURDATE())"
            );
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function countDormant(): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `members` WHERE `status` = 'dormant'");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    // ----------------------------------------------------------------
    // Dormant status sync
    //
    // 'active'/'inactive' remain a manual admin choice (still a member vs
    // left/suspended). 'dormant' is computed: an active member becomes
    // dormant once `dormancy_months` calendar months have passed with no
    // month meeting the club's minimum deposits/amount — measured from
    // their last qualifying month, or their join date if they've never
    // had one. Mirrors LoanModel::syncOverdueStatus()'s pattern: call this
    // wherever member status is displayed or filtered, and it self-heals.
    //
    // Stage 6-C: this method now only ever considers and writes members
    // whose `status_source` is 'automatic' (both the initial SELECT scope
    // AND the per-row UPDATE's own WHERE clause carry that condition, so
    // even a status_source flip that happens between the SELECT and this
    // row's UPDATE cannot be silently overwritten -- see the Stage 6-C
    // report's race/atomicity section). A member whose status_source is
    // 'manual' (set by changeStatus() or an explicit Edit-form status
    // change) is never touched here, in either direction, regardless of
    // which of the three statuses they were manually set to. This closes
    // the gap the Stage 6-B audit found: previously this recompute could
    // (and, per that audit's live simulation, reliably did) revert a
    // manual override on the very next call.
    // ----------------------------------------------------------------
    public function syncDormantStatus(): void
    {
        try {
            require_once APP_PATH . '/models/SettingsModel.php';
            $policy = (new SettingsModel())->memberActivityPolicy();

            $members = $this->db->query(
                "SELECT id, member_number, join_date FROM `members`
                 WHERE status IN ('active','dormant') AND status_source = 'automatic'"
            )->fetchAll();
            if (!$members) return;

            $qualStmt = $this->db->prepare(
                "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym
                 FROM `savings`
                 WHERE member_id = ?
                 GROUP BY ym
                 HAVING COUNT(*) >= ? AND SUM(COALESCE(credit,0)-COALESCE(debit,0)) >= ?
                 ORDER BY ym DESC LIMIT 1"
            );
            $updStmt = $this->db->prepare(
                "UPDATE `members` SET `status` = ?, `status_source` = 'automatic'
                 WHERE `id` = ? AND `status` != ? AND `status_source` = 'automatic'"
            );

            $nowIndex = (int)date('Y') * 12 + (int)date('n');

            foreach ($members as $m) {
                $qualStmt->execute([$m['id'], $policy['min_monthly_deposits'], $policy['min_monthly_savings']]);
                $lastQualifying = $qualStmt->fetchColumn();

                if ($lastQualifying) {
                    [$y, $mo]    = explode('-', $lastQualifying);
                    $anchorIndex = (int)$y * 12 + (int)$mo;
                } else {
                    $join        = new \DateTime($m['join_date']);
                    $anchorIndex = (int)$join->format('Y') * 12 + (int)$join->format('n');
                }

                $newStatus = ($nowIndex - $anchorIndex) >= $policy['dormancy_months'] ? 'dormant' : 'active';
                $updStmt->execute([$newStatus, $m['id'], $newStatus]);
                if ($updStmt->rowCount() > 0) {
                    try {
                        $this->db->prepare(
                            "INSERT INTO `activity_logs` (`user_id`,`action`,`description`,`ip_address`)
                             VALUES (NULL,?,?,?)"
                        )->execute([
                            'member_status_auto_synced',
                            "Member {$m['member_number']} status auto-synced to {$newStatus} by dormancy policy",
                            $_SERVER['REMOTE_ADDR'] ?? null,
                        ]);
                    } catch (PDOException $e) {}
                }
            }
        } catch (PDOException $e) {}
    }

    // ----------------------------------------------------------------
    // Recent members for dashboard widget
    // ----------------------------------------------------------------

    public function recent(int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `members` ORDER BY `created_at` DESC LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return []; // table doesn't exist yet
        }
    }

    // ----------------------------------------------------------------
    // Auto member number generation
    // ----------------------------------------------------------------

    /**
     * Returns the next member number, e.g. EMP0001, EMP0042.
     * Thread-safe: reads MAX numeric suffix.
     */
    public function generateMemberNumber(): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(CAST(SUBSTRING(`member_number`, 4) AS UNSIGNED)) AS max_seq
                 FROM `members`"
            );
            $stmt->execute();
            $row  = $stmt->fetch();
            $next = (int) ($row['max_seq'] ?? 0) + 1;
            return 'EMP' . str_pad($next, 4, '0', STR_PAD_LEFT);
        } catch (PDOException $e) {
            return 'EMP0001';
        }
    }

    // ----------------------------------------------------------------
    // Paginated search / filter
    // ----------------------------------------------------------------

    /**
     * @return array{rows:array, total:int, pages:int}
     */
    public function search(
        string $term     = '',
        string $status   = '',
        string $dateFrom = '',
        string $dateTo   = '',
        int    $page     = 1,
        int    $perPage  = 15
    ): array {
        $where  = [];
        $params = [];

        if ($term !== '') {
            $like    = '%' . $term . '%';
            $where[] = '(
                `member_number`  LIKE ? OR
                `account_number` LIKE ? OR
                `first_name`     LIKE ? OR
                `last_name`      LIKE ? OR
                `phone`          LIKE ? OR
                `email`          LIKE ? OR
                `national_id`    LIKE ?
            )';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
        }

        if (in_array($status, ['active', 'inactive', 'dormant'], true)) {
            $where[]  = '`status` = ?';
            $params[] = $status;
        }

        if ($dateFrom !== '') {
            $where[]  = '`join_date` >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo !== '') {
            $where[]  = '`join_date` <= ?';
            $params[] = $dateTo;
        }

        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $offset   = ($page - 1) * $perPage;

        // Count
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `members` {$whereSQL}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Rows
        $listStmt = $this->db->prepare(
            "SELECT * FROM `members` {$whereSQL}
             ORDER BY `member_number` ASC
             LIMIT ? OFFSET ?"
        );
        // Bind typed so PDO doesn't cast int as string
        $i = 1;
        foreach ($params as $v) {
            $listStmt->bindValue($i++, $v);
        }
        $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($i,   $offset,  PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'rows'  => $listStmt->fetchAll(),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    // ----------------------------------------------------------------
    // Uniqueness checks (exclude current record on edit)
    // ----------------------------------------------------------------

    public function nationalIdExists(string $val, ?int $excludeId = null): bool
    {
        return $this->fieldExists('national_id', $val, $excludeId);
    }

    public function phoneExists(string $val, ?int $excludeId = null): bool
    {
        return $this->fieldExists('phone', $val, $excludeId);
    }

    public function emailExists(string $val, ?int $excludeId = null): bool
    {
        if ($val === '') return false;
        return $this->fieldExists('email', $val, $excludeId);
    }

    private function fieldExists(string $col, string $val, ?int $excludeId): bool
    {
        $sql    = "SELECT COUNT(*) FROM `members` WHERE `{$col}` = ?";
        $params = [$val];
        if ($excludeId !== null) {
            $sql    .= ' AND `id` != ?';
            $params[] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    // ----------------------------------------------------------------
    // Explicit status change (Stage 6-A; status_source added Stage 6-C)
    //
    // Replaces the old binary toggleStatus(), which could only ever
    // produce active<->inactive and silently mis-handled dormant members
    // (see results/stage6_member_status_toggle_audit.md). Every one of
    // the three statuses is now an explicit, named target the caller
    // must choose — including setting a status to its own current value.
    //
    // Stage 6-C: every call to this method is, by definition, an
    // authorized administrator's explicit status decision (it is only
    // ever reached via MemberController::changeStatus()'s dedicated,
    // admin-only "Change Status" action) -- so it always sets
    // `status_source = 'manual'`, even when $newStatus matches the
    // member's current value. Re-affirming the same status is still a
    // deliberate action that establishes/renews manual authority; it is
    // NOT the same as an untouched field on the general Edit-Member form
    // (that preservation logic lives in MemberController::collectInput()/
    // handleSave(), which only sets status_source when the submitted
    // status actually differs from the member's existing one).
    // ----------------------------------------------------------------
    public function changeStatus(int $id, string $newStatus): bool
    {
        if (!in_array($newStatus, ['active', 'inactive', 'dormant'], true)) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE `members` SET `status` = ?, `status_source` = 'manual' WHERE `id` = ?"
        );
        return $stmt->execute([$newStatus, $id]);
    }

    // ----------------------------------------------------------------
    // Activity logging (proxies to activity_logs table)
    // ----------------------------------------------------------------

    public function log(int $userId, string $action, string $desc = ''): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `activity_logs` (`user_id`,`action`,`description`,`ip_address`)
             VALUES (?,?,?,?)"
        );
        $stmt->execute([
            $userId,
            $action,
            $desc,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    // ----------------------------------------------------------------
    // Account Number
    // ----------------------------------------------------------------

    /**
     * Check uniqueness of account_number, optionally excluding a member on edit.
     */
    public function accountNumberExists(string $val, ?int $excludeId = null): bool
    {
        if ($val === '') return false;
        return $this->fieldExists('account_number', $val, $excludeId);
    }

    /**
     * Stage 14-B: find a member by their member_number -- the human/
     * business identifier used by the bulk account-provisioning CSV
     * template, resolved to members.id server-side (never trusting a
     * raw id from the upload).
     */
    public function findByMemberNumber(string $memberNumber): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `members` WHERE `member_number` = ? LIMIT 1"
            );
            $stmt->execute([trim($memberNumber)]);
            return $stmt->fetch() ?: false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Find a member by their account number.
     */
    public function findByAccountNumber(string $accountNumber): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `members` WHERE `account_number` = ? LIMIT 1"
            );
            $stmt->execute([trim($accountNumber)]);
            return $stmt->fetch() ?: false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Generate a suggested account number (next sequential 6-digit number).
     * Admin can override this manually.
     */
    public function generateAccountNumber(): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(CAST(`account_number` AS UNSIGNED)) AS max_acc
                 FROM `members`
                 WHERE `account_number` REGEXP '^[0-9]+$'"
            );
            $stmt->execute();
            $row  = $stmt->fetch();
            $next = (int)($row['max_acc'] ?? 100000) + 1;
            return (string)$next;
        } catch (PDOException $e) {
            return '100001';
        }
    }

    // ----------------------------------------------------------------
    // Expanded relationship list (static helper)
    // ----------------------------------------------------------------

    public static function relationshipOptions(): array
    {
        return [
            'Father', 'Mother', 'Husband', 'Wife',
            'Son', 'Daughter', 'Brother', 'Sister',
            'Uncle', 'Aunt', 'Grandfather', 'Grandmother',
            'Cousin', 'Nephew', 'Niece',
            'Friend', 'Colleague', 'Workmate', 'Business Partner',
            'Guardian', 'Parent', 'Spouse', 'Relative',
            'Other',
        ];
    }

    // ----------------------------------------------------------------
    // Search — also searches account_number
    // ----------------------------------------------------------------

    // ----------------------------------------------------------------
    // Savings Accounts Stage 3 — atomic member + compulsory account
    // creation. Registration-fee charging (FeeModel::chargeRegistrationFee())
    // deliberately stays OUTSIDE this transaction and outside this method,
    // called by the controller exactly where it already was: it silently
    // swallows PDOException and never throws (see FeeModel::chargeMember()/
    // chargeRegistrationFee()), so it was never actually protected by any
    // enclosing transaction before this change either -- moving it inside
    // would be an unrequested behavior change, not a safety improvement.
    // ----------------------------------------------------------------

    /**
     * Create a member and their compulsory savings account atomically.
     * Never leaves a member without their compulsory account, or an
     * orphaned account without its member -- if account/holder creation
     * fails for any reason, the member row is rolled back too.
     *
     * @return array{member_id:int, account_id:int, account_number:string}
     */
    public function createWithCompulsoryAccount(array $memberData, int $userId): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $memberId = $this->create($memberData);
            if ($memberId === false) {
                throw new RuntimeException('Failed to create member.');
            }

            // status intentionally omitted -- createAccount() defaults to
            // 'active', per the Stage 1 design (approved, and reaffirmed
            // when this exact question came up again in Stage 3): account
            // lifecycle status and compulsory qualification are two
            // separate concepts, never coupled. Qualification progress is
            // tracked entirely via qualification_met_date /
            // checkCompulsoryQualification(), not via account status.
            $accountModel = new MemberSavingsAccountModel();
            $accountId = $accountModel->createAccount(
                [
                    'account_type' => 'compulsory',
                    'opened_date'  => $memberData['join_date'],
                ],
                [['member_id' => $memberId, 'role' => 'primary']],
                $userId
            );
            $account = $accountModel->getAccount($accountId);

            if ($ownTransaction) {
                $this->db->commit();
            }

            return [
                'member_id'      => $memberId,
                'account_id'     => $accountId,
                'account_number' => $account['account_number'],
            ];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
