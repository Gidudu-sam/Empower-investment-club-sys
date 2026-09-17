<?php
$base    = APP_URL . '/index.php';
$v       = fn(string $k, string $d='') => htmlspecialchars($repayment[$k] ?? $d);
$err     = fn(string $k) => $errors[$k] ?? '';
$cls     = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$methods = ['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'];

// Pre-load loan data for the summary panel
$loanData = $preLoan ?? null;
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-arrow-down-circle-fill me-2" style="color:var(--brand-orange)"></i>Record Repayment
        </h1>
        <p class="text-muted mb-0 small">Receipt: <strong style="color:var(--ink)"><?= htmlspecialchars($repaymentNumber) ?></strong></p>
    </div>
    <a href="<?=$base?>?page=repayments" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<form id="repayForm" method="POST" action="<?= $formAction ?>" novalidate>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" name="member_id" id="hiddenMemberId" value="<?= (int)($repayment['member_id'] ?? $loanData['member_id'] ?? 0) ?>">
<input type="hidden" name="submission_token" value="<?= htmlspecialchars($submissionToken ?? '') ?>">

<div class="row g-4">
<div class="col-lg-8">

    <!-- Member/Loan Search -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-person-fill" style="color:var(--brand-orange)"></i>
            <h6 class="mb-0 fw-semibold">Search Member</h6>
        </div>
        <div class="card-body p-4">
            <input type="hidden" name="loan_id" id="loanId" value="<?= (int)($repayment['loan_id'] ?? $loanData['id'] ?? 0) ?>">

            <?php if ($loanData): ?>
            <!-- Pre-selected loan -->
            <div id="loanCard" class="p-3 rounded-3 bg-light border">
                <?php include __DIR__ . '/partials/loan-card.php'; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clearLoan">
                    <i class="bi bi-x me-1"></i>Change Member/Loan
                </button>
            </div>
            <?php else: ?>
            <label class="form-label fw-semibold" for="memberSearch">Member Name or Number <span class="text-danger">*</span></label>
            <div class="input-group mb-1">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="memberSearch" class="form-control<?= $cls('loan_id') ?>"
                       placeholder="Type member name or number..." autocomplete="off">
            </div>
            <?php if ($err('loan_id')): ?><div class="text-danger small mb-2"><?= htmlspecialchars($err('loan_id')) ?></div><?php endif; ?>
            <div id="memberDropdown" class="list-group shadow"
                 style="position:absolute;z-index:1050;width:100%;max-width:480px;display:none;max-height:260px;overflow-y:auto;"></div>
            <div id="loanCard" class="d-none p-3 rounded-3 bg-light border mt-2">
                <div id="loanCardContent"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clearLoan">
                    <i class="bi bi-x me-1"></i>Change Member/Loan
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Type - Auto-detected with option to override -->
    <div class="card mb-4" id="paymentTypeCard" style="display:none;">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-tag" style="color:var(--brand-orange)"></i>
            <h6 class="mb-0 fw-semibold">Payment Type</h6>
        </div>
        <div class="card-body p-4">
            <!-- Auto-detected payment type (hidden input) -->
            <input type="hidden" name="payment_type" id="paymentTypeHidden" value="installment">
            
            <div class="alert alert-info mb-3" id="autoDetectedType">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Payment Type:</strong> <span id="autoDetectedLabel"><?= htmlspecialchars($repaymentFrequencyLabel ?? 'Regular Installment Payment') ?></span>
                <div class="small mt-1" id="autoDetectedHint">This payment will reduce your loan balance according to the repayment schedule.</div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="showAdvancedPaymentType">
                <label class="form-check-label small text-muted" for="showAdvancedPaymentType">
                    Show advanced payment options (settlement, interest-only, etc.)
                </label>
            </div>

            <div id="advancedPaymentTypeGroup" style="display:none;">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">What are you paying? <span class="text-danger">*</span></label>
                        <select name="payment_type_override" id="paymentType" class="form-select">
                            <option value="installment">Regular Installment Payment</option>
                            <option value="interest">Monthly Interest Payment</option>
                            <option value="weekly_savings">Weekly Interest Payment</option>
                            <option value="principal">Principal Repayment</option>
                            <option value="settlement">Full Settlement</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="weekCoveredGroup" style="display:none;">
                        <label class="form-label fw-semibold">Week Covered</label>
                        <input type="text" name="week_covered" id="weekCovered" class="form-control"
                               placeholder="e.g. Week 30, Jul 2026" value="<?= htmlspecialchars($repayment['week_covered'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-3 p-2 rounded" style="background:var(--paper);font-size:.72rem;color:var(--slate);" id="paymentTypeHint">
                    Standard installment payment — reduces the outstanding loan balance.
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Details -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-cash-coin" style="color:var(--brand-orange)"></i>
            <h6 class="mb-0 fw-semibold">Payment Details</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">

                <!-- Amount Paid -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="amount_paid">
                        Amount Paid (Shs) <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text fw-bold" style="color:var(--slate)">Shs</span>
                        <input type="number" id="amount_paid" name="amount_paid"
                               step="0.01" min="0.01"
                               class="form-control<?= $cls('amount_paid') ?>"
                               value="<?= $v('amount_paid') ?>" placeholder="0.00" required>
                    </div>
                    <?php if ($err('amount_paid')): ?>
                    <div class="text-danger small mt-1"><?= htmlspecialchars($err('amount_paid')) ?></div>
                    <?php endif; ?>
                    <div class="form-text" id="maxHint"></div>
                </div>

                <!-- Payment Method -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="payment_method">
                        Payment Method <span class="text-danger">*</span>
                    </label>
                    <select id="payment_method" name="payment_method"
                            class="form-select<?= $cls('payment_method') ?>" required>
                        <option value="" <?= ($v('payment_method','')==='')?'selected':'' ?> disabled>Select payment source</option>
                        <?php foreach ($methods as $pm): ?>
                        <option value="<?=$pm?>" <?= ($v('payment_method','')===$pm)?'selected':'' ?>><?=$pm?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('payment_method')): ?>
                    <div class="text-danger small mt-1"><?= htmlspecialchars($err('payment_method')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Penalty Payment (Stage 17 Part C) -->
                <div class="col-md-6" id="penaltyGroup" style="display:none;">
                    <label class="form-label fw-semibold" for="penalty_paid">
                        Penalty Payment (Shs)
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">Shs</span>
                        <input type="number" id="penalty_paid" name="penalty_paid"
                               step="0.01" min="0"
                               class="form-control<?= $cls('penalty_paid') ?>"
                               value="<?= $v('penalty_paid', '0') ?>" placeholder="0.00">
                    </div>
                    <?php if ($err('penalty_paid')): ?>
                    <div class="text-danger small mt-1"><?= htmlspecialchars($err('penalty_paid')) ?></div>
                    <?php endif; ?>
                    <div class="form-text" id="penaltyHint"></div>
                </div>

                <!-- Cash Reference (shown only when Payment Method = Cash) -->
                <div class="col-md-6" id="cashReferenceNote">
                    <label class="form-label fw-semibold">Cash Reference</label>
                    <input type="text" class="form-control" value="Will be generated automatically" disabled readonly>
                </div>

                <!-- Reference Number (shown only for non-Cash methods) -->
                <div class="col-md-6" id="externalReferenceField" style="display:none;">
                    <label class="form-label fw-semibold" for="reference_number">Reference Number</label>
                    <input type="text" id="reference_number" name="reference_number"
                           class="form-control" value="<?= $v('reference_number') ?>"
                           placeholder="Mobile Money code, cheque no..." maxlength="100">
                </div>

                <!-- Payment Date -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="payment_date">
                        Payment Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" id="payment_date" name="payment_date"
                           class="form-control<?= $cls('payment_date') ?>"
                           value="<?= $v('payment_date', date('Y-m-d')) ?>"
                           max="<?= date('Y-m-d') ?>" required>
                    <?php if ($err('payment_date')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('payment_date')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Notes -->
                <div class="col-12">
                    <label class="form-label fw-semibold" for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="2"
                              class="form-control" placeholder="Optional notes..."><?= $v('notes') ?></textarea>
                </div>

            </div>
        </div>
    </div>

</div><!-- /.col-lg-8 -->

<!-- -- RIGHT COLUMN — Summary ------------------------------ -->
<div class="col-lg-4">
    <div style="position:sticky;top:70px;">
    <div class="card mb-3" style="border:1px solid rgba(244,121,32,.3);">
        <div class="card-header d-flex align-items-center gap-2" style="background:rgba(244,121,32,.1)">
            <i class="bi bi-receipt" style="color:var(--brand-orange)"></i>
            <h6 class="mb-0 fw-semibold" style="color:var(--ink)">Payment Summary</h6>
        </div>
        <div class="card-body p-4">
            <dl class="row mb-0 small">
                <dt class="col-7 text-muted">Receipt No.</dt>
                <dd class="col-5 fw-semibold" style="color:var(--ink)"><?= htmlspecialchars($repaymentNumber) ?></dd>
                <dt class="col-7 text-muted">Loan No.</dt>
                <dd class="col-5" id="sumLoanNo"><?= $loanData ? htmlspecialchars($loanData['loan_number']) : '—' ?></dd>
                <dt class="col-7 text-muted">Member</dt>
                <dd class="col-5" id="sumMember"><?= $loanData ? htmlspecialchars($loanData['first_name'].' '.$loanData['last_name']) : '—' ?></dd>
                <hr class="my-2">
                <dt class="col-7 text-muted">Loan Amount</dt>
                <dd class="col-5" id="sumLoanAmt"><?= $loanData ? 'Shs '.number_format($loanData['loan_amount'],2) : '—' ?></dd>
                <dt class="col-7 text-muted">Total Payable</dt>
                <dd class="col-5" id="sumTotal"><?= $loanData ? 'Shs '.number_format($loanData['total_payable'],2) : '—' ?></dd>
                <?php if (isset($nextInstallmentAmount) && $nextInstallmentAmount > 0): ?>
                <dt class="col-7 text-muted fw-semibold" style="color:var(--brand-orange)">Expected Payment</dt>
                <dd class="col-5 fw-semibold" style="color:var(--brand-orange)">Shs <?= number_format($nextInstallmentAmount, 2) ?></dd>
                <?php endif; ?>
                <dt class="col-7 text-muted">Outstanding</dt>
                <dd class="col-5 fw-bold text-danger" id="sumOutstanding"><?= $loanData ? 'Shs '.number_format($loanData['outstanding'],2) : '—' ?></dd>
                <dt class="col-7 text-muted" id="sumOutstandingPenaltyLabel" style="display:none;">Outstanding Penalty</dt>
                <dd class="col-5 fw-bold text-warning" id="sumOutstandingPenalty" style="display:none;">Shs 0.00</dd>
                <hr class="my-2">
                <dt class="col-7 text-muted fw-bold">Paying Now</dt>
                <dd class="col-5 fw-bold fs-5 mb-1" style="color:var(--brand-orange)" id="sumPaying">Shs 0.00</dd>
                <dt class="col-7 text-muted" id="sumPenaltyLabel" style="display:none;">— of which Penalty</dt>
                <dd class="col-5" id="sumPenaltyPortion" style="display:none;">Shs 0.00</dd>
                <dt class="col-7 text-muted">— of which Interest</dt>
                <dd class="col-5" id="sumInterestPortion">Shs 0.00</dd>
                <dt class="col-7 text-muted">— of which Principal</dt>
                <dd class="col-5" id="sumPrincipalPortion">Shs 0.00</dd>
                <dt class="col-7 text-muted">Balance After</dt>
                <dd class="col-5 fw-bold text-success" id="sumAfter">—</dd>
            </dl>
        </div>
    </div>
    <div class="d-flex flex-column gap-2">
        <button type="submit" id="submitBtn" class="btn text-white w-100 fw-semibold py-2" style="background:var(--brand-orange)">
            <i class="bi bi-save me-2"></i>Record Payment
        </button>
        <button type="reset" class="btn btn-light w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
        <a href="<?=$base?>?page=repayments" class="btn btn-outline-secondary w-100"><i class="bi bi-x-lg me-1"></i>Cancel</a>
    </div>
    </div><!-- /sticky wrapper -->
</div>

</div><!-- /.row -->
</form>

<script>
(function(){
'use strict';
const fmt = n => parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
const setText = (id,v) => { const e=document.getElementById(id); if(e) e.textContent=v; };
const showElement = (id) => { const e=document.getElementById(id); if(e) e.style.display=''; };
const hideElement = (id) => { const e=document.getElementById(id); if(e) e.style.display='none'; };

// Make these variables global so selectLoan can access them
window.currentOutstanding = <?= $loanData ? (float)$loanData['outstanding'] : 0 ?>;
window.currentOutstandingPenalty = <?= (float)($outstandingPenalty ?? 0) ?>;
window.currentInterestAmount = <?= $loanData ? (float)$loanData['interest_amount'] : 0 ?>;
window.currentInterestPaidTotal = <?= $loanData ? (float)($loanData['interest_paid_total'] ?? 0) : 0 ?>;
window.currentLoanAmount = <?= $loanData ? (float)$loanData['loan_amount'] : 0 ?>;
window.currentLoanStatus = '<?= $loanData['status'] ?? '' ?>';
window.currentMonthlyInstallment = <?= $loanData ? (float)($loanData['monthly_installment'] ?? 0) : 0 ?>;
window.currentWeeklySavingsAmount = <?= $loanData ? (float)($loanData['weekly_savings_amount'] ?? 0) : 0 ?>;
window.currentLoanTypeId = <?= $loanData ? (int)($loanData['loan_type_id'] ?? 1) : 1 ?>;

const amtEl     = document.getElementById('amount_paid');
const penaltyEl = document.getElementById('penalty_paid');
const loanId    = document.getElementById('loanId');

(function () {
    const methodSel   = document.getElementById('payment_method');
    const cashNote    = document.getElementById('cashReferenceNote');
    const externalRef = document.getElementById('externalReferenceField');
    if (!methodSel || !cashNote || !externalRef) { return; }
    function toggleCashReference() {
        const isCash = methodSel.value === 'Cash';
        cashNote.style.display    = isCash ? '' : 'none';
        externalRef.style.display = isCash ? 'none' : '';
    }
    methodSel.addEventListener('change', toggleCashReference);
    toggleCashReference();
})();

function updatePenaltyFieldVisibility(){
    const group = document.getElementById('penaltyGroup');
    const penaltyEl = document.getElementById('penalty_paid');
    if (!group) return;
    // Only show penalty field if loan is overdue AND has outstanding penalty
    const shouldShowPenalty = (window.currentLoanStatus === 'overdue' && window.currentOutstandingPenalty > 0);
    group.style.display = shouldShowPenalty ? '' : 'none';
    if (penaltyEl) penaltyEl.max = window.currentOutstandingPenalty.toFixed(2);
    
    // Update summary labels visibility
    const penaltyLabel = document.getElementById('sumOutstandingPenaltyLabel');
    if (penaltyLabel) penaltyLabel.style.display = shouldShowPenalty ? '' : 'none';
    const penaltyValue = document.getElementById('sumOutstandingPenalty');
    if (penaltyValue) penaltyValue.style.display = shouldShowPenalty ? '' : 'none';
    
    const fmt = n => parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    const setText = (id,v) => { const e=document.getElementById(id); if(e) e.textContent=v; };
    setText('sumOutstandingPenalty', 'Shs ' + fmt(window.currentOutstandingPenalty));
    const hint = document.getElementById('penaltyHint');
    if (hint) hint.textContent = window.currentOutstandingPenalty > 0
        ? 'Max collectible now: Shs ' + fmt(window.currentOutstandingPenalty)
        : '';
    if (!shouldShowPenalty && penaltyEl) penaltyEl.value = '0';
}
window.updatePenaltyFieldVisibility = updatePenaltyFieldVisibility;

function updateExpectedPaymentDisplay(){
    const expectedLabel = document.getElementById('sumExpectedLabel');
    const expectedValue = document.getElementById('sumExpectedPayment');
    const fmt = n => parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    
    if (!expectedLabel || !expectedValue) return;
    
    // Determine expected payment based on loan type
    let expectedPayment = 0;
    let paymentLabel = 'Expected Payment';
    
    if (window.currentLoanTypeId == 2) {
        // Business Loan - show weekly savings amount
        expectedPayment = window.currentWeeklySavingsAmount;
        paymentLabel = 'Weekly Payment';
    } else {
        // Normal/Asset Financing - show monthly installment
        expectedPayment = window.currentMonthlyInstallment;
        paymentLabel = 'Monthly Installment';
    }
    
    if (expectedPayment > 0) {
        expectedLabel.textContent = paymentLabel;
        expectedLabel.style.display = '';
        expectedValue.textContent = 'Shs ' + fmt(expectedPayment);
        expectedValue.style.display = '';
    } else {
        expectedLabel.style.display = 'none';
        expectedValue.style.display = 'none';
    }
}
window.updateExpectedPaymentDisplay = updateExpectedPaymentDisplay;

function updateSummary(){
    const amtEl = document.getElementById('amount_paid');
    const penaltyEl = document.getElementById('penalty_paid');
    const fmt = n => parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    const setText = (id,v) => { const e=document.getElementById(id); if(e) e.textContent=v; };
    
    const paying   = parseFloat(amtEl?.value)||0;
    const penalty  = Math.min(parseFloat(penaltyEl?.value)||0, window.currentOutstandingPenalty, paying);
    const nonPenaltyPaid = Math.max(0, paying - penalty);
    
    // Calculate interest and principal allocation (same logic as backend)
    const interestRemaining = Math.max(0, window.currentInterestAmount - window.currentInterestPaidTotal);
    const totalPayableOriginal = window.currentLoanAmount + window.currentInterestAmount;
    
    let interestPaid = 0.0;
    if (interestRemaining > 0.005 && totalPayableOriginal > 0 && nonPenaltyPaid > 0) {
        const interestRatio = window.currentInterestAmount / totalPayableOriginal;
        interestPaid = Math.min(
            Math.round(nonPenaltyPaid * interestRatio * 100) / 100,
            interestRemaining,
            nonPenaltyPaid
        );
    }
    const principal = Math.max(0, Math.round((nonPenaltyPaid - interestPaid) * 100) / 100);
    
    const after = Math.max(0, window.currentOutstanding - nonPenaltyPaid);
    
    setText('sumPaying', 'Shs ' + fmt(paying));
    
    // Show/hide penalty row based on whether loan is overdue and has penalty
    const showPenalty = (window.currentLoanStatus === 'overdue' && penalty > 0);
    const penaltyLabel = document.getElementById('sumPenaltyLabel');
    const penaltyValue = document.getElementById('sumPenaltyPortion');
    if (penaltyLabel) penaltyLabel.style.display = showPenalty ? '' : 'none';
    if (penaltyValue) {
        penaltyValue.style.display = showPenalty ? '' : 'none';
        penaltyValue.textContent = 'Shs ' + fmt(penalty);
    }
    
    setText('sumInterestPortion', 'Shs ' + fmt(interestPaid));
    setText('sumPrincipalPortion', 'Shs ' + fmt(principal));
    setText('sumAfter',  paying > 0 ? 'Shs ' + fmt(after) : '—');
    const hint = document.getElementById('maxHint');
    if(hint) hint.textContent = window.currentOutstanding > 0
        ? 'Max payable: Shs ' + fmt(window.currentOutstanding)
        : '';
}
window.updateSummary = updateSummary;

if(amtEl) amtEl.addEventListener('input', updateSummary);
if(penaltyEl) penaltyEl.addEventListener('input', updateSummary);
updatePenaltyFieldVisibility();
updateExpectedPaymentDisplay();
updateSummary();

// Member search autocomplete - fetches member's active loan automatically
const searchEl  = document.getElementById('memberSearch');
const dropdown  = document.getElementById('memberDropdown');
const loanCard  = document.getElementById('loanCard');
const loanContent = document.getElementById('loanCardContent');
let timer;

// Stage 13-F2: shared safe-templating helper (same pattern already used
// in app/views/loans/form.php) for interpolating untrusted loan/member
// data (loan_number, member_name) into HTML template literals below.
function escHtml(str){ const d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }

if(searchEl){
    searchEl.addEventListener('input', function(){
        clearTimeout(timer);
        const query = this.value.trim();
        console.log('🔍 Search input:', query, 'Length:', query.length);
        if(query.length < 2){ 
            if(dropdown) dropdown.style.display='none'; 
            return; 
        }
        timer = setTimeout(() => {
            const url = '<?=APP_URL?>/index.php?page=repayment-member-search&q='+encodeURIComponent(query);
            console.log('📡 Fetching:', url);
            fetch(url)
                .then(r => {
                    console.log('✅ Response status:', r.status);
                    if (!r.ok) {
                        console.error('❌ HTTP Error:', r.status, r.statusText);
                    }
                    return r.json();
                })
                .then(data => {
                    console.log('📦 Data received:', data);
                    if (data.error) {
                        console.error('❌ Backend error:', data.error);
                        dropdown.innerHTML = '<div class="list-group-item text-danger small">Error: ' + data.error + '</div>';
                        dropdown.style.display='block';
                        return;
                    }
                    dropdown.innerHTML = '';
                    if(!data.members?.length){ 
                        console.warn('⚠️ No members found with active loans for query:', query);
                        dropdown.innerHTML = '<div class="list-group-item text-muted small">No members with active loans found</div>';
                        dropdown.style.display='block';
                        return; 
                    }
                    console.log('👥 Found', data.members.length, 'members');
                    data.members.forEach(m => {
                        const a = document.createElement('a');
                        a.href='#'; a.className='list-group-item list-group-item-action py-2 px-3';
                        
                        let loanInfo = '';
                        if (m.active_loan) {
                            const badge = m.active_loan.status === 'overdue' 
                                ? '<span class="badge bg-danger ms-1 small">Overdue</span>' 
                                : '<span class="badge bg-success ms-1 small">Active</span>';
                            loanInfo = `<div class="text-muted" style="font-size:.72rem">
                                ${escHtml(m.active_loan.loan_number)} ${badge} — Outstanding: Shs ${parseFloat(m.active_loan.outstanding).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')}
                            </div>`;
                        } else {
                            loanInfo = '<div class="text-muted small">No active loan</div>';
                        }
                        
                        a.innerHTML = `<div class="fw-semibold small">${escHtml(m.member_name)} <span class="text-muted">(${escHtml(m.member_number)})</span></div>${loanInfo}`;
                        
                        a.addEventListener('click', e => { 
                            e.preventDefault(); 
                            if (m.active_loan) {
                                selectLoan(m.active_loan); 
                            }
                        });
                        dropdown.appendChild(a);
                    });
                    dropdown.style.display = 'block';
                    console.log('✅ Dropdown displayed with', data.members.length, 'members');
                }).catch(err => {
                    console.error('❌ Fetch error:', err);
                    dropdown.innerHTML = '<div class="list-group-item text-danger small">Network error - check console</div>';
                    dropdown.style.display='block';
                });
        }, 300);
    });
    document.addEventListener('click', e => { if(!searchEl.contains(e.target)) dropdown.style.display='none'; });
}

function selectLoan(l){
    const loanIdEl = document.getElementById('loanId');
    if(loanIdEl) loanIdEl.value = l.id;
    const memberHidden = document.getElementById('hiddenMemberId');
    if(memberHidden) memberHidden.value = l.member_id||0;
    const dropdown = document.getElementById('memberDropdown');
    if(dropdown) dropdown.style.display = 'none';
    
    window.currentOutstanding = parseFloat(l.outstanding)||0;
    window.currentOutstandingPenalty = parseFloat(l.outstanding_penalty)||0;
    window.currentInterestAmount = parseFloat(l.interest_amount)||0;
    window.currentInterestPaidTotal = parseFloat(l.interest_paid_total)||0;
    window.currentLoanAmount = parseFloat(l.loan_amount)||0;
    window.currentLoanStatus = l.status || '';
    window.currentMonthlyInstallment = parseFloat(l.monthly_installment)||0;
    window.currentWeeklySavingsAmount = parseFloat(l.weekly_savings_amount)||0;
    window.currentLoanTypeId = parseInt(l.loan_type_id)||1;
    
    if (window.updatePenaltyFieldVisibility) window.updatePenaltyFieldVisibility();
    if (window.updateExpectedPaymentDisplay) window.updateExpectedPaymentDisplay();

    // Populate card
    const loanContent = document.getElementById('loanCardContent');
    const loanCard = document.getElementById('loanCard');
    const searchEl = document.getElementById('memberSearch');
    
    function escHtml(str){ const d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }
    
    if(loanContent) loanContent.innerHTML =
        `<div class="row g-2 small">
            <div class="col-6"><span class="text-muted">Loan No.</span><br><strong>${escHtml(l.loan_number)}</strong></div>
            <div class="col-6"><span class="text-muted">Member</span><br><strong>${escHtml(l.member_name)}</strong></div>
            <div class="col-6"><span class="text-muted">Outstanding</span><br><strong class="text-danger">Shs ${parseFloat(l.outstanding).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')}</strong></div>
            <div class="col-6"><span class="text-muted">Status</span><br>
                <span class="badge ${l.status==='overdue'?'bg-danger':'bg-success'} rounded-pill">${escHtml(l.status)}</span>
            </div>
         </div>`;
    if(loanCard) loanCard.classList.remove('d-none');
    if(searchEl) searchEl.value = '';

    // Update summary
    const fmt = n => parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    const setText = (id,v) => { const e=document.getElementById(id); if(e) e.textContent=v; };
    
    setText('sumLoanNo', l.loan_number);
    setText('sumMember',  l.member_name);
    setText('sumLoanAmt', 'Shs ' + fmt(l.loan_amount));
    setText('sumTotal', 'Shs ' + fmt(l.total_payable));
    setText('sumOutstanding', 'Shs ' + fmt(l.outstanding));

    // Update payment type label based on loan's repayment frequency
    const payTypeCard = document.getElementById('paymentTypeCard');
    const payTypeHidden = document.getElementById('paymentTypeHidden');
    const autoDetectedLabel = document.getElementById('autoDetectedLabel');
    const autoDetectedHint = document.getElementById('autoDetectedHint');
    
    if (payTypeCard) {
        payTypeCard.style.display = 'block';
    }
    
    if (payTypeHidden) {
        payTypeHidden.value = 'installment';
    }
    
    if (autoDetectedLabel) {
        let label = 'Regular Installment Payment';
        const freq = l.repayment_frequency || 'monthly';
        
        if (freq === 'weekly') {
            label = 'Weekly Installment Payment';
        } else if (freq === 'monthly') {
            label = 'Monthly Installment Payment';
        }
        
        autoDetectedLabel.textContent = label;
    }
    
    if (autoDetectedHint) {
        autoDetectedHint.textContent = 'This payment will reduce your loan balance according to the repayment schedule (principal + interest).';
    }

    if (window.updateSummary) window.updateSummary();
}

document.getElementById('clearLoan')?.addEventListener('click', () => {
    const loanIdEl = document.getElementById('loanId');
    const loanCardEl = document.getElementById('loanCard');
    const loanContentEl = document.getElementById('loanCardContent');
    const searchEl = document.getElementById('memberSearch');
    
    if(loanIdEl) loanIdEl.value = '0';
    if(loanCardEl){ 
        loanCardEl.classList.add('d-none'); 
        if(loanContentEl) loanContentEl.innerHTML=''; 
    }
    if(searchEl) searchEl.value = '';
    
    window.currentOutstanding = 0;
    window.currentOutstandingPenalty = 0;
    window.currentInterestAmount = 0;
    window.currentInterestPaidTotal = 0;
    window.currentLoanAmount = 0;
    window.currentLoanStatus = '';
    window.currentMonthlyInstallment = 0;
    window.currentWeeklySavingsAmount = 0;
    window.currentLoanTypeId = 1;
    
    if(window.updatePenaltyFieldVisibility) window.updatePenaltyFieldVisibility();
    if(window.updateExpectedPaymentDisplay) window.updateExpectedPaymentDisplay();
    
    const setText = (id,v) => { const e=document.getElementById(id); if(e) e.textContent=v; };
    setText('sumLoanNo','—'); 
    setText('sumMember','—');
    setText('sumLoanAmt','—');
    setText('sumTotal','—');
    setText('sumOutstanding','—'); 
    setText('sumPaying','Shs 0.00'); 
    setText('sumAfter','—');
    
    if(window.updateSummary) window.updateSummary();
});

// Submit
const form = document.getElementById('repayForm');
const btn  = document.getElementById('submitBtn');
form.addEventListener('submit', function(e){
    const lid = parseInt(document.getElementById('loanId')?.value||0);
    if(!form.checkValidity() || lid < 1){
        e.preventDefault(); e.stopPropagation();
        const first = form.querySelector(':invalid');
        if(first) first.scrollIntoView({behavior:'smooth',block:'center'});
    } else {
        btn.disabled=true;
        btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    }
    form.classList.add('was-validated');
});

// Payment type change handler
const payTypeSelect = document.getElementById('paymentType');
const weekGroup = document.getElementById('weekCoveredGroup');
const payHint = document.getElementById('paymentTypeHint');
const showAdvancedCheckbox = document.getElementById('showAdvancedPaymentType');
const advancedPaymentGroup = document.getElementById('advancedPaymentTypeGroup');

// Toggle advanced payment options
if (showAdvancedCheckbox && advancedPaymentGroup) {
    showAdvancedCheckbox.addEventListener('change', function() {
        advancedPaymentGroup.style.display = this.checked ? '' : 'none';
        // Reset to installment when hiding advanced options
        if (!this.checked) {
            const payTypeHidden = document.getElementById('paymentTypeHidden');
            if (payTypeHidden) payTypeHidden.value = 'installment';
        }
    });
}

if (payTypeSelect) {
    payTypeSelect.addEventListener('change', function() {
        const val = this.value;
        
        // Update the hidden field that actually gets submitted
        const payTypeHidden = document.getElementById('paymentTypeHidden');
        if (payTypeHidden) {
            payTypeHidden.value = val;
        }
        
        // Show week field only for weekly_savings
        if (weekGroup) weekGroup.style.display = val === 'weekly_savings' ? '' : 'none';
        
        // Update hint
        const hints = {
            'installment': 'Standard installment payment — reduces the outstanding loan balance (principal + interest).',
            'interest': 'Monthly interest payment — does NOT reduce the principal balance.',
            'weekly_savings': 'Weekly Interest Payment — tracked separately from the loan.',
            'principal': 'Principal repayment — directly reduces the outstanding loan balance.',
            'settlement': 'Full settlement — pays off the entire remaining balance.'
        };
        if (payHint) payHint.textContent = hints[val] || '';

        // Penalty collection only applies to installment/settlement payments
        const penaltyGroup = document.getElementById('penaltyGroup');
        const penaltyEl = document.getElementById('penalty_paid');
        const penaltyApplies = (val === 'installment' || val === 'settlement');
        if (penaltyGroup) {
            // Only show penalty if: payment type allows it AND loan is overdue AND has outstanding penalty
            const shouldShow = penaltyApplies && window.currentLoanStatus === 'overdue' && window.currentOutstandingPenalty > 0;
            penaltyGroup.style.display = shouldShow ? '' : 'none';
            if (!shouldShow && penaltyEl) penaltyEl.value = '0';
        }
    });
}

})();

// Show payment type card on page load if loan is pre-selected
<?php if ($loanData): ?>
(function(){
    const payTypeCard = document.getElementById('paymentTypeCard');
    const payTypeSelect = document.getElementById('paymentType');
    if (payTypeCard) {
        payTypeCard.style.display = 'block';
        const loanTypeId = <?= (int)($loanData['loan_type_id'] ?? 1) ?>;
        if (loanTypeId == 2) {
            payTypeSelect.innerHTML = `
                <option value="interest">Monthly Interest Payment</option>
                <option value="weekly_savings">Weekly Interest Payment</option>
                <option value="principal">Principal Repayment</option>
                <option value="settlement">Full Settlement</option>
            `;
        } else {
            payTypeSelect.innerHTML = `
                <option value="installment">Installment Payment</option>
                <option value="settlement">Full Settlement</option>
            `;
        }
        payTypeSelect.dispatchEvent(new Event('change'));
    }
})();
<?php endif; ?>

</script>
