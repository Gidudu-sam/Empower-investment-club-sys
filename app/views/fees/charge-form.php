<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Record Fee</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Charge a fee to a member — Fees &rsaquo; Charges</p>
    </div>
    <a href="<?= $base ?>?page=fee-charges" class="btn btn-outline-secondary btn-sm">Cancel</a>
</div>

<?php if (Session::has('error')): ?>
<div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=fee-charge-store" id="chargeForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3 position-relative">
                        <label class="form-label fw-semibold">Member</label>
                        <input type="text" class="form-control" id="memberSearch" placeholder="Search by name, member number, or phone..." autocomplete="off" required>
                        <input type="hidden" name="member_id" id="memberId">
                        <div class="list-group position-absolute" id="memberResults" style="z-index:1000;display:none;max-height:220px;overflow-y:auto;width:100%;"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fee</label>
                        <select name="fee_id" id="feeSelect" class="form-select" required onchange="updatePreview()">
                            <option value="">Select a fee...</option>
                            <?php foreach ($fees as $f): ?>
                            <option value="<?= $f['id'] ?>" data-amount="<?= (float)$f['amount'] ?>" data-frequency="<?= htmlspecialchars($f['frequency']) ?>">
                                <?= htmlspecialchars($f['fee_name']) ?> (<?= ucfirst(str_replace('_', ' ', $f['frequency'])) ?>) — UGX <?= number_format($f['amount'], 0) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($fees)): ?>
                        <div class="form-text text-danger">No fixed, non-loan fees are configured as active. Ask an administrator to set one up under Manage Fees.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" id="paymentMethodSelect" class="form-select" onchange="updatePreview()">
                            <option value="">— Charge only, collect payment later —</option>
                            <option value="Cash">Cash</option>
                            <option value="MTN Mobile Money">MTN Mobile Money</option>
                            <option value="Airtel Money">Airtel Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">External Payment Reference</label>
                        <input type="text" name="external_reference" class="form-control" maxlength="100">
                        <div class="form-text">Optional — enter the reference provided by the bank, mobile-money provider, cheque, or other external payment channel.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold" id="chargeSubmit" disabled>
                        <i class="bi bi-cash-coin me-1"></i><span id="chargeSubmitLabel">Record Fee</span>
                    </button>
                    <div class="form-text mt-2" id="chargeHelpText">This creates a <strong>pending</strong> charge in the Charge Ledger — it does not collect payment. Use "Mark Paid" there once the member actually pays.</div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2"></i>Preview</div>
            <div class="card-body">
                <div id="preview" class="small">
                    <em class="text-muted">Select a member and a fee to preview.</em>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let selectedMemberLabel = '';

document.getElementById('memberSearch').addEventListener('input', function () {
    document.getElementById('memberId').value = '';
    selectedMemberLabel = '';
    validateForm();
    updatePreview();
    clearTimeout(window.__memberTimer);
    const q = this.value.trim();
    const results = document.getElementById('memberResults');
    if (q.length < 2) { results.style.display = 'none'; return; }
    window.__memberTimer = setTimeout(() => {
        fetch('<?= $base ?>?page=fee-member-search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                results.innerHTML = '';
                if (!data.members || !data.members.length) { results.style.display = 'none'; return; }
                data.members.forEach(m => {
                    const item = document.createElement('a');
                    item.href = '#';
                    item.className = 'list-group-item list-group-item-action';
                    item.style.fontSize = '.82rem';
                    item.textContent = m.full_name + ' (' + m.member_number + ') — ' + m.phone;
                    item.onclick = (e) => {
                        e.preventDefault();
                        selectedMemberLabel = m.full_name + ' (' + m.member_number + ')';
                        document.getElementById('memberSearch').value = selectedMemberLabel;
                        document.getElementById('memberId').value = m.id;
                        results.style.display = 'none';
                        validateForm();
                        updatePreview();
                    };
                    results.appendChild(item);
                });
                results.style.display = '';
            });
    }, 250);
});
document.addEventListener('click', function (e) {
    const input = document.getElementById('memberSearch'), results = document.getElementById('memberResults');
    if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none';
});

function validateForm() {
    const memberOk = !!document.getElementById('memberId').value;
    const feeOk = !!document.getElementById('feeSelect').value;
    document.getElementById('chargeSubmit').disabled = !(memberOk && feeOk);
}

function updatePreview() {
    validateForm();

    const payingNow = !!document.getElementById('paymentMethodSelect').value;
    document.getElementById('chargeSubmitLabel').textContent = payingNow ? 'Record Fee & Mark Paid' : 'Record Fee';
    document.getElementById('chargeHelpText').innerHTML = payingNow
        ? 'This will charge the fee <strong>and immediately mark it paid</strong> with the selected payment method — posted straight to the Charge Ledger as Paid.'
        : 'This creates a <strong>pending</strong> charge in the Charge Ledger — it does not collect payment. Use "Mark Paid" there once the member actually pays.';

    const preview = document.getElementById('preview');
    const memberId = document.getElementById('memberId').value;
    const feeSelect = document.getElementById('feeSelect');
    const opt = feeSelect.options[feeSelect.selectedIndex];
    if (!memberId || !feeSelect.value) {
        preview.innerHTML = '<em class="text-muted">Select a member and a fee to preview.</em>';
        return;
    }
    const amount = parseFloat(opt.dataset.amount || 0);
    const frequency = opt.dataset.frequency || '';
    // Stage 13-F2 (13E-XSS-01 chain): selectedMemberLabel is built from
    // m.full_name (untrusted stored member data, set on selection above)
    // and opt.text is a <select> option's decoded text -- both are safe
    // to place in the DOM as text but not to splice raw into an innerHTML
    // string. Rendered with textContent per row instead.
    preview.innerHTML =
        '<div class="d-flex justify-content-between mb-2"><span class="text-muted">Member</span><strong id="previewMember"></strong></div>' +
        '<div class="d-flex justify-content-between mb-2"><span class="text-muted">Fee</span><strong id="previewFee"></strong></div>' +
        '<div class="d-flex justify-content-between mb-2"><span class="text-muted">Frequency</span><strong id="previewFrequency"></strong></div>' +
        '<div class="d-flex justify-content-between mb-2"><span class="text-muted">Status</span><strong id="previewStatus"></strong></div>' +
        '<hr>' +
        '<div class="d-flex justify-content-between"><span class="text-muted">Amount</span><strong class="text-primary">UGX ' + amount.toLocaleString() + '</strong></div>';
    document.getElementById('previewMember').textContent = selectedMemberLabel;
    document.getElementById('previewFee').textContent = opt.text.split(' (')[0];
    document.getElementById('previewFrequency').textContent = frequency.replace('_', ' ');
    document.getElementById('previewStatus').textContent = payingNow ? 'Will be marked Paid' : 'Pending';
}
</script>
