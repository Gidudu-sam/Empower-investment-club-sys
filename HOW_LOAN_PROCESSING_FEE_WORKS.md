# How Loan Processing Fee Works — Quick Guide

**Question:** How is the loan processing fee recorded? Where can I record it from?

---

## ✅ Answer: It's **Automatic** — No Manual Recording Needed!

The loan processing fee is **automatically charged** when a loan is recorded. You don't need to manually record it anywhere — the system does it for you.

---

## 📋 How It Works (Step-by-Step)

### Step 1: Configure the Processing Fee (One-Time Setup)

**Navigation:** Settings → Fees & Charges

**What to do:**
1. Click **"Add New Fee"**
2. Fill in:
   - **Fee Name:** "Loan Processing Fee" (or any name you want)
   - **Fee Type:** Fixed or Percentage
   - **Amount:** 
     - Fixed: e.g., Shs 10,000 (flat fee per loan)
     - Percentage: e.g., 2% (2% of loan amount)
   - **Frequency:** **"Per Loan"** ← This is critical!
   - **Effective Date:** When it starts applying
   - **Status:** Active
3. **Save**

**Example Configuration:**
```
Fee Name:       Loan Processing Fee
Fee Type:       Percentage
Amount:         2
Frequency:      Per Loan ← Makes it auto-charge
GL Account:     4100 — Loan Processing Fees
Status:         Active
```

---

### Step 2: Record a Loan (Normal Loan Recording)

**Navigation:** Loans → Add New Loan

**What happens automatically:**

1. **You record the loan** (amount, member, interest rate, etc.)
2. **System saves the loan** as draft
3. **System automatically:**
   - Finds the fee with frequency = "Per Loan"
   - Calculates the fee:
     - Fixed fee: Uses exact amount (e.g., Shs 10,000)
     - Percentage fee: Calculates from loan amount (e.g., 2% of Shs 1,000,000 = Shs 20,000)
   - **Creates a fee charge** for the member
   - Links it to the loan
   - Status: "Pending" (awaiting payment)
   - **Sends notification** to Cashier/Treasurer

**You see:**
```
✓ Loan LNS-000123 saved as a draft. Submit it for approval when ready.
```

**Behind the scenes:**
```
✓ Loan created: LNS-000123
✓ Processing fee charged: Shs 20,000 (pending payment)
✓ Notification sent to Cashier
```

---

### Step 3: Collect the Processing Fee

**Navigation:** Fees & Charges → Fee Charges

**What to do:**

1. **Find the pending fee** (shows in the list with Status: "Pending")
2. Click **"Mark Paid"**
3. Select **Payment Method:**
   - Cash
   - MTN Mobile Money
   - Airtel Money
   - Bank Transfer
4. Click **"Confirm Payment"**

**What happens when marked paid:**
- Fee status changes to "Paid"
- **GL journal entry auto-posts:**
  ```
  Dr 1110 Cash at Hand (or 1121/1122/1140 depending on method)
  Cr 4100 Loan Processing Fees
  ```
- Income is recorded automatically
- Trial Balance updates
- Income Statement shows the income

---

## 🔍 Where to View Processing Fees

### View All Fees & Charges
**Navigation:** Fees & Charges → Fee Charges

**Filter by:**
- **Fee Type:** Select "Loan Processing Fee" from dropdown
- **Status:** Pending / Paid / Waived
- **Member:** Search specific member
- **Date Range:** Filter by date

### View Fees for a Specific Loan
**Navigation:** Loans → View Loan → (scroll down to "Associated Fees")

Shows all fees charged for that loan.

---

## 💰 Accounting Flow

### When Fee is Charged (Automatic)
**No GL entry yet** — just records the charge as "Pending"

### When Fee is Paid (Manual — Mark Paid)
**GL Entry Auto-Posted:**

```
Dr 1110 Cash at Hand           20,000    (if paid in cash)
   Cr 4100 Loan Processing Fees      20,000

OR

Dr 1121 MTN MoMo               20,000    (if paid via MTN)
   Cr 4100 Loan Processing Fees      20,000
```

**Result:**
- ✅ Cash/Bank/MoMo increases (asset)
- ✅ Income increases (revenue)
- ✅ Appears on Income Statement
- ✅ Member's fee status = "Paid"

---

## 📊 Important Notes

### 1. One Fee Per Loan
The system **prevents duplicate charges** — if a processing fee has already been charged for a loan, it won't charge again.

### 2. Fee Must Be Configured with "Per Loan" Frequency
If no fee exists with `frequency = 'per_loan'`, **no fee is charged automatically**.

Check: Settings → Fees & Charges → ensure one fee has Frequency = "Per Loan"

### 3. Fee is Charged at Loan Creation, Not Disbursement
- **Charged:** When loan is recorded (draft stage)
- **Can be paid:** Anytime (even before loan is approved/disbursed)
- **Common practice:** Collect before disbursing the loan

### 4. Fee Can Be Waived
If approved by Admin/Treasurer:
- Go to: Fees & Charges → Find the charge
- Click **"Mark Waived"**
- Enter reason
- No income recorded (fee is written off)

---

## 🎯 Common Scenarios

### Scenario 1: Fixed Fee (Shs 10,000 per loan)

**Setup:**
```
Fee Name: Loan Processing Fee
Type: Fixed
Amount: 10,000
Frequency: Per Loan
```

**Example:**
- Loan Amount: Shs 500,000
- **Processing Fee Charged: Shs 10,000** (fixed, regardless of loan size)

---

### Scenario 2: Percentage Fee (2% of loan amount)

**Setup:**
```
Fee Name: Loan Processing Fee
Type: Percentage
Amount: 2
Frequency: Per Loan
```

**Example:**
- Loan Amount: Shs 1,000,000
- **Processing Fee Charged: Shs 20,000** (2% of 1,000,000)

---

### Scenario 3: No Processing Fee

**Setup:**
- No fee configured with Frequency = "Per Loan"
- OR fee is set to Inactive

**Result:**
- Loan is recorded normally
- **No fee is charged**
- No notification sent

---

## ✅ Summary

| Question | Answer |
|----------|--------|
| **Where do I record processing fee?** | You don't — it's automatic when loan is recorded |
| **How do I set it up?** | Settings → Fees & Charges → Add fee with Frequency = "Per Loan" |
| **When is it charged?** | Automatically when loan is saved (draft stage) |
| **How do I collect it?** | Fees & Charges → Find pending fee → Mark Paid |
| **When does GL posting happen?** | When you mark the fee as "Paid" |
| **What if I don't want to charge it?** | Delete/deactivate the "Per Loan" fee, or waive individual charges |

---

## 🔧 Troubleshooting

### Problem: "Processing fee is not being charged automatically"

**Checklist:**
- [ ] Go to Settings → Fees & Charges
- [ ] Check if a fee exists with Frequency = "Per Loan"
- [ ] Check if that fee is Active (not disabled)
- [ ] Check if the fee has a valid amount (not zero)

**Fix:** Create or activate a fee with Frequency = "Per Loan"

---

### Problem: "I charged the fee but income isn't showing in reports"

**Reason:** Fee is still "Pending" — not yet paid.

**Fix:** Go to Fees & Charges → Find the fee → Click "Mark Paid"

Only when marked paid does the GL entry post and income get recorded.

---

### Problem: "Fee was charged twice for the same loan"

**This shouldn't happen** — system prevents duplicates.

**If it did happen:**
1. Check: Fees & Charges → Filter by that loan
2. If duplicate exists, mark one as "Waived" with reason "Duplicate charge"

---

## 📞 Need Help?

**View all fees:** Fees & Charges → Fee Charges  
**Configure fees:** Settings → Fees & Charges  
**View income:** Reports → Income Statement  
**Check GL:** Reports → Trial Balance (account 4100)

---

**Key Takeaway:** You don't manually record loan processing fees. Just configure one fee with Frequency = "Per Loan", and the system auto-charges it whenever a loan is recorded. You only need to mark it as "Paid" when the member pays!

**Document Version:** 1.0  
**Last Updated:** 2026-09-14
