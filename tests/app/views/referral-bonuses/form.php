<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Record Referral / Bonus Payout</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Reference <?= htmlspecialchars($referenceNumber) ?> (provisional)</p>
    </div>
    <a href="<?= $base ?>?page=referral-bonuses" class="btn btn-outline-secondary btn-sm">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
<?php endif; ?>
<?php if (Session::has('error')): ?>
<div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=referral-bonus-store" id="rbForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bonus Type</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="bonus_type" id="typeReferral" value="referral" checked onchange="updateType()">
                            <label class="btn btn-outline-primary" for="typeReferral"><i class="bi bi-person-plus me-1"></i>Referral</label>
                            <input type="radio" class="btn-check" name="bonus_type" id="typeTarget" value="staff_target" onchange="updateType()">
                            <label class="btn btn-outline-warning" for="typeTarget"><i class="bi bi-trophy me-1"></i>Staff Target</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" id="beneficiaryLabel">Member Receiving the Bonus</label>
                        <input type="text" class="form-control" id="beneficiarySearch" placeholder="Search by name, member number, or phone..." autocomplete="off" required>
                        <input type="hidden" name="beneficiary_member_id" id="beneficiaryId">
                        <div class="list-group position-absolute" id="beneficiaryResults" style="z-index:1000;display:none;max-height:220px;overflow-y:auto;"></div>
                    </div>

                    <div class="mb-3" id="referredGroup">
                        <label class="form-label fw-semibold">Member Who Was Referred (new member)</label>
                        <input type="text" class="form-control" id="referredSearch" placeholder="Search by name, member number, or phone..." autocomplete="off">
                        <input type="hidden" name="referred_member_id" id="referredId">
                        <div class="list-group position-absolute" id="referredResults" style="z-index:1000;display:none;max-height:220px;overflow-y:auto;"></div>
                    </div>

                    <div class="mb-3" id="targetGroup" style="display:none;">
                        <label class="form-label fw-semibold">Target Description</label>
                        <input type="text" name="target_note" class="form-control" placeholder="e.g. Recruited 10 new members in August">
                    </div>

                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Amount (UGX)</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="Cash">Cash</option>
                            <option value="MTN Mobile Money">MTN Mobile Money</option>
                            <option value="Airtel Money">Airtel Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes (optional)</label>
                        <textarea name="narration" class="form-control" rows="2"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold" id="rbSubmit" disabled>
                        <i class="bi bi-cash-coin me-1"></i>Record &amp; Post Payout
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function updateType() {
    const isReferral = document.getElementById('typeReferral').checked;
    document.getElementById('referredGroup').style.display = isReferral ? '' : 'none';
    document.getElementById('targetGroup').style.display = isReferral ? 'none' : '';
    document.getElementById('beneficiaryLabel').textContent = isReferral ? 'Member Receiving the Bonus (the referrer)' : 'Member Receiving the Bonus';
    if (!isReferral) {
        document.getElementById('referredSearch').value = '';
        document.getElementById('referredId').value = '';
    }
    validateForm();
}

function setupPicker(searchId, hiddenId, resultsId) {
    const input = document.getElementById(searchId);
    const hidden = document.getElementById(hiddenId);
    const results = document.getElementById(resultsId);
    let timer = null;

    input.addEventListener('input', function () {
        hidden.value = '';
        validateForm();
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function () {
            fetch('<?= $base ?>?page=referral-bonus-member-search&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    results.innerHTML = '';
                    if (!data.members || !data.members.length) { results.style.display = 'none'; return; }
                    data.members.forEach(function (m) {
                        const item = document.createElement('a');
                        item.href = '#';
                        item.className = 'list-group-item list-group-item-action';
                        item.style.fontSize = '.82rem';
                        item.textContent = m.full_name + ' (' + m.member_number + ') — ' + m.phone;
                        item.onclick = function (e) {
                            e.preventDefault();
                            input.value = m.full_name + ' (' + m.member_number + ')';
                            hidden.value = m.id;
                            results.style.display = 'none';
                            validateForm();
                        };
                        results.appendChild(item);
                    });
                    results.style.display = '';
                });
        }, 250);
    });
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none';
    });
}

function validateForm() {
    const isReferral = document.getElementById('typeReferral').checked;
    const hasBeneficiary = document.getElementById('beneficiaryId').value !== '';
    const hasReferred = document.getElementById('referredId').value !== '';
    document.getElementById('rbSubmit').disabled = !hasBeneficiary || (isReferral && !hasReferred);
}

setupPicker('beneficiarySearch', 'beneficiaryId', 'beneficiaryResults');
setupPicker('referredSearch', 'referredId', 'referredResults');
updateType();
</script>
