# Phase B: Mystery Journal Investigation Report
**STRICT READ-ONLY FORENSIC INVESTIGATION**

---

## Executive Summary

**Investigation Date:** September 18, 2026  
**Investigator:** Kiro AI Agent  
**Investigation Type:** Strict Read-Only Forensic Audit  
**Status:** ⚠️ **CRITICAL DATABASE CORRUPTION DISCOVERED**

### Mystery Solved

The mystery 51,761,620 increase in GL 3010 was caused by **ORPHANED JOURNAL LINES** from a deleted journal entry. Someone posted the historical share recognition journal (JE00020) on September 16, 2026, then deleted it, leaving the journal lines orphaned in the database and still affecting GL balances.

---

## Timeline of Events

### Initial State (Forensic Audit)
- **Date:** Prior to September 18, 2026
- **GL 3010 Balance:** UGX 4,970,000.00
- **Composition:** 4 journal lines from Internal Vouchers IV-000006/007/008/009

### Mystery Journal Posted
- **Date:** September 16, 2026 at 15:13:03
- **Journal Entry:** JE00020 (ID: 20)
- **Transaction:** DR 3020 Retained Earnings / CR 3010 Shares
- **Amount:** UGX 51,761,620.00 (**WRONG AMOUNT - includes EMP0002 duplicate**)
- **Description:** "Opening Balance - Retained Earnings Converted to Shares FY2024-2025"
- **Created By:** Unknown (user ID not captured, journal entry deleted)

### Journal Entry Deleted
- **Date:** Between September 16-18, 2026
- **Action:** Journal entry JE00020 DELETED from `journal_entries` table
- **Result:** Journal LINES left orphaned in `journal_lines` table
- **Data Corruption:** Orphaned lines STILL affecting GL balances

### Phase A Verification
- **Date:** September 18, 2026 at 10:28:41
- **GL 3010 Balance:** UGX 56,731,620.00
- **Composition:** 4 voucher lines (4,970,000) + 1 orphaned line (51,761,620)
- **Status:** Mystery increase detected, Phase B investigation initiated

### Current State (Phase B)
- **Date:** September 18, 2026 at 10:36:43
- **GL 3010 Balance:** UGX 56,731,620.00 (unchanged)
- **Status:** Orphaned line identified, database corruption confirmed

---

## Orphaned Journal Line Details

### The Mystery Line (Line ID 42)

```
Line ID:             42
Journal Entry ID:    20 (DELETED - JE00020)
Account:             3010 - Shares (Share Capital)
Debit:               UGX 0.00
Credit:              UGX 51,761,620.00
Description:         Opening Balance - Retained Earnings Converted to Shares FY2024-2025
Created:             2026-09-16 15:13:03
Parent Entry Status: DELETED
```

### Companion Line (Line ID 41)

```
Line ID:             41
Journal Entry ID:    20 (DELETED - JE00020)
Account:             3020 - Retained Earnings
Debit:               UGX 51,761,620.00
Credit:              UGX 0.00
Description:         Opening Balance - Retained Earnings Converted to Shares FY2024-2025
Created:             2026-09-16 15:13:03
Parent Entry Status: DELETED
```

---

## Current GL 3010 Composition

| Source | Line Count | Debit | Credit | Net Effect |
|--------|------------|-------|--------|------------|
| **Valid Lines (Vouchers)** | 4 | 104,000 | 5,074,000 | +4,970,000 |
| Line 64 (IV-000006) | 1 | 0 | 104,000 | +104,000 |
| Line 70 (IV-000007) | 1 | 0 | 2,485,000 | +2,485,000 |
| Line 72 (IV-000008) | 1 | 0 | 2,485,000 | +2,485,000 |
| Line 73 (IV-000009) | 1 | 104,000 | 0 | -104,000 |
| **Orphaned Lines** | 1 | 0 | 51,761,620 | +51,761,620 |
| Line 42 (DELETED JE00020) | 1 | 0 | 51,761,620 | +51,761,620 |
| **TOTAL** | **5** | **104,000** | **56,835,620** | **56,731,620** |

---

## Database Corruption Scope

### Deleted Journal Entries
- **Count:** 27 journal entries deleted
- **Range:** JE00001 through JE00027 (IDs 1-20, plus 8 others)
- **Current Sequence:** Starts at JE00028 (ID: 1)

### Orphaned Journal Lines
- **Total Orphaned Lines:** 45 lines
- **Source:** 20 deleted journal entries (IDs 8-28)
- **Affected Accounts:** Multiple (Loans, Savings, Cash, Mobile Money, Interest, Fees, Expenses, Shares, Retained Earnings)

### Sample Deleted Journals (by Line Evidence)

| Deleted Entry ID | Lines | Description | Created | Impact |
|------------------|-------|-------------|---------|--------|
| 8 | 2 | REVERSAL DUE TO WRONG POSTING | 2026-09-15 19:55:21 | Loans/Savings (15 UGX) |
| 9 | 2 | REVERSAL | 2026-09-15 20:00:10 | Savings/Loans (15 UGX) |
| 10 | 2 | REVERSAL | 2026-09-15 20:05:07 | Interest/Savings (15 UGX) |
| 11 | 3 | Cash/Loans/Interest | 2026-09-16 10:17:34 | 303K cash/233K loans/70K interest |
| 12 | 2 | Loans to Members | 2026-09-16 14:20:49 | 10M loans/bank |
| 13 | 2 | Office Rent | 2026-09-16 14:31:15 | 200K rent expense |
| 14 | 2 | Income from T-Shirts | 2026-09-16 14:33:54 | 5M income |
| 15-19 | 2 each | Various fees/expenses | 2026-09-16 | Various amounts |
| **20** | **2** | **Historical Shares** | **2026-09-16 15:13:03** | **51.76M shares** |
| 21-23 | 2 each | Interest postings | 2026-09-16 | 542K each |
| 24-26 | 2 each | Reversals of 21-23 | 2026-09-16 15:51:09-26 | Reversal 542K each |
| 27 | 3 | Loan repayment | 2026-09-16 15:56:47 | 5.42M cash/4.17M principal/1.25M interest |
| 28 | 3 | Loan repayment | 2026-09-16 19:21:09 | 303K cash/233K principal/70K interest |

---

## Critical Issues Identified

### 1. Data Integrity Violation
**Issue:** Orphaned journal lines exist without parent journal entries  
**Impact:** GL balances include transactions from non-existent journals  
**Severity:** 🔴 **CRITICAL**  
**Status:** Database corruption confirmed

### 2. Unreliable GL Balances
**Issue:** All GL accounts may have orphaned lines affecting balances  
**Impact:** Cannot trust any GL balance until all orphaned lines are identified and removed  
**Severity:** 🔴 **CRITICAL**  
**Status:** Full GL reconciliation required

### 3. Audit Trail Broken
**Issue:** No journal entry metadata for orphaned lines (created_by, approval, etc.)  
**Impact:** Cannot determine who posted/deleted entries or why  
**Severity:** 🟡 **HIGH**  
**Status:** Metadata lost permanently

### 4. Wrong Amount Used
**Issue:** Historical share recognition used 51,761,620 instead of 51,485,620  
**Impact:** Includes EMP0002 duplicate (276,000 excess)  
**Severity:** 🟡 **HIGH**  
**Status:** Must be corrected

### 5. Referential Integrity Failed
**Issue:** Database allows deletion of journal entries without cascade delete of lines  
**Impact:** Orphaned foreign keys in journal_lines.journal_entry_id  
**Severity:** 🟡 **HIGH**  
**Status:** Database schema needs foreign key constraints

---

## The Correct Amount Should Have Been

```
Historical Share Transactions:     82 records = UGX 51,761,620.00
LESS: EMP0002 Duplicate (ID 8):     1 record = UGX   (276,000.00)
                                   ─────────────────────────────────
CORRECTED Historical Total:        81 records = UGX 51,485,620.00
```

**Posted Amount:**   UGX 51,761,620.00  
**Correct Amount:**  UGX 51,485,620.00  
**Excess:**          UGX    276,000.00 (EMP0002 duplicate)

---

## Affected Accounts Analysis

### Accounts with Potential Orphaned Lines

Based on deleted journal entry patterns, the following accounts may have orphaned lines:

| Account Code | Account Name | Risk Level |
|--------------|--------------|------------|
| 1110 | Cash at Hand | 🔴 HIGH (7+ orphaned lines) |
| 1120 | Mobile Money / Float | 🔴 HIGH (4+ orphaned lines) |
| 1140 | Bank Accounts | 🟡 MEDIUM (3 orphaned lines) |
| 1180 | Loans to Members | 🔴 HIGH (5+ orphaned lines) |
| 2020 | Members' Savings | 🔴 HIGH (6+ orphaned lines) |
| 3010 | Shares (Share Capital) | 🔴 **CONFIRMED** (1 orphaned line: 51.76M) |
| 3020 | Retained Earnings | 🔴 **CONFIRMED** (1 orphaned line: 51.76M) |
| 4035 | Loan Interest Income | 🔴 HIGH (8+ orphaned lines) |
| 4090 | Membership Fees | 🟡 MEDIUM (1 orphaned line) |
| 4100 | Loan Processing Fees | 🟡 MEDIUM (2 orphaned lines) |
| 4110 | Annual Subscription Fees | 🟡 MEDIUM (1 orphaned line) |
| 4150 | Income from Sales of T-Shirts | 🟡 MEDIUM (1 orphaned line) |
| 5090 | Office Rent | 🟡 MEDIUM (1 orphaned line) |
| 5100 | Electricity Bills | 🟡 MEDIUM (1 orphaned line) |

**Full audit required for ALL accounts**

---

## Recommended Actions

### Immediate Actions (CRITICAL)

1. **STOP All Financial Transactions**
   - Freeze all accounting operations until orphaned lines are cleaned
   - Reason: Cannot trust GL balances with orphaned data

2. **Full Database Backup**
   - Take complete backup BEFORE any cleanup
   - Preserve corrupted state for forensic analysis

3. **Identify All Orphaned Lines**
   - Query: `SELECT * FROM journal_lines WHERE journal_entry_id NOT IN (SELECT id FROM journal_entries)`
   - Document all 45 orphaned lines by account

4. **Communicate with Stakeholders**
   - Inform management of database corruption
   - Set expectations: significant cleanup required

### Cleanup Strategy (Phase C)

#### Option A: Delete All Orphaned Lines (RECOMMENDED)
**Approach:** Remove all 45 orphaned lines from database  
**Rationale:** These are ghost data from deleted journals with no audit trail  
**Impact:** GL balances will return to pre-corruption state  
**Pros:**
- Clean database state
- Restores data integrity
- Simple to execute

**Cons:**
- Loses any "intentional" work (like historical share recognition)
- Requires re-posting correct journals

**Steps:**
1. Backup database
2. Document all orphaned lines with full details
3. DELETE FROM journal_lines WHERE journal_entry_id NOT IN (SELECT id FROM journal_entries)
4. Verify GL balances return to expected state
5. Re-implement corrections properly:
   - Remove EMP0002 duplicate (share_transactions ID 8)
   - Post historical recognition: DR 3020 / CR 3010 for 51,485,620 (correct amount)

#### Option B: Reconstruct Deleted Journal Entries
**Approach:** Recreate journal_entries records for orphaned lines  
**Rationale:** Preserve the work, fix the structure  
**Impact:** Brings orphaned lines back under proper journal entries  
**Pros:**
- Preserves posted amounts
- Maintains transaction history

**Cons:**
- Complex reconstruction
- Missing metadata (created_by, approval workflow, etc.)
- Legitimizes corrupted data
- Still requires fixing wrong amount (51.76M → 51.485M)

**Not Recommended:** Too complex, legitimizes corruption

#### Option C: Hybrid Approach
**Approach:** Delete most orphaned lines, reconstruct ONLY the historical share entry  
**Rationale:** Most deletions were mistakes/reversals, but share recognition was intentional  
**Impact:** Moderate complexity  
**Pros:**
- Cleans up most corruption
- Preserves intentional work

**Cons:**
- Still requires fixing amount
- Selective cleanup adds complexity

**Verdict:** If historical share entry was intentional, Option C is viable

---

## Revised Correction Plan

### Original Plan (from Phase A)
1. Remove EMP0002 duplicate transaction (ID 8)
2. Post historical recognition: DR 3020 / CR 3010 for 51,485,620
3. Leave four vouchers untouched

### Revised Plan (After Phase B)

#### Step 1: Database Cleanup (NEW)
**Objective:** Remove all 45 orphaned journal lines  
**Method:** `DELETE FROM journal_lines WHERE journal_entry_id NOT IN (SELECT id FROM journal_entries)`  
**Impact:** GL 3010 will return from 56,731,620 to 4,970,000  
**Verification:** Confirm GL 3010 = 4,970,000 (4 voucher lines only)

#### Step 2: Remove EMP0002 Duplicate (UNCHANGED)
**Objective:** Delete duplicate share transaction  
**Method:** `DELETE FROM share_transactions WHERE id = 8`  
**Impact:** Historical share total changes from 51,761,620 to 51,485,620  
**Verification:** Confirm 81 transactions totaling 51,485,620

#### Step 3: Post Historical Recognition (UNCHANGED)
**Objective:** Recognize historical shares in GL  
**Method:** Post journal entry DR 3020 / CR 3010 for 51,485,620  
**Impact:** GL 3010 final balance = 4,970,000 + 51,485,620 = 56,455,620  
**Verification:** Confirm GL 3010 = 56,455,620 with 5 lines (4 vouchers + 1 historical)

#### Step 4: Four Vouchers (UNCHANGED)
**Action:** Leave untouched  
**Rationale:** Complex business scenarios requiring investigation

---

## Expected Final State

### GL 3010 (Shares - Share Capital)

| Source | Amount | Status |
|--------|--------|--------|
| IV-000006 | +104,000 | Keep (voucher) |
| IV-000007 | +2,485,000 | Keep (voucher) |
| IV-000008 | +2,485,000 | Keep (voucher) |
| IV-000009 | -104,000 | Keep (voucher) |
| **Subtotal (Vouchers)** | **4,970,000** | **Unchanged** |
| Historical Recognition | +51,485,620 | **New posting** (correct amount) |
| **FINAL GL 3010** | **56,455,620** | **Clean state** |

### GL 3020 (Retained Earnings)

| Source | Amount | Status |
|--------|--------|--------|
| Historical Recognition | -51,485,620 | **New posting** (correct amount) |
| **FINAL GL 3020** | **-51,485,620** | **Clean state** |

### Share Transactions Subledger

| Metric | Value | Status |
|--------|-------|--------|
| Total Transactions | 81 | EMP0002 duplicate removed |
| Total Members | 81 | One account per member |
| Total Amount | 51,485,620 | Correct amount |
| GL Linkage | 1 journal | Proper recognition posted |

---

## Database Schema Recommendations

### Add Foreign Key Constraint

**Current Issue:** journal_lines.journal_entry_id has no foreign key constraint  
**Result:** Orphaned lines possible when journal_entries are deleted

**Recommended Fix:**
```sql
ALTER TABLE journal_lines
ADD CONSTRAINT fk_journal_lines_entry
FOREIGN KEY (journal_entry_id) 
REFERENCES journal_entries(id)
ON DELETE CASCADE;
```

**Impact:** When a journal entry is deleted, all its lines are automatically deleted  
**Benefit:** Prevents orphaned lines

### Add Soft Delete Pattern

**Current Issue:** Hard deletes lose audit trail  
**Recommended Fix:** Add `deleted_at` column to journal_entries

```sql
ALTER TABLE journal_entries
ADD COLUMN deleted_at TIMESTAMP NULL,
ADD COLUMN deleted_by INT NULL;
```

**Impact:** Entries are marked deleted, not removed  
**Benefit:** Preserves audit trail, allows undelete

---

## Investigation Methodology

### Tools Used
- Custom PHP forensic scripts (temp_*.php)
- Direct SQL queries against production database
- Cross-reference between journal_entries and journal_lines tables
- Orphaned line detection via LEFT JOIN with NULL check

### Scripts Created
1. `temp_phase_b_investigation.php` - Initial mystery search (found incorrect data)
2. `temp_current_state_check.php` - Timeline and entry sequence analysis
3. `temp_find_mystery_line.php` - GL 3010 line enumeration
4. `temp_find_orphaned_lines.php` - **BREAKTHROUGH** - Orphaned line discovery

### Key SQL Query (Orphaned Line Detection)
```sql
SELECT 
    jl.id as line_id,
    jl.journal_entry_id,
    jl.account_id,
    a.code,
    a.name,
    jl.debit,
    jl.credit,
    jl.description,
    jl.created_at
FROM journal_lines jl
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
LEFT JOIN accounts a ON a.id = jl.account_id
WHERE je.id IS NULL
ORDER BY jl.id ASC
```

**This query revealed the 45 orphaned lines that solved the mystery.**

---

## Verification Results

### Production Write Confirmation
✅ **ZERO production writes performed during investigation**
- No INSERTs
- No UPDATEs
- No DELETEs
- No journal postings
- No schema changes

**Investigation maintained strict READ-ONLY protocol as required.**

---

## Conclusions

### Mystery Status
🟢 **SOLVED**

### Root Cause
Database corruption caused by deletion of journal entries without cascade delete of their journal lines, leaving 45 orphaned lines affecting multiple GL accounts.

### Specific to GL 3010
- Orphaned Line ID 42 from deleted journal entry JE00020 (ID 20)
- Amount: 51,761,620 (WRONG - includes EMP0002 duplicate)
- Posted: September 16, 2026 at 15:13:03
- Deleted: Between September 16-18, 2026
- Line remained in database, still affecting GL 3010

### Database State
🔴 **CORRUPTED** - 45 orphaned lines across multiple accounts

### GL Balances
⚠️ **UNRELIABLE** - Full reconciliation required

### Correction Plan
📋 **REVISED** - Must clean orphaned lines BEFORE implementing original corrections

---

## Next Steps

### Phase C: Database Cleanup & Correction Implementation

1. **Get Approval for Revised Plan**
   - Present findings to management
   - Get authorization for orphaned line cleanup
   - Confirm correction approach

2. **Execute Cleanup**
   - Backup database
   - Delete 45 orphaned lines
   - Verify GL balances return to pre-corruption state

3. **Execute Original Corrections**
   - Remove EMP0002 duplicate
   - Post historical recognition (correct amount: 51,485,620)
   - Leave four vouchers untouched

4. **Implement Database Protection**
   - Add foreign key constraint
   - Consider soft delete pattern
   - Update deletion procedures

5. **Full GL Reconciliation**
   - Verify all GL accounts
   - Reconcile subledgers to GL
   - Document final state

---

## Appendices

### Appendix A: All 45 Orphaned Lines

See investigation script output: `temp_find_orphaned_lines.php`

### Appendix B: GL 3010 Timeline

```
State 1 (Forensic Audit):
  Balance: 4,970,000
  Lines: 4 (all vouchers)

State 2 (JE00020 Posted - Sept 16, 15:13):
  Balance: 56,731,620
  Lines: 5 (4 vouchers + 1 historical)

State 3 (JE00020 Deleted - Sept 16-18):
  Balance: 56,731,620 (UNCHANGED - line orphaned)
  Lines: 5 (4 vouchers + 1 ORPHANED)

State 4 (Phase A Verification - Sept 18, 10:28):
  Balance: 56,731,620 (mystery detected)
  Lines: 5 (4 vouchers + 1 ORPHANED)

State 5 (Phase B Investigation - Sept 18, 10:36):
  Balance: 56,731,620 (mystery solved)
  Lines: 5 (4 vouchers + 1 ORPHANED)
  Status: ORPHANED LINE IDENTIFIED
```

### Appendix C: Investigation Scripts

All temporary investigation scripts to be deleted after Phase C:
- temp_share_gl_forensic_audit.php
- temp_historical_correction_verification.php
- temp_phase_b_investigation.php
- temp_current_state_check.php
- temp_find_mystery_line.php
- temp_find_orphaned_lines.php

---

**Report Generated:** September 18, 2026  
**Investigation Status:** ✅ COMPLETE  
**Next Phase:** Phase C - Database Cleanup & Correction Implementation  
**Authorization Required:** YES - for orphaned line deletion  

---

*This report documents a strict read-only forensic investigation. No production data was modified during this investigation.*
