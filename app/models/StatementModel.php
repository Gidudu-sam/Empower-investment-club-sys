<?php
/**
 * StatementModel — Member Savings & Shares Statement
 *
 * Financial year convention: May Y → April Y+1
 * e.g. FY 2026 = 01 May 2026 → 30 April 2027
 */
class StatementModel extends Model
{
    protected string $table      = 'savings';
    protected string $primaryKey = 'id';

    // ── Financial year helpers ────────────────────────────────

    /**
     * Return the start date (01 May) for a given FY start year.
     * FY 2026 → 2026-05-01
     */
    public static function fyStart(int $year): string
    {
        return "{$year}-05-01";
    }

    /**
     * Return the end date (30 April) for a given FY start year.
     * FY 2026 → 2027-04-30
     */
    public static function fyEnd(int $year): string
    {
        return ($year + 1) . '-04-30';
    }

    /**
     * Human label for the statement period.
     * FY 2026 → "May 2026 – April 2027"
     */
    public static function fyLabel(int $year): string
    {
        return "May {$year} – April " . ($year + 1);
    }

    /**
     * Determine which financial year a given date falls in.
     * Dates in Jan-Apr belong to the PREVIOUS May-start year.
     */
    public static function dateToFY(\DateTime $date): int
    {
        $month = (int)$date->format('m');
        $year  = (int)$date->format('Y');
        return $month >= 5 ? $year : $year - 1;
    }

    /**
     * Build a list of available financial years based on savings data,
     * always including the current FY even if no data yet.
     */
    public function availableYears(int $memberId): array
    {
        $currentFY = self::dateToFY(new \DateTime());
        $years     = [$currentFY];

        try {
            // Years from savings
            $stmt = $this->db->prepare(
                "SELECT DISTINCT
                     CASE WHEN MONTH(transaction_date) >= 5
                          THEN YEAR(transaction_date)
                          ELSE YEAR(transaction_date) - 1
                     END AS fy
                 FROM `savings`
                 WHERE member_id = ?
                 ORDER BY fy DESC"
            );
            $stmt->execute([$memberId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $y) {
                $years[] = (int)$y;
            }

            // Years from withdrawals
            $stmt2 = $this->db->prepare(
                "SELECT DISTINCT financial_year FROM `withdrawals`
                 WHERE member_id = ? ORDER BY financial_year DESC"
            );
            $stmt2->execute([$memberId]);
            foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $y) {
                // Convert withdrawal financial_year (calendar year of May start)
                $years[] = (int)$y;
                $years[] = (int)$y - 1; // prior year BF
            }
        } catch (PDOException $e) {}

        $years = array_unique($years);
        rsort($years);
        return array_values($years);
    }

    // ── Retained Savings B/F ─────────────────────────────────

    /**
     * Retained savings brought forward = sum of retained_amount from ALL
     * withdrawals processed BEFORE the current FY.
     * This represents permanent shares accumulated in prior years.
     */
    public function retainedBF(int $memberId, int $fyYear): float
    {
        return $this->openingBalanceAsOf($memberId, self::fyStart($fyYear));
    }

    /**
     * Opening balance as of any arbitrary date -- the general form of
     * retainedBF(), needed for custom (non-financial-year) statement
     * periods. Uses withdrawal_date rather than the coarse integer
     * financial_year column, since a custom period can start mid-year.
     */
    public function openingBalanceAsOf(int $memberId, string $asOfDate): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings`
                 WHERE member_id = ? AND transaction_date < ?"
            );
            $stmt->execute([$memberId, $asOfDate]);
            $totalSavingsBefore = (float)$stmt->fetchColumn();

            $stmt2 = $this->db->prepare(
                "SELECT COALESCE(SUM(withdrawal_amount),0)
                 FROM `withdrawals`
                 WHERE member_id = ? AND withdrawal_date < ?"
            );
            $stmt2->execute([$memberId, $asOfDate]);
            $totalWithdrawnBefore = (float)$stmt2->fetchColumn();

            return max(0, $totalSavingsBefore - $totalWithdrawnBefore);
        } catch (PDOException $e) { return 0; }
    }

    // ── Current FY savings rows ───────────────────────────────

    public function currentYearSavings(int $memberId, int $fyYear): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT transaction_date, (COALESCE(credit,0)-COALESCE(debit,0)) AS amount,
                        payment_method, receipt_number
                 FROM `savings`
                 WHERE member_id = ?
                   AND transaction_date BETWEEN ? AND ?
                 ORDER BY transaction_date ASC, id ASC"
            );
            $stmt->execute([
                $memberId,
                self::fyStart($fyYear),
                self::fyEnd($fyYear),
            ]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Get all transactions (savings credits and withdrawal debits) for a member in a financial year,
     * sorted chronologically ascending.
     */
    public function getTransactions(int $memberId, int $fyYear): array
    {
        return $this->getTransactionsByRange($memberId, self::fyStart($fyYear), self::fyEnd($fyYear));
    }

    /**
     * General form of getTransactions(), for custom (non-financial-year)
     * statement periods -- identical logic, parameterized by explicit dates.
     */
    public function getTransactionsByRange(int $memberId, string $startDate, string $endDate): array
    {
        $txs = [];

        try {
            // Savings deposits (Credits) and Account Adjustments (either direction) —
            // an adjustment row is distinguished by transaction_type='adjustment'
            // and labeled accordingly (Phase 19: must not appear as an ordinary
            // deposit). Existing deposit rows are untouched: transaction_type is
            // never 'adjustment' for them, so this branch is unreachable and
            // output is byte-identical to before for every pre-existing row.
            $stmt = $this->db->prepare(
                "SELECT transaction_date AS tx_date, COALESCE(debit,0) AS debit_amt, COALESCE(credit,0) AS credit_amt,
                        payment_method, receipt_number, transaction_type
                 FROM `savings`
                 WHERE member_id = ? AND transaction_date BETWEEN ? AND ?"
            );
            $stmt->execute([$memberId, $startDate, $endDate]);
            while ($row = $stmt->fetch()) {
                $isAdjustment = $row['transaction_type'] === 'adjustment';
                $isDebitRow   = (float)$row['debit_amt'] > 0;
                $txs[] = [
                    'date'        => $row['tx_date'],
                    'description' => $isAdjustment
                        ? ('Account Adjustment (' . ($isDebitRow ? 'Debit' : 'Credit') . ')')
                        : 'Savings Deposit (' . $row['payment_method'] . ')',
                    'reference'   => $row['receipt_number'] ?: '—',
                    'debit'       => $isDebitRow ? (float)$row['debit_amt'] : 0.0,
                    'credit'      => $isDebitRow ? 0.0 : (float)$row['credit_amt'],
                    'timestamp'   => strtotime($row['tx_date']),
                    'type'        => $isAdjustment ? 'adjustment' : 'savings',
                ];
            }

            // Withdrawals (Debits)
            $stmt2 = $this->db->prepare(
                "SELECT withdrawal_date AS tx_date, withdrawal_amount, payment_method, reference_number, withdrawal_number, 'withdrawal' AS tx_type
                 FROM `withdrawals`
                 WHERE member_id = ? AND withdrawal_date BETWEEN ? AND ?"
            );
            $stmt2->execute([$memberId, $startDate, $endDate]);
            while ($row = $stmt2->fetch()) {
                $txs[] = [
                    'date'        => $row['tx_date'],
                    'description' => 'Annual Withdrawal (' . $row['payment_method'] . ')',
                    'reference'   => $row['withdrawal_number'] ?: ($row['reference_number'] ?: '—'),
                    'debit'       => (float)$row['withdrawal_amount'],
                    'credit'      => 0.0,
                    'timestamp'   => strtotime($row['tx_date']),
                    'type'        => 'withdrawal',
                ];
            }
        } catch (PDOException $e) {}

        // Sort chronologically ascending by date
        usort($txs, function($a, $b) {
            if ($a['timestamp'] === $b['timestamp']) {
                return 0;
            }
            return ($a['timestamp'] < $b['timestamp']) ? -1 : 1;
        });

        return $txs;
    }

    /**
     * Group savings into weekly bands (Monday→Sunday).
     * Returns array of:
     *   [ 'date_range' => '27th May – 2nd Jun 2026', 'amount' => 30000.00 ]
     */
    public function currentYearSavingsWeekly(int $memberId, int $fyYear): array
    {
        $rows = $this->currentYearSavings($memberId, $fyYear);
        if (empty($rows)) return [];

        $weeks = [];

        foreach ($rows as $row) {
            $date = new \DateTime($row['transaction_date']);

            // Find the Monday of this week
            $dow     = (int)$date->format('N'); // 1=Mon … 7=Sun
            $monday  = clone $date;
            $monday->modify('-' . ($dow - 1) . ' days');
            $sunday  = clone $monday;
            $sunday->modify('+6 days');

            $key = $monday->format('Y-m-d');

            if (!isset($weeks[$key])) {
                $weeks[$key] = [
                    'week_start'  => clone $monday,
                    'week_end'    => clone $sunday,
                    'amount'      => 0.0,
                    'receipts'    => [],
                ];
            }
            $weeks[$key]['amount']   += (float)$row['amount'];
            $weeks[$key]['receipts'][] = $row['receipt_number'];
        }

        // Format date ranges
        $result = [];
        foreach ($weeks as $entry) {
            /** @var \DateTime $ws */
            $ws = $entry['week_start'];
            /** @var \DateTime $we */
            $we = $entry['week_end'];

            $startDay   = self::ordinal((int)$ws->format('j'));
            $endDay     = self::ordinal((int)$we->format('j'));

            // Show month on start only if different from end month, else share
            if ($ws->format('M Y') === $we->format('M Y')) {
                // Same month: "27th-2nd May 2026"
                $dateRange = "{$startDay}-{$endDay} {$we->format('M Y')}";
            } else {
                // Spanning two months: "27th May – 2nd Jun 2026"
                $dateRange = "{$startDay} {$ws->format('M')} – {$endDay} {$we->format('M Y')}";
            }

            $result[] = [
                'date_range' => $dateRange,
                'amount'     => $entry['amount'],
                'receipts'   => implode(', ', $entry['receipts']),
            ];
        }

        return $result;
    }

    /** Convert integer to ordinal string: 1→1st, 2→2nd, 3→3rd, 4→4th … */
    public static function ordinal(int $n): string
    {
        $suffix = match(true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default       => 'th',
        };
        return "{$n}{$suffix}";
    }

    public function currentYearTotal(int $memberId, int $fyYear): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings`
                 WHERE member_id = ?
                   AND transaction_date BETWEEN ? AND ?"
            );
            $stmt->execute([
                $memberId,
                self::fyStart($fyYear),
                self::fyEnd($fyYear),
            ]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    // ── Retained shares (from withdrawals in this FY) ────────

    public function retainedThisYear(int $memberId, int $fyYear): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(retained_amount),0)
                 FROM `withdrawals`
                 WHERE member_id = ? AND financial_year = ?"
            );
            $stmt->execute([$memberId, $fyYear]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /**
     * Total retained shares across ALL years up to and including this FY.
     * This is the permanent Share Capital.
     */
    public function totalRetainedShares(int $memberId, int $upToYear): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(retained_amount),0)
                 FROM `withdrawals`
                 WHERE member_id = ? AND financial_year <= ?"
            );
            $stmt->execute([$memberId, $upToYear]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /**
     * Number of shares: assume each share = Shs 20,000 (configurable via settings).
     * Returns [count, share_value].
     */
    public function sharePosition(int $memberId, int $upToYear, float $shareValue = 20000): array
    {
        $totalRetained = $this->totalRetainedShares($memberId, $upToYear);
        $shareCount    = $shareValue > 0 ? floor($totalRetained / $shareValue) : 0;
        return [
            'count'          => (int)$shareCount,
            'total_retained' => $totalRetained,
            'share_value'    => $shareValue,
        ];
    }

    /**
     * Chronological share ledger for a member, up to and including a given
     * FY -- each row is one retained-shares event (from a withdrawal's
     * retained_amount), with a running balance. Mirrors the club's physical
     * "Shares Ledger Card" record: Date / Description / Debit / Credit /
     * Balance. Shares only ever accumulate in this system (no share-debit
     * path exists), so `debit` is always 0 -- kept as a real column rather
     * than omitted so the ledger's shape matches the physical card exactly.
     */
    public function shareLedger(int $memberId, int $uptoYear): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT withdrawal_date, withdrawal_number, retained_amount
                 FROM `withdrawals`
                 WHERE member_id = ? AND financial_year <= ? AND retained_amount > 0
                 ORDER BY withdrawal_date ASC, id ASC"
            );
            $stmt->execute([$memberId, $uptoYear]);

            $balance = 0.0;
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $balance += (float)$r['retained_amount'];
                $rows[] = [
                    'date'        => $r['withdrawal_date'],
                    'description' => 'Shares Retained (' . $r['withdrawal_number'] . ')',
                    'debit'       => 0.0,
                    'credit'      => (float)$r['retained_amount'],
                    'balance'     => $balance,
                ];
            }
            return $rows;
        } catch (PDOException $e) { return []; }
    }

    // ── All active FY years across all members ────────────────

    public function allFinancialYears(): array
    {
        $currentFY = self::dateToFY(new \DateTime());
        $years     = [$currentFY];

        try {
            $stmt = $this->db->query(
                "SELECT DISTINCT
                     CASE WHEN MONTH(transaction_date) >= 5
                          THEN YEAR(transaction_date)
                          ELSE YEAR(transaction_date) - 1
                     END AS fy
                 FROM `savings` ORDER BY fy DESC"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $y) {
                $years[] = (int)$y;
            }
        } catch (PDOException $e) {}

        $years = array_unique($years);
        rsort($years);
        return array_values($years);
    }

    /**
     * Resolve the requested statement period: either a financial year
     * (existing behavior, default) or an explicit custom date range
     * (?range_mode=custom&date_from=...&date_to=...) -- e.g. "last 3
     * months" or "1 week" ranges a member/staff may ask for, which don't
     * align to the May-April financial year. Relocated here (unchanged)
     * from StatementController's private resolvePeriod() so the member
     * portal's own print action can offer the exact same range options,
     * not a second parsing/validation implementation.
     * Returns [startDate, endDate, periodLabel, fyYearForSharePosition].
     * Share position is always computed as of the FY containing the
     * period's end date -- share capital is a point-in-time accumulation,
     * not something a custom transaction window changes the meaning of.
     */
    public static function resolvePeriod(array $get): array
    {
        if (($get['range_mode'] ?? 'fy') === 'custom' && !empty($get['date_from']) && !empty($get['date_to'])) {
            $from = $get['date_from'];
            $to   = $get['date_to'];
            if (strtotime($from) !== false && strtotime($to) !== false && strtotime($from) <= strtotime($to)) {
                $label = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
                return [$from, $to, $label, self::dateToFY(new \DateTime($to))];
            }
        }
        $fyYear = (int)($get['year'] ?? self::dateToFY(new \DateTime()));
        return [self::fyStart($fyYear), self::fyEnd($fyYear), self::fyLabel($fyYear), $fyYear];
    }

    /**
     * Full savings/shares/loans statement dataset for one member over one
     * period -- relocated here (unchanged) from StatementController's
     * private buildStatementData() so the member portal's own print action
     * can reuse the exact same, already-audited calculation rather than a
     * second copy of it. StatementController now just delegates to this.
     * LoanModel is taken as a parameter rather than a constructor
     * dependency so this model gains no new hard coupling.
     */
    public function buildMemberStatement(array $member, string $startDate, string $endDate, string $periodLabel, int $fyYearForShares, float $shareValue, LoanModel $loanModel): array
    {
        $memberId = (int)$member['id'];

        // Opening Balance
        $openingBalance = $this->openingBalanceAsOf($memberId, $startDate);

        // Transactions (Savings credits & Withdrawal debits)
        $rawTransactions = $this->getTransactionsByRange($memberId, $startDate, $endDate);

        $runningBalance = $openingBalance;
        $totalCredits   = 0.0;
        $totalDebits    = 0.0;
        $transactions   = [];

        foreach ($rawTransactions as $tx) {
            $totalCredits   += $tx['credit'];
            $totalDebits    += $tx['debit'];
            $runningBalance = $runningBalance - $tx['debit'] + $tx['credit'];
            $tx['balance']  = $runningBalance;
            $transactions[] = $tx;
        }

        $closingBalance = $openingBalance + $totalCredits - $totalDebits;
        $totalSavings   = $closingBalance;

        $depositCount    = 0;
        $withdrawalCount = 0;
        foreach ($transactions as $tx) {
            if ($tx['credit'] > 0) $depositCount++;
            if ($tx['debit'] > 0) $withdrawalCount++;
        }
        $netChange = $totalCredits - $totalDebits;

        // Shares -- always a point-in-time position as of the FY containing
        // the period's end date, regardless of whether the transaction
        // window itself is a financial year or a custom range.
        $sharePos        = $this->sharePosition($memberId, $fyYearForShares, $shareValue);
        $shareCapital    = $sharePos['total_retained'];
        $shareCount      = $sharePos['count'];
        $shareLedgerRows = $this->shareLedger($memberId, $fyYearForShares);

        $totalMemberAssets = $totalSavings + $shareCapital;

        // Loans
        $loanHistory    = $loanModel->memberLoanHistory($memberId, 20);
        $activeLoan     = $loanModel->memberActiveLoan($memberId);
        $totalBorrowed  = 0.0;
        $outstandingBal = 0.0;
        foreach ($loanHistory as $ln) {
            $totalBorrowed  += (float)$ln['loan_amount'];
            $outstandingBal += (float)$ln['outstanding'];
        }
        $loanCount = count($loanHistory);
        $loanStatus = $activeLoan ? ucfirst($activeLoan['status']) : 'None';

        return [
            'member'            => $member,
            'fyYear'            => $fyYearForShares,
            'fyLabel'           => $periodLabel,
            'fyStart'           => $startDate,
            'fyEnd'             => $endDate,
            'dateIssued'        => date('d F Y'),
            'openingBalance'    => $openingBalance,
            'totalCredits'      => $totalCredits,
            'totalDebits'       => $totalDebits,
            'closingBalance'    => $closingBalance,
            'transactions'      => $transactions,
            'depositCount'      => $depositCount,
            'withdrawalCount'   => $withdrawalCount,
            'netChange'         => $netChange,
            'retainedBF'        => $openingBalance,
            'currentYearSavings'=> $rawTransactions,
            'currentYearTotal'  => $totalCredits,
            'totalSavings'      => $totalSavings,
            'shareCapital'      => $shareCapital,
            'shareCount'        => $shareCount,
            'shareValue'        => $shareValue,
            'shareLedgerRows'   => $shareLedgerRows,
            'totalMemberAssets' => $totalMemberAssets,
            'loanHistory'       => $loanHistory,
            'loanCount'         => $loanCount,
            'totalBorrowed'     => $totalBorrowed,
            'outstandingBal'    => $outstandingBal,
            'loanStatus'        => $loanStatus,
        ];
    }
}
