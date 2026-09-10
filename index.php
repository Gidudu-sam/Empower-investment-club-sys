<?php
/**
 * Front Controller — single entry point for the application.
 */

// Bootstrap
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/config/push.php';
require_once __DIR__ . '/app/config/mail.php';
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php'; // Stage 12-D: minishlink/web-push; also phpmailer/phpmailer
}
require_once __DIR__ . '/core/Autoloader.php';

Session::start();

// ---------------------------------------------------------------
// Simple page-based router
// Map ?page=xxx  to  ControllerName@method
// ---------------------------------------------------------------
$routes = [
    ''               => ['AuthController',      'login'],
    'login'          => ['AuthController',      'login'],
    'logout'         => ['AuthController',      'logout'],
    'dashboard'      => ['DashboardController', 'index'],
    'dashboard-deposit-analytics' => ['DashboardController', 'depositAnalyticsData'],
    // Member Portal module (Stage 14-B) -- every action derives its
    // member identity exclusively from Session::requireMember(), never
    // from a route/query parameter.
    'portal-home'             => ['MemberPortalController', 'home'],
    'portal-profile'          => ['MemberPortalController', 'profile'],
    'portal-savings'          => ['MemberPortalController', 'savings'],
    'portal-loans'            => ['MemberPortalController', 'loans'],
    'portal-loan-view'        => ['MemberPortalController', 'loanView'],
    'portal-repayments'       => ['MemberPortalController', 'repayments'],
    'portal-fees'             => ['MemberPortalController', 'fees'],
    'portal-statement'        => ['MemberPortalController', 'statement'],
    'portal-statement-print'  => ['MemberPortalController', 'statementPrint'],
    'portal-statement-email'  => ['MemberPortalController', 'emailStatement'],
    'portal-change-password'  => ['MemberPortalController', 'changePassword'],
    // Members module
    'members'        => ['MemberController',    'index'],
    'member-add'     => ['MemberController',    'add'],
    'member-edit'    => ['MemberController',    'edit'],
    'member-view'    => ['MemberController',    'view'],
    'member-delete'  => ['MemberController',    'delete'],
    'member-toggle'  => ['MemberController',    'toggleStatus'],
    // Member Import
    'member-import'          => ['MemberImportController', 'index'],
    'member-import-preview'  => ['MemberImportController', 'preview'],
    'member-import-process'  => ['MemberImportController', 'process'],
    'member-import-template' => ['MemberImportController', 'template'],
    'member-import-template-download' => ['MemberImportController', 'templateDirect'],
    // Placeholder modules (not yet built)
    'contributions'          => ['ComingSoonController', 'index'],
    'expenses'               => ['ExpenseController',    'index'],
    'audit'                  => ['ComingSoonController', 'index'],
    // Investments module
    'investments'                     => ['InvestmentController', 'index'],
    'investment-create'               => ['InvestmentController', 'create'],
    'investment-store'                => ['InvestmentController', 'store'],
    'investment-view'                 => ['InvestmentController', 'view'],
    'investment-submit'               => ['InvestmentController', 'submit'],
    'investment-approve'              => ['InvestmentController', 'approve'],
    'investment-reject'               => ['InvestmentController', 'reject'],
    'investment-post'                 => ['InvestmentController', 'post'],
    'investment-transaction-create'   => ['InvestmentController', 'transactionCreate'],
    'investment-transaction-store'    => ['InvestmentController', 'transactionStore'],
    'investment-types'                => ['InvestmentController', 'types'],
    'investment-type-store'           => ['InvestmentController', 'typeStore'],
    'investment-type-toggle'          => ['InvestmentController', 'typeToggle'],
    // Internal Vouchers module
    'internal-vouchers'          => ['InternalVoucherController', 'index'],
    'internal-voucher-create'    => ['InternalVoucherController', 'create'],
    'internal-voucher-store'     => ['InternalVoucherController', 'store'],
    'internal-voucher-view'      => ['InternalVoucherController', 'view'],
    'internal-voucher-submit'    => ['InternalVoucherController', 'submit'],
    'internal-voucher-approve'   => ['InternalVoucherController', 'approve'],
    'internal-voucher-reject'    => ['InternalVoucherController', 'reject'],
    'internal-voucher-post'      => ['InternalVoucherController', 'post'],
    'internal-voucher-print'     => ['InternalVoucherController', 'print'],
    'internal-voucher-member-search'   => ['InternalVoucherController', 'memberSearch'],
    'internal-voucher-member-accounts' => ['InternalVoucherController', 'memberAccounts'],
    // Member Account Adjustments module
    'member-adjustments'            => ['MemberAccountAdjustmentController', 'index'],
    'member-adjustment-create'      => ['MemberAccountAdjustmentController', 'create'],
    'member-adjustment-store'       => ['MemberAccountAdjustmentController', 'store'],
    'member-adjustment-view'        => ['MemberAccountAdjustmentController', 'view'],
    'member-adjustment-submit'      => ['MemberAccountAdjustmentController', 'submit'],
    'member-adjustment-approve'     => ['MemberAccountAdjustmentController', 'approve'],
    'member-adjustment-reject'      => ['MemberAccountAdjustmentController', 'reject'],
    'member-adjustment-post'        => ['MemberAccountAdjustmentController', 'post'],
    'member-adjustment-reverse'     => ['MemberAccountAdjustmentController', 'reverse'],
    'member-adjustment-member-search'   => ['MemberAccountAdjustmentController', 'memberSearch'],
    'member-adjustment-member-accounts' => ['MemberAccountAdjustmentController', 'memberAccounts'],
    // Referral Commissions & Bonuses module
    'referral-bonuses'              => ['ReferralBonusController', 'index'],
    'referral-bonus-create'         => ['ReferralBonusController', 'create'],
    'referral-bonus-store'          => ['ReferralBonusController', 'store'],
    'referral-bonus-view'           => ['ReferralBonusController', 'view'],
    'referral-bonus-report'         => ['ReferralBonusController', 'report'],
    'referral-bonus-member-search'  => ['ReferralBonusController', 'memberSearch'],
    // Savings Accounts module (Stage 4)
    'savings-accounts'                  => ['SavingsAccountController', 'overview'],
    'savings-account-open'              => ['SavingsAccountController', 'openSelect'],
    'savings-account-voluntary'         => ['SavingsAccountController', 'voluntaryForm'],
    'savings-account-voluntary-store'   => ['SavingsAccountController', 'voluntaryStore'],
    'savings-account-fixed-deposit'       => ['SavingsAccountController', 'fixedDepositForm'],
    'savings-account-fixed-deposit-store' => ['SavingsAccountController', 'fixedDepositStore'],
    // Fixed Deposit maturity/payout/closure governance (Stage FD-2)
    'savings-account-fd-closure-request'       => ['SavingsAccountController', 'fixedDepositClosureRequest'],
    'savings-account-fd-closure-request-store' => ['SavingsAccountController', 'fixedDepositClosureRequestStore'],
    'savings-account-fd-closure-cancel'        => ['SavingsAccountController', 'fixedDepositClosureCancel'],
    'savings-account-fd-closures'              => ['SavingsAccountController', 'fixedDepositClosureQueue'],
    'savings-account-fd-closure-review'        => ['SavingsAccountController', 'fixedDepositClosureReview'],
    'savings-account-fd-closure-approve'       => ['SavingsAccountController', 'fixedDepositClosureApprove'],
    'savings-account-fd-closure-reject'        => ['SavingsAccountController', 'fixedDepositClosureReject'],
    'savings-account-fd-payouts'                => ['SavingsAccountController', 'fixedDepositPayoutQueue'],
    'savings-account-fd-payout-form'            => ['SavingsAccountController', 'fixedDepositPayoutForm'],
    'savings-account-fd-payout-store'           => ['SavingsAccountController', 'fixedDepositPayoutStore'],

    // Stage 11 — universal closure (compulsory/voluntary/joint/corporate)
    'savings-account-closure-request'          => ['SavingsAccountController', 'closureRequestForm'],
    'savings-account-closure-request-store'    => ['SavingsAccountController', 'closureRequestStore'],
    'savings-account-closure-cancel'           => ['SavingsAccountController', 'closureCancel'],
    'savings-account-closures'                 => ['SavingsAccountController', 'closureQueue'],
    'savings-account-closure-review'           => ['SavingsAccountController', 'closureReview'],
    'savings-account-closure-approve'          => ['SavingsAccountController', 'closureApprove'],
    'savings-account-closure-reject'           => ['SavingsAccountController', 'closureReject'],
    'savings-account-settlements'              => ['SavingsAccountController', 'closureSettleQueue'],
    'savings-account-settle-form'              => ['SavingsAccountController', 'closureSettleForm'],
    'savings-account-settle-store'             => ['SavingsAccountController', 'closureSettleStore'],
    'savings-account-joint'             => ['SavingsAccountController', 'jointForm'],
    'savings-account-joint-store'       => ['SavingsAccountController', 'jointStore'],
    'savings-account-corporate'         => ['SavingsAccountController', 'corporateForm'],
    'savings-account-corporate-store'   => ['SavingsAccountController', 'corporateStore'],
    'savings-account-view'              => ['SavingsAccountController', 'view'],
    'savings-account-statement'         => ['SavingsAccountController', 'statement'],
    'savings-account-member-summary'    => ['SavingsAccountController', 'memberSummary'],
    // Savings Accounts — live transaction integration (Stage 5B)
    'savings-account-deposit'           => ['SavingsAccountController', 'depositForm'],
    'savings-account-deposit-store'     => ['SavingsAccountController', 'depositStore'],
    'savings-account-withdrawal'        => ['SavingsAccountController', 'withdrawalForm'],
    'savings-account-withdrawal-store'  => ['SavingsAccountController', 'withdrawalStore'],
    // Reports module
    'reports'                => ['ReportController',     'index'],
    'report-members'         => ['ReportController',     'members'],
    'report-savings'         => ['ReportController',     'savings'],
    'report-loans'           => ['ReportController',     'loans'],
    'report-loan-aging'      => ['ReportController',     'aging'],
    'report-repayments'      => ['ReportController',     'repayments'],
    'report-shares'          => ['ReportController',     'shares'],
    'report-withdrawals'     => ['ReportController',     'withdrawals'],
    'report-financial'       => ['ReportController',     'financial'],
    // Settings module (comprehensive)
    'settings'               => ['SettingsController', 'general'],
    'settings-general'       => ['SettingsController', 'general'],
    'settings-general-save'  => ['SettingsController', 'generalSave'],
    'settings-system-config-save' => ['SettingsController', 'systemConfigSave'],
    'settings-financial-years' => ['SettingsController', 'financialYears'],
    'settings-fy-save'       => ['SettingsController', 'financialYearSave'],
    'settings-fy-activate'   => ['SettingsController', 'financialYearActivate'],
    'settings-fy-close'      => ['SettingsController', 'financialYearClose'],
    'settings-loans'         => ['SettingsController', 'loanSettings'],
    'settings-loans-save'    => ['SettingsController', 'loanSettingsSave'],
    'settings-withdrawals'   => ['SettingsController', 'withdrawalSettings'],
    'settings-withdrawals-save' => ['SettingsController', 'withdrawalSettingsSave'],
    // Savings Withdrawal Policy configuration (Stage 17 Part E-0) -- distinct from
    // the legacy global settings-withdrawals above; configuration only, no
    // withdrawal transaction processing lives here.
    'withdrawal-policies'          => ['WithdrawalPolicyController', 'index'],
    'withdrawal-policy-history'    => ['WithdrawalPolicyController', 'history'],
    'withdrawal-policy-create'     => ['WithdrawalPolicyController', 'create'],
    'withdrawal-policy-store'      => ['WithdrawalPolicyController', 'store'],
    'withdrawal-policy-edit-draft' => ['WithdrawalPolicyController', 'editDraft'],
    'withdrawal-policy-update-draft' => ['WithdrawalPolicyController', 'updateDraft'],
    'withdrawal-policy-toggle'     => ['WithdrawalPolicyController', 'toggle'],
    'settings-receipts'      => ['SettingsController', 'receiptSettings'],
    'settings-receipts-save' => ['SettingsController', 'receiptSettingsSave'],
    'settings-users'         => ['SettingsController', 'users'],
    'settings-user-save'     => ['SettingsController', 'userSave'],
    'settings-user-toggle'   => ['SettingsController', 'userToggle'],
    'settings-user-member-search' => ['SettingsController', 'userMemberSearch'],
    // Bulk member portal account provisioning (Stage 14-B Phase 6)
    'member-account-import'          => ['MemberAccountImportController', 'index'],
    'member-account-import-template' => ['MemberAccountImportController', 'template'],
    'member-account-import-preview'  => ['MemberAccountImportController', 'preview'],
    'member-account-import-process'  => ['MemberAccountImportController', 'process'],
    'settings-user-reset'    => ['SettingsController', 'userResetPassword'],
    'settings-database'      => ['SettingsController', 'database'],
    'settings-db-backup'     => ['SettingsController', 'databaseBackup'],
    'settings-db-download'   => ['SettingsController', 'databaseDownload'],
    'settings-audit'         => ['SettingsController', 'auditLogs'],
    // System Integrity & Diagnostics (SA-3, 2026-09) -- read-only, System Administrator + admin only
    'system-integrity'        => ['SystemIntegrityController', 'index'],
    'system-integrity-detail' => ['SystemIntegrityController', 'detail'],
    // Transaction Investigation & Forensic Traceability (SA-4, 2026-09) -- read-only, System Administrator + admin only
    'investigation'              => ['TransactionInvestigationController', 'index'],
    'investigation-member'       => ['TransactionInvestigationController', 'member'],
    'investigation-savings'      => ['TransactionInvestigationController', 'savings'],
    'investigation-loan'         => ['TransactionInvestigationController', 'loan'],
    'investigation-repayment'    => ['TransactionInvestigationController', 'repayment'],
    'investigation-withdrawal'   => ['TransactionInvestigationController', 'withdrawal'],
    'investigation-fee'          => ['TransactionInvestigationController', 'fee'],
    'investigation-journal'      => ['TransactionInvestigationController', 'journal'],
    'investigation-orphans'      => ['TransactionInvestigationController', 'orphans'],
    'investigation-coverage'     => ['TransactionInvestigationController', 'coverage'],
    // Controlled Corrections, Reversals & Financial Remediation (SA-5, 2026-09) -- System Administrator + admin only
    'corrections'           => ['ControlledCorrectionController', 'index'],
    'correction-prepare'    => ['ControlledCorrectionController', 'prepare'],
    'correction-store'      => ['ControlledCorrectionController', 'store'],
    'correction-review'     => ['ControlledCorrectionController', 'review'],
    'correction-execute'    => ['ControlledCorrectionController', 'execute'],
    'correction-result'     => ['ControlledCorrectionController', 'result'],
    'correction-cancel'     => ['ControlledCorrectionController', 'cancel'],
    // Database Recovery & Restore (SA-6, 2026-09) -- System Administrator + admin only, isolated-DB verification only
    'recovery'        => ['DatabaseRecoveryController', 'index'],
    'recovery-verify' => ['DatabaseRecoveryController', 'verify'],
    'recovery-result' => ['DatabaseRecoveryController', 'result'],
    // Legacy settings save (backward compat)
    'settings-save'          => ['SettingsController', 'withdrawalSettingsSave'],
    // Withdrawals module
    'withdrawals'            => ['WithdrawalController', 'index'],
    'withdrawal-process'     => ['WithdrawalController', 'process'],
    'withdrawal-view'        => ['WithdrawalController', 'view'],
    'withdrawal-receipt'     => ['WithdrawalController', 'receipt'],
    'withdrawal-member'      => ['WithdrawalController', 'memberWithdrawals'],
    'withdrawal-report'      => ['WithdrawalController', 'report'],
    'withdrawal-member-search' => ['WithdrawalController', 'memberSearch'],
    'withdrawal-delete'      => ['WithdrawalController', 'delete'],
    // Savings module
    'savings'                => ['SavingsController',    'index'],
    'savings-add'            => ['SavingsController',    'add'],
    'savings-edit'           => ['SavingsController',    'edit'],
    'savings-view'           => ['SavingsController',    'view'],
    'savings-delete'         => ['SavingsController',    'delete'],
    'savings-bulk-delete'    => ['SavingsController',    'bulkDelete'],
    'savings-receipt'        => ['SavingsController',    'receipt'],
    'savings-member'         => ['SavingsController',    'memberSavings'],
    'savings-report'         => ['SavingsController',    'report'],
    'savings-member-search'  => ['SavingsController',    'memberSearch'],
    'savings-add-member-accounts' => ['SavingsController', 'memberAccountsForAdd'],
    // Loans module
    'loans'                  => ['LoanController',       'index'],
    'loan-pending-approval'  => ['LoanController',       'pendingApproval'],
    'loan-add'               => ['LoanController',       'add'],
    'loan-edit'              => ['LoanController',       'edit'],
    'loan-view'              => ['LoanController',       'view'],
    'loan-post-disbursement' => ['LoanController',       'postDisbursementAction'],
    'loan-submit'            => ['LoanController',       'submit'],
    'loan-approve'           => ['LoanController',       'approve'],
    'loan-reject'            => ['LoanController',       'reject'],
    'loan-disburse'          => ['LoanController',       'disburse'],
    'loan-delete'            => ['LoanController',       'delete'],
    'loan-complete'          => ['LoanController',       'markComplete'],
    'loan-schedule'          => ['LoanController',       'printSchedule'],
    'loan-statement'         => ['LoanController',       'statement'],
    'loan-statement-print'   => ['LoanController',       'printStatement'],
    'loan-card'              => ['LoanController',       'repaymentCard'],
    'loan-member'            => ['LoanController',       'memberLoans'],
    'loan-member-search'     => ['LoanController',       'memberSearch'],
    'loan-member-lookup'     => ['LoanController',       'memberLookup'],
    'loan-product-settings'  => ['LoanController',       'productSettings'],
    'loan-whatsapp-schedule' => ['LoanController',       'whatsappSchedule'],
    'loan-convert-application' => ['LoanController',     'convertApplication'],
    // Loan Applications (Stage 9)
    'loan-applications'         => ['LoanApplicationController', 'index'],
    'loan-application-add'      => ['LoanApplicationController', 'add'],
    'loan-application-edit'     => ['LoanApplicationController', 'edit'],
    'loan-application-view'     => ['LoanApplicationController', 'view'],
    'loan-application-submit'   => ['LoanApplicationController', 'submit'],
    'loan-application-approve'  => ['LoanApplicationController', 'approve'],
    'loan-application-reject'   => ['LoanApplicationController', 'reject'],
    // Loan Import
    'loan-import'            => ['LoanImportController', 'index'],
    'loan-import-preview'    => ['LoanImportController', 'preview'],
    'loan-import-process'    => ['LoanImportController', 'process'],
    'loan-import-template'   => ['LoanImportController', 'template'],
    // Repayments module
    'repayments'             => ['RepaymentController',  'index'],
    'repayment-add'          => ['RepaymentController',  'add'],
    'repayment-view'         => ['RepaymentController',  'view'],
    'repayment-receipt'      => ['RepaymentController',  'receipt'],
    'repayment-loan'         => ['RepaymentController',  'loanRepayments'],
    'repayment-report'       => ['RepaymentController',  'report'],
    'repayment-loan-search'  => ['RepaymentController',  'loanSearch'],
    'repayment-delete'       => ['RepaymentController',  'delete'],
    // Statements module
    'statements'             => ['StatementController',  'index'],
    'statement-view'         => ['StatementController',  'view'],
    'statement-print'        => ['StatementController',  'printView'],
    'statement-search'       => ['StatementController',  'search'],
    'statement-member-loans' => ['StatementController',  'memberLoansAjax'],
    'statement-member-savings-accounts' => ['StatementController', 'memberSavingsAccountsAjax'],
    'statement-whatsapp'     => ['StatementController',  'whatsappSummary'],
    'statement-email-all'    => ['StatementController',  'emailAll'],
    // Notifications module
    'notifications'          => ['NotificationController', 'index'],
    'notification-read'      => ['NotificationController', 'markRead'],
    'notification-mark-all'  => ['NotificationController', 'markAllRead'],
    'notification-delete'    => ['NotificationController', 'delete'],
    'notification-fetch'     => ['NotificationController', 'fetchLatest'],
    'notification-audit'     => ['NotificationController', 'audit'],
    // Stage 12-D — push notification subscription lifecycle
    'push-vapid-key'   => ['PushController', 'vapidPublicKey'],
    'push-subscribe'   => ['PushController', 'subscribe'],
    'push-unsubscribe' => ['PushController', 'unsubscribe'],
    'push-status'      => ['PushController', 'status'],
    // Weekly Reports module (WhatsApp sharing)
    'weekly-savings'         => ['WeeklyReportController', 'savings'],
    'weekly-loans'           => ['WeeklyReportController', 'loans'],
    'weekly-repayments'      => ['WeeklyReportController', 'repayments'],
    'weekly-overdue'         => ['WeeklyReportController', 'overdue'],
    // Fees & Charges module
    'fees'                   => ['FeeController', 'index'],
    'fee-save'               => ['FeeController', 'save'],
    'fee-toggle'             => ['FeeController', 'toggle'],
    'fee-delete'             => ['FeeController', 'delete'],
    'fee-charges'            => ['FeeController', 'charges'],
    'fee-charge-form'        => ['FeeController', 'chargeForm'],
    'fee-charge-store'       => ['FeeController', 'chargeStore'],
    'fee-member-search'      => ['FeeController', 'memberSearch'],
    'fee-mark-paid'          => ['FeeController', 'markPaid'],
    'fee-mark-waived'        => ['FeeController', 'markWaived'],
    'fee-report'             => ['FeeController', 'report'],
    // Accounting Periods module (Step 4; expanded Stage 20)
    'accounting-periods'                => ['AccountingPeriodController', 'index'],
    'accounting-period-view'            => ['AccountingPeriodController', 'view'],
    'accounting-period-create'          => ['AccountingPeriodController', 'create'],
    'accounting-period-store'           => ['AccountingPeriodController', 'store'],
    'accounting-period-close-confirm'   => ['AccountingPeriodController', 'closeConfirm'],
    'accounting-period-close'           => ['AccountingPeriodController', 'close'],
    'accounting-period-reopen-confirm'  => ['AccountingPeriodController', 'reopenConfirm'],
    'accounting-period-reopen'          => ['AccountingPeriodController', 'reopen'],
    // Financial Years module (Stage 20 view/close/reopen + Stage 2 create/edit/activate — canonical, single implementation)
    'financial-years'                   => ['FinancialYearController', 'index'],
    'financial-year-view'               => ['FinancialYearController', 'view'],
    'financial-year-create'             => ['FinancialYearController', 'create'],
    'financial-year-store'              => ['FinancialYearController', 'store'],
    'financial-year-edit'               => ['FinancialYearController', 'edit'],
    'financial-year-update'             => ['FinancialYearController', 'update'],
    'financial-year-activate-confirm'   => ['FinancialYearController', 'activateConfirm'],
    'financial-year-activate'           => ['FinancialYearController', 'activate'],
    'financial-year-close-confirm'      => ['FinancialYearController', 'closeConfirm'],
    'financial-year-close'              => ['FinancialYearController', 'close'],
    'financial-year-reopen-confirm'     => ['FinancialYearController', 'reopenConfirm'],
    'financial-year-reopen'             => ['FinancialYearController', 'reopen'],
    // Opening Balances module (Step 5)
    'opening-balances'          => ['OpeningBalanceController', 'index'],
    'opening-balance-create'    => ['OpeningBalanceController', 'create'],
    'opening-balance-store'     => ['OpeningBalanceController', 'store'],
    'opening-balance-view'      => ['OpeningBalanceController', 'view'],
    'opening-balance-submit'    => ['OpeningBalanceController', 'submit'],
    'opening-balance-approve'   => ['OpeningBalanceController', 'approve'],
    'opening-balance-reject'    => ['OpeningBalanceController', 'reject'],
    'opening-balance-post'      => ['OpeningBalanceController', 'post'],
    // Accounting Reports module (Step 6)
    'report-trial-balance'      => ['AccountingReportController', 'trialBalance'],
    'report-general-ledger'     => ['AccountingReportController', 'generalLedger'],
    'report-income-statement'   => ['AccountingReportController', 'incomeStatement'],
    'report-balance-sheet'      => ['AccountingReportController', 'balanceSheet'],
    // Expenses module (Step 7)
    'expense-create'            => ['ExpenseController', 'create'],
    'expense-store'             => ['ExpenseController', 'store'],
    'expense-view'               => ['ExpenseController', 'view'],
    'expense-post'               => ['ExpenseController', 'post'],
    'expense-categories'         => ['ExpenseController', 'categories'],
    'expense-category-store'     => ['ExpenseController', 'categoryStore'],
    'expense-category-update'    => ['ExpenseController', 'categoryUpdate'],
    'expense-category-toggle'    => ['ExpenseController', 'categoryToggle'],
    // Other Income module (Stage 17 Part D)
    'other-income'                     => ['OtherIncomeController', 'index'],
    'other-income-create'              => ['OtherIncomeController', 'create'],
    'other-income-store'               => ['OtherIncomeController', 'store'],
    'other-income-view'                => ['OtherIncomeController', 'view'],
    'other-income-post'                => ['OtherIncomeController', 'post'],
    'other-income-categories'          => ['OtherIncomeController', 'categories'],
    'other-income-category-store'      => ['OtherIncomeController', 'categoryStore'],
    'other-income-category-update'     => ['OtherIncomeController', 'categoryUpdate'],
    'other-income-category-toggle'     => ['OtherIncomeController', 'categoryToggle'],
    // Chart of Accounts (Task 6.5)
    'chart-of-accounts'          => ['ChartOfAccountsController', 'index'],
    'account-view'               => ['ChartOfAccountsController', 'view'],
    'account-create'             => ['ChartOfAccountsController', 'create'],
    'account-store'              => ['ChartOfAccountsController', 'store'],
    'account-toggle-status'      => ['ChartOfAccountsController', 'toggleStatus'],
    // Loan Provisioning (Stage 21-D) — policy PROV-001, read-only policy;
    // no policy-editing route exists here by design (Stage 21-D §17).
    'loan-provisioning'                    => ['LoanProvisioningController', 'index'],
    'loan-provisioning-calculate'          => ['LoanProvisioningController', 'calculateForm'],
    'loan-provisioning-calculate-store'    => ['LoanProvisioningController', 'calculateStore'],
    'loan-provisioning-view'               => ['LoanProvisioningController', 'view'],
    'loan-provisioning-review'             => ['LoanProvisioningController', 'review'],
    'loan-provisioning-finalize'           => ['LoanProvisioningController', 'finalize'],
    'loan-provisioning-correction'         => ['LoanProvisioningController', 'correction'],
    'loan-provisioning-report-summary'     => ['LoanProvisioningController', 'reportSummary'],
    'loan-provisioning-report-by-bucket'   => ['LoanProvisioningController', 'reportByBucket'],
    'loan-provisioning-report-loan-level'  => ['LoanProvisioningController', 'reportLoanLevel'],
    'loan-provisioning-report-movement'    => ['LoanProvisioningController', 'reportMovement'],
];

$page       = $_GET['page'] ?? '';
$page       = preg_replace('/[^a-z0-9_\-]/', '', strtolower($page)); // whitelist

$route      = $routes[$page] ?? null;

if ($route === null) {
    http_response_code(404);
    require VIEW_PATH . '/errors/404.php';
    exit;
}

[$controllerClass, $method] = $route;

$controllerFile = APP_PATH . '/controllers/' . $controllerClass . '.php';
if (!file_exists($controllerFile)) {
    die("Controller not found: {$controllerClass}");
}

require_once $controllerFile;

$controller = new $controllerClass();
$controller->$method();
