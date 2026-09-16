# Empower Investment Club — Approval Workflows Guide
**Purpose:** Understanding what requires approval and who can approve  
**Date:** 2026-09-14  
**Audience:** All users (understand the workflows)

---

## 📋 Quick Summary: What Requires Approval?

| Module | Requires Approval? | Who Approves? | Status Flow |
|--------|-------------------|---------------|-------------|
| **Loans** | ✅ YES | Chairman / Vice Chairman | Draft → Pending → **Approved** → Disbursed |
| **Loan Applications** | ✅ YES | Chairman / Vice Chairman / Secretary | Draft → Pending → **Approved** → Convert to Loan |
| **Internal Vouchers** | ✅ YES | Chairman only | Draft → Pending → **Approved** → Post |
| **Investments** | ✅ YES | Chairman / Vice Chairman / Secretary | Draft → Pending → **Approved** → Post |
| **Opening Balances** | ✅ YES | Chairman / Vice Chairman | Draft → Pending → **Approved** → Post |
| **Member Account Adjustments** | ✅ YES | Chairman / Vice Chairman | Draft → Pending → **Approved** → Post |
| **Fixed Deposit Closures** | ✅ YES | Admin / Treasurer | Requested → **Approved** → Payout |
| **Savings Account Closures** | ✅ YES | Admin / Treasurer | Requested → **Approved** → Settlement |
| **Expenses** | ❌ NO | Auto-post | Draft → **Posted** (immediate) |
| **Other Income** | ❌ NO | Auto-post | Draft → **Posted** (immediate) |
| **Fees** | ❌ NO | N/A | Mark Paid (immediate) |
| **Savings Deposits** | ❌ NO | N/A | Record (immediate) |
| **Savings Withdrawals** | ❌ NO | N/A | Record (immediate) |
| **Loan Repayments** | ❌ NO | N/A | Record (immediate) |
| **Member Registration** | ❌ NO | N/A | Add (immediate) |
| **Shares** | ❌ NO | N/A | Record (immediate) |

---

## 🎯 The Two Types of Workflows

### Type 1: Approval Workflow (Maker-Checker) ✅
**Pattern:** Draft → Submit → **Approve/Reject** → Post

**Characteristics:**
- ✅ Requires **two people** (maker ≠ checker)
- ✅ Chairman/Vice Chairman typically approves
- ✅ Can be **rejected** with reason
- ✅ Stays in "Draft" until submitted
- ✅ Stays in "Pending" until approved
- ✅ Only posts to GL **after approval**

**Used for:**
- Financial commitments (loans, investments)
- Accounting adjustments (vouchers, adjustments, opening balances)
- High-value decisions (account closures)

---

### Type 2: Direct Posting (No Approval) ❌
**Pattern:** Record → **Post** (immediate)

**Characteristics:**
- ❌ No approval needed
- ❌ Single person can complete
- ❌ Posts to GL immediately
- ✅ Faster for routine operations
- ✅ Trusted roles only (admin, treasurer, cashier)

**Used for:**
- Routine transactions (deposits, withdrawals, repayments)
- Fee collection
- Daily operations
- Expense recording

---

## 📊 Detailed Approval Workflows

### 1️⃣ **Loans** (Critical — Requires Approval ✅)

**Workflow:**
```
Step 1: Record Loan (Admin/Treasurer/Loans Officer)
   └─ Status: Draft
   └─ Can edit freely

Step 2: Submit for Approval (Same person)
   └─ Status: Pending Approval
   └─ Can no longer edit
   └─ Notification sent to Chairman

Step 3: Chairman Reviews
   ├─ Option A: Approve
   │  └─ Status: Approved
   │  └─ Ready for disbursement
   │
   └─ Option B: Reject (with reason)
      └─ Status: Rejected
      └─ Loan officer can re-edit and resubmit

Step 4: Disburse Loan (Loans Officer)
   └─ Status: Active
   └─ GL posts: Dr Loans Receivable / Cr Cash/Bank/MoMo
```

**Who Can:**
- **Record:** Admin, Treasurer, Loans Officer
- **Submit:** Same person who recorded
- **Approve:** Chairman, Vice Chairman ⭐
- **Reject:** Chairman, Vice Chairman
- **Disburse:** Loans Officer, Admin, Treasurer

**Why Approval Needed:**
- High financial risk
- Long-term commitment
- Governance oversight required

---

### 2️⃣ **Loan Applications** (Requires Approval ✅)

**Workflow:**
```
Step 1: Create Application (Admin/Treasurer/Loans Officer)
   └─ Status: Draft

Step 2: Submit for Approval
   └─ Status: Pending Approval

Step 3: Approve/Reject (Chairman/Vice Chairman/Secretary)
   ├─ Approve → Can convert to loan
   └─ Reject → Application closed

Step 4: Convert to Loan (if approved)
   └─ Creates actual loan record
```

**Who Can:**
- **Create:** Admin, Treasurer, Loans Officer
- **Approve:** Chairman, Vice Chairman, Secretary ⭐
- **Reject:** Chairman, Vice Chairman, Secretary

**Why Approval Needed:**
- Pre-approval before formal loan
- Secretary can approve (operational decision)

---

### 3️⃣ **Internal Vouchers** (Critical — Requires Approval ✅)

**Workflow:**
```
Step 1: Create Voucher (Admin/Treasurer)
   └─ Status: Draft
   └─ Add journal lines (must balance)

Step 2: Submit for Approval
   └─ Status: Pending Approval
   └─ Notification to Chairman

Step 3: Chairman Reviews
   ├─ Approve → Ready to post
   └─ Reject → Back to draft

Step 4: Post (Admin/Treasurer)
   └─ Status: Posted
   └─ GL entry created
```

**Who Can:**
- **Create:** Admin, Treasurer
- **Submit:** Same person
- **Approve:** Chairman ONLY (not Vice Chairman!) ⭐⭐
- **Reject:** Chairman only
- **Post:** Admin, Treasurer (after approval)

**Why Approval Needed:**
- Direct GL manipulation
- Can affect any account
- Highest risk transaction type
- Chairman-only governance

**⚠️ Important:** Internal Vouchers are the ONLY approval workflow where **Chairman is required** — Vice Chairman cannot approve vouchers!

---

### 4️⃣ **Investments** (Requires Approval ✅)

**Workflow:**
```
Step 1: Record Investment (Admin/Treasurer)
   └─ Status: Draft

Step 2: Submit for Approval
   └─ Status: Pending Approval

Step 3: Approve/Reject (Chairman/Vice Chairman/Secretary)
   ├─ Approve → Ready to post
   └─ Reject → Back to draft

Step 4: Post (Admin/Treasurer)
   └─ Status: Posted
   └─ GL posts: Dr Investment Account / Cr Funding Account
```

**Who Can:**
- **Record:** Admin, Treasurer
- **Approve:** Chairman, Vice Chairman, Secretary ⭐
- **Post:** Admin, Treasurer (after approval)

**Why Approval Needed:**
- Large financial commitment
- External investment decision

---

### 5️⃣ **Opening Balances** (Critical — Requires Approval ✅)

**Workflow:**
```
Step 1: Create Opening Balance Batch (Admin/Treasurer)
   └─ Status: Draft
   └─ Add account lines (debits must = credits)

Step 2: Submit for Approval
   └─ Status: Pending Approval
   └─ Notification to Chairman

Step 3: Chairman Reviews
   ├─ Approve → Ready to post
   └─ Reject → Back to draft

Step 4: Post (Admin/Treasurer)
   └─ Status: Posted
   └─ GL entries created
   └─ All reports now show data
```

**Who Can:**
- **Create:** Admin, Treasurer
- **Approve:** Chairman, Vice Chairman ⭐
- **Post:** Admin, Treasurer (after approval)

**Why Approval Needed:**
- Sets financial year baseline
- Affects all future reports
- One-time critical entry
- High financial impact

---

### 6️⃣ **Member Account Adjustments** (Requires Approval ✅)

**Workflow:**
```
Step 1: Create Adjustment (Admin/Treasurer)
   └─ Status: Draft
   └─ Adjust member's savings account balance

Step 2: Submit for Approval
   └─ Status: Pending Approval

Step 3: Chairman Reviews
   ├─ Approve → Ready to post
   └─ Reject → Back to draft

Step 4: Post (Admin/Treasurer)
   └─ Status: Posted
   └─ Member balance adjusted
   └─ GL entry created
```

**Who Can:**
- **Create:** Admin, Treasurer
- **Approve:** Chairman, Vice Chairman ⭐
- **Post:** Admin, Treasurer (after approval)

**Why Approval Needed:**
- Directly alters member balances
- Correction/adjustment scenario
- Risk of fraud without oversight

---

### 7️⃣ **Fixed Deposit Closures** (Requires Approval ✅)

**Workflow:**
```
Step 1: Member Requests Closure
   └─ Status: Pending Approval

Step 2: Admin/Treasurer Reviews
   ├─ Approve → Closure approved
   │  └─ Moves to Payout Queue
   │
   └─ Reject → Request denied

Step 3: Process Payout (Admin/Treasurer)
   └─ Status: Closed
   └─ GL posts closure + interest
```

**Who Can:**
- **Approve:** Admin, Treasurer ⭐
- **Payout:** Admin, Treasurer

**Why Approval Needed:**
- Early closure may have penalties
- Interest calculation verification
- Financial impact review

---

### 8️⃣ **Savings Account Closures** (Requires Approval ✅)

**Workflow:**
```
Step 1: Request Closure
   └─ Status: Pending Approval

Step 2: Admin/Treasurer Reviews
   ├─ Approve → Ready for settlement
   └─ Reject → Request denied

Step 3: Settle Account (Admin/Treasurer)
   └─ Status: Closed
   └─ Final balance paid out
```

**Who Can:**
- **Approve:** Admin, Treasurer ⭐

**Why Approval Needed:**
- Account closure is permanent
- Final balance verification

---

## ❌ What DOES NOT Require Approval (Direct Posting)

### 1️⃣ **Expenses**
**Workflow:** Record → Post (immediate)

**Why No Approval:**
- Routine operational expenses
- Trusted roles (Admin/Treasurer)
- Speed needed for day-to-day ops

**Who Can Post:** Admin, Treasurer

---

### 2️⃣ **Other Income**
**Workflow:** Record → Post (immediate)

**Why No Approval:**
- Routine income collection
- Trusted roles
- Fast posting needed

**Who Can Post:** Admin, Treasurer

---

### 3️⃣ **Fees (Mark Paid)**
**Workflow:** Charge created → Mark Paid (immediate)

**Why No Approval:**
- Routine collection
- Fee already charged (separate action)
- Trusted collection roles

**Who Can Mark Paid:** Admin, Treasurer, Cashier, Office Admin

---

### 4️⃣ **Savings Deposits**
**Workflow:** Record → Post (immediate)

**Who Can:** Admin, Treasurer, Cashier

---

### 5️⃣ **Savings Withdrawals**
**Workflow:** Record → Post (immediate)

**Who Can:** Admin, Treasurer

---

### 6️⃣ **Loan Repayments**
**Workflow:** Record → Post (immediate)

**Who Can:** Admin, Treasurer, Cashier, Loans Officer

---

### 7️⃣ **Member Registration**
**Workflow:** Add → Active (immediate)

**Who Can:** Admin, Office Admin, Treasurer

---

### 8️⃣ **Share Transactions**
**Workflow:** Record → Post (immediate)

**Who Can:** Admin, Treasurer

---

## 🎭 Role-Based Approval Matrix

### Who Can Approve What?

| Transaction Type | Chairman | Vice Chairman | Secretary | Admin | Treasurer |
|-----------------|----------|---------------|-----------|-------|-----------|
| **Loans** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Loan Applications** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Internal Vouchers** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Investments** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Opening Balances** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Member Adjustments** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **FD Closures** | ❌ | ❌ | ❌ | ✅ | ✅ |
| **Savings Closures** | ❌ | ❌ | ❌ | ✅ | ✅ |

**Key Insights:**
- ⭐ **Chairman** can approve everything
- ⭐ **Vice Chairman** can approve most things (except Internal Vouchers!)
- ⭐ **Secretary** can approve operational items (loans applications, investments)
- ⭐ **Admin/Treasurer** approve account closures only

---

## 🚫 Maker-Checker Rules

### Rule 1: Same Person Cannot Make AND Approve
**Example:**
```
❌ WRONG:
   └─ Treasurer records loan
   └─ Treasurer approves loan (BLOCKED!)

✅ CORRECT:
   └─ Treasurer records loan
   └─ Chairman approves loan
```

**Why:** Prevents fraud — dual control required

---

### Rule 2: Can Edit Only in "Draft" Status
**Example:**
```
Draft → Can edit ✅
Pending Approval → Cannot edit ❌
Approved → Cannot edit ❌
Posted → Cannot edit ❌
```

**If changes needed after submission:**
- Chairman must **Reject** → sends back to Draft
- Maker edits → Resubmits
- Chairman **Approves** again

---

### Rule 3: Approval is Permanent (Cannot Un-Approve)
Once approved, you can only:
- ✅ **Post** it (move forward)
- ❌ Cannot "un-approve" (would need correction entry)

---

## 📊 Status Transitions

### Approval Workflow Statuses:

```
Draft
  ↓ (Submit)
Pending Approval
  ↓           ↓
Approved     Rejected
  ↓           ↓
Posted    Back to Draft
            ↓ (Re-edit)
         Re-submit
```

### Common Status Values:

| Status | Meaning | Actions Available |
|--------|---------|------------------|
| **draft** | Created, not submitted | Edit, Submit, Delete |
| **pending_approval** | Awaiting approval | Approve, Reject (approver only) |
| **approved** | Approved, not posted | Post (maker only) |
| **rejected** | Denied by approver | Edit, Re-submit |
| **posted** | Finalized in GL | View only (no changes) |

---

## 🔔 Notifications

### Who Gets Notified?

**When Submitted:**
- ✅ Approvers get notification (Chairman, Vice Chairman, etc.)

**When Approved:**
- ✅ Maker gets notification (can now post)

**When Rejected:**
- ✅ Maker gets notification (can re-edit)

---

## ⚙️ Configuring Approval Workflows

### Can I Add/Remove Approval Requirements?

**Short Answer:** ❌ No, approval workflows are **hardcoded** for security.

**Why:**
- Prevents someone from disabling approvals to commit fraud
- Governance model is built into the system
- Changing would require code modification

**What You CAN Configure:**
- ✅ Who has which role (assign users to Chairman/Vice Chairman/etc.)
- ✅ Notification preferences

**What You CANNOT Configure:**
- ❌ Which transactions require approval (fixed)
- ❌ Who can approve what (role-based, fixed)

---

## 🎯 Best Practices

### 1. Don't Submit Unless Ready
- ✅ Review thoroughly before submitting
- ✅ Once submitted, you can't edit (unless rejected)

### 2. Chairman Should Review Daily
- ✅ Check pending approvals regularly
- ✅ Don't leave items pending for days

### 3. Use Rejection Reasons
- ✅ Always provide clear reason when rejecting
- ✅ Helps maker understand what to fix

### 4. Post Immediately After Approval
- ✅ Don't leave approved items unposted
- ✅ GL should reflect approved decisions

### 5. Internal Vouchers Need Extra Care
- ✅ Chairman should review very carefully
- ✅ Can affect any account in the system
- ✅ Highest risk transaction type

---

## 📋 Quick Reference Checklist

**Before Submitting for Approval:**
- [ ] All required fields filled
- [ ] Amounts verified
- [ ] Member/account details correct
- [ ] Description clear and complete
- [ ] Journal lines balance (if applicable)

**When Approving:**
- [ ] Review all details
- [ ] Verify amounts are reasonable
- [ ] Check member/account information
- [ ] Ensure proper documentation exists
- [ ] Understand the business reason

**When Rejecting:**
- [ ] Provide clear reason
- [ ] Suggest what needs fixing
- [ ] Notify maker directly if urgent

---

## ✅ Summary

### What Requires Approval:
1. ✅ **Loans** (Chairman/Vice Chairman)
2. ✅ **Loan Applications** (Chairman/Vice Chairman/Secretary)
3. ✅ **Internal Vouchers** (Chairman ONLY)
4. ✅ **Investments** (Chairman/Vice Chairman/Secretary)
5. ✅ **Opening Balances** (Chairman/Vice Chairman)
6. ✅ **Member Account Adjustments** (Chairman/Vice Chairman)
7. ✅ **Account Closures** (Admin/Treasurer)

### What Does NOT Require Approval:
- ❌ Expenses (direct post)
- ❌ Other Income (direct post)
- ❌ Fees (direct mark paid)
- ❌ Savings deposits/withdrawals (direct post)
- ❌ Loan repayments (direct post)
- ❌ Member registration (direct add)
- ❌ Share transactions (direct post)

### Key Governance Rules:
1. **Maker ≠ Checker** — Same person cannot make and approve
2. **Chairman has most power** — Can approve almost everything
3. **Vice Chairman is deputy** — Same powers except Internal Vouchers
4. **Secretary is operational** — Approves applications and investments only
5. **Internal Vouchers are special** — Chairman approval ONLY (highest risk)

---

**Document Version:** 1.0  
**Last Updated:** 2026-09-14
