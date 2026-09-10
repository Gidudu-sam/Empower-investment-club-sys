<?php
/**
 * OtherIncomeModel — Other Income transactions. draft -> posted (via
 * JournalService::post() only; never a direct journal_entries/journal_lines
 * write). Immutable once posted, mirroring ExpenseModel's pattern exactly.
 *
 * This is a NEW, clean architecture (Stage 17 Part D) — it does not read
 * from, write to, or in any way depend on the legacy, broken `other_income`
 * table, which remains an untouched forensic artifact.
 */
class OtherIncomeModel extends Model
{
    protected string $table      = 'other_income_transactions';
    protected string $primaryKey = 'id';

    /** payment_method -> debit-side cash/bank GL account id (same mapping as Fees/Loans/Savings/Repayments/Expenses) */
    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    public function update(int $id, array $data): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] === 'posted') {
            throw new RuntimeException('A posted Other Income transaction is immutable — it cannot be edited.');
        }
        return parent::update($id, $data);
    }

    public function delete(int $id): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] === 'posted') {
            throw new RuntimeException('A posted Other Income transaction is immutable — it cannot be deleted.');
        }
        return parent::delete($id);
    }

    public function getAll(): array
    {
        return $this->db->query("
            SELECT oi.*, oic.category_name, u.full_name AS recorded_by_name, up.full_name AS posted_by_name
            FROM `other_income_transactions` oi
            JOIN `other_income_categories` oic ON oic.id = oi.category_id
            JOIN `users` u ON u.id = oi.recorded_by
            LEFT JOIN `users` up ON up.id = oi.posted_by
            ORDER BY oi.income_date DESC, oi.id DESC
        ")->fetchAll();
    }

    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT oi.*, oic.category_name, oic.gl_account_id,
                   a.code AS account_code, a.name AS account_name,
                   u.full_name AS recorded_by_name, up.full_name AS posted_by_name,
                   je.entry_number
            FROM `other_income_transactions` oi
            JOIN `other_income_categories` oic ON oic.id = oi.category_id
            LEFT JOIN `accounts` a ON a.id = oic.gl_account_id
            JOIN `users` u ON u.id = oi.recorded_by
            LEFT JOIN `users` up ON up.id = oi.posted_by
            LEFT JOIN `journal_entries` je ON je.id = oi.journal_entry_id
            WHERE oi.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Create a draft Other Income transaction. Validates required fields
     * and a positive amount; does not touch the ledger.
     */
    public function createDraft(array $data, int $userId): int
    {
        if (empty($data['category_id'])) {
            throw new InvalidArgumentException('An income category is required.');
        }
        if (empty($data['income_date'])) {
            throw new InvalidArgumentException('Income date is required.');
        }
        if (empty($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException('Income amount must be greater than zero.');
        }
        if (empty($data['payment_method']) || !isset(self::PAYMENT_ACCOUNTS[$data['payment_method']])) {
            throw new InvalidArgumentException('A valid payment method is required.');
        }

        $category = (new OtherIncomeCategoryModel())->find((int)$data['category_id']);
        if (!$category || !$category['is_active']) {
            throw new InvalidArgumentException('Selected income category does not exist or is inactive.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $incomeNumber = $this->nextIncomeNumber();
            // Cash Reference (CHO-000001) -- server-computed, never from
            // caller/client input. Payment method is already validated
            // above (line 90) to be one of the 6 canonical values.
            $cashReferenceNumber = $data['payment_method'] === 'Cash'
                ? $this->nextCashReference('CHO')
                : null;

            $id = $this->create([
                'income_number'         => $incomeNumber,
                'category_id'           => $data['category_id'],
                'income_date'           => $data['income_date'],
                'amount'                => $data['amount'],
                'description'           => $data['description'] ?? null,
                'reference_number'      => $data['reference_number'] ?? null,
                'cash_reference_number' => $cashReferenceNumber,
                'payment_method'        => $data['payment_method'],
                'financial_year'        => date('Y', strtotime($data['income_date'])),
                'status'                => 'draft',
                'recorded_by'           => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to create Other Income transaction.');
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Post a draft Other Income transaction as a balanced journal entry:
     * Dr <payment-method account> / Cr <category's mapped income account>.
     */
    public function post(int $incomeId, int $userId): array
    {
        $income = $this->find($incomeId);
        if (!$income) {
            throw new InvalidArgumentException("Other Income id {$incomeId} does not exist.");
        }
        if ($income['status'] === 'posted') {
            return ['journal_entry_id' => (int)$income['journal_entry_id'], 'created' => false];
        }

        $category = (new OtherIncomeCategoryModel())->find((int)$income['category_id']);
        if (!$category) {
            throw new InvalidArgumentException('Income category no longer exists.');
        }
        if (empty($category['gl_account_id'])) {
            throw new InvalidArgumentException("Category \"{$category['category_name']}\" has no GL account mapped — set one before posting this income.");
        }

        $creditAccount = (new AccountModel())->findActive((int)$category['gl_account_id']);
        if (!$creditAccount) {
            throw new InvalidArgumentException('The GL account mapped to this category does not exist or is inactive.');
        }
        if ($creditAccount['type'] !== 'income') {
            throw new InvalidArgumentException('The GL account mapped to this category is not an income account — Other Income cannot be posted to it.');
        }

        $debitAccountId = self::PAYMENT_ACCOUNTS[$income['payment_method']] ?? null;
        if ($debitAccountId === null) {
            throw new InvalidArgumentException('Invalid payment method on this transaction.');
        }
        $debitAccount = (new AccountModel())->findActive($debitAccountId);
        if (!$debitAccount) {
            throw new InvalidArgumentException('The cash/bank account for this payment method does not exist or is inactive.');
        }

        $amount = (float)$income['amount'];

        // financial_year_id/accounting_period_id are resolved the same way
        // Expenses/Fees already do -- from the open period covering this
        // date -- rather than duplicating that logic elsewhere.
        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$income['income_date'], $income['income_date']]);
        $period = $periodStmt->fetch();

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'            => $income['income_date'],
                'description'           => "Other Income {$income['income_number']} — {$category['category_name']}" . ($income['description'] ? ' — ' . $income['description'] : ''),
                'source_module'         => 'other_income_transactions',
                'source_reference_type' => 'other_income',
                'source_reference_id'   => $incomeId,
                'financial_year_id'     => $period['financial_year_id'] ?? null,
                'accounting_period_id'  => $period['period_id'] ?? null,
                'created_by'            => $userId,
                'lines'                 => [
                    ['account_id' => $debitAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $income['payment_method']],
                    ['account_id' => $creditAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $category['category_name']],
                ],
            ]);

            $this->db->prepare(
                "UPDATE `other_income_transactions` SET status='posted', posted_by=?, posted_at=NOW(), journal_entry_id=? WHERE id=?"
            )->execute([$userId, $result['id'], $incomeId]);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number'], 'created' => $result['created']];
    }

    private function nextIncomeNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'OI' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'OI'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'OI'")->execute([$next]);
        return sprintf('OI-%06d', $next);
    }

    /** Cash Reference (CHO-000001) -- new, separate identifier, populated
     *  only for payment_method = Cash. Same row-locked pattern as above. */
    private function nextCashReference(string $prefix): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = ? FOR UPDATE");
        $stmt->execute([$prefix]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix '{$prefix}'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = ?")
            ->execute([$next, $prefix]);
        return sprintf('%s-%06d', $prefix, $next);
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
