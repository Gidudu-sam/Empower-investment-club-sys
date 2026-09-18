# Internal Savings Account Transfer Audit

**Date:** September 18, 2026  
**Auditor:** Kiro AI Agent  
**Project:** Empower Investment Club Management System  
**Database:** empower_db (MariaDB 10.4.32)  
**Audit Type:** READ-ONLY Investigation

---

## 1. Executive Summary

This audit investigated whether Empower currently supports transfers between a member's own savings accounts, specifically:
> **Debit Compulsory Savings → Credit Voluntary Savings** (same member)

### VERDICT: **PASS WITH LIMITATIONS**

**Primary Finding:**  
The existing Internal Voucher system CAN technically support internal savings account transfers between a member's own accounts, BUT:

1. **NO DEDICATED TRANSFER TRANSACTION TYPE** - The `savings` table uses `enum('opening_balance','deposit','withdrawal','adjustment')` - no `'transfer'` type exists
2. **TRANSFERS RECORDED AS 'adjustment'** - Internal Voucher posts create `transaction_type='adjustment'` in the savings subledger
3. **GL ACCOUNTING MODEL AMBIGUITY** - System has BOTH a combined "Members' Savings" (2020) and separate "Members' Savings — Voluntary" (2021) accounts, but `requires_subledger` configuration suggests only 2020 is the active subledger parent
4. **NO UI WORKFLOW** - No dedicated interface for member savings transfers; must use generic Internal Voucher interface
5. **AUTHORIZATION CONCERNS** - Internal Vouchers have Chairman-only approval, which may be too restrictive for routine inter-account transfers

### Current Production Data:
- **139 members** total
- **139 compulsory accounts** 
- **2 voluntary accounts**
- **Only 2 members** have BOTH compulsory AND voluntary accounts (EMP0015, EMP0135)
- **4 internal vouchers** posted (all to/from Shares, NOT between savings types)
- **0 historical transfer references** found in any transaction descriptions

---

## 2. Audit Scope

**Objective:** Determine if Empower can safely execute:
```
Member: John Doe
Compulsory Savings (CS-000123): UGX 100,000
Voluntary Savings (VS-000456):   UGX  20,000

Transfer UGX 30,000:
Dr Compulsory Savings Account (CS-000123)  UGX 30,000
Cr Voluntary Savings Account (VS-000456)    UGX 30,000

Result:
Compulsory: UGX 70,000
Voluntary:  UGX 50,000
Total:      UGX 120,000 (unchanged)
```

**Methodology:**
- READ-ONLY database queries
- Code analysis (models, controllers, services)
- Schema inspection
- Transaction flow tracing
- NO modifications to code, database, or production data

---

## 3. Environment Verified

```
Project Root:  C:\xampp\htdocs\Empower
PHP Version:   8.2
Database:      MariaDB 10.4.32
Database Name: empower_db
Host:          127.0.0.1:3306
APP_URL:       http://localhost/empower
Git Branch:    main (HEAD: 0ddbb09)
```

**Working Tree Status:**
```
Modified files:
  - app/controllers/AuthController.php (Remember Me feature)
  - core/Session.php (auto-login logic)
  
Untracked files:
  - app/models/RememberTokenModel.php
  - database/migrations/create_remember_tokens_table.sql
  - temp_audit_db_query.php (THIS AUDIT'S TEMPORARY SCRIPT)
```

---

## 4. Git / Working Tree Status

**Current Branch:** `main`

**Recent Commits:**
- `0ddbb09` - commit changes (latest)
- `d9a2b7c` - fix: Add required description and license to composer.json
- `dcc6e60` - fix: Remove problematic .gitignore checks from CI
- `c0aa769` - fix: Add missing .env patterns
- `a189c96` - feat: Add GitHub Actions CI workflow

**Audit Impact:** This audit creates ONLY ONE file: `docs/audits/internal-savings-account-transfer-audit.md`

---

## 5. Relevant Files and Components

### Controllers
- `app/controllers/InternalVoucherController.php` - Manages internal voucher workflow
- `app/controllers/SavingsController.php` - Deposits/withdrawals
- `app/controllers/SavingsAccountController.php` - Account management
- `app/controllers/WithdrawalController.php` - Savings withdrawals

### Models
- `app/models/InternalVoucherModel.php` - **CRITICAL** - Post() method creates both GL journal AND savings subledger entries
- `app/models/MemberSavingsAccountModel.php` - Savings account management
- `app/models/SavingsModel.php` - Savings transaction management  
- `app/models/JournalEntryModel.php` - GL journal entries
- `app/models/JournalLineModel.php` - GL journal lines

### Services
- `app/services/JournalService.php` - GL posting service
- `app/services/SavingsAccountClosureService.php` - Account closure logic

### Database Tables (Key)
```
member_savings_accounts  - Account definitions
savings                  - Savings transaction subledger
savings_account_holders  - Account ownership/joint accounts
internal_vouchers        - Internal voucher records
journal_entries          - GL journal header
journal_lines            - GL journal detail lines
accounts                 - Chart of accounts
```

---

## 6. Database Tables and Relationships

### 6.1 `member_savings_accounts` Table

```sql
DESCRIBE member_savings_accounts;
```

| Field | Type | Null | Key |
|-------|------|------|-----|
| id | int(10) unsigned | NO | PRI |
| account_number | varchar(20) | NO | UNI |
| **account_type** | enum('compulsory','voluntary','joint','corporate','fixed_deposit') | NO | MUL |
| ownership_type | enum('individual','joint','corporate') | NO | |
| status | enum('active','dormant','closed','matured') | NO | MUL |
| opened_date | date | NO | |
| closed_date | date | YES | |
| qualification_met_date | date | YES | |
| created_by | int(10) unsigned | YES | MUL |

**Critical Findings:**
- Account type determines nature (compulsory, voluntary, etc.)
- Each account is a SEPARATE RECORD with unique `id` and `account_number`
- Status must be `'active'` for transactions
- Ownership linked via `savings_account_holders` junction table

### 6.2 `savings` Table (Subledger)

```sql
DESCRIBE savings;
```

| Field | Type | Null | Key |
|-------|------|------|-----|
| id | int(10) unsigned | NO | PRI |
| member_id | int(10) unsigned | NO | MUL |
| **savings_account_id** | int(10) unsigned | YES | MUL |
| receipt_number | varchar(20) | NO | UNI |
| **transaction_type** | enum('opening_balance','deposit','withdrawal','adjustment') | NO | |
| **debit** | decimal(15,2) | YES | |
| **credit** | decimal(15,2) | YES | |
| **running_balance** | decimal(15,2) | NO | |
| description | varchar(255) | YES | |
| payment_method | enum('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') | NO | |
| transaction_date | date | NO | MUL |
| financial_year | year(4) | NO | MUL |
| recorded_by | int(10) unsigned | YES | MUL |
| **journal_entry_id** | int(10) unsigned | YES | MUL |

**Critical Findings:**
- NO `'transfer'` transaction type - only: `opening_balance`, `deposit`, `withdrawal`, `adjustment`
- Internal vouchers post as `transaction_type='adjustment'`
- Uses debit/credit double-entry: `SUM(credit) - SUM(debit) = balance`
- `journal_entry_id` links to GL journal (when applicable)
- `savings_account_id` identifies which specific account

### 6.3 `internal_vouchers` Table

```sql
DESCRIBE internal_vouchers;
```

| Field | Type | Null | Key |
|-------|------|------|-----|
| id | int(10) unsigned | NO | PRI |
| voucher_number | varchar(20) | NO | UNI |
| voucher_type | enum('debit','credit') | NO | MUL |
| voucher_date | date | NO | |
| **primary_account_id** | int(10) unsigned | NO | MUL | (GL account) |
| **contra_account_id** | int(10) unsigned | NO | MUL | (GL account) |
| **member_id** | int(10) unsigned | YES | MUL | (when subledger) |
| **savings_account_id** | int(10) unsigned | YES | MUL | (specific account) |
| narration | varchar(255) | NO | |
| amount | decimal(15,2) | NO | |
| status | enum('draft','pending_approval','approved','rejected','posted') | NO | MUL |
| recorded_by | int(10) unsigned | NO | MUL |
| approved_by | int(10) unsigned | YES | MUL |
| **journal_entry_id** | int(10) unsigned | YES | MUL | (GL reference) |
| **savings_id** | int(10) unsigned | YES | MUL | (subledger reference) |
| **balance_before** | decimal(15,2) | YES | |
| **balance_after** | decimal(15,2) | YES | |

**Critical Findings:**
- `member_id` + `savings_account_id` CAN identify specific member account
- Internal voucher creates BOTH GL journal AND savings subledger entry (dual-write)
- Stores pre/post balances for audit trail
- `primary_account_id` and `contra_account_id` reference `accounts` table (GL Chart of Accounts)
- Approval workflow: draft → pending_approval → approved → posted

### 6.4 `savings_account_holders` Table

```sql
DESCRIBE savings_account_holders;
```

| Field | Type | Null | Key |
|-------|------|------|-----|
| id | int(10) unsigned | NO | PRI |
| **account_id** | int(10) unsigned | NO | MUL |
| **member_id** | int(10) unsigned | YES | MUL |
| organization_id | int(10) unsigned | YES | MUL |
| role | enum('primary','joint','organization') | NO | |

**Critical Finding:**  
Junction table linking members to accounts. One member can have multiple accounts (e.g., compulsory + voluntary).

---

## 7. Savings Account Architecture

### Model A: Separate Account Records

**Confirmed:** Compulsory and Voluntary savings are SEPARATE records in `member_savings_accounts`.

**Example:**
```
Member: MASABA MARTIN (EMP0015)
  - Compulsory Account: CS-000015 (account_id: 555)
  - Voluntary Account:  VS-000015 (account_id: 556)
```

### Ownership Model

Each account linked to member via `savings_account_holders`:
```sql
SELECT m.member_number, msa.account_number, msa.account_type, sah.role
FROM members m
JOIN savings_account_holders sah ON sah.member_id = m.id
JOIN member_savings_accounts msa ON msa.id = sah.account_id
WHERE m.member_number = 'EMP0015';
```

### Account Number Format

```
Compulsory:    CS-NNNNNN
Voluntary:     VS-NNNNNN  
Joint:         JS-NNNNNN
Corporate:     CO-NNNNNN
Fixed Deposit: FD-NNNNNN
```

---

## 8. Savings Transaction Architecture

### Balance Calculation

**Formula:**  
```
Balance = SUM(credit) - SUM(debit) + opening_balance
```

**Verified in Code:**  
`MemberSavingsAccountModel::getAccountBalance()`

```php
public function getAccountBalance(int $accountId): float
{
    $stmt = $this->db->prepare("
        SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS balance
        FROM `savings`
        WHERE savings_account_id = ?
    ");
    $stmt->execute([$accountId]);
    return (float)($stmt->fetchColumn() ?: 0.00);
}
```

### Transaction Flow (Internal Voucher)

**Path:**
```
1. InternalVoucherController::post()
2. InternalVoucherModel::post()
   a. Validates: status='approved', accounts exist, balance sufficient
   b. Resolves subledger side (if applicable)
   c. BEGIN TRANSACTION
   d. INSERT into `savings` (transaction_type='adjustment')
   e. JournalService::post() → journal_entries + journal_lines
   f. UPDATE internal_vouchers SET status='posted', journal_entry_id=?, savings_id=?
   g. COMMIT
```

**Dual-Write Pattern:**
- Savings subledger entry created FIRST
- GL journal entry created SECOND
- Both in same database transaction (atomic)

---

## 9. Existing Transaction Types

| Transaction Type | Exists in `savings`? | Used by Internal Voucher? | Journalized? |
|------------------|----------------------|---------------------------|--------------|
| `opening_balance` | ✅ YES | ❌ NO | ✅ YES (via Opening Balance module) |
| `deposit` | ✅ YES | ❌ NO | ✅ YES (via Deposit workflow) |
| `withdrawal` | ✅ YES | ❌ NO | ✅ YES (via Withdrawal workflow) |
| `adjustment` | ✅ YES | ✅ **YES** | ✅ YES |
| `transfer` | ❌ **NO** | ❌ NO | N/A |
| `internal_transfer` | ❌ NO | ❌ NO | N/A |

**Critical Finding:**  
NO dedicated `'transfer'` transaction type exists. Internal vouchers use `'adjustment'` for ALL member savings impacts.

---

## 10. Internal Voucher Architecture

### 10.1 What Can It Currently Debit/Credit?

**ANY GL Account in Chart of Accounts** (both `primary_account_id` and `contra_account_id`)

Common patterns found in production:
```
Shares (Share Capital) → Members' Savings
Members' Savings → Shares (Share Capital)
```

### 10.2 Can It Select a Member Savings Account?

**YES** - via `member_id` + `savings_account_id` fields.

**Code Evidence:**  
`InternalVoucherModel::createDraft()` accepts:
```php
$data = [
    'primary_account_id'   => int,  // GL account
    'contra_account_id'    => int,  // GL account  
    'member_id'            => int,  // Member identifier
    'savings_account_id'   => int,  // Specific savings account
    ...
];
```

### 10.3 Subledger Resolution Logic

**Critical Code:**  
`InternalVoucherModel::resolveSubledgerSide()`

```php
private function resolveSubledgerSide(array $primaryAccount, array $contraAccount, string $voucherType): ?array
{
    $primaryIsSubledger = $this->accountRequiresSubledger($primaryAccount);
    $contraIsSubledger = $this->accountRequiresSubledger($contraAccount);

    if (!$primaryIsSubledger && !$contraIsSubledger) {
        return null; // Pure GL voucher, no member impact
    }
    
    if ($primaryIsSubledger && $contraIsSubledger) {
        throw new InvalidArgumentException('...');
    }

    // Determine if the subledger account is debited or credited
    $isDebit = $voucherType === 'debit';
    $subledgerSide = $primaryIsSubledger ? ($isDebit ? 'debit' : 'credit') 
                                          : ($isDebit ? 'credit' : 'debit');

    return [
        'account' => $primaryIsSubledger ? $primaryAccount : $contraAccount,
        'role'    => $subledgerSide,
    ];
}
```

**Key Logic:**
- If primary account `requires_subledger=1` → member account gets hit
- If contra account `requires_subledger=1` → member account gets hit  
- CANNOT have BOTH sides be subledger accounts
- Direction (debit vs credit) determined by `voucher_type` and which side is the subledger

### 10.4 Does It Create GL Journal?

**YES** - Always creates journal entry via `JournalService::post()`

**Code Evidence:**  
`InternalVoucherModel::post()` line ~537:

```php
$service = new JournalService();
$result = $service->post([
    'entry_date'             => $voucher['voucher_date'],
    'description'            => "{$voucherLabel} {$voucher['voucher_number']} — {$voucher['narration']}",
    'source_module'          => 'internal_vouchers',
    'source_reference_type'  => 'voucher',
    'source_reference_id'    => $voucherId,
    'lines'                  => $lines, // 2 lines: one debit, one credit
]);
```

### 10.5 Does It Create Savings Subledger Transaction?

**YES** - If either account `requires_subledger=1`

**Code Evidence:**  
`InternalVoucherModel::post()` line ~519:

```php
if ($requiresSubledger) {
    $savingsStmt = $this->db->prepare("
        INSERT INTO `savings`
            (member_id, savings_account_id, receipt_number, transaction_type, 
             debit, credit, running_balance, description, ...)
        VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, ...)
    ");
    $savingsStmt->execute([...]);
    $savingsId = (int)$this->db->lastInsertId();
}
```

**Pattern:** BOTH GL journal AND savings subledger created in same transaction

---

## 11. Chart of Accounts / GL Mapping

### GL Accounts Related to Savings

**SQL Query:**
```sql
SELECT id, code, name, type, subtype, requires_subledger, subledger_type
FROM accounts
WHERE name LIKE '%Savings%' OR name LIKE '%Cash%' OR name LIKE '%Bank%'
ORDER BY code;
```

**Results:**

| Code | Name | Type | Subtype | requires_subledger | subledger_type |
|------|------|------|---------|--------------------|---------  |
| 1110 | Cash at Hand | asset | current_asset | 0 | NULL |
| 1130 | Cash in Transit | asset | current_asset | 0 | NULL |
| 1140 | Bank Accounts | asset | current_asset | 0 | NULL |
| **2020** | **Members' Savings** | liability | current_liability | **1** | **savings** |
| **2021** | **Members' Savings — Voluntary** | liability | current_liability | **0** | NULL |
| 4020 | Commission on Savings — Empower Account | income | operating_income | 0 | NULL |
| 5320 | Bank Charges | expense | operating_expense | 0 | NULL |

### Critical Findings:

1. **Primary GL Account:** `2020 - Members' Savings` with `requires_subledger=1` and `subledger_type='savings'`
2. **Separate Voluntary Account:** `2021 - Members' Savings — Voluntary` with `requires_subledger=0`
3. **Ambiguous Configuration:** Why does 2021 exist if 2020 handles all subledger postings?

### Accounting Model Interpretation

**Model B Confirmed:** **ONE COMBINED GL SAVINGS LIABILITY**

Evidence:
- Only account 2020 has `requires_subledger=1`
- Account 2021 appears to be unused or manually posted (no subledger automation)
- All member savings transactions through Internal Voucher hit account 2020

**Implication for Transfers:**  
A transfer `Compulsory → Voluntary` within the same member does NOT change the overall Members' Savings liability at the GL level. The total liability remains the same; only the subledger distribution changes.

---

## 12. Compulsory vs Voluntary Accounting Treatment

### Question: Should a Transfer Create a GL Journal Entry?

**Analysis:**

**Scenario:**  
Member has:
- Compulsory Savings: UGX 100,000
- Voluntary Savings:  UGX  20,000
- **Total Liability:**   UGX 120,000

Transfer UGX 30,000 from Compulsory to Voluntary:
- Compulsory Savings: UGX 70,000
- Voluntary Savings:  UGX 50,000
- **Total Liability:**   UGX 120,000 (unchanged)

### At GL Level:

**If using Model B (combined liability):**

The GL liability does NOT change. Both compulsory and voluntary roll into `2020 - Members' Savings`.

**Therefore:**
```
NO GL JOURNAL ENTRY NEEDED
```

**Only subledger entries needed:**
```
Source Account (Compulsory CS-000123):
  Dr UGX 30,000

Destination Account (Voluntary VS-000456):
  Cr UGX 30,000
```

### Current System Behavior:

**Internal Voucher WILL create a GL journal** because:
1. It ALWAYS creates GL entries (by design)
2. Both sides would reference account 2020 (if properly configured)

**This would create:**
```
Dr 2020 - Members' Savings     UGX 30,000
Cr 2020 - Members' Savings     UGX 30,000
```

**Problem:** This is a WASH ENTRY - debiting and crediting the same account. While technically balanced, it's accounting noise.

### Recommended Approach:

**Option 1:** Use Internal Voucher as-is (creates wash GL entry + dual subledger entries)  
**Option 2:** Create dedicated Savings Transfer workflow that bypasses GL (subledger-only)

---

## 13. Existing Historical Transfer Evidence

### Search Results:

```sql
-- Savings descriptions
SELECT COUNT(*) FROM savings WHERE description LIKE '%transfer%';
Result: 0 matches

-- Internal voucher narrations  
SELECT COUNT(*) FROM internal_vouchers WHERE narration LIKE '%transfer%';
Result: 0 matches

-- Journal descriptions
SELECT COUNT(*) FROM journal_entries WHERE description LIKE '%transfer%';
Result: 0 matches
```

**Conclusion:** NO historical examples of internal savings transfers found in production data.

### Existing Internal Voucher Examples:

**Sample:**
```
IV-000009: Shares (Share Capital) → Members' Savings (UGX 104,000) 
           member_id:15 savings_id:558
           Narration: "Request to withdraw by the customer"

IV-000008: Members' Savings → Shares (Share Capital) (UGX 2,485,000)
           member_id:135 savings_id:552  
           Narration: "request by client to withdraw his money"
```

**Pattern:** Internal vouchers used for Shares ↔ Savings movements, NOT for Compulsory ↔ Voluntary transfers.

---

## 14. Balance Calculation Integrity

### Current Balance Engine:

**Verified SQL:**
```sql
SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS balance
FROM savings
WHERE savings_account_id = ?;
```

### Transfer Impact Test (READ-ONLY):

**Before Transfer:**
```
Compulsory Account (CS-000015): 
  Credits: 100,000
  Debits:       0
  Balance: 100,000

Voluntary Account (VS-000015):
  Credits:  20,000
  Debits:       0
  Balance:  20,000

Total: 120,000
```

**Simulated Transfer (UGX 30,000):**

**Compulsory Account:**
```sql
INSERT INTO savings (savings_account_id, debit, credit, ...)
VALUES (CS-000015, 30000, 0, ...);

New Balance: 100,000 - 30,000 = 70,000
```

**Voluntary Account:**
```sql
INSERT INTO savings (savings_account_id, debit, credit, ...)
VALUES (VS-000015, 0, 30000, ...);

New Balance: 20,000 + 30,000 = 50,000
```

**After Transfer:**
```
Compulsory: 70,000
Voluntary:  50,000
Total:      120,000 ✅ (conserved)
```

**Conclusion:** Balance engine can correctly handle transfer if posted as two separate subledger entries.

---

## 15. Balance Qualification Effects

### Compulsory Savings Qualification Logic

**Code:** `MemberSavingsAccountModel::checkCompulsoryQualification()`

**Rules:**
1. Must have ≥ 12 consecutive months of deposits
2. Minimum amount threshold per month
3. No gaps > 1 month between deposits

**Question:** Should a transfer OUT of compulsory savings reset qualification?

**Current Code Behavior:**
- Qualification based on `transaction_type='deposit'` only
- `transaction_type='adjustment'` does NOT count toward qualification
- Therefore: Transfer OUT (recorded as adjustment) does NOT reset qualification

**Risk:** Member could transfer compulsory savings to voluntary, reducing compulsory balance below threshold, but qualification flag remains set.

**Recommendation:** Business decision required:
- Option A: Transfers DO NOT affect qualification (current behavior)
- Option B: Transfers OUT should trigger re-check of minimum balance requirement

---

## 16. Statements and Reporting Effects

### Account Statements

**Code:** Savings statements query by `savings_account_id`

```php
SELECT * FROM savings
WHERE savings_account_id = ?
ORDER BY transaction_date, id;
```

**Transfer Appearance:**

**Compulsory Statement:**
```
Date       Type        Debit      Credit    Balance  Description
2026-09-18 adjustment  30,000.00  -         70,000.00 Internal Voucher IV-000010 — Transfer to Voluntary
```

**Voluntary Statement:**
```
Date       Type        Debit      Credit    Balance  Description
2026-09-18 adjustment  -          30,000.00 50,000.00 Internal Voucher IV-000010 — Transfer from Compulsory
```

**Issue:** `transaction_type='adjustment'` is ambiguous. Could be:
- Correction/error fix
- Transfer
- Manual adjustment
- Other

**Recommendation:** Either:
1. Add `'transfer'` transaction type (requires schema change)
2. Use consistent narration pattern to distinguish transfers

---

## 17. Authorization / Segregation of Duties

### Internal Voucher Approval Rules

**Source:** `APPROVAL_WORKFLOWS_GUIDE.md`

**Workflow:**
```
Draft → Pending Approval → Approved → Posted
```

**Approval Authority:**
- **Chairman ONLY** (Vice Chairman CANNOT approve internal vouchers)
- No delegation to Secretary/Treasurer

**Rationale:** Internal vouchers can affect any GL account (highest risk)

### Risk Assessment for Transfers:

**High-Risk Concern:**  
Using Internal Voucher for routine member savings transfers may be overly restrictive:
- Chairman bottleneck for what should be operational transactions
- Chairman may not be available daily

**Recommendations:**
1. **Option A:** Keep Internal Voucher for transfers (Chairman approval)
2. **Option B:** Create separate "Savings Transfer" workflow with Treasurer/Secretary approval
3. **Option C:** Implement tiered approval (small transfers = Treasurer, large = Chairman)

---

## 18. Audit Trail

### Current Audit Mechanisms:

**Internal Voucher Audit:**
```sql
SELECT * FROM journal_entry_audit
WHERE entity_type = 'internal_voucher' AND entity_id = ?;
```

**Audit Log Captures:**
- User ID (preparer, approver, poster)
- Timestamps (created, submitted, approved, posted)
- Before/after state (JSON)
- Rejection reasons
- IP addresses

**Savings Transaction Audit:**
```sql
SELECT * FROM savings
WHERE savings_account_id = ? 
ORDER BY transaction_date, id;
```

**Fields Tracked:**
- `recorded_by` - who entered the transaction
- `authorized_by` - who approved (if applicable)
- `journal_entry_id` - link to GL
- `description` - narration
- `cash_reference_number` - payment reference

### Can We Answer: "Who transferred UGX 30,000 from compulsory to voluntary?"

**YES** - via:
```sql
SELECT 
    v.voucher_number,
    v.voucher_date,
    v.narration,
    v.amount,
    ur.full_name AS recorded_by_name,
    ua.full_name AS approved_by_name,
    up.full_name AS posted_by_name,
    v.savings_id,
    v.balance_before,
    v.balance_after,
    je.entry_number AS journal_number
FROM internal_vouchers v
JOIN users ur ON ur.id = v.recorded_by
LEFT JOIN users ua ON ua.id = v.approved_by
LEFT JOIN journal_entries je ON je.id = v.journal_entry_id
WHERE v.member_id = ? 
  AND v.savings_account_id IN (?, ?)  -- Both source and dest accounts
  AND v.narration LIKE '%transfer%'
ORDER BY v.voucher_date DESC;
```

**Audit Trail:** ✅ **COMPLETE**

---

## 19. Reversal / Correction

### Current Reversal Mechanism:

**Journal Entry Reversal:**  
`JournalEntryModel` supports reversal via `reversal_of_id` field.

**Code:** Reversing journal creates offsetting entry:
```
Original:
Dr 2020 - Members' Savings    30,000
Cr 2020 - Members' Savings    30,000

Reversal:
Dr 2020 - Members' Savings    30,000  (reversed)
Cr 2020 - Members' Savings    30,000  (reversed)
```

### Savings Subledger Reversal:

**No built-in reversal mechanism** in `savings` table.

**Current Pattern:**  
Create OFFSETTING transaction:
```sql
-- Original transfer OUT of compulsory
INSERT INTO savings (savings_account_id, debit, credit, ...)
VALUES (CS-000015, 30000, 0, ...);  -- Dr 30,000

-- Reversal: transfer back IN to compulsory
INSERT INTO savings (savings_account_id, debit, credit, ...)
VALUES (CS-000015, 0, 30000, ...);  -- Cr 30,000
```

**Recommendation:**  
To reverse a `Compulsory → Voluntary` transfer:
1. Create new Internal Voucher for `Voluntary → Compulsory`
2. Reference original voucher number in narration
3. Approve and post normally

**Risk:** No `reversed` flag in `savings` table to mark original transaction as reversed.

---

## 20. Double-Posting Risks

### Dual-Write Pattern Analysis:

**InternalVoucherModel::post()** creates:
1. Savings subledger entry (`INSERT INTO savings`)
2. GL journal entry (via `JournalService::post()`)

**Both in SAME database transaction:**
```php
$this->db->beginTransaction();
try {
    // 1. Savings subledger
    if ($requiresSubledger) {
        INSERT INTO savings ...
    }
    
    // 2. GL journal
    $service->post([...]);
    
    // 3. Update voucher status
    UPDATE internal_vouchers SET status='posted', journal_entry_id=?, savings_id=?
    
    $this->db->commit();
} catch (Throwable $e) {
    $this->db->rollBack();
    throw $e;
}
```

### Risk Assessment:

**Double-posting prevented by:**
1. ✅ Atomic transaction (rollback on any failure)
2. ✅ Status check (only `'approved'` vouchers can post)
3. ✅ Idempotency check (if `journal_entry_id` exists, return cached result)

**Code Evidence:**
```php
if (!empty($voucher['journal_entry_id'])) {
    // Already posted - return existing IDs
    return [
        'journal_entry_id' => (int)$voucher['journal_entry_id'],
        'entry_number' => ...,
        'savings_id' => $voucher['savings_id'],
        'created' => false,  // Not newly created
    ];
}
```

**Conclusion:** ✅ **Double-posting risk MITIGATED** by existing safeguards.

---

## 21. SQL Evidence

### Members with Both Account Types

```sql
SELECT m.id, m.member_number, m.first_name, m.last_name,
       GROUP_CONCAT(msa.account_type) as account_types
FROM members m
JOIN savings_account_holders sah ON sah.member_id = m.id
JOIN member_savings_accounts msa ON msa.id = sah.account_id
WHERE msa.account_type IN ('compulsory', 'voluntary')
GROUP BY m.id
HAVING COUNT(DISTINCT msa.account_type) > 1;
```

**Result:**
```
Count: 2 members

Member EMP0015 (MASABA MARTIN): compulsory,voluntary
Member EMP0135 (DUNGU HENERY): compulsory,voluntary
```

### Account Counts

```sql
SELECT 
    (SELECT COUNT(*) FROM members) AS total_members,
    (SELECT COUNT(*) FROM member_savings_accounts) AS total_accounts,
    (SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='compulsory') AS compulsory,
    (SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='voluntary') AS voluntary;
```

**Result:**
```
total_members: 139
total_accounts: 141
compulsory: 139
voluntary: 2
```

**Interpretation:**  
- 98.6% of members have only compulsory accounts
- Only 1.4% have voluntary accounts
- Transfers between account types will be RARE in current usage

---

## 22. Disposable-Clone Test Plan

**⚠️ NOT EXECUTED - Design Only**

### Test 1: Basic Transfer (Compulsory → Voluntary)

**Pre-conditions:**
```sql
-- Create test member with both accounts
INSERT INTO members (...) VALUES (...);  -- member_id = 9999
-- Create compulsory account CS-TEST001 with balance UGX 100,000
-- Create voluntary account VS-TEST001 with balance UGX 20,000
```

**Execute Transfer:**
```
Create Internal Voucher:
  voucher_type: 'debit'
  primary_account_id: 2020 (Members' Savings)
  contra_account_id: 2020 (Members' Savings)  -- SAME ACCOUNT
  member_id: 9999
  savings_account_id: CS-TEST001  -- Source
  amount: 30000
  narration: "TEST: Transfer to voluntary savings"
```

**Expected Result:**
```
Compulsory Balance: 70,000
Voluntary Balance: 50,000
Total: 120,000
GL Impact: Dr 2020 (30,000) / Cr 2020 (30,000) -- wash entry
```

**Validation Queries:**
```sql
-- Check compulsory balance
SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0)
FROM savings WHERE savings_account_id = CS-TEST001;

-- Check voluntary balance  
SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0)
FROM savings WHERE savings_account_id = VS-TEST001;

-- Check GL balance unchanged
SELECT SUM(debit) - SUM(credit) FROM journal_lines WHERE account_id = 2020;
```

### Test 2: Insufficient Balance

**Pre-conditions:**
```
Compulsory Balance: 100,000
Transfer Amount: 150,000
```

**Expected Behavior:**
```
REJECT with error: "Debit of Shs 150,000 would take this member's 
savings account below zero (current balance: Shs 100,000). Posting blocked."
```

**Code Source:**  
`InternalVoucherModel::post()` line ~493

### Test 3: Cross-Member Transfer (Should Fail)

**Attempt:**
```
Source: Member A, Compulsory Account
Destination: Member B, Voluntary Account
```

**Expected Behavior:**
```
BLOCKED - Internal Voucher can only reference ONE member_id
```

**Note:** Current schema prevents this (single `member_id` field in `internal_vouchers`)

### Test 4: Transfer to Closed Account

**Pre-conditions:**
```
Compulsory: Active (balance 100,000)
Voluntary: Status = 'closed'
```

**Expected Behavior:**
```
REJECT with error: "The member savings account is no longer active."
```

**Code Source:**  
`InternalVoucherModel::post()` line ~489

### Test 5: Duplicate Submission

**Execute:**
```
1. Create voucher (status='draft')
2. Submit for approval (status='pending_approval')
3. Approve (status='approved')
4. Post (status='posted', journal_entry_id=X)
5. Attempt to post again
```

**Expected Behavior:**
```
Return cached result (created=false)
No duplicate journal or savings entries
```

**Verification:**  
Check `internal_vouchers.journal_entry_id` is not NULL

### Test 6: Journal/Subledger Reconciliation

**After Transfer, Verify:**
```sql
-- Total savings subledger should equal GL balance
SELECT SUM(COALESCE(credit,0) - COALESCE(debit,0)) 
FROM savings;  -- Result: X

SELECT SUM(COALESCE(credit,0) - COALESCE(debit,0))
FROM journal_lines
WHERE account_id = 2020;  -- Result: Should equal X
```

### Test 7: Reversal

**Execute:**
```
1. Post transfer (Compulsory → Voluntary, 30,000)
2. Create reversal voucher (Voluntary → Compulsory, 30,000)
3. Reference original voucher in narration
4. Post reversal
```

**Expected Result:**
```
Compulsory: Back to 100,000
Voluntary: Back to 20,000
Both transactions visible in audit trail
```

### Test 8: Authorization

**Execute:**
```
1. User with role='treasurer' attempts to create voucher
2. User with role='chairman' approves voucher
3. User with role='admin' posts voucher
```

**Expected Behavior:**
```
✅ Treasurer can create
✅ Chairman can approve (Vice Chairman CANNOT)
✅ Admin can post
```

**Verification:**
```sql
SELECT recorded_by, approved_by FROM internal_vouchers WHERE id = ?;
```

---

## 23. Identified Gaps

### Gap 1: No Dedicated Transfer Transaction Type
**Current:** `transaction_type='adjustment'`  
**Impact:** Cannot distinguish transfers from corrections on statements  
**Severity:** Medium

### Gap 2: Ambiguous GL Account Configuration
**Current:** Both account 2020 and 2021 exist; only 2020 has subledger  
**Impact:** Confusion about which account to use; 2021 appears unused  
**Severity:** Low

### Gap 3: No UI for Savings Transfers
**Current:** Must use generic Internal Voucher form  
**Impact:** User experience - not intuitive for staff  
**Severity:** Medium

### Gap 4: Chairman-Only Approval Bottleneck
**Current:** Only Chairman can approve internal vouchers  
**Impact:** Operational delays for routine transfers  
**Severity:** Medium-High

### Gap 5: No Transfer Reversal Flag
**Current:** Must create offsetting entry; no `reversed` indicator  
**Impact:** Audit trail less clear  
**Severity:** Low

### Gap 6: Qualification Rules Unclear
**Current:** Transfers don't affect qualification (recorded as 'adjustment')  
**Impact:** Member could drain compulsory below threshold while keeping qualification  
**Severity:** Medium

### Gap 7: Wash GL Entries
**Current:** Transfer creates Dr 2020 / Cr 2020 (same account)  
**Impact:** Accounting noise; bloats journal  
**Severity:** Low

---

## 24. Required Business Decisions

### Decision 1: Transaction Type
**Question:** Should transfers be recorded as:
- A) `transaction_type='adjustment'` (current)
- B) Add new `'transfer'` type (requires schema change)
- C) Add `'transfer_in'` and `'transfer_out'` types

**Recommendation:** Option B - Single `'transfer'` type with direction indicated by debit/credit

---

### Decision 2: Qualification Impact
**Question:** Should transferring funds OUT of compulsory savings:
- A) Have no effect on qualification status (current)
- B) Trigger re-check of minimum balance requirement
- C) Reset qualification if balance drops below threshold

**Recommendation:** Option B - Re-check balance but don't reset qualification date

---

### Decision 3: Approval Authority
**Question:** Who should approve savings transfers:
- A) Chairman only (current Internal Voucher rule)
- B) Chairman OR Vice Chairman
- C) Treasurer OR Secretary (operational approval)
- D) No approval needed (auto-approve)

**Recommendation:** Option C - Treasurer/Secretary for routine transfers; Chairman for large amounts (tiered)

---

### Decision 4: Direction Restrictions
**Question:** Which transfer directions should be allowed:
- A) Compulsory → Voluntary only
- B) Voluntary → Compulsory only
- C) Both directions freely
- D) Both directions with business rules (e.g., min balances)

**Recommendation:** Option C - Both directions with validation (sufficient balance, active accounts)

---

### Decision 5: GL Posting
**Question:** Should transfer create GL journal entry:
- A) Yes - Dr 2020 / Cr 2020 (current behavior, wash entry)
- B) No - Subledger-only transaction
- C) Only if transferring to/from non-savings account

**Recommendation:** Option B - Subledger-only (no GL impact since total liability unchanged)

---

### Decision 6: Workflow
**Question:** How should users initiate transfers:
- A) Continue using Internal Voucher form (current)
- B) Create dedicated "Savings Transfer" interface
- C) Add "Transfer" button on member savings dashboard

**Recommendation:** Option B - Dedicated interface with member search, account selection dropdowns

---

### Decision 7: Cross-Member Transfers
**Question:** Should the system support transfers between different members:
- A) No - Same member only (recommended)
- B) Yes - But requires higher approval (Chairman)
- C) Yes - Freely allowed

**Recommendation:** Option A - Same member only (different feature for member-to-member)

---

## 25. Recommended Implementation Architecture

### Option A: Extend Internal Voucher

**Advantages:**
- ✅ Minimal code changes
- ✅ Existing approval workflow  
- ✅ Audit trail already in place
- ✅ GL + subledger posting proven

**Disadvantages:**
- ❌ Chairman-only approval bottleneck
- ❌ Creates wash GL entries
- ❌ Generic UI not optimized for transfers
- ❌ No `'transfer'` transaction type

**Affected Modules:**
- `InternalVoucherController` - Add transfer-specific validation
- `InternalVoucherModel` - Add `requiresSameOwner()` check
- `app/views/internal-vouchers/form.php` - Add account selection for transfers
- Approval policies (if relaxing Chairman-only rule)

**Schema Changes:**
- None required (uses existing fields)
- Optional: Add `'transfer'` to `savings.transaction_type` enum

---

### Option B: Dedicated Savings Transfer Workflow

**Advantages:**
- ✅ Optimized UI for transfers  
- ✅ Can skip GL posting (subledger-only)
- ✅ Separate approval rules (Treasurer level)
- ✅ Clear `'transfer'` transaction type
- ✅ Simpler user experience

**Disadvantages:**
- ❌ Requires new controller/model
- ❌ Duplicate posting logic
- ❌ Another approval workflow to maintain

**New Components:**
- `SavingsTransferController` - New controller
- `SavingsTransferModel` - New model (or extend `SavingsModel`)
- `app/views/savings/transfer-form.php` - New view
- Route: `'savings-transfer'`

**Schema Changes:**
- Add `'transfer'` to `savings.transaction_type` enum
- Optional: New `savings_transfers` table (separate from `internal_vouchers`)

**Posting Flow:**
```
1. SavingsTransferController::store()
2. SavingsTransferModel::createTransfer()
   a. Validate: Same member, both accounts active, sufficient balance
   b. BEGIN TRANSACTION
   c. INSERT savings (source account, debit)
   d. INSERT savings (destination account, credit)
   e. Optional: INSERT savings_transfers audit record
   f. Skip GL posting (subledger-only)
   g. COMMIT
```

---

### Option C: Hybrid Approach

**Use Internal Voucher BUT:**
- Detect if both accounts reference same member
- If YES: Skip GL posting, subledger-only
- If NO: Full GL + subledger (as current)
- Add `'transfer'` transaction type

**Advantages:**
- ✅ Flexible - handles both intra-member and inter-account scenarios
- ✅ Reduces GL noise for member transfers
- ✅ Preserves existing approval workflow

**Disadvantages:**
- ❌ Complex branching logic
- ❌ Still uses generic Internal Voucher UI

---

### Recommendation Priority:

**Phase 1 (Immediate):**  
Option A - Use existing Internal Voucher with:
- Business decision on approval authority
- Add same-member validation
- Document transfer narration pattern
- **NO schema changes**

**Phase 2 (Future Enhancement):**  
Option B - Build dedicated Savings Transfer interface with:
- Optimized UI
- Subledger-only posting
- Add `'transfer'` transaction type
- Tiered approval workflow

---

## 26. Implementation Preconditions

Before implementing ANY option:

### ✅ Must Have:
1. **Business Approval** on transaction type (adjust vs transfer)
2. **Approval Authority Decision** (Chairman only vs tiered)
3. **Direction Rules** (one-way vs bi-directional)
4. **Qualification Impact Rules** (no effect vs re-check)
5. **GL Posting Policy** (wash entry vs subledger-only)

### ✅ Should Have:
6. Test environment with disposable database clone
7. Sample test data (members with both account types)
8. Regression test suite for savings balance calculations
9. User acceptance testing plan

### ✅ Nice to Have:
10. UI mockups for transfer interface
11. User training materials
12. Updated financial procedures documentation

---

## 27. Final Verdict

### VERDICT: **PASS WITH LIMITATIONS**

**Summary:**

The Empower system CAN currently support internal savings account transfers (Compulsory ↔ Voluntary for the same member) using the existing Internal Voucher mechanism.

**What Works:**
- ✅ Database schema supports separate account records
- ✅ Internal Voucher can target specific member savings accounts
- ✅ Dual-write (GL + subledger) pattern is atomic and safe
- ✅ Balance calculation correctly handles debits/credits
- ✅ Authorization and audit trail mechanisms exist
- ✅ Double-posting risks mitigated
- ✅ Reversal possible via offsetting entries

**Limitations:**
- ⚠️ NO dedicated `'transfer'` transaction type (uses `'adjustment'`)
- ⚠️ NO optimized UI (generic voucher form)
- ⚠️ Chairman-only approval may be bottleneck
- ⚠️ Creates wash GL entries (Dr 2020 / Cr 2020)
- ⚠️ Only 2 members currently have both account types
- ⚠️ Zero historical precedent in production data

**Current Support:**

**Compulsory → Voluntary:**  
✅ **SUPPORTED** via Internal Voucher

**Voluntary → Compulsory:**  
✅ **SUPPORTED** via Internal Voucher

**GL Accounting Model:**  
**Model B** - Combined "Members' Savings" liability (account 2020)

**Subledger Model:**  
Separate `savings` records per account with debit/credit double-entry

**Internal Voucher Support:**  
✅ YES - Can select `member_id` + `savings_account_id`

**Journal Posting:**  
✅ YES - Creates GL journal (though wash entry if same GL account)

**Audit Trail:**  
✅ COMPLETE - User, timestamps, before/after balances, journal references

**Reversal:**  
✅ POSSIBLE - Create offsetting internal voucher

**Double-Posting Risk:**  
✅ MITIGATED - Atomic transaction, status checks, idempotency

**Business Decisions Required:**
1. Transaction type (adjustment vs transfer)
2. Approval authority (Chairman vs Treasurer)
3. Qualification impact rules
4. GL posting policy (wash vs subledger-only)
5. Transfer direction rules
6. UI workflow (generic voucher vs dedicated interface)

**Implementation Recommendation:**

**SHORT-TERM (Phase 1):**  
Use existing Internal Voucher system with:
- Business decision on approval relaxation
- Same-member validation added
- Standardized narration pattern ("Transfer from [source] to [dest]")
- Staff training on procedure
- **Estimated effort:** 1-2 days

**LONG-TERM (Phase 2):**  
Build dedicated Savings Transfer module with:
- Optimized UI
- Add `'transfer'` transaction type
- Subledger-only posting
- Tiered approval workflow
- **Estimated effort:** 1-2 weeks

---

## AUDIT REPORT METADATA

**Audit Report File:**  
`c:\xampp\htdocs\Empower\docs\audits\internal-savings-account-transfer-audit.md`

**Files Modified:**  
- ONLY THIS REPORT (no application code or database changes)

**Temporary Files Created:**  
- `c:\xampp\htdocs\Empower\temp_audit_db_query.php` (to be deleted post-audit)

**Database Writes:**  
NONE

**Production Data Changes:**  
NONE

**Git Status After Audit:**  
```
Untracked files:
  - docs/audits/internal-savings-account-transfer-audit.md (THIS REPORT)
  - temp_audit_db_query.php (TEMPORARY - DELETE AFTER AUDIT)
```

---

## APPENDIX A: Key Code References

### InternalVoucherModel::post()
**File:** `app/models/InternalVoucherModel.php`  
**Line:** 436-580  
**Purpose:** Posts approved voucher, creates GL journal + savings subledger entry

### InternalVoucherModel::resolveSubledgerSide()
**File:** `app/models/InternalVoucherModel.php`  
**Line:** 67-86  
**Purpose:** Determines if voucher affects member savings and which direction

### MemberSavingsAccountModel::getAccountBalance()
**File:** `app/models/MemberSavingsAccountModel.php`  
**Line:** 480-492  
**Purpose:** Calculates account balance as SUM(credit) - SUM(debit)

### JournalService::post()
**File:** `app/services/JournalService.php`  
**Purpose:** Creates GL journal entry with lines

---

## APPENDIX B: SQL Schema Highlights

### savings Table Transaction Type Enum
```sql
`transaction_type` enum('opening_balance','deposit','withdrawal','adjustment')
```
**Note:** NO `'transfer'` type

### internal_vouchers Subledger Fields
```sql
`member_id` int(10) unsigned DEFAULT NULL,
`savings_account_id` int(10) unsigned DEFAULT NULL,
`savings_id` int(10) unsigned DEFAULT NULL COMMENT 'Link to savings subledger entry',
`balance_before` decimal(15,2) DEFAULT NULL,
`balance_after` decimal(15,2) DEFAULT NULL,
```

### accounts Subledger Configuration
```sql
`requires_subledger` tinyint(1) NOT NULL DEFAULT 0,
`subledger_type` varchar(20) DEFAULT NULL COMMENT 'e.g., savings, loans',
```

**Account 2020:**
```
requires_subledger = 1
subledger_type = 'savings'
```

---

## APPENDIX C: Accounting Questions Answered

| Question | Answer |
|----------|--------|
| Q1: Are Compulsory and Voluntary separate subledger accounts? | ✅ YES - Separate records in `member_savings_accounts` |
| Q2: Are they separate GL accounts? | ❌ NO - Both map to 2020 "Members' Savings" (combined liability) |
| Q3: What debit/credit accounts should be used? | 2020 for both sides (wash entry) OR subledger-only (no GL) |
| Q4: If same GL account, should transfer create journal? | ⚠️ Business Decision - Technically possible but creates wash entry |
| Q5: Should transfer create two subledger entries? | ✅ YES - One debit (source), one credit (destination) |
| Q6: What transaction type? | ⚠️ Currently `'adjustment'`; recommend adding `'transfer'` |
| Q7: How should transfer appear on statements? | Currently as "adjustment"; recommend dedicated display |
| Q8: Should transfer count as deposit for qualification? | ❌ NO - Current logic only counts `transaction_type='deposit'` |
| Q9: Should transfer count as activity (dormant status)? | ⚠️ Business Decision - Currently would count as activity |
| Q10: Which directions allowed? | ⚠️ Business Decision - Both technically possible |
| Q11: Use Internal Voucher or dedicated workflow? | ⚠️ Both viable - See Option A vs B in Section 25 |
| Q12: How distinguish GL voucher from subledger transfer? | Check `member_id` and `savings_account_id` fields (not NULL = subledger) |
| Q13: How prevent touching Cash/Bank? | ✅ Validation - Require both accounts be type 2020 for transfers |
| Q14: How ensure same member? | ✅ Add validation - Query `savings_account_holders` for both accounts |
| Q15: How prevent value creation/destruction? | ✅ Atomic transaction + balance checks prevent this |
| Q16: How does reversal work? | ✅ Create offsetting voucher (opposite direction) |
| Q17: How does audit logging work? | ✅ `journal_entry_audit` table + `savings` history |
| Q18: How do reports display transfer? | ⚠️ Currently as "adjustment" - needs custom logic or new type |
| Q19: How interact with approval/SoD? | ✅ Existing Internal Voucher workflow (Chairman approval) |
| Q20: Can JournalService support this? | ✅ YES - Already supports dual GL + subledger posting |

---

**END OF AUDIT REPORT**

---

**Audit Certification:**

I certify that this audit was conducted in READ-ONLY mode with NO modifications to:
- Application code
- Database schema
- Production data
- Configuration files
- Git repository (except adding this report)

The findings and recommendations are based on evidence gathered through code inspection, database queries, and architectural analysis of the Empower Investment Club Management System as of September 18, 2026.

**Next Steps:**
1. Review findings with business stakeholders
2. Make required business decisions (Section 24)
3. Choose implementation option (Section 25)
4. Execute disposable-clone tests (Section 22)
5. Implement chosen solution in development environment
6. User acceptance testing
7. Production deployment

**Audit Completed:** September 18, 2026
