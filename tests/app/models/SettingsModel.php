<?php
/**
 * SettingsModel — key-value system settings + admin features.
 */
class SettingsModel extends Model
{
    protected string $table      = 'settings';
    protected string $primaryKey = 'id';

    /** Get a single setting value, with a fallback default. */
    public function get(string $key, string $default = ''): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_val FROM `settings` WHERE setting_key = ? LIMIT 1"
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? (string)$row['setting_val'] : $default;
        } catch (PDOException $e) {
            return $default;
        }
    }

    /** Get all settings as key => ['value'=>..., 'label'=>...] map. */
    public function getAllSettings(): array
    {
        try {
            $rows = $this->db->query(
                "SELECT setting_key, setting_val, label FROM `settings` ORDER BY id"
            )->fetchAll();
            $map = [];
            foreach ($rows as $r) {
                $map[$r['setting_key']] = ['value' => $r['setting_val'], 'label' => $r['label']];
            }
            return $map;
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Update or insert a setting value. */
    public function set(string $key, string $value): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `settings` (setting_key, setting_val)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
            );
            $stmt->execute([$key, $value]);
        } catch (PDOException $e) {}
    }

    /** Withdrawal policy helper — returns [withdrawal_pct, retained_pct, max_per_year] */
    public function withdrawalPolicy(): array
    {
        return [
            'withdrawal_pct'       => (float)$this->get('withdrawal_pct',       '50'),
            'retained_pct'         => (float)$this->get('retained_pct',         '50'),
            'max_withdrawals_year' => (int)  $this->get('max_withdrawals_year',  '1'),
        ];
    }

    /** Loan settings helper */
    public function loanPolicy(): array
    {
        return [
            'loan_threshold'  => (float)$this->get('loan_threshold',  '1000000'),
            'loan_rate_below' => (float)$this->get('loan_rate_below', '10'),
            'loan_rate_above' => (float)$this->get('loan_rate_above', '5'),
        ];
    }

    /**
     * Member activity policy — what an Active member must do each calendar
     * month to stay Active, and how many months of missing that before
     * they're considered Dormant.
     */
    public function memberActivityPolicy(): array
    {
        return [
            'min_monthly_deposits' => (int)  $this->get('min_monthly_deposits', '2'),
            'min_monthly_savings'  => (float)$this->get('min_monthly_savings',  '40000'),
            'dormancy_months'      => (int)  $this->get('dormancy_months',      '6'),
        ];
    }

    // ================================================================
    // FINANCIAL YEARS
    // ================================================================

    public function getFinancialYearsList(): array
    {
        try {
            return $this->db->query(
                "SELECT fy.*, u.full_name AS created_by_name
                 FROM `financial_years` fy
                 LEFT JOIN `users` u ON u.id = fy.created_by
                 ORDER BY fy.start_date DESC"
            )->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function createFinancialYear(string $name, string $start, string $end, int $userId): int|false
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `financial_years` (`name`,`start_date`,`end_date`,`status`,`created_by`)
                 VALUES (?,?,?,'pending',?)"
            );
            $stmt->execute([$name, $start, $end, $userId]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) { return false; }
    }

    public function updateFinancialYear(int $id, string $name, string $start, string $end): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE `financial_years` SET `name`=?, `start_date`=?, `end_date`=? WHERE `id`=?"
            );
            return $stmt->execute([$name, $start, $end, $id]);
        } catch (PDOException $e) { return false; }
    }

    public function activateFinancialYear(int $id): void
    {
        try {
            $this->db->exec("UPDATE `financial_years` SET `status`='pending' WHERE `status`='active'");
            $stmt = $this->db->prepare("UPDATE `financial_years` SET `status`='active' WHERE `id`=?");
            $stmt->execute([$id]);
        } catch (PDOException $e) {}
    }

    public function closeFinancialYear(int $id): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE `financial_years` SET `status`='closed' WHERE `id`=?");
            $stmt->execute([$id]);
        } catch (PDOException $e) {}
    }

    public function getActiveFinancialYear(): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `financial_years` WHERE `status`='active' LIMIT 1");
            $stmt->execute();
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    // ================================================================
    // USER MANAGEMENT
    // ================================================================

    public function getAllUsers(): array
    {
        try {
            // Stage 14-B: LEFT JOIN members so the user list can display
            // which member a portal account is linked to without a
            // separate lookup per row -- member_id is NULL for every
            // staff account, so this is a no-op for them.
            return $this->db->query(
                "SELECT u.*, r.label AS role_label, r.name AS role_name,
                        m.member_number, m.first_name AS member_first_name, m.last_name AS member_last_name
                 FROM `users` u
                 JOIN `roles` r ON r.id = u.role_id
                 LEFT JOIN `members` m ON m.id = u.member_id
                 ORDER BY u.full_name ASC"
            )->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getAllRoles(): array
    {
        try {
            return $this->db->query("SELECT * FROM `roles` ORDER BY id")->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function createUser(array $data): int|false
    {
        try {
            $columns      = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $stmt = $this->db->prepare("INSERT INTO `users` ({$columns}) VALUES ({$placeholders})");
            $stmt->execute(array_values($data));
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) { return false; }
    }

    public function updateUser(int $id, array $data): bool
    {
        try {
            $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
            $values = array_values($data);
            $values[] = $id;
            $stmt = $this->db->prepare("UPDATE `users` SET {$set} WHERE `id` = ?");
            return $stmt->execute($values);
        } catch (PDOException $e) { return false; }
    }

    public function toggleUser(int $id): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE `users` SET `is_active` = IF(`is_active`=1,0,1) WHERE `id`=?"
            );
            $stmt->execute([$id]);
        } catch (PDOException $e) {}
    }

    // ================================================================
    // DATABASE MANAGEMENT
    // ================================================================

    public function getAllTables(): array
    {
        try {
            return $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) { return []; }
    }

    public function recordBackup(string $filename, int $size, int $userId): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `database_backups` (`filename`,`file_size`,`created_by`) VALUES (?,?,?)"
            );
            $stmt->execute([$filename, $size, $userId]);
        } catch (PDOException $e) {}
    }

    public function getBackupHistory(): array
    {
        try {
            return $this->db->query(
                "SELECT b.*, u.full_name AS created_by_name
                 FROM `database_backups` b
                 LEFT JOIN `users` u ON u.id = b.created_by
                 ORDER BY b.created_at DESC"
            )->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ================================================================
    // AUDIT LOGS
    // ================================================================

    public function getAuditLogs(string $search = '', string $action = '', int $page = 1, int $perPage = 25): array
    {
        try {
            $where = [];
            $params = [];

            if ($search !== '') {
                $like = '%' . $search . '%';
                $where[] = '(a.description LIKE ? OR u.full_name LIKE ? OR a.ip_address LIKE ?)';
                array_push($params, $like, $like, $like);
            }
            if ($action !== '') {
                $where[] = 'a.action = ?';
                $params[] = $action;
            }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $offset = ($page - 1) * $perPage;

            $from = "FROM `activity_logs` a LEFT JOIN `users` u ON u.id = a.user_id {$whereSQL}";

            $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listStmt = $this->db->prepare(
                "SELECT a.*, u.full_name AS user_name
                 {$from}
                 ORDER BY a.created_at DESC
                 LIMIT ? OFFSET ?"
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

    /** Chairman dashboard "Recent Decisions" (2026-09): real governance-
     *  trail entries from journal_entry_audit -- decision-type actions
     *  only (approved/rejected/reversed/disbursed), never the maker-side
     *  actions (created/submitted/posted) that clutter the same table.
     *  If a module has never had a decision recorded (e.g. loans, since
     *  every existing loan predates the approval workflow), it simply
     *  contributes nothing -- no placeholder rows are fabricated. */
    public function recentDecisions(int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.*, u.full_name AS user_name
                 FROM `journal_entry_audit` a
                 LEFT JOIN `users` u ON u.id = a.user_id
                 WHERE a.action IN ('approved','rejected','reversed','disbursed')
                 ORDER BY a.created_at DESC
                 LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /** Chairman dashboard "Recent Activity" (2026-09): real operational
     *  entries from activity_logs -- excludes pure page-view/session noise
     *  (report_viewed, weekly_report_viewed, login, logout) so the feed
     *  reads as "things that happened," not "pages that were opened." */
    public function recentActivity(int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.*, u.full_name AS user_name
                 FROM `activity_logs` a
                 LEFT JOIN `users` u ON u.id = a.user_id
                 WHERE a.action NOT IN ('report_viewed','weekly_report_viewed','login','logout')
                 ORDER BY a.created_at DESC
                 LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
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
            $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }

    /**
     * Most recent activity_logs row for a given action whose description
     * starts with $descriptionPrefix, if one exists within the last
     * $withinMinutes minutes -- powers an idempotency guard (e.g. Stage
     * 13-E's 13E-MAIL-01: blocking an accidental duplicate bulk
     * statement send) without a dedicated new table. Reuses the exact
     * audit-log row already written for the action being guarded, so
     * the guard and the audit trail can never drift apart.
     */
    public function recentLog(string $action, string $descriptionPrefix, int $withinMinutes): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `activity_logs`
                 WHERE action = ? AND description LIKE ? AND created_at >= (NOW() - INTERVAL ? MINUTE)
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute([$action, $descriptionPrefix . '%', $withinMinutes]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }
}
