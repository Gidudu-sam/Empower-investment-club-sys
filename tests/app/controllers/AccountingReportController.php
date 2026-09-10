<?php
/**
 * AccountingReportController — Trial Balance, General Ledger, Income
 * Statement, Balance Sheet. Read-only: GET filters only, no CSRF needed
 * since nothing here is ever written.
 */
class AccountingReportController extends Controller
{
    private AccountingReportModel $model;

    public function __construct()
    {
        Session::requireAuth();

        // Stage 23: Vice Chairman added -- brief's explicit "Financial
        // Oversight" nav requirement (Accounting Reports). Secretary
        // deliberately excluded -- Trial Balance/Income Statement/Balance
        // Sheet are deep accounting statements outside Secretary's
        // governance/records-scoped authority.
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view accounting reports.');
        }

        $this->model = new AccountingReportModel();
    }

    public function trialBalance(): void
    {
        $filters = $this->parseFilters();
        $report = $this->model->trialBalance($filters);

        $this->render('accounting-reports/trial-balance', [
            'pageTitle' => 'Trial Balance',
            'report' => $report,
            'filters' => $filters,
            'financialYears' => $this->financialYears(),
            'periods' => $this->periods(),
            'club' => $this->clubInfo(),
        ]);
    }

    public function generalLedger(): void
    {
        $filters = $this->parseFilters();
        $accountId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : null;

        $report = $accountId
            ? $this->model->generalLedgerForAccount($accountId, $filters)
            : ['lines' => $this->model->journalListing($filters)];

        $this->render('accounting-reports/general-ledger', [
            'pageTitle' => 'General Ledger',
            'report' => $report,
            'filters' => $filters,
            'accountId' => $accountId,
            'accounts' => (new AccountModel())->activeAccounts(),
            'financialYears' => $this->financialYears(),
            'periods' => $this->periods(),
            'club' => $this->clubInfo(),
        ]);
    }

    public function incomeStatement(): void
    {
        $filters = $this->parseFilters();
        $report = $this->model->incomeStatement($filters);

        $this->render('accounting-reports/income-statement', [
            'pageTitle' => 'Income Statement',
            'report' => $report,
            'filters' => $filters,
            'financialYears' => $this->financialYears(),
            'periods' => $this->periods(),
            'club' => $this->clubInfo(),
        ]);
    }

    public function balanceSheet(): void
    {
        $filters = $this->parseFilters();
        $report = $this->model->balanceSheet($filters);

        $this->render('accounting-reports/balance-sheet', [
            'pageTitle' => 'Balance Sheet',
            'report' => $report,
            'filters' => $filters,
            'financialYears' => $this->financialYears(),
            'periods' => $this->periods(),
            'club' => $this->clubInfo(),
        ]);
    }

    private function parseFilters(): array
    {
        return [
            'financial_year_id'    => !empty($_GET['financial_year_id']) ? (int)$_GET['financial_year_id'] : null,
            'accounting_period_id' => !empty($_GET['accounting_period_id']) ? (int)$_GET['accounting_period_id'] : null,
            'date_from'            => $_GET['date_from'] ?? null,
            'date_to'              => $_GET['date_to'] ?? null,
        ];
    }

    private function financialYears(): array
    {
        return $this->db()->query("SELECT id, name, is_legacy, status FROM financial_years ORDER BY is_legacy, start_date DESC")->fetchAll();
    }

    private function periods(): array
    {
        return $this->db()->query("
            SELECT ap.id, ap.name, ap.financial_year_id, ap.status
            FROM accounting_periods ap
            ORDER BY ap.start_date DESC
        ")->fetchAll();
    }

    private function clubInfo(): array
    {
        $stmt = $this->db()->query("SELECT setting_key, setting_val FROM settings WHERE setting_key IN ('club_name','club_logo','club_address','currency')");
        $info = [];
        foreach ($stmt->fetchAll() as $row) {
            $info[$row['setting_key']] = $row['setting_val'];
        }
        return $info;
    }

    private function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
