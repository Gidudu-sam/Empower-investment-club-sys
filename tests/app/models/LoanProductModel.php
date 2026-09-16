<?php
/**
 * LoanProductModel — Loan Products, Interest Engine & Business Loan Tracking
 */
class LoanProductModel extends Model
{
    protected string $table      = 'loan_product_settings';
    protected string $primaryKey = 'id';

    // ================================================================
    // INTEREST BRACKET ENGINE
    // ================================================================

    /**
     * Determine the monthly interest rate for a product/amount, per the
     * approved precedence: `loan_product_rules.interest_type` decides
     * whether this product is tiered (look up `loan_interest_brackets`)
     * or flat-rate (use `loan_product_rules.flat_interest_rate` directly
     * — no generic/hardcoded fallback for products without a bracket row).
     *
     * @throws InvalidArgumentException if the product has no rule row,
     *         or (for a tiered product) the amount falls in a gap between
     *         configured brackets. Callers must not treat a missing rate
     *         as "use some default" — see Stage 9 audit for why silently
     *         substituting an unrelated rate is unacceptable here.
     */
    public function getInterestRate(int $loanTypeId, float $amount): float
    {
        $stmt = $this->db->prepare(
            "SELECT `interest_type`, `flat_interest_rate` FROM `loan_product_rules` WHERE `loan_type_id` = ? LIMIT 1"
        );
        $stmt->execute([$loanTypeId]);
        $rule = $stmt->fetch();

        if (!$rule) {
            throw new InvalidArgumentException(
                "No product rule is configured for loan_type_id {$loanTypeId} — cannot determine an interest rate."
            );
        }

        if ($rule['interest_type'] === 'flat_rate') {
            if ($rule['flat_interest_rate'] === null) {
                throw new InvalidArgumentException(
                    "Loan type {$loanTypeId} is configured as flat_rate but has no flat_interest_rate set."
                );
            }
            return (float)$rule['flat_interest_rate'];
        }

        // Tiered: look up loan_interest_brackets and require an exact match.
        $stmt = $this->db->prepare(
            "SELECT `monthly_rate`, `min_amount`, `max_amount`
             FROM `loan_interest_brackets`
             WHERE `loan_type_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC"
        );
        $stmt->execute([$loanTypeId]);
        $brackets = $stmt->fetchAll();

        if (empty($brackets)) {
            throw new InvalidArgumentException(
                "Loan type {$loanTypeId} is configured as tiered but has no interest brackets defined."
            );
        }

        foreach ($brackets as $b) {
            $min = (float)$b['min_amount'];
            $max = (float)$b['max_amount'];
            if ($amount >= $min && ($max === 0.0 || $amount <= $max)) {
                return (float)$b['monthly_rate'];
            }
        }

        // No bracket matches -- this amount falls in an unconfigured gap
        // between two tiers. Fail clearly rather than silently returning
        // an unrelated bracket's rate (see Stage 9 audit finding).
        throw new InvalidArgumentException(
            'No interest bracket is configured for UGX ' . number_format($amount, 2) .
            ' — this amount falls in a gap between the approved tiers. ' .
            'Please contact an administrator to confirm the correct rate before proceeding.'
        );
    }

    /**
     * Get all brackets for a loan product (for display/settings).
     */
    public function getBrackets(int $loanTypeId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `loan_interest_brackets` WHERE `loan_type_id`=? ORDER BY `sort_order`"
            );
            $stmt->execute([$loanTypeId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Get all brackets grouped by product.
     */
    public function getAllBrackets(): array
    {
        try {
            return $this->db->query(
                "SELECT lib.*, lt.name AS loan_type_name
                 FROM `loan_interest_brackets` lib
                 JOIN `loan_types` lt ON lt.id = lib.loan_type_id
                 ORDER BY lib.loan_type_id, lib.sort_order"
            )->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ================================================================
    // LOAN CALCULATION ENGINE
    // ================================================================

    /**
     * Calculate loan details based on product rules.
     *
     * Normal Loan & Asset Financing: Principal + Interest + Processing Fee, divided into monthly installments.
     * Business Loan: Interest paid monthly, principal remains until end.
     */
    public function calculateLoan(int $loanTypeId, float $amount, int $periodMonths): array
    {
        $monthlyRate = $this->getInterestRate($loanTypeId, $amount);
        $procFeePct  = $this->processingFeePct();

        // Get repayment type
        $repaymentType = 'installment';
        try {
            $stmt = $this->db->prepare("SELECT `repayment_type` FROM `loan_types` WHERE `id`=?");
            $stmt->execute([$loanTypeId]);
            $row = $stmt->fetch();
            if ($row) $repaymentType = $row['repayment_type'];
        } catch (PDOException $e) {}

        $monthlyInterest = $amount * ($monthlyRate / 100);
        $totalInterest   = $monthlyInterest * $periodMonths;
        $processingFee   = round($amount * ($procFeePct / 100), 2);

        if ($repaymentType === 'interest_only') {
            // Business Loan: pay interest monthly, principal at end
            $totalPayable       = $amount + $totalInterest; // Processing fee NOT included
            $monthlyInstallment = $monthlyInterest; // Only interest each month
        } else {
            // Normal & Asset Financing: equal monthly installments
            $totalPayable       = $amount + $totalInterest; // Processing fee NOT included
            $monthlyInstallment = $periodMonths > 0 ? round($totalPayable / $periodMonths, 2) : $totalPayable;
        }

        return [
            'loan_type_id'        => $loanTypeId,
            'repayment_type'      => $repaymentType,
            'loan_amount'         => $amount,
            'interest_rate'       => $monthlyRate,
            'monthly_interest'    => round($monthlyInterest, 2),
            'interest_amount'     => round($totalInterest, 2),
            'processing_fee'      => $processingFee,
            'processing_fee_pct'  => $procFeePct,
            'total_payable'       => round($totalPayable, 2),
            'monthly_installment' => round($monthlyInstallment, 2),
            'loan_period_months'  => $periodMonths,
        ];
    }

    /**
     * Re-derive a calculateLoan() result for a different rate -- the exact
     * same formula calculateLoan() itself uses (flat rate x principal),
     * parameterized on rate instead of looking one up. This exists so the
     * Stage 9.1 controlled rate-override path never re-implements the
     * arithmetic a second time: it computes the authoritative suggested
     * rate via calculateLoan(), then calls this to apply an authorized
     * user's approved rate on top of the same $calc array.
     *
     * $calc must be a calculateLoan()-shaped array (loan_amount,
     * loan_period_months, repayment_type are read; everything else is
     * carried through unchanged, e.g. processing_fee/processing_fee_pct).
     */
    public function applyRate(array $calc, float $rate): array
    {
        $amount = (float)$calc['loan_amount'];
        $months = (int)$calc['loan_period_months'];

        $monthlyInterest = $amount * ($rate / 100);
        $totalInterest   = $monthlyInterest * $months;
        $totalPayable    = $amount + $totalInterest;

        $monthlyInstallment = ($calc['repayment_type'] ?? '') === 'interest_only'
            ? $monthlyInterest
            : ($months > 0 ? round($totalPayable / $months, 2) : $totalPayable);

        return array_merge($calc, [
            'interest_rate'       => $rate,
            'monthly_interest'    => round($monthlyInterest, 2),
            'interest_amount'     => round($totalInterest, 2),
            'total_payable'       => round($totalPayable, 2),
            'monthly_installment' => round($monthlyInstallment, 2),
        ]);
    }

    /**
     * The single authoritative loan-processing-fee percentage, read from
     * the same global `fees` row (frequency='per_loan') that
     * FeeModel::chargeLoanProcessingFee() already uses to actually charge
     * the member. Previously this calculation read a separate,
     * independently-configured `loan_product_settings.processing_fee_pct`
     * column that could (and did, for Business Loan) disagree with what
     * was actually charged. One lookup now feeds both, so the two cannot
     * drift apart again. Falls back to 3.0 only if the global fee row is
     * ever missing/inactive, matching the previous hardcoded default.
     */
    public function processingFeePct(): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT `amount` FROM `fees` WHERE `frequency` = 'per_loan' AND `fee_type` = 'percentage' AND `is_active` = 1 LIMIT 1"
            );
            $stmt->execute();
            $pct = $stmt->fetchColumn();
            return $pct !== false ? (float)$pct : 3.0;
        } catch (PDOException $e) {
            return 3.0;
        }
    }

    /**
     * The currently-configured grace period (in months) for a loan
     * product, straight from loan_product_rules. Callers that create a
     * loan must read this once, at creation time, and store it on the
     * loan itself (loans.grace_period_months) rather than re-reading it
     * later -- a snapshot, so a subsequent change to product config never
     * retroactively alters an existing loan's schedule. Returns 0 (no
     * grace) if the product has no configured rule row.
     */
    public function graceMonthsFor(int $loanTypeId): int
    {
        try {
            $stmt = $this->db->prepare("SELECT `grace_period_months` FROM `loan_product_rules` WHERE `loan_type_id` = ? LIMIT 1");
            $stmt->execute([$loanTypeId]);
            $months = $stmt->fetchColumn();
            return $months !== false ? (int)$months : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * When a product requires security of a specific, fixed kind (e.g.
     * Start-Up Loan's 'chattel'), the submitted security_type must not be
     * trusted as free text -- it is forced to this fixed value. Returns
     * null when the product either doesn't require security or leaves the
     * type open (e.g. Asset Financing's "asset or alternative pledge",
     * stored as a NULL security_type -- any submitted type is accepted
     * there).
     */
    public function fixedSecurityTypeFor(int $loanTypeId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT `security_type` FROM `loan_product_rules` WHERE `loan_type_id` = ? AND `requires_security` = 1 LIMIT 1"
            );
            $stmt->execute([$loanTypeId]);
            $type = $stmt->fetchColumn();
            return ($type !== false && $type !== null && $type !== '') ? (string)$type : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    // ================================================================
    // PRODUCT-RULE VALIDATION (Stage 9 — net-new: amount/duration/
    // eligibility were not enforced anywhere before this)
    // ================================================================

    /**
     * Validate a proposed loan against its product's approved amount
     * range, duration range, and (where the product requires one) minimum
     * savings tenure. Returns a list of human-readable error strings;
     * empty means valid. Never throws -- callers merge the result into
     * their own form-error array.
     *
     * $savingsTenureMonths is null when the member has no compulsory
     * savings account at all; a product with a savings-history
     * requirement then fails with a specific "no savings account" message
     * rather than being silently skipped.
     */
    public function validateAgainstProductRules(
        int $loanTypeId,
        float $amount,
        int $periodMonths,
        ?float $savingsTenureMonths,
        array $eligibility = []
    ): array {
        $errors = [];

        $stmt = $this->db->prepare("SELECT * FROM `loan_product_rules` WHERE `loan_type_id` = ? LIMIT 1");
        $stmt->execute([$loanTypeId]);
        $rule = $stmt->fetch();

        if (!$rule) {
            // No rule configured for this product -- nothing to validate against.
            return $errors;
        }

        $minAmount = (float)$rule['min_amount'];
        $maxAmount = $rule['max_amount'] !== null ? (float)$rule['max_amount'] : null;
        if ($amount < $minAmount) {
            $errors[] = sprintf('Loan amount must be at least UGX %s for this product.', number_format($minAmount, 2));
        } elseif ($maxAmount !== null && $amount > $maxAmount) {
            $errors[] = sprintf('Loan amount must not exceed UGX %s for this product.', number_format($maxAmount, 2));
        }

        $minPeriod = (int)$rule['min_period_months'];
        $maxPeriod = $rule['max_period_months'] !== null ? (int)$rule['max_period_months'] : null;
        if ($periodMonths < $minPeriod) {
            $errors[] = "This product requires a minimum period of {$minPeriod} month(s).";
        } elseif ($maxPeriod !== null && $periodMonths > $maxPeriod) {
            $errors[] = "This product allows a maximum period of {$maxPeriod} month(s).";
        }

        $minSavingsMonths = (int)($rule['min_savings_months'] ?? 0);
        if ($minSavingsMonths > 0) {
            if ($savingsTenureMonths === null) {
                $errors[] = "This product requires the member to have an active compulsory savings account of more than {$minSavingsMonths} months' standing; no such account was found.";
            } elseif (!($savingsTenureMonths > $minSavingsMonths)) {
                // Strictly greater than, per the approved "more than N months" wording.
                $errors[] = "This product requires more than {$minSavingsMonths} months of savings history; this member has " . number_format($savingsTenureMonths, 1) . ' months.';
            }
        }

        // Eligibility gates (Stage 9 — these flags/columns were already
        // configured in loan_product_rules and mirrored on `loans`, but
        // were read by no code anywhere before this). Each gate is only
        // evaluated when the product's own row requires it.

        if ((int)($rule['requires_income_source'] ?? 0) === 1) {
            if (empty($eligibility['income_source'])) {
                $errors[] = 'This product requires a stated source of income (salary or business).';
            }
        }

        $contributionPct = $rule['member_contribution_pct'] !== null ? (float)$rule['member_contribution_pct'] : null;
        if ($contributionPct !== null && $contributionPct > 0) {
            $assetPrice   = isset($eligibility['asset_purchase_price']) ? (float)$eligibility['asset_purchase_price'] : 0.0;
            $contribution = isset($eligibility['member_contribution']) ? (float)$eligibility['member_contribution'] : 0.0;
            if ($assetPrice <= 0) {
                $errors[] = 'This product requires the asset purchase price to be recorded, to verify the required member contribution.';
            } elseif ($contribution <= 0) {
                $errors[] = sprintf('This product requires a member contribution of at least %s%% of the asset price.', rtrim(rtrim(number_format($contributionPct, 2), '0'), '.'));
            } else {
                $requiredContribution = round($assetPrice * ($contributionPct / 100), 2);
                if ($contribution < $requiredContribution) {
                    $errors[] = sprintf(
                        'This product requires a member contribution of at least UGX %s (%s%% of the UGX %s asset price); UGX %s was recorded.',
                        number_format($requiredContribution, 2),
                        rtrim(rtrim(number_format($contributionPct, 2), '0'), '.'),
                        number_format($assetPrice, 2),
                        number_format($contribution, 2)
                    );
                }
            }
        }

        if ((int)($rule['requires_security'] ?? 0) === 1) {
            $fixedType   = $rule['security_type'] !== null ? (string)$rule['security_type'] : null;
            $description = trim((string)($eligibility['security_description'] ?? ''));
            if ($description === '') {
                $errors[] = $fixedType !== null
                    ? "This product requires {$fixedType} security to be recorded before it can be approved."
                    : 'This product requires security (the financed asset or an alternative pledge) to be recorded before it can be approved.';
            }
        }

        if ((int)($rule['requires_weekly_savings'] ?? 0) === 1) {
            $weekly = isset($eligibility['weekly_savings_commitment']) ? (float)$eligibility['weekly_savings_commitment'] : 0.0;
            if ($weekly <= 0) {
                $errors[] = 'This product requires a weekly savings commitment amount to be recorded. ' .
                    'Note: the exact consistency/regularity threshold for monitoring this commitment after disbursement is not yet defined by the business — only the commitment amount itself is captured and required here (BUSINESS_SAVINGS_CONSISTENCY_RULE_REQUIRES_OWNER_DEFINITION).';
            }
        }

        return $errors;
    }

    // ================================================================
    // PRODUCT SETTINGS
    // ================================================================

    /**
     * The authoritative per-product info the loan form's rate-prediction
     * UI needs (amount/period limits, repayment type, grace period, and
     * -- for a flat-rate product -- its single rate as a synthetic one-row
     * bracket matching the tiered-product bracket shape). Sourced from
     * `loan_product_rules`, which (unlike `loan_product_settings`, which
     * only ever had rows for the original 3 products) has a row for every
     * one of the 7 active loan types. Stage 9.1: `getSettings()` below was
     * the only thing the loan form's product-selection AJAX call used, and
     * it silently returned nothing for Start-Up/Agricultural/Executive/
     * Emergency, so their rate/limits never appeared client-side even
     * though the server-side engine has always calculated them correctly.
     */
    public function getProductInfo(int $loanTypeId): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT lpr.*, lt.name AS loan_type_name, lt.repayment_type
                 FROM `loan_product_rules` lpr
                 JOIN `loan_types` lt ON lt.id = lpr.loan_type_id
                 WHERE lpr.loan_type_id = ? LIMIT 1"
            );
            $stmt->execute([$loanTypeId]);
            $row = $stmt->fetch();
            if (!$row) return false;
            $row['processing_fee_pct'] = $this->processingFeePct();
            return $row;
        } catch (PDOException $e) { return false; }
    }

    /**
     * Brackets for a product, in the shape the loan form's JS already
     * understands: real tiered rows for a tiered product, or one synthetic
     * row (min=0, max=0, monthly_rate=the flat rate) for a flat-rate
     * product -- the same convention getBrackets() already uses for the
     * (pre-existing) Asset Financing flat-rate case.
     */
    public function getBracketsOrFlatRate(int $loanTypeId): array
    {
        $rule = null;
        try {
            $stmt = $this->db->prepare("SELECT `interest_type`, `flat_interest_rate` FROM `loan_product_rules` WHERE `loan_type_id` = ? LIMIT 1");
            $stmt->execute([$loanTypeId]);
            $rule = $stmt->fetch();
        } catch (PDOException $e) {}

        if ($rule && $rule['interest_type'] === 'flat_rate') {
            return [['min_amount' => 0, 'max_amount' => 0, 'monthly_rate' => $rule['flat_interest_rate']]];
        }
        return $this->getBrackets($loanTypeId);
    }

    public function getSettings(int $loanTypeId): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT lps.*, lt.name AS loan_type_name, lt.repayment_type
                 FROM `loan_product_settings` lps
                 JOIN `loan_types` lt ON lt.id = lps.loan_type_id
                 WHERE lps.loan_type_id = ? LIMIT 1"
            );
            $stmt->execute([$loanTypeId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    public function getAllSettings(): array
    {
        try {
            return $this->db->query(
                "SELECT lps.*, lt.name AS loan_type_name, lt.repayment_type
                 FROM `loan_product_settings` lps
                 JOIN `loan_types` lt ON lt.id = lps.loan_type_id
                 ORDER BY lt.id"
            )->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function updateSettings(int $loanTypeId, array $data): bool
    {
        try {
            $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
            $values = array_values($data);
            $values[] = $loanTypeId;
            $stmt = $this->db->prepare("UPDATE `loan_product_settings` SET {$set} WHERE `loan_type_id` = ?");
            return $stmt->execute($values);
        } catch (PDOException $e) { return false; }
    }

    // ================================================================
    // BUSINESS LOAN — MONTHLY INTEREST TRACKING
    // ================================================================

    /**
     * Generate monthly interest schedule for a business loan.
     */
    public function generateBusinessLoanSchedule(int $loanId, int $memberId, float $monthlyInterest, int $periodMonths, string $startDate): void
    {
        try {
            // Remove existing entries for this loan
            $this->db->prepare("DELETE FROM `business_loan_interest_payments` WHERE `loan_id`=?")->execute([$loanId]);

            for ($i = 1; $i <= $periodMonths; $i++) {
                $dueDate = date('Y-m-d', strtotime("+{$i} months", strtotime($startDate)));
                $monthCovered = date('M Y', strtotime($dueDate));

                $this->db->prepare(
                    "INSERT INTO `business_loan_interest_payments`
                     (`loan_id`,`member_id`,`month_covered`,`due_date`,`interest_due`,`status`)
                     VALUES (?,?,?,?,?,'pending')"
                )->execute([$loanId, $memberId, $monthCovered, $dueDate, $monthlyInterest]);
            }
        } catch (PDOException $e) {}
    }

    // ================================================================
    // BUSINESS BOOST LOAN — WEEKLY PRINCIPAL + INTEREST FROM WEEK 1
    // ================================================================

    /**
     * Generate the Business Boost Loan repayment schedule.
     * 
     * Business Boost: Weekly principal + interest payments from Week 1.
     * NO interest-only phase. Borrower pays both principal and interest from the beginning.
     * 
     * Formula:
     * - Monthly Installment = Total Payable ÷ Term Months
     * - Weekly Installment = Monthly Installment ÷ 4
     * - Weekly Principal = Principal ÷ Total Weeks
     * - Weekly Interest = Total Interest ÷ Total Weeks
     * 
     * Final week absorbs rounding differences to ensure exact reconciliation.
     */
    public function generateBusinessBoostSchedule(
        int $loanId,
        float $principal,
        float $monthlyRate,
        int $totalMonths,
        string $startDate
    ): void {
        try {
            // Clear existing installments
            $this->db->prepare("DELETE FROM `loan_installments` WHERE `loan_id`=?")->execute([$loanId]);

            // Calculate totals
            $monthlyInterest = round($principal * ($monthlyRate / 100), 2);
            $totalInterest   = round($monthlyInterest * $totalMonths, 2);
            $totalPayable    = $principal + $totalInterest;
            
            // CORRECTION: Use actual calendar duration, not months × 4
            // Calculate contractual end date (actual calendar months from start)
            if (!class_exists('LoanModel')) {
                require_once dirname(__DIR__) . '/models/LoanModel.php';
            }
            $contractualEndDate = LoanModel::addCalendarMonths($startDate, $totalMonths);
            
            // Generate weekly installment dates from start through contractual end
            $installmentDates = [];
            $currentDate = strtotime("+1 week", strtotime($startDate));
            
            while (date('Y-m-d', $currentDate) <= $contractualEndDate) {
                $installmentDates[] = date('Y-m-d', $currentDate);
                $currentDate = strtotime("+1 week", $currentDate);
            }
            
            $actualWeeks = count($installmentDates);
            
            // Calculate standard weekly amounts based on ACTUAL weeks
            $standardWeeklyPrincipal = round($principal / $actualWeeks, 2);
            $standardWeeklyInterest  = round($totalInterest / $actualWeeks, 2);
            $standardWeeklyPayment   = $standardWeeklyPrincipal + $standardWeeklyInterest;

            $installmentNo = 0;
            $scheduledPrincipal = 0;
            $scheduledInterest = 0;
            $balance = $principal;

            // Generate weekly schedule using actual calendar dates
            foreach ($installmentDates as $index => $dueDate) {
                $installmentNo++;
                $isLastWeek = ($index === count($installmentDates) - 1);
                $monthCovered = date('M Y', strtotime($dueDate));

                // Final week: absorb rounding differences to ensure exact reconciliation
                if ($isLastWeek) {
                    $actualPrincipal = $principal - $scheduledPrincipal;
                    $actualInterest = $totalInterest - $scheduledInterest;
                    $actualTotal = $actualPrincipal + $actualInterest;
                    $balance = 0;
                } else {
                    $actualPrincipal = $standardWeeklyPrincipal;
                    $actualInterest = $standardWeeklyInterest;
                    $actualTotal = $standardWeeklyPayment;
                    $balance = round($balance - $actualPrincipal, 2);
                }

                $scheduledPrincipal += $actualPrincipal;
                $scheduledInterest += $actualInterest;

                $this->db->prepare(
                    "INSERT INTO `loan_installments`
                     (`loan_id`,`installment_no`,`period_type`,`payment_type`,`due_date`,`month_covered`,
                      `principal_due`,`interest_due`,`amount_due`,`amount_paid`,`remaining`,`balance_after`,`status`)
                     VALUES (?,?,'weekly','principal_interest',?,?,?,?,?,0,?,?,'pending')"
                )->execute([
                    $loanId, $installmentNo, $dueDate, $monthCovered,
                    $actualPrincipal, $actualInterest, $actualTotal, $balance, $balance
                ]);
            }

            // Update loan metadata with ACTUAL weeks
            $this->db->prepare(
                "UPDATE `loans` SET `repayment_method`='business_boost',
                 `interest_only_months`=0, `principal_recovery_weeks`=? WHERE `id`=?"
            )->execute([$actualWeeks, $loanId]);

        } catch (PDOException $e) {
            error_log('generateBusinessBoostSchedule() failed for loan ' . $loanId . ': ' . $e->getMessage());
            throw $e;
        }
    }

    // ================================================================
    // INTEREST ONLY (STANDARD) — HYBRID SCHEDULE GENERATION
    // ================================================================

    /**
     * Generate the Interest Only (Standard) Loan repayment schedule.
     *
     * Interest Only Standard: Interest-only for (totalMonths - 2) months, 
     * then weekly principal + interest recovery for 8 weeks.
     * 
     * For a 6-month loan:
     * - First 4 months: Interest only (principal due = 0)
     * - Final 8 weeks: Principal + remaining interest
     */
    public function generateInterestOnlySchedule(
        int $loanId,
        float $principal,
        float $monthlyRate,
        int $totalMonths,
        string $startDate
    ): void {
        try {
            // Clear existing installments
            $this->db->prepare("DELETE FROM `loan_installments` WHERE `loan_id`=?")->execute([$loanId]);

            // Calculate phases
            $interestOnlyMonths = max(1, $totalMonths - 2);
            $recoveryWeeks      = 8;

            $monthlyInterest    = round($principal * ($monthlyRate / 100), 2);
            $totalInterest      = round($monthlyInterest * $totalMonths, 2);
            
            // Phase 1 interest
            $phase1Interest = $monthlyInterest * $interestOnlyMonths;
            
            // Phase 2 remaining interest
            $remainingInterest = $totalInterest - $phase1Interest;
            
            $weeklyPrincipal    = round($principal / $recoveryWeeks, 2);
            $weeklyInterest     = round($remainingInterest / $recoveryWeeks, 2);
            $installmentNo      = 0;
            $balance            = $principal;
            $scheduledPrincipal = 0;
            $scheduledInterest  = 0;

            // ── Interest Only Period (monthly) ────────────────────
            for ($m = 1; $m <= $interestOnlyMonths; $m++) {
                $installmentNo++;
                $dueDate = LoanModel::addCalendarMonths($startDate, $m);
                $monthCovered = date('M Y', strtotime($dueDate));

                $scheduledInterest += $monthlyInterest;

                $this->db->prepare(
                    "INSERT INTO `loan_installments`
                     (`loan_id`,`installment_no`,`period_type`,`payment_type`,`due_date`,`month_covered`,
                      `principal_due`,`interest_due`,`amount_due`,`amount_paid`,`remaining`,`balance_after`,`status`)
                     VALUES (?,?,'monthly','interest_only',?,?,0,?,?,0,?,?,'pending')"
                )->execute([
                    $loanId, $installmentNo, $dueDate, $monthCovered,
                    $monthlyInterest, $monthlyInterest, $balance, $balance
                ]);
            }

            // ── Weekly Principal + Interest Recovery (last 2 months) ──
            $weekStartDate = LoanModel::addCalendarMonths($startDate, $interestOnlyMonths);

            for ($w = 1; $w <= $recoveryWeeks; $w++) {
                $installmentNo++;
                $dueDate = date('Y-m-d', strtotime("+{$w} weeks", strtotime($weekStartDate)));
                $monthCovered = date('M Y', strtotime($dueDate));

                // Last week: absorb rounding differences
                if ($w === $recoveryWeeks) {
                    $actualPrincipal = $principal - $scheduledPrincipal;
                    $actualInterest = $totalInterest - $scheduledInterest;
                    $actualTotal = $actualPrincipal + $actualInterest;
                    $balance = 0;
                } else {
                    $actualPrincipal = $weeklyPrincipal;
                    $actualInterest = $weeklyInterest;
                    $actualTotal = $actualPrincipal + $actualInterest;
                    $balance = round($balance - $actualPrincipal, 2);
                }

                $scheduledPrincipal += $actualPrincipal;
                $scheduledInterest += $actualInterest;

                $this->db->prepare(
                    "INSERT INTO `loan_installments`
                     (`loan_id`,`installment_no`,`period_type`,`payment_type`,`due_date`,`month_covered`,
                      `principal_due`,`interest_due`,`amount_due`,`amount_paid`,`remaining`,`balance_after`,`status`)
                     VALUES (?,?,'weekly','principal_interest',?,?,?,?,?,0,?,?,'pending')"
                )->execute([
                    $loanId, $installmentNo, $dueDate, $monthCovered,
                    $actualPrincipal, $actualInterest, $actualTotal, $balance, $balance
                ]);
            }

            // Update loan metadata
            $this->db->prepare(
                "UPDATE `loans` SET `repayment_method`='interest_only',
                 `interest_only_months`=?, `principal_recovery_weeks`=? WHERE `id`=?"
            )->execute([$interestOnlyMonths, $recoveryWeeks, $loanId]);

        } catch (PDOException $e) {
            error_log('generateInterestOnlySchedule() failed for loan ' . $loanId . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate Business Boost Loan totals.
     * 
     * Business Boost: Weekly principal + interest from Week 1.
     * Uses ACTUAL calendar duration, not months × 4.
     * 
     * @param float $principal Loan principal
     * @param float $monthlyRate Monthly interest rate percentage
     * @param int $totalMonths Loan term in calendar months
     * @param string $startDate Start/disbursement date (YYYY-MM-DD)
     * @return array Calculation results including actual week count
     */
    public function calculateBusinessBoost(float $principal, float $monthlyRate, int $totalMonths, string $startDate = null): array
    {
        $monthlyInterest = round($principal * ($monthlyRate / 100), 2);
        $totalInterest   = round($monthlyInterest * $totalMonths, 2);
        $totalPayable    = $principal + $totalInterest;
        
        // CORRECTION: Calculate actual weeks based on calendar duration
        if ($startDate) {
            // Use LoanModel's static method for calendar-safe month addition
            if (!class_exists('LoanModel')) {
                require_once dirname(__DIR__) . '/models/LoanModel.php';
            }
            $contractualEndDate = LoanModel::addCalendarMonths($startDate, $totalMonths);
            
            // Count actual weekly intervals
            $actualWeeks = 0;
            $currentDate = strtotime("+1 week", strtotime($startDate));
            while (date('Y-m-d', $currentDate) <= $contractualEndDate) {
                $actualWeeks++;
                $currentDate = strtotime("+1 week", $currentDate);
            }
            $totalWeeks = $actualWeeks;
        } else {
            // Fallback for preview/estimation when start date unknown
            // Use approximate: months * 4.33 weeks per month
            $totalWeeks = max(1, round($totalMonths * 4.33));
        }

        $monthlyInstallment = round($totalPayable / $totalMonths, 2);
        $weeklyInstallment  = round($totalPayable / $totalWeeks, 2);
        
        $weeklyPrincipal = round($principal / $totalWeeks, 2);
        $weeklyInterest  = round($totalInterest / $totalWeeks, 2);

        return [
            'principal'            => $principal,
            'monthly_rate'         => $monthlyRate,
            'monthly_interest'     => $monthlyInterest,
            'total_interest'       => $totalInterest,
            'total_payable'        => $totalPayable,
            'total_weeks'          => $totalWeeks,
            'monthly_installment'  => $monthlyInstallment,
            'weekly_installment'   => $weeklyInstallment,
            'weekly_principal'     => $weeklyPrincipal,
            'weekly_interest'      => $weeklyInterest,
            'interest_only_months' => 0,
            'recovery_weeks'       => $totalWeeks,
            'contractual_end_date' => $startDate ? LoanModel::addCalendarMonths($startDate, $totalMonths) : null,
        ];
    }

    /**
     * Calculate Interest Only (Standard) Loan totals.
     * 
     * Interest Only: Interest-only for (totalMonths - 2) months, 
     * then weekly principal + interest recovery for 8 weeks.
     */
    public function calculateInterestOnly(float $principal, float $monthlyRate, int $totalMonths): array
    {
        $interestOnlyMonths = max(1, $totalMonths - 2);
        $recoveryWeeks = 8;

        $monthlyInterest = round($principal * ($monthlyRate / 100), 2);
        $totalInterest   = round($monthlyInterest * $totalMonths, 2);
        $totalPayable    = $principal + $totalInterest;

        $phase1Interest = $monthlyInterest * $interestOnlyMonths;
        $remainingInterest = $totalInterest - $phase1Interest;

        $weeklyPrincipal = round($principal / $recoveryWeeks, 2);
        $weeklyInterest  = round($remainingInterest / $recoveryWeeks, 2);
        $weeklyPayment   = $weeklyPrincipal + $weeklyInterest;

        return [
            'principal'            => $principal,
            'monthly_rate'         => $monthlyRate,
            'monthly_interest'     => $monthlyInterest,
            'total_interest'       => $totalInterest,
            'total_payable'        => $totalPayable,
            'interest_only_months' => $interestOnlyMonths,
            'recovery_weeks'       => $recoveryWeeks,
            'phase1_interest'      => $phase1Interest,
            'remaining_interest'   => $remainingInterest,
            'weekly_principal'     => $weeklyPrincipal,
            'weekly_interest'      => $weeklyInterest,
            'weekly_payment'       => $weeklyPayment,
        ];
    }

    /**
     * Get business loan interest payments.
     */
    public function getBusinessLoanPayments(int $loanId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `business_loan_interest_payments` WHERE `loan_id`=? ORDER BY `due_date` ASC"
            );
            $stmt->execute([$loanId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Record a business loan interest payment.
     */
    public function recordBusinessInterestPayment(int $paymentId, float $amount): void
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `business_loan_interest_payments` WHERE `id`=?");
            $stmt->execute([$paymentId]);
            $row = $stmt->fetch();
            if (!$row) return;

            $newPaid = (float)$row['interest_paid'] + $amount;
            $status  = $newPaid >= (float)$row['interest_due'] ? 'paid' : 'partial';
            $paidDate = $status === 'paid' ? date('Y-m-d') : null;

            $this->db->prepare(
                "UPDATE `business_loan_interest_payments` SET `interest_paid`=?, `status`=?, `paid_date`=? WHERE `id`=?"
            )->execute([$newPaid, $status, $paidDate, $paymentId]);
        } catch (PDOException $e) {}
    }

    // ================================================================
    // PENALTY ENGINE
    // ================================================================

    public function calculatePenalties(): void
    {
        try {
            $stmt = $this->db->query(
                "SELECT li.*, l.member_id, l.loan_type_id, l.id AS loan_id,
                        DATEDIFF(CURDATE(), li.due_date) AS days_overdue
                 FROM `loan_installments` li
                 JOIN `loans` l ON l.id = li.loan_id
                 WHERE li.status IN('overdue','pending','partial')
                 AND li.due_date < CURDATE()
                 AND l.status IN('active','overdue')"
            );
            $overdueInstallments = $stmt->fetchAll();

            foreach ($overdueInstallments as $inst) {
                $daysOverdue = (int)$inst['days_overdue'];
                if ($daysOverdue <= 0) continue;

                $settings = $this->getSettings((int)$inst['loan_type_id']);
                $gracedays = $settings ? (int)$settings['grace_period_days'] : 0;
                $penaltyRate = $settings ? (float)$settings['penalty_rate_per_day'] : 0.25;

                $penaltyDays = max(0, $daysOverdue - $gracedays);
                if ($penaltyDays <= 0) continue;

                $unpaid = (float)$inst['amount_due'] - (float)$inst['amount_paid'];
                if ($unpaid <= 0) continue;

                $penaltyAmount = round($unpaid * ($penaltyRate / 100) * $penaltyDays, 2);
                if ($penaltyAmount <= 0) continue;

                $check = $this->db->prepare(
                    "SELECT COUNT(*) FROM `loan_penalties`
                     WHERE `installment_id`=? AND `calculated_date`=CURDATE() AND `status`='accruing'"
                );
                $check->execute([$inst['id']]);
                if ((int)$check->fetchColumn() > 0) continue;

                $this->db->prepare(
                    "INSERT INTO `loan_penalties` (`loan_id`,`installment_id`,`member_id`,`days_overdue`,`base_amount`,`penalty_rate`,`penalty_amount`,`status`,`calculated_date`)
                     VALUES (?,?,?,?,?,?,?,'accruing',CURDATE())"
                )->execute([$inst['loan_id'], $inst['id'], $inst['member_id'], $penaltyDays, $unpaid, $penaltyRate, $penaltyAmount]);

                $this->db->prepare(
                    "UPDATE `loans` SET `penalty_accrued` = (
                        SELECT COALESCE(SUM(penalty_amount),0) FROM `loan_penalties` WHERE `loan_id`=? AND `status`='accruing'
                    ) WHERE `id`=?"
                )->execute([$inst['loan_id'], $inst['loan_id']]);
            }
        } catch (PDOException $e) {
            // loan_installments is currently unreadable at the storage-engine
            // level (Stage 7E) -- penalty accrual cannot run until that is
            // resolved; log rather than let it fail invisibly.
            error_log('calculatePenalties() failed: ' . $e->getMessage());
        }
    }

    // ================================================================
    // WHATSAPP SCHEDULE
    // ================================================================

    public function generateWhatsAppSchedule(array $loan, array $installments): string
    {
        $memberName = (($loan['gender'] ?? '') === 'Female' ? 'Ms.' : 'Mr.')
                    . ' ' . $loan['first_name'] . ' ' . $loan['last_name'];
        $approvalDate = date('jS/n/Y', strtotime($loan['approval_date'] ?? $loan['issue_date']));
        $lastDueDate  = !empty($installments)
            ? date('jS F Y', strtotime(end($installments)['due_date']))
            : date('jS F Y', strtotime($loan['due_date']));

        $text  = $approvalDate . "\n\n";

        // Business name as applicant with C/O member
        if (!empty($loan['business_name'])) {
            $text .= $loan['business_name'] . "\n";
            $text .= "C/O " . $memberName . "\n\n";
        } else {
            $text .= $memberName . "\n\n";
        }
        $text .= "Loan amount: " . number_format($loan['loan_amount'], 0) . "\n";
        $text .= "Purpose: " . ($loan['purpose'] ?? 'Personal') . "\n";
        $text .= "Interest rate terms: " . number_format($loan['interest_rate'], 0) . "% per month flat rate\n";

        if (($loan['processing_fee'] ?? 0) > 0) {
            $text .= "Processing fee: " . number_format($loan['processing_fee'], 0) . "\n";
        }

        $text .= "\n*Payment schedule*\n\n";

        // Show only total amount due per installment (no breakdown)
        foreach ($installments as $inst) {
            $dueDate = date('jS F Y', strtotime($inst['due_date']));
            $total   = number_format((float)($inst['amount_due'] ?? 0), 0);
            $text .= $dueDate . "  :" . $total . "\n\n";
        }

        $text .= "*Please note*\n";
        $text .= "• Timely repayment strengthens relationship\n";
        $text .= "• Stay on weekly list to increase credit score\n";
        $text .= "• There is a penalty of 0.25% every 3 days from " . $lastDueDate . " for all existing balance due.\n\n";
        $text .= "Wish you the best of luck";

        return $text;
    }

    // ================================================================
    // ACTIVITY LOG
    // ================================================================

    public function log(int $userId, string $action, string $desc = ''): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
            );
            $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }
}
