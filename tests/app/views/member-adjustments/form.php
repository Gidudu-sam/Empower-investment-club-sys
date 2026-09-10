<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">New Member Account Adjustment</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Adjustment <?= htmlspecialchars($nextAdjustmentNumber) ?> (provisional) — Accounting &rsaquo; Account Adjustments</p>
    </div>
    <a href="<?= $base ?>?page=member-adjustments" class="btn btn-outline-secondary btn-sm">Cancel</a>
</div>

<?php if (Session::has('error')): ?>
<div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-body p-4">
                <div class="alert alert-info small">
                    This creates the adjustment as a <strong>draft</strong>. It must be submitted, approved by a different user, then posted before it affects the member's balance.
                </div>

                <form method="POST" action="<?= $base ?>?page=member-adjustment-store" id="adjForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Member</label>
                        <input type="text" class="form-control" id="memberSearch" placeholder="Search by name, member number, or phone..." autocomplete="off" required>
                        <input type="hidden" name="member_id" id="memberId">
                        <div class="list-group position-absolute" id="memberResults" style="z-index:1000;display:none;max-height:220px;overflow-y:auto;"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Member's Account</label>
                        <select name="savings_account_id" id="accountSelect" class="form-select" required disabled onchange="onAccountChange()">
                            <option value="">Select a member first...</option>
                        </select>
                        <div class="form-text" id="accountBalanceText"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">Adjustment Type</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="adjustment_type" id="typeCredit" value="credit" checked onchange="updatePreview()">
                            <label class="btn btn-outline-success" for="typeCredit"><i class="bi bi-plus-circle me-1"></i>Credit (increase balance)</label>
                            <input type="radio" class="btn-check" name="adjustment_type" id="typeDebit" value="debit" onchange="updatePreview()">
                            <label class="btn btn-outline-danger" for="typeDebit"><i class="bi bi-dash-circle me-1"></i>Debit (decrease balance)</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (Shs)</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" required oninput="updatePreview()">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contra (Other Side) GL Account</label>
                        <select name="contra_account_id" class="form-select" required>
                            <option value="">Select account...</option>
                            <?php foreach ($accounts as $a): ?>
                                <?php if ($a['code'] === '2020') continue; // liability side is implicit, never selectable as the contra ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name'] . ' (' . $a['code'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Where the other side of this correction hits — e.g. Cash if real money moves, or an appropriate income/expense/suspense account for a pure bookkeeping correction.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" required minlength="10"
                            placeholder="Explain specifically what happened, e.g. &quot;Correction of wrongly posted transaction — Shs 100,000 posted to this member in error on 28 Aug 2026.&quot;"></textarea>
                        <div class="form-text">Be specific. Generic reasons like "test" or "correction" are rejected.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Original Transaction Reference (optional)</label>
                        <input type="text" name="original_reference" class="form-control" placeholder="e.g. SAV-000125">
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold" id="adjSubmit" disabled>
                        <i class="bi bi-check-circle me-1"></i>Create Draft
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2"></i>Preview</div>
            <div class="card-body">
                <div id="preview" class="small">
                    <em class="text-muted">Select a member, an account, and an amount to preview.</em>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let selectedAccountBalance = null;

document.getElementById('memberSearch').addEventListener('input', function () {
    document.getElementById('memberId').value = '';
    resetAccountSelect();
    validateForm();
    clearTimeout(window.__memberTimer);
    const q = this.value.trim();
    const results = document.getElementById('memberResults');
    if (q.length < 2) { results.style.display = 'none'; return; }
    window.__memberTimer = setTimeout(() => {
        fetch('<?= $base ?>?page=member-adjustment-member-search&q=' + encodeURIComponent(q))
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
                        document.getElementById('memberSearch').value = m.full_name + ' (' + m.member_number + ')';
                        document.getElementById('memberId').value = m.id;
                        results.style.display = 'none';
                        loadAccounts(m.id);
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

function resetAccountSelect() {
    const sel = document.getElementById('accountSelect');
    sel.innerHTML = '<option value="">Select a member first...</option>';
    sel.disabled = true;
    selectedAccountBalance = null;
    document.getElementById('accountBalanceText').textContent = '';
    updatePreview();
}

function loadAccounts(memberId) {
    fetch('<?= $base ?>?page=member-adjustment-member-accounts&member_id=' + memberId)
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('accountSelect');
            sel.innerHTML = '';
            if (!data.accounts || !data.accounts.length) {
                sel.innerHTML = '<option value="">This member has no eligible accounts</option>';
                sel.disabled = true;
                validateForm();
                return;
            }
            sel.innerHTML = '<option value="">Select account...</option>';
            data.accounts.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.dataset.balance = a.balance;
                opt.textContent = a.account_type + ' — ' + a.account_number + ' (Shs ' + Number(a.balance).toLocaleString(undefined, {minimumFractionDigits: 2}) + ')';
                sel.appendChild(opt);
            });
            sel.disabled = false;
            validateForm();
        });
}

function onAccountChange() {
    const sel = document.getElementById('accountSelect');
    const opt = sel.options[sel.selectedIndex];
    selectedAccountBalance = opt && opt.dataset.balance !== undefined ? parseFloat(opt.dataset.balance) : null;
    document.getElementById('accountBalanceText').textContent = selectedAccountBalance !== null
        ? 'Current balance: Shs ' + selectedAccountBalance.toLocaleString(undefined, {minimumFractionDigits: 2}) : '';
    updatePreview();
}

function updatePreview() {
    const el = document.getElementById('preview');
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const isCredit = document.getElementById('typeCredit').checked;
    const memberLabel = document.getElementById('memberSearch').value;

    if (selectedAccountBalance === null || amount <= 0 || !memberLabel) {
        el.innerHTML = '<em class="text-muted">Select a member, an account, and an amount to preview.</em>';
        validateForm();
        return;
    }
    const newBalance = isCredit ? selectedAccountBalance + amount : selectedAccountBalance - amount;
    const fmt = n => 'Shs ' + n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    el.innerHTML = `
        <div class="table-responsive">
            <table class="table table-sm mb-0">
            <tr><th>Member</th><td>${memberLabel}</td></tr>
            <tr><th>Type</th><td><span class="badge ${isCredit ? 'bg-success' : 'bg-danger'}">${isCredit ? 'CREDIT' : 'DEBIT'}</span></td></tr>
            <tr><th>Current Balance</th><td>${fmt(selectedAccountBalance)}</td></tr>
            <tr><th>${isCredit ? 'Credit' : 'Debit'}</th><td>${fmt(amount)}</td></tr>
            <tr class="fw-bold"><th>New Balance</th><td class="${newBalance < 0 ? 'text-danger' : ''}">${fmt(newBalance)}</td></tr>
        </table>
        </div>
        ${newBalance < 0 ? '<div class="alert alert-danger small mt-2 mb-0">This would take the account below zero — the server will reject it.</div>' : ''}
    `;
    validateForm();
}

function validateForm() {
    const hasMember = document.getElementById('memberId').value !== '';
    const hasAccount = document.getElementById('accountSelect').value !== '';
    const hasAmount = (parseFloat(document.getElementById('amount').value) || 0) > 0;
    document.getElementById('adjSubmit').disabled = !(hasMember && hasAccount && hasAmount);
}
resetAccountSelect();
</script>
