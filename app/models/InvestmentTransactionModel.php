<?php
/**
 * InvestmentTransactionModel — post-placement events against an investment:
 * income, withdrawal, disposal/maturity. Simple draft->posted workflow (no
 * maker-checker for v1, per the approved design). Posting delegates to
 * JournalService::post() only — this model never writes to journal_entries
 * or journal_lines directly.
 *
 * Disposal accounting (approved design):
 *   - proceeds == carrying amount: Dr Funding / Cr Investment (carrying)
 *   - proceeds >  carrying amount: Dr Funding (proceeds) / Cr Investment (carrying) / Cr income_gl_account_id (gain)
 *   - proceeds <  carrying amount: Dr Funding (proceeds) / Dr loss_gl_account_id (loss) / Cr Investment (carrying)
 *   The investment asset account is never credited more than its carrying amount.
 */
class InvestmentTransactionModel extends Model
{
    protected string $table      = 'investment_transactions';
    protected string $primaryKey = 'id';

    private AccountModel $accountModel;
    private InvestmentModel $investmentModel;
    private InvestmentTypeModel $typeModel;

    public function __construct()
    {
        parent::__construct();
        $this->accountModel    = new AccountModel();
        $this->investmentModel = new InvestmentModel();
        $this->typeModel       = new InvestmentTypeModel();
    }

    public function update(int $id, array $data): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] === 'posted') {
            throw new RuntimeException('A posted investment transaction is immutable — it cannot be edited.');
        }
        return parent::update($id, $data);
    }

    public function delete(int $id): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] === 'posted') {
            throw new RuntimeException('A posted investment transaction is immutable — it cannot be deleted.');
        }
        return parent::delete($id);
    }

    /**
     * Create a draft transaction. Basic input validation only — the
     * authoritative checks (carrying amount, single disposal, dates,
     * investment status) run again at post() time against live data.
     */
    public function createDraft(array $data, int $userId): int
    {
        if (empty($data['investment_id'])) {
            throw new InvalidArgumentException('An investment is required.');
        }
        if (empty($data['transaction_type']) || !in_array($data['transaction_type'], ['income', 'withdrawal', 'disposal'], true)) {
            throw new InvalidArgumentException('A valid transaction type (income, withdrawal, disposal) is required.');
        }
        if (empty($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException('Transaction amount must be greater than zero.');
        }
        if (empty($data['transaction_date'])) {
            throw new InvalidArgumentException('Transaction date is required.');
        }
        if (empty($data['funding_account_id'])) {
            throw new InvalidArgumentException('A funding account is required.');
        }

        $investment = $this->investmentModel->find((int)$data['investment_id']);
        if (!$investment) {
            throw new InvalidArgumentException('Selected investment does not exist.');
        }
        if ($data['transaction_date'] < $investment['start_date']) {
            throw new InvalidArgumentException('Transaction date cannot be before the investment start date.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $transactionNumber = $this->nextTransactionNumber();

            $id = $this->create([
                'transaction_number' => $transactionNumber,
                'investment_id'      => $data['investment_id'],
                'transaction_type'   => $data['transaction_type'],
                'amount'             => $data['amount'],
                'transaction_date'   => $data['transaction_date'],
                'description'        => $data['description'] ?? null,
                'funding_account_id' => $data['funding_account_id'],
                'status'             => 'draft',
                'recorded_by'        => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to create investment transaction.');
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
     * Post a draft transaction as a balanced journal entry. Idempotent on
     * this row's own journal_entry_id.
     */
    public function post(int $transactionId, int $userId): array
    {
        $txn = $this->find($transactionId);
        if (!$txn) {
            throw new InvalidArgumentException("Investment transaction id {$transactionId} does not exist.");
        }
        if ($txn['status'] === 'posted') {
            $je = $this->db->prepare("SELECT entry_number FROM journal_entries WHERE id = ?");
            $je->execute([$txn['journal_entry_id']]);
            return ['journal_entry_id' => (int)$txn['journal_entry_id'], 'entry_number' => $je->fetchColumn(), 'created' => false];
        }

        $investment = $this->investmentModel->find((int)$txn['investment_id']);
        if (!$investment) {
            throw new InvalidArgumentException('The investment for this transaction no longer exists.');
        }
        // Only a posted (or already-progressing: matured/withdrawn/disposed for
        // additional income/further partial withdrawals) investment can receive
        // transactions -- never draft/pending_approval/approved/rejected.
        if (!in_array($investment['status'], ['posted', 'matured', 'withdrawn', 'disposed'], true)) {
            throw new InvalidArgumentException("The investment must be posted before transactions can be recorded against it (current status: {$investment['status']}).");
        }
        if ($investment['status'] === 'disposed') {
            throw new InvalidArgumentException('This investment has already been fully disposed of — no further transactions are allowed.');
        }
        if ($txn['transaction_date'] < $investment['start_date']) {
            throw new InvalidArgumentException('Transaction date cannot be before the investment start date.');
        }

        $fundingAccount = $this->accountModel->findActive((int)$txn['funding_account_id']);
        if (!$fundingAccount) {
            throw new InvalidArgumentException('The funding account does not exist or is not active.');
        }

        $type = $this->typeModel->find((int)$investment['investment_type_id']);
        $amount = (float)$txn['amount'];
        $carrying = $this->investmentModel->carryingAmount((int)$investment['id'], (float)$investment['principal_amount']);

        $lines = [];
        $newInvestmentStatus = null;

        if ($txn['transaction_type'] === 'income') {
            $incomeAccount = $this->accountModel->findActive((int)$type['income_gl_account_id']);
            if (!$incomeAccount) {
                throw new InvalidArgumentException('The income account mapped to this investment type does not exist or is not active.');
            }
            $lines = [
                ['account_id' => $fundingAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Investment income'],
                ['account_id' => $incomeAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => "Income — {$investment['investment_number']}"],
            ];
        } elseif ($txn['transaction_type'] === 'withdrawal') {
            if ($amount > $carrying + 0.005) {
                throw new InvalidArgumentException(
                    "Withdrawal amount (" . number_format($amount, 2) . ") cannot exceed the investment's remaining carrying amount (" . number_format($carrying, 2) . ")."
                );
            }
            $lines = [
                ['account_id' => $fundingAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Investment withdrawal'],
                ['account_id' => $investment['investment_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => "Withdrawal — {$investment['investment_number']}"],
            ];
            $newInvestmentStatus = ($amount >= $carrying - 0.005) ? 'withdrawn' : null;
        } else { // disposal
            $existingDisposal = $this->db->prepare("
                SELECT id FROM investment_transactions
                WHERE investment_id = ? AND transaction_type = 'disposal' AND status = 'posted' AND id != ?
                LIMIT 1
            ");
            $existingDisposal->execute([$investment['id'], $transactionId]);
            if ($existingDisposal->fetch()) {
                throw new InvalidArgumentException('This investment has already been disposed of — only one disposal is allowed per investment.');
            }

            if (abs($amount - $carrying) < 0.005) {
                // Disposal at carrying amount.
                $lines = [
                    ['account_id' => $fundingAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Investment disposal'],
                    ['account_id' => $investment['investment_account_id'], 'debit' => 0, 'credit' => $carrying, 'description' => "Disposal — {$investment['investment_number']}"],
                ];
            } elseif ($amount > $carrying) {
                // Gain on disposal.
                $gain = round($amount - $carrying, 2);
                $incomeAccount = $this->accountModel->findActive((int)$type['income_gl_account_id']);
                if (!$incomeAccount) {
                    throw new InvalidArgumentException('The income account mapped to this investment type does not exist or is not active — required to record a disposal gain.');
                }
                $lines = [
                    ['account_id' => $fundingAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Investment disposal proceeds'],
                    ['account_id' => $investment['investment_account_id'], 'debit' => 0, 'credit' => $carrying, 'description' => "Disposal — {$investment['investment_number']}"],
                    ['account_id' => $incomeAccount['id'], 'debit' => 0, 'credit' => $gain, 'description' => "Gain on disposal of {$type['type_name']} — {$investment['investment_number']}"],
                ];
            } else {
                // Loss on disposal.
                $loss = round($carrying - $amount, 2);
                if (empty($type['loss_gl_account_id'])) {
                    throw new InvalidArgumentException("This investment type (\"{$type['type_name']}\") has no Loss GL account configured — cannot record a below-carrying-amount disposal. Set one in Investment Types first.");
                }
                $lossAccount = $this->accountModel->findActive((int)$type['loss_gl_account_id']);
                if (!$lossAccount) {
                    throw new InvalidArgumentException('The loss account mapped to this investment type does not exist or is not active.');
                }
                $lines = [
                    ['account_id' => $fundingAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Investment disposal proceeds'],
                    ['account_id' => $lossAccount['id'], 'debit' => $loss, 'credit' => 0, 'description' => "Loss on disposal of {$type['type_name']} — {$investment['investment_number']}"],
                    ['account_id' => $investment['investment_account_id'], 'debit' => 0, 'credit' => $carrying, 'description' => "Disposal — {$investment['investment_number']}"],
                ];
            }
            $newInvestmentStatus = 'disposed';
        }

        $period = $this->investmentModel->resolvePeriod($txn['transaction_date']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $txn['transaction_date'],
                'description'            => ucfirst($txn['transaction_type']) . " — {$investment['investment_number']}" . ($txn['description'] ? ' — ' . $txn['description'] : ''),
                'source_module'          => 'investments',
                'source_reference_type'  => 'investment_' . $txn['transaction_type'],
                'source_reference_id'    => $transactionId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => $lines,
            ]);

            $this->db->prepare(
                "UPDATE `investment_transactions` SET status = 'posted', journal_entry_id = ? WHERE id = ?"
            )->execute([$result['id'], $transactionId]);

            if ($newInvestmentStatus !== null) {
                $this->db->prepare("UPDATE `investments` SET status = ? WHERE id = ?")
                    ->execute([$newInvestmentStatus, $investment['id']]);
            } elseif (!empty($investment['maturity_date']) && $txn['transaction_date'] >= $investment['maturity_date'] && $investment['status'] === 'posted') {
                $this->db->prepare("UPDATE `investments` SET status = 'matured' WHERE id = ?")->execute([$investment['id']]);
            }

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

    private function nextTransactionNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'INVTX' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'INVTX'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'INVTX'")->execute([$next]);
        return sprintf('INVTX-%06d', $next);
    }
}
