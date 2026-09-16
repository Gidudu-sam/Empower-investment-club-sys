# Empower Investment Club — Chart of Accounts Reference Guide
**Purpose:** Understanding which accounts to use for opening balances  
**Date:** 2026-09-12  
**For:** Treasurer, Accountant, Admin

---

## What This Guide Covers

This document explains:
1. **What each account is** (plain English explanation)
2. **When to use it** for opening balances
3. **Debit or Credit** (which column to use)
4. **Real-world examples** of what goes in each account

---

## Quick Reference: Debit vs Credit

**DEBIT (left side):** Assets and Expenses  
**CREDIT (right side):** Liabilities, Equity, and Income

**Opening balances rule:** Only use **Assets, Liabilities, and Equity** accounts. Never use Income or Expense accounts for opening balances (they start at zero each year).

---

## Complete Chart of Accounts (Organized by Type)

### 🏦 ASSETS (What the Club Owns) — **USE DEBIT**

#### **💵 Current Assets (Cash & Near-Cash)**

| Code | Account Name | What It Is | Example for Opening Balance |
|------|--------------|------------|----------------------------|
| **1110** | **Cash at Hand** | Physical cash in the office safe | Count cash: Shs 2,000,000 → Dr 1110: 2,000,000 |
| **1120** | **Mobile Money / Float** | Main mobile money account (parent) | Don't use parent; use 1121 or 1122 below |
| **1121** | ↳ MTN Mobile Money | MTN MoMo balance | Check MTN wallet: Shs 300,000 → Dr 1121: 300,000 |
| **1122** | ↳ Airtel Money | Airtel Money balance | Check Airtel wallet: Shs 200,000 → Dr 1122: 200,000 |
| **1130** | **Cash in Transit** | Money being moved (rare) | Usually zero unless cash is physically being transported |
| **1140** | **Bank Accounts** | Main bank account balance | Check bank statement: Shs 5,000,000 → Dr 1140: 5,000,000 |
| **1150** | **Debtors and Prepayments** | Money others owe the club | Rare; usually club collects cash upfront |
| **1160** | **Stock of Stationery** | Value of unused stationery | Count supplies: Shs 50,000 → Dr 1160: 50,000 |
| **1170** | **Staff Advances** | Money advanced to employees | Check records: Shs 100,000 → Dr 1170: 100,000 |
| **1180** | **Loans to Members** | 🔴 **CRITICAL** — Total loans outstanding | Calculate all unpaid loans: Shs 10,000,000 → Dr 1180: 10,000,000 |
| **1185** | **Allowance for Impairment on Loans** | Provision for bad loans (contra-asset) | Estimated uncollectable: Shs 500,000 → **Cr** 1185: 500,000 ⚠️ |
| **1190** | **Loan Reserve** | Reserved funds for loan security | If you maintain a reserve: Shs 300,000 → Dr 1190: 300,000 |

**⚠️ Note:** Account 1185 is a **contra-asset** — it's an asset account but uses **CREDIT** (opposite of normal). It reduces the net loans receivable.

#### **🏢 Non-Current Assets (Long-Term Assets)**

| Code | Account Name | What It Is | Example for Opening Balance |
|------|--------------|------------|----------------------------|
| **1010** | **Land** | Owned land | If club owns land: Shs 20,000,000 → Dr 1010: 20,000,000 |
| **1020** | **Computer and Accessories** | Computers, printers, laptops | Equipment value: Shs 2,000,000 → Dr 1020: 2,000,000 |
| **1030** | **Safes** | Physical safes | Safe value: Shs 500,000 → Dr 1030: 500,000 |
| **1040** | **Investments** | External investments (bonds, stocks) | Investment balance: Shs 3,000,000 → Dr 1040: 3,000,000 |
| **1050** | **Software** | Software licenses (if club owns) | Rarely used for small clubs |
| **1060** | **Furniture and Fittings** | Desks, chairs, cabinets | Furniture value: Shs 1,000,000 → Dr 1060: 1,000,000 |

---

### 📊 LIABILITIES (What the Club Owes) — **USE CREDIT**

| Code | Account Name | What It Is | Example for Opening Balance |
|------|--------------|------------|----------------------------|
| **2010** | **Creditors and Accruals** | Unpaid bills (rent, utilities) | Owe landlord Shs 200,000 → Cr 2010: 200,000 |
| **2020** | **Members' Savings** | Parent account for all savings | Don't use parent; use 2021 below or this for compulsory |
| **2021** | ↳ Members' Savings — Voluntary | Voluntary savings balances | Sum voluntary savings: Shs 5,000,000 → Cr 2021: 5,000,000 |
| | **(Use 2020 for Compulsory)** | Compulsory savings balances | Sum compulsory savings: Shs 7,000,000 → Cr 2020: 7,000,000 |
| **2030** | **Provision for Audit** | Money set aside for audit fees | If reserved: Shs 500,000 → Cr 2030: 500,000 |
| **2040** | **Provision for Board and Staff** | Reserved for salaries/allowances | If reserved: Shs 300,000 → Cr 2040: 300,000 |
| **2050** | **Provision for Dividends** | Reserved for dividend payments | If reserved: Shs 1,000,000 → Cr 2050: 1,000,000 |
| **2060** | **Provision for AGM** | Reserved for AGM expenses | If reserved: Shs 200,000 → Cr 2060: 200,000 |
| **2070** | **Loan Insurance Fund** | Insurance fund for loan defaults | If maintained: Shs 500,000 → Cr 2070: 500,000 |
| **2080** | **Fixed Deposits** | Fixed deposit liabilities | Sum FD balances: Shs 2,000,000 → Cr 2080: 2,000,000 |

---

### 💰 EQUITY (Club's Net Worth) — **USE CREDIT**

| Code | Account Name | What It Is | Example for Opening Balance |
|------|--------------|------------|----------------------------|
| **3010** | **Shares (Share Capital)** | 🔴 **CRITICAL** — Total member shares | Sum all member shares: Shs 3,000,000 → Cr 3010: 3,000,000 |
| **3020** | **Retained Earnings** | 🔴 **BALANCING ACCOUNT** — Accumulated profit | Use to balance the equation: Shs 2,500,000 → Cr 3020: 2,500,000 |
| **3030** | **Share Transfer Fund** | Reserved for share transfers | If maintained: Shs 100,000 → Cr 3030: 100,000 |
| **3040** | **Surplus / Deficit (Current Year)** | ❌ **DO NOT USE** — Current year profit | Always zero at year start (closes to Retained Earnings) |
| **3050** | **Education Fund** | Reserved for member education | If maintained: Shs 200,000 → Cr 3050: 200,000 |

**⚠️ Important:** Account 3020 (Retained Earnings) is your **"plug figure"** — the amount needed to make Assets = Liabilities + Equity balance.

---

### 📈 INCOME (Revenue) — **❌ NEVER USE FOR OPENING BALANCES**

Income accounts always start at **zero** at the beginning of each year. They accumulate during the year and close to Retained Earnings at year-end.

| Code | Account Name | Used For (During the Year) |
|------|--------------|----------------------------|
| **4010** | Sales of Pass Books | Selling pass books to members |
| **4020** | Commission on Savings | Commission earned |
| **4030** | Investment Income | Dividends, interest from investments |
| **4035** | **Loan Interest Income** | 🔴 **MOST USED** — Interest on member loans |
| **4040** | Commission on Mobile Money | MoMo transaction fees |
| **4050** | Agency Banking Income | Agent banking commissions |
| **4060** | Penalties | Late payment penalties |
| **4070** | Bank Interest Received | Bank account interest |
| **4080** | Refunds | Refunds received |
| **4090** | **Membership / Registration Fees** | 🔴 **MOST USED** — Member registration |
| **4100** | **Loan Processing Fees** | 🔴 **MOST USED** — Loan application fees |
| **4110** | **Annual Subscription Fees** | 🔴 **MOST USED** — Annual member fees |
| **4120** | Income from Write-offs | Bad debt recoveries |
| **4130** | Miscellaneous Income | Other income |
| **4140** | Account Activation Fees | Account activation charges |
| **4150** | Income from Sales of T-Shirts | T-shirt sales revenue |
| **4160** | Property Management Income | Property rental income |

---

### 💸 EXPENSES (Operating Costs) — **❌ NEVER USE FOR OPENING BALANCES**

Expense accounts always start at **zero** at the beginning of each year. They accumulate during the year and close to Retained Earnings at year-end.

| Code | Account Name | Used For (During the Year) |
|------|--------------|----------------------------|
| **5010** | Salaries | Employee salaries |
| **5020** | Staff Incentives | Bonuses, performance pay |
| **5030** | Staff Welfare | Staff welfare expenses |
| **5040** | Wages | Part-time/casual wages |
| **5050** | Transport | Travel, fuel, vehicle maintenance |
| **5060** | Income Tax Filing Fees | Tax consultant fees |
| **5070** | Telephone and Postage | Phone bills, postage |
| **5080** | Stationery | Office supplies |
| **5090** | Office Rent | Monthly rent payments |
| **5100** | Electricity Bills | Electricity expenses |
| **5110** | Computer Service and Software Maintenance | IT support, software fees |
| **5120** | Water | Water bills |
| **5130** | Office Cleaning Expense | Cleaning services |
| **5140** | Security Fees | Security guard costs |
| **5150** | Internet | Internet bills |
| **5160** | Office Expenses | General office costs |
| **5161** | ↳ Garbage | Garbage collection |
| **5162** | ↳ Refreshments | Tea, snacks for office |
| **5163** | ↳ Water Dispenser | Water dispenser costs |
| **5170** | Legal Fees | Lawyer fees |
| **5180** | Renovation | Office renovation |
| **5190** | Audit Fees | External audit costs |
| **5200** | Loan Recovery and Verification | Debt collection costs |
| **5210** | Financial Literacy Training | Member training programs |
| **5220** | Public Relations / Condolence / Wedding Contributions | Social contributions |
| **5230** | Consultation Fees | Consultant payments |
| **5240** | Marketing and Publicity | Advertising, marketing |
| **5250** | AGM Expenses | Annual general meeting costs |
| **5260** | Miscellaneous Expenses | Other expenses |
| **5270** | Board Expenses / Allowances | Board member allowances |
| **5280** | Supervisory Expenses | Supervisory committee costs |
| **5290** | Corporate Social Responsibility | CSR activities |
| **5300** | Bad Debt / Loan Impairment Provision Expense | Provision for bad loans (pairs with 1185) |

---

## Step-by-Step: How to Prepare Opening Balances

### Step 1: Gather Real Financial Data

**ASSETS (What you own):**
1. **Count physical cash** → goes in 1110
2. **Check MTN MoMo balance** → goes in 1121
3. **Check Airtel Money balance** → goes in 1122
4. **Check bank statement** → goes in 1140
5. **Calculate total loans outstanding** → goes in 1180
   - List every member with an unpaid loan
   - Add up all remaining balances
6. **Value any equipment/property** → goes in 1020, 1030, 1060, etc.

**LIABILITIES (What you owe):**
7. **Calculate total member savings** → goes in 2020 (compulsory) or 2021 (voluntary)
   - List every member's savings balance
   - Add them all up
8. **Calculate fixed deposit balances** → goes in 2080
9. **List any unpaid bills** → goes in 2010

**EQUITY (Club's net worth):**
10. **Calculate total member shares** → goes in 3010
11. **Calculate Retained Earnings** → goes in 3020 (use this to balance)

### Step 2: Use the Accounting Equation

**Assets = Liabilities + Equity**

Example:
```
Assets Total:     Shs 17,500,000
Liabilities Total: Shs 12,000,000
Equity Needed:     Shs  5,500,000 (to balance)
```

If your Liabilities + Equity don't equal Assets, adjust **3020 Retained Earnings** to make them balance.

### Step 3: Enter in System

Go to **Accounting → Opening Balances → Prepare Batch**

**For each ASSET account:**
- Select account (e.g., 1110 Cash at Hand)
- Enter amount in **Debit** column
- Leave Credit blank

**For each LIABILITY account:**
- Select account (e.g., 2020 Members' Savings)
- Enter amount in **Credit** column
- Leave Debit blank

**For each EQUITY account:**
- Select account (e.g., 3010 Shares)
- Enter amount in **Credit** column
- Leave Debit blank

System will validate: **Total Debits = Total Credits**

---

## Common Scenarios & Examples

### Scenario 1: Small Club (Just Starting)

```
ASSETS (Debit):
1110 Cash at Hand:         Shs    500,000
1140 Bank Account:         Shs  1,000,000
1180 Loans to Members:     Shs  2,000,000
Total Assets:              Shs  3,500,000

LIABILITIES (Credit):
2020 Members' Savings:     Shs  2,000,000
Total Liabilities:         Shs  2,000,000

EQUITY (Credit):
3010 Shares:               Shs  1,000,000
3020 Retained Earnings:    Shs    500,000
Total Equity:              Shs  1,500,000

CHECK: 3,500,000 = 2,000,000 + 1,500,000 ✓
```

### Scenario 2: Established Club (Multiple Account Types)

```
ASSETS (Debit):
1110 Cash at Hand:         Shs  2,000,000
1121 MTN MoMo:             Shs    300,000
1122 Airtel Money:         Shs    200,000
1140 Bank Account:         Shs  5,000,000
1180 Loans to Members:     Shs 15,000,000
1185 Loan Impairment:      Shs   (500,000) ← CREDIT not debit!
1020 Computers:            Shs  1,000,000
Total Assets:              Shs 23,000,000

LIABILITIES (Credit):
2020 Compulsory Savings:   Shs 10,000,000
2021 Voluntary Savings:    Shs  3,000,000
2080 Fixed Deposits:       Shs  2,000,000
Total Liabilities:         Shs 15,000,000

EQUITY (Credit):
3010 Shares:               Shs  5,000,000
3020 Retained Earnings:    Shs  3,000,000
Total Equity:              Shs  8,000,000

CHECK: 23,000,000 = 15,000,000 + 8,000,000 ✓
```

### Scenario 3: Club with Provisions (Reserved Funds)

```
ASSETS (Debit):
1110 Cash at Hand:         Shs  3,000,000
1140 Bank Account:         Shs  7,000,000
1180 Loans to Members:     Shs 12,000,000
Total Assets:              Shs 22,000,000

LIABILITIES (Credit):
2020 Members' Savings:     Shs 12,000,000
2030 Provision for Audit:  Shs    500,000
2040 Provision for Board:  Shs    300,000
2050 Provision for Dividends: Shs 1,000,000
Total Liabilities:         Shs 13,800,000

EQUITY (Credit):
3010 Shares:               Shs  4,000,000
3020 Retained Earnings:    Shs  4,200,000
Total Equity:              Shs  8,200,000

CHECK: 22,000,000 = 13,800,000 + 8,200,000 ✓
```

---

## Most Common Opening Balance Accounts (Priority List)

**If you're overwhelmed, start with these 6 accounts — they cover 90% of clubs:**

| Priority | Code | Account | What to Enter |
|----------|------|---------|---------------|
| 1️⃣ | **1110** | Cash at Hand | Count physical cash (**Debit**) |
| 2️⃣ | **1140** | Bank Account | Check bank statement (**Debit**) |
| 3️⃣ | **1180** | Loans to Members | Sum all outstanding loans (**Debit**) |
| 4️⃣ | **2020** | Members' Savings | Sum all member savings (**Credit**) |
| 5️⃣ | **3010** | Shares | Sum all member shares (**Credit**) |
| 6️⃣ | **3020** | Retained Earnings | Balancing figure (**Credit**) |

**Simple formula:**
```
Retained Earnings = (Cash + Bank + Loans) - (Savings + Shares)
```

---

## Troubleshooting

### Problem: "My debits don't equal my credits"

**Solution:** Adjust **3020 Retained Earnings** to make them balance.

Example:
```
Total Debits:  17,500,000
Total Credits: 15,000,000 (Savings 12M + Shares 3M)
Difference:     2,500,000

→ Credit 3020 Retained Earnings: 2,500,000
→ Now: 17,500,000 = 17,500,000 ✓
```

### Problem: "I don't know our Retained Earnings amount"

**Answer:** You don't need to! Retained Earnings is a **calculated figure**:

**Retained Earnings = Assets - Liabilities - Share Capital**

It represents all the club's historical profit that hasn't been distributed.

### Problem: "Should I include Mobile Money in Cash at Hand?"

**No!** Use separate accounts:
- **1110** = Physical cash only
- **1121** = MTN MoMo
- **1122** = Airtel Money
- **1140** = Bank account

This keeps them trackable individually.

### Problem: "What if we have no loans outstanding?"

**Answer:** Then **don't use account 1180**. Only include accounts with non-zero balances.

---

## Validation Checklist

Before submitting your opening balance batch, verify:

- [ ] Total Debits = Total Credits (system enforces this)
- [ ] Assets = Liabilities + Equity
- [ ] Cash count matches physical count
- [ ] Bank balance matches statement
- [ ] Total loans matches member ledgers
- [ ] Total savings matches member ledgers
- [ ] No Income or Expense accounts used (they should be zero)
- [ ] All amounts are positive (no negative numbers)
- [ ] Retained Earnings calculated as balancing figure

---

## Need Help?

**Can't find an account?**
- View full chart: **Settings → Chart of Accounts**
- Or: **Accounting → Chart of Accounts**

**Need to create a new account?**
- Go to: **Chart of Accounts → Create Account**
- (Admin or Treasurer role required)

**Still confused?**
- Read: `ACCOUNTING_SETUP_GUIDE_FOR_TEAM.md` (full guide)
- Read: `ACCOUNTING_SETUP_QUICK_SUMMARY.md` (quick reference)
- Contact: Your system administrator or accountant

---

**This guide is your reference** when preparing opening balances. Print it and keep it handy during your setup session!

**Document Version:** 1.0  
**Last Updated:** 2026-09-12
