# Empower Investment Club — Accounting Setup Guide
**Document Purpose:** Team briefing on financial year, accounting periods, and opening balances  
**Date:** 2026-09-12  
**Audience:** Management team, treasurer, accountant  

---

## Executive Summary

**Why can't we see the Balance Sheet right now?**

The system is asking us to complete **3 mandatory accounting setup steps** before it can present a legitimate Balance Sheet. This is **not a system error** — it's a deliberate **accounting control** to ensure financial reports show real, approved data rather than fabricated numbers.

**What needs to be done:**
1. Create a **Financial Year** (e.g., "2026-2027")
2. Create **Accounting Periods** within that year (e.g., "January 2026", "February 2026")
3. Record and approve **Opening Balances** for that financial year

**Timeline:** 2-4 hours for first-time setup (one-off task)

---

## Understanding the 3 Required Components

### 1️⃣ Financial Year

**What it is:**
- A 12-month period for your club's accounting (e.g., "January 2026 - December 2026")
- Can be calendar year (Jan-Dec) or fiscal year (Jul-Jun, Apr-Mar, etc.)

**Why it's required:**
- All financial reports are **year-specific**
- You can't mix transactions from different years
- Allows year-end closing and comparisons

**Analogy:** Like chapters in a book — you need to define which chapter you're writing before adding content.

**Status in your system:**
- ❌ Not yet created for 2026
- ✅ System ready to create it (Settings → Financial Years)

---

### 2️⃣ Accounting Periods

**What it is:**
- Subdivisions of a financial year (usually monthly)
- Example: Financial Year 2026 has 12 periods (Jan 2026, Feb 2026, ..., Dec 2026)

**Why it's required:**
- Organizes transactions into manageable chunks
- Allows monthly/quarterly reports
- Enables period-by-period closing

**Analogy:** Like pages in a chapter — you need numbered pages before writing paragraphs.

**Status in your system:**
- ❌ Not yet created for 2026
- ✅ System can auto-create monthly periods once financial year exists

**Important:** You can close periods (lock past months) to prevent backdated changes.

---

### 3️⃣ Opening Balances

**What it is:**
- Your club's **starting financial position** at the beginning of a financial year
- Example: "On January 1, 2026, we had Shs 5,000,000 in cash, Shs 3,000,000 in member savings, etc."

**Why it's required:**
- A Balance Sheet shows **cumulative balances** from the beginning of the year
- Without opening balances, the system has **no legitimate starting point**
- Prevents showing fabricated/test data as real club financials

**Analogy:** Like the opening balance in your bank passbook — you can't calculate your current balance without knowing where you started.

**Status in your system:**
- ❌ Not yet recorded for 2026
- ⚠️ **Critical:** This requires an **accountant's decision**, not automatic calculation

---

## Why Is the System Asking for This?

### The "No Fabricated Figures" Policy

Your system follows **strict accounting principles:**

✅ **Correct behavior:**
- Refuses to show a Balance Sheet until opening balances are **formally approved**
- Shows a clear warning: "Opening balances have not been established"
- Requires maker-checker approval (one person prepares, another approves)

❌ **Wrong behavior (what cheap systems do):**
- Automatically calculate opening balances from historical data
- Show figures even when data integrity is questionable
- Mix test transactions with real club data

### Why Historical Data Can't Be Trusted (Stage 24 Forensic Audit Finding)

A forensic review of your system found:
- Most historical loan and savings entries are **development/test activity**, not real transactions
- These test entries are **preserved for audit purposes** but aren't treated as authoritative
- Using them for opening balances would **misrepresent** your club's actual financial position

**Therefore:** Opening balances **must be manually prepared** by someone who knows the club's real financial position (e.g., your treasurer/accountant reviewing bank statements, cash counts, and member ledgers).

---

## Step-by-Step Setup Process

### Phase 1: Create Financial Year (5 minutes)

**Who:** Admin or Treasurer  
**Where:** Settings → Financial Years → Create New

**Decision needed:**
- **Name:** e.g., "FY 2026-2027"
- **Start Date:** e.g., January 1, 2026
- **End Date:** e.g., December 31, 2026
- **Status:** Active

**Example:**
```
Name: Financial Year 2026
Start Date: 2026-01-01
End Date: 2026-12-31
```

**Result:** A financial year container that can hold accounting periods and transactions.

---

### Phase 2: Create Accounting Periods (10-15 minutes)

**Who:** Admin or Treasurer  
**Where:** Accounting → Accounting Periods → Create Period

**You'll create 12 periods (or fewer if starting mid-year):**

| Period Name | Start Date | End Date | Status |
|-------------|-----------|----------|--------|
| January 2026 | 2026-01-01 | 2026-01-31 | Open |
| February 2026 | 2026-02-01 | 2026-02-28 | Open |
| March 2026 | 2026-03-01 | 2026-03-31 | Open |
| ... | ... | ... | Open |
| December 2026 | 2026-12-01 | 2026-12-31 | Open |

**Important:**
- Periods cannot overlap
- Past periods can be "closed" to lock them (prevent backdated entries)
- Current period stays "open" for daily transactions

---

### Phase 3: Prepare Opening Balances (1-3 hours — **Most Important Step**)

**Who:** Treasurer or Accountant (person with real financial records)  
**Where:** Accounting → Opening Balances → Prepare Batch

#### 3A: Gather Real Financial Data (Before Starting)

**You need the following as of your chosen start date (e.g., January 1, 2026):**

**Assets (what the club owns):**
- [ ] Cash in hand (count physical cash)
- [ ] Bank balance (check bank statement)
- [ ] Mobile Money balance (MTN/Airtel)
- [ ] Total loans owed TO the club by members (sum of all outstanding loans)
- [ ] Any other assets (property, investments, etc.)

**Liabilities (what the club owes):**
- [ ] Total member savings (sum of all member savings balances)
- [ ] Any external loans the club owes
- [ ] Any pending payments/expenses owed

**Equity (club's net worth):**
- [ ] Member shares (if applicable)
- [ ] Retained earnings from prior years
- [ ] Any contributions/reserves

**Accounting rule:** **Assets = Liabilities + Equity** (must balance exactly)

**Example realistic opening balances:**
```
ASSETS:
Cash at Hand (1110): Shs 2,000,000
Bank Account (1140): Shs 5,000,000
Mobile Money (1150): Shs 500,000
Loans Receivable (1210): Shs 10,000,000
Total Assets: Shs 17,500,000

LIABILITIES:
Member Savings (2110): Shs 12,000,000
Total Liabilities: Shs 12,000,000

EQUITY:
Member Shares (3110): Shs 3,000,000
Retained Earnings (3200): Shs 2,500,000
Total Equity: Shs 5,500,000

CHECK: 17,500,000 = 12,000,000 + 5,500,000 ✓ (Balanced)
```

#### 3B: Enter Opening Balances in System

**Steps:**
1. Go to **Accounting → Opening Balances → Prepare Batch**
2. Fill in:
   - **Batch Number:** e.g., "OB-2026-001"
   - **Financial Year:** Select "FY 2026" (the one you created)
   - **Accounting Period:** Select "January 2026"
   - **As Of Date:** 2026-01-01 (or your start date)
   - **Description:** "Opening balances for FY 2026"

3. **Add lines for each account:**
   - Select account (e.g., "1110 Cash at Hand")
   - Enter **Debit** for assets, expenses (left side)
   - Enter **Credit** for liabilities, equity, income (right side)

4. **System validates:**
   - Total Debits must equal Total Credits (accounting rule)
   - Won't let you save if unbalanced

5. **Save as Draft**

#### 3C: Maker-Checker Approval (Security Control)

**Why this step exists:**
- Opening balances affect **every future report**
- Requires dual authorization to prevent fraud
- Ensures independent review

**Process:**
1. **Preparer** (e.g., Treasurer) creates the batch (saves as draft)
2. **Preparer** clicks **"Submit for Approval"**
3. **Approver** (e.g., Chairman — must be different person) reviews:
   - Are amounts correct?
   - Do totals match physical records?
   - Does it balance?
4. **Approver** clicks **"Approve"** or **"Reject"** with reason

**Important:** Same person cannot prepare AND approve (system prevents this).

#### 3D: Post Opening Balances

**Final step:**
1. After approval, the batch status is "Approved (Not Posted)"
2. Authorized user clicks **"Post"**
3. System creates journal entries
4. Opening balances are now **locked** and reflected in all reports

**Result:** Balance Sheet now shows real data! ✅

---

## Complete Setup Timeline

| Phase | Task | Time | Who |
|-------|------|------|-----|
| 1 | Create Financial Year | 5 min | Admin/Treasurer |
| 2 | Create 12 Accounting Periods | 15 min | Admin/Treasurer |
| 3A | Gather real financial data | 1-2 hours | Treasurer/Accountant |
| 3B | Enter opening balances in system | 30 min | Treasurer |
| 3C | Review and approve | 15 min | Chairman (different person) |
| 3D | Post opening balances | 2 min | Admin/Treasurer |
| **Total** | **First-time setup** | **2-4 hours** | Team effort |

---

## Navigation Paths (Where to Find Each Feature)

**Financial Years:**
- Menu: Settings → Financial Years
- URL: `?page=financial-years`

**Accounting Periods:**
- Menu: Accounting → Accounting Periods
- URL: `?page=accounting-periods`

**Opening Balances:**
- Menu: Accounting → Opening Balances
- URL: `?page=opening-balances`
- Create New: `?page=opening-balance-create`

**Balance Sheet (After Setup):**
- Menu: Reports → Balance Sheet
- URL: `?page=report-balance-sheet`

---

## Who Can Do What? (Role Permissions)

| Action | Admin | Treasurer | Chairman | Loans Officer | Cashier |
|--------|-------|-----------|----------|---------------|---------|
| Create Financial Year | ✅ | ✅ | ❌ | ❌ | ❌ |
| Create Accounting Period | ✅ | ✅ | ❌ | ❌ | ❌ |
| Prepare Opening Balances | ✅ | ✅ | ❌ | ❌ | ❌ |
| Approve Opening Balances | ✅ | ❌ | ✅ | ❌ | ❌ |
| Post Opening Balances | ✅ | ✅ | ❌ | ❌ | ❌ |
| View Balance Sheet | ✅ | ✅ | ✅ | ❌ | ❌ |

**Key rule:** Same person can't prepare AND approve opening balances (security control).

---

## Common Questions & Answers

### Q1: Do we have to do this every year?

**Yes**, but it gets easier:
- **First year:** Manual setup (gather all data from scratch)
- **Subsequent years:** System can auto-generate opening balances from prior year's closing balances
- You still review and approve, but preparation is automated

---

### Q2: What if we started using the system mid-year?

**No problem:**
- Create financial year for full year (e.g., Jan-Dec 2026)
- Create periods starting from the month you began (e.g., August onwards)
- Opening balances reflect your position on the start date (e.g., August 1, 2026)

---

### Q3: Can we use historical transactions as opening balances?

**Not recommended** for your club because:
- Historical data includes test/development transactions (forensic audit finding)
- Mixing test data with real financials violates accounting principles
- Proper opening balances require **accountant verification**

**Better approach:** 
- Treasurer manually compiles real financial position (bank statements, cash count, member ledgers)
- Enters those verified figures as opening balances
- Future transactions build on that clean foundation

---

### Q4: What happens if we enter wrong opening balances?

**Before posting:**
- Edit the draft batch anytime
- Reject and create a new batch

**After posting:**
- Opening balances are **locked** (can't edit)
- You'd need to create a **correcting journal entry** (Internal Voucher)
- Or **reopen** the financial year (if period hasn't started yet)

**Lesson:** Get it right before posting! Have Chairman review carefully.

---

### Q5: How often do we create accounting periods?

**Once per month** (or at period start):
- Most clubs pre-create all 12 periods at year start
- Or create them monthly as needed
- Once created, periods stay until year-end

---

### Q6: Can we change the financial year dates later?

**Before any transactions:**
- Yes, edit freely

**After transactions exist:**
- Cannot change dates (would break posted transactions)
- Would need to close the year and create a new one

**Best practice:** Get dates right upfront.

---

### Q7: What if our opening balances don't balance? (Debits ≠ Credits)

**System won't let you save** until they balance.

**Common reasons for imbalance:**
1. Forgot an account (e.g., forgot to enter member savings)
2. Arithmetic error (amounts don't add up)
3. Misclassified something (e.g., put liability as debit instead of credit)

**How to fix:**
- Review your manual calculations
- Check: Assets = Liabilities + Equity
- Use the "Retained Earnings" account to absorb the difference (this represents club's historical profit)

**Example fix:**
```
If Assets > Liabilities + Equity:
→ You're missing equity (retained earnings)
→ Credit 3200 Retained Earnings for the difference

If Assets < Liabilities + Equity:
→ You're missing assets or overstated liabilities
→ Review your source data
```

---

## What Happens After Setup Is Complete?

### ✅ You Can Now:

1. **View Balance Sheet** with real data
2. **View Income Statement** for the period
3. **Run Trial Balance** to verify accounting integrity
4. **Record transactions** (expenses, income, loans, savings) — they auto-post to correct periods
5. **Generate reports** filtered by financial year/period
6. **Close periods** month by month to lock historical data
7. **Year-end close** when the year ends (transfers net income to retained earnings)

### 🔄 Ongoing Operations:

**Daily/Weekly:**
- Record transactions normally (the system auto-assigns them to correct periods based on date)

**Monthly:**
- Review month-end reports
- Optionally close the completed month (locks it from changes)

**Annually:**
- Close the financial year
- Create next year's financial year
- System auto-generates next year's opening balances from this year's closing

---

## Recommended Setup Session Agenda

**Meeting:** Financial Year & Opening Balances Setup Session  
**Duration:** 2-3 hours  
**Attendees:** Treasurer, Chairman, Admin, Accountant (if available)

**Agenda:**

**Part 1: Pre-Work (Before Meeting) — 1-2 hours**
- Treasurer gathers real financial data:
  - Bank statements
  - Cash count
  - Member savings ledger totals
  - Outstanding loans list
- Calculates Assets, Liabilities, Equity
- Ensures Assets = Liabilities + Equity (must balance)

**Part 2: System Setup (During Meeting) — 1 hour**
- [ ] **Step 1:** Admin creates Financial Year (5 min)
- [ ] **Step 2:** Admin creates 12 Accounting Periods (15 min)
- [ ] **Step 3:** Treasurer enters opening balances batch (30 min)
  - Reads out amounts from gathered data
  - Chairman verifies against physical records
  - System validates debits = credits
- [ ] **Step 4:** Save as draft, submit for approval

**Part 3: Review & Approval (During Meeting) — 30 min**
- [ ] Chairman reviews draft batch
- [ ] Compares against source documents
- [ ] Asks questions if amounts seem wrong
- [ ] Approves batch (or requests corrections)
- [ ] Treasurer posts approved batch

**Part 4: Verification (During Meeting) — 15 min**
- [ ] Generate Balance Sheet → verify it shows figures
- [ ] Check Assets = Liabilities + Equity
- [ ] Print and file for records
- [ ] Setup complete! 🎉

---

## Support & Resources

**Documentation:**
- System user manual (if available)
- Chart of Accounts (shows all account codes/names)
- This document

**In-System Help:**
- Balance Sheet warning message (explains why opening balances needed)
- Form validation messages (guides you if something's wrong)

**Contact:**
- System Administrator: [Your contact]
- Accountant/Consultant: [Your contact]

---

## Key Takeaways for Your Team

### ✅ This Is Normal
- **Every professional accounting system** requires financial year setup and opening balances
- This is **not a bug** — it's proper accounting practice
- QuickBooks, Sage, Tally all have the same requirement

### ✅ It's a One-Time Task
- Setup takes 2-4 hours **once per year**
- Subsequent years are mostly automated
- Daily operations are unaffected

### ✅ It Protects Your Club
- Ensures financial reports show **real data**, not test/fabricated figures
- Maker-checker approval prevents fraud
- Clean foundation for all future reporting

### ✅ What You Get After Setup
- Professional Balance Sheet
- Income Statement
- Trial Balance
- All accounting reports filtered by year/period
- Year-end closing capability
- Audit trail for all financial activity

---

## Next Steps

**Action Items:**

**For Treasurer:**
- [ ] Gather real financial data (bank statements, cash count, member ledgers)
- [ ] Calculate Assets, Liabilities, Equity totals
- [ ] Ensure figures balance (Assets = Liabilities + Equity)
- [ ] Schedule setup session with Chairman

**For Admin:**
- [ ] Prepare to create Financial Year and Accounting Periods
- [ ] Have login credentials ready

**For Chairman:**
- [ ] Block 2-3 hours for setup session
- [ ] Bring physical records to verify opening balances
- [ ] Prepare to review and approve

**For Team:**
- [ ] Read this document (15 minutes)
- [ ] Understand why setup is needed
- [ ] Ask questions if anything unclear

---

**Ready to begin? Start with Phase 1: Create Financial Year**

**Questions?** Contact your system administrator.

---

**Document Version:** 1.0  
**Last Updated:** 2026-09-12  
**Author:** System Administrator
