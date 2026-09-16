# Empower Investment Club — Multi-Level Approval Policy Design
**Purpose:** Risk-based approval structure with separation of duties  
**Date:** 2026-09-14  
**Status:** DRAFT — For discussion and refinement

---

## 🎯 Core Governance Principles

### 1. **Separation of Duties**
- Approval authority ≠ Execution authority
- Treasurer handles disbursement/financial execution → should NOT routinely approve
- Chairman/Vice Chairman/Secretary provide independent oversight → approve but don't execute

### 2. **Risk-Based Approval**
- Higher financial risk = more approvers required
- Not "everyone approves everything" (operationally unworkable)
- Tiered approach scales with transaction value/risk

### 3. **Parallel Approval**
- All required approvers notified simultaneously
- No sequential waiting (Chairman → Vice → Secretary)
- Transaction proceeds when required number reached
- Any rejection = immediate overall rejection

### 4. **Independent Approval**
- Maker cannot approve their own transaction
- Each approver reviews independently
- No "group approval" meetings (individual accountability)

---

## 📊 Approval Tier Structure

### **TIER 0: No Approval (Routine Operations)**

**Philosophy:** Trusted operational roles, low financial risk, speed critical

**Transaction Types:**
- ✅ Savings deposits
- ✅ Savings withdrawals (<Shs 500,000)
- ✅ Loan repayments
- ✅ Fee collection (mark paid)
- ✅ Routine expenses (<Shs 200,000)
- ✅ Member registration
- ✅ Share purchases

**Who Can Execute:**
- Admin, Treasurer, Cashier, Loans Officer (depending on transaction)

**Accounting Impact:**
- Posts to GL immediately
- Full audit trail maintained

**Why No Approval:**
- Routine daily operations
- Low individual amounts
- Trusted roles only
- Speed needed for operations

---

### **TIER 1: Single Approval (Low Risk)**

**Philosophy:** Independent oversight, but speed still important

**Required Approvers:** 1 of the following:
- Chairman
- Vice Chairman

**Transaction Types:**

| Transaction | Amount Threshold | Maker | Approver |
|-------------|-----------------|-------|----------|
| **Small Loans** | <Shs 2,000,000 | Loans Officer | Chairman OR Vice Chairman |
| **Small Investments** | <Shs 3,000,000 | Treasurer | Chairman OR Vice Chairman |
| **Loan Applications** | Any amount | Loans Officer | Chairman OR Vice Chairman OR Secretary |
| **Small Internal Vouchers** | <Shs 500,000 | Treasurer | Chairman OR Vice Chairman |
| **Large Expenses** | Shs 200K - 500K | Treasurer | Chairman OR Vice Chairman |
| **Large Withdrawals** | Shs 500K - 1M | Cashier/Treasurer | Chairman OR Vice Chairman |
| **Account Closures** | Savings/FD | Treasurer | Admin OR Treasurer (separate from maker) |

**Workflow:**
```
1. Maker creates transaction (Status: DRAFT)
2. Maker submits (Status: PENDING APPROVAL)
3. System notifies Chairman + Vice Chairman
4. Either one approves → Status: APPROVED
5. Maker/authorized person can now execute (disburse/post)
```

**Rejection:**
- Either approver can reject
- Returns to DRAFT
- Maker can re-edit and resubmit

---

### **TIER 2: Dual Approval (Medium Risk)**

**Philosophy:** Two independent officers must agree

**Required Approvers:** 2 of the following (minimum):
- Chairman (required)
- Vice Chairman OR Secretary (one of them)

**Transaction Types:**

| Transaction | Amount Threshold | Maker | Approvers Required |
|-------------|-----------------|-------|-------------------|
| **Medium Loans** | Shs 2M - 5M | Loans Officer | Chairman + (Vice Chairman OR Secretary) |
| **Medium Investments** | Shs 3M - 10M | Treasurer | Chairman + (Vice Chairman OR Secretary) |
| **Medium Internal Vouchers** | Shs 500K - 2M | Treasurer | Chairman + Vice Chairman |
| **Large Withdrawals** | Shs 1M - 3M | Treasurer | Chairman + Vice Chairman |
| **Opening Balance Batches** | Any amount | Treasurer | Chairman + Vice Chairman |

**Workflow:**
```
1. Maker creates transaction (Status: DRAFT)
2. Maker submits (Status: PENDING APPROVAL)
3. System notifies: Chairman, Vice Chairman, Secretary
4. Dashboard shows:
   
   Required: 2 approvals
   Chairman:      PENDING ⏳
   Vice Chairman: PENDING ⏳
   Secretary:     PENDING ⏳
   
   Overall: PENDING APPROVAL
   Disbursement: LOCKED 🔒

5. Chairman approves:
   
   Required: 2 approvals
   Chairman:      APPROVED ✓
   Vice Chairman: PENDING ⏳
   Secretary:     PENDING ⏳
   
   Overall: PENDING (1/2 approvals)
   Disbursement: LOCKED 🔒

6. Vice Chairman approves:
   
   Required: 2 approvals
   Chairman:      APPROVED ✓
   Vice Chairman: APPROVED ✓
   Secretary:     (not required) —
   
   Overall: APPROVED — READY FOR DISBURSEMENT ✅
   Disbursement: UNLOCKED 🔓

7. Authorized person can now disburse/post
```

**Rejection:**
- If ANY required approver rejects → Status: REJECTED (entire transaction)
- Preserved data:
  - Who rejected
  - Rejection reason
  - Date/time
  - Previous approvals (for audit trail)
- Returns to DRAFT (maker can re-edit)

---

### **TIER 3: Triple Approval (High Risk)**

**Philosophy:** Three senior officers must independently agree

**Required Approvers:** All 3 of the following:
- Chairman (required)
- Vice Chairman (required)
- Secretary (required)

**Transaction Types:**

| Transaction | Amount Threshold | Maker | Approvers Required |
|-------------|-----------------|-------|--------------------|
| **Large Loans** | Shs 5M - 10M | Loans Officer | Chairman + Vice Chairman + Secretary |
| **Large Investments** | Shs 10M - 20M | Treasurer | Chairman + Vice Chairman + Secretary |
| **Large Internal Vouchers** | Shs 2M - 5M | Treasurer | Chairman + Vice Chairman + Secretary |
| **Large Withdrawals** | >Shs 3M | Treasurer | Chairman + Vice Chairman + Secretary |
| **Member Account Adjustments** | >Shs 1M | Treasurer | Chairman + Vice Chairman + Secretary |

**Workflow:**
```
1. Maker submits
2. System notifies all 3 required approvers
3. Each reviews independently (parallel, not sequential)
4. Dashboard shows real-time status:
   
   UGX 7,000,000 LOAN — MEMBER #1234
   
   Required: 3 approvals
   Chairman:      APPROVED ✓  (2026-09-14 10:30)
   Vice Chairman: APPROVED ✓  (2026-09-14 11:15)
   Secretary:     PENDING ⏳
   
   Overall: PENDING (2/3 approvals)
   Disbursement: LOCKED 🔒

5. When 3rd person approves:
   
   Overall: APPROVED — READY FOR DISBURSEMENT ✅
   Disbursement: UNLOCKED 🔓

6. Loans Officer can now disburse
```

**Rejection:**
- Any of the 3 rejects → Entire transaction rejected
- All previous approvals preserved (audit trail)
- Clear rejection reason required

---

### **TIER 4: Committee Approval (Exceptional Risk)**

**Philosophy:** Full oversight committee consensus required

**Required Approvers:** ALL 4 of the following:
- Chairman (required)
- Vice Chairman (required)
- Secretary (required)
- Treasurer (required)

**Transaction Types:**

| Transaction | Criteria | Maker | Approvers Required |
|-------------|----------|-------|-------------------|
| **Very Large Loans** | >Shs 10M | Loans Officer | All 4 officers |
| **Exceptional Investments** | >Shs 20M | Treasurer | All 4 officers |
| **Exceptional Vouchers** | >Shs 5M | Treasurer | All 4 officers |
| **Loans to Officers** | ANY amount | Loans Officer | All 4 officers (minus recipient) |
| **Related-Party Transactions** | ANY amount | Any | All 4 officers |
| **Opening Balance Batches** | >Shs 50M total | Treasurer | All 4 officers |
| **Member Adjustments** | >Shs 5M | Treasurer | All 4 officers |

**Special Rules:**

**Loans to Officers:**
```
If loan is to the Chairman:
└─ Vice Chairman, Secretary, Treasurer must approve (3/3)
   (Chairman excluded from approval)

If loan is to the Vice Chairman:
└─ Chairman, Secretary, Treasurer must approve (3/3)
   (Vice Chairman excluded)

Etc. for other officers.
```

**Workflow:**
```
1. Maker submits exceptional transaction
2. System flags: COMMITTEE APPROVAL REQUIRED
3. All 4 officers notified simultaneously
4. Dashboard shows:
   
   UGX 15,000,000 LOAN — EXCEPTIONAL AMOUNT
   
   Required: 4 approvals (FULL COMMITTEE)
   Chairman:      APPROVED ✓
   Vice Chairman: APPROVED ✓
   Secretary:     PENDING ⏳
   Treasurer:     PENDING ⏳
   
   Overall: PENDING (2/4 approvals)
   Disbursement: LOCKED 🔒

5. Transaction only proceeds when ALL 4 approve
```

**Rejection:**
- Any officer rejects → Transaction rejected
- Cannot be overridden (even by Chairman)

**Operational Note:**
- This is intentionally slow
- For exceptional/high-risk only
- If one officer unavailable → transaction waits
- This is by design (protects club from exceptional risks)

---

## 👥 Role Definitions in Approval Context

### **Chairman**
**Approval Authority:**
- Required approver for Tier 2, 3, 4
- Can be sole approver for Tier 1
- Cannot approve own transactions

**Execution Authority:**
- None (approval role only)

**Special Powers:**
- Can reject at any tier
- Approval carries highest weight

---

### **Vice Chairman**
**Approval Authority:**
- Required approver for Tier 2, 3, 4
- Can be sole approver for Tier 1
- Functionally equivalent to Chairman for approvals

**Execution Authority:**
- None (approval role only)

**Role:**
- Deputy approver
- Covers when Chairman unavailable

---

### **Secretary**
**Approval Authority:**
- Required approver for Tier 3, 4
- Alternative approver for Tier 2 (if Vice Chairman unavailable)
- Can approve Loan Applications (Tier 1)

**Execution Authority:**
- None (approval role only)

**Role:**
- Oversight role
- Operational decisions (loan applications, investments)

---

### **Treasurer**
**Approval Authority:**
- Required approver for Tier 4 only (committee approval)
- NOT routine approver for Tier 1, 2, 3

**Execution Authority:**
- Creates Internal Vouchers (Maker)
- Handles disbursements (after approval)
- Posts accounting entries
- Records expenses, income

**Role:**
- Financial execution and accounting
- Separated from approval authority (governance)

**Why Treasurer Doesn't Routinely Approve:**
```
❌ BAD (Treasurer approves loans):
   Treasurer approves loan
   → Treasurer disburses loan
   → Treasurer posts accounting
   → No separation of duties!

✅ GOOD (Current design):
   Chairman/Vice/Secretary approve loan
   → Treasurer disburses (execution only)
   → Separation maintained!
```

---

### **Loans Officer**
**Approval Authority:**
- None

**Execution Authority:**
- Creates loan records (Maker)
- Disburses approved loans
- Records repayments

**Role:**
- Operational role
- No approval authority (separation)

---

### **Admin / Office Admin / Cashier**
**Approval Authority:**
- None (or very limited)

**Execution Authority:**
- Routine transactions (deposits, withdrawals, fees)

**Role:**
- Daily operations
- Speed-critical tasks

---

## 🔢 Proposed Monetary Thresholds

**To Be Finalized After Discussion**

### Option A: Conservative (Lower Thresholds)

| Tier | Loans | Investments | Vouchers | Withdrawals |
|------|-------|-------------|----------|-------------|
| **Tier 1** | <1M | <2M | <300K | <500K |
| **Tier 2** | 1M-3M | 2M-5M | 300K-1M | 500K-2M |
| **Tier 3** | 3M-7M | 5M-15M | 1M-3M | >2M |
| **Tier 4** | >7M | >15M | >3M | — |

---

### Option B: Moderate (Medium Thresholds)

| Tier | Loans | Investments | Vouchers | Withdrawals |
|------|-------|-------------|----------|-------------|
| **Tier 1** | <2M | <3M | <500K | <1M |
| **Tier 2** | 2M-5M | 3M-10M | 500K-2M | 1M-3M |
| **Tier 3** | 5M-10M | 10M-20M | 2M-5M | >3M |
| **Tier 4** | >10M | >20M | >5M | — |

---

### Option C: Aggressive (Higher Thresholds)

| Tier | Loans | Investments | Vouchers | Withdrawals |
|------|-------|-------------|----------|-------------|
| **Tier 1** | <3M | <5M | <1M | <2M |
| **Tier 2** | 3M-8M | 5M-15M | 1M-3M | 2M-5M |
| **Tier 3** | 8M-15M | 15M-30M | 3M-7M | >5M |
| **Tier 4** | >15M | >30M | >7M | — |

---

### Factors to Consider When Choosing:

**1. Club Size**
- What's your typical loan size?
- What's your total asset base?
- How many loans per month?

**2. Operational Speed**
- How urgent are approvals typically?
- How available are officers?
- Can you tolerate 2-3 day approval times?

**3. Risk Tolerance**
- What amount feels "large" to your club?
- What loss amount would significantly harm the club?
- Historical loan default rates?

**4. Officer Availability**
- Are all officers usually available?
- Geographic spread?
- Work schedules?

---

## 🔄 Approval Workflow Examples

### Example 1: Small Loan (Tier 1)

**Scenario:** Member needs Shs 1,500,000 loan

```
Day 1 — 9:00 AM
├─ Loans Officer creates loan record
└─ Status: DRAFT

Day 1 — 10:00 AM
├─ Loans Officer reviews, submits for approval
└─ Status: PENDING APPROVAL
└─ System notifies: Chairman, Vice Chairman

Day 1 — 2:00 PM
├─ Chairman reviews on mobile
├─ Chairman approves
└─ Status: APPROVED — READY FOR DISBURSEMENT
└─ System notifies: Loans Officer

Day 1 — 3:00 PM
├─ Loans Officer disburses via MoMo
├─ Status: ACTIVE
└─ GL posts: Dr Loans Receivable / Cr MoMo Suspense

Total time: 6 hours ✅
```

---

### Example 2: Medium Loan (Tier 2)

**Scenario:** Member needs Shs 4,000,000 loan

```
Day 1 — 9:00 AM
├─ Loans Officer creates loan record
└─ Status: DRAFT

Day 1 — 10:00 AM
├─ Loans Officer submits
└─ Status: PENDING APPROVAL (0/2 approvals)
└─ System notifies: Chairman, Vice Chairman, Secretary

Dashboard shows:
   Required: 2 approvals
   Chairman:      PENDING ⏳
   Vice Chairman: PENDING ⏳
   Secretary:     PENDING ⏳

Day 1 — 11:00 AM
├─ Vice Chairman reviews, approves
└─ Status: PENDING APPROVAL (1/2 approvals)

Dashboard shows:
   Required: 2 approvals
   Chairman:      PENDING ⏳
   Vice Chairman: APPROVED ✓
   Secretary:     PENDING ⏳

Day 1 — 3:00 PM
├─ Chairman reviews, approves
└─ Status: APPROVED — READY FOR DISBURSEMENT (2/2 approvals)

Dashboard shows:
   Required: 2 approvals
   Chairman:      APPROVED ✓
   Vice Chairman: APPROVED ✓
   Secretary:     (not needed) —
   
   Overall: APPROVED ✅
   Disbursement: UNLOCKED 🔓

Day 1 — 4:00 PM
├─ Loans Officer disburses
└─ Status: ACTIVE

Total time: 7 hours ✅
```

---

### Example 3: Large Loan (Tier 3)

**Scenario:** Member needs Shs 7,000,000 loan

```
Day 1 — 9:00 AM
├─ Loans Officer submits
└─ Status: PENDING APPROVAL (0/3 approvals)
└─ System notifies: Chairman, Vice Chairman, Secretary

Dashboard:
   Required: 3 approvals
   Chairman:      PENDING ⏳
   Vice Chairman: PENDING ⏳
   Secretary:     PENDING ⏳

Day 1 — 10:30 AM
├─ Chairman approves
└─ Status: PENDING (1/3)

Day 1 — 11:15 AM
├─ Vice Chairman approves
└─ Status: PENDING (2/3)

Day 2 — 9:00 AM (Secretary was unavailable Day 1)
├─ Secretary reviews, approves
└─ Status: APPROVED — READY FOR DISBURSEMENT (3/3)

Day 2 — 10:00 AM
├─ Loans Officer disburses
└─ Status: ACTIVE

Total time: 25 hours (acceptable for large loan) ✅
```

---

### Example 4: Rejection Scenario

**Scenario:** Member needs Shs 4,000,000 loan (Tier 2)

```
Day 1 — 10:00 AM
├─ Loans Officer submits
└─ Status: PENDING (0/2)

Day 1 — 11:00 AM
├─ Vice Chairman reviews
├─ Notes: Member already has 2 active loans
├─ Vice Chairman REJECTS
├─ Reason: "Member overleveraged — existing loans not yet repaid"
└─ Status: REJECTED

Dashboard shows:
   Chairman:      PENDING ⏳ (didn't review yet)
   Vice Chairman: REJECTED ❌
   
   Overall: REJECTED ❌
   Rejection reason: "Member overleveraged..."
   Rejected by: Vice Chairman
   Rejected at: 2026-09-14 11:00 AM

System notifies Loans Officer:
   "Loan rejected by Vice Chairman. 
    Reason: Member overleveraged..."

Loans Officer options:
1. Review the concern
2. If valid → Close the application
3. If addressable → Edit loan (reduce amount?), resubmit
```

---

### Example 5: Loan to Officer (Tier 4)

**Scenario:** Vice Chairman needs Shs 2,000,000 loan

```
Special Rule: Vice Chairman excluded from approval process

Day 1 — 9:00 AM
├─ Loans Officer creates loan
├─ Borrower: Vice Chairman (officer)
└─ System flags: LOAN TO OFFICER — COMMITTEE APPROVAL REQUIRED

Day 1 — 10:00 AM
├─ Loans Officer submits
└─ Status: PENDING APPROVAL (0/3 — Vice Chairman excluded)
└─ System notifies: Chairman, Secretary, Treasurer
└─ Vice Chairman: Notified but CANNOT approve (conflict of interest)

Dashboard shows:
   Required: 3 approvals (officer loan — Vice Chairman excluded)
   Chairman:      PENDING ⏳
   Secretary:     PENDING ⏳
   Treasurer:     PENDING ⏳
   Vice Chairman: EXCLUDED (borrower) 🚫

Day 1-3 — Approvals
├─ Chairman approves (Day 1)
├─ Secretary approves (Day 2)
├─ Treasurer approves (Day 3)
└─ Status: APPROVED

Day 3 — Disbursement
├─ Loans Officer disburses
└─ Extra audit flag: "Officer loan — full committee approved"

Total time: 3 days (acceptable for officer loan) ✅
```

---

## 🔒 Security & Audit Features

### 1. **Approval Immutability**
- Once approved, approval CANNOT be withdrawn
- Only way to undo: Create reversal transaction (with approval)
- Full audit trail preserved

### 2. **Rejection Audit Trail**
```
Transaction record shows:
├─ Current status: REJECTED
├─ Rejection details:
│   ├─ Rejected by: Vice Chairman (User ID: 5)
│   ├─ Rejected at: 2026-09-14 11:00:32 AM
│   └─ Rejection reason: "Member overleveraged..."
├─ Previous approval attempts:
│   └─ Chairman: PENDING (never reviewed)
└─ Submission history:
    ├─ First submission: 2026-09-14 10:00 AM
    └─ Re-submission: (if re-edited)
```

### 3. **Conflict of Interest Detection**
```
System automatically detects:
├─ Maker attempting to approve own transaction → BLOCKED
├─ Borrower attempting to approve own loan → BLOCKED
├─ Related party transaction → FLAGS for committee approval
```

### 4. **Approval Dashboard**
All officers see real-time approval queue:
```
MY APPROVAL QUEUE

PENDING MY APPROVAL (3)
├─ Loan LNS-001245 — Shs 4,000,000 — (1/2 approvals) ⏳
│   └─ Vice Chairman approved 2 hours ago
├─ Investment INV-00023 — Shs 8,000,000 — (0/2 approvals) ⏳
│   └─ Submitted 1 day ago
└─ Voucher VCH-000567 — Shs 1,500,000 — (1/3 approvals) ⏳
    └─ Chairman, Vice Chairman approved

WAITING ON OTHERS (1)
└─ Loan LNS-001246 — Shs 7,000,000 — (1/3 approvals) ⏳
    └─ I approved, waiting on Secretary

RECENTLY APPROVED (5)
└─ (transactions I approved in last 7 days)

RECENTLY REJECTED (2)
└─ (transactions I rejected in last 7 days)
```

### 5. **Notification System**
```
When transaction submitted:
└─ Email + SMS to all required approvers
    "Loan LNS-001245 (Shs 4M) needs your approval. 
     View: https://empower.club/approvals/12345"

When approval received:
└─ Email + SMS to other pending approvers
    "Chairman approved Loan LNS-001245. 
     1 more approval needed."

When fully approved:
└─ Email + SMS to maker
    "Your loan LNS-001245 is approved. Ready for disbursement."

When rejected:
└─ Email + SMS to maker
    "Loan LNS-001245 rejected by Vice Chairman. 
     Reason: [reason]"
```

---

## 🛠️ Implementation Phases

### **Phase 1: Immediate (Manual Process)**
**Timeline:** Today

**What:**
- Document policy (this document)
- Implement manual email-based multi-approval for high-value items
- Train officers on policy

**Process:**
```
For Tier 2+ transactions:
1. Maker creates in system (Draft)
2. Maker emails required approvers with details
3. Each approver replies "APPROVED" or "REJECTED" via email
4. Once all approvals received, maker submits in system
5. Single approver (typically Chairman) clicks "Approve" in system
6. Email thread preserved as audit trail
```

**Pros:**
- ✅ Implement immediately
- ✅ No code changes
- ✅ Policy established

**Cons:**
- ❌ Manual process
- ❌ Not enforced by system

---

### **Phase 2: System Implementation (Multi-Approval Feature)**
**Timeline:** 3-6 months (after production launch stabilizes)

**Database Changes:**
```sql
-- New table: approval_requirements
CREATE TABLE approval_requirements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_type ENUM('loan','investment','voucher','withdrawal','adjustment'),
    tier INT, -- 1, 2, 3, 4
    min_amount DECIMAL(15,2),
    max_amount DECIMAL(15,2),
    approvals_required INT,
    required_roles JSON, -- ['chairman','vice_chairman','secretary']
    created_at TIMESTAMP
);

-- New table: transaction_approvals
CREATE TABLE transaction_approvals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_type VARCHAR(50),
    transaction_id INT,
    required_approvals INT,
    current_approvals INT DEFAULT 0,
    approval_status ENUM('pending','approved','rejected'),
    submitted_at TIMESTAMP,
    completed_at TIMESTAMP NULL
);

-- New table: approval_actions
CREATE TABLE approval_actions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_approval_id INT,
    user_id INT,
    action ENUM('approved','rejected'),
    action_reason TEXT,
    created_at TIMESTAMP,
    FOREIGN KEY (transaction_approval_id) REFERENCES transaction_approvals(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

**UI Changes:**
1. **Approval Dashboard** (new page for officers)
2. **Multi-approval status widget** (shows 1/3, 2/3, etc.)
3. **Approval history** (who approved when)
4. **Real-time notifications**

**Logic Changes:**
1. Determine tier based on transaction type + amount
2. Check required approvals for tier
3. Track individual approval actions
4. Unlock execution only when threshold reached
5. Handle rejection (immediately mark as rejected)

**Testing:**
- Test all tier transitions
- Test rejection scenarios
- Test officer loan exclusion
- Test notification system
- Load testing (multiple concurrent approvals)

---

### **Phase 3: Refinement (After 3-6 Months Live)**
**Timeline:** 6-12 months

**What:**
- Review thresholds based on actual usage
- Adjust tiers if needed
- Add approval analytics
- Implement approval reminders (auto-escalation)

---

## 📋 Discussion Questions to Finalize

### 1. **Monetary Thresholds**
Which option fits your club best?
- [ ] Option A (Conservative: Lower thresholds, more oversight)
- [ ] Option B (Moderate: Balanced approach)
- [ ] Option C (Aggressive: Higher thresholds, more speed)
- [ ] Custom (specify amounts)

### 2. **Opening Balances**
Should opening balances always be Tier 2 (dual approval)?
- [ ] Yes — always Chairman + Vice Chairman
- [ ] No — depends on total amount (Tier 2 vs Tier 4)

### 3. **Internal Vouchers**
Current system: Chairman-only approval (most restrictive)
- [ ] Keep chairman-only for all vouchers
- [ ] Use tiered approach (amount-based)
- [ ] Require dual approval for all vouchers regardless of amount

### 4. **Savings Withdrawals**
Should large withdrawals require approval?
- [ ] Yes — use tiered approach (>1M needs approval)
- [ ] No — Treasurer can process all withdrawals (current system)

### 5. **Investment Returns**
When investment matures and returns principal + interest:
- [ ] No approval needed (routine accounting)
- [ ] Same approval as original investment (symmetry)

### 6. **Loan Restructuring**
If loan terms are changed (extend period, adjust interest):
- [ ] Same approval tier as original loan
- [ ] Always Tier 3 (high risk — governance oversight)
- [ ] Tier 2 minimum

### 7. **Emergency Transactions**
For urgent situations (member medical emergency, etc.):
- [ ] No shortcuts — follow normal approval process
- [ ] Chairman can override (fast-track approval with documentation)
- [ ] Approve first, review later (with mandatory post-review)

---

## ✅ Next Steps

1. **Finalize thresholds** — Choose Option A/B/C or specify custom amounts
2. **Answer discussion questions** — Clarify edge cases
3. **Create formal policy document** — Board resolution
4. **Implement Phase 1** — Manual email process (immediate)
5. **Plan Phase 2** — Budget for development, timeline
6. **Train officers** — Policy, workflow, responsibilities

---

**Document Version:** DRAFT 1.0  
**Last Updated:** 2026-09-14  
**Status:** For discussion and approval
