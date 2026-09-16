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

    <!-- Loan Search -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-bank2" style="color:var(--brand-orange)"></i>
            <h6 class="mb-0 fw-semibold">Select Loan</h6>
        </div>
        <div class="card-body p-4">
            <input type="hidden" name="loan_id" id="loanId" value="<?= (int)($repayment['loan_id'] ?? $loanData['id'] ?? 0) ?>">

            <?php if ($loanData): ?>
            <!-- Pre-selected loan -->
            <div id="loanCard" class="p-3 rounded-3 bg-light border">
                <?php include __DIR__ . '/partials/loan-card.php'; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clearLoan">
                    <i class="bi bi-x me-1"></i>Change Loan
                </button>
            </div>
            <?php else: ?>
            <label class="form-label fw-semibold" for="loanSearch">Search Loan <span class="text-danger">*</span></label>
            <div class="input-group mb-1">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="loanSearch" class="form-control<?= $cls('loan_id') ?>"
                       placeholder="Member name, loan number..." autocomplete="off">
            </div>
            <?php if ($err('loan_id')): ?><div class="text-danger small mb-2"><?= htmlspecialchars($err('loan_id')) ?></div><?php endif; ?>
            <div id="loanDropdown" class="list-group shadow"
                 style="position:absolute;z-index:1050;width:100%;max-width:480px;display:none;max-height:260px;overflow-y:auto;"></div>
            <div id="loanCard" class="d-none p-3 rounded-3 bg-light border mt-2">
                <div id="loanCardContent"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clearLoan">
                    <i class="bi bi-x me-1"></i>Change Loan
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Type (for Business Loans) -->
    <div class="card mb-4" id="paymentTypeCard" style="display:none;">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-tag" style="color:var(--brand-orange)"></i>
            <h6 class="mb-0 fw-semibold">Payment Type</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">What are you paying? <span class="text-danger">*</span></label>
                    <select name="payment_type" id="paymentType" class="form-select">
                        <option value="installment">Installment Payment</option>
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
                <dt class="col-7 text-muted" id="sumExpectedLabel" style="display:none;">Expected Payment</dt>
                <dd class="col-5 fw-semibold" style="color:var(--brand-orange)" id="sumExpectedPayment">—</dd>
                <dt class="col-7 text-muted">Outstanding</dt>
                <dd class="col-5 fw-bold text-danger" id="sumOutstanding"><?= $loanData ? 'Shs '.number_format($loanData['outstanding'],2) : '—' ?></dd>
                <dt class="col-7 text-muted" id="sumOutstandingPenaltyLabel" style="display:none;">Outstanding Penalty</dt>
                <dd class="col-5 fw-bold text-warning" id="sumOutstandingPenalty"><?= ($outstandingPenalty ?? 0) > 0 ? 'Shs '.number_format($outstandingPenalty,2) : 'Shs 0.00' ?></dd>
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

let currentOutstanding = <?= $loanData ? (float)$loanData['outstanding'] : 0 ?>;
let currentOutstandingPenalty = <?= (float)($outstandingPenalty ?? 0) ?>;
let currentInterestAmount = <?= $loanData ? (float)$loanData['interest_amount'] : 0 ?>;
let currentInterestPaidTotal = <?= $loanData ? (float)($loanData['interest_paid_total'] ?? 0) : 0 ?>;
let currentLoanAmount = <?= $loanData ? (float)$loanData['loan_amount'] : 0 ?>;
let currentLoanStatus = '<?= $loanData['status'] ?? '' ?>';
let currentMonthlyInstallment = <?= $loanData ? (float)($loanData['monthly_installment'] ?? 0) : 0 ?>;
let currentWeeklySavingsAmount = <?= $loanData ? (float)($loanData['weekly_savings_amount'] ?? 0) : 0 ?>;
let currentLoanTypeId = <?= $loanData ? (int)($loanData['loan_type_id'] ?? 1) : 1 ?>;

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
    if (!group) return;
    // Only show penalty field if loan is overdue AND has outstanding penalty
    const shouldShowPenalty = (currentLoanStatus === 'overdue' && currentOutstandingPenalty > 0);
    group.style.display = shouldShowPenalty ? '' : 'none';
    if (penaltyEl) penaltyEl.max = currentOutstandingPenalty.toFixed(2);
    
    // Update summary labels visibility
    const penaltyLabel = document.getElementById('sumOutstandingPenaltyLabel');
    if (penaltyLabel) penaltyLabel.style.display = shouldShowPenalty ? '' : 'none';
    const penaltyValue = document.getElementById('sumOutstandingPenalty');
    if (penaltyValue) penaltyValue.style.display = shouldShowPenalty ? '' : 'none';
    
    setText('sumOutstandingPenalty', 'Shs ' + fmt(currentOutstandingPenalty));
    const hint = document.getElementById('penaltyHint');
    if (hint) hint.textContent = currentOutstandingPenalty > 0
        ? 'Max collectible now: Shs ' + fmt(currentOutstandingPenalty)
        : '';
    if (!shouldShowPenalty && penaltyEl) penaltyEl.value = '0';
}

function updateExpectedPaymentDisplay(){
    const expectedLabel = document.getElementById('sumExpectedLabel');
    const expectedValue = document.getElementById('sumExpectedPayment');
    
    if (!expectedLabel || !expectedValue) return;
    
    // Determine expected payment based on loan type
    let expectedPayment = 0;
    let paymentLabel = 'Expected Payment';
    
    if (currentLoanTypeId == 2) {
        // Business Loan - show weekly savings amount
        expectedPayment = currentWeeklySavingsAmount;
        paymentLabel = 'Weekly Payment';
    } else {
        // Normal/Asset Financing - show monthly installment
        expectedPayment = currentMonthlyInstallment;
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

function updateSummary(){
    const paying   = parseFloat(amtEl?.value)||0;
    const penalty  = Math.min(parseFloat(penaltyEl?.value)||0, currentOutstandingPenalty, paying);
    const nonPenaltyPaid = Math.max(0, paying - penalty);
    
    // Calculate interest and principal allocation (same logic as backend)
    const interestRemaining = Math.max(0, currentInterestAmount - currentInterestPaidTotal);
    const totalPayableOriginal = currentLoanAmount + currentInterestAmount;
    
    let interestPaid = 0.0;
    if (interestRemaining > 0.005 && totalPayableOriginal > 0 && nonPenaltyPaid > 0) {
        const interestRatio = currentInterestAmount / totalPayableOriginal;
        interestPaid = Math.min(
            Math.round(nonPenaltyPaid * interestRatio * 100) / 100,
            interestRemaining,
            nonPenaltyPaid
        );
    }
    const principal = Math.max(0, Math.round((nonPenaltyPaid - interestPaid) * 100) / 100);
    
    const after = Math.max(0, currentOutstanding - nonPenaltyPaid);
    
    setText('sumPaying', 'Shs ' + fmt(paying));
    
    // Show/hide penalty row based on whether loan is overdue and has penalty
    const showPenalty = (currentLoanStatus === 'overdue' && penalty > 0);
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
    if(hint) hint.textContent = currentOutstanding > 0
        ? 'Max payable: Shs ' + fmt(currentOutstanding)
        : '';
}

if(amtEl) amtEl.addEventListener('input', updateSummary);
if(penaltyEl) penaltyEl.addEventListener('input', updateSummary);
updatePenaltyFieldVisibility();
updateExpectedPaymentDisplay();
updateSummary();

// Loan search autocomplete
const searchEl  = document.getElementById('loanSearch');
const dropdown  = document.getElementById('loanDropdown');
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
        if(this.value.trim().length < 2){ if(dropdown) dropdown.style.display='none'; return; }
        timer = setTimeout(() => {
            fetch('<?=APP_URL?>/index.php?page=repayment-loan-search&q='+encodeURIComponent(this.value.trim()))
                .then(r => r.json())
                .then(data => {
                    dropdown.innerHTML = '';
                    if(!data.loans?.length){ dropdown.style.display='none'; return; }
                    data.loans.forEach(l => {
                        const a = document.createElement('a');
                        a.href='#'; a.className='list-group-item list-group-item-action py-2 px-3';
                        const badge = l.status==='overdue' ? '<span class="badge bg-danger ms-1 small">Overdue</span>' : '<span class="badge bg-success ms-1 small">Active</span>';
                        a.innerHTML = `<div class="fw-semibold small">${escHtml(l.loan_number)} ${badge}</div>
                                       <div class="text-muted" style="font-size:.75rem">${escHtml(l.member_name)} — Outstanding: Shs ${parseFloat(l.outstanding).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')}</div>`;
                        a.addEventListener('click', e => { e.preventDefault(); selectLoan(l); });
                        dropdown.appendChild(a);
                    });
                    dropdown.style.display = 'block';
                }).catch(()=>{});
        }, 300);
    });
    document.addEventListener('click', e => { if(!searchEl.contains(e.target)) dropdown.style.display='none'; });
}

function selectLoan(l){
    if(loanId)      loanId.value     = l.id;
    const memberHidden = document.getElementById('hiddenMemberId');
    if(memberHidden) memberHidden.value = l.member_id||0;
    if(dropdown)    dropdown.style.display = 'none';
    currentOutstanding = parseFloat(l.outstanding)||0;
    currentOutstandingPenalty = parseFloat(l.outstanding_penalty)||0;
    currentInterestAmount = parseFloat(l.interest_amount)||0;
    currentInterestPaidTotal = parseFloat(l.interest_paid_total)||0;
    currentLoanAmount = parseFloat(l.loan_amount)||0;
    currentLoanStatus = l.status || '';
    currentMonthlyInstallment = parseFloat(l.monthly_installment)||0;
    currentWeeklySavingsAmount = parseFloat(l.weekly_savings_amount)||0;
    currentLoanTypeId = parseInt(l.loan_type_id)||1;
    updatePenaltyFieldVisibility();
    updateExpectedPaymentDisplay();

    // Populate card
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
    setText('sumLoanNo', l.loan_number);
    setText('sumMember',  l.member_name);
    setText('sumOutstanding', 'Shs ' + fmt(l.outstanding));

    // Show payment type card for Business Loans (loan_type_id == 2)
    const payTypeCard = document.getElementById('paymentTypeCard');
    const payTypeSelect = document.getElementById('paymentType');
    if (payTypeCard) {
        // Always show it so user can choose payment type
        payTypeCard.style.display = 'block';

        // Reset options based on loan type
        if (l.loan_type_id == 2) {
            // Business Loan: show all options
            payTypeSelect.innerHTML = `
                <option value="interest">Monthly Interest Payment</option>
                <option value="weekly_savings">Weekly Interest Payment</option>
                <option value="principal">Principal Repayment</option>
                <option value="settlement">Full Settlement</option>
            `;
        } else {
            // Normal / Asset Financing
            payTypeSelect.innerHTML = `
                <option value="installment">Installment Payment</option>
                <option value="settlement">Full Settlement</option>
            `;
        }
        payTypeSelect.dispatchEvent(new Event('change'));
    }

    updateSummary();
}

document.getElementById('clearLoan')?.addEventListener('click', () => {
    if(loanId) loanId.value = '0';
    if(loanCard){ loanCard.classList.add('d-none'); if(loanContent) loanContent.innerHTML=''; }
    if(searchEl) searchEl.value = '';
    currentOutstanding = 0;
    currentOutstandingPenalty = 0;
    currentInterestAmount = 0;
    currentInterestPaidTotal = 0;
    currentLoanAmount = 0;
    currentLoanStatus = '';
    currentMonthlyInstallment = 0;
    currentWeeklySavingsAmount = 0;
    currentLoanTypeId = 1;
    updatePenaltyFieldVisibility();
    updateExpectedPaymentDisplay();
    setText('sumLoanNo','—'); setText('sumMember','—');
    setText('sumOutstanding','—'); setText('sumPaying','Shs 0.00'); setText('sumAfter','—');
    updateSummary();
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
if (payTypeSelect) {
    payTypeSelect.addEventListener('change', function() {
        const val = this.value;
        // Show week field only for weekly_savings
        if (weekGroup) weekGroup.style.display = val === 'weekly_savings' ? '' : 'none';
        // Update hint
        const hints = {
            'installment': 'Standard installment payment — reduces the outstanding loan balance.',
            'interest': 'Monthly interest payment — does NOT reduce the principal balance.',
            'weekly_savings': 'Weekly Interest Payment — tracked separately from the loan.',
            'principal': 'Principal repayment — directly reduces the outstanding loan balance.',
            'settlement': 'Full settlement — pays off the entire remaining balance.'
        };
        if (payHint) payHint.textContent = hints[val] || '';

        // Penalty collection only applies to installment/settlement payments
        // (Stage 17 Part C) -- the other payment types are Business-Loan-
        // specific flows this stage does not extend penalty handling into.
        const penaltyGroup = document.getElementById('penaltyGroup');
        const penaltyApplies = (val === 'installment' || val === 'settlement');
        if (penaltyGroup) {
            // Only show penalty if: payment type allows it AND loan is overdue AND has outstanding penalty
            penaltyGroup.style.display = (penaltyApplies && currentLoanStatus === 'overdue' && currentOutstandingPenalty > 0) ? '' : 'none';
        }
        if (!penaltyApplies && penaltyEl) penaltyEl.value = '0';
        updateSummary();
    });
}

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

})();
</script>
