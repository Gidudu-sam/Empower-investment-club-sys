<?php
$base = APP_URL . '/index.php';
$old = $oldInput ?? [];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-clock-history me-2 text-success"></i>Record Historical Shares
        </h1>
        <p class="text-muted mb-0 small">Enter a member's pre-existing share position — retained savings or previously bought shares</p>
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
            <label class="form-check-label small" for="confirmDuplicateCheckbox">Yes, this is a genuinely separate entry — add it anyway</label>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7 col-md-9">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2" style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
                <i class="bi bi-journal-plus"></i>
                <h6 class="mb-0 fw-semibold">Historical Share Entry</h6>
            </div>
            <div class="card-body p-4">
                <form id="histForm" method="POST" action="<?= $base ?>?page=share-historical-store">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="confirm_duplicate" id="confirmDuplicateField" value="0">

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

                    <!-- Share Source -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><i class="bi bi-diagram-2 me-1"></i>Share Source <span class="text-danger">*</span></label>
                        <div class="btn-group w-100 filter-pills" role="group">
                            <input type="radio" class="btn-check" name="source" id="srcRetained" value="retained" <?= ($old['source'] ?? '') === 'purchase' ? '' : 'checked' ?>>
                            <label class="btn" for="srcRetained">Retained Savings</label>
                            <input type="radio" class="btn-check" name="source" id="srcPurchase" value="purchase" <?= ($old['source'] ?? '') === 'purchase' ? 'checked' : '' ?>>
                            <label class="btn" for="srcPurchase">Bought Shares</label>
                        </div>
                    </div>

                    <!-- Historical Date -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="transactionDate"><i class="bi bi-calendar-event me-1"></i>Historical Date <span class="text-danger">*</span></label>
                        <input type="date" name="transaction_date" id="transactionDate" class="form-control form-control-lg"
                               max="<?= htmlspecialchars($today) ?>" value="<?= htmlspecialchars($old['transaction_date'] ?? '') ?>" required>
                        <div class="form-text"><i class="bi bi-info-circle me-1"></i>Must be today or an earlier date — this records a pre-existing position, not a new transaction.</div>
                    </div>

                    <!-- Retained Savings fields -->
                    <div id="retainedFields">
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="retainedAmount"><i class="bi bi-cash me-1"></i>Retained Amount (UGX) <span class="text-danger">*</span></label>
                            <input type="number" name="retained_amount" id="retainedAmount" class="form-control form-control-lg" min="1" step="0.01" value="<?= htmlspecialchars($old['retained_amount'] ?? '') ?>">
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="form-label text-muted small">Share Value</label>
                                <div class="form-control-plaintext fw-semibold">Shs <?= number_format($shareValue, 2) ?></div>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">Calculated Shares</label>
                                <div class="form-control-plaintext fw-bold text-success" id="calcRetainedQty">0.0000</div>
                            </div>
                        </div>
                    </div>

                    <!-- Bought Shares fields -->
                    <div id="purchaseFields" class="d-none">
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="quantity"><i class="bi bi-hash me-1"></i>Number of Shares (whole numbers only) <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" id="quantity" class="form-control form-control-lg" min="1" step="1" value="<?= htmlspecialchars($old['quantity'] ?? '') ?>">
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="form-label text-muted small">Share Value</label>
                                <div class="form-control-plaintext fw-semibold">Shs <?= number_format($shareValue, 2) ?></div>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">Calculated Amount</label>
                                <div class="form-control-plaintext fw-bold text-success" id="calcPurchaseAmount">Shs 0.00</div>
                            </div>
                        </div>
                    </div>

                    <!-- Confirmation summary (shown before the real submit) -->
                    <div id="confirmBox" class="d-none alert alert-light border mb-4">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-clipboard-check me-1"></i>Confirm Historical Share Entry</h6>
                        <dl class="row mb-0 small">
                            <dt class="col-4">Member</dt><dd class="col-8" id="cfMember"></dd>
                            <dt class="col-4">Source</dt><dd class="col-8" id="cfSource"></dd>
                            <dt class="col-4">Historical Date</dt><dd class="col-8" id="cfDate"></dd>
                            <dt class="col-4" id="cfAmountLabel">Retained Amount</dt><dd class="col-8" id="cfAmount"></dd>
                            <dt class="col-4">Share Value</dt><dd class="col-8" id="cfShareValue"></dd>
                            <dt class="col-4">Shares</dt><dd class="col-8" id="cfShares"></dd>
                        </dl>
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

const srcRetained = document.getElementById('srcRetained');
const srcPurchase = document.getElementById('srcPurchase');
const retainedFields = document.getElementById('retainedFields');
const purchaseFields = document.getElementById('purchaseFields');
const retainedAmountEl = document.getElementById('retainedAmount');
const quantityEl = document.getElementById('quantity');
const calcRetainedQty = document.getElementById('calcRetainedQty');
const calcPurchaseAmount = document.getElementById('calcPurchaseAmount');
const dateEl = document.getElementById('transactionDate');
const reviewBtn = document.getElementById('reviewBtn');
const confirmBtn = document.getElementById('confirmBtn');
const cancelReviewBtn = document.getElementById('cancelReviewBtn');
const confirmBox = document.getElementById('confirmBox');
const histForm = document.getElementById('histForm');

function syncSourceUI(){
    const isPurchase = srcPurchase.checked;
    retainedFields.classList.toggle('d-none', isPurchase);
    purchaseFields.classList.toggle('d-none', !isPurchase);
    recalc();
}
srcRetained.addEventListener('change', syncSourceUI);
srcPurchase.addEventListener('change', syncSourceUI);

function recalc(){
    if (srcPurchase.checked) {
        const qty = parseInt(quantityEl.value, 10);
        const amt = (qty > 0) ? qty * SHARE_VALUE : 0;
        calcPurchaseAmount.textContent = 'Shs ' + amt.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    } else {
        const amt = parseFloat(retainedAmountEl.value);
        const qty = (amt > 0 && SHARE_VALUE > 0) ? (amt / SHARE_VALUE) : 0;
        calcRetainedQty.textContent = qty.toFixed(4);
    }
    syncReviewButton();
}
retainedAmountEl.addEventListener('input', recalc);
quantityEl.addEventListener('input', function(){
    // Client-side whole-number guard only -- the server independently
    // rejects any fractional value regardless of what the browser allows.
    this.value = this.value.replace(/[^0-9]/g, '');
    recalc();
});
dateEl.addEventListener('change', syncReviewButton);

function syncReviewButton(){
    const hasMember = parseInt(memberIdEl.value, 10) > 0;
    const hasDate = !!dateEl.value;
    const hasAmount = srcPurchase.checked ? (parseInt(quantityEl.value, 10) > 0) : (parseFloat(retainedAmountEl.value) > 0);
    reviewBtn.disabled = !(hasMember && hasDate && hasAmount);
}

reviewBtn.addEventListener('click', function(){
    const isPurchase = srcPurchase.checked;
    document.getElementById('cfMember').textContent = selectedMemberName || searchEl.value;
    document.getElementById('cfSource').textContent = isPurchase ? 'Bought Shares' : 'Retained Savings';
    document.getElementById('cfDate').textContent = dateEl.value;
    document.getElementById('cfShareValue').textContent = 'Shs ' + SHARE_VALUE.toLocaleString(undefined, {minimumFractionDigits:2});
    if (isPurchase) {
        const qty = parseInt(quantityEl.value, 10) || 0;
        document.getElementById('cfAmountLabel').textContent = 'Number of Shares';
        document.getElementById('cfAmount').textContent = qty;
        document.getElementById('cfShares').textContent = qty + '.0000';
    } else {
        const amt = parseFloat(retainedAmountEl.value) || 0;
        const qty = SHARE_VALUE > 0 ? (amt / SHARE_VALUE) : 0;
        document.getElementById('cfAmountLabel').textContent = 'Retained Amount';
        document.getElementById('cfAmount').textContent = 'Shs ' + amt.toLocaleString(undefined, {minimumFractionDigits:2});
        document.getElementById('cfShares').textContent = qty.toFixed(4);
    }
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

histForm.addEventListener('submit', function(e){
    if (parseInt(memberIdEl.value, 10) < 1) { e.preventDefault(); alert('Please select a member.'); return; }
    if (!dateEl.value) { e.preventDefault(); alert('Please choose a historical date.'); return; }
});

syncSourceUI();
<?php if (!empty($old['member_id'])): ?>
searchEl.value = <?= json_encode(($old['member_id'] ?? '') ? 'Selected member #' . (int)$old['member_id'] : '') ?>;
<?php endif; ?>
})();
</script>
