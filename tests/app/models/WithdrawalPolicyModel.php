<?php
/**
 * WithdrawalPolicyModel — Configurable, account-type-driven, effective-dated
 * savings withdrawal policy (Stage 17 Part E-0).
 *
 * This model does NOT process withdrawals, touch the savings ledger, or post
 * journal entries. It only stores and resolves POLICY (what percentages are
 * allowed) and exposes pure, read-only calculation helpers. The actual
 * withdrawal transaction engine (a later stage) is expected to call
 * getActivePolicy() as its single source of truth rather than querying this
 * table directly in multiple places.
 */
class WithdrawalPolicyModel extends Model
{
    protected string $table      = 'savings_withdrawal_policies';
    protected string $primaryKey = 'id';

    public const ACCOUNT_TYPES = ['compulsory', 'voluntary', 'joint', 'corporate'];
    public const FREQUENCIES   = ['any_time', 'once_per_financial_year', 'monthly', 'quarterly', 'half_yearly', 'custom'];

    // ================================================================
    // CENTRALIZED POLICY RESOLUTION
    // ================================================================

    /**
     * The single authoritative way to ask "what withdrawal policy applies to
     * this account type on this date?" Returns null if no active,
     * date-covering policy exists for the type -- callers must treat that as
     * "withdrawals are not currently configured/permitted", never as an
     * implicit default.
     */
    public function getActivePolicy(string $accountType, ?string $date = null): array|null
    {
        $date = $date ?? date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT * FROM `savings_withdrawal_policies`
            WHERE `account_type` = ?
              AND `status` = 'active'
              AND `effective_from` <= ?
              AND (`effective_to` IS NULL OR `effective_to` >= ?)
            ORDER BY `effective_from` DESC
            LIMIT 1
        ");
        $stmt->execute([$accountType, $date, $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** One row per account_type, whatever is active today (or null if none) -- for the admin UI table. */
    public function getAllCurrentPolicies(): array
    {
        $out = [];
        foreach (self::ACCOUNT_TYPES as $type) {
            $out[$type] = $this->getActivePolicy($type);
        }
        return $out;
    }

    /** Full version history for one account type, newest effective_from first. */
    public function getHistoryForAccountType(string $accountType): array
    {
        $stmt = $this->db->prepare("
            SELECT swp.*, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
            FROM `savings_withdrawal_policies` swp
            LEFT JOIN `users` cu ON cu.id = swp.created_by
            LEFT JOIN `users` uu ON uu.id = swp.updated_by
            WHERE swp.account_type = ?
            ORDER BY swp.effective_from DESC, swp.id DESC
        ");
        $stmt->execute([$accountType]);
        return $stmt->fetchAll();
    }

    // ================================================================
    // VALIDATION
    // ================================================================

    /** @return string[] Empty array means valid. */
    public function validatePolicy(array $data): array
    {
        $errors = [];

        if (!in_array($data['account_type'] ?? '', self::ACCOUNT_TYPES, true)) {
            $errors[] = 'A valid account type is required.';
        }
        if (!array_key_exists('withdrawal_enabled', $data)) {
            $errors[] = 'withdrawal_enabled is required.';
        }

        $max = $data['maximum_withdrawal_percent'] ?? null;
        if (!is_numeric($max) || (float)$max < 0 || (float)$max > 100) {
            $errors[] = 'Maximum withdrawal percent must be between 0 and 100.';
        }

        $share = $data['share_conversion_percent'] ?? null;
        if (!is_numeric($share) || (float)$share < 0 || (float)$share > 100) {
            $errors[] = 'Share conversion percent must be between 0 and 100.';
        }

        if (is_numeric($max) && is_numeric($share) && ((float)$max + (float)$share) > 100.0001) {
            $errors[] = 'Maximum withdrawal percent + share conversion percent cannot exceed 100%.';
        }

        if (!in_array($data['frequency'] ?? '', self::FREQUENCIES, true)) {
            $errors[] = 'A valid frequency is required.';
        }

        if (empty($data['effective_from']) || !DateTime::createFromFormat('Y-m-d', $data['effective_from'])) {
            $errors[] = 'A valid effective_from date is required.';
        }
        if (!empty($data['effective_to'])) {
            if (!DateTime::createFromFormat('Y-m-d', $data['effective_to'])) {
                $errors[] = 'effective_to must be a valid date.';
            } elseif (!empty($data['effective_from']) && $data['effective_to'] < $data['effective_from']) {
                $errors[] = 'effective_to cannot be before effective_from.';
            }
        }

        if (isset($data['minimum_balance']) && $data['minimum_balance'] !== null && $data['minimum_balance'] !== '') {
            if (!is_numeric($data['minimum_balance']) || (float)$data['minimum_balance'] < 0) {
                $errors[] = 'Minimum balance, if set, must be zero or greater.';
            }
        }

        return $errors;
    }

    /**
     * True if an ACTIVE-status policy already exists for this account type
     * whose [effective_from, effective_to] range overlaps the given range
     * (open-ended effective_to treated as "forever"). $excludeId lets an
     * update check against everything except itself.
     */
    public function hasOverlap(string $accountType, string $effectiveFrom, ?string $effectiveTo, ?int $excludeId = null): bool
    {
        $sql = "
            SELECT COUNT(*) FROM `savings_withdrawal_policies`
            WHERE account_type = ? AND status = 'active'
              AND effective_from <= COALESCE(?, '9999-12-31')
              AND (effective_to IS NULL OR effective_to >= ?)
        ";
        $params = [$accountType, $effectiveTo, $effectiveFrom];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ================================================================
    // CREATE / VERSION (create-only history model -- see class docblock;
    // an already-effective row is never mutated in place)
    // ================================================================

    /**
     * Create a new policy version. If an active policy already exists for
     * this account type and its range would overlap the new one, it is
     * automatically closed out (effective_to = day before the new
     * effective_from) in the same transaction -- this is the "supersede"
     * path used when an admin changes a policy going forward. Throws
     * InvalidArgumentException on validation failure or an unresolvable
     * overlap (e.g. the new effective_from is not after the existing
     * policy's own effective_from, which would create ambiguous history).
     */
    public function createPolicy(array $data, int $userId): int
    {
        $errors = $this->validatePolicy($data);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $this->db->beginTransaction();
        try {
            $current = $this->getActivePolicyRowForUpdate($data['account_type']);
            if ($current) {
                if ($data['effective_from'] <= $current['effective_from']) {
                    throw new InvalidArgumentException(
                        "A new policy's effective_from ({$data['effective_from']}) must be after the current policy's effective_from ({$current['effective_from']}) -- cannot rewrite past history."
                    );
                }
                $newEffectiveTo = date('Y-m-d', strtotime($data['effective_from'] . ' -1 day'));
                $this->db->prepare(
                    "UPDATE `savings_withdrawal_policies` SET effective_to = ?, updated_by = ? WHERE id = ?"
                )->execute([$newEffectiveTo, $userId, $current['id']]);
            }

            if ($this->hasOverlap($data['account_type'], $data['effective_from'], $data['effective_to'] ?? null)) {
                throw new InvalidArgumentException('This effective date range overlaps another active policy for this account type.');
            }

            $id = $this->create([
                'account_type'               => $data['account_type'],
                'withdrawal_enabled'         => !empty($data['withdrawal_enabled']) ? 1 : 0,
                'maximum_withdrawal_percent' => $data['maximum_withdrawal_percent'],
                'share_conversion_percent'   => $data['share_conversion_percent'],
                'frequency'                  => $data['frequency'],
                'minimum_balance'            => ($data['minimum_balance'] ?? '') !== '' ? $data['minimum_balance'] : null,
                'effective_from'             => $data['effective_from'],
                'effective_to'               => $data['effective_to'] ?? null,
                'status'                     => 'active',
                'created_by'                 => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to create withdrawal policy.');
            }

            $this->logChange($userId, 'withdrawal_policy_created', $data['account_type'], $current, $data);

            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function getActivePolicyRowForUpdate(string $accountType): array|null
    {
        $stmt = $this->db->prepare("
            SELECT * FROM `savings_withdrawal_policies`
            WHERE account_type = ? AND status = 'active' AND effective_to IS NULL
            ORDER BY effective_from DESC LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$accountType]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Direct edit is only permitted for a policy row that has not yet taken
     * effect (effective_from is in the future) -- an already-effective row
     * is immutable in place (mirrors the immutable-once-posted pattern used
     * elsewhere in this codebase), so a real, past-effective policy's
     * percentages can never be silently rewritten.
     */
    public function updateDraft(int $id, array $data, int $userId): bool
    {
        $current = $this->find($id);
        if (!$current) {
            throw new InvalidArgumentException("Policy id {$id} does not exist.");
        }
        if ($current['effective_from'] <= date('Y-m-d')) {
            throw new RuntimeException('This policy is already effective and cannot be edited in place -- create a new version instead.');
        }

        $merged = array_merge($current, $data);
        $errors = $this->validatePolicy($merged);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        if ($this->hasOverlap($current['account_type'], $merged['effective_from'], $merged['effective_to'] ?? null, $id)) {
            throw new InvalidArgumentException('This effective date range overlaps another active policy for this account type.');
        }

        $ok = $this->update($id, [
            'withdrawal_enabled'         => !empty($data['withdrawal_enabled']) ? 1 : 0,
            'maximum_withdrawal_percent' => $merged['maximum_withdrawal_percent'],
            'share_conversion_percent'   => $merged['share_conversion_percent'],
            'frequency'                  => $merged['frequency'],
            'minimum_balance'            => ($merged['minimum_balance'] ?? '') !== '' ? $merged['minimum_balance'] : null,
            'effective_from'             => $merged['effective_from'],
            'effective_to'               => $merged['effective_to'] ?: null,
            'updated_by'                 => $userId,
        ]);

        $this->logChange($userId, 'withdrawal_policy_updated', $current['account_type'], $current, $merged);
        return $ok;
    }

    /** Manual enable/disable toggle -- does not touch percentages or dates. */
    public function toggleStatus(int $id, int $userId): void
    {
        $current = $this->find($id);
        if (!$current) {
            throw new InvalidArgumentException("Policy id {$id} does not exist.");
        }
        $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
        $this->update($id, ['status' => $newStatus, 'updated_by' => $userId]);
        $this->logChange($userId, 'withdrawal_policy_status_toggled', $current['account_type'], $current, ['status' => $newStatus]);
    }

    private function logChange(int $userId, string $action, string $accountType, ?array $old, array $new): void
    {
        $desc = "Account type: {$accountType}. ";
        if ($old) {
            $desc .= "Old: max={$old['maximum_withdrawal_percent']}% share={$old['share_conversion_percent']}% "
                . "freq={$old['frequency']} enabled=" . ($old['withdrawal_enabled'] ? 'yes' : 'no')
                . " effective_from={$old['effective_from']} effective_to=" . ($old['effective_to'] ?? 'open') . '. ';
        } else {
            $desc .= 'Old: (none -- first policy for this account type). ';
        }
        $desc .= 'New: max=' . ($new['maximum_withdrawal_percent'] ?? '-') . '% share=' . ($new['share_conversion_percent'] ?? '-') . '% '
            . 'freq=' . ($new['frequency'] ?? '-') . ' enabled=' . (!empty($new['withdrawal_enabled']) ? 'yes' : (array_key_exists('withdrawal_enabled', $new) ? 'no' : '-'))
            . ' effective_from=' . ($new['effective_from'] ?? '-') . ' effective_to=' . ($new['effective_to'] ?? ($new['status'] ?? '-'));

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
            );
            $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }

    // ================================================================
    // PURE CALCULATION ENGINE — read-only, no financial writes
    // ================================================================

    /** Maximum cash a member may withdraw from $balance under $policy. */
    public function calculateMaximumWithdrawal(float $balance, array $policy): float
    {
        if (empty($policy['withdrawal_enabled'])) {
            return 0.0;
        }
        return round($balance * ((float)$policy['maximum_withdrawal_percent'] / 100), 2);
    }

    /**
     * Given an actual (already-validated, <= calculateMaximumWithdrawal())
     * withdrawal amount, compute the share-conversion and any portion that
     * remains untouched in savings.
     *
     * Model (see results/stage17_withdrawal_policy_evidence/
     * 15_business_rule_note.txt for the full evidence-based reasoning):
     * the policy's combined "addressable ceiling" is
     * balance * (maximum_withdrawal_percent + share_conversion_percent) / 100
     * -- a fixed amount regardless of how much cash is actually taken. The
     * share-conversion absorbs whatever of that ceiling is not withdrawn as
     * cash this transaction; anything beyond the ceiling (only possible when
     * max+share < 100) remains untouched in savings. For today's two
     * approved policies (max+share = 100 in both cases), remains_in_savings
     * is always 0 and share_conversion is simply (balance - withdrawalAmount).
     */
    public function calculateShareConversion(float $balance, float $withdrawalAmount, array $policy): array
    {
        $ceiling = round($balance * (((float)$policy['maximum_withdrawal_percent'] + (float)$policy['share_conversion_percent']) / 100), 2);
        $shareConversion  = max(0.0, round($ceiling - $withdrawalAmount, 2));
        $remainsInSavings = max(0.0, round($balance - $withdrawalAmount - $shareConversion, 2));
        return ['share_conversion' => $shareConversion, 'remains_in_savings' => $remainsInSavings];
    }

    /** Normalized, human-labeled frequency info for display/enforcement by the future withdrawal engine. */
    public function getFrequencyRule(array $policy): array
    {
        $labels = [
            'any_time'                => 'Any time',
            'once_per_financial_year' => 'Once per financial year',
            'monthly'                 => 'Monthly',
            'quarterly'               => 'Quarterly',
            'half_yearly'             => 'Half-yearly',
            'custom'                  => 'Custom',
        ];
        $freq = $policy['frequency'] ?? 'any_time';
        return ['frequency' => $freq, 'label' => $labels[$freq] ?? $freq];
    }
}
