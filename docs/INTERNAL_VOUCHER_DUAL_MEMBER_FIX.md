# Internal Voucher: Dual Member Subledger Support

**Issue:** When both Debit and Credit accounts are "Members' Savings (2020)", the form only shows ONE member search field. It should show TWO separate member search fields.

**Use Case:** Transfer from Person A's account to Person B's account (or same person's different accounts)

---

## Current Behavior (WRONG)

When selecting:
- **Debit Account:** Members' Savings (2020)
- **Credit Account:** Members' Savings (2020)

Form shows only ONE "Member / Account" search box, which applies to whichever account was selected first.

---

## Required Behavior (CORRECT)

When selecting:
- **Debit Account:** Members' Savings (2020)  
  → Show "**Debit Member / Account**" search box
  
- **Credit Account:** Members' Savings (2020)  
  → Show "**Credit Member / Account**" search box (SEPARATE from debit)

### Example:
```
Debit Account: Members' Savings (2020)
  → Search: "MASABA MARTIN"
  → Select: Compulsory — CS-000431 (Shs 104,000.00)

Credit Account: Members' Savings (2020)
  → Search: "JANE DOE" (or same person)
  → Select: Voluntary — VS-000123 (Shs 50,000.00)
```

---

## Database Schema Changes

**File:** `database/internal_voucher_fix_dual_subledger.sql`

### Current Schema (PRIMARY side only):
```
member_id               - Member for primary account
savings_account_id      - Savings account for primary
savings_id              - Resulting transaction for primary
balance_before          - Primary balance before
balance_after           - Primary balance after
```

### NEW: Add CONTRA side:
```sql
ALTER TABLE `internal_vouchers`
    ADD COLUMN `contra_member_id` INT UNSIGNED NULL;

ALTER TABLE `internal_vouchers`
    ADD COLUMN `contra_savings_account_id` INT UNSIGNED NULL;

ALTER TABLE `internal_vouchers`
    ADD COLUMN `contra_savings_id` INT UNSIGNED NULL;

ALTER TABLE `internal_vouchers`
    ADD COLUMN `contra_balance_before` DECIMAL(15,2) NULL;

ALTER TABLE `internal_vouchers`
    ADD COLUMN `contra_balance_after` DECIMAL(15,2) NULL;
```

---

## Code Changes Needed

### 1. InternalVoucherModel.php

#### Method: `resolveSubledgerSide()`

**REPLACE** the error for dual-subledger scenario:

```php
// CURRENT CODE (throws error):
if ($primaryRequires && $contraRequires) {
    throw new InvalidArgumentException(
        'Both the debit and credit accounts require a member subledger — this combination is not supported.'
    );
}

// NEW CODE (supports dual subledger):
if ($primaryRequires && $contraRequires) {
    // Both sides need subledgers - this is a member-to-member transfer
    return [
        'type' => 'dual',
        'primary_account' => $primaryAccount,
        'contra_account' => $contraAccount,
        'voucher_type' => $voucherType
    ];
}
```

#### Method: `createDraft()`

**ADD** validation and insertion for contra member fields:

```php
// After existing member_id/savings_account_id validation, ADD:

// Validate contra member/account if both sides require subledgers
if (!empty($data['contra_member_id']) && !empty($data['contra_savings_account_id'])) {
    $contraAccount = $memberAccountModel->getAccount((int)$data['contra_savings_account_id']);
    if (!$contraAccount || $contraAccount['status'] !== 'active') {
        throw new InvalidArgumentException('Contra savings account is not active.');
    }
    if ((int)$contraAccount['member_id'] !== (int)$data['contra_member_id']) {
        throw new InvalidArgumentException('Contra savings account does not belong to specified member.');
    }
}

// UPDATE INSERT statement to include contra fields:
$stmt = $this->db->prepare("
    INSERT INTO `internal_vouchers`
        (voucher_number, voucher_type, voucher_date, primary_account_id, contra_account_id,
         member_id, savings_account_id,
         contra_member_id, contra_savings_account_id,
         expense_category_id, narration, amount, status, recorded_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
");
```

#### Method: `post()`

**ADD** routing for dual-subledger scenario:

```php
$subledger = $this->resolveSubledgerSide($primaryAccount, $contraAccount, $voucher['voucher_type']);

// ADD this check BEFORE existing logic:
if ($subledger && $subledger['type'] === 'dual') {
    return $this->postDualSubledger($voucher, $subledger, $userId);
}

// ... existing single subledger logic ...
```

#### NEW Method: `postDualSubledger()`

```php
private function postDualSubledger(array $voucher, array $subledger, int $userId): array
{
    $memberAccountModel = new MemberSavingsAccountModel();
    $amount = (float)$voucher['amount'];
    $isDebit = $voucher['voucher_type'] === 'debit';
    
    // Validate PRIMARY account (debit side)
    $primaryAccount = $memberAccountModel->getAccount((int)$voucher['savings_account_id']);
    if (!$primaryAccount || $primaryAccount['status'] !== 'active') {
        throw new InvalidArgumentException('Primary savings account is not active.');
    }
    
    // Validate CONTRA account (credit side)
    $contraAccount = $memberAccountModel->getAccount((int)$voucher['contra_savings_account_id']);
    if (!$contraAccount || $contraAccount['status'] !== 'active') {
        throw new InvalidArgumentException('Contra savings account is not active.');
    }
    
    // Get balances
    $primaryBalance = $memberAccountModel->getAccountBalance((int)$voucher['savings_account_id']);
    $contraBalance = $memberAccountModel->getAccountBalance((int)$voucher['contra_savings_account_id']);
    
    // For DEBIT voucher: primary is debited, contra is credited
    // For CREDIT voucher: primary is credited, contra is debited
    $primaryIsDebited = $isDebit;
    $contraIsDebited = !$isDebit;
    
    // Check sufficient balance on the debited side
    if ($primaryIsDebited && $amount > $primaryBalance + 0.01) {
        throw new InvalidArgumentException(sprintf(
            'Insufficient balance in primary account. Required: Shs %s, Available: Shs %s',
            number_format($amount, 2), number_format($primaryBalance, 2)
        ));
    }
    
    if ($contraIsDebited && $amount > $contraBalance + 0.01) {
        throw new InvalidArgumentException(sprintf(
            'Insufficient balance in contra account. Required: Shs %s, Available: Shs %s',
            number_format($amount, 2), number_format($contraBalance, 2)
        ));
    }
    
    $primaryBalanceAfter = $primaryIsDebited ? $primaryBalance - $amount : $primaryBalance + $amount;
    $contraBalanceAfter = $contraIsDebited ? $contraBalance - $amount : $contraBalance + $amount;
    
    $ownTransaction = !$this->db->inTransaction();
    if ($ownTransaction) {
        $this->db->beginTransaction();
    }
    
    try {
        // Create PRIMARY side savings transaction
        $primaryTxnType = $primaryIsDebited ? 'transfer_out' : 'transfer_in';
        $primaryStmt = $this->db->prepare("
            INSERT INTO `savings`
                (member_id, savings_account_id, receipt_number, transaction_type, 
                 debit, credit, running_balance, description, payment_method, 
                 transaction_date, financial_year, notes, recorded_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Other', ?, ?, ?, ?, 'posted')
        ");
        $primaryStmt->execute([
            $voucher['member_id'],
            $voucher['savings_account_id'],
            $voucher['voucher_number'],
            $primaryTxnType,
            $primaryIsDebited ? $amount : 0.00,
            $primaryIsDebited ? 0.00 : $amount,
            $primaryBalanceAfter,
            "IV Transfer: {$voucher['voucher_number']} — {$voucher['narration']}",
            $voucher['voucher_date'],
            date('Y', strtotime($voucher['voucher_date'])),
            $voucher['narration'],
            $userId,
        ]);
        $primarySavingsId = (int)$this->db->lastInsertId();
        
        // Create CONTRA side savings transaction
        $contraTxnType = $contraIsDebited ? 'transfer_out' : 'transfer_in';
        $contraStmt = $this->db->prepare("
            INSERT INTO `savings`
                (member_id, savings_account_id, receipt_number, transaction_type, 
                 debit, credit, running_balance, description, payment_method, 
                 transaction_date, financial_year, notes, recorded_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Other', ?, ?, ?, ?, 'posted')
        ");
        $contraStmt->execute([
            $voucher['contra_member_id'],
            $voucher['contra_savings_account_id'],
            $voucher['voucher_number'],
            $contraTxnType,
            $contraIsDebited ? $amount : 0.00,
            $contraIsDebited ? 0.00 : $amount,
            $contraBalanceAfter,
            "IV Transfer: {$voucher['voucher_number']} — {$voucher['narration']}",
            $voucher['voucher_date'],
            date('Y', strtotime($voucher['voucher_date'])),
            $voucher['narration'],
            $userId,
        ]);
        $contraSavingsId = (int)$this->db->lastInsertId();
        
        // Post journal entry
        $lines = $isDebit
            ? [
                ['account_id' => $voucher['primary_account_id'], 'debit' => $amount, 'credit' => 0, 
                 'description' => $voucher['narration']],
                ['account_id' => $voucher['contra_account_id'], 'debit' => 0, 'credit' => $amount, 
                 'description' => $voucher['narration']],
            ]
            : [
                ['account_id' => $voucher['contra_account_id'], 'debit' => $amount, 'credit' => 0, 
                 'description' => $voucher['narration']],
                ['account_id' => $voucher['primary_account_id'], 'debit' => 0, 'credit' => $amount, 
                 'description' => $voucher['narration']],
            ];
        
        $voucherLabel = $isDebit ? 'Internal Debit Voucher' : 'Internal Credit Voucher';
        $period = $this->resolvePeriod($voucher['voucher_date']);
        
        $service = new JournalService();
        $result = $service->post([
            'entry_date'             => $voucher['voucher_date'],
            'description'            => "{$voucherLabel} {$voucher['voucher_number']} — Dual Subledger — {$voucher['narration']}",
            'source_module'          => 'internal_vouchers',
            'source_reference_type'  => 'voucher_dual_subledger',
            'source_reference_id'    => $voucherId,
            'financial_year_id'      => $period['financial_year_id'] ?? null,
            'accounting_period_id'   => $period['period_id'] ?? null,
            'created_by'             => $userId,
            'lines'                  => $lines,
        ]);
        
        // Update voucher with both savings IDs and balances
        $this->db->prepare("
            UPDATE `internal_vouchers` 
            SET status = 'posted', 
                posted_at = NOW(), 
                journal_entry_id = ?,
                savings_id = ?,
                balance_before = ?,
                balance_after = ?,
                contra_savings_id = ?,
                contra_balance_before = ?,
                contra_balance_after = ?
            WHERE id = ?
        ")->execute([
            $result['id'],
            $primarySavingsId,
            $primaryBalance,
            $primaryBalanceAfter,
            $contraSavingsId,
            $contraBalance,
            $contraBalanceAfter,
            $voucherId
        ]);
        
        $this->writeAudit($userId, 'posted_dual_subledger', $voucherId, [
            'journal_entry_id' => $result['id'],
            'entry_number' => $result['entry_number'],
            'primary_savings_id' => $primarySavingsId,
            'contra_savings_id' => $contraSavingsId,
        ]);
        
        if ($ownTransaction) {
            $this->db->commit();
        }
        
        return [
            'journal_entry_id' => $result['id'],
            'entry_number' => $result['entry_number'],
            'created' => $result['created'],
            'savings_id' => $primarySavingsId,
            'balance_before' => $primaryBalance,
            'balance_after' => $primaryBalanceAfter,
            'contra_savings_id' => $contraSavingsId,
            'contra_balance_before' => $contraBalance,
            'contra_balance_after' => $contraBalanceAfter,
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

### 2. form.php View Changes

#### Add Second Member/Account Search Block

After the existing `memberSubledgerBlock`, add a CONTRA block:

```html
<!-- EXISTING: Primary Member/Account Block -->
<div class="mb-3" id="memberSubledgerBlock" style="display:none;">
    <label class="form-label fw-semibold">
        <span id="primaryMemberLabel">Debit</span> Member / Account 
        <span class="text-muted fw-normal" id="voucherSubledgerAccountLabel"></span>
    </label>
    <input type="text" class="form-control" id="voucherMemberSearch" 
           placeholder="Search by name, member number, or account number..." autocomplete="off">
    <input type="hidden" name="member_id" id="voucherMemberId">
    <div class="list-group position-absolute" id="voucherMemberResults" 
         style="z-index:1000;display:none;max-height:220px;overflow-y:auto;"></div>

    <select name="savings_account_id" id="voucherAccountSelect" 
            class="form-select mt-2" disabled onchange="onVoucherAccountChange()">
        <option value="">Select a member first...</option>
    </select>
    <div class="alert alert-secondary py-1 px-2 small mb-0 mt-2" 
         id="voucherAccountBalanceText" style="display:none;"></div>
</div>

<!-- NEW: Contra Member/Account Block (shows when both sides need subledgers) -->
<div class="mb-3" id="contraMemberSubledgerBlock" style="display:none;">
    <label class="form-label fw-semibold">
        <span id="contraMemberLabel">Credit</span> Member / Account 
        <span class="text-muted fw-normal" id="contraSubledgerAccountLabel"></span>
    </label>
    <input type="text" class="form-control" id="contraMemberSearch" 
           placeholder="Search by name, member number, or account number..." autocomplete="off">
    <input type="hidden" name="contra_member_id" id="contraMemberId">
    <div class="list-group position-absolute" id="contraMemberResults" 
         style="z-index:1000;display:none;max-height:220px;overflow-y:auto;"></div>

    <select name="contra_savings_account_id" id="contraAccountSelect" 
            class="form-select mt-2" disabled onchange="onContraAccountChange()">
        <option value="">Select a member first...</option>
    </select>
    <div class="alert alert-secondary py-1 px-2 small mb-0 mt-2" 
         id="contraAccountBalanceText" style="display:none;"></div>
</div>
```

#### Update JavaScript: `checkSubledgerRequirement()`

```javascript
function checkSubledgerRequirement() {
    const catChosen = document.getElementById('expenseCategory').value !== '';
    const primarySel = document.getElementById('primaryAccount');
    const contraSel = document.getElementById('contraAccount');
    const primaryOpt = !catChosen ? primarySel.options[primarySel.selectedIndex] : null;
    const contraOpt = contraSel.options[contraSel.selectedIndex];

    const primaryNeeds = !!(primaryOpt && primaryOpt.value && primaryOpt.dataset.requiresSubledger === '1');
    const contraNeeds = !!(contraOpt && contraOpt.value && contraOpt.dataset.requiresSubledger === '1');
    
    const primaryBlock = document.getElementById('memberSubledgerBlock');
    const contraBlock = document.getElementById('contraMemberSubledgerBlock');
    const primaryLabel = document.getElementById('primaryMemberLabel');
    const contraLabel = document.getElementById('contraMemberLabel');
    
    const isDebit = document.getElementById('typeDebit').checked;
    
    // Show/hide blocks based on requirements
    if (primaryNeeds && contraNeeds) {
        // BOTH sides need subledgers - show both blocks
        primaryBlock.style.display = '';
        contraBlock.style.display = '';
        primaryLabel.textContent = isDebit ? 'Debit' : 'Credit';
        contraLabel.textContent = isDebit ? 'Credit' : 'Debit';
    } else if (primaryNeeds) {
        // Only primary needs subledger
        primaryBlock.style.display = '';
        contraBlock.style.display = 'none';
        primaryLabel.textContent = isDebit ? 'Debit' : 'Credit';
        clearContraMemberSearch();
    } else if (contraNeeds) {
        // Only contra needs subledger
        primaryBlock.style.display = '';
        contraBlock.style.display = 'none';
        // When contra needs subledger, show it in the primary block
        primaryLabel.textContent = isDebit ? 'Credit' : 'Debit';
        clearContraMemberSearch();
    } else {
        // Neither needs subledger
        primaryBlock.style.display = 'none';
        contraBlock.style.display = 'none';
        clearPrimaryMemberSearch();
        clearContraMemberSearch();
    }
    
    updatePreview();
}

function clearPrimaryMemberSearch() {
    document.getElementById('voucherMemberSearch').value = '';
    document.getElementById('voucherMemberId').value = '';
    document.getElementById('voucherMemberResults').style.display = 'none';
    resetVoucherAccountSelect();
}

function clearContraMemberSearch() {
    document.getElementById('contraMemberSearch').value = '';
    document.getElementById('contraMemberId').value = '';
    document.getElementById('contraMemberResults').style.display = 'none';
    resetContraAccountSelect();
}

function resetContraAccountSelect() {
    const sel = document.getElementById('contraAccountSelect');
    sel.innerHTML = '<option value="">Select a member first...</option>';
    sel.disabled = true;
    document.getElementById('contraAccountBalanceText').style.display = 'none';
}
```

#### Add Contra Member Search Handler (duplicate of primary but for contra)

```javascript
// CONTRA member search (similar to existing primary member search)
document.getElementById('contraMemberSearch').addEventListener('input', function () {
    document.getElementById('contraMemberId').value = '';
    resetContraAccountSelect();
    clearTimeout(window.__contraMemberTimer);
    const q = this.value.trim();
    const results = document.getElementById('contraMemberResults');
    if (q.length < 2) { results.style.display = 'none'; return; }
    window.__contraMemberTimer = setTimeout(() => {
        fetch('<?= $base ?>?page=internal-voucher-member-search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                results.innerHTML = '';
                if (!data.members || !data.members.length) { results.style.display = 'none'; return; }
                data.members.forEach(m => {
                    const item = document.createElement('a');
                    item.href = '#';
                    item.className = 'list-group-item list-group-item-action py-2';
                    item.innerHTML = '<div class="fw-semibold">' + m.full_name + ' (' + m.member_number + ')</div>' +
                                    '<small class="text-muted">' + (m.phone || 'No phone') + '</small>';
                    item.onclick = (e) => {
                        e.preventDefault();
                        document.getElementById('contraMemberSearch').value = m.full_name + ' (' + m.member_number + ')';
                        document.getElementById('contraMemberId').value = m.id;
                        results.style.display = 'none';
                        loadContraAccounts(m.id);
                    };
                    results.appendChild(item);
                });
                results.style.display = '';
            });
    }, 300);
});

function loadContraAccounts(memberId) {
    fetch('<?= $base ?>?page=internal-voucher-member-accounts&member_id=' + memberId)
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('contraAccountSelect');
            sel.innerHTML = '<option value="">Select account</option>';
            if (!data.accounts || !data.accounts.length) {
                sel.innerHTML = '<option value="">No active accounts</option>';
                sel.disabled = true;
                return;
            }
            data.accounts.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.account_type + ' — ' + a.account_number + ' (Shs ' + a.balance.toLocaleString() + ')';
                opt.dataset.balance = a.balance;
                sel.appendChild(opt);
            });
            sel.disabled = false;
        });
}

function onContraAccountChange() {
    const sel = document.getElementById('contraAccountSelect');
    const opt = sel.options[sel.selectedIndex];
    const balanceText = document.getElementById('contraAccountBalanceText');
    if (opt && opt.value && opt.dataset.balance) {
        balanceText.textContent = 'Current Balance: Shs ' + parseFloat(opt.dataset.balance).toLocaleString();
        balanceText.style.display = '';
    } else {
        balanceText.style.display = 'none';
    }
    updatePreview();
}
```

---

## Testing Steps

1. **Apply Schema:**
```bash
php -r "require 'app/config/config.php'; require 'app/config/database.php'; require 'core/Autoloader.php'; \$db = Database::getInstance(); \$sql = file_get_contents('database/internal_voucher_fix_dual_subledger.sql'); \$db->exec(\$sql);"
```

2. **Test UI:**
   - Select Debit Account: Members' Savings
   - Select Credit Account: Members' Savings
   - Verify TWO member search boxes appear
   - Search for different people in each
   - Select different accounts

3. **Test Transaction:**
   - Create draft
   - Submit for approval
   - Approve
   - Post
   - Verify both members' balances changed correctly

---

## Summary

**Before:** Form only showed ONE member search field when account requires subledger

**After:** Form shows TWO member search fields when BOTH debit and credit require subledgers

This allows transferring between any two members' savings accounts!
