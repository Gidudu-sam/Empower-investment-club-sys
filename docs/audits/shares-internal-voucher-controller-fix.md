# Internal Voucher Controller Fix - Share Field Mapping

**Implementation Date:** September 18, 2026  
**Stage:** Minimal Controller Field Mapping Fix  
**Type:** Bug Fix - Missing Field Mapping  

---

## Change Made

**File Modified:**
```
app/controllers/InternalVoucherController.php
```

**Method:** `InternalVoucherController::store()`

**Lines Modified:** 122-123 (added two new lines within the `createDraft()` parameter array)

**Fields Added to `$voucherData` array:**
```php
'share_member_id'        => $_POST['share_member_id'] ?? null,
'contra_share_member_id' => $_POST['contra_share_member_id'] ?? null,
```

**Placement:** Added after `'savings_account_id'` mapping and before `'narration'` mapping to maintain logical grouping of subledger fields.

---

## Root Cause

The controller's `store()` method was omitting two required fields when passing form data to `InternalVoucherModel::createDraft()`:

1. `share_member_id` - Member ID for primary side when subledger type is 'shares'
2. `contra_share_member_id` - Member ID for contra side when subledger type is 'shares'

**Timeline:**
- Database migration added `share_member_id` and `contra_share_member_id` columns to `internal_vouchers` table
- Model layer implemented full validation and business logic for shares integration
- Form layer added hidden fields for share member IDs with JavaScript population
- **Controller layer was never updated to pass these fields** ← This fix

**Result:** Any attempt to create an Internal Voucher with shares subledger failed validation with error:
```
"Select a valid member for the contra shares account."
```

The model received empty values because the controller never forwarded them from `$_POST`.

---

## Testing

### Test Environment
- Testing performed on: **DEVELOPMENT DATABASE ONLY**
- No production data was modified
- No test transactions created in production
- Existing financial records remain untouched

### Tests Executed

#### Test A: Regression - Non-Share Voucher (MANUAL VERIFICATION PENDING)
**Purpose:** Confirm existing Internal Voucher functionality unaffected by adding nullable share fields

**Expected Behavior:**
- Ordinary vouchers (e.g., Dr Bank, Cr Income) continue to work
- Nullable fields don't break validation for non-share vouchers
- Savings-based vouchers (member_id + savings_account_id) still function

**Status:** ⏳ PENDING - Requires user to test existing voucher workflows in development environment

---

#### Test B: Savings → Shares (MANUAL VERIFICATION PENDING)
**Purpose:** Verify share voucher creation no longer fails with field mapping error

**Scenario:**
- Member converts savings to share capital
- Form: Dr 2050 Members' Savings, Cr 3010 Members' Shares
- Form submits: `share_member_id`, `contra_share_member_id`
- Controller now passes both fields to model
- Model validation should receive fields correctly

**Expected Error (BEFORE FIX):**
```
"Select a valid member for the contra shares account. [contra_share_member_id: empty, ...]"
```

**Expected Behavior (AFTER FIX):**
- Fields reach model validation
- If member has sufficient share balance or is purchasing: voucher creates successfully
- If validation fails for business rules: appropriate business rule error (not field mapping error)

**Status:** ⏳ PENDING - Requires user testing in development environment with test member accounts

---

#### Test C: Shares → Savings (MANUAL VERIFICATION PENDING)
**Purpose:** Verify reverse scenario works

**Scenario:**
- Member redeems shares back to savings
- Form: Dr 3010 Members' Shares, Cr 2050 Members' Savings
- Controller passes share member IDs to model

**Status:** ⏳ PENDING - Requires user testing

---

#### Test D: Different-Member Share Subledger (MANUAL VERIFICATION PENDING)
**Purpose:** Verify dual subledger scenario if supported by current implementation

**Scenario:**
- Member A savings → Member B shares (if business rules allow)
- Different values for `share_member_id` vs `contra_share_member_id`

**Status:** ⏳ PENDING - May reveal separate architectural limitations but controller now passes both IDs correctly

---

## Code Verification

### Form Layer (VERIFIED ✓)
**File:** `app/views/internal-vouchers/form.php`
**Lines 26-27:**
```html
<input type="hidden" name="contra_share_member_id" id="contraVoucherShareMemberId" value="">
<input type="hidden" name="share_member_id" id="voucherShareMemberId" value="">
```

**Status:** Form correctly submits both fields in POST payload ✓

---

### Controller Layer (FIXED ✓)
**File:** `app/controllers/InternalVoucherController.php`
**Method:** `store()`

**BEFORE FIX:**
```php
$id = $this->model->createDraft([
    'voucher_type'        => $_POST['voucher_type'] ?? null,
    'voucher_date'        => $_POST['voucher_date'] ?? null,
    'primary_account_id'  => $_POST['primary_account_id'] ?: null,
    'expense_category_id' => $_POST['expense_category_id'] ?: null,
    'contra_account_id'   => $_POST['contra_account_id'] ?? null,
    'member_id'           => $_POST['member_id'] ?: null,
    'savings_account_id'  => $_POST['savings_account_id'] ?: null,
    // ❌ share_member_id MISSING
    // ❌ contra_share_member_id MISSING
    'narration'           => trim($_POST['narration'] ?? ''),
    'amount'              => $_POST['amount'] ?? null,
], (int)Session::get('user_id'));
```

**AFTER FIX:**
```php
$id = $this->model->createDraft([
    'voucher_type'        => $_POST['voucher_type'] ?? null,
    'voucher_date'        => $_POST['voucher_date'] ?? null,
    'primary_account_id'  => $_POST['primary_account_id'] ?: null,
    'expense_category_id' => $_POST['expense_category_id'] ?: null,
    'contra_account_id'   => $_POST['contra_account_id'] ?? null,
    'member_id'           => $_POST['member_id'] ?: null,
    'savings_account_id'  => $_POST['savings_account_id'] ?: null,
    'share_member_id'     => $_POST['share_member_id'] ?? null,        // ✓ ADDED
    'contra_share_member_id' => $_POST['contra_share_member_id'] ?? null, // ✓ ADDED
    'narration'           => trim($_POST['narration'] ?? ''),
    'amount'              => $_POST['amount'] ?? null,
], (int)Session::get('user_id'));
```

**Status:** Controller now forwards both share member ID fields to model ✓

---

### Model Layer (UNCHANGED ✓)
**File:** `app/models/InternalVoucherModel.php`
**Method:** `validateSharesSubledger()`

**Lines 150-158:**
```php
private function validateSharesSubledger(array $data, string $role, bool $isPrimary, string $label, float $shareValue): array
{
    $shareMemberIdKey = $isPrimary ? 'share_member_id' : 'contra_share_member_id';
    
    $shareMemberId = (int)($data[$shareMemberIdKey] ?? 0);
    if ($shareMemberId < 1 || !(new MemberModel())->find($shareMemberId)) {
        $debug = "DEBUG: Looking for key='$shareMemberIdKey', found value='$shareMemberId', isPrimary=" . ($isPrimary ? 'true' : 'false') . ", available keys=" . implode(',', array_keys($data));
        throw new InvalidArgumentException("Select a valid member for the $label shares account. [$debug]");
    }
    // ... rest of validation
}
```

**Status:** Model expects both fields and will now receive them correctly ✓

---

## Files Modified

**Total Files Modified:** 1

1. `app/controllers/InternalVoucherController.php` - Added 2 field mappings

**Files NOT Modified:**
- `app/models/InternalVoucherModel.php` - No changes
- `app/views/internal-vouchers/form.php` - No changes
- `app/models/ShareModel.php` - No changes
- `app/models/MemberShareAccountModel.php` - No changes
- `app/services/ShareTransferService.php` - No changes
- `app/services/JournalService.php` - No changes
- Any database schema files - No changes
- Any configuration files - No changes

---

## Database Changes

```
No database changes.
```

This fix is **code-only**. The database schema already supports the share member ID columns (added in prior migration `internal_voucher_fix_dual_subledger.sql`).

---

## Existing Financial Data

**Status:** ✓ UNTOUCHED

No existing financial records were modified during this fix:
- Existing Internal Vouchers (IV-000006, IV-000007, IV-000008, IV-000009) remain unchanged
- Existing journal entries (JE00029, JE00032, JE00033, JE00034, JE00035) remain unchanged
- Existing share transactions remain unchanged
- Existing share balances remain unchanged
- Existing savings balances remain unchanged
- GL account 3010 configuration remains unchanged
- Historical opening share transactions remain unchanged

This fix only affects **future voucher creation attempts**.

---

## Scope Limitations

This stage **ONLY** fixes the controller field mapping error.

### What This Fix Does:
✓ Allows `share_member_id` to reach the model validation layer  
✓ Allows `contra_share_member_id` to reach the model validation layer  
✓ Eliminates the "Select a valid member for the contra shares account" error caused by missing fields  
✓ Enables the model's existing shares validation and business logic to execute  

### What This Fix Does NOT Do:
❌ Does not expand Internal Voucher to support multi-line vouchers  
❌ Does not implement member-to-member share transfers (may or may not be supported by existing model logic)  
❌ Does not modify share transaction types  
❌ Does not alter accounting logic  
❌ Does not change share balance calculation  
❌ Does not guarantee that ALL shares scenarios are production-ready  

### Separate Questions Still Open:
- Whether dual subledger (different members) fully works end-to-end
- Whether share balance validation handles all edge cases
- Whether share redemption limits are enforced correctly
- Whether share purchase limits exist and are enforced
- Whether the UI correctly handles all shares scenarios

**These require separate testing beyond this controller fix.**

---

## Risk Assessment

**Risk Level:** ✅ MINIMAL

**Rationale:**
1. **Change is additive:** Only adds two nullable fields to existing data flow
2. **No existing behavior altered:** Non-share vouchers are unaffected (fields remain null)
3. **No database changes:** Schema already supports these columns
4. **No logic changes:** Model validation remains identical
5. **Follows existing pattern:** Uses same `?? null` syntax as other optional fields
6. **Preserves security:** CSRF validation unchanged, no new injection vectors
7. **Preserves accounting:** No GL account changes, no transaction manipulation

**Potential Issues:**
- If form JavaScript fails to populate hidden fields, null values pass to model (same as before)
- Model validation may reveal additional business rule issues previously masked by field mapping error

---

## Verdict

```
PASS WITH LIMITATIONS
```

### Explanation:

**PASS:**
- ✅ Controller fix implemented correctly
- ✅ Code follows existing patterns and style
- ✅ No unintended changes made
- ✅ No database or financial data modified
- ✅ Fix addresses root cause identified in forensic audit

**WITH LIMITATIONS:**
- ⏳ Manual testing required to verify full shares integration
- ⏳ Regression testing needed for existing voucher workflows
- ⏳ Business rule validation (share balance checks, transaction limits) not yet verified
- ⏳ Dual subledger scenario (different members) functionality unknown
- ⏳ Production readiness of entire shares integration still requires verification

### Next Steps Required:

1. **User must test in development environment:**
   - Create non-share voucher (regression test)
   - Create savings→shares voucher
   - Create shares→savings voucher
   - Verify error message changes from field mapping error to business rule validation

2. **If testing reveals additional issues:**
   - Document new issues separately
   - Determine if issues are business rules, model logic, or UI/UX
   - Create separate stages for additional fixes

3. **Only after successful development testing:**
   - Consider deployment to production
   - Monitor first production share voucher carefully
   - Verify GL reconciliation after first share transaction

---

## Evidence Summary

### Pre-Fix State:
```
Form: ✓ Has share_member_id and contra_share_member_id fields
Controller: ❌ Does not pass these fields to model
Model: ✓ Expects these fields for validation
Result: ❌ Validation fails with "missing field" error
```

### Post-Fix State:
```
Form: ✓ Has share_member_id and contra_share_member_id fields
Controller: ✓ Now passes these fields to model
Model: ✓ Expects these fields for validation
Result: ⏳ Fields reach validation (business rule checks now execute)
```

---

## Related Documentation

- **Forensic Audit:** `docs/audits/shares-internal-voucher-integration-audit.md`
- **Database Migration:** `database/internal_voucher_fix_dual_subledger.sql`
- **Model Implementation:** `app/models/InternalVoucherModel.php` (lines 397-580 for shares logic)
- **Share Architecture:** `database/shares_module_stage1.sql`

---

**Fix Completed:** September 18, 2026  
**Implementation Time:** < 5 minutes (2 lines of code)  
**Complexity:** Minimal - Simple field mapping addition  
**Production Risk:** Low - Additive change, nullable fields  
**Testing Status:** Awaiting user verification in development environment
