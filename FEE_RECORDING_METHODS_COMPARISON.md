# Fee Recording Methods — When to Use What?

**Question:** Can we use Internal Vouchers to post the processing fee?

**Answer:** YES, but it depends on your use case. Here are all the methods and when to use each.

---

## 📊 Three Ways to Record a Loan Processing Fee

### Method 1: Automatic Charging (Built-In) ✅ **RECOMMENDED**
### Method 2: Manual Fee Charge (Fee Module) ⚠️ **Limited Use**
### Method 3: Internal Voucher (Manual Journal Entry) 🔧 **For Corrections/Special Cases**

---

## Method 1: Automatic Charging (✅ Recommended — Normal Operation)

### When to Use:
- ✅ **Normal day-to-day loan recording**
- ✅ When fee policy is standardized (same rate/amount for all loans)
- ✅ When you want tracking of who paid, when, and how
- ✅ When you want member-specific fee records

### How It Works:
```
Step 1: Configure fee (one-time setup)
   └─ Settings → Fees & Charges
   └─ Frequency = "Per Loan"
   └─ Type = Percentage or Fixed

Step 2: Record loan normally
   └─ Loans → Add New Loan
   └─ System auto-creates fee charge (Status: Pending)

Step 3: Collect the fee
   └─ Fees & Charges → Fee Charges
   └─ Find pending charge → Mark Paid
   └─ GL auto-posts: Dr Cash / Cr 4100 Loan Processing Fees
```

### Accounting Entry (When Marked Paid):
```
Dr 1110 Cash at Hand                 30,000
   Cr 4100 Loan Processing Fees              30,000
```

### Advantages:
- ✅ **Fully automated** — no manual calculation
- ✅ **Tracked per member** — know who paid, who didn't
- ✅ **Linked to loan** — easy to trace
- ✅ **Audit trail** — who charged, who collected, when
- ✅ **Reports available** — fee collection reports
- ✅ **Prevents duplicates** — system checks if already charged
- ✅ **Maker-checker ready** — can add approval workflow if needed

### Disadvantages:
- ❌ Requires fee to be configured first
- ❌ Can't charge different amounts for different loans (unless you change the fee config each time)

---

## Method 2: Manual Fee Charge (⚠️ Limited — Special Cases)

### When to Use:
- ⚠️ **One-off fee charges** (not tied to loan recording)
- ⚠️ **Retroactive corrections** (loan was recorded but fee wasn't charged)
- ⚠️ **Special circumstances** (loan was recorded elsewhere, importing to system)

### How It Works:
```
Step 1: Create a "One Time" fee type
   └─ Settings → Fees & Charges
   └─ Name: "Loan Processing Fee (Manual)"
   └─ Frequency = "One Time"
   └─ Type = "Fixed"
   └─ Amount = 30,000

Step 2: Manually charge the member
   └─ Fees & Charges → Record Fee
   └─ Select member
   └─ Select "Loan Processing Fee (Manual)"
   └─ Creates pending charge

Step 3: Mark as paid
   └─ Same as Method 1
```

### Accounting Entry (When Marked Paid):
```
Dr 1110 Cash at Hand                 30,000
   Cr 4100 Loan Processing Fees              30,000
```

### Advantages:
- ✅ **Still tracked per member**
- ✅ **Still has payment history**
- ✅ **Can be used for non-loan fees** too

### Disadvantages:
- ❌ **Manual work** — you have to remember to charge it
- ❌ **Not linked to specific loan** (unless you add reference in notes)
- ❌ **Can be missed** — no automatic reminder
- ❌ **"Per Loan" fees don't show** in manual charge dropdown (by design)

---

## Method 3: Internal Voucher (🔧 For Corrections/Special Cases)

### When to Use:
- 🔧 **Corrections** — fixing errors in past accounting
- 🔧 **Backdated entries** — recording historical transactions
- 🔧 **Complex entries** — multiple fees, adjustments, reclassifications
- 🔧 **No member record needed** — just the accounting entry
- 🔧 **Chairman approval required** — sensitive adjustments

### How It Works:
```
Step 1: Create voucher
   └─ Other Finance → Internal Vouchers → Create New
   └─ Voucher Number: IV-2026-001
   └─ Description: "Loan processing fee for LNS-000123"

Step 2: Add journal lines
   └─ Line 1: Dr 1110 Cash at Hand — Debit: 30,000
   └─ Line 2: Cr 4100 Loan Processing Fees — Credit: 30,000
   └─ Save as Draft

Step 3: Submit → Chairman Approves → Post
   └─ GL entry created immediately
```

### Accounting Entry (When Posted):
```
Dr 1110 Cash at Hand                 30,000
   Cr 4100 Loan Processing Fees              30,000
```

### Advantages:
- ✅ **Maximum flexibility** — any accounts, any amounts
- ✅ **Multi-line entries** — can record multiple fees at once
- ✅ **Backdating allowed** — fix historical errors
- ✅ **Chairman approval** — governance control for sensitive entries
- ✅ **Complex scenarios** — reclassifications, adjustments, corrections
- ✅ **Full audit trail** — who created, who approved, description

### Disadvantages:
- ❌ **Not tracked per member** in fee system (just in GL)
- ❌ **No fee-specific reports** — shows in GL only
- ❌ **Manual calculation** — you do the math
- ❌ **Requires approval** — can't post immediately (unless you're Chairman)
- ❌ **More steps** — not as quick as Method 1

---

## 🎯 Decision Tree: Which Method Should I Use?

### Scenario 1: Recording a New Loan Today
**Use:** **Method 1 (Automatic)** ✅
- Let the system auto-charge the fee
- Mark it paid when member pays

**Why:** Fast, tracked, automated, no errors

---

### Scenario 2: I Forgot to Charge a Processing Fee Last Month
**Use:** **Method 2 (Manual Fee Charge)** ⚠️ OR **Method 3 (Internal Voucher)** 🔧

**Option A — Method 2 (If you want it tracked in fee system):**
1. Create "One Time" fee if not exists
2. Manually charge the member
3. Mark as paid (if already collected)

**Option B — Method 3 (If you just need the accounting entry):**
1. Create Internal Voucher
2. Dr Cash / Cr 4100 Loan Processing Fees
3. Get Chairman approval → Post

**Why:** Correcting a past mistake

---

### Scenario 3: Importing Historical Loans from Paper Records
**Use:** **Method 3 (Internal Voucher)** 🔧

**Why:** 
- Backdating needed
- Multiple loans at once
- Chairman should review historical data import
- No need for individual member fee tracking (already paid long ago)

---

### Scenario 4: Member Paid Processing Fee But Loan Wasn't Recorded in System Yet
**Use:** **Method 1 (still!)** ✅

**Process:**
1. Record the loan → Fee auto-charges (Pending)
2. Immediately mark it as "Paid" → select payment method
3. System posts the income

**Why:** Keeps the linkage between loan and fee

---

### Scenario 5: Complex Adjustment (Reclassifying Multiple Fees)
**Use:** **Method 3 (Internal Voucher)** 🔧

**Example:**
```
Reclassify wrongly recorded "Membership Fee" to "Loan Processing Fee"

Dr 4090 Membership Fees              30,000
   Cr 4100 Loan Processing Fees              30,000
```

**Why:** Fee module can't do reclassifications, only Internal Vouchers can

---

## 📊 Comparison Table

| Feature | Method 1: Automatic | Method 2: Manual Fee | Method 3: Internal Voucher |
|---------|---------------------|---------------------|---------------------------|
| **Speed** | ⚡ Fastest | 🐢 Slow | 🐢 Slowest (approval needed) |
| **Tracked per member** | ✅ Yes | ✅ Yes | ❌ No (GL only) |
| **Linked to loan** | ✅ Yes | ⚠️ Via notes only | ⚠️ Via description only |
| **Automatic calculation** | ✅ Yes (%) | ❌ No | ❌ No |
| **Payment tracking** | ✅ Yes | ✅ Yes | ❌ No (just entry) |
| **Prevents duplicates** | ✅ Yes | ⚠️ Manual check | ❌ Manual check |
| **Chairman approval** | ❌ No | ❌ No | ✅ Yes |
| **Backdating** | ❌ No | ❌ Limited | ✅ Yes |
| **Multi-line entries** | ❌ No | ❌ No | ✅ Yes |
| **Reclassifications** | ❌ No | ❌ No | ✅ Yes |
| **Best for** | Daily ops | One-offs | Corrections, complex |

---

## 🔍 Real-World Examples

### Example 1: Normal Day (Use Method 1)
**Situation:** Recording 5 loans today

**Process:**
```
9:00 AM — Record loan LNS-001 (Shs 1,000,000)
   └─ System charges Shs 30,000 processing fee (Pending)

9:15 AM — Record loan LNS-002 (Shs 500,000)
   └─ System charges Shs 15,000 processing fee (Pending)

... repeat for 3 more loans ...

2:00 PM — Cashier collects all 5 processing fees
   └─ Go to Fee Charges
   └─ Mark all 5 as "Paid" — Cash
   └─ Total income: Shs 100,000 posted to GL
```

**Result:** ✅ Fast, accurate, tracked

---

### Example 2: Missed Fee (Use Method 2 or 3)

**Situation:** Last week you recorded loan LNS-050 but forgot to configure the "Per Loan" fee, so nothing was charged.

**Method 2 Approach (Fee Module):**
```
1. Go to Fee Charges → Record Fee
2. Select member
3. Select "Loan Processing Fee (Manual)" — Shs 30,000
4. Mark as Paid (if already collected)
5. Note: "Processing fee for LNS-050"
```

**Method 3 Approach (Internal Voucher):**
```
1. Other Finance → Internal Vouchers → Create
2. Description: "Processing fee for loan LNS-050 — missed at recording"
3. Add lines:
   - Dr 1110 Cash at Hand: 30,000
   - Cr 4100 Loan Processing Fees: 30,000
4. Submit → Get Chairman approval → Post
```

**Which to use?**
- **Method 2** if you want it tracked in fee system
- **Method 3** if Chairman needs to approve corrections

---

### Example 3: Historical Import (Use Method 3)

**Situation:** Importing 50 old loans from 2024 paper records. All processing fees were collected back then.

**Process:**
```
1. Create Internal Voucher
2. Description: "Processing fees for 50 historical loans imported from 2024 records"
3. Single journal entry:
   - Dr 1110 Cash at Hand: 1,500,000
   - Cr 4100 Loan Processing Fees: 1,500,000
4. Date: 2024-12-31 (backdated)
5. Submit → Chairman reviews totals → Approve → Post
```

**Why Method 3:** 
- Bulk entry (not 50 individual charges)
- Backdating needed
- Chairman oversight for historical data
- No need for member-by-member tracking (already collected)

---

## ⚠️ Important Notes

### 1. All Three Methods Post to the Same GL Account
Regardless of method, the income ends up in **4100 Loan Processing Fees**.

**Income Statement will show:**
```
4100 Loan Processing Fees: Shs 500,000
  ├─ From automatic charging (Method 1): Shs 400,000
  ├─ From manual fee charges (Method 2): Shs 50,000
  └─ From internal vouchers (Method 3): Shs 50,000
```

### 2. Chairman Approval Only for Internal Vouchers
- **Methods 1 & 2:** No approval needed (routine collection)
- **Method 3:** Chairman must approve before posting

### 3. Audit Trail Differs
- **Method 1:** Full trail (who charged, who paid, when, payment method, linked loan)
- **Method 2:** Full trail (who charged, who paid, when, payment method, notes field)
- **Method 3:** Basic trail (who created, who approved, description field)

### 4. Reporting Differs
- **Methods 1 & 2:** Show in "Fee Collection Reports"
- **Method 3:** Only shows in "General Ledger" and "Trial Balance"

---

## ✅ Best Practice Recommendations

### Daily Operations:
**Use Method 1 (Automatic)** ✅
- Configure "Per Loan" fee once
- Let system auto-charge
- Cashier marks as paid when collected
- Fast, accurate, tracked

### One-Off Corrections:
**Use Method 2 (Manual Fee)** if you need member tracking  
**Use Method 3 (Internal Voucher)** if Chairman approval needed

### Bulk/Historical:
**Use Method 3 (Internal Voucher)** 🔧
- Single entry for multiple transactions
- Backdating allowed
- Chairman oversight

### Reclassifications/Adjustments:
**Use Method 3 (Internal Voucher)** 🔧
- Only method that can Dr/Cr income accounts
- Required for fixing misclassifications

---

## 🎯 Quick Answer Summary

**Q: Can we use Internal Vouchers to post the processing fee?**

**A: YES, absolutely!** 

**When:**
- ✅ Correcting past errors
- ✅ Backdating entries
- ✅ Bulk historical imports
- ✅ Reclassifications
- ✅ Any time Chairman approval is desired
- ✅ When member-specific tracking isn't needed

**But for normal daily operations:**
- Use **Method 1 (Automatic charging)** — faster, tracked, automated

**All three methods are valid.** Choose based on your use case:
- **Daily routine** → Method 1 (Automatic)
- **One-off manual** → Method 2 (Manual Fee)
- **Corrections/complex** → Method 3 (Internal Voucher)

---

**Document Version:** 1.0  
**Last Updated:** 2026-09-14
