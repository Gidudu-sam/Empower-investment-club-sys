# Savings Account-to-Account Transfer Implementation Guide

**Feature:** Internal Voucher support for transferring between a member's different savings accounts (e.g., Compulsory → Voluntary)

**Date:** 2026-09-18

---

## Overview

This enhancement extends the existing Internal Voucher system to support **savings account-to-account transfers** where a member can move funds between their own savings accounts using the established maker-checker approval workflow.

### Use Case Example
Transfer UGX 100,000 from a member's:
- **Source:** Compulsory Savings Account (CS-000431)
- **Destination:** Voluntary Savings Account (VS-000123)

### Business Rules
1. Both accounts must belong to the **same member**
2. Source account must have **sufficient balance**
3. Both accounts must be **active**
4. Transfer requires **approval workflow** (like all Internal Vouchers)
5. Creates **two savings transactions** (debit source, credit destination)
6. GL entry shows both sides as 2020 (nets to zero at GL level, but subledgers change)

---

## Database Schema Changes

###File: `database/internal_voucher_savings_transfers.sql`

**Changes Required:**

1. **Add transfer transaction types to savings table:**
```sql
ALTER TABLE `savings`
    MODIFY COLUMN `transaction_type` 
    ENUM('opening_balance','deposit','withdrawal','adjustment','transfer_in','transfer_out') 
    NOT NULL;
```

2. **Add destination fields to internal_vouchers:**
```sql
ALTER TABLE `internal_vouchers`
    ADD COLUMN `destination_member_id` INT UNSIGNED NULL;

ALTER TABLE `internal_vouchers`
    ADD COLUMN `destination_savings_account_id` INT UNSIGNED NULL;

ALTER TABLE `internal_vouchers`
    ADD COLUMN `destination_savings_id` INT UNSIGNED NULL;
```

3. **Add foreign keys:**
```sql
ALTER TABLE `internal_vouchers`
    ADD CONSTRAINT `fk_voucher_dest_member`
    FOREIGN KEY (`destination_member_id`) REFERENCES `members`(`id`);

ALTER TABLE `internal_vouchers`
    ADD CONSTRAINT `fk_voucher_dest_savings_account`
    FOREIGN KEY (`destination_savings_account_id`) REFERENCES `member_savings_accounts`(`id`);

ALTER TABLE `internal_vouchers`
    ADD CONSTRAINT `fk_voucher_dest_savings`
    FOREIGN KEY (`destination_savings_id`) REFERENCES `savings`(`id`);
```

---

## Application Code Changes

### 1. InternalVoucherModel.php

#### Method: `resolveSubledgerSide()`

**Current Behavior:** Throws error when both accounts require subledgers

**New Behavior:** Detect account transfer scenario and return dual-subledger configuration

```php
private function resolveSubledgerSide(array $primaryAccount, array $contraAccount, string $voucherType): ?array
{
    $primaryRequires = $this->accountRequiresSubledger($primaryAccount);
    $contraRequires  = $this->accountRequiresSubledger($contraAccount);

    if (!$primaryRequires && !$contraRequires) {
        return null;
    }

    // NEW: Check for savings account transfer
    if ($primaryRequires && $contraRequires) {
        // Same account on both sides = account transfer
        if ($primaryAccount['id'] === $contraAccount['id']) {
            $isDebitVoucher = $voucherType === 'debit';
            return [
                'type' => 'account_transfer',
                'source_role' => $isDebitVoucher ? 'debit' : 'credit',
                'destination_role' => $isDebitVoucher ? 'credit' : 'debit',
            ];
        }
        
        throw new InvalidArgumentException(
            'Both accounts require subledgers but are different accounts. ' .
            'For account transfers, both must be the same GL account.'
        );
    }

    // Existing single-subledger logic
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
```

#### Method: `createDraft()`

**Add validation for destination accounts:**

```php
public function createDraft(array $data, int $userId): int
{
    // ... existing validations ...

    // NEW: Validate destination account if provided
    if (!empty($data['destination_savings_account_id'])) {
        if (empty($data['destination_member_id'])) {
            throw new InvalidArgumentException('Destination member required for account transfers.');
        }
        
        // Must be same member
        if (!empty($data['member_id']) && $data['member_id'] != $data['destination_member_id']) {
            throw new InvalidArgumentException('Cannot transfer between different members\' accounts.');
        }
        
        $memberAccountModel = new MemberSavingsAccountModel();
        $destAccount = $memberAccountModel->getAccount((int)$data['destination_savings_account_id']);
        
        if (!$destAccount || $destAccount['status'] !== 'active') {
            throw new InvalidArgumentException('Destination savings account is not active.');
        }
        
        if ((int)$destAccount['member_id'] !== (int)$data['destination_member_id']) {
            throw new InvalidArgumentException('Destination account does not belong to specified member.');
        }
    }

    // UPDATE INSERT to include new fields
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
```

#### Method: `post()`

**Add routing logic for account transfers:**

```php
public function post(int $voucherId, int $userId): array
{
    // ... existing validation ...

    $subledger = $this->resolveSubledgerSide($primaryAccount, $contraAccount, $voucher['voucher_type']);
    
    if ($subledger && $subledger['type'] === 'account_transfer') {
        return $this->postAccountTransfer($voucher, $subledger, $userId);
    } else if ($subledger && $subledger['type'] === 'single') {
        // Existing single subledger logic
        return $this->postSingleSubledger($voucher, $subledger, $userId);
    } else {
        // Existing no-subledger logic
        return $this->postWithoutSubledger($voucher, $userId);
    }
}
```

#### NEW Method: `postAccountTransfer()`

**Handle dual-subledger account transfers:**

```php
private function postAccountTransfer(array $voucher, array $subledger, int $userId): array
{
    $memberAccountModel = new MemberSavingsAccountModel();
    $amount = (float)$voucher['amount'];
    
    // Validate both accounts
    $sourceAccount = $memberAccountModel->getAccount((int)$voucher['savings_account_id']);
    if (!$sourceAccount || $sourceAccount['status'] !== 'active') {
        throw new InvalidArgumentException('Source account not active.');
    }
    
    $destAccount = $memberAccountModel->getAccount((int)$voucher['destination_savings_account_id']);
    if (!$destAccount || $destAccount['status'] !== 'active') {
        throw new InvalidArgumentException('Destination account not active.');
    }
    
    // Check sufficient balance
    $sourceBalance = $memberAccountModel->getAccountBalance((int)$voucher['savings_account_id']);
    if ($amount > $sourceBalance + 0.01) {
        throw new InvalidArgumentException(sprintf(
            'Insufficient balance. Required: Shs %s, Available: Shs %s',
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
        // Create source debit (transfer_out)
        $sourceSavingsStmt = $this->db->prepare("
            INSERT INTO `savings`
                (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                 running_balance, description, payment_method, transaction_date, financial_year, 
                 notes, recorded_by, status)
            VALUES (?, ?, ?, 'transfer_out', ?, 0.00, ?, ?, 'Other', ?, ?, ?, ?, 'posted')
        ");
        $sourceSavingsStmt->execute([
            $voucher['member_id'],
            $voucher['savings_account_id'],
            $voucher['voucher_number'],
            $amount,
            $sourceBalanceAfter,
            "Account Transfer Out: {$voucher['voucher_number']} — {$voucher['narration']}",
            $voucher['voucher_date'],
            date('Y', strtotime($voucher['voucher_date'])),
            $voucher['narration'],
            $userId,
        ]);
        $sourceSavingsId = (int)$this->db->lastInsertId();
        
        // Create destination credit (transfer_in)
        $destSavingsStmt = $this->db->prepare("
            INSERT INTO `savings`
                (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                 running_balance, description, payment_method, transaction_date, financial_year, 
                 notes, recorded_by, status)
            VALUES (?, ?, ?, 'transfer_in', 0.00, ?, ?, ?, 'Other', ?, ?, ?, ?, 'posted')
        ");
        $destSavingsStmt->execute([
            $voucher['destination_member_id'],
            $voucher['destination_savings_account_id'],
            $voucher['voucher_number'],
            $amount,
            $destBalanceAfter,
            "Account Transfer In: {$voucher['voucher_number']} — {$voucher['narration']}",
            $voucher['voucher_date'],
            date('Y', strtotime($voucher['voucher_date'])),
            $voucher['narration'],
            $userId,
        ]);
        $destSavingsId = (int)$this->db->lastInsertId();
        
        // Post journal entry (both lines are GL 2020, nets to zero)
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
        
        // Update voucher with both savings IDs
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
```

---

### 2. InternalVoucherController.php

**No major changes required** - the existing `memberAccounts()` endpoint already returns multiple accounts per member.

---

### 3. View: `app/views/internal-vouchers/form.php`

**Add destination account selection UI:**

This requires JavaScript modifications to:
1. Detect when both primary and contra accounts are the same and require subledgers
2. Show a second member/account selector for the destination
3. Validate that both accounts belong to the same member
4. Display both account balances before transfer

**Key UI Changes:**

```javascript
// After existing member subledger block, add:
<div class="mb-3" id="destinationSubledgerBlock" style="display:none;">
    <label class="form-label fw-semibold">
        Destination Account <span class="text-muted fw-normal">for Account Transfer</span>
    </label>
    
    <input type="hidden" name="destination_member_id" id="destinationMemberId">
    
    <select name="destination_savings_account_id" id="destinationAccountSelect" class="form-select">
        <option value="">Select destination account...</option>
    </select>
    
    <div class="alert alert-secondary py-1 px-2 small mb-0 mt-2" id="destinationAccountBalanceText" style="display:none;"></div>
</div>

<div class="alert alert-warning" id="accountTransferWarning" style="display:none;">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Account Transfer:</strong> Funds will be moved from the source account to the destination account.
</div>
```

**JavaScript Logic:**

```javascript
function checkSubledgerRequirement() {
    const primary = document.getElementById('primaryAccount');
    const contra = document.getElementById('contraAccount');
    
    const primaryOption = primary.options[primary.selectedIndex];
    const contraOption = contra.options[contra.selectedIndex];
    
    const primaryRequires = primaryOption?.dataset.requiresSubledger === '1';
    const contraRequires = contraOption?.dataset.requiresSubledger === '1';
    
    // Check if both are same account and both require subledgers
    const isAccountTransfer = primaryRequires && contraRequires && 
                             primary.value === contra.value && 
                             primary.value !== '';
    
    if (isAccountTransfer) {
        // Show destination account selector
        document.getElementById('destinationSubledgerBlock').style.display = 'block';
        document.getElementById('accountTransferWarning').style.display = 'block';
        
        // Populate destination with same member's other accounts
        populateDestinationAccounts();
    } else {
        document.getElementById('destinationSubledgerBlock').style.display = 'none';
        document.getElementById('accountTransferWarning').style.display = 'none';
    }
}

function populateDestinationAccounts() {
    const memberId = document.getElementById('voucherMemberId').value;
    const sourceAccountId = document.getElementById('voucherAccountSelect').value;
    
    if (!memberId) return;
    
    fetch(`?page=internal-voucher-member-accounts&member_id=${memberId}`)
        .then(r => r.json())
        .then(data => {
            const select = document.getElementById('destinationAccountSelect');
            select.innerHTML = '<option value="">Select destination account...</option>';
            
            data.accounts.forEach(acc => {
                // Exclude the source account
                if (acc.id != sourceAccountId) {
                    const option = document.createElement('option');
                    option.value = acc.id;
                    option.textContent = `${acc.account_type} — ${acc.account_number} (Balance: Shs ${acc.balance.toLocaleString()})`;
                    option.dataset.balance = acc.balance;
                    select.appendChild(option);
                }
            });
            
            document.getElementById('destinationMemberId').value = memberId;
        });
}
```

---

## Testing Plan

### 1. Schema Migration Test
```bash
# Apply schema changes
php -r "require 'app/config/config.php'; require 'app/config/database.php'; 
require 'core/Autoloader.php'; 
\$db = Database::getInstance(); 
\$sql = file_get_contents('database/internal_voucher_savings_transfers.sql'); 
\$db->exec(\$sql);"

# Verify
php check_iv_schema.php
```

### 2. Unit Tests
- [ ] Member with Compulsory and Voluntary accounts
- [ ] Transfer UGX 50,000 from Compulsory to Voluntary
- [ ] Verify source balance decreases
- [ ] Verify destination balance increases
- [ ] Verify GL 2020 net change is zero
- [ ] Verify two savings transactions created
- [ ] Verify journal entry posted
- [ ] Test insufficient balance rejection
- [ ] Test different member rejection
- [ ] Test inactive account rejection

### 3. Integration Tests
- [ ] Create draft voucher
- [ ] Submit for approval
- [ ] Approve
- [ ] Post to ledger
- [ ] Verify balances
- [ ] Check audit trail
- [ ] Test rejection workflow

---

## Deployment Steps

1. **Backup database**
2. **Apply schema changes:** `database/internal_voucher_savings_transfers.sql`
3. **Update Model:** Apply changes to `InternalVoucherModel.php`
4. **Update View:** Apply changes to `form.php` JavaScript
5. **Test on clone database first**
6. **Deploy to production**
7. **Verify with test transfer**

---

## Known Limitations

1. **Same member only** - Cannot transfer between different members
2. **Active accounts only** - Both accounts must be active
3. **Same GL account** - Both sides must be same GL account (e.g., both 2020)
4. **Requires approval** - Uses standard maker-checker workflow
5. **No batch transfers** - One transfer per voucher

---

## Future Enhancements

- Batch account transfers
- Scheduled/recurring transfers
- Transfer templates
- Transfer limits/policies
- Email notifications on transfer

---

## Questions?

Contact: System Administrator or Technical Lead
