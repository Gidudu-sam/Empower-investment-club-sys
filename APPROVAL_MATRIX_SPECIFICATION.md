# Empower Investment Club — Approval Matrix Specification
**Purpose:** Exact approval requirements for system implementation  
**Date:** 2026-09-14  
**Status:** For finalization before coding

---

## 🎯 Core Principles

### Separation of Duties
- **Approval authority ≠ Execution authority**
- **Treasurer = execution role** (disburses, posts accounting)
- **Chairman/Vice/Secretary = oversight roles** (approve, don't execute)

### Maker-Checker
- Maker cannot approve own transaction
- Each approver reviews independently
- Parallel approval (all notified simultaneously)

### Rejection Rule
- **Any required approver rejects → Entire transaction rejected**
- Previous approvals preserved for audit
- Returns to DRAFT status

---

## 1️⃣ LOANS — Approval Matrix

### Tier 1: Small Loans (< UGX 1,000,000)

**Required Approvals:** 1

**Who Can Approve:**
- Chairman OR
- Vice Chairman

**Logic:**
```
ANY 1 of the following approves → Transaction approved
├─ Chairman approves → APPROVED ✅
└─ Vice Chairman approves → APPROVED ✅
```

**Who Can:**
- **Record loan:** Loans Officer, Admin, Treasurer
- **Submit for approval:** Same person who recorded
- **Approve:** Chairman OR Vice Chairman (only 1 needed)
- **Disburse:** Loans Officer, Admin, Treasurer (after approval)

**Special Cases:**
- If borrower is Chairman → Vice Chairman must approve
- If borrower is Vice Chairman → Chairman must approve
- If borrower is any other officer → escalate to Tier 4 (see below)

---

### Tier 2: Medium Loans (UGX 1M – 5M)

**Required Approvals:** 2

**Who Must Approve:**
- Chairman (required) AND
- Vice Chairman OR Secretary (one of them required)

**Logic:**
```
Chairman must approve + 1 other → Transaction approved

Valid combinations:
├─ Chairman + Vice Chairman → APPROVED ✅
└─ Chairman + Secretary → APPROVED ✅

Invalid combinations:
└─ Vice Chairman + Secretary (without Chairman) → NOT sufficient ❌
```

**Who Can:**
- **Record:** Loans Officer, Admin, Treasurer
- **Submit:** Same person who recorded
- **Approve:** 
  - Chairman (required — must always approve)
  - Vice Chairman (can be 2nd approver)
  - Secretary (can be 2nd approver)
- **Disburse:** Loans Officer, Admin, Treasurer (after 2 approvals)

**Approval Dashboard Shows:**
```
LOAN LNS-001234 — UGX 3,500,000

Required: 2 approvals
├─ Chairman:      APPROVED ✓  (2026-09-14 10:30)
├─ Vice Chairman: PENDING ⏳
└─ Secretary:     PENDING ⏳

Status: PENDING (1/2 approvals)
Waiting for: Vice Chairman OR Secretary
Disbursement: LOCKED 🔒

When Vice Chairman approves:
Status: APPROVED (2/2 approvals) ✅
Disbursement: UNLOCKED 🔓
```

**Special Cases:**
- If borrower is officer → escalate to Tier 4

---

### Tier 3: Large Loans (UGX 5M – 10M)

**Required Approvals:** 3

**Who Must Approve:**
- Chairman (required) AND
- Vice Chairman (required) AND
- Secretary (required)

**Logic:**
```
ALL 3 must approve → Transaction approved

Chairman + Vice Chairman + Secretary → APPROVED ✅

Any missing → NOT sufficient:
├─ Chairman + Vice Chairman only → NOT sufficient ❌
├─ Chairman + Secretary only → NOT sufficient ❌
└─ Vice Chairman + Secretary only → NOT sufficient ❌
```

**Who Can:**
- **Record:** Loans Officer, Admin, Treasurer
- **Submit:** Same person who recorded
- **Approve:** Chairman AND Vice Chairman AND Secretary (all 3 required)
- **Disburse:** Loans Officer, Admin, Treasurer (after 3 approvals)

**Approval Dashboard Shows:**
```
LOAN LNS-001235 — UGX 7,000,000

Required: 3 approvals
├─ Chairman:      APPROVED ✓  (2026-09-14 10:30)
├─ Vice Chairman: APPROVED ✓  (2026-09-14 11:15)
└─ Secretary:     PENDING ⏳

Status: PENDING (2/3 approvals)
Waiting for: Secretary
Disbursement: LOCKED 🔒

When Secretary approves:
Status: APPROVED (3/3 approvals) ✅
Disbursement: UNLOCKED 🔓
```

**Special Cases:**
- If borrower is officer → escalate to Tier 4

---

### Tier 4: Exceptional Loans (> UGX 10M OR Officer Loans)

**Required Approvals:** 4 (Full Committee)

**Who Must Approve:**
- Chairman (required) AND
- Vice Chairman (required) AND
- Secretary (required) AND
- Treasurer (required)

**Exception:** If borrower is an officer, exclude that officer from approval pool

**Logic:**
```
Regular Tier 4 (non-officer borrower):
ALL 4 must approve → Chairman + Vice + Secretary + Treasurer → APPROVED ✅

Officer loan (borrower = Chairman):
ALL 3 must approve → Vice + Secretary + Treasurer → APPROVED ✅
(Chairman excluded - conflict of interest)

Officer loan (borrower = Vice Chairman):
ALL 3 must approve → Chairman + Secretary + Treasurer → APPROVED ✅
(Vice Chairman excluded)

Officer loan (borrower = Secretary):
ALL 3 must approve → Chairman + Vice + Treasurer → APPROVED ✅
(Secretary excluded)

Officer loan (borrower = Treasurer):
ALL 3 must approve → Chairman + Vice + Secretary → APPROVED ✅
(Treasurer excluded)
```

**Who Can:**
- **Record:** Loans Officer, Admin, Treasurer
- **Submit:** Same person who recorded
- **Approve:** 
  - If non-officer borrower: All 4 officers
  - If officer borrower: All officers EXCEPT the borrower
- **Disburse:** Loans Officer, Admin, Treasurer (after all required approvals)

**System Detection:**
```
When loan is created:
├─ System checks: Is borrower a user with role in [admin, chairman, vice_chairman, secretary, treasurer]?
├─ If YES → Flag: OFFICER LOAN — TIER 4 REQUIRED
└─ If NO → Check amount for tier
```

**Approval Dashboard Shows:**
```
LOAN LNS-001236 — UGX 15,000,000

Required: 4 approvals (EXCEPTIONAL AMOUNT)
├─ Chairman:      APPROVED ✓  (2026-09-14 10:30)
├─ Vice Chairman: APPROVED ✓  (2026-09-14 11:15)
├─ Secretary:     APPROVED ✓  (2026-09-14 14:00)
└─ Treasurer:     PENDING ⏳

Status: PENDING (3/4 approvals)
Waiting for: Treasurer
Disbursement: LOCKED 🔒

OR (officer loan example):

LOAN LNS-001237 — UGX 2,000,000 — OFFICER LOAN
Borrower: Vice Chairman

Required: 3 approvals (OFFICER LOAN — Vice Chairman excluded)
├─ Chairman:      APPROVED ✓  (2026-09-14 10:30)
├─ Vice Chairman: EXCLUDED 🚫 (borrower - conflict of interest)
├─ Secretary:     APPROVED ✓  (2026-09-14 11:15)
└─ Treasurer:     PENDING ⏳

Status: PENDING (2/3 approvals)
Waiting for: Treasurer
Disbursement: LOCKED 🔒
```

---

## 2️⃣ INTERNAL VOUCHERS — Approval Matrix

**Your Choice:** C — Dual approval minimum (Chairman + Vice Chairman for ALL vouchers)

### All Internal Vouchers (Any Amount)

**Required Approvals:** 2

**Who Must Approve:**
- Chairman (required) AND
- Vice Chairman (required)

**Logic:**
```
BOTH must approve → Transaction approved

Chairman + Vice Chairman → APPROVED ✅

Either missing → NOT sufficient:
├─ Chairman only → NOT sufficient ❌
└─ Vice Chairman only → NOT sufficient ❌

Secretary/Treasurer cannot substitute:
└─ Chairman + Secretary → NOT sufficient ❌
└─ Chairman + Treasurer → NOT sufficient ❌
```

**Who Can:**
- **Create voucher:** Admin, Treasurer
- **Submit:** Same person who created
- **Approve:** Chairman AND Vice Chairman (both required, no substitutes)
- **Post to GL:** Admin, Treasurer (after 2 approvals)

**Why This Rule:**
- Internal vouchers directly manipulate GL (highest risk)
- Require top 2 officers to review (Chairman + Deputy)
- Cannot delegate to Secretary/Treasurer (they may be the makers)

**Approval Dashboard Shows:**
```
VOUCHER VCH-000123 — UGX 1,500,000

Required: 2 approvals (Chairman + Vice Chairman mandatory)
├─ Chairman:      APPROVED ✓  (2026-09-14 10:30)
└─ Vice Chairman: PENDING ⏳

Status: PENDING (1/2 approvals)
Waiting for: Vice Chairman (mandatory)
Posting: LOCKED 🔒

Secretary/Treasurer cannot substitute for Vice Chairman.
```

**Special Cases:**
- If voucher >UGX 5,000,000 → Consider requiring Tier 4 (committee)?
- **QUESTION FOR YOU:** Should very large vouchers (>5M) require all 4 officers?

---

## 3️⃣ INVESTMENTS — Approval Matrix

**Your Choice:** B — Higher thresholds than loans

**I need you to specify:**

### Option 3A: My Recommendation

| Investment Amount | Approvers Required | Who Approves |
|------------------|-------------------|--------------|
| **< UGX 3,000,000** | 1 | Chairman OR Vice Chairman |
| **UGX 3M – 10M** | 2 | Chairman + (Vice Chairman OR Secretary) |
| **UGX 10M – 20M** | 3 | Chairman + Vice Chairman + Secretary |
| **> UGX 20M** | 4 (Committee) | Chairman + Vice Chairman + Secretary + Treasurer |

**Rationale:** Investments are less frequent, larger amounts, longer commitments than loans

### Option 3B: Same as Loans

| Investment Amount | Approvers Required | Who Approves |
|------------------|-------------------|--------------|
| **< UGX 1,000,000** | 1 | Chairman OR Vice Chairman |
| **UGX 1M – 5M** | 2 | Chairman + (Vice Chairman OR Secretary) |
| **UGX 5M – 10M** | 3 | Chairman + Vice Chairman + Secretary |
| **> UGX 10M** | 4 (Committee) | Chairman + Vice Chairman + Secretary + Treasurer |

**Rationale:** Simpler — same rules for loans and investments

### Option 3C: Custom

**You specify the thresholds:**
- Tier 1: < UGX ________ → 1 approver
- Tier 2: UGX ________ to ________ → 2 approvers
- Tier 3: UGX ________ to ________ → 3 approvers
- Tier 4: > UGX ________ → 4 approvers

**YOUR CHOICE (3A, 3B, or 3C with amounts):** ___________

---

## 4️⃣ MEMBER ACCOUNT ADJUSTMENTS — Approval Matrix

**These directly change member balances (corrections/adjustments)**

### My Recommendation:

| Adjustment Amount | Approvers Required | Who Approves |
|------------------|-------------------|--------------|
| **< UGX 500,000** | 2 | Chairman + Vice Chairman |
| **UGX 500K – 2M** | 2 | Chairman + Vice Chairman |
| **> UGX 2M** | 3 | Chairman + Vice Chairman + Secretary |

**Rationale:** 
- Always require at least 2 approvers (high fraud risk)
- Large adjustments need 3 approvers

**Who Can:**
- **Create:** Admin, Treasurer
- **Approve:** As per tier above
- **Post:** Admin, Treasurer (after approval)

**YOUR APPROVAL (or specify different thresholds):** ___________

---

## 5️⃣ OPENING BALANCE BATCHES — Approval Matrix

**These set the financial year starting point (one-time critical entries)**

### My Recommendation:

**All Opening Balance Batches:**
- **Required Approvals:** 2
- **Who Must Approve:** Chairman AND Vice Chairman

**Rationale:**
- Critical one-time entry (affects all future reports)
- Not amount-based (always important regardless of size)
- Dual approval minimum

**Alternative:** Require 3 or 4 approvers for batches >UGX 50M total?

**YOUR APPROVAL (or specify different rule):** ___________

---

## 6️⃣ LARGE SAVINGS WITHDRAWALS — Approval Matrix

**Currently, all withdrawals post immediately (no approval)**

### Question: Should large withdrawals require approval?

**Option A: No approval for any withdrawals** (current system)
- Rationale: Member's own money, Treasurer trusted

**Option B: Require approval for large withdrawals**
| Withdrawal Amount | Approvers Required | Who Approves |
|------------------|-------------------|--------------|
| **< UGX 1,000,000** | 0 (direct) | — |
| **UGX 1M – 3M** | 1 | Chairman OR Vice Chairman |
| **> UGX 3M** | 2 | Chairman + Vice Chairman |

- Rationale: Large cash movements should have oversight

**YOUR CHOICE (A or B):** ___________

---

## 7️⃣ FIXED DEPOSIT / SAVINGS ACCOUNT CLOSURES

**Currently: Admin or Treasurer can approve**

### My Recommendation: Keep current system
- **Required Approvals:** 1
- **Who Can Approve:** Admin OR Treasurer (separate from maker)

**Rationale:**
- Operational decision
- Member's own funds
- Low fraud risk

**YOUR APPROVAL (or specify change):** ___________

---

## 8️⃣ LOAN APPLICATIONS (Pre-Approval)

**These are NOT actual loans yet — just applications**

### My Recommendation: Keep current system
- **Required Approvals:** 1
- **Who Can Approve:** Chairman OR Vice Chairman OR Secretary

**Rationale:**
- No disbursement yet (low risk)
- Operational screening
- Actual loan will require proper approval when created

**YOUR APPROVAL (or specify change):** ___________

---

## 📊 FINALIZED APPROVAL POLICY

### ✅ All Decisions Made — Ready for Implementation

---

## LOANS

| Amount | Approvers Required | Who Approves |
|--------|-------------------|--------------|
| **< UGX 1M** | 1 | Chairman OR Vice Chairman |
| **UGX 1M – 5M** | 2 | Chairman + (Vice Chairman OR Secretary) |
| **UGX 5M – 10M** | 3 | Chairman + Vice Chairman + Secretary |
| **> UGX 10M** | 4 | Chairman + Vice Chairman + Secretary + Treasurer |

**Special Rule:** Officer loans (any amount) → 4 approvers, excluding the recipient

---

## INVESTMENTS

| Amount | Approvers Required | Who Approves |
|--------|-------------------|--------------|
| **< UGX 3M** | 1 | Chairman OR Vice Chairman |
| **UGX 3M – <10M** | 2 | Chairman + Vice Chairman |
| **UGX 10M – <20M** | 3 | Chairman + Vice Chairman + Secretary |
| **≥ UGX 20M** | 4 | Chairman + Vice Chairman + Secretary + Treasurer |

---

## INTERNAL VOUCHERS

| Amount | Approvers Required | Who Approves |
|--------|-------------------|--------------|
| **≤ UGX 5M** | 2 | Chairman + Vice Chairman (both mandatory) |
| **> UGX 5M** | 4 | Chairman + Vice Chairman + Secretary + Treasurer |

**Rationale:** Direct GL manipulation = highest risk, always requires dual minimum

---

## MEMBER ACCOUNT ADJUSTMENTS

| Amount | Approvers Required | Who Approves |
|--------|-------------------|--------------|
| **≤ UGX 2M** | 2 | Chairman + Vice Chairman |
| **> UGX 2M** | 3 | Chairman + Vice Chairman + Secretary |

**Rationale:** Always minimum 2 approvers (directly alters member balances = high fraud risk)

---

## OPENING BALANCES

**All batches (regardless of amount):**
- **Approvers Required:** 2
- **Who Approves:** Chairman + Vice Chairman

**Rationale:** One-time critical entry, affects all future reports

---

## SAVINGS WITHDRAWALS

| Amount | Approvers Required | Who Approves |
|--------|-------------------|--------------|
| **≤ UGX 1M** | 0 | Treasurer posts directly (operational) |
| **> UGX 1M** | 2 | Chairman + Vice Chairman (before posting) |

**Rationale:** Large cash movements need oversight

---

## ACCOUNT CLOSURES (Fixed Deposit / Savings)

**All closures (regardless of account balance):**
- **Approvers Required:** 2
- **Who Approves:** Chairman + Vice Chairman

**Rationale:** Account closure is significant lifecycle change, shouldn't be single-person decision

**Note:** Closure does NOT delete history, only transitions status to `closed`

---

## LOAN APPLICATIONS

**Change:** No separate final approval rule

**New Workflow:**
```
1. Loans Officer assesses application
   └─ Reviews eligibility, documents, creditworthiness
   └─ Recommends amount and terms
   └─ Status: Assessment Complete

2. If recommendation approved, convert to actual loan
   └─ Loan creation follows standard loan approval matrix
   └─ Based on recommended amount:
      - <1M → 1 approver
      - 1M-5M → 2 approvers
      - 5M-10M → 3 approvers
      - >10M → 4 approvers

3. After loan approval, disburse
```

**Rationale:** Avoids competing approval systems, maintains clean separation between assessment and financial approval

---

## 🔒 UNIVERSAL APPROVAL RULES

### Rule 1: Maker-Checker Separation
**The maker cannot approve their own transaction.**

### Rule 2: Conflict of Interest
**The borrower/recipient cannot approve their own loan/transaction, regardless of role.**

### Rule 3: No Double-Approval
**The same person cannot provide multiple approvals for the same transaction.**
(E.g., Chairman cannot approve twice even if 2 approvals needed)

### Rule 4: Approval Invalidation
**Changing an approved transaction invalidates all approvals and sends it back through the approval process.**

### Rule 5: Posting Lock
**No financial posting/disbursement may occur until ALL required approvals are complete.**

This keeps approval system compatible with existing `JournalService` accounting controls.

### Rule 6: Rejection is Immediate
**If ANY required approver rejects, the entire transaction is immediately rejected.**
- Previous approvals preserved for audit trail
- Transaction returns to DRAFT status
- Clear rejection reason required
- Maker can re-edit and resubmit

### Rule 7: Approval Immutability
**Once given, approval cannot be withdrawn.**
- Only way to undo: Create reversal transaction (which itself requires approval)
- Full audit trail preserved

---

## 📊 Complete Summary Matrix

| Transaction Type | ≤ Low Tier | Mid Tier | High Tier | Exceptional |
|-----------------|-----------|----------|-----------|-------------|
| **Loans** | <1M: 1 | 1M-5M: 2 | 5M-10M: 3 | >10M: 4 |
| **Investments** | <3M: 1 | 3M-10M: 2 | 10M-20M: 3 | ≥20M: 4 |
| **Vouchers** | ≤5M: 2 | — | — | >5M: 4 |
| **Adjustments** | ≤2M: 2 | — | >2M: 3 | — |
| **Opening Balances** | All: 2 | — | — | — |
| **Withdrawals** | ≤1M: 0 | >1M: 2 | — | — |
| **Closures** | All: 2 | — | — | — |

**Officer Loans:** Always 4 approvers (excluding recipient), regardless of amount

---

## ✅ POLICY STATUS

**Document Version:** FINAL 1.0  
**Last Updated:** 2026-09-14  
**Status:** APPROVED — Ready for architecture audit and implementation  
**Approved By:** Management decision based on risk-based governance principles

---

## 🚀 NEXT STEPS

**Phase 1: Architecture Audit** (DO NOT SKIP THIS!)
- Audit existing approval tables, columns, controllers
- Map current approval workflows
- Identify what can be extended vs what needs replacement
- Document existing approval logic patterns

**Phase 2: Implementation Plan**
- Design database schema changes
- Design controller/model changes
- Design UI changes (approval dashboard, status displays)
- Plan notification system

**Phase 3: Implementation**
- Database migrations
- Code changes
- UI updates
- Testing

**Phase 4: Testing & Rollout**
- Test all approval tiers
- Test rejection scenarios
- Test officer loan exclusion
- UAT with officers
- Production deployment
