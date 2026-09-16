# Balance Sheet Setup — Visual Flowchart

```
┌────────────────────────────────────────────────────────────────┐
│                     BALANCE SHEET SETUP                         │
│              Why You Can't See It Yet (And How to Fix)          │
└────────────────────────────────────────────────────────────────┘

                              START
                                │
                                ▼
                    ┌───────────────────────┐
                    │  Want to view         │
                    │  Balance Sheet?       │
                    └───────────┬───────────┘
                                │
                                ▼
                    ┌───────────────────────┐
                    │  System checks:       │
                    │  1. Financial Year?   │
                    │  2. Accounting Period?│
                    │  3. Opening Balances? │
                    └───────────┬───────────┘
                                │
                ┌───────────────┴────────────────┐
                │                                │
         ❌ Any missing?                  ✅ All present?
                │                                │
                ▼                                ▼
    ┌───────────────────────┐       ┌───────────────────────┐
    │  Shows warning:       │       │  Shows Balance Sheet  │
    │  "Setup required"     │       │  with real data       │
    └───────────┬───────────┘       └───────────────────────┘
                │                              END ✓
                │
                ▼
         DO THIS SETUP ▼

┌────────────────────────────────────────────────────────────────┐
│                         STEP 1 OF 3                             │
│                   CREATE FINANCIAL YEAR                         │
└────────────────────────────────────────────────────────────────┘

    Navigation: Settings → Financial Years → Create New
    
    ┌─────────────────────────────────────────────┐
    │  Fill in:                                   │
    │  • Name: "Financial Year 2026"              │
    │  • Start Date: 2026-01-01                   │
    │  • End Date: 2026-12-31                     │
    │  • Status: Active                           │
    └─────────────────┬───────────────────────────┘
                      │
                      ▼
    ┌─────────────────────────────────────────────┐
    │  Click "Save"                               │
    └─────────────────┬───────────────────────────┘
                      │
                      ▼
                 ✓ Step 1 Done (5 minutes)


┌────────────────────────────────────────────────────────────────┐
│                         STEP 2 OF 3                             │
│                  CREATE ACCOUNTING PERIODS                      │
└────────────────────────────────────────────────────────────────┘

    Navigation: Accounting → Accounting Periods → Create Period
    
    Create 12 monthly periods:
    
    ┌────────────────┬──────────────┬────────────┐
    │ Period Name    │ Start Date   │ End Date   │
    ├────────────────┼──────────────┼────────────┤
    │ January 2026   │ 2026-01-01   │ 2026-01-31 │
    │ February 2026  │ 2026-02-01   │ 2026-02-28 │
    │ March 2026     │ 2026-03-01   │ 2026-03-31 │
    │ ...            │ ...          │ ...        │
    │ December 2026  │ 2026-12-01   │ 2026-12-31 │
    └────────────────┴──────────────┴────────────┘
                      │
                      ▼
                 ✓ Step 2 Done (15 minutes)


┌────────────────────────────────────────────────────────────────┐
│                         STEP 3 OF 3                             │
│                  PREPARE OPENING BALANCES                       │
│                  (Most Important Step)                          │
└────────────────────────────────────────────────────────────────┘

    Navigation: Accounting → Opening Balances → Prepare Batch

    ┌─────────────────────────────────────────────────────┐
    │  3A: GATHER DATA (Before entering in system)       │
    └─────────────────────────────────────────────────────┘
    
    Treasurer collects:
    
    ┌─────────────────────────────────────────────────────┐
    │  ASSETS (What club owns):                           │
    │  □ Count cash in hand                               │
    │  □ Check bank statement                             │
    │  □ Check mobile money balance                       │
    │  □ Calculate total loans to members                 │
    └─────────────────────────────────────────────────────┘
                      │
                      ▼
    ┌─────────────────────────────────────────────────────┐
    │  LIABILITIES (What club owes):                      │
    │  □ Calculate total member savings                   │
    │  □ Any external loans                               │
    └─────────────────────────────────────────────────────┘
                      │
                      ▼
    ┌─────────────────────────────────────────────────────┐
    │  EQUITY (Club net worth):                           │
    │  □ Member shares                                    │
    │  □ Retained earnings                                │
    └─────────────────────────────────────────────────────┘
                      │
                      ▼
    ┌─────────────────────────────────────────────────────┐
    │  CHECK: Assets = Liabilities + Equity?              │
    │         (Must balance!)                             │
    └──────────────┬──────────────────────────────────────┘
                   │
          ┌────────┴────────┐
          │                 │
      ❌ No           ✅ Yes
          │                 │
          │                 ▼
          │    ┌─────────────────────────────────────┐
          │    │  3B: ENTER IN SYSTEM                │
          │    └─────────────────────────────────────┘
          │                 │
          │                 ▼
          │    Go to: Opening Balances → Prepare Batch
          │                 │
          │                 ▼
          │    ┌─────────────────────────────────────┐
          │    │ Fill in:                            │
          │    │ • Batch Number: OB-2026-001         │
          │    │ • Financial Year: FY 2026           │
          │    │ • Period: January 2026              │
          │    │ • As Of Date: 2026-01-01            │
          │    └──────────────┬──────────────────────┘
          │                   │
          │                   ▼
          │    ┌─────────────────────────────────────┐
          │    │ Add lines for each account:         │
          │    │                                     │
          │    │ Cash (1110)      Debit:  2,000,000 │
          │    │ Bank (1140)      Debit:  5,000,000 │
          │    │ Loans (1210)     Debit: 10,000,000 │
          │    │ Savings (2110)   Credit:12,000,000 │
          │    │ Shares (3110)    Credit: 3,000,000 │
          │    │ Ret. Earn (3200) Credit: 2,000,000 │
          │    └──────────────┬──────────────────────┘
          │                   │
          │                   ▼
          │    ┌─────────────────────────────────────┐
          │    │ System validates:                   │
          │    │ Total Debits = Total Credits?       │
          │    └──────────────┬──────────────────────┘
          │                   │
          │          ┌────────┴────────┐
          │          │                 │
          │      ❌ No             ✅ Yes
          │          │                 │
          └──────────┘                 ▼
       (Review your             Save as Draft
        calculations)                  │
                                      ▼
                      ┌───────────────────────────────┐
                      │  3C: SUBMIT FOR APPROVAL      │
                      └───────────────┬───────────────┘
                                      │
                                      ▼
                      Treasurer clicks "Submit for Approval"
                                      │
                                      ▼
                      ┌───────────────────────────────┐
                      │  3D: CHAIRMAN REVIEWS         │
                      └───────────────┬───────────────┘
                                      │
                                      ▼
              Chairman checks amounts against physical records
                                      │
                          ┌───────────┴───────────┐
                          │                       │
                    ✅ Approve            ❌ Reject
                          │                       │
                          ▼                       ▼
          ┌───────────────────────┐   ┌──────────────────┐
          │  Status: Approved     │   │  Back to Draft   │
          │  (Not Posted Yet)     │   │  Treasurer fixes │
          └───────────┬───────────┘   └──────────────────┘
                      │
                      ▼
          ┌───────────────────────────────────┐
          │  3E: POST TO LEDGER               │
          └───────────┬───────────────────────┘
                      │
                      ▼
          Admin/Treasurer clicks "Post"
                      │
                      ▼
          ┌───────────────────────────────────┐
          │  Opening balances now LOCKED      │
          │  and reflected in all reports     │
          └───────────┬───────────────────────┘
                      │
                      ▼
                 ✓ Step 3 Done (1-3 hours)


┌────────────────────────────────────────────────────────────────┐
│                        🎉 SETUP COMPLETE!                       │
└────────────────────────────────────────────────────────────────┘

                      ▼
    ┌─────────────────────────────────────────────┐
    │  Now you can:                               │
    │  ✅ View Balance Sheet                      │
    │  ✅ View Income Statement                   │
    │  ✅ Run Trial Balance                       │
    │  ✅ Generate all accounting reports         │
    │  ✅ Record daily transactions               │
    │  ✅ Close periods month-by-month            │
    └─────────────────────────────────────────────┘


┌────────────────────────────────────────────────────────────────┐
│                         KEY REMINDERS                           │
└────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  ⚠️  IMPORTANT RULES                                         │
│                                                              │
│  1. Opening balances MUST balance:                          │
│     Total Debits = Total Credits                            │
│     Assets = Liabilities + Equity                           │
│                                                              │
│  2. Same person CANNOT prepare AND approve                  │
│     (Security control — maker-checker)                      │
│                                                              │
│  3. Once posted, opening balances are LOCKED                │
│     (Can't edit — get it right before posting!)             │
│                                                              │
│  4. This is a ONE-TIME setup per financial year             │
│     (Subsequent years mostly automated)                     │
└─────────────────────────────────────────────────────────────┘


┌────────────────────────────────────────────────────────────────┐
│                      ROLES & PERMISSIONS                        │
└────────────────────────────────────────────────────────────────┘

┌──────────────────────────┬─────────┬──────────┬──────────┐
│ Action                   │ Admin   │Treasurer │ Chairman │
├──────────────────────────┼─────────┼──────────┼──────────┤
│ Create Financial Year    │   ✅    │    ✅    │    ❌    │
│ Create Periods           │   ✅    │    ✅    │    ❌    │
│ Prepare Opening Balance  │   ✅    │    ✅    │    ❌    │
│ Approve Opening Balance  │   ✅    │    ❌    │    ✅    │
│ Post Opening Balance     │   ✅    │    ✅    │    ❌    │
│ View Balance Sheet       │   ✅    │    ✅    │    ✅    │
└──────────────────────────┴─────────┴──────────┴──────────┘


┌────────────────────────────────────────────────────────────────┐
│                      TIME BREAKDOWN                             │
└────────────────────────────────────────────────────────────────┘

Step 1: Financial Year     ■■■■■ (5 min)
Step 2: Accounting Periods ■■■■■■■■■ (15 min)
Step 3: Opening Balances   ■■■■■■■■■■■■■■■■■■■■■■■■■■ (1-3 hours)
                           └────────────────────────────────┘
                           Total: 2-4 hours (one-time setup)


┌────────────────────────────────────────────────────────────────┐
│                    TROUBLESHOOTING                              │
└────────────────────────────────────────────────────────────────┘

Problem: "Debits don't equal Credits"
└─▶ Solution: 
    • Review your manual calculations
    • Use Retained Earnings account to absorb difference
    • Check: Assets = Liabilities + Equity

Problem: "Can't approve my own opening balance batch"
└─▶ Solution: 
    • Get Chairman or different Admin to approve
    • Security control — prevents fraud

Problem: "Made mistake after posting"
└─▶ Solution: 
    • Create correcting journal entry (Internal Voucher)
    • Or reopen financial year (if no transactions yet)

Problem: "Don't know what our opening balances are"
└─▶ Solution: 
    • Count physical cash
    • Check bank statement
    • Review member savings records
    • Calculate loans receivable
    • Consult club accountant


┌────────────────────────────────────────────────────────────────┐
│                      WHAT YOU'LL SEE                            │
└────────────────────────────────────────────────────────────────┘

BEFORE SETUP:
┌──────────────────────────────────────────────────────┐
│ ⚠️ Opening balances have not been established        │
│                                                      │
│ A Balance Sheet cannot be presented until opening   │
│ balances are prepared, approved and posted.         │
│                                                      │
│ [Prepare Opening Balances] [View Batches]           │
└──────────────────────────────────────────────────────┘

AFTER SETUP:
┌──────────────────────────────────────────────────────┐
│              BALANCE SHEET                           │
│        As of December 31, 2026                       │
│                                                      │
│  ASSETS                                              │
│  Cash at Hand          Shs  2,000,000                │
│  Bank Account          Shs  5,000,000                │
│  Loans Receivable      Shs 10,000,000                │
│  Total Assets          Shs 17,000,000                │
│                                                      │
│  LIABILITIES                                         │
│  Member Savings        Shs 12,000,000                │
│                                                      │
│  EQUITY                                              │
│  Member Shares         Shs  3,000,000                │
│  Retained Earnings     Shs  2,000,000                │
│                                                      │
│  Assets = Liabilities + Equity ✓ (Balanced)         │
└──────────────────────────────────────────────────────┘


┌────────────────────────────────────────────────────────────────┐
│                   📚 MORE INFORMATION                           │
└────────────────────────────────────────────────────────────────┘

Quick Summary (1 page):
└─▶ ACCOUNTING_SETUP_QUICK_SUMMARY.md

Full Team Guide (20 pages):
└─▶ ACCOUNTING_SETUP_GUIDE_FOR_TEAM.md

This Flowchart (visual):
└─▶ ACCOUNTING_SETUP_FLOWCHART.md


┌────────────────────────────────────────────────────────────────┐
│              ✅ READY TO START? GO TO:                          │
│         Settings → Financial Years → Create New                 │
└────────────────────────────────────────────────────────────────┘
```
