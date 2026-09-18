# Shares and Internal Voucher Integration - Forensic Audit Report

**Audit Date:** September 18, 2026  
**System:** Empower Investment Club Management System  
**Audit Type:** READ-ONLY Pre-Production Architecture Review  
**Status:** ⚠️ **BLOCKED - INCOMPLETE FEATURE MIGRATION**

---

## Executive Summary

**VERDICT: BLOCKED - CRITICAL INTEGRATION FAILURE**

The Shares and Internal Voucher integration feature is **INCOMPLETE and NON-FUNCTIONAL**. The system was partially migrated to support share transactions via Internal Vouchers, but the migration was abandoned mid-implementation. The controller layer was never updated to pass required fields to the model layer, making it impossible to create share-related vouchers.

**Impact:** System cannot process Savings→Shares or Shares→Savings transfers via Internal Voucher interface despite database schema and business logic being in place.

**Root Cause:** InternalVoucherController::store() method does not pass `share_member_id` and `contra_share_member_id` fields from form submission to the model's `createDraft()` method.

---

## 1. Error Analysis

### 1.1 Current Error Message
```
"Select a valid member for the contra shares account. [contra_share_member_id: empty, contra_savings_account_id: empty]"
```

**Error Location:**
- File: `app/models/InternalVoucherModel.php`
- Line: 156
- Method: `validateSharesSubledger()`

### 1.2 Call Chain
```
Browser Form (POST)
    ↓
InternalVoucherController::store() [line 114]
    ↓ (missing fields here)
InternalVoucherModel::createDraft() [line 268]
    ↓
InternalVoucherModel::validateSharesSubledger() [lines 351, 378, 417]
    ↓
❌ EXCEPTION THROWN (line 156)
```

---

## 2. Root Cause Analysis

### 2.1 Controller-Model Mismatch

**Controller sends (InternalVoucherController.php, line 114-126):**
```php
$voucherData = [
    'voucher_type'       => $_POST['voucher_type'],
    'voucher_date'       => $_POST['voucher_date'],
    'primary_account_id' => $_POST['primary_account_id'],
    'expense_category_id'=> $_POST['expense_category_id'] ?? null,
    'contra_account_id'  => $_POST['contra_account_id'],
    'member_id'          => $_POST['member_id'] ?? null,
    'savings_account_id' => $_POST['savings_account_id'] ?? null,
    'narration'          => $_POST['narration'],
    'amount'             => $_POST['amount']
];
// ❌ share_member_id and contra_share_member_id NOT included
```

**Model expects (InternalVoucherModel.php, line 156):**
```php
$memberId = $data['share_member_id'] ?? null;         // ❌ MISSING
$contraMemberId = $data['contra_share_member_id'] ?? null;  // ❌ MISSING
```

### 2.2 Form Submission Verified

**Form HTML (app/views/internal-vouchers/form.php, lines 26-27):**
```html
<input type="hidden" name="contra_share_member_id" id="contraVoucherShareMemberId" value="">
<input type="hidden" name="share_member_id" id="voucherShareMemberId" value="">
```

**JavaScript Behavior:**
- Fields are correctly populated on member selection
- Form correctly submits these fields in POST payload
- **Controller ignores these fields** ✓ CONFIRMED

---

## 3. Architectural Review

### 3.1 Internal Voucher Architecture

**Type:** Two-sided voucher (NOT multi-line)
- One `primary_account_id` (debit or credit)
- One `contra_account_id` (opposite side)
- Always creates exactly 2 journal lines

**Subledger Support:**
- `SUPPORTED_SUBLEDGER_TYPES = ['savings', 'shares']`
- Each side can have independent subledger type
- Supports dual subledger (different members for each side)

### 3.2 Share Transactions Architecture

**Database Tables:**

1. **share_transactions** (primary ledger):
   - `member_id` (FK to members)
   - `transaction_type` ENUM: retained_withdrawal, direct_purchase, transfer_in, transfer_out, redemption, adjustment, opening_retained, opening_purchase
   - `quantity` DECIMAL(15,4)
   - `share_value`, `amount`, `reference_number`
   - `source_reference_type` (e.g., 'internal_voucher')
   - `source_reference_id` (FK to internal_vouchers.id)
   - `journal_entry_id` (FK to journal_entries)

2. **member_share_accounts** (account registry):
   - ONE account per member (UNIQUE `member_id`)
   - `account_number` (e.g., SHR-000123)
   - Status tracking (active/dormant/closed)
   - **NOT used for balance calculation**

3. **internal_vouchers** (voucher header):
   - `share_member_id` (for primary side shares)
   - `contra_share_member_id` (for contra side shares)
   - Columns added in migration but never used

**Balance Calculation:**
```sql
-- ShareModel::memberQuantity()
SUM(withdrawals.retained_amount) + 
SUM(CASE 
    WHEN transaction_type IN ('transfer_out', 'redemption') THEN -quantity 
    ELSE quantity 
END)
```

### 3.3 GL Account Configuration

**Account 3010 - Members' Shares (Equity):**
- `requires_subledger = 1`
- `subledger_type = 'shares'`
- Journal entries post to GL
- Subledger details post to `share_transactions`

---

## 4. Designed Scenarios (Currently Broken)

### Scenario A: Savings → Shares (Same Member)
**Business Case:** Member converts savings to share capital

**Accounting Entry:**
```
Dr 2050 Members' Savings (Liability)  $500.00
  Cr 3010 Members' Shares (Equity)            $500.00
```

**Subledger Impact:**
- Reduce member's savings balance: $500.00
- Increase member's shares: 50 shares @ $10.00
- Transaction type: `direct_purchase`

**Current Status:** ❌ BLOCKED by controller issue

---

### Scenario B: Shares → Savings (Same Member)
**Business Case:** Member redeems shares back to savings

**Accounting Entry:**
```
Dr 3010 Members' Shares (Equity)       $500.00
  Cr 2050 Members' Savings (Liability)         $500.00
```

**Subledger Impact:**
- Reduce member's shares: 50 shares @ $10.00
- Increase member's savings balance: $500.00
- Transaction type: `redemption`

**Current Status:** ❌ BLOCKED by controller issue

---

### Scenario C: Savings → Shares (Different Members)
**Business Case:** Member A gifts savings to Member B as shares

**Accounting Entry:**
```
Dr 2050 Members' Savings (Liability)  $500.00
  Cr 3010 Members' Shares (Equity)            $500.00
```

**Subledger Impact:**
- Reduce Member A's savings: $500.00
- Increase Member B's shares: 50 shares @ $10.00
- Dual subledger transaction

**Model Support:** ✓ Implemented (see `$isDual` logic, lines 483-499)  
**Current Status:** ❌ BLOCKED by controller issue

---

## 5. Historical Shares Implementation

**Special Transaction Types:**
- `opening_retained`: Historical retained earnings converted to shares
- `opening_purchase`: Historical share purchases recognized at system start

**Characteristics:**
- `journal_entry_id = NULL` (no GL impact - pre-system equity)
- Special reference numbers (e.g., "OPEN-RET-2024-001")
- Add to member balances without creating new GL transactions
- Used for one-time equity recognition during system migration

---

## 6. Key Questions Answered

### Q1: Is this a form issue, controller issue, or model issue?
**A:** **Controller issue.** 
- Form correctly submits fields ✓
- Model correctly validates fields ✓
- Controller fails to pass fields from form to model ❌

### Q2: Why does it work for savings but not shares?
**A:** Savings uses the existing `member_id` field (line 121 of controller) which was always present. Shares requires NEW fields (`share_member_id`, `contra_share_member_id`) that were added to the model layer but NEVER integrated into the controller layer.

### Q3: When was shares support added to Internal Voucher?
**A:** Evidence shows incomplete migration:
- **Database layer:** Migration `internal_voucher_fix_dual_subledger.sql` added columns
- **Model layer:** `post()` method (lines 397-580) has complete shares logic
- **Controller layer:** ❌ NEVER UPDATED (abandoned mid-implementation)

### Q4: Is member_share_accounts table used correctly?
**A:** Yes. Internal Vouchers do NOT use `member_share_accounts` directly. They write to `share_transactions` with `member_id`. The `ShareModel::memberQuantity()` method calculates balances by summing transactions. This is correct design - `member_share_accounts` is an account registry, not a transaction ledger.

### Q5: Can Internal Voucher handle dual subledger (different members)?
**A:** Model **SUPPORTS** this via `$isDual` logic (lines 483-499). Controller **BLOCKS** this by not passing required fields. Feature is architecturally sound but non-functional.

### Q6: What's the smallest safe fix?
**A:** Add 2 lines to `InternalVoucherController::store()` after line 121:
```php
'share_member_id'        => $_POST['share_member_id'] ?? null,
'contra_share_member_id' => $_POST['contra_share_member_id'] ?? null,
```

This matches the pattern used for existing fields and maintains null safety.

### Q7: Why wasn't this caught in testing?
**A:** Feature was **NEVER COMPLETED**. No evidence exists of any successful shares voucher creation via the Internal Voucher form. This appears to be an abandoned development effort where:
1. Database schema was updated
2. Model business logic was implemented
3. Form fields were added
4. **Controller integration was abandoned**

---

## 7. Security & Compliance Review

### 7.1 Security Controls (Model Layer)
✓ CSRF token validation present  
✓ Prepared SQL statements used  
✓ Input validation implemented  
✓ Share balance validation before redemption  
✓ Audit trail logging (activity_logs table)

### 7.2 Accounting Controls
✓ Double-entry accounting enforced (always 2 lines)  
✓ Subledger reconciliation tracked  
✓ Quantity before/after recorded  
✓ Journal entry linkage maintained  
✓ Source reference tracking (voucher_id)

**Issue is NOT security-related** - it is an incomplete feature migration.

---

## 8. Recommendations

### 8.1 CRITICAL - Immediate Action Required

**Fix Controller Integration:**

**File:** `app/controllers/InternalVoucherController.php`  
**Line:** 114-126  
**Change:** Add share member ID fields to `$voucherData` array

```php
$voucherData = [
    'voucher_type'       => $_POST['voucher_type'],
    'voucher_date'       => $_POST['voucher_date'],
    'primary_account_id' => $_POST['primary_account_id'],
    'expense_category_id'=> $_POST['expense_category_id'] ?? null,
    'contra_account_id'  => $_POST['contra_account_id'],
    'member_id'          => $_POST['member_id'] ?? null,
    'savings_account_id' => $_POST['savings_account_id'] ?? null,
    // ADD THESE TWO LINES:
    'share_member_id'        => $_POST['share_member_id'] ?? null,
    'contra_share_member_id' => $_POST['contra_share_member_id'] ?? null,
    // END NEW LINES
    'narration'          => $_POST['narration'],
    'amount'             => $_POST['amount']
];
```

**Risk Level:** LOW - Simple field mapping, no logic changes  
**Testing Required:** 
1. Create Savings→Shares voucher (same member)
2. Create Shares→Savings voucher (same member)
3. Create Savings→Shares voucher (different members)
4. Verify GL entries and subledger balances reconcile

### 8.2 Post-Fix Verification Checklist

- [ ] Voucher creates without validation errors
- [ ] `share_transactions` record created with correct `transaction_type`
- [ ] Member share balance updates correctly via `ShareModel::memberQuantity()`
- [ ] Journal entry creates with 2 lines (Dr/Cr)
- [ ] `internal_vouchers.share_transaction_id` links correctly
- [ ] Quantity before/after recorded accurately
- [ ] Audit trail logged to `activity_logs`
- [ ] Dual subledger scenario works (different members)

### 8.3 Future Enhancements (Post-Production)

1. **Add integration tests** for share voucher scenarios
2. **Document share transaction workflows** in system documentation
3. **Add UI validation** to prevent invalid share redemptions (insufficient balance)
4. **Implement share transfer approval workflow** if business rules require

---

## 9. Conclusion

The Shares and Internal Voucher integration represents a **well-designed architectural solution that was incompletely implemented**. The database schema, business logic, and accounting controls are sound. The failure point is a simple oversight in the controller layer where new fields were not added to the data mapping array.

**This is a 2-line code fix with minimal risk.**

The model layer has comprehensive validation that will catch any post-fix issues during testing. The accounting framework (double-entry, subledger tracking, audit trails) is robust.

**Recommendation:** Implement the controller fix, execute the verification checklist, and proceed with production deployment.

---

## Appendix A: Code References

### Controller File
`app/controllers/InternalVoucherController.php`
- store() method: line 75-186
- Field mapping: lines 114-126 (FIX REQUIRED HERE)

### Model File
`app/models/InternalVoucherModel.php`
- createDraft(): lines 268-433
- validateSharesSubledger(): lines 103-160
- post(): lines 397-580 (shares logic: 483-528)

### Form File
`app/views/internal-vouchers/form.php`
- Hidden fields: lines 26-27
- JavaScript: lines 320-450

### Database Migrations
- `database/internal_voucher_fix_dual_subledger.sql` (adds share_member_id columns)
- `database/shares_module_stage1.sql` (creates share_transactions table)
- `database/member_share_accounts_schema.sql` (creates account registry)

---

## Appendix B: Transaction Type Reference

| Transaction Type | Effect on Balance | Used By | GL Impact |
|-----------------|-------------------|---------|-----------|
| `retained_withdrawal` | +quantity | Withdrawal retention | Yes |
| `direct_purchase` | +quantity | Internal Voucher | Yes |
| `transfer_in` | +quantity | Internal Voucher | Yes |
| `transfer_out` | -quantity | Internal Voucher | Yes |
| `redemption` | -quantity | Internal Voucher | Yes |
| `adjustment` | +/- quantity | Admin correction | Yes |
| `opening_retained` | +quantity | Historical import | No (NULL journal) |
| `opening_purchase` | +quantity | Historical import | No (NULL journal) |

---

**Audit Completed By:** Kiro AI Development Environment  
**Audit Duration:** 15-task comprehensive READ-ONLY investigation  
**Code Modified:** NONE (read-only audit as requested)  
**Next Action:** Implement 2-line controller fix and execute verification checklist

---

## VERDICT: PASS WITH REQUIRED FIX

✅ **Architecture:** Sound  
✅ **Security:** Adequate  
✅ **Accounting:** Compliant  
❌ **Implementation:** Incomplete (2-line fix required)  

**Production Readiness:** BLOCKED until controller fix implemented and tested.
