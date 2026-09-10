<?php
/**
 * SystemIntegrityService — SA-3 (System Integrity & Diagnostics, 2026-09).
 *
 * READ-ONLY. Every method in this class only ever SELECTs. None of them
 * write, repair, reverse, or post anything -- detection only. Correction
 * workflows are a later, separate stage (SA-5) built around the existing
 * JournalService::reverse() engine, not this class.
 *
 * Each check returns a standardized result array:
 *   ['code','title','status','message','count','details','severity']
 * status is one of: PASS, WARNING, ERROR, UNAVAILABLE.
 *
 * Historical-data handling (Section 13/15 of the SA-3 brief): this system's
 * accounting engine was added after a large amount of dummy/test data had
 * already accumulated in transaction tables (documented across this
 * engagement's Stage 24-28 "clean accounting baseline" work -- see project
 * memory). journal_entries carries a real data_classification column
 * ('live'/'dummy'/'unknown') for entries that DO have a journal, but the
 * source transaction tables (savings, loan_repayments, etc.) carry no such
 * column, so a missing journal link cannot be auto-classified as
 * dummy-therefore-safe or live-therefore-corrupt. This service never
 * upgrades a bare "no journal found" count to ERROR on that basis alone --
 * it is reported at WARNING with an explicit note that human
 * classification (via the existing documented baseline work) is required,
 * never presented as confirmed corruption.
 */
class SystemIntegrityService
{
    private PDO $db;

    /**
     * Tables the live application actually depends on (confirmed via
     * `protected string $table` declarations across app/models/ plus
     * grep-confirmed raw-SQL references), NOT a raw dump of every table in
     * the schema. This project has pre-existing, already-documented,
     * storage-engine-corrupted legacy tables (e.g. a stale `other_income`,
     * `savings_accounts`, `admins`, `repayment_installment_allocations` --
     * all superseded by their current equivalents and unreferenced by any
     * model) that are deliberately excluded here: including them would
     * misreport pre-existing, already-investigated junk as a fresh SA-3
     * finding. See project memory:
     * "Accounting tables broken + forensic investigation".
     */
    private const REQUIRED_TABLES = [
        'accounts', 'journal_entries', 'journal_lines', 'journal_entry_audit',
        'accounting_periods', 'financial_years', 'journal_number_sequences',
        'members', 'member_savings_accounts', 'savings_account_holders', 'savings',
        'loans', 'loan_repayments', 'loan_installments', 'loan_types',
        'loan_product_settings',
        'withdrawals', 'savings_withdrawal_policies',
        'fees', 'member_fees', 'fee_history',
        'other_income_transactions', 'other_income_categories',
        'expenses', 'expense_categories',
        'internal_vouchers', 'member_account_adjustments',
        'opening_balances', 'opening_balance_batches',
        'investments', 'investment_types', 'investment_transactions',
        'referral_bonuses',
        'users', 'roles', 'settings', 'activity_logs', 'notifications',
        'organizations', 'database_backups',
    ];
    // NOTE: 'cash_reference_sequences' was considered and deliberately
    // excluded -- confirmed via grep that no model/controller references
    // it (same class as the already-documented broken legacy tables:
    // other_income, savings_accounts, admins). It is also, separately,
    // storage-engine-corrupted like those others; not re-reported here
    // since it is not a live-app dependency -- see Section F of the SA-3
    // report and project memory "Accounting tables broken + forensic
    // investigation".

    private const LIVE_NOTE = 'Live-data issue -- genuine defect, requires investigation.';
    private const DUMMY_NOTE = 'Not automatic corruption -- dummy/unknown-classified data, consistent with this project\'s already-documented pre-enforcement test data (see project memory, Stage 24-28 "clean accounting baseline" / Stage 19D "orphan journal forensics"). Requires human review against that documentation, not an automatic verdict.';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Runs every category check and returns them as a flat, ordered list. */
    public function runAll(): array
    {
        return [
            $this->checkApplicationHealth(),
            $this->checkDatabaseHealth(),
            $this->checkJournalIntegrity(),
            $this->checkOrphanRecords(),
            $this->checkAccountingIntegrity(),
            $this->checkLoanIntegrity(),
            $this->checkSavingsIntegrity(),
            $this->checkTransactionCoverage(),
        ];
    }

    private function result(string $code, string $title, string $status, string $message, int $count = 0, array $details = []): array
    {
        $severityMap = ['PASS' => 'info', 'WARNING' => 'warning', 'ERROR' => 'danger', 'UNAVAILABLE' => 'secondary'];
        return [
            'code'     => $code,
            'title'    => $title,
            'status'   => $status,
            'message'  => $message,
            'count'    => $count,
            'details'  => $details,
            'severity' => $severityMap[$status] ?? 'secondary',
        ];
    }

    // ================================================================
    // A. APPLICATION HEALTH
    // ================================================================
    public function checkApplicationHealth(): array
    {
        $items = [];
        $worst = 'PASS';

        $items[] = ['label' => 'PHP version', 'value' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.0.0', '>=')];
        $items[] = ['label' => 'Application environment', 'value' => defined('APP_ENV') ? APP_ENV : 'undefined', 'ok' => defined('APP_ENV')];
        $items[] = ['label' => 'Application version', 'value' => defined('APP_VERSION') ? APP_VERSION : 'undefined', 'ok' => defined('APP_VERSION')];

        try {
            $this->db->query('SELECT 1');
            $items[] = ['label' => 'Database connectivity', 'value' => 'connected', 'ok' => true];
        } catch (Throwable $e) {
            $items[] = ['label' => 'Database connectivity', 'value' => 'FAILED', 'ok' => false];
            $worst = 'ERROR';
        }
        // Database name is safe to display (not a secret); host/user/password are not.
        $items[] = ['label' => 'Database name', 'value' => defined('DB_NAME') ? DB_NAME : 'undefined', 'ok' => defined('DB_NAME')];

        foreach (['app' => APP_PATH, 'core' => CORE_PATH, 'views' => VIEW_PATH, 'public' => PUBLIC_PATH] as $label => $path) {
            $ok = is_dir($path) && is_readable($path);
            $items[] = ['label' => "Directory: {$label}", 'value' => $ok ? 'present & readable' : 'MISSING/UNREADABLE', 'ok' => $ok];
            if (!$ok) { $worst = $worst === 'ERROR' ? 'ERROR' : 'WARNING'; }
        }

        $entryPoint = ROOT_PATH . '/index.php';
        $ok = is_file($entryPoint) && is_readable($entryPoint);
        $items[] = ['label' => 'Front controller (index.php)', 'value' => $ok ? 'present' : 'MISSING', 'ok' => $ok];
        if (!$ok) { $worst = 'ERROR'; }

        $items[] = ['label' => 'Session name', 'value' => defined('SESSION_NAME') ? SESSION_NAME : 'undefined', 'ok' => defined('SESSION_NAME')];
        $items[] = ['label' => 'Session lifetime (seconds)', 'value' => defined('SESSION_LIFETIME') ? (string)SESSION_LIFETIME : 'undefined', 'ok' => defined('SESSION_LIFETIME')];

        // Writable-directory checks: existence only affects overall status.
        // PHP's is_writable() is a filesystem *stat* call (never creates or
        // modifies anything itself), but on Windows it only inspects the
        // read-only attribute bit and is known to false-negative on
        // directories that are, in practice, fully writable (confirmed
        // during SA-3 verification: both directories accepted a real
        // write/delete probe while is_writable() reported false) -- so its
        // result is shown as informational only and never flips the
        // overall status.
        foreach (['backups' => ROOT_PATH . '/backups', 'uploads' => PUBLIC_PATH . '/uploads'] as $label => $path) {
            $exists = is_dir($path);
            $items[] = [
                'label' => "Directory exists: {$label}/", 'value' => $exists ? 'present' : 'MISSING', 'ok' => $exists,
                'note' => 'is_writable() result is informational only (unreliable on Windows) and does not affect overall status.',
            ];
            if (!$exists) { $worst = $worst === 'ERROR' ? 'ERROR' : 'WARNING'; }
        }

        $failCount = count(array_filter($items, fn($i) => !$i['ok']));
        $msg = $failCount === 0 ? 'All application health checks passed.' : "{$failCount} application health item(s) need attention.";
        return $this->result('APP_HEALTH', 'Application Health', $worst, $msg, $failCount, $items);
    }

    // ================================================================
    // B. DATABASE HEALTH
    // ================================================================
    public function checkDatabaseHealth(): array
    {
        $details = [];
        $errorCount = 0;

        foreach (self::REQUIRED_TABLES as $table) {
            $existsRow = $this->db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
            );
            $existsRow->execute([$table]);
            $exists = ((int)$existsRow->fetchColumn()) > 0;

            if (!$exists) {
                $details[] = ['table' => $table, 'status' => 'ERROR', 'note' => 'Required table does not exist.'];
                $errorCount++;
                continue;
            }

            try {
                $this->db->query("SELECT 1 FROM `{$table}` LIMIT 1");
                $details[] = ['table' => $table, 'status' => 'PASS', 'note' => 'Readable.'];
            } catch (Throwable $e) {
                $details[] = ['table' => $table, 'status' => 'ERROR', 'note' => 'Exists in metadata but is not readable (storage-engine issue).'];
                $errorCount++;
            }
        }

        $status = $errorCount > 0 ? 'ERROR' : 'PASS';
        $msg = $errorCount > 0
            ? "{$errorCount} of " . count(self::REQUIRED_TABLES) . " required table(s) are missing or unreadable."
            : 'All ' . count(self::REQUIRED_TABLES) . ' required tables exist and are readable.';
        return $this->result('DB_HEALTH', 'Database Health', $status, $msg, $errorCount, $details);
    }

    // ================================================================
    // C. JOURNAL INTEGRITY
    // ================================================================
    public function checkJournalIntegrity(): array
    {
        $details = [];
        $errorCount = 0;
        $warningCount = 0;

        // (A) Unbalanced journal entries -- SUM(debit) must equal SUM(credit)
        // per entry. Split by data_classification: a 'live' imbalance is a
        // real accounting defect (ERROR); a 'dummy'/'unknown' imbalance is
        // still surfaced, but only as a WARNING, since it is known,
        // already-documented test data (see class docblock).
        $rows = $this->db->query("
            SELECT je.id, je.entry_number, je.entry_date, je.source_module, je.data_classification,
                   COALESCE(SUM(jl.debit),0) AS total_debit, COALESCE(SUM(jl.credit),0) AS total_credit
            FROM journal_entries je
            LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
            GROUP BY je.id
            HAVING ROUND(total_debit - total_credit, 2) <> 0
        ")->fetchAll();
        foreach ($rows as $r) {
            $isLive = $r['data_classification'] === 'live';
            $details[] = [
                'issue' => 'Unbalanced journal entry',
                'entry_id' => (int)$r['id'], 'entry_number' => $r['entry_number'], 'date' => $r['entry_date'],
                'source_module' => $r['source_module'], 'classification' => $r['data_classification'],
                'debit_total' => (float)$r['total_debit'], 'credit_total' => (float)$r['total_credit'],
                'difference' => round((float)$r['total_debit'] - (float)$r['total_credit'], 2),
                'status' => $isLive ? 'ERROR' : 'WARNING',
                'note' => $isLive ? self::LIVE_NOTE : self::DUMMY_NOTE,
            ];
            $isLive ? $errorCount++ : $warningCount++;
        }

        // (B) Empty journal entries -- no lines at all.
        $rows = $this->db->query("
            SELECT je.id, je.entry_number, je.entry_date, je.data_classification
            FROM journal_entries je
            LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
            WHERE jl.id IS NULL
        ")->fetchAll();
        foreach ($rows as $r) {
            $isLive = $r['data_classification'] === 'live';
            $details[] = [
                'issue' => 'Journal entry has no lines', 'entry_id' => (int)$r['id'],
                'entry_number' => $r['entry_number'], 'date' => $r['entry_date'],
                'classification' => $r['data_classification'], 'status' => $isLive ? 'ERROR' : 'WARNING',
                'note' => $isLive ? self::LIVE_NOTE : self::DUMMY_NOTE,
            ];
            $isLive ? $errorCount++ : $warningCount++;
        }

        // (C) Invalid journal lines: both debit+credit populated, neither
        // populated, negative amounts, or an orphaned account/entry
        // reference (the account/entry side of this is also picked up
        // under Orphan Detection; kept here too since it's a journal-line
        // structural defect in its own right).
        $rows = $this->db->query("
            SELECT jl.id AS line_id, jl.journal_entry_id, je.entry_number, je.data_classification,
                   jl.debit, jl.credit, jl.account_id,
                   (a.id IS NULL) AS account_missing
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id
            LEFT JOIN accounts a ON a.id = jl.account_id
            WHERE (jl.debit > 0 AND jl.credit > 0)
               OR (jl.debit = 0 AND jl.credit = 0)
               OR jl.debit < 0 OR jl.credit < 0
               OR a.id IS NULL
        ")->fetchAll();
        foreach ($rows as $r) {
            $isLive = $r['data_classification'] === 'live';
            $reason = $r['account_missing'] ? 'References a non-existent account'
                : (($r['debit'] > 0 && $r['credit'] > 0) ? 'Both debit and credit populated'
                : ((($r['debit'] == 0) && ($r['credit'] == 0)) ? 'Neither debit nor credit populated' : 'Negative amount'));
            $details[] = [
                'issue' => 'Invalid journal line', 'line_id' => (int)$r['line_id'],
                'journal_entry_id' => (int)$r['journal_entry_id'], 'entry_number' => $r['entry_number'],
                'reason' => $reason, 'debit' => (float)$r['debit'], 'credit' => (float)$r['credit'],
                'classification' => $r['data_classification'], 'status' => $isLive ? 'ERROR' : 'WARNING',
                'note' => $isLive ? self::LIVE_NOTE : self::DUMMY_NOTE,
            ];
            $isLive ? $errorCount++ : $warningCount++;
        }

        $status = $errorCount > 0 ? 'ERROR' : ($warningCount > 0 ? 'WARNING' : 'PASS');
        $total = $errorCount + $warningCount;
        $msg = $total === 0 ? 'All journal entries are balanced and structurally valid.'
            : "{$errorCount} live-data issue(s), {$warningCount} dummy/unknown-data issue(s) found.";
        return $this->result('JOURNAL_INTEGRITY', 'Journal Integrity', $status, $msg, $total, $details);
    }

    // ================================================================
    // D. ORPHAN DETECTION
    // ================================================================
    public function checkOrphanRecords(): array
    {
        $details = [];
        $count = 0;

        $checks = [
            ['label' => 'Loan repayments referencing a missing loan',
             'sql' => "SELECT lr.id, lr.repayment_number FROM loan_repayments lr LEFT JOIN loans l ON l.id = lr.loan_id WHERE l.id IS NULL"],
            ['label' => 'Loan installments referencing a missing loan',
             'sql' => "SELECT li.id, li.installment_no FROM loan_installments li LEFT JOIN loans l ON l.id = li.loan_id WHERE l.id IS NULL"],
            ['label' => 'Loans referencing a missing member',
             'sql' => "SELECT l.id, l.loan_number FROM loans l LEFT JOIN members m ON m.id = l.member_id WHERE m.id IS NULL"],
            ['label' => 'Savings transactions referencing a missing member',
             'sql' => "SELECT s.id, s.receipt_number FROM savings s LEFT JOIN members m ON m.id = s.member_id WHERE m.id IS NULL"],
            ['label' => 'Savings transactions referencing a missing savings account',
             'sql' => "SELECT s.id, s.receipt_number FROM savings s LEFT JOIN member_savings_accounts a ON a.id = s.savings_account_id WHERE s.savings_account_id IS NOT NULL AND a.id IS NULL"],
            ['label' => 'Savings account holders referencing a missing account',
             'sql' => "SELECT h.id FROM savings_account_holders h LEFT JOIN member_savings_accounts a ON a.id = h.account_id WHERE a.id IS NULL"],
            ['label' => 'Savings account holders (member role) referencing a missing member',
             'sql' => "SELECT h.id FROM savings_account_holders h LEFT JOIN members m ON m.id = h.member_id WHERE h.member_id IS NOT NULL AND m.id IS NULL"],
            ['label' => 'Withdrawals referencing a missing member',
             'sql' => "SELECT w.id, w.withdrawal_number FROM withdrawals w LEFT JOIN members m ON m.id = w.member_id WHERE m.id IS NULL"],
            ['label' => 'Withdrawals referencing a missing savings account',
             'sql' => "SELECT w.id, w.withdrawal_number FROM withdrawals w LEFT JOIN member_savings_accounts a ON a.id = w.savings_account_id WHERE w.savings_account_id IS NOT NULL AND a.id IS NULL"],
            ['label' => 'Member fee charges referencing a missing member',
             'sql' => "SELECT f.id FROM member_fees f LEFT JOIN members m ON m.id = f.member_id WHERE m.id IS NULL"],
            ['label' => 'Member fee charges referencing a missing fee type',
             'sql' => "SELECT f.id FROM member_fees f LEFT JOIN fees ft ON ft.id = f.fee_id WHERE ft.id IS NULL"],
            ['label' => 'Journal lines referencing a missing journal entry',
             'sql' => "SELECT jl.id FROM journal_lines jl LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE je.id IS NULL"],
            ['label' => 'Journal entries referencing a missing accounting period',
             'sql' => "SELECT je.id, je.entry_number FROM journal_entries je LEFT JOIN accounting_periods p ON p.id = je.accounting_period_id WHERE je.accounting_period_id IS NOT NULL AND p.id IS NULL"],
            ['label' => 'Journal entries referencing a missing financial year',
             'sql' => "SELECT je.id, je.entry_number FROM journal_entries je LEFT JOIN financial_years fy ON fy.id = je.financial_year_id WHERE je.financial_year_id IS NOT NULL AND fy.id IS NULL"],
        ];

        foreach ($checks as $c) {
            try {
                $rows = $this->db->query($c['sql'])->fetchAll();
                if ($rows) {
                    $details[] = ['issue' => $c['label'], 'count' => count($rows), 'sample_ids' => array_slice(array_column($rows, 'id'), 0, 10), 'status' => 'ERROR'];
                    $count += count($rows);
                }
            } catch (Throwable $e) {
                $details[] = ['issue' => $c['label'], 'count' => 0, 'note' => 'Could not evaluate: ' . $e->getMessage(), 'status' => 'UNAVAILABLE'];
            }
        }

        $status = $count > 0 ? 'ERROR' : 'PASS';
        $msg = $count > 0 ? "{$count} orphaned record(s) found across " . count(array_filter($details, fn($d) => ($d['status'] ?? '') === 'ERROR')) . ' relationship type(s).' : 'No orphaned records detected in any checked relationship.';
        return $this->result('ORPHAN_RECORDS', 'Orphan Detection', $status, $msg, $count, $details);
    }

    // ================================================================
    // E. ACCOUNTING INTEGRITY (periods, years, period/date alignment)
    // ================================================================
    public function checkAccountingIntegrity(): array
    {
        $details = [];
        $errorCount = 0;

        // Structurally invalid periods/years (start >= end).
        $rows = $this->db->query("SELECT id, name, start_date, end_date FROM accounting_periods WHERE start_date >= end_date")->fetchAll();
        foreach ($rows as $r) {
            $details[] = ['issue' => 'Accounting period has start_date >= end_date', 'id' => (int)$r['id'], 'name' => $r['name'], 'status' => 'ERROR'];
            $errorCount++;
        }
        $rows = $this->db->query("SELECT id, name, start_date, end_date FROM financial_years WHERE start_date >= end_date")->fetchAll();
        foreach ($rows as $r) {
            $details[] = ['issue' => 'Financial year has start_date >= end_date', 'id' => (int)$r['id'], 'name' => $r['name'], 'status' => 'ERROR'];
            $errorCount++;
        }

        // Overlapping periods within the same financial year.
        $rows = $this->db->query("
            SELECT p1.id AS id1, p1.name AS name1, p2.id AS id2, p2.name AS name2
            FROM accounting_periods p1
            JOIN accounting_periods p2 ON p2.financial_year_id = p1.financial_year_id AND p2.id > p1.id
            WHERE p1.start_date <= p2.end_date AND p2.start_date <= p1.end_date
        ")->fetchAll();
        foreach ($rows as $r) {
            $details[] = ['issue' => 'Overlapping accounting periods', 'period_a' => $r['name1'], 'period_b' => $r['name2'], 'status' => 'ERROR'];
            $errorCount++;
        }

        // Journal entries whose date falls outside their own assigned
        // period. Same classification-aware severity as Journal Integrity/
        // Transaction Coverage: a 'dummy'/'unknown' entry is surfaced at
        // WARNING (known test-data artifact), a 'live' entry is ERROR.
        $warningCount = 0;
        $rows = $this->db->query("
            SELECT je.id, je.entry_number, je.entry_date, je.data_classification, p.name AS period_name, p.start_date, p.end_date
            FROM journal_entries je
            JOIN accounting_periods p ON p.id = je.accounting_period_id
            WHERE je.entry_date < p.start_date OR je.entry_date > p.end_date
        ")->fetchAll();
        foreach ($rows as $r) {
            $isLive = $r['data_classification'] === 'live';
            $details[] = [
                'issue' => 'Journal entry date falls outside its assigned accounting period',
                'entry_number' => $r['entry_number'], 'entry_date' => $r['entry_date'],
                'period' => $r['period_name'], 'period_range' => "{$r['start_date']} to {$r['end_date']}",
                'classification' => $r['data_classification'], 'status' => $isLive ? 'ERROR' : 'WARNING',
                'note' => $isLive ? 'Live-data period misassignment -- genuine defect, requires investigation.'
                    : 'Not automatic corruption -- dummy-classified data seeded before the accounting-enforcement stage (see project memory, Stage 24-28).',
            ];
            $isLive ? $errorCount++ : $warningCount++;
        }

        $status = $errorCount > 0 ? 'ERROR' : ($warningCount > 0 ? 'WARNING' : 'PASS');
        $total = $errorCount + $warningCount;
        $msg = $total > 0 ? "{$errorCount} error(s), {$warningCount} warning(s) found." : 'Accounting periods and financial years are structurally consistent.';
        return $this->result('ACCOUNTING_INTEGRITY', 'Accounting Integrity', $status, $msg, $total, $details);
    }

    // ================================================================
    // F. LOAN INTEGRITY
    // ================================================================
    public function checkLoanIntegrity(): array
    {
        $details = [];
        $errorCount = 0;
        $warningCount = 0;

        $rows = $this->db->query("SELECT id, loan_number, outstanding FROM loans WHERE outstanding < 0")->fetchAll();
        foreach ($rows as $r) {
            $details[] = ['issue' => 'Negative outstanding balance', 'loan_number' => $r['loan_number'], 'outstanding' => (float)$r['outstanding'], 'status' => 'ERROR'];
            $errorCount++;
        }

        $rows = $this->db->query("SELECT id, loan_number, loan_amount FROM loans WHERE loan_amount < 0")->fetchAll();
        foreach ($rows as $r) {
            $details[] = ['issue' => 'Negative loan principal amount', 'loan_number' => $r['loan_number'], 'loan_amount' => (float)$r['loan_amount'], 'status' => 'ERROR'];
            $errorCount++;
        }

        // Overpayment (amount_paid exceeds total_payable): flagged as
        // WARNING, not ERROR -- a legitimate early settlement/write-off can
        // legitimately land here; needs human review, not an auto-verdict.
        $rows = $this->db->query("SELECT id, loan_number, amount_paid, total_payable FROM loans WHERE amount_paid > total_payable AND total_payable > 0")->fetchAll();
        foreach ($rows as $r) {
            $details[] = [
                'issue' => 'Amount paid exceeds total payable (review: may be a legitimate settlement)',
                'loan_number' => $r['loan_number'], 'amount_paid' => (float)$r['amount_paid'], 'total_payable' => (float)$r['total_payable'],
                'status' => 'WARNING',
            ];
            $warningCount++;
        }

        $status = $errorCount > 0 ? 'ERROR' : ($warningCount > 0 ? 'WARNING' : 'PASS');
        $total = $errorCount + $warningCount;
        $msg = $total === 0 ? 'No loan-balance anomalies detected.' : "{$errorCount} error(s), {$warningCount} warning(s) found.";
        return $this->result('LOAN_INTEGRITY', 'Loan Integrity', $status, $msg, $total, $details);
    }

    // ================================================================
    // G. SAVINGS INTEGRITY
    // ================================================================
    public function checkSavingsIntegrity(): array
    {
        $details = [];
        $errorCount = 0;

        // A savings account's running_balance is a computed ledger value --
        // it must never go negative (this system enforces
        // withdrawal-cannot-exceed-balance at the application layer; a
        // negative running_balance in the data means that guarantee was
        // bypassed somewhere).
        $rows = $this->db->query("
            SELECT s1.id, s1.savings_account_id, s1.running_balance, s1.transaction_date
            FROM savings s1
            INNER JOIN (
                SELECT savings_account_id, MAX(id) AS max_id
                FROM savings
                WHERE savings_account_id IS NOT NULL
                GROUP BY savings_account_id
            ) latest ON latest.max_id = s1.id
            WHERE s1.running_balance < 0
        ")->fetchAll();
        foreach ($rows as $r) {
            $details[] = [
                'issue' => 'Savings account has a negative current balance',
                'savings_account_id' => (int)$r['savings_account_id'], 'running_balance' => (float)$r['running_balance'],
                'as_of' => $r['transaction_date'], 'status' => 'ERROR',
            ];
            $errorCount++;
        }

        $status = $errorCount > 0 ? 'ERROR' : 'PASS';
        $msg = $errorCount > 0 ? "{$errorCount} savings account(s) with a negative balance." : 'No savings account currently holds a negative balance.';
        return $this->result('SAVINGS_INTEGRITY', 'Savings Integrity', $status, $msg, $errorCount, $details);
    }

    // ================================================================
    // H. TRANSACTION-TO-JOURNAL COVERAGE (informational -- see class docblock)
    // ================================================================
    public function checkTransactionCoverage(): array
    {
        $details = [];
        $warningCount = 0;

        $coverage = [
            ['label' => 'Loan repayments with no linked journal entry',
             'sql' => "SELECT COUNT(*) FROM loan_repayments lr WHERE lr.journal_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_module='loan_repayments' AND je.source_reference_id=lr.id)"],
            ['label' => 'Savings transactions with no linked journal entry',
             'sql' => "SELECT COUNT(*) FROM savings s WHERE s.journal_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_module='savings' AND je.source_reference_id=s.id)"],
            ['label' => 'Withdrawals with no linked journal entry',
             'sql' => "SELECT COUNT(*) FROM withdrawals w WHERE w.journal_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_module='withdrawals' AND je.source_reference_id=w.id)"],
            // Fees are only expected to have a journal once actually paid --
            // a pending/waived/cancelled charge legitimately has none.
            ['label' => "Paid member fee charges with no linked journal entry",
             'sql' => "SELECT COUNT(*) FROM member_fees f WHERE f.status='paid' AND f.journal_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_module='fees' AND je.source_reference_id=f.id)"],
        ];

        foreach ($coverage as $c) {
            $n = (int)$this->db->query($c['sql'])->fetchColumn();
            if ($n > 0) {
                $details[] = [
                    'issue' => $c['label'], 'count' => $n, 'status' => 'WARNING',
                    'note' => 'Not automatically treated as corruption -- this project has a documented, pre-existing volume of dummy/test data seeded before the accounting-enforcement stage (Stage 24-28). Requires human review against that documentation, not an automatic verdict.',
                ];
                $warningCount += $n;
            }
        }

        // Orphan direction: a journal entry referencing a source record
        // that no longer exists (the source was deleted after journaling,
        // which should never happen under JournalService's own rules).
        // Split by data_classification, same principle as Journal
        // Integrity above: a 'dummy'/'unknown' orphan is surfaced at
        // WARNING (known, already-documented test-data artifact -- see
        // project memory "Stage 19D orphan journal forensics"), a 'live'
        // orphan is a real defect (ERROR).
        $orphanJournalRefs = [
            ['module' => 'loan_repayments', 'table' => 'loan_repayments'],
            ['module' => 'savings', 'table' => 'savings'],
            ['module' => 'withdrawals', 'table' => 'withdrawals'],
        ];
        $errorCount = 0;
        foreach ($orphanJournalRefs as $o) {
            // $o['table'] is a fixed literal from the hardcoded array above,
            // never user input, so it is safe to interpolate as an
            // identifier (PDO cannot parameterize table names); the actual
            // data value (module) is still bound normally.
            $stmt = $this->db->prepare("
                SELECT je.data_classification, COUNT(*) AS n
                FROM journal_entries je
                WHERE je.source_module = ? AND je.source_reference_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM `{$o['table']}` t WHERE t.id = je.source_reference_id)
                GROUP BY je.data_classification
            ");
            $stmt->execute([$o['module']]);
            foreach ($stmt->fetchAll() as $row) {
                $isLive = $row['data_classification'] === 'live';
                $n = (int)$row['n'];
                $details[] = [
                    'issue' => "Journal entries reference a missing {$o['module']} record",
                    'count' => $n, 'classification' => $row['data_classification'],
                    'status' => $isLive ? 'ERROR' : 'WARNING',
                    'note' => $isLive ? 'Live-data orphan journal reference -- genuine defect, requires investigation.'
                        : 'Not automatic corruption -- dummy-classified data (see project memory "Stage 19D orphan journal forensics" for this exact category of pre-existing test-data artifact).',
                ];
                $isLive ? $errorCount += $n : $warningCount += $n;
            }
        }

        $status = $errorCount > 0 ? 'ERROR' : ($warningCount > 0 ? 'WARNING' : 'PASS');
        $total = $errorCount + $warningCount;
        $msg = $total === 0 ? 'No coverage gaps detected.'
            : "{$errorCount} confirmed orphan journal reference(s), {$warningCount} transaction(s) without journal linkage (see notes -- review required, not automatic corruption).";
        return $this->result('TRANSACTION_COVERAGE', 'Transaction-to-Journal Coverage', $status, $msg, $total, $details);
    }
}
