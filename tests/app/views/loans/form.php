<?php
$isEdit = $formMode === 'edit';
$v   = fn(string $k, string $d='') => htmlspecialchars($loan[$k] ?? $d);
$err = fn(string $k) => $errors[$k] ?? '';
$cls = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';

// Repayment period options — updated per business rules
$periods = [
    '1 Month'   => 1,
    '2 Months'  => 2,
    '3 Months'  => 3,
    '4 Months'  => 4,
    '5 Months'  => 5,
    '6 Months'  => 6,
    '7 Months'  => 7,
    '8 Months'  => 8,
    '9 Months'  => 9,
    '10 Months' => 10,
    '11 Months' => 11,
    '12 Months' => 12,
    '14 Months' => 14,
    '16 Months' => 16,
    '18 Months' => 18,
    '20 Months' => 20,
    '24 Months' => 24,
];
$storedPeriod = $v('loan_period');
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-<?= $isEdit?'pencil-square':'plus-circle-fill' ?> me-2 text-warning"></i>
            <?= $isEdit ? 'Edit Loan Record' : 'Record Approved Loan' ?>
        </h1>
        <p class="text-muted mb-0 small">Loan Number: <strong style="color:var(--brand-navy)"><?= htmlspecialchars($loanNumber) ?></strong></p>
    </div>
    <a href="<?= APP_URL ?>/index.php?page=loans" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Loans
    </a>
</div>

<?php if (!empty($conversionInfo)): ?>
<div class="alert alert-info">
    <strong>Recording from application <?= htmlspecialchars($conversionInfo['application_number']) ?>.</strong>
    Requested: Shs <?= number_format((float)$conversionInfo['requested_amount'], 2) ?> for <?= (int)$conversionInfo['requested_period_months'] ?> month(s).
    <strong>Approved (used below): Shs <?= number_format((float)$conversionInfo['approved_amount'], 2) ?> for <?= (int)$conversionInfo['approved_period_months'] ?> month(s).</strong>
</div>
<?php endif; ?>
<form id="loanForm" method="POST" action="<?= $formAction ?>" novalidate>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" name="application_id" value="<?= $v('application_id') ?>">
<?php if ($err('product_rules') || $err('interest_rate') || $err('application')): ?>
<div class="alert alert-danger">
    <?php if ($err('product_rules')): ?><div><?= htmlspecialchars($err('product_rules')) ?></div><?php endif; ?>
    <?php if ($err('interest_rate')): ?><div><?= htmlspecialchars($err('interest_rate')) ?></div><?php endif; ?>
    <?php if ($err('application')): ?><div><?= htmlspecialchars($err('application')) ?></div><?php endif; ?>
</div>
<?php endif; ?>
<div class="row g-4">

<!-- ── LEFT COLUMN ───────────────────────────────────────────── -->
<div class="col-lg-8">

<!-- Member -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-person-circle text-warning"></i>
        <h6 class="mb-0 fw-semibold">Member Selection</h6>
    </div>
    <div class="card-body p-4">
        <?php
        // Helper: build status badge HTML for member status
        function memberStatusBadge(string $s): string {
            return $s === 'active'
                ? '<span class="badge bg-success-subtle text-success px-2 py-1"><i class="bi bi-check-circle me-1"></i>Active</span>'
                : '<span class="badge bg-secondary-subtle text-secondary px-2 py-1"><i class="bi bi-x-circle me-1"></i>Inactive</span>';
        }
        // Helper: build loan status badge
        function loanStatusBadge(string $s): string {
            return match($s) {
                'Active'    => '<span class="badge bg-success-subtle text-success px-2 py-1"><i class="bi bi-bank2 me-1"></i>Active</span>',
                'Overdue'   => '<span class="badge bg-danger-subtle text-danger px-2 py-1"><i class="bi bi-exclamation-triangle me-1"></i>Overdue</span>',
                'Completed' => '<span class="badge bg-primary-subtle text-primary px-2 py-1"><i class="bi bi-check2-all me-1"></i>Completed</span>',
                default     => '<span class="badge bg-light text-muted px-2 py-1"><i class="bi bi-dash me-1"></i>None</span>',
            };
        }
        ?>

        <?php if ($isEdit && $preselected): ?>
        <!-- ── EDIT MODE: locked member display ─────────────── -->
        <input type="hidden" name="member_id"      value="<?= (int)($loan['member_id'] ?? 0) ?>">
        <input type="hidden" name="account_number" value="<?= htmlspecialchars($preselected['account_number'] ?? $loan['account_number'] ?? '') ?>">
        <div class="member-info-card member-info-card--static">
            <?= renderMemberCard($preselected) ?>
        </div>
        <?php else: ?>
        <!-- ── ADD MODE: search + dynamic card ──────────────── -->
        <input type="hidden" name="member_id"      id="memberId"            value="<?= (int)($loan['member_id'] ?? $preselected['id'] ?? 0) ?>">
        <input type="hidden" name="account_number" id="memberAccountNumber" value="<?= htmlspecialchars($preselected['account_number'] ?? $loan['account_number'] ?? '') ?>">

        <!-- Search Row: Account Number + Name/Phone on same line -->
        <div class="row g-0 mb-3" style="align-items:flex-start;">
            <!-- Account Number field -->
            <div class="col-sm-5">
                <label class="form-label fw-semibold mb-1" for="accountLookup">Account Number</label>
                <div class="input-group">
                    <input type="text" id="accountLookup" class="form-control"
                           placeholder="e.g. 100105"
                           value="<?= htmlspecialchars($preselected['account_number'] ?? $loan['account_number'] ?? '') ?>">
                    <button type="button" id="accountLookupBtn" class="btn btn-warning text-white fw-semibold px-3">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <div id="accountLookupMsg" class="small mt-1" style="min-height:18px;"></div>
            </div>

            <!-- OR divider — vertically centred against the input row -->
            <div class="col-sm-1 d-flex align-items-center justify-content-center" style="padding-top:1.6rem;">
                <span class="text-muted fw-bold" style="font-size:.8rem;letter-spacing:.04em;">OR</span>
            </div>

            <!-- Name / Member No. / Phone field -->
            <div class="col-sm-6">
                <label class="form-label fw-semibold mb-1" for="memberSearch">
                    Name / Member No. / Phone <span class="text-danger">*</span>
                </label>
                <div class="position-relative">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="memberSearch" class="form-control<?= $cls('member_id') ?>"
                               placeholder="Type to search…" autocomplete="off"
                               value="<?= $preselected ? htmlspecialchars($preselected['full_name'].' ('.$preselected['member_number'].')') : '' ?>">
                        <button type="button" id="clearMember" class="btn btn-outline-secondary <?= $preselected ? '' : 'd-none' ?>" title="Clear">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div id="memberDropdown" class="list-group shadow-sm"
                         style="position:absolute;z-index:1055;width:100%;display:none;max-height:260px;overflow-y:auto;border-radius:0 0 6px 6px;"></div>
                </div>
                <?php if ($err('member_id')): ?>
                <div class="text-danger small mt-1"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($err('member_id')) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Member Info Card (hidden until a member is selected) -->
        <div id="memberInfoCard" class="<?= $preselected ? '' : 'd-none' ?>">
            <?php if ($preselected): ?>
            <?= renderMemberCard($preselected) ?>
            <?php else: ?>
            <!-- JS will inject card here -->
            <?php endif; ?>
        </div>

        <!-- Placeholder shown when no member selected yet -->
        <div id="memberInfoPlaceholder" class="<?= $preselected ? 'd-none' : '' ?> text-center py-4 rounded-3" style="border:2px dashed #dee2e6;background:#f9fafb;">
            <i class="bi bi-person-plus fs-2 text-muted opacity-50 d-block mb-2"></i>
            <p class="text-muted mb-0 small">Search for a member to see their details here.</p>
        </div>

        <?php endif; ?>
    </div>
</div>

<?php
/**
 * Render the read-only member info card.
 * Works for both PHP (initial load) and is mirrored in JS for dynamic updates.
 */
function renderMemberCard(array $m): string {
    $initial  = htmlspecialchars(strtoupper(substr($m['full_name'] ?? 'M', 0, 1)));
    $name     = htmlspecialchars($m['full_name']      ?? '—');
    $memNo    = htmlspecialchars($m['member_number']  ?? '—');
    $accNo    = htmlspecialchars($m['account_number'] ?? '');
    $phone    = htmlspecialchars($m['phone']          ?? '—');
    $mStatus  = $m['status'] ?? 'active';
    $lStatus  = $m['loan_status']  ?? 'None';
    $outstanding = (float)($m['outstanding'] ?? 0);

    $mBadge = $mStatus === 'active'
        ? '<span class="badge bg-success-subtle text-success px-2"><i class="bi bi-check-circle me-1"></i>Active</span>'
        : '<span class="badge bg-secondary-subtle text-secondary px-2"><i class="bi bi-x-circle me-1"></i>Inactive</span>';

    $lBadge = match($lStatus) {
        'Active'    => '<span class="badge bg-success-subtle text-success px-2"><i class="bi bi-bank2 me-1"></i>Active</span>',
        'Overdue'   => '<span class="badge bg-danger-subtle text-danger px-2"><i class="bi bi-exclamation-triangle me-1"></i>Overdue</span>',
        'Completed' => '<span class="badge bg-primary-subtle text-primary px-2"><i class="bi bi-check2-all me-1"></i>Completed</span>',
        default     => '<span class="badge bg-light text-muted border px-2"><i class="bi bi-dash me-1"></i>None</span>',
    };

    $outstandingFmt = $outstanding > 0
        ? '<span class="fw-bold text-danger">UGX ' . number_format($outstanding, 0) . '</span>'
        : '<span class="text-muted">UGX 0</span>';

    $accRow = $accNo
        ? "<span class=\"fw-semibold\" style=\"color:var(--brand-navy,#1B2B6B)\">{$accNo}</span>"
        : '<span class="text-muted fst-italic small">Not assigned</span>';

    $warningRow = '';
    if (in_array($lStatus, ['Active','Overdue'])) {
        $warningRow = '<div class="mt-3 alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-2" style="font-size:.8rem;">'
            . '<i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>'
            . '<span>This member has an <strong>' . $lStatus . '</strong> loan. Confirm before proceeding.</span>'
            . '</div>';
    }

    return <<<HTML
<div class="card border-0 shadow-sm" style="background:#f8f9fc;">
  <div class="card-body p-3">
    <div class="d-flex align-items-center gap-3 mb-3">
      <div class="member-avatar-sm bg-blue flex-shrink-0" style="width:42px;height:42px;font-size:1rem;">{$initial}</div>
      <div class="flex-grow-1 min-width-0">
        <div class="fw-bold" style="font-size:.95rem;color:var(--brand-navy,#1B2B6B)">{$name}</div>
        <div class="text-muted" style="font-size:.75rem">{$memNo}</div>
      </div>
      <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1" style="font-size:.65rem;">Selected</span>
    </div>
    <div class="row g-2" style="font-size:.8rem;">
      <div class="col-6 col-md-4">
        <div class="text-muted mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Membership No.</div>
        <div class="fw-semibold">{$memNo}</div>
      </div>
      <div class="col-6 col-md-4">
        <div class="text-muted mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Account No.</div>
        <div>{$accRow}</div>
      </div>
      <div class="col-6 col-md-4">
        <div class="text-muted mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Phone</div>
        <div class="fw-semibold">{$phone}</div>
      </div>
      <div class="col-6 col-md-4">
        <div class="text-muted mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Membership Status</div>
        <div>{$mBadge}</div>
      </div>
      <div class="col-6 col-md-4">
        <div class="text-muted mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Current Loan</div>
        <div>{$lBadge}</div>
      </div>
      <div class="col-6 col-md-4">
        <div class="text-muted mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;">Outstanding Balance</div>
        <div>{$outstandingFmt}</div>
      </div>
    </div>
    {$warningRow}
  </div>
</div>
HTML;
}
?>

<!-- Loan Details -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-cash-stack text-warning"></i>
        <h6 class="mb-0 fw-semibold">Loan Details</h6>
    </div>
    <div class="card-body p-4">

        <!-- Loan Product Selection (FIRST FIELD) -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="loan_type_id">
                    Loan Product <span class="text-danger">*</span>
                </label>
                <select id="loan_type_id" name="loan_type_id" class="form-select" required>
                    <option value="">— Select Loan Product —</option>
                    <?php foreach ($loanTypes ?? [] as $lt): ?>
                    <option value="<?= $lt['id'] ?>" <?= ((int)($loan['loan_type_id'] ?? 1) === (int)$lt['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lt['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div id="productInfo" class="small text-muted"></div>
            </div>
            <div class="col-md-3" id="repaymentMethodGroup" style="display:none;">
                <label class="form-label fw-semibold">Repayment Method</label>
                <select name="repayment_method" id="repaymentMethod" class="form-select">
                    <option value="interest_only">Interest Only (Standard)</option>
                    <option value="business_boost">Business Boost (Interest + Weekly Recovery)</option>
                </select>
            </div>
        </div>

        <!-- Business Details (visible only for Business Loans) -->
        <div class="row g-3 mb-4" id="businessDetailsCard" style="display:none;">
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="business_name">Business Name <span class="text-danger">*</span></label>
                <input type="text" id="business_name" name="business_name" class="form-control"
                       value="<?= $v('business_name') ?>" placeholder="e.g. Ssali Enterprises" maxlength="200">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="business_location">Business Location</label>
                <input type="text" id="business_location" name="business_location" class="form-control"
                       value="<?= $v('business_location') ?>" placeholder="e.g. Kalagi Trading Centre" maxlength="200">
            </div>
        </div>

        <!-- ROW 1 — Repayment Frequency + Interest Mode (equal width) -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Repayment Frequency <span class="text-danger">*</span></label>
                <select name="repayment_frequency" id="repaymentFrequency" class="form-select">
                    <option value="monthly" <?= ($v('repayment_frequency','monthly') === 'monthly') ? 'selected' : '' ?>>Monthly</option>
                    <option value="weekly" <?= ($v('repayment_frequency') === 'weekly') ? 'selected' : '' ?>>Weekly</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Interest Mode</label>
                <select name="interest_mode" id="interestMode" class="form-select">
                    <option value="percentage" <?= ($v('interest_mode','percentage') === 'percentage') ? 'selected' : '' ?>>Percentage (%)</option>
                    <option value="fixed" <?= ($v('interest_mode') === 'fixed') ? 'selected' : '' ?>>Fixed Amount (Manual)</option>
                </select>
            </div>
        </div>
        <div class="row g-3 mb-4" id="fixedInterestGroup" style="display:none;">
            <div class="col-md-6">
                <label class="form-label fw-semibold" id="fixedInterestLabel">Fixed Recurring Interest (Shs)</label>
                <div class="input-group">
                    <span class="input-group-text">Shs</span>
                    <input type="number" id="fixedInterestAmount" name="fixed_interest_amount"
                           class="form-control" step="1" min="0"
                           value="<?= $v('fixed_interest_amount', '0') ?>"
                           placeholder="e.g. 40000 per week">
                </div>
            </div>
        </div>

        <!-- Interest policy info box -->
        <div class="alert alert-info d-flex align-items-start gap-2 mb-4 py-2">
            <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
            <div class="small">
                <strong>Interest & fees are configured per loan product.</strong><br>
                <span class="text-muted">The suggested rate is calculated automatically from the selected product's own rate table once you enter an amount. An authorized user may set a different approved rate below — the server independently validates it and records both figures.</span>
            </div>
        </div>

        <!-- ROW 2 — Loan Amount, Suggested Rate, Approved Rate (balanced, top-aligned) -->
        <div class="row g-3">

            <!-- Loan Amount — cashier enters this -->
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="loan_amount">
                    Loan Amount (Shs) <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text fw-bold">Shs</span>
                    <input type="number" id="loan_amount" name="loan_amount"
                           step="1" min="1"
                           class="form-control<?= $cls('loan_amount') ?>"
                           value="<?= $v('loan_amount') ?>" placeholder="0" required>
                </div>
                <div id="amountRangeMsg" class="small mt-1" style="min-height:18px;"></div>
                <?php if ($err('loan_amount')): ?>
                <div class="text-danger small mt-1"><?= htmlspecialchars($err('loan_amount')) ?></div>
                <?php endif; ?>
            </div>

            <!-- Suggested Interest Rate — server-calculated from the selected
                 product's own rate table; read-only display only. Never
                 trusted as an input -- see the Approved Interest Rate field
                 for the value that is actually submitted. -->
            <div class="col-md-4">
                <label class="form-label fw-semibold">Suggested Rate</label>
                <div class="input-group">
                    <input type="text" id="interest_rate_display"
                           class="form-control text-center fw-bold" readonly tabindex="-1"
                           value="Select a loan product"
                           style="background:#eee;">
                    <span class="input-group-text fw-bold">% / mo</span>
                </div>
                <div id="amountRateHint" class="small text-muted mt-1" style="min-height:18px;"></div>
            </div>

            <!-- Approved Interest Rate — Stage 9.1: the field actually
                 submitted to the server. Initializes to the suggested rate;
                 an authorized user (whoever can already reach this form --
                 no new role gate is introduced) may adjust it. The server
                 independently recalculates the suggestion and records
                 whether/by-whom an override occurred; it never trusts this
                 value blindly (validateAgainstProductRules() and the
                 product's own bracket/flat-rate engine still govern what is
                 ultimately accepted). -->
            <div class="col-md-4">
                <label class="form-label fw-semibold">Approved Interest Rate <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="number" id="approved_interest_rate" name="approved_interest_rate"
                           class="form-control text-center fw-bold<?= $cls('interest_rate') ?>"
                           step="0.0001" min="0" max="100"
                           value="<?= $v('interest_rate','') ?>"
                           style="background:#fff8e1;">
                    <span class="input-group-text fw-bold">% / mo</span>
                </div>
                <div id="rateOverrideIndicator" class="small mt-1" style="min-height:18px;"></div>
                <?php if ($err('interest_rate')): ?>
                <div class="text-danger small mt-1"><?= htmlspecialchars($err('interest_rate')) ?></div>
                <?php endif; ?>
            </div>
            <input type="hidden" id="interest_rate" value="<?= $v('interest_rate','0') ?>">
            <div class="col-12" id="rateOverrideReasonGroup" style="display:none;">
                <label class="form-label fw-semibold" for="rate_override_reason">Reason for Rate Adjustment <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" id="rate_override_reason" name="rate_override_reason" class="form-control"
                       value="<?= $v('rate_override_reason') ?>" placeholder="e.g. negotiated rate approved by Chairman" maxlength="255">
            </div>

            <!-- Total Interest — hidden, submitted to server -->
            <input type="hidden" id="interest_amount" name="interest_amount" value="<?= $v('interest_amount','0') ?>">
            <input type="hidden" id="interest_amount_display" value="">

            <!-- Processing Fee — hidden, submitted to server -->
            <input type="hidden" id="processing_fee" name="processing_fee" value="<?= $v('processing_fee','0') ?>">
            <input type="hidden" id="processing_fee_display" value="">

            <!-- Total Amount Payable — hidden, submitted to server -->
            <input type="hidden" id="total_payable" name="total_payable" value="<?= $v('total_payable','0') ?>">
            <input type="hidden" id="total_payable_display" value="">
            <?php if ($err('total_payable')): ?>
            <div class="text-danger small mt-1"><?= htmlspecialchars($err('total_payable')) ?></div>
            <?php endif; ?>

            <!-- Monthly Instalment — hidden, submitted to server -->
            <input type="hidden" id="instalment_display" name="monthly_installment" value="">
            <div class="d-none" id="instalmentLabel"></div>
            <div class="d-none" id="instalmentHint"></div>

            <!-- Outstanding (edit only) -->
            <?php if ($isEdit): ?>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="outstanding">Outstanding Balance (Shs)</label>
                <div class="input-group">
                    <span class="input-group-text">Shs</span>
                    <input type="number" id="outstanding" name="outstanding" step="0.01" min="0"
                           class="form-control" value="<?= $v('outstanding') ?>" placeholder="0.00">
                </div>
            </div>
            <?php endif; ?>

            <!-- Issue Date — cashier can set this -->
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="issue_date">
                    Issue Date <span class="text-danger">*</span>
                </label>
                <input type="date" id="issue_date" name="issue_date"
                       class="form-control<?= $cls('issue_date') ?>"
                       value="<?= $v('issue_date', date('Y-m-d')) ?>"
                       max="<?= date('Y-m-d') ?>" required>
                <?php if ($err('issue_date')): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($err('issue_date')) ?></div>
                <?php endif; ?>
            </div>

            <!-- Disbursement Method — determines the accounting cash/bank credit account -->
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="disbursement_method">Disbursement Method</label>
                <select id="disbursement_method" name="disbursement_method" class="form-select">
                    <?php foreach (['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'] as $method): ?>
                        <option value="<?= $method ?>" <?= $v('disbursement_method', 'Cash') === $method ? 'selected' : '' ?>><?= $method ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Repayment Period — cashier selects this -->
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="repayment_period">
                    Repayment Period <span class="text-danger">*</span>
                </label>
                <select id="repayment_period" name="loan_period"
                        class="form-select<?= $cls('loan_period') ?>" required>
                    <option value="">— Select Period —</option>
                    <?php foreach ($periods as $label => $months): ?>
                    <option value="<?= $label ?>" data-months="<?= $months ?>"
                        <?= ($storedPeriod === $label) ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('loan_period')): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($err('loan_period')) ?></div>
                <?php endif; ?>
            </div>

            <!-- Due Date — auto-calculated, read-only -->
            <div class="col-md-4">
                <label class="form-label fw-semibold">
                    Due Date
                    <span class="badge bg-info-subtle text-info ms-1" style="font-size:.6rem;">AUTO</span>
                </label>
                <input type="hidden" id="due_date" name="due_date" value="<?= $v('due_date') ?>">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar-check text-info"></i></span>
                    <input type="text" id="due_date_display" class="form-control"
                           value="<?= $v('due_date') ? date('d M Y', strtotime($loan['due_date']??'')) : '' ?>"
                           readonly placeholder="Select period above" style="background:#f0f8ff;">
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Status + Purpose + Remarks -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-info-circle text-warning"></i>
        <h6 class="mb-0 fw-semibold">Additional Information</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="status">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="active"    <?= ($v('status','active')==='active')   ?'selected':'' ?>>Active</option>
                    <option value="completed" <?= ($v('status','active')==='completed')?'selected':'' ?>>Completed</option>
                    <option value="overdue"   <?= ($v('status','active')==='overdue')  ?'selected':'' ?>>Overdue</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="loan_officer">Loan Officer</label>
                <input type="text" id="loan_officer" name="loan_officer" class="form-control"
                       value="<?= $v('loan_officer') ?>" placeholder="Officer's name…" maxlength="100">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="date_approved">Date Approved</label>
                <input type="date" id="date_approved" name="date_approved" class="form-control"
                       value="<?= $v('date_approved') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="date_issued">Date Issued</label>
                <input type="date" id="date_issued" name="date_issued" class="form-control"
                       value="<?= $v('date_issued') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="approved_amount">Approved Amount (Shs)</label>
                <div class="input-group">
                    <span class="input-group-text">Shs</span>
                    <input type="number" id="approved_amount" name="approved_amount" class="form-control"
                           step="1" min="0" value="<?= $v('approved_amount') ?>" placeholder="0">
                </div>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="purpose">Purpose</label>
                <textarea id="purpose" name="purpose" rows="2" class="form-control"
                          placeholder="Purpose of this loan…"><?= $v('purpose') ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="remarks">Remarks</label>
                <textarea id="remarks" name="remarks" rows="2" class="form-control"
                          placeholder="Additional remarks…"><?= $v('remarks') ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ── PRODUCT ELIGIBILITY REQUIREMENTS ─────────────────────────
     Stage 9: these fields were previously not captured anywhere in this
     form even though several products require them (Asset Financing:
     income source, 30% contribution, security; Start-Up: chattel
     security; Business Loan: weekly savings commitment). The backend
     rejects the submission if the selected product requires one of these
     and it is left blank -- filling in a field this loan's product
     doesn't require has no effect.
     Follow-up: each sub-group below now shows only for the product(s)
     that actually need it, driven by the same loan_product_rules flags
     (requires_income_source/requires_security/requires_weekly_savings/
     member_contribution_pct) the loan-product-settings fetch already
     loads for the rate preview -- no new AJAX call. Hiding a group also
     clears its value so a stale entry from a previously-selected product
     is never silently submitted. -->
<div class="card mb-4" id="eligibilityCard" style="display:none;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-shield-check text-warning"></i>
        <h6 class="mb-0 fw-semibold">Product Eligibility Requirements</h6>
    </div>
    <div class="card-body p-4">
        <p class="text-muted small mb-3" id="eligibilityIntro"></p>
        <div class="row g-3">
            <div class="col-md-4 elig-group" id="eligIncomeSourceGroup" style="display:none;">
                <label class="form-label fw-semibold" for="income_source">Source of Income</label>
                <select id="income_source" name="income_source" class="form-select<?= $cls('product_rules') ?>">
                    <option value="" <?= $v('income_source') === '' ? 'selected' : '' ?>>— Not applicable —</option>
                    <option value="salary" <?= $v('income_source') === 'salary' ? 'selected' : '' ?>>Salary</option>
                    <option value="business" <?= $v('income_source') === 'business' ? 'selected' : '' ?>>Business</option>
                </select>
            </div>
            <div class="col-md-8 elig-group" id="eligIncomeDetailsGroup" style="display:none;">
                <label class="form-label fw-semibold" for="income_details">Income Details</label>
                <input type="text" id="income_details" name="income_details" class="form-control"
                       value="<?= $v('income_details') ?>" placeholder="e.g. employer name, or business name/location" maxlength="255">
            </div>
            <div class="col-md-6 elig-group" id="eligAssetPriceGroup" style="display:none;">
                <label class="form-label fw-semibold" for="asset_purchase_price">Asset Purchase Price (Shs)</label>
                <div class="input-group">
                    <span class="input-group-text">Shs</span>
                    <input type="number" id="asset_purchase_price" name="asset_purchase_price" class="form-control"
                           step="1" min="0" value="<?= $v('asset_purchase_price') ?>" placeholder="0">
                </div>
            </div>
            <div class="col-md-6 elig-group" id="eligContributionGroup" style="display:none;">
                <label class="form-label fw-semibold" for="member_contribution">Member Contribution (Shs)</label>
                <div class="input-group">
                    <span class="input-group-text">Shs</span>
                    <input type="number" id="member_contribution" name="member_contribution" class="form-control"
                           step="1" min="0" value="<?= $v('member_contribution') ?>" placeholder="0">
                </div>
                <div class="form-text" id="eligContributionHint"></div>
            </div>
            <div class="col-md-4 elig-group" id="eligSecurityTypeGroup" style="display:none;">
                <label class="form-label fw-semibold" for="security_type">Security Type</label>
                <input type="text" id="security_type" name="security_type" class="form-control"
                       value="<?= $v('security_type') ?>" placeholder="e.g. chattel, financed asset, alternative pledge" maxlength="100">
                <div class="form-text" id="eligSecurityTypeHint"></div>
            </div>
            <div class="col-md-8 elig-group" id="eligSecurityDescGroup" style="display:none;">
                <label class="form-label fw-semibold" for="security_description">Security Description</label>
                <textarea id="security_description" name="security_description" rows="2" class="form-control"
                          placeholder="Describe the security being pledged…"><?= $v('security_description') ?></textarea>
            </div>
            <div class="col-md-6 elig-group" id="eligWeeklySavingsGroup" style="display:none;">
                <label class="form-label fw-semibold" for="weekly_savings_commitment">Weekly Savings Commitment (Shs)</label>
                <div class="input-group">
                    <span class="input-group-text">Shs</span>
                    <input type="number" id="weekly_savings_commitment" name="weekly_savings_commitment" class="form-control"
                           step="1" min="0" value="<?= $v('weekly_savings_commitment') ?>" placeholder="0">
                </div>
                <div class="form-text">The commitment amount is captured and required; the ongoing consistency/regularity threshold is not yet defined by the business.</div>
            </div>
            <!-- Grace Period — Stage 9 already applies this correctly to the
                 generated schedule (principal deferred, interest still due,
                 for the product's configured number of months); this is a
                 read-only indicator so staff can actually see it's happening
                 -- it was previously invisible anywhere on the form. Not
                 editable per loan: it is a per-product policy value, not an
                 operator choice. -->
            <div class="col-12 elig-group" id="eligGracePeriodGroup" style="display:none;">
                <div class="alert alert-secondary py-2 px-3 mb-0 small" id="eligGracePeriodMsg"></div>
            </div>
        </div>
    </div>
</div>

<!-- ── GUARANTOR INFORMATION ─────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-person-check text-warning"></i>
        <h6 class="mb-0 fw-semibold">Guarantor Information</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="guarantor_name">Guarantor Full Name</label>
                <input type="text" id="guarantor_name" name="guarantor_name" class="form-control"
                       value="<?= $v('guarantor_name') ?>" placeholder="Full name of guarantor…" maxlength="150">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="guarantor_contact">Guarantor Phone Number</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="tel" id="guarantor_contact" name="guarantor_contact" class="form-control"
                           value="<?= $v('guarantor_contact') ?>" placeholder="0712 345 678" maxlength="30">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── EMERGENCY CONTACT ──────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-shield-exclamation text-warning"></i>
        <h6 class="mb-0 fw-semibold">Emergency Contact</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="emergency_contact_name">Emergency Contact Name</label>
                <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="form-control"
                       value="<?= $v('emergency_contact_name') ?>" placeholder="Full name…" maxlength="150">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="emergency_contact_phone">Emergency Contact Phone</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control"
                           value="<?= $v('emergency_contact_phone') ?>" placeholder="0712 345 678" maxlength="30">
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /.col-lg-8 -->

<!-- ── RIGHT COLUMN — Live Summary ───────────────────────────── -->
<div class="col-lg-4">
    <div style="position:sticky;top:70px;">
    <div class="card mb-3 border-warning border-opacity-25">
        <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
            <i class="bi bi-calculator text-warning"></i>
            <h6 class="mb-0 fw-semibold text-warning">Live Loan Summary</h6>
        </div>
        <div class="card-body p-4">
            <dl class="row mb-0 small">
                <dt class="col-7 text-muted">Loan Number</dt>
                <dd class="col-5 fw-semibold" style="color:var(--brand-navy)"><?= htmlspecialchars($loanNumber) ?></dd>

                <dt class="col-7 text-muted">Member</dt>
                <dd class="col-5" id="previewMember"><?= $preselected ? htmlspecialchars($preselected['first_name'].' '.$preselected['last_name']) : '—' ?></dd>

                <dt class="col-7 text-muted">Issue Date</dt>
                <dd class="col-5" id="previewIssue"><?= $v('issue_date', date('d M Y')) ?></dd>

                <dt class="col-7 text-muted">Repayment Period</dt>
                <dd class="col-5" id="previewPeriod">—</dd>

                <dt class="col-7 text-muted">Due Date</dt>
                <dd class="col-5 fw-semibold text-danger" id="previewDue">—</dd>

                <hr class="my-2">

                <dt class="col-7 text-muted">Loan Amount</dt>
                <dd class="col-5 fw-bold" id="previewLoan">Shs <?= $v('loan_amount') ? number_format((float)($loan['loan_amount']??0),2) : '0.00' ?></dd>

                <dt class="col-7 text-muted">Interest Rate</dt>
                <dd class="col-5" id="previewRate">0.00%</dd>

                <dt class="col-7 text-muted">Interest Amount</dt>
                <dd class="col-5" id="previewInterest">Shs 0.00</dd>

                <dt class="col-7 text-muted procFeeRow">Processing Fee</dt>
                <dd class="col-5 procFeeRow" id="previewProcFee">—</dd>

                <hr class="my-2">

                <dt class="col-7 text-muted fw-bold">Total Payable</dt>
                <dd class="col-5 fw-bold fs-5 text-warning mb-1" id="previewTotal">Shs <?= $v('total_payable') ? number_format((float)($loan['total_payable']??0),2) : '0.00' ?></dd>

                <dt class="col-7 text-muted" id="previewInstalmentLabel">Monthly Instalment</dt>
                <dd class="col-5 fw-semibold text-success mb-0" id="previewInstalment">Shs 0.00</dd>
            </dl>
        </div>
    </div>

    <div class="d-flex flex-column gap-2">
        <button type="submit" id="submitBtn" class="btn btn-warning text-white w-100 fw-semibold py-2">
            <i class="bi bi-<?= $isEdit?'floppy':'save' ?> me-2"></i>
            <?= $isEdit ? 'Save Changes' : 'Record Loan' ?>
        </button>
        <button type="reset" class="btn btn-light w-100" id="resetBtn">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
        </button>
        <a href="<?= APP_URL ?>/index.php?page=loans" class="btn btn-outline-secondary w-100">
            <i class="bi bi-x-lg me-1"></i>Cancel
        </a>
    </div>
    </div><!-- /sticky wrapper -->
</div>

</div><!-- /.row -->
</form>

<script>
(function(){
'use strict';

/* ── Helpers ──────────────────────────────────────────────── */
function fmt(n){ return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }

function addMonths(dateStr, months){
    if(!dateStr) return null;
    const d = new Date(dateStr);
    if(isNaN(d)) return null;
    d.setMonth(d.getMonth() + months);
    return d;
}
function formatDate(d){
    if(!d) return '—';
    return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
}
function toISODate(d){
    if(!d) return '';
    const y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), dd=String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${dd}`;
}
function setText(id,val){ const e=document.getElementById(id); if(e) e.textContent=val; }

/* ── Interest policy ──────────────────────────────────────── */
// Loaded dynamically from product settings
let productRate = 0;
let productProcessingFee = 3;
let productMinAmount = 0;
let productMaxAmount = 0;
let productMinPeriod = 0;
let productMaxPeriod = 0;
let productBrackets = [];
let productRepaymentType = 'installment';

function getMonthlyRate(principal){
    // Use brackets if loaded
    if (productBrackets.length > 0) {
        // Single bracket with 0/0 = flat rate (Asset Financing)
        if (productBrackets.length === 1 && parseFloat(productBrackets[0].min_amount) === 0 && parseFloat(productBrackets[0].max_amount) === 0) {
            return parseFloat(productBrackets[0].monthly_rate);
        }
        // Find matching bracket
        for (let b of productBrackets) {
            const min = parseFloat(b.min_amount);
            const max = parseFloat(b.max_amount);
            if (principal >= min && (max === 0 || principal <= max)) {
                return parseFloat(b.monthly_rate);
            }
        }
        // Fallback to last bracket
        return parseFloat(productBrackets[productBrackets.length - 1].monthly_rate);
    }
    // Fallback if no brackets loaded
    if (productRate > 0) return productRate;
    if (principal <= 0) return 0;
    if (principal <= 1000000) return 10;
    if (principal <= 5000000) return 5;
    if (principal <= 10000000) return 4;
    return 3;
}

// Show only the eligibility sub-fields the selected product actually
// requires (loan_product_rules flags, already present in the same
// loan-product-settings payload the rate preview uses -- no new AJAX
// call). Hiding a group clears its value so a stale entry from a
// previously-selected product is never silently submitted.
function toggleEligibilityFields(s) {
    const groups = {
        eligIncomeSourceGroup:  { show: !!(s && +s.requires_income_source === 1), fields: ['income_source'] },
        eligIncomeDetailsGroup: { show: !!(s && +s.requires_income_source === 1), fields: ['income_details'] },
        eligAssetPriceGroup:    { show: !!(s && parseFloat(s.member_contribution_pct) > 0), fields: ['asset_purchase_price'] },
        eligContributionGroup:  { show: !!(s && parseFloat(s.member_contribution_pct) > 0), fields: ['member_contribution'] },
        eligSecurityTypeGroup:  { show: !!(s && +s.requires_security === 1), fields: ['security_type'] },
        eligSecurityDescGroup:  { show: !!(s && +s.requires_security === 1), fields: ['security_description'] },
        eligWeeklySavingsGroup: { show: !!(s && +s.requires_weekly_savings === 1), fields: ['weekly_savings_commitment'] },
        eligGracePeriodGroup:   { show: !!(s && +s.show_grace_period_field === 1), fields: [] },
    };
    let anyVisible = false;
    Object.keys(groups).forEach(function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        const g = groups[id];
        el.style.display = g.show ? '' : 'none';
        if (g.show) { anyVisible = true; }
        else {
            g.fields.forEach(function(fieldId) {
                const f = document.getElementById(fieldId);
                if (f) f.value = '';
            });
        }
    });

    const cardEl = document.getElementById('eligibilityCard');
    if (cardEl) cardEl.style.display = anyVisible ? '' : 'none';

    const introEl = document.getElementById('eligibilityIntro');
    if (introEl && s) {
        const parts = [];
        if (+s.requires_income_source === 1) parts.push('a stated source of income');
        if (parseFloat(s.member_contribution_pct) > 0) parts.push('the asset price and a member contribution of at least ' + parseFloat(s.member_contribution_pct) + '%');
        if (+s.requires_security === 1) parts.push(s.security_type ? (s.security_type + ' security') : 'security to be recorded');
        if (+s.requires_weekly_savings === 1) parts.push('a weekly savings commitment amount');
        introEl.textContent = parts.length ? ('This product requires: ' + parts.join('; ') + '.') : '';
    }

    const contributionHintEl = document.getElementById('eligContributionHint');
    if (contributionHintEl) {
        contributionHintEl.textContent = (s && parseFloat(s.member_contribution_pct) > 0)
            ? ('Requires at least ' + parseFloat(s.member_contribution_pct) + '% of the asset purchase price.') : '';
    }
    const gracePeriodMsgEl = document.getElementById('eligGracePeriodMsg');
    if (gracePeriodMsgEl && s) {
        const graceMonths = parseInt(s.grace_period_months) || 0;
        gracePeriodMsgEl.innerHTML = graceMonths > 0
            ? ('<i class="bi bi-hourglass-split me-1"></i><strong>Grace Period: ' + graceMonths + ' month' + (graceMonths === 1 ? '' : 's') + '.</strong> '
               + 'Principal repayment is deferred for the first ' + graceMonths + ' month' + (graceMonths === 1 ? '' : 's') + ' of this loan -- interest is still due during that time. Applied automatically; not editable per loan.')
            : ('<i class="bi bi-exclamation-triangle me-1"></i><strong>Grace Period: not currently configured (0 months)</strong> for this product, even though it is meant to support one. No grace period will be applied to this loan until product configuration is corrected.');
    }
    const securityTypeHintEl = document.getElementById('eligSecurityTypeHint');
    if (securityTypeHintEl) {
        securityTypeHintEl.textContent = (s && s.security_type)
            ? ('This product requires ' + s.security_type + ' security; the submitted value is locked to that type.') : '';
    }
}

// Fetch product settings on loan type change
const loanTypeEl = document.getElementById('loan_type_id');
if (loanTypeEl) {
    loanTypeEl.addEventListener('change', function() {
        const typeId = this.value;
        if (!typeId) {
            productRate = 0; productProcessingFee = 3; productBrackets = [];
            productRepaymentType = 'installment';
            document.getElementById('productInfo').innerHTML = 'Select a loan product to see its rates and limits.';
            toggleEligibilityFields(null);
            recalc(); return;
        }
        fetch('<?= APP_URL ?>/index.php?page=loan-product-settings&type_id=' + typeId)
            .then(r => r.json())
            .then(data => {
                if (data.settings) {
                    const s = data.settings;
                    productRate = parseFloat(s.monthly_interest_rate) || 0;
                    productProcessingFee = parseFloat(s.processing_fee_pct) || 3;
                    productMinAmount = parseFloat(s.min_amount) || 0;
                    productMaxAmount = parseFloat(s.max_amount) || 0;
                    productMinPeriod = parseInt(s.min_period_months) || 1;
                    productMaxPeriod = parseInt(s.max_period_months) || 12;
                    productRepaymentType = s.repayment_type || 'installment';
                    productBrackets = data.brackets || [];
                    toggleEligibilityFields(s);

                    let info = '<strong>' + s.loan_type_name + '</strong>';
                    if (productRepaymentType === 'interest_only') {
                        info += ' <span class="badge bg-info-subtle text-info" style="font-size:.55rem;">Interest Only Monthly</span>';
                    }
                    info += '<br>';
                    if (productBrackets.length === 1 && parseFloat(productBrackets[0].min_amount) === 0) {
                        info += 'Rate: ' + productBrackets[0].monthly_rate + '% flat/mo';
                    } else {
                        productBrackets.forEach(b => {
                            const min = parseInt(b.min_amount).toLocaleString();
                            const max = parseFloat(b.max_amount) > 0 ? parseInt(b.max_amount).toLocaleString() : '∞';
                            info += min + ' – ' + max + ': <strong>' + b.monthly_rate + '%</strong> · ';
                        });
                    }
                    info += '<br>Fee: ' + productProcessingFee + '% · Period: ' + s.min_period_months + '–' + s.max_period_months + ' mo';
                    document.getElementById('productInfo').innerHTML = info;
                }
                recalc();
            }).catch(() => { toggleEligibilityFields(null); recalc(); });
    });
    // Trigger on page load if value already set
    if (loanTypeEl.value) loanTypeEl.dispatchEvent(new Event('change'));

    // Show/hide repayment method and business details based on product type
    loanTypeEl.addEventListener('change', function() {
        const repMethodGroup = document.getElementById('repaymentMethodGroup');
        const bizCard = document.getElementById('businessDetailsCard');
        const procFeeRow = document.querySelectorAll('.procFeeRow');
        const isBusinessLoan = (this.value == '2');
        if (repMethodGroup) repMethodGroup.style.display = isBusinessLoan ? '' : 'none';
        if (bizCard) bizCard.style.display = isBusinessLoan ? '' : 'none';
        procFeeRow.forEach(el => el.style.display = isBusinessLoan ? 'none' : '');
        recalc();
    });
    // Trigger on load
    if (loanTypeEl.value) {
        const bizCard = document.getElementById('businessDetailsCard');
        const repMethodGroup = document.getElementById('repaymentMethodGroup');
        const procFeeRow = document.querySelectorAll('.procFeeRow');
        const isBusinessLoan = (loanTypeEl.value == '2');
        if (bizCard) bizCard.style.display = isBusinessLoan ? '' : 'none';
        if (repMethodGroup) repMethodGroup.style.display = isBusinessLoan ? '' : 'none';
        procFeeRow.forEach(el => el.style.display = isBusinessLoan ? 'none' : '');
    }
}

/* ── DOM refs ─────────────────────────────────────────────── */
const loanEl           = document.getElementById('loan_amount');
const periodEl         = document.getElementById('repayment_period');
const issueEl          = document.getElementById('issue_date');
const dueDateEl        = document.getElementById('due_date');
const dueDisplayEl     = document.getElementById('due_date_display');
// Display-only (read-only text inputs)
const rateDisplayEl    = document.getElementById('interest_rate_display'); // SUGGESTED rate -- never submitted
const intDisplayEl     = document.getElementById('interest_amount_display');
const totalDisplayEl   = document.getElementById('total_payable_display');
const instalmentEl     = document.getElementById('instalment_display');
// Editable / hidden fields (submitted)
const approvedRateEl   = document.getElementById('approved_interest_rate'); // Stage 9.1: the APPROVED rate, actually submitted
const rateHiddenEl     = document.getElementById('interest_rate'); // legacy mirror, harmless if unused server-side
const intHiddenEl      = document.getElementById('interest_amount');
const totalHiddenEl    = document.getElementById('total_payable');
const amountRangeMsgEl = document.getElementById('amountRangeMsg');
const amountRateHintEl = document.getElementById('amountRateHint');
const rateOverrideIndicatorEl   = document.getElementById('rateOverrideIndicator');
const rateOverrideReasonGroupEl = document.getElementById('rateOverrideReasonGroup');

// Stage 9.1: the approved-rate field auto-syncs to the suggested rate
// until the user manually edits it; editing a loan that already has a
// stored rate starts "touched" so the loaded value isn't immediately
// clobbered by the first product-fetch-triggered recalc(). Changing the
// product or amount afterwards resets this, since a manual value from a
// different amount/product should not silently survive unnoticed.
let approvedRateTouched = <?= (!empty($loan['interest_rate']) && (float)$loan['interest_rate'] > 0) ? 'true' : 'false' ?>;
if (approvedRateEl) {
    approvedRateEl.addEventListener('input', function() { approvedRateTouched = true; recalc(); });
}
if (loanEl) { loanEl.addEventListener('input', function() { approvedRateTouched = false; }); }
if (loanTypeEl) { loanTypeEl.addEventListener('change', function() { approvedRateTouched = false; }); }

function fmtPct(n){ return n.toFixed(4).replace(/\.?0+$/, ''); }

/* ── Core calculation ─────────────────────────────────────── */
function recalc(){
    const principal = parseFloat(loanEl?.value) || 0;

    // Interest Mode: percentage or fixed
    const intMode = document.getElementById('interestMode')?.value || 'percentage';
    const fixedInt = parseFloat(document.getElementById('fixedInterestAmount')?.value) || 0;
    const typeSelected = !!(loanTypeEl && loanTypeEl.value);

    // Suggested rate — always computed from the loaded product's own
    // brackets/flat rate (never hardcoded here); empty-state messages per
    // whether a product/amount is available yet. The server independently
    // recalculates this from scratch and never trusts anything submitted
    // for it -- this is UX preview only.
    let suggestedRate = 0;
    if (intMode === 'fixed') {
        if (rateDisplayEl) rateDisplayEl.value = 'n/a (fixed amount mode)';
        if (amountRateHintEl) amountRateHintEl.textContent = '';
    } else if (!typeSelected) {
        if (rateDisplayEl) rateDisplayEl.value = '—';
        if (amountRateHintEl) amountRateHintEl.textContent = 'Select a loan product to determine the applicable rate.';
    } else if (principal <= 0) {
        if (rateDisplayEl) rateDisplayEl.value = '—';
        if (amountRateHintEl) amountRateHintEl.textContent = 'Enter a loan amount to calculate the applicable rate.';
    } else {
        suggestedRate = getMonthlyRate(principal);
        if (rateDisplayEl) rateDisplayEl.value = fmtPct(suggestedRate) + '%';
        if (amountRateHintEl) amountRateHintEl.textContent = '';
    }

    // Amount-range feedback -- UX only, from the already-loaded product
    // limits; validateAgainstProductRules() is the unchanged, authoritative
    // server-side check.
    if (amountRangeMsgEl) {
        if (typeSelected && principal > 0 && productMinAmount > 0 && principal < productMinAmount) {
            amountRangeMsgEl.innerHTML = '<span class="text-danger">Minimum loan amount is Shs ' + fmt(productMinAmount) + ' for this product.</span>';
        } else if (typeSelected && principal > 0 && productMaxAmount > 0 && principal > productMaxAmount) {
            amountRangeMsgEl.innerHTML = '<span class="text-danger">Amount exceeds the maximum of Shs ' + fmt(productMaxAmount) + ' allowed for this loan product.</span>';
        } else {
            amountRangeMsgEl.textContent = '';
        }
    }

    // Approved rate: keep in sync with the suggestion until the operator
    // deliberately edits it.
    if (approvedRateEl && intMode !== 'fixed' && !approvedRateTouched) {
        approvedRateEl.value = suggestedRate > 0 ? fmtPct(suggestedRate) : '';
    }

    // This is what's actually submitted and what drives every figure
    // below -- the server re-derives everything from scratch regardless
    // (LoanController::collectInput()/LoanProductModel::applyRate()), this
    // is purely for the on-screen preview to match what will be saved.
    const monthlyRate = (intMode === 'fixed') ? 0 : (parseFloat(approvedRateEl?.value) || 0);

    if (rateOverrideIndicatorEl) {
        if (intMode === 'fixed' || !typeSelected || principal <= 0 || suggestedRate <= 0 || monthlyRate <= 0) {
            rateOverrideIndicatorEl.textContent = '';
            if (rateOverrideReasonGroupEl) rateOverrideReasonGroupEl.style.display = 'none';
        } else if (Math.abs(monthlyRate - suggestedRate) > 0.0001) {
            rateOverrideIndicatorEl.innerHTML = '<span class="text-warning">⚠ Rate adjusted from ' + fmtPct(suggestedRate) + '% to ' + fmtPct(monthlyRate) + '%</span>';
            if (rateOverrideReasonGroupEl) rateOverrideReasonGroupEl.style.display = '';
        } else {
            rateOverrideIndicatorEl.innerHTML = '<span class="text-success">✓ Using product rate</span>';
            if (rateOverrideReasonGroupEl) rateOverrideReasonGroupEl.style.display = 'none';
        }
    }

    // Get period
    const selectedOpt  = periodEl?.options[periodEl.selectedIndex];
    const months       = parseInt(selectedOpt?.dataset.months || '0');
    const period       = selectedOpt?.value || '';

    // Repayment frequency
    const freq = document.getElementById('repaymentFrequency')?.value || 'monthly';

    // Calculate interest based on mode
    let recurringInterest, totalInterest;
    if (intMode === 'fixed' && fixedInt > 0) {
        // Fixed mode: the entered amount matches the chosen frequency
        if (freq === 'weekly') {
            // fixedInt = weekly amount → monthly = weekly × 4, total = weekly × months × 4
            recurringInterest = fixedInt * 4; // monthly equivalent
            totalInterest = fixedInt * months * 4;
        } else {
            // fixedInt = monthly amount
            recurringInterest = fixedInt;
            totalInterest = fixedInt * months;
        }
    } else {
        // Percentage mode: calculate from rate
        recurringInterest = principal * (monthlyRate / 100);
        totalInterest = recurringInterest * months;
    }

    const procFee      = principal * (productProcessingFee / 100);
    const totalPayable = principal + totalInterest;

    // Business Loan (interest_only): monthly payment = interest only
    let instalment;
    let instalmentLabelText = 'Monthly Instalment (Shs)';

    if (productRepaymentType === 'interest_only') {
        instalment = recurringInterest;
        instalmentLabelText = 'Monthly Interest Due (Shs)';
    } else {
        instalment = months > 0 ? totalPayable / months : 0;
        instalmentLabelText = 'Monthly Instalment (Shs)';
    }

    // Repayment frequency adjustments
    const repaymentMethodEl = document.getElementById('repaymentMethod');
    const isBusinessBoost = productRepaymentType === 'interest_only' && repaymentMethodEl && repaymentMethodEl.value === 'business_boost';
    if (freq === 'weekly') {
        if (intMode === 'fixed' && fixedInt > 0) {
            // Fixed weekly interest: show the weekly interest amount
            instalmentLabelText = 'Weekly Interest Due (Shs)';
            instalment = fixedInt;
        } else if (productRepaymentType === 'interest_only' && !isBusinessBoost) {
            // "Interest Only (Standard)" -- this preview shows the
            // interest-only phase's weekly figure; the loan's final 8
            // weeks additionally recover principal (see the generated
            // schedule after saving for the full picture).
            instalmentLabelText = 'Weekly Interest Due (Shs)';
            // Percentage: monthly interest ÷ 4
            instalment = recurringInterest / 4;
        } else {
            // Standard installment products, and "Business Boost" --
            // Stage 9.2-B: Business Boost pays principal + interest every
            // week from week 1, so its preview is the full total_payable
            // split evenly across the term, exactly like any other
            // fully-amortizing weekly loan.
            instalmentLabelText = 'Weekly Instalment (Shs)';
            const weeks = months * 4;
            instalment = weeks > 0 ? totalPayable / weeks : 0;
        }
    }

    // Update instalment label
    const instalmentLabel = document.getElementById('instalmentLabel');
    if (instalmentLabel) instalmentLabel.textContent = instalmentLabelText;

    // Due date
    const dueDate = addMonths(issueEl?.value, months);

    // Processing fee
    const procFeeEl = document.getElementById('processing_fee_display');
    const procFeeHidden = document.getElementById('processing_fee');
    if(procFeeEl)     procFeeEl.value     = procFee > 0 ? procFee.toFixed(2) : '';
    if(procFeeHidden) procFeeHidden.value = procFee.toFixed(2);

    // Update other display fields
    if(intDisplayEl)   intDisplayEl.value   = totalInterest > 0 ? totalInterest.toFixed(2) : '';
    if(totalDisplayEl) totalDisplayEl.value = totalPayable > 0 ? totalPayable.toFixed(2) : '';
    if(instalmentEl)   instalmentEl.value   = instalment > 0 ? instalment.toFixed(2) : '';

    // Update hidden interest rate field
    const rateHidden = document.getElementById('interest_rate');
    if(rateHidden) rateHidden.value = monthlyRate.toFixed(2);

    // Update hidden fields (sent to server)
    if(rateHiddenEl)  rateHiddenEl.value  = monthlyRate.toFixed(2);
    if(intHiddenEl)   intHiddenEl.value   = totalInterest.toFixed(2);
    if(totalHiddenEl) totalHiddenEl.value = totalPayable.toFixed(2);

    // Due date
    if(dueDateEl)    dueDateEl.value    = toISODate(dueDate);
    if(dueDisplayEl) dueDisplayEl.value = formatDate(dueDate);

    // Summary panel
    const issueFormatted = issueEl?.value
        ? new Date(issueEl.value).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})
        : '—';

    setText('previewIssue',      issueFormatted);
    setText('previewPeriod',     period || '—');
    setText('previewDue',        formatDate(dueDate));
    setText('previewRate',       monthlyRate > 0 ? monthlyRate.toFixed(2) + '% / month' : '—');
    setText('previewLoan',       principal > 0 ? 'Shs ' + fmt(principal) : '—');
    setText('previewInterest',   totalInterest > 0 ? 'Shs ' + fmt(totalInterest) : 'Shs 0.00');
    setText('previewProcFee',    procFee > 0 ? 'Shs ' + fmt(procFee) : '—');
    setText('previewTotal',      totalPayable > 0 ? 'Shs ' + fmt(totalPayable) : '—');
    setText('previewInstalment', instalment > 0 ? 'Shs ' + fmt(instalment) + (freq === 'weekly' ? ' / wk' : ' / mo') : '—');
    // Stage 9.2 follow-up: this used to ignore instalmentLabelText and
    // always say "Weekly/Monthly Instalment" regardless of what the figure
    // actually represents -- for an interest_only product (Business Loan)
    // the figure is the interest-only phase's recurring payment, not a
    // full principal+interest instalment, and must say so. Business
    // Boost's real saved schedule (generateBusinessBoostSchedule(), not
    // this preview) has its own separate two-phase interest-only/recovery
    // structure -- this label only describes what this single preview
    // number means, not the whole schedule.
    setText('previewInstalmentLabel', instalmentLabelText.replace(' (Shs)', ''));
}

/* ── Event listeners ──────────────────────────────────────── */
[loanEl, periodEl, issueEl, rateDisplayEl].forEach(el => {
    if(el){ el.addEventListener('input', recalc); el.addEventListener('change', recalc); }
});

// Interest mode toggle
const interestModeEl = document.getElementById('interestMode');
const fixedInterestGroup = document.getElementById('fixedInterestGroup');
const fixedInterestEl = document.getElementById('fixedInterestAmount');

if (interestModeEl) {
    interestModeEl.addEventListener('change', function() {
        if (fixedInterestGroup) {
            fixedInterestGroup.style.display = (this.value === 'fixed') ? '' : 'none';
        }
        recalc();
    });
    // Trigger on load
    if (interestModeEl.value === 'fixed' && fixedInterestGroup) fixedInterestGroup.style.display = '';
}
if (fixedInterestEl) {
    fixedInterestEl.addEventListener('input', recalc);
}

// Stage 9.2-B: the preview must update immediately when switching between
// "Interest Only (Standard)" and "Business Boost", since they now show
// genuinely different weekly figures.
const repaymentMethodSelectEl = document.getElementById('repaymentMethod');
if (repaymentMethodSelectEl) {
    repaymentMethodSelectEl.addEventListener('change', recalc);
}

// Repayment frequency change → recalculate and update fixed interest label
const repFreqEl = document.getElementById('repaymentFrequency');
if (repFreqEl) {
    repFreqEl.addEventListener('change', function() {
        // Update the "Fixed Recurring Interest" label to reflect weekly/monthly
        const fixedLabel = document.getElementById('fixedInterestLabel');
        if (fixedLabel) {
            fixedLabel.textContent = this.value === 'weekly'
                ? 'Fixed Weekly Interest (Shs)'
                : 'Fixed Monthly Interest (Shs)';
        }
        recalc();
    });
    // Trigger on load if weekly is already selected
    if (repFreqEl.value === 'weekly') {
        const fixedLabel = document.getElementById('fixedInterestLabel');
        if (fixedLabel) fixedLabel.textContent = 'Fixed Weekly Interest (Shs)';
    }
}

/* ── Helpers ─────────────────────────────────────────────── */
function escHtml(str){ const d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }

function loanBadgeHtml(s){
    const map = {
        'Active':    ['bg-success-subtle text-success', 'bi-bank2'],
        'Overdue':   ['bg-danger-subtle text-danger',   'bi-exclamation-triangle'],
        'Completed': ['bg-primary-subtle text-primary', 'bi-check2-all'],
    };
    const [cls, icon] = map[s] || ['bg-light text-muted border', 'bi-dash'];
    return `<span class="badge ${cls} px-2" style="font-size:.65rem;font-weight:600"><i class="bi ${icon} me-1"></i>${escHtml(s||'None')}</span>`;
}

function buildMemberCardHtml(m){
    const initial    = escHtml((m.full_name||'M').charAt(0).toUpperCase());
    const name       = escHtml(m.full_name     || '—');
    const memNo      = escHtml(m.member_number || '—');
    const accNo      = m.account_number
        ? `<span class="fw-semibold" style="color:#1B2B6B">${escHtml(m.account_number)}</span>`
        : '<span class="text-muted fst-italic" style="font-size:.78rem">Not assigned</span>';
    const phone      = escHtml(m.phone || '—');
    const mBadge     = m.status === 'active'
        ? '<span class="badge bg-success-subtle text-success px-2" style="font-size:.65rem"><i class="bi bi-check-circle me-1"></i>Active</span>'
        : '<span class="badge bg-secondary-subtle text-secondary px-2" style="font-size:.65rem"><i class="bi bi-x-circle me-1"></i>Inactive</span>';
    const lBadge     = loanBadgeHtml(m.loan_status || 'None');
    const outstanding = parseFloat(m.outstanding || 0);
    const outFmt     = outstanding > 0
        ? `<span class="fw-bold text-danger">UGX ${outstanding.toLocaleString('en',{maximumFractionDigits:0})}</span>`
        : '<span class="text-muted">UGX 0</span>';
    const warningRow = ['Active','Overdue'].includes(m.loan_status)
        ? `<div class="mt-3 alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-2" style="font-size:.8rem;">
             <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
             <span>This member has an <strong>${escHtml(m.loan_status)}</strong> loan. Confirm before proceeding.</span>
           </div>` : '';
    return `
    <div class="card border-0 rounded-3 shadow-sm" style="background:#f8f9fc;">
      <div class="card-body p-3">
        <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
          <div class="member-avatar-sm bg-blue flex-shrink-0"
               style="width:44px;height:44px;font-size:1.05rem;line-height:44px;text-align:center;border-radius:50%;">${initial}</div>
          <div class="flex-grow-1 min-width-0">
            <div class="fw-bold" style="font-size:.95rem;color:#1B2B6B;line-height:1.2">${name}</div>
            <div class="text-muted mt-1" style="font-size:.72rem">${escHtml(m.member_number||'')}</div>
          </div>
          <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"
                style="font-size:.62rem;white-space:nowrap">✓ Selected</span>
        </div>
        <div class="row g-3" style="font-size:.82rem;">
          <div class="col-6 col-md-4">
            <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.62rem;letter-spacing:.05em">Membership No.</div>
            <div class="fw-semibold">${memNo}</div>
          </div>
          <div class="col-6 col-md-4">
            <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.62rem;letter-spacing:.05em">Account No.</div>
            <div>${accNo}</div>
          </div>
          <div class="col-6 col-md-4">
            <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.62rem;letter-spacing:.05em">Phone</div>
            <div class="fw-semibold">${phone}</div>
          </div>
          <div class="col-6 col-md-4">
            <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.62rem;letter-spacing:.05em">Membership Status</div>
            <div>${mBadge}</div>
          </div>
          <div class="col-6 col-md-4">
            <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.62rem;letter-spacing:.05em">Current Loan</div>
            <div>${lBadge}</div>
          </div>
          <div class="col-6 col-md-4">
            <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.62rem;letter-spacing:.05em">Outstanding Balance</div>
            <div>${outFmt}</div>
          </div>
        </div>
        ${warningRow}
      </div>
    </div>`;
}

/* ── Member autocomplete ──────────────────────────────────── */
const searchEl   = document.getElementById('memberSearch');
const dropdown   = document.getElementById('memberDropdown');
const memberIdEl = document.getElementById('memberId');
let timer;

if(searchEl){
    searchEl.addEventListener('input', function(){
        clearTimeout(timer);
        const q = this.value.trim();
        if(q.length < 2){ if(dropdown) dropdown.style.display='none'; return; }
        timer = setTimeout(() => {
            fetch('<?= APP_URL ?>/index.php?page=loan-member-search&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if(!dropdown) return;
                    dropdown.innerHTML = '';
                    if(!data.members?.length){ dropdown.style.display='none'; return; }
                    data.members.forEach(m => {
                        const a = document.createElement('a');
                        a.href='#'; a.className='list-group-item list-group-item-action py-2 px-3';
                        const accLine = m.account_number
                            ? `<span style="color:#1B2B6B;font-weight:600"><i class="bi bi-hash"></i>${escHtml(m.account_number)}</span> · ` : '';
                        a.innerHTML = `
                          <div class="d-flex align-items-center gap-2">
                            <div style="width:30px;height:30px;border-radius:50%;background:#1B2B6B;color:#fff;
                                        display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0">
                              ${escHtml(m.full_name.charAt(0).toUpperCase())}
                            </div>
                            <div class="flex-grow-1 min-width-0">
                              <div class="fw-semibold small">${escHtml(m.full_name)}</div>
                              <div class="text-muted" style="font-size:.72rem">
                                ${escHtml(m.member_number)} · ${accLine}${escHtml(m.phone)}
                              </div>
                            </div>
                            ${loanBadgeHtml(m.loan_status)}
                          </div>`;
                        a.addEventListener('click', e => { e.preventDefault(); selectMember(m); });
                        dropdown.appendChild(a);
                    });
                    dropdown.style.display = 'block';
                }).catch(()=>{});
        }, 300);
    });
    document.addEventListener('click', e => {
        const wrap = searchEl.closest('.position-relative');
        if(wrap && !wrap.contains(e.target) && dropdown) dropdown.style.display = 'none';
    });
}

/* ── Account Number lookup ────────────────────────────────── */
const accLookupEl  = document.getElementById('accountLookup');
const accLookupBtn = document.getElementById('accountLookupBtn');
const accLookupMsg = document.getElementById('accountLookupMsg');

function setLookupMsg(txt, cls){
    if(!accLookupMsg) return;
    accLookupMsg.textContent = txt;
    accLookupMsg.className   = 'small mt-1 ' + (cls||'');
}
function lookupByAccount(){
    const val = accLookupEl?.value.trim();
    if(!val){ setLookupMsg('',''); return; }
    setLookupMsg('Searching…', 'text-muted');
    fetch('<?= APP_URL ?>/index.php?page=loan-member-lookup&account_number=' + encodeURIComponent(val))
        .then(r => r.json())
        .then(data => {
            if(data.member){
                selectMember(data.member);
                setLookupMsg('✓ Member found: ' + data.member.full_name, 'text-success');
            } else {
                setLookupMsg('✗ No member found with account number "' + val + '"', 'text-danger');
            }
        }).catch(()=>{ setLookupMsg('Error during lookup. Please try again.', 'text-danger'); });
}
if(accLookupBtn) accLookupBtn.addEventListener('click', lookupByAccount);
if(accLookupEl)  accLookupEl.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); lookupByAccount(); } });

/* ── selectMember: populate hidden fields + render info card ─ */
function selectMember(m){
    // Update hidden inputs
    if(memberIdEl) memberIdEl.value = m.id;
    const accHidden = document.getElementById('memberAccountNumber');
    if(accHidden) accHidden.value = m.account_number || '';

    // Sync search field text and account lookup field
    if(searchEl)    searchEl.value = m.full_name + (m.member_number ? ' (' + m.member_number + ')' : '');
    if(accLookupEl && m.account_number) accLookupEl.value = m.account_number;
    if(dropdown)    dropdown.style.display = 'none';

    // Show / update info card
    const card        = document.getElementById('memberInfoCard');
    const placeholder = document.getElementById('memberInfoPlaceholder');
    const clearBtn    = document.getElementById('clearMember');
    if(card)        { card.innerHTML = buildMemberCardHtml(m); card.classList.remove('d-none'); }
    if(placeholder) placeholder.classList.add('d-none');
    if(clearBtn)    clearBtn.classList.remove('d-none');

    // Update live summary preview
    setText('previewMember', m.full_name);
}

/* ── Clear selected member ────────────────────────────────── */
document.getElementById('clearMember')?.addEventListener('click', () => {
    if(memberIdEl) memberIdEl.value = '0';
    const accHidden = document.getElementById('memberAccountNumber');
    if(accHidden)   accHidden.value = '';
    if(searchEl)    searchEl.value = '';
    if(accLookupEl) accLookupEl.value = '';
    setLookupMsg('', '');
    if(dropdown)    dropdown.style.display = 'none';

    const card        = document.getElementById('memberInfoCard');
    const placeholder = document.getElementById('memberInfoPlaceholder');
    const clearBtn    = document.getElementById('clearMember');
    if(card)        { card.innerHTML = ''; card.classList.add('d-none'); }
    if(placeholder) placeholder.classList.remove('d-none');
    if(clearBtn)    clearBtn.classList.add('d-none');

    setText('previewMember', '—');
});

/* ── Reset ────────────────────────────────────────────────── */
document.getElementById('resetBtn')?.addEventListener('click', function(){
    setTimeout(recalc, 50);
});

/* ── Form submit ──────────────────────────────────────────── */
const form = document.getElementById('loanForm');
const btn  = document.getElementById('submitBtn');
form.addEventListener('submit', function(e){
    if(!dueDateEl?.value){
        e.preventDefault(); e.stopPropagation();
        periodEl.classList.add('is-invalid');
        periodEl.scrollIntoView({behavior:'smooth', block:'center'});
        return;
    }
    if(!form.checkValidity() || (memberIdEl && parseInt(memberIdEl.value) < 1)){
        e.preventDefault(); e.stopPropagation();
        if(memberIdEl && parseInt(memberIdEl.value) < 1 && searchEl)
            searchEl.classList.add('is-invalid');
        const first = form.querySelector(':invalid');
        if(first) first.scrollIntoView({behavior:'smooth', block:'center'});
    } else {
        btn.disabled=true;
        btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
    }
    form.classList.add('was-validated');
});

/* ── Init ─────────────────────────────────────────────────── */
recalc();

})();
</script>
