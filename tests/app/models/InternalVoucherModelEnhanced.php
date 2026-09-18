<?php

/**
 * InternalVoucherModel Enhancement
 * 
 * This extends the existing InternalVoucherModel to support:
 * - Savings Account-to-Account transfers (e.g., Compulsory → Voluntary)
 * - Both debit and credit can have member subledgers when transferring between accounts
 * 
 * Key Changes:
 * 1. resolveSubledgerSide() now detects and supports dual-subledger scenarios
 * 2. createDraft() stores both source and destination savings accounts
 * 3. post() creates two savings transactions (debit source, credit destination)
 * 4. Validation ensures both accounts belong to same member
 */

// This file documents the changes needed. The actual implementation will modify
// the existing InternalVoucherModel.php file.

class InternalVoucherModelEnhancements
{
    /**
     * Enhanced resolveSubledgerSide - Returns array with source and destination
     * when both accounts require subledgers (savings account transfer scenario)
     */
    private function resolveSubledgerSide(array $primaryAccount, array $contraAccount, string $voucherType): ?array
    {
        $primaryRequires = $this->accountRequiresSubledger($primaryAccount);
        $contraRequires  = $this->accountRequiresSubledger($contraAccount);

        if (!$primaryRequires && !$contraRequires) {
            return null; // No subledger needed
        }

        // NEW: Check if this is a savings account-to-account transfer
        // (both accounts are the same GL account with subledgers)
        if ($primaryRequires && $contraRequires) {
            // Check if both are the same account (e.g., both are 2020 Members' Savings)
            if ($primaryAccount['id'] === $contraAccount['id']) {
                // This is a savings account transfer - both sides need subledgers
                $isDebitVoucher = $voucherType === 'debit';
                return [
                    'type' => 'account_transfer',
                    'source_account' => $isDebitVoucher ? $primaryAccount : $contraAccount,
                    'destination_account' => $isDebitVoucher ? $contraAccount : $primaryAccount,
                    'source_role' => $isDebitVoucher ? 'debit' : 'credit',
                    'destination_role' => $isDebitVoucher ? 'credit' : 'debit',
                ];
            }
            
            // Different accounts both requiring subledgers - not supported
            throw new InvalidArgumentException(
                'Both accounts require member subledgers but are different accounts. ' .
                'Account-to-account transfers must use the same GL account (e.g., both Members\' Savings).'
            );
        }

        // Single subledger scenario (existing logic)
        $isDebitVoucher = $voucherType === 'debit';
        if ($primaryRequires) {
            return [
                'type' => 'single',
                'account' => $primaryAccount, 
                'role' => $isDebitVoucher ? 'debit' : 'credit'
            ];
        }
        
        return [
            'type' => 'single',
            'account' => $contraAccount, 
            'role' => $isDebitVoucher ? 'credit' : 'debit'
        ];
    }

    /**
     * Enhanced createDraft - Stores destination savings account for transfers
     */
    public function createDraft(array $data, int $userId): int
    {
        // ... existing validation ...

        // NEW: If destination savings account provided, validate it
        if (!empty($data['destination_savings_account_id'])) {
            if (empty($data['destination_member_id'])) {
                throw new InvalidArgumentException('Destination member is required for account transfers.');
            }
            
            // Ensure same member
            if (!empty($data['member_id']) && $data['member_id'] != $data['destination_member_id']) {
                throw new InvalidArgumentException(
                    'Account transfers must be between the same member\'s accounts. ' .
                    'Source and destination members do not match.'
                );
            }
            
            // Validate destination account exists and is active
            $destAccount = $memberAccountModel->getAccount((int)$data['destination_savings_account_id']);
            if (!$destAccount || $destAccount['status'] !== 'active') {
                throw new InvalidArgumentException('Destination savings account is not active or does not exist.');
            }
            
            // Verify destination account belongs to destination member
            if ((int)$destAccount['member_id'] !== (int)$data['destination_member_id']) {
                throw new InvalidArgumentException('Destination savings account does not belong to the specified member.');
            }
        }

        // Insert with new fields
        $stmt = $this->db->prepare("
            INSERT INTO `internal_vouchers`
                (voucher_number, voucher_type, voucher_date, primary_account_id, contra_account_id,
                 member_id, savings_account_id,
                 destination_member_id, destination_savings_account_id,
                 expense_category_id, narration, amount, status, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
        ");
        
        // ... execute with all parameters ...
    }

    /**
     * Enhanced post() - Creates two savings transactions for account transfers
     */
    public function post(int $voucherId, int $userId): array
    {
        // ... existing validation ...

        $subledger = $this->resolveSubledgerSide($primaryAccount, $contraAccount, $voucher['voucher_type']);
        
        if ($subledger && $subledger['type'] === 'account_transfer') {
            // ACCOUNT-TO-ACCOUNT TRANSFER
            return $this->postAccountTransfer($voucher, $subledger, $userId);
        } else if ($subledger && $subledger['type'] === 'single') {
            // SINGLE SUBLEDGER (existing logic)
            return $this->postSingleSubledger($voucher, $subledger, $userId);
        } else {
            // NO SUBLEDGER (existing logic)
            return $this->postWithoutSubledger($voucher, $userId);
        }
    }

    /**
     * NEW: Handle account-to-account transfers
     */
    private function postAccountTransfer(array $voucher, array $subledger, int $userId): array
    {
        $memberAccountModel = new MemberSavingsAccountModel();
        $amount = (float)$voucher['amount'];
        
        // Validate source account
        $sourceAccount = $memberAccountModel->getAccount((int)$voucher['savings_account_id']);
        if (!$sourceAccount || $sourceAccount['status'] !== 'active') {
            throw new InvalidArgumentException('Source savings account is not active.');
        }
        
        // Validate destination account
        $destAccount = $memberAccountModel->getAccount((int)$voucher['destination_savings_account_id']);
        if (!$destAccount || $destAccount['status'] !== 'active') {
            throw new InvalidArgumentException('Destination savings account is not active.');
        }
        
        // Check sufficient balance in source
        $sourceBalance = $memberAccountModel->getAccountBalance((int)$voucher['savings_account_id']);
        if ($amount > $sourceBalance + 0.01) {
            throw new InvalidArgumentException(sprintf(
                'Insufficient balance in source account. Required: Shs %s, Available: Shs %s',
                number_format($amount, 2), number_format($sourceBalance, 2)
            ));
        }
        
        $destBalance = $memberAccountModel->getAccountBalance((int)$voucher['destination_savings_account_id']);
        $sourceBalanceAfter = $sourceBalance - $amount;
        $destBalanceAfter = $destBalance + $amount;
        
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        
        try {
            // Create source debit transaction
            $sourceSavingsStmt = $this->db->prepare("
                INSERT INTO `savings`
                    (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                     running_balance, description, payment_method, transaction_date, financial_year, notes, recorded_by, status)
                VALUES (?, ?, ?, 'transfer_out', ?, 0.00, ?, ?, 'Other', ?, ?, ?, ?, 'posted')
            ");
            $sourceSavingsStmt->execute([
                $voucher['member_id'],
                $voucher['savings_account_id'],
                $voucher['voucher_number'],
                $amount,
                $sourceBalanceAfter,
                "Account Transfer: {$voucher['voucher_number']} — {$voucher['narration']}",
                $voucher['voucher_date'],
                date('Y', strtotime($voucher['voucher_date'])),
                $voucher['narration'],
                $userId,
            ]);
            $sourceSavingsId = (int)$this->db->lastInsertId();
            
            // Create destination credit transaction
            $destSavingsStmt = $this->db->prepare("
                INSERT INTO `savings`
                    (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                     running_balance, description, payment_method, transaction_date, financial_year, notes, recorded_by, status)
                VALUES (?, ?, ?, 'transfer_in', 0.00, ?, ?, ?, 'Other', ?, ?, ?, ?, 'posted')
            ");
            $destSavingsStmt->execute([
                $voucher['destination_member_id'],
                $voucher['destination_savings_account_id'],
                $voucher['voucher_number'],
                $amount,
                $destBalanceAfter,
                "Account Transfer: {$voucher['voucher_number']} — {$voucher['narration']}",
                $voucher['voucher_date'],
                date('Y', strtotime($voucher['voucher_date'])),
                $voucher['narration'],
                $userId,
            ]);
            $destSavingsId = (int)$this->db->lastInsertId();
            
            // Post journal entry (net zero for same GL account, but required for audit)
            $isDebit = $voucher['voucher_type'] === 'debit';
            $lines = $isDebit
                ? [
                    ['account_id' => $voucher['primary_account_id'], 'debit' => $amount, 'credit' => 0, 
                     'description' => $voucher['narration'] . ' (Source)'],
                    ['account_id' => $voucher['contra_account_id'], 'debit' => 0, 'credit' => $amount, 
                     'description' => $voucher['narration'] . ' (Destination)'],
                ]
                : [
                    ['account_id' => $voucher['contra_account_id'], 'debit' => $amount, 'credit' => 0, 
                     'description' => $voucher['narration'] . ' (Source)'],
                    ['account_id' => $voucher['primary_account_id'], 'debit' => 0, 'credit' => $amount, 
                     'description' => $voucher['narration'] . ' (Destination)'],
                ];
            
            $voucherLabel = $isDebit ? 'Internal Debit Voucher' : 'Internal Credit Voucher';
            $period = $this->resolvePeriod($voucher['voucher_date']);
            
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $voucher['voucher_date'],
                'description'            => "{$voucherLabel} {$voucher['voucher_number']} — Account Transfer — {$voucher['narration']}",
                'source_module'          => 'internal_vouchers',
                'source_reference_type'  => 'voucher_account_transfer',
                'source_reference_id'    => $voucherId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => $lines,
            ]);
            
            // Update voucher with both savings transaction IDs
            $this->db->prepare("
                UPDATE `internal_vouchers` 
                SET status = 'posted', 
                    posted_at = NOW(), 
                    journal_entry_id = ?,
                    savings_id = ?,
                    destination_savings_id = ?,
                    balance_before = ?,
                    balance_after = ?
                WHERE id = ?
            ")->execute([
                $result['id'],
                $sourceSavingsId,
                $destSavingsId,
                $sourceBalance,
                $sourceBalanceAfter,
                $voucherId
            ]);
            
            $this->writeAudit($userId, 'posted_account_transfer', $voucherId, [
                'journal_entry_id' => $result['id'],
                'entry_number' => $result['entry_number'],
                'source_savings_id' => $sourceSavingsId,
                'destination_savings_id' => $destSavingsId,
                'source_balance_before' => $sourceBalance,
                'source_balance_after' => $sourceBalanceAfter,
                'destination_balance_before' => $destBalance,
                'destination_balance_after' => $destBalanceAfter,
            ]);
            
            if ($ownTransaction) {
                $this->db->commit();
            }
            
            return [
                'journal_entry_id' => $result['id'],
                'entry_number' => $result['entry_number'],
                'created' => $result['created'],
                'savings_id' => $sourceSavingsId,
                'destination_savings_id' => $destSavingsId,
                'balance_before' => $sourceBalance,
                'balance_after' => $sourceBalanceAfter,
            ];
            
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
