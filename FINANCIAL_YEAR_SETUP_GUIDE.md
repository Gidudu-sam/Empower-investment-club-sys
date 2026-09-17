# 📅 FINANCIAL YEAR SETUP GUIDE

**Empower Investment Club Management System**

---

## ⚠️ CRITICAL: MUST BE COMPLETED IMMEDIATELY AFTER DEPLOYMENT

**This is the FIRST thing you must do after deploying to production!**

No financial transactions (savings, loans, repayments, fees, expenses) can be recorded until a Financial Year is created and active.

---

## 🎯 OVERVIEW

The Empower system operates on a **MAY-to-APRIL** financial year cycle:
- **Start:** May 1st
- **End:** April 30th of the following year
- **12 Monthly Periods:** May → June → July → ... → March → April

---

## 📋 STEP-BY-STEP INSTRUCTIONS

### STEP 1: Login as Administrator

1. Navigate to your production URL: `https://your-domain.com`
2. Login with administrator credentials
3. Verify you have "System Admin" or "Admin" role

---

### STEP 2: Navigate to Financial Year Management

**Path:** Settings → Financial Year Management

**URL:** `https://your-domain.com/index.php?page=financial-year`

---

### STEP 3: Create First Financial Year

Click **"Create New Financial Year"** button

#### Fill in the Form:

| Field | Value | Example |
|-------|-------|---------|
| **Year Name** | Format: YYYY/YYYY | `2026/2027` |
| **Start Date** | May 1st | `2026-05-01` |
| **End Date** | April 30th (next year) | `2027-04-30` |
| **Status** | Active | `Active` |
| **Description** | Optional notes | `First operational financial year` |

#### Important Rules:

✅ **DO:**
- Use May 1st as start date
- Use April 30th (next year) as end date
- Set status to "Active"
- Use clear naming convention (YYYY/YYYY)

❌ **DON'T:**
- Use any start date other than May 1st
- Create overlapping financial years
- Create multiple active years at once
- Use non-standard date ranges

---

### STEP 4: Verify Accounting Periods Created

After creating the financial year, the system automatically creates 12 monthly periods.

**Navigate to:** Accounting → Accounting Periods

**URL:** `https://your-domain.com/index.php?page=accounting-period`

#### Expected Periods:

1. **May 2026** (2026-05-01 to 2026-05-31) - OPEN
2. **June 2026** (2026-06-01 to 2026-06-30) - OPEN
3. **July 2026** (2026-07-01 to 2026-07-31) - OPEN
4. **August 2026** (2026-08-01 to 2026-08-31) - OPEN
5. **September 2026** (2026-09-01 to 2026-09-30) - OPEN
6. **October 2026** (2026-10-01 to 2026-10-31) - OPEN
7. **November 2026** (2026-11-01 to 2026-11-30) - OPEN
8. **December 2026** (2026-12-01 to 2026-12-31) - OPEN
9. **January 2027** (2027-01-01 to 2027-01-31) - OPEN
10. **February 2027** (2027-02-01 to 2027-02-28) - OPEN
11. **March 2027** (2027-03-01 to 2027-03-31) - OPEN
12. **April 2027** (2027-04-01 to 2027-04-30) - OPEN

All periods should be **OPEN** status initially.

---

### STEP 5: Verification Checklist

Before recording any transactions, verify:

- [ ] Financial Year is created and shows as "Active"
- [ ] 12 monthly periods are created (May → April)
- [ ] All periods show "OPEN" status
- [ ] Current date falls within the created financial year
- [ ] System dashboard shows the active financial year

---

## 📊 TESTING THE FINANCIAL YEAR

### Test Transaction Flow

After creating the financial year, test with a small transaction:

#### Test 1: Record a Small Savings Deposit

1. **Navigate to:** Members → [Select any member] → Savings
2. **Create transaction:**
   - Type: Deposit
   - Account: Member's Compulsory Savings
   - Amount: KES 100.00
   - Date: Today's date
   - Description: "Test transaction to verify financial year"

3. **Submit and verify:**
   - Transaction saves successfully
   - No error about "No active financial year"
   - Transaction date is validated against period

#### Test 2: Verify Journal Entry Created

1. **Navigate to:** Accounting → General Ledger
2. **Search for the test transaction**
3. **Verify:**
   - Journal entry was created
   - Entry has correct date (within current period)
   - Debit and Credit balance
   - Posted to correct accounts:
     - DR: Bank/Cash
     - CR: Member Savings - Compulsory

#### Test 3: Check Trial Balance

1. **Navigate to:** Accounting → Trial Balance
2. **Select:** Current period
3. **Verify:**
   - Report generates without errors
   - Shows the test transaction accounts
   - Debits = Credits (balanced)

---

## 🔄 PERIOD MANAGEMENT

### When to Close a Period

Close accounting periods monthly after:
- ✅ All transactions for the month are recorded
- ✅ Month-end reconciliation completed
- ✅ All reports reviewed and approved
- ✅ Any corrections/adjustments made

### How to Close a Period

1. **Navigate to:** Accounting → Accounting Periods
2. **Find the period** to close (e.g., "May 2026")
3. **Click:** "Close Period" button
4. **Confirm:** Closing action

**⚠️ WARNING:** Once closed, transactions cannot be posted to that period!

### Period Status Flow

```
OPEN → Can record transactions
  ↓
CLOSED → Cannot record new transactions (can still view)
  ↓
LOCKED → Fully locked (year-end processing)
```

---

## 📅 YEAR-END PROCEDURES

### When Financial Year Ends (April 30th)

#### STEP 1: Close All Periods
Close each period sequentially from May → April

#### STEP 2: Generate Year-End Reports
- Annual Income Statement
- Annual Balance Sheet
- Trial Balance (full year)
- Member statements
- Loan reports

#### STEP 3: Run Year-End Close Process
**Navigate to:** Accounting → Year-End Close

This will:
- Transfer profit/loss to retained earnings
- Close temporary accounts (Income, Expenses)
- Create opening balances for next year
- Lock the financial year

#### STEP 4: Create Next Financial Year
- Name: `2027/2028`
- Start: `2027-05-01`
- End: `2028-04-30`
- Status: Active

---

## 🚨 TROUBLESHOOTING

### Error: "No active financial year found"

**Cause:** No financial year is set to "Active" status

**Solution:**
1. Go to Settings → Financial Year Management
2. Find the current year
3. Click "Activate" button
4. Verify status changes to "Active"

### Error: "Transaction date outside active financial year"

**Cause:** Trying to post transaction with date outside May 1 - April 30 range

**Solution:**
1. Check transaction date
2. Ensure date is within: `2026-05-01` to `2027-04-30`
3. If backdating, ensure period is still OPEN

### Error: "Period is closed for the transaction date"

**Cause:** Trying to post to a closed accounting period

**Solution:**
1. Check if period needs to be reopened
2. If legitimate backdating: Reopen period temporarily
3. Post transaction
4. Re-close period
5. Document reason for reopening in audit log

### No Periods Created After Creating Financial Year

**Cause:** System error during automatic period creation

**Solution:**
1. Contact system administrator
2. Check database for period records:
   ```sql
   SELECT * FROM accounting_periods 
   WHERE financial_year_id = [your_year_id];
   ```
3. If no periods exist, may need to manually create or re-create financial year

---

## 📝 BEST PRACTICES

### DO:
✅ Create financial year immediately after deployment  
✅ Close periods monthly after reconciliation  
✅ Keep financial year aligned with fiscal calendar  
✅ Document all period reopenings  
✅ Run reports before closing periods  
✅ Backup database before year-end close  

### DON'T:
❌ Create multiple overlapping active years  
❌ Change financial year dates after transactions exist  
❌ Reopen closed periods without documentation  
❌ Delete financial years with transactions  
❌ Close periods before month-end reconciliation  
❌ Skip year-end closing procedures  

---

## 📞 SUPPORT

If you encounter issues during financial year setup:

1. **Check System Logs:**
   - Location: `app/logs/error.log`
   - Look for financial year or period errors

2. **Verify Database:**
   ```sql
   -- Check financial years
   SELECT * FROM financial_years ORDER BY start_date DESC;
   
   -- Check accounting periods
   SELECT * FROM accounting_periods ORDER BY start_date DESC;
   ```

3. **Contact System Administrator:**
   - Provide error messages
   - Include transaction details if applicable
   - Note the date and time of issue

---

## ✅ COMPLETION CHECKLIST

**After following this guide, you should have:**

- [x] One active financial year (2026/2027)
- [x] 12 open accounting periods (May 2026 → April 2027)
- [x] Tested with sample transaction
- [x] Verified journal entry creation
- [x] Confirmed trial balance works
- [x] System ready for operational use

**Next Steps:**
- Begin recording actual member transactions
- Establish monthly period closing schedule
- Train staff on period management
- Set calendar reminders for year-end procedures

---

**Financial Year Setup Status:** ⬜ PENDING / ⬜ IN PROGRESS / ⬜ COMPLETED

**Setup Completed By:** ____________________  
**Date:** ____________________  
**Verified By:** ____________________
