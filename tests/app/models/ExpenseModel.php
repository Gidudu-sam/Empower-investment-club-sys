<?php
/**
 * ExpenseModel — expense records. draft -> posted (via JournalService::post()
 * only; never a direct journal_entries/journal_lines write). Immutable once
 * posted, matching every other posted-entity pattern in this engagement.
 */
class ExpenseModel extends Model
{
    protected string $table      = 'expenses';
    protected string $primaryKey = 'id';

    /** payment_method -> credit-side GL account id (Cash/Bank/Mobile Money accounts already in the recovered chart) */
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
            throw new RuntimeException('A posted expense is immutable — it cannot be edited.');
        }
        return parent::update($id, $data);
    }

    public function delete(int $id): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] === 'posted') {
            throw new RuntimeException('A posted expense is immutable — it cannot be deleted.');
        }
        return parent::delete($id);
    }

    public function getAll(): array
    {
        return $this->db->query("
            SELECT e.*, ec.category_name, u.full_name AS recorded_by_name, ua.full_name AS approved_by_name
            FROM `expenses` e
            JOIN `expense_categories` ec ON ec.id = e.category_id
            JOIN `users` u ON u.id = e.recorded_by
            LEFT JOIN `users` ua ON ua.id = e.approved_by
            ORDER BY e.expense_date DESC, e.id DESC
        ")->fetchAll();
    }

    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT e.*, ec.category_name, ec.category_group, ec.gl_account_id,
                   a.code AS account_code, a.name AS account_name,
                   u.full_name AS recorded_by_name, ua.full_name AS approved_by_name,
                   je.entry_number, e.approved_date AS approved_date
            FROM `expenses` e
            JOIN `expense_categories` ec ON ec.id = e.category_id
            LEFT JOIN `accounts` a ON a.id = ec.gl_account_id
            JOIN `users` u ON u.id = e.recorded_by
            LEFT JOIN `users` ua ON ua.id = e.approved_by
            LEFT JOIN `journal_entries` je ON je.id = e.journal_entry_id
            WHERE e.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Create a draft expense. Validates required fields and a positive
     * amount; does not touch the ledger.
     */
    public function createDraft(array $data, int $userId): int
    {
        if (empty($data['category_id'])) {
            throw new InvalidArgumentException('An expense category is required.');
        }
        if (empty($data['expense_date'])) {
            throw new InvalidArgumentException('Expense date is required.');
        }
        if (empty($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException('Expense amount must be greater than zero.');
        }
        if (empty($data['payment_method']) || !isset(self::PAYMENT_ACCOUNTS[$data['payment_method']])) {
            throw new InvalidArgumentException('A valid payment method is required.');
        }

        $category = (new ExpenseCategoryModel())->find((int)$data['category_id']);
        if (!$category || !$category['is_active']) {
            throw new InvalidArgumentException('Selected expense category does not exist or is inactive.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $expenseNumber = $this->nextExpenseNumber();
            // Cash Reference (CHE-000001) -- server-computed, never from
            // caller/client input. Payment method is already validated
            // above (line 86) to be one of the 6 canonical values.
            $cashReferenceNumber = $data['payment_method'] === 'Cash'
                ? $this->nextCashReference('CHE')
                : null;

            $id = $this->create([
                'expense_number'        => $expenseNumber,
                'category_id'           => $data['category_id'],
                'expense_date'          => $data['expense_date'],
                'amount'                => $data['amount'],
                'description'           => $data['description'] ?? null,
                'reference_number'      => $data['reference_number'] ?? null,
                'cash_reference_number' => $cashReferenceNumber,
                'payment_method'        => $data['payment_method'],
                'payee_name'            => $data['payee_name'] ?? null,
                'financial_year'        => date('Y', strtotime($data['expense_date'])),
                'status'                => 'draft',
                'recorded_by'           => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to create expense.');
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
     * Post a draft expense as a balanced journal entry:
     * Dr <category's mapped GL account> / Cr <payment-method account>.
     */
    public function post(int $expenseId, int $userId): array
    {
        $expense = $this->find($expenseId);
        if (!$expense) {
            throw new InvalidArgumentException("Expense id {$expenseId} does not exist.");
        }
        if ($expense['status'] === 'posted') {
            return ['journal_entry_id' => (int)$expense['journal_entry_id'], 'created' => false];
        }

        $category = (new ExpenseCategoryModel())->find((int)$expense['category_id']);
        if (!$category) {
            throw new InvalidArgumentException('Expense category no longer exists.');
        }
        if (empty($category['gl_account_id'])) {
            throw new InvalidArgumentException("Category \"{$category['category_name']}\" has no GL account mapped — set one before posting this expense.");
        }

        $debitAccount = (new AccountModel())->findActive((int)$category['gl_account_id']);
        if (!$debitAccount) {
            throw new InvalidArgumentException('The GL account mapped to this category does not exist or is inactive.');
        }

        $creditAccountId = self::PAYMENT_ACCOUNTS[$expense['payment_method']] ?? 7;
        $creditAccount = (new AccountModel())->findActive($creditAccountId);
        if (!$creditAccount) {
            throw new InvalidArgumentException('The cash/bank account for this payment method does not exist or is inactive.');
        }

        $amount = (float)$expense['amount'];

        // JournalService::post() only auto-resolves accounting_period_id when
        // none is passed -- financial_year_id is taken as-is from the request
        // and defaults to NULL if omitted. Resolve both explicitly here (from
        // the same open-period-covering-this-date query JournalService itself
        // uses) so the posted entry is correctly attributed to a financial
        // year and shows up in year-filtered reports.
        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$expense['expense_date'], $expense['expense_date']]);
        $period = $periodStmt->fetch();

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $expense['expense_date'],
                'description'            => "Expense {$expense['expense_number']} — {$category['category_name']}" . ($expense['description'] ? ' — ' . $expense['description'] : ''),
                'source_module'          => 'expenses',
                'source_reference_type'  => 'expense',
                'source_reference_id'    => $expenseId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => [
                    ['account_id' => $debitAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $category['category_name']],
                    ['account_id' => $creditAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $expense['payment_method']],
                ],
            ]);

            $this->db->prepare(
                "UPDATE `expenses` SET status='posted', approved_by=?, approved_date=NOW(), journal_entry_id=? WHERE id=?"
            )->execute([$userId, $result['id'], $expenseId]);

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

    private function nextExpenseNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'EXP' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'EXP'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'EXP'")->execute([$next]);
        return sprintf('EXP-%06d', $next);
    }

    /** Cash Reference (CHE-000001) -- new, separate identifier, populated
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
}
