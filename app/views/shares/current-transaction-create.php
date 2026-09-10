<?php
$base = APP_URL . '/index.php';
$old = $oldInput ?? [];
$paymentMethods = ['Cash', 'Airtel Money', 'MTN Mobile Money', 'Bank Transfer', 'Cheque', 'Other'];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-receipt me-2 text-success"></i>Record Share Transaction
        </h1>
        <p class="text-muted mb-0 small">Record a share contribution the club has already received and confirmed outside Empower</p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <a href="<?= $base ?>?page=shares" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Shares
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($duplicateWarning)): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
    <i class="bi bi-exclamation-diamond-fill flex-shrink-0 mt-1"></i>
    <div>
        <div class="fw-semibold"><?= htmlspecialchars($duplicateWarning) ?></div>
        <div class="form-check mt-2">
            <input type="checkbox" class="form-check-input" id="confirmDuplicateCheckbox">
            <label class="form-check-label small" for="confirmDuplicateCheckbox">Yes, this is a genuinely separate transaction — add it anyway</label>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($externalDuplicateWarning)): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
    <i class="bi bi-exclamation-diamond-fill flex-shrink-0 mt-1"></i>
    <div>
        <div class="fw-semibold"><?= htmlspecialchars($externalDuplicateWarning) ?></div>
        <div class="form-check mt-2">
            <input type="checkbox" class="form-check-input" id="confirmExternalDuplicateCheckbox">
            <label class="form-check-label small" for="confirmExternalDuplicateCheckbox">Yes, this external reference is correct — add it anyway</label>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7 col-md-9">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2" style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
                <i class="bi bi-journal-plus"></i>
                <h6 class="mb-0 fw-semibold">Share Transaction</h6>
            </div>
            <div class="card-body p-4">
                <form id="ctForm" method="POST" action="<?= $base ?>?page=share-transaction-store">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="confirm_duplicate" id="confirmDuplicateField" value="0">
                    <input type="hidden" name="confirm_external_duplicate" id="confirmExternalDuplicateField" value="0">

                    <!-- Member Search -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="memberSearch">
                            <i class="bi bi-person-circle me-1"></i>Member <span class="text-danger">*</span>
                        </label>
                        <input type="hidden" name="member_id" id="memberId" value="<?= (int)($old['member_id'] ?? 0) ?>">
                        <div class="position-relative">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="memberSearch" class="form-control" placeholder="Type name, member number or phone…" autocomplete="off">
                            </div>
                            <div id="memberDropdown" class="list-group shadow mt-1" style="position:absolute;z-index:1050;width:100%;display:none;max-height:260px;overflow-y:auto;"></div>
                        </div>
                        <div id="selectedMember" class="d-none mt-2 p-3 bg-light rounded-3 d-flex align-items-center gap-3">
                            <div class="member-avatar-sm bg-blue" id="memberInitial">?</div>
                            <div>
                                <div class="fw-semibold" id="memberName"></div>
                                <div class="text-muted small" id="memberInfo"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="clearMember"><i class="bi bi-x"></i></button>
                        </div>
                    </div>

                    <!-- Transaction Date -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="transactionDate"><i class="bi bi-calendar-event me-1"></i>Transaction Date <span class="text-danger">*</span></label>
                        <input type="date" name="transaction_date" id="transactionDate" class="form-control form-control-lg"
                               max="<?= htmlspecialchars($today) ?>" value="<?= htmlspecialchars($old['transaction_date'] ?? $today) ?>" required>
                        <div class="form-text"><i class="bi bi-info-circle me-1"></i>The date the club actually received this contribution — must be today or an earlier date.</div>
                    </div>

                    <!-- Amount Received -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="amount"><i class="bi bi-cash-stack me-1"></i>Amount Received (UGX) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="amount" class="form-control form-control-lg" min="1" step="0.01" value="<?= htmlspecialchars($old['amount'] ?? '') ?>">
                        <div class="form-text"><i class="bi bi-info-circle me-1"></i>Enter the actual amount the club already received. The system calculates the shares.</div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label text-muted small">Share Value</label>
                            <div class="form-control-plaintext fw-semibold">Shs <?= number_format($shareValue, 2) ?></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-muted small">Calculated Shares (preview)</label>
                            <div class="form-control-plaintext fw-bold text-success" id="calcQty">0.0000</div>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="paymentMethod"><i class="bi bi-wallet2 me-1"></i>Payment Method <span class="text-danger">*</span></label>
                        <select name="payment_method" id="paymentMethod" class="form-select form-select-lg">
                            <option value="">-- Select how the contribution was received --</option>
                            <?php foreach ($paymentMethods as $pm): ?>
                            <option value="<?= htmlspecialchars($pm) ?>" <?= ($old['payment_method'] ?? '') === $pm ? 'selected' : '' ?>><?= htmlspecialchars($pm) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><i class="bi bi-info-circle me-1"></i>This records how the already-completed contribution was settled — it does not initiate or collect any payment.</div>
                    </div>

                    <!-- External Reference -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="externalReference"><i class="bi bi-link-45deg me-1"></i>External Reference <span class="text-muted">(optional)</span></label>
                        <input type="text" name="external_reference" id="externalReference" class="form-control" maxlength="100"
                               placeholder="Mobile Money transaction ID, bank reference, cheque number…" value="<?= htmlspecialchars($old['external_reference'] ?? '') ?>">
                    </div>

                    <!-- Confirmation summary (shown before the real submit) -->
                    <div id="confirmBox" class="d-none alert alert-light border mb-4">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-clipboard-check me-1"></i>Confirm Share Transaction</h6>
                        <dl class="row mb-0 small">
                            <dt class="col-4">Member</dt><dd class="col-8" id="cfMember"></dd>
                            <dt class="col-4">Transaction Date</dt><dd class="col-8" id="cfDate"></dd>
                            <dt class="col-4">Amount Received</dt><dd class="col-8" id="cfAmount"></dd>
                            <dt class="col-4">Share Value</dt><dd class="col-8" id="cfShareValue"></dd>
                            <dt class="col-4">Shares</dt><dd class="col-8" id="cfShares"></dd>
                            <dt class="col-4">Payment Method</dt><dd class="col-8" id="cfPaymentMethod"></dd>
                            <dt class="col-4">External Reference</dt><dd class="col-8" id="cfExternalRef"></dd>
                        </dl>
                        <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i>The internal reference number is generated after you confirm.</div>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="button" id="reviewBtn" class="btn btn-lg fw-semibold px-5" style="background:var(--brand-navy);color:#fff;" disabled>
                            <i class="bi bi-eye me-2"></i>Review
                        </button>
                        <button type="submit" id="confirmBtn" class="btn btn-lg btn-success fw-semibold px-5 d-none">
                            <i class="bi bi-check-circle me-2"></i>Confirm Entry
                        </button>
                        <button type="button" id="cancelReviewBtn" class="btn btn-lg btn-outline-secondary d-none">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';
function escHtml(str){ const d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }
const base = '<?= APP_URL ?>/index.php';
const SHARE_VALUE = <?= json_encode($shareValue) ?>;

const memberIdEl = document.getElementById('memberId');
const searchEl = document.getElementById('memberSearch');
const dropdown = document.getElementById('memberDropdown');
const selCard = document.getElementById('selectedMember');
let selectedMemberName = '';
let timer;

if (searchEl) {
    searchEl.addEventListener('input', function(){
        clearTimeout(timer);
        if (this.value.trim().length < 2) { dropdown.style.display = 'none'; return; }
        timer = setTimeout(() => {
            fetch(base + '?page=share-member-search&q=' + encodeURIComponent(this.value.trim()))
                .then(r => r.json()).then(data => {
                    dropdown.innerHTML = '';
                    if (!data.members?.length) { dropdown.style.display = 'none'; return; }
                    data.members.forEach(m => {
                        const a = document.createElement('a');
                        a.href = '#'; a.className = 'list-group-item list-group-item-action py-2 px-3';
                        a.innerHTML = `<div class="fw-semibold small">${escHtml(m.full_name)}</div>
                                       <div class="text-muted" style="font-size:.75rem">${escHtml(m.member_number)} · ${escHtml(m.phone)}</div>`;
                        a.addEventListener('click', e => { e.preventDefault(); selectMember(m); });
                        dropdown.appendChild(a);
                    });
                    dropdown.style.display = 'block';
                }).catch(() => {});
        }, 300);
    });
    document.addEventListener('click', e => { if (!searchEl.contains(e.target)) dropdown.style.display = 'none'; });
}

function selectMember(m){
    memberIdEl.value = m.id;
    selectedMemberName = m.full_name + ' (' + m.member_number + ')';
    searchEl.value = selectedMemberName;
    dropdown.style.display = 'none';
    document.getElementById('memberInitial').textContent = m.full_name.charAt(0).toUpperCase();
    document.getElementById('memberName').textContent = m.full_name;
    document.getElementById('memberInfo').textContent = m.member_number + ' · ' + m.phone;
    selCard.classList.remove('d-none');
    syncReviewButton();
}
document.getElementById('clearMember')?.addEventListener('click', () => {
    memberIdEl.value = '0'; searchEl.value = ''; selectedMemberName = '';
    selCard.classList.add('d-none'); syncReviewButton();
});

const amountEl = document.getElementById('amount');
const calcQty = document.getElementById('calcQty');
const dateEl = document.getElementById('transactionDate');
const paymentMethodEl = document.getElementById('paymentMethod');
const externalRefEl = document.getElementById('externalReference');
const reviewBtn = document.getElementById('reviewBtn');
const confirmBtn = document.getElementById('confirmBtn');
const cancelReviewBtn = document.getElementById('cancelReviewBtn');
const confirmBox = document.getElementById('confirmBox');
const ctForm = document.getElementById('ctForm');

// Preview only -- the server independently recalculates from the amount
// and the current settings.share_value at submission time; this client-
// side figure is never sent as an authoritative value.
function recalc(){
    const amt = parseFloat(amountEl.value);
    const qty = (amt > 0 && SHARE_VALUE > 0) ? (amt / SHARE_VALUE) : 0;
    calcQty.textContent = qty.toFixed(4);
    syncReviewButton();
}
amountEl.addEventListener('input', recalc);
dateEl.addEventListener('change', syncReviewButton);
paymentMethodEl.addEventListener('change', syncReviewButton);

function syncReviewButton(){
    const hasMember = parseInt(memberIdEl.value, 10) > 0;
    const hasDate = !!dateEl.value;
    const hasAmount = parseFloat(amountEl.value) > 0;
    const hasMethod = !!paymentMethodEl.value;
    reviewBtn.disabled = !(hasMember && hasDate && hasAmount && hasMethod);
}

reviewBtn.addEventListener('click', function(){
    const amt = parseFloat(amountEl.value) || 0;
    const qty = SHARE_VALUE > 0 ? (amt / SHARE_VALUE) : 0;
    document.getElementById('cfMember').textContent = selectedMemberName || searchEl.value;
    document.getElementById('cfDate').textContent = dateEl.value;
    document.getElementById('cfAmount').textContent = 'Shs ' + amt.toLocaleString(undefined, {minimumFractionDigits:2});
    document.getElementById('cfShareValue').textContent = 'Shs ' + SHARE_VALUE.toLocaleString(undefined, {minimumFractionDigits:2});
    document.getElementById('cfShares').textContent = qty.toFixed(4);
    document.getElementById('cfPaymentMethod').textContent = paymentMethodEl.value;
    document.getElementById('cfExternalRef').textContent = externalRefEl.value.trim() || '—';
    confirmBox.classList.remove('d-none');
    reviewBtn.classList.add('d-none');
    confirmBtn.classList.remove('d-none');
    cancelReviewBtn.classList.remove('d-none');
});
cancelReviewBtn.addEventListener('click', function(){
    confirmBox.classList.add('d-none');
    reviewBtn.classList.remove('d-none');
    confirmBtn.classList.add('d-none');
    cancelReviewBtn.classList.add('d-none');
});

document.getElementById('confirmDuplicateCheckbox')?.addEventListener('change', function(){
    document.getElementById('confirmDuplicateField').value = this.checked ? '1' : '0';
});
document.getElementById('confirmExternalDuplicateCheckbox')?.addEventListener('change', function(){
    document.getElementById('confirmExternalDuplicateField').value = this.checked ? '1' : '0';
});

ctForm.addEventListener('submit', function(e){
    if (parseInt(memberIdEl.value, 10) < 1) { e.preventDefault(); alert('Please select a member.'); return; }
    if (!dateEl.value) { e.preventDefault(); alert('Please choose a transaction date.'); return; }
    if (!paymentMethodEl.value) { e.preventDefault(); alert('Please select a payment method.'); return; }
});

recalc();
<?php if (!empty($old['member_id'])): ?>
searchEl.value = <?= json_encode(($old['member_id'] ?? '') ? 'Selected member #' . (int)$old['member_id'] : '') ?>;
<?php endif; ?>
})();
</script>
