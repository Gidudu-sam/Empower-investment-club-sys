<?php
/**
 * ReferralBonusModel — Referral Commissions & Bonuses.
 *
 * Two payout types, both paid to an existing member (staff are also
 * members, per the brief -- no separate staff/agent entity exists):
 *   - 'referral'     -- a member is paid for bringing in a new member
 *   - 'staff_target' -- a member/staff is paid for hitting a target
 *                       (free-text note only; no target-tracking system
 *                       exists to validate against)
 *
 * Cashier-facing and immediate: recordPayout() validates and posts through
 * JournalService in the same call, inside one transaction -- there is no
 * separate "pending" state to approve later, unlike Expenses/Internal
 * Vouchers. This mirrors FeeModel::markPaid()'s shape exactly, including
 * reusing its PAYMENT_ACCOUNTS mapping and open-period resolution.
 *
 * Dr 5350 Referral Commissions & Bonuses (expense)
 * Cr <cash/bank account for the payment method>
 */
class ReferralBonusModel extends Model
{
    protected string $table      = 'referral_bonuses';
    protected string $primaryKey = 'id';

    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    private const EXPENSE_ACCOUNT_CODE = '5350'; // Referral Commissions & Bonuses

    public function generateReferenceNumber(): string
    {
        $stmt = $this->db->query("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'RB'");
        $row = $stmt->fetch();
        $next = (int)($row['last_number'] ?? 0) + 1;
        return sprintf('RB-%06d', $next);
    }

    private function nextReferenceNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'RB' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'RB'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'RB'")->execute([$next]);
        return sprintf('RB-%06d', $next);
    }

    /**
     * Validate and post a bonus payout in one step. Throws
     * InvalidArgumentException on any validation failure (nothing is
     * written); throws whatever JournalService throws if posting fails
     * (transaction rolled back, nothing partially committed).
     */
    public function recordPayout(array $data, int $userId): array
    {
        $bonusType   = $data['bonus_type'] ?? '';
        $beneficiary = (int)($data['beneficiary_member_id'] ?? 0);
        $referred    = !empty($data['referred_member_id']) ? (int)$data['referred_member_id'] : null;
        $targetNote  = trim($data['target_note'] ?? '');
        $amount      = (float)($data['amount'] ?? 0);
        $paidDate    = $data['payment_date'] ?? date('Y-m-d');
        $method      = $data['payment_method'] ?? 'Cash';
        $narration   = trim($data['narration'] ?? '');

        if (!in_array($bonusType, ['referral', 'staff_target'], true)) {
            throw new InvalidArgumentException('Select a valid bonus type.');
        }
        if ($beneficiary < 1) {
            throw new InvalidArgumentException('Select the member receiving the bonus.');
        }
        $beneficiaryRow = (new MemberModel())->find($beneficiary);
        if (!$beneficiaryRow) {
            throw new InvalidArgumentException('Selected beneficiary member does not exist.');
        }

        if ($bonusType === 'referral') {
            if (!$referred) {
                throw new InvalidArgumentException('Select the member who was referred.');
            }
            if ($referred === $beneficiary) {
                throw new InvalidArgumentException('A member cannot be recorded as referring themselves.');
            }
            if (!(new MemberModel())->find($referred)) {
                throw new InvalidArgumentException('Selected referred member does not exist.');
            }
            $targetNote = null;
        } else {
            $referred = null;
            if ($targetNote === '') {
                throw new InvalidArgumentException('Describe the target that was hit.');
            }
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        if (!array_key_exists($method, self::PAYMENT_ACCOUNTS)) {
            throw new InvalidArgumentException("Invalid payment method \"{$method}\".");
        }
        if (empty($paidDate) || !DateTime::createFromFormat('Y-m-d', $paidDate)) {
            throw new InvalidArgumentException('Enter a valid payment date.');
        }

        $accountModel = new AccountModel();
        $creditAccount = $accountModel->findActive(self::PAYMENT_ACCOUNTS[$method]);
        if (!$creditAccount) {
            throw new InvalidArgumentException("The cash/bank account for payment method \"{$method}\" does not exist or is inactive.");
        }
        $expenseAccountRow = $accountModel->findByCode(self::EXPENSE_ACCOUNT_CODE);
        if (!$expenseAccountRow || !$expenseAccountRow['is_active']) {
            throw new InvalidArgumentException('The Referral Commissions & Bonuses expense account does not exist or is inactive.');
        }

        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$paidDate, $paidDate]);
        $period = $periodStmt->fetch();

        $beneficiaryLabel = trim($beneficiaryRow['first_name'] . ' ' . $beneficiaryRow['last_name']);
        $description = $bonusType === 'referral'
            ? "Referral bonus — {$beneficiaryLabel}"
            : "Staff target bonus — {$beneficiaryLabel}";

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $refNumber = $this->nextReferenceNumber();

            // Row created first (no journal_entry_id yet) so its own id can
            // become the journal's source_reference_id -- matching
            // SavingsModel::recordDepositWithPosting()'s create-then-post
            // ordering exactly.
            $id = $this->create([
                'reference_number'      => $refNumber,
                'bonus_type'            => $bonusType,
                'beneficiary_member_id' => $beneficiary,
                'referred_member_id'    => $referred,
                'target_note'           => $targetNote,
                'amount'                => $amount,
                'payment_date'          => $paidDate,
                'payment_method'        => $method,
                'narration'             => $narration ?: null,
                'recorded_by'           => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to record the bonus payout.');
            }

            $service = new JournalService();
            $result = $service->post([
                'entry_date'            => $paidDate,
                'description'           => "{$description} ({$refNumber})",
                'source_module'         => 'referral_bonuses',
                'source_reference_type' => 'referral_bonus',
                'source_reference_id'   => $id,
                'financial_year_id'     => $period['financial_year_id'] ?? null,
                'accounting_period_id'  => $period['period_id'] ?? null,
                'created_by'            => $userId,
                'lines'                 => [
                    ['account_id' => $expenseAccountRow['id'], 'debit' => $amount, 'credit' => 0, 'description' => $description],
                    ['account_id' => $creditAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $method],
                ],
            ]);

            $this->db->prepare("UPDATE `referral_bonuses` SET `journal_entry_id` = ? WHERE `id` = ?")
                ->execute([$result['id'], $id]);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['id' => $id, 'reference_number' => $refNumber, 'journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number']];
    }

    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT rb.*,
                   b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name, b.member_number AS beneficiary_member_number,
                   r.first_name AS referred_first_name, r.last_name AS referred_last_name, r.member_number AS referred_member_number,
                   u.full_name AS recorded_by_name,
                   je.entry_number AS journal_entry_number
            FROM `referral_bonuses` rb
            JOIN `members` b ON b.id = rb.beneficiary_member_id
            LEFT JOIN `members` r ON r.id = rb.referred_member_id
            LEFT JOIN `users` u ON u.id = rb.recorded_by
            LEFT JOIN `journal_entries` je ON je.id = rb.journal_entry_id
            WHERE rb.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function search(string $term = '', string $type = '', int $page = 1, int $perPage = 20): array
    {
        $where = ['1=1'];
        $params = [];
        if ($term !== '') {
            $where[] = "(rb.reference_number LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ?)";
            $needle = "%{$term}%";
            $params[] = $needle; $params[] = $needle; $params[] = $needle;
        }
        if (in_array($type, ['referral', 'staff_target'], true)) {
            $where[] = 'rb.bonus_type = ?';
            $params[] = $type;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM `referral_bonuses` rb JOIN `members` b ON b.id = rb.beneficiary_member_id WHERE {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $listStmt = $this->db->prepare("
            SELECT rb.*, b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name, b.member_number AS beneficiary_member_number,
                   r.first_name AS referred_first_name, r.last_name AS referred_last_name
            FROM `referral_bonuses` rb
            JOIN `members` b ON b.id = rb.beneficiary_member_id
            LEFT JOIN `members` r ON r.id = rb.referred_member_id
            WHERE {$whereSql}
            ORDER BY rb.payment_date DESC, rb.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $listStmt->execute($params);

        return [
            'rows'  => $listStmt->fetchAll(),
            'total' => $total,
            'pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }

    /** Total paid to a member across all bonus types -- the "how much has John earned" question. */
    public function memberTotalReceived(int $memberId): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM `referral_bonuses` WHERE beneficiary_member_id = ?");
        $stmt->execute([$memberId]);
        return (float)$stmt->fetchColumn();
    }

    /** Whether a given referral has already been paid -- prevents double-paying the same referral. */
    public function referralAlreadyPaid(int $referredMemberId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `referral_bonuses` WHERE referred_member_id = ? AND bonus_type = 'referral'");
        $stmt->execute([$referredMemberId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Per-beneficiary summary for the report page. */
    public function summaryByBeneficiary(): array
    {
        return $this->db->query("
            SELECT b.id AS member_id, b.member_number, b.first_name, b.last_name,
                   COUNT(*) AS bonus_count, SUM(rb.amount) AS total_amount
            FROM `referral_bonuses` rb
            JOIN `members` b ON b.id = rb.beneficiary_member_id
            GROUP BY b.id, b.member_number, b.first_name, b.last_name
            ORDER BY total_amount DESC
        ")->fetchAll();
    }

    public function totals(): array
    {
        $row = $this->db->query("
            SELECT
                COALESCE(SUM(amount),0) AS total_amount,
                COALESCE(SUM(CASE WHEN bonus_type='referral' THEN amount ELSE 0 END),0) AS referral_amount,
                COALESCE(SUM(CASE WHEN bonus_type='staff_target' THEN amount ELSE 0 END),0) AS staff_target_amount,
                COUNT(*) AS bonus_count
            FROM `referral_bonuses`
        ")->fetch();
        return $row ?: ['total_amount' => 0, 'referral_amount' => 0, 'staff_target_amount' => 0, 'bonus_count' => 0];
    }

    public function log(int $userId, string $action, string $desc = ''): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
            );
            $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }
}
