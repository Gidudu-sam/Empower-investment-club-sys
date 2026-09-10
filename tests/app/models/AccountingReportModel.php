<?php
/**
 * AccountingReportModel — Trial Balance / General Ledger / Income Statement /
 * Balance Sheet, read exclusively from the ledger (accounts, journal_entries,
 * journal_lines, opening_balance_batches, opening_balances). Never reads
 * operational tables (loans, savings, loan_repayments, expenses) — every
 * method here is a plain SELECT, nothing is ever written.
 *
 * $filters accepted by every method: financial_year_id, accounting_period_id,
 * date_from, date_to — all optional.
 */
class AccountingReportModel extends Model
{
    protected string $table = 'accounts';

    /**
     * Build the JOIN-condition fragment + params applied to journal_entries
     * inside a LEFT JOIN (never WHERE), so accounts with no matching activity
     * still appear with zero balances instead of being dropped.
     */
    /**
     * Build a human-readable description of the active filters for report
     * print headers, e.g. "FY 2026 — Q3 2026 — 2026-07-01 to 2026-09-30".
     */
    public static function describeFilters(array $filters, array $financialYears, array $periods): string
    {
        $parts = [];

        if (!empty($filters['financial_year_id'])) {
            foreach ($financialYears as $fy) {
                if ((int)$fy['id'] === (int)$filters['financial_year_id']) {
                    $parts[] = $fy['name'] . ($fy['is_legacy'] ? ' (Legacy)' : '');
                    break;
                }
            }
        } else {
            $parts[] = 'All financial years';
        }

        if (!empty($filters['accounting_period_id'])) {
            foreach ($periods as $p) {
                if ((int)$p['id'] === (int)$filters['accounting_period_id']) {
                    $parts[] = $p['name'];
                    break;
                }
            }
        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $parts[] = ($filters['date_from'] ?? '...') . ' to ' . ($filters['date_to'] ?? '...');
        }

        return implode(' — ', $parts);
    }

    /**
     * Every normal accounting report (Trial Balance, General Ledger, Income
     * Statement, Balance Sheet) is driven by this single filter, so the
     * authoritative-population restriction below applies uniformly rather
     * than being repeated (and risking drift) in four places. Journal
     * entries classified 'dummy' or 'unknown' (Stage 24/25 forensic
     * classification — see results/stage24_.../02, stage25_.../03) are
     * real, physically-preserved rows; they are simply never counted by a
     * normal report. Nothing here deletes, reverses, or alters them.
     */
    private function joinFilterSql(array $filters): array
    {
        $sql = " AND je.data_classification = 'live'";
        $params = [];

        if (!empty($filters['financial_year_id'])) {
            $sql .= ' AND je.financial_year_id = ?';
            $params[] = (int)$filters['financial_year_id'];
        }
        if (!empty($filters['accounting_period_id'])) {
            $sql .= ' AND je.accounting_period_id = ?';
            $params[] = (int)$filters['accounting_period_id'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND je.entry_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND je.entry_date <= ?';
            $params[] = $filters['date_to'];
        }

        return [$sql, $params];
    }

    /**
     * Filtered debit/credit activity per account, keyed by account_id.
     * Uses a genuinely filtered (WHERE-based) query, then is looked up
     * per-account in PHP — this is what correctly restricts which lines
     * count, unlike appending filters to a LEFT JOIN's ON clause (which
     * only nulls out journal_entries columns, not the already-joined
     * journal_lines row — a real bug caught during Step 6 testing).
     */
    private function accountMovements(array $filters): array
    {
        [$filterSql, $filterParams] = $this->joinFilterSql($filters);
        $whereSql = $filterSql !== '' ? ('WHERE ' . ltrim(substr($filterSql, 5))) : '';

        $sql = "
            SELECT jl.account_id,
                   COALESCE(SUM(jl.debit),0) AS total_debit,
                   COALESCE(SUM(jl.credit),0) AS total_credit
            FROM `journal_lines` jl
            JOIN `journal_entries` je ON je.id = jl.journal_entry_id
            {$whereSql}
            GROUP BY jl.account_id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($filterParams);

        $byAccount = [];
        foreach ($stmt->fetchAll() as $row) {
            $byAccount[(int)$row['account_id']] = $row;
        }
        return $byAccount;
    }

    /**
     * Trial Balance: every account (zero-activity accounts included), net
     * debit/credit per classic presentation, plus grand totals.
     */
    public function trialBalance(array $filters): array
    {
        $movements = $this->accountMovements($filters);
        $accounts = $this->db->query("SELECT id, code, name, type, normal_balance FROM `accounts` ORDER BY code")->fetchAll();

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($accounts as $a) {
            $m = $movements[(int)$a['id']] ?? ['total_debit' => 0, 'total_credit' => 0];
            $net = round((float)$m['total_debit'] - (float)$m['total_credit'], 2);
            $a['debit']  = $net > 0 ? $net : 0.0;
            $a['credit'] = $net < 0 ? -$net : 0.0;
            $totalDebit  += $a['debit'];
            $totalCredit += $a['credit'];
            $rows[] = $a;
        }

        return [
            'accounts'     => $rows,
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'difference'   => round($totalDebit - $totalCredit, 2),
            'balanced'     => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    /**
     * General Ledger for a single account, chronological, with a running
     * balance signed by the account's normal_balance.
     */
    public function generalLedgerForAccount(int $accountId, array $filters): array
    {
        [$filterSql, $filterParams] = $this->joinFilterSql($filters);

        $account = (new AccountModel())->find($accountId);
        if (!$account) {
            throw new InvalidArgumentException("Account id {$accountId} does not exist.");
        }

        $sql = "
            SELECT jl.id, jl.debit, jl.credit, jl.description AS line_description,
                   je.entry_number, je.entry_date, je.description AS entry_description,
                   je.source_module, je.source_reference_type, je.source_reference_id
            FROM `journal_lines` jl
            JOIN `journal_entries` je ON je.id = jl.journal_entry_id
            WHERE jl.account_id = ? {$filterSql}
            ORDER BY je.entry_date, je.id, jl.id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$accountId], $filterParams));
        $lines = $stmt->fetchAll();

        $running = 0.0;
        $debitNormal = $account['normal_balance'] === 'debit';
        foreach ($lines as &$l) {
            $delta = $debitNormal
                ? ((float)$l['debit'] - (float)$l['credit'])
                : ((float)$l['credit'] - (float)$l['debit']);
            $running += $delta;
            $l['running_balance'] = round($running, 2);
        }
        unset($l);

        return ['account' => $account, 'lines' => $lines, 'closing_balance' => round($running, 2)];
    }

    /**
     * All journal lines across all accounts, chronological — the "all
     * entries" view when no single account is selected. No running balance
     * (not meaningful across mixed accounts).
     */
    public function journalListing(array $filters): array
    {
        [$filterSql, $filterParams] = $this->joinFilterSql($filters);
        // joinFilterSql() prefixes with "AND" for the LEFT JOIN use-case;
        // here journal_entries is the driving table so this is a WHERE clause.
        $whereSql = $filterSql !== '' ? ('WHERE ' . ltrim(substr($filterSql, 5))) : '';

        $sql = "
            SELECT je.id AS entry_id, je.entry_number, je.entry_date, je.description AS entry_description,
                   je.source_module, je.source_reference_type, je.source_reference_id,
                   jl.id AS line_id, jl.account_id, a.code AS account_code, a.name AS account_name,
                   jl.debit, jl.credit, jl.description AS line_description
            FROM `journal_entries` je
            JOIN `journal_lines` jl ON jl.journal_entry_id = je.id
            JOIN `accounts` a ON a.id = jl.account_id
            {$whereSql}
            ORDER BY je.entry_date, je.id, jl.id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($filterParams);
        return $stmt->fetchAll();
    }

    /**
     * Income Statement: every income/expense account with its net movement
     * (signed by normal_balance so a positive number always means "more of
     * what this account measures"), plus totals and net surplus/deficit.
     */
    public function incomeStatement(array $filters): array
    {
        $movements = $this->accountMovements($filters);
        $accounts = $this->db->query("
            SELECT id, code, name, type, subtype, normal_balance FROM `accounts`
            WHERE type IN ('income','expense') ORDER BY type, code
        ")->fetchAll();

        $income = [];
        $expense = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;

        foreach ($accounts as $r) {
            $m = $movements[(int)$r['id']] ?? ['total_debit' => 0, 'total_credit' => 0];
            $debit = (float)$m['total_debit'];
            $credit = (float)$m['total_credit'];
            $net = $r['normal_balance'] === 'credit' ? round($credit - $debit, 2) : round($debit - $credit, 2);
            $r['net_amount'] = $net;

            if ($r['type'] === 'income') {
                $income[] = $r;
                $totalIncome += $net;
            } else {
                $expense[] = $r;
                $totalExpense += $net;
            }
        }

        return [
            'income'         => $income,
            'expense'        => $expense,
            'total_income'   => round($totalIncome, 2),
            'total_expense'  => round($totalExpense, 2),
            'net_surplus'    => round($totalIncome - $totalExpense, 2),
        ];
    }

    /**
     * Balance Sheet: Assets/Liabilities/Equity, closing = posted opening
     * balance + net journal movement. If no opening balance batch has been
     * posted for the selected financial year, returns
     * openingBalancesEstablished=false and no fabricated figures.
     */
    public function balanceSheet(array $filters): array
    {
        $financialYearId = !empty($filters['financial_year_id']) ? (int)$filters['financial_year_id'] : null;

        $established = false;
        if ($financialYearId !== null) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `opening_balance_batches` WHERE financial_year_id = ? AND status = 'posted'"
            );
            $stmt->execute([$financialYearId]);
            $established = (int)$stmt->fetchColumn() > 0;
        }

        if (!$established) {
            return [
                'openingBalancesEstablished' => false,
                'assets' => [], 'liabilities' => [], 'equity' => [],
                'total_assets' => null, 'total_liabilities' => null, 'total_equity' => null,
                'difference' => null, 'balanced' => null,
            ];
        }

        $openingSql = "
            SELECT ob.account_id, COALESCE(SUM(ob.debit),0) AS ob_debit, COALESCE(SUM(ob.credit),0) AS ob_credit
            FROM `opening_balances` ob
            JOIN `opening_balance_batches` bb ON bb.id = ob.batch_id
            WHERE bb.status = 'posted' AND bb.financial_year_id = ?
            GROUP BY ob.account_id
        ";
        $stmt = $this->db->prepare($openingSql);
        $stmt->execute([$financialYearId]);
        $openingByAccount = [];
        foreach ($stmt->fetchAll() as $row) {
            $openingByAccount[(int)$row['account_id']] = $row;
        }

        // Movement here must exclude the opening-balance posting itself --
        // its amount is already counted via $openingByAccount above. The
        // shared accountMovements() helper is deliberately NOT reused here
        // (Trial Balance/General Ledger/Income Statement correctly DO want
        // every journal line, opening balance included, since they don't
        // separately add an "opening" column the way this report does) --
        // this bug was caught by testing Test F end-to-end: a posted
        // opening balance was being added once from `opening_balances` and
        // a second time from `journal_lines`, doubling every account that
        // had one.
        [$filterSql, $filterParams] = $this->joinFilterSql($filters);
        $whereSql = $filterSql !== '' ? ('WHERE ' . ltrim(substr($filterSql, 5))) : '';
        $whereSql .= ($whereSql === '' ? 'WHERE ' : ' AND ') . "je.source_module != 'opening_balances'";
        $movementStmt = $this->db->prepare("
            SELECT jl.account_id, COALESCE(SUM(jl.debit),0) AS total_debit, COALESCE(SUM(jl.credit),0) AS total_credit
            FROM `journal_lines` jl
            JOIN `journal_entries` je ON je.id = jl.journal_entry_id
            {$whereSql}
            GROUP BY jl.account_id
        ");
        $movementStmt->execute($filterParams);
        $movements = [];
        foreach ($movementStmt->fetchAll() as $row) {
            $movements[(int)$row['account_id']] = $row;
        }

        $accounts = $this->db->query("
            SELECT id, code, name, type, subtype, normal_balance FROM `accounts`
            WHERE type IN ('asset','liability','equity') ORDER BY type, code
        ")->fetchAll();

        $assets = []; $liabilities = []; $equity = [];
        $totalAssets = 0.0; $totalLiabilities = 0.0; $totalEquity = 0.0;

        foreach ($accounts as $r) {
            $m = $movements[(int)$r['id']] ?? ['total_debit' => 0, 'total_credit' => 0];
            $debitNormal = $r['normal_balance'] === 'debit';
            $opening = $openingByAccount[(int)$r['id']] ?? ['ob_debit' => 0, 'ob_credit' => 0];
            $openingAmount = $debitNormal
                ? ((float)$opening['ob_debit'] - (float)$opening['ob_credit'])
                : ((float)$opening['ob_credit'] - (float)$opening['ob_debit']);
            $movement = $debitNormal
                ? ((float)$m['total_debit'] - (float)$m['total_credit'])
                : ((float)$m['total_credit'] - (float)$m['total_debit']);
            $closing = round($openingAmount + $movement, 2);

            // $closing is always a positive magnitude in the account's own
            // normal_balance direction (unchanged behavior -- Stage 19-A).
            // A contra_asset (e.g. 1185, credit-normal) must still REDUCE
            // Total Assets even though its own closing_balance is positive;
            // driven by `subtype`, not by any specific account code, so a
            // future second contra-asset is handled automatically.
            $isContraAsset = $r['type'] === 'asset' && $r['subtype'] === 'contra_asset';

            $r['opening_balance'] = round($openingAmount, 2);
            $r['movement'] = round($movement, 2);
            $r['closing_balance'] = $closing;
            $r['is_contra_asset'] = $isContraAsset;

            if ($r['type'] === 'asset') {
                $assets[] = $r;
                $totalAssets += $isContraAsset ? -$closing : $closing;
            } elseif ($r['type'] === 'liability') {
                $liabilities[] = $r; $totalLiabilities += $closing;
            } else {
                $equity[] = $r; $totalEquity += $closing;
            }
        }

        $totalAssets = round($totalAssets, 2);
        $totalLiabilities = round($totalLiabilities, 2);
        $totalEquity = round($totalEquity, 2);
        $difference = round($totalAssets - ($totalLiabilities + $totalEquity), 2);

        return [
            'openingBalancesEstablished' => true,
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'total_assets' => $totalAssets, 'total_liabilities' => $totalLiabilities, 'total_equity' => $totalEquity,
            'difference' => $difference, 'balanced' => abs($difference) < 0.01,
        ];
    }
}
