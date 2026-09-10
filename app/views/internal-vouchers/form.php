<?php
$pageTitle = $pageTitle ?? 'New Internal Voucher';
$title = $pageTitle;
$icon  = 'bi-journal-check';
$base = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if (Session::has('error')): ?>
        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header"><i class="bi bi-journal-check me-2"></i>New Internal Voucher</div>
                <div class="card-body">
                    <div class="alert alert-info small">
                        This creates the voucher as a <strong>draft</strong>. It must be submitted for approval and approved by another user before it can be posted to the ledger.
                    </div>

                    <form method="POST" action="<?= $base ?>?page=internal-voucher-store" id="voucherForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Voucher Number</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($nextVoucherNumber) ?>" disabled>
                                <div class="form-text">Provisional — assigned for real when the draft is created.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Prepared By</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($preparedByName) ?>" disabled>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label d-block">Voucher Type</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="voucher_type" id="typeDebit" value="debit" checked onchange="updateType()">
                                <label class="btn btn-outline-primary" for="typeDebit"><i class="bi bi-dash-circle me-1"></i>Debit Voucher</label>
                                <input type="radio" class="btn-check" name="voucher_type" id="typeCredit" value="credit" onchange="updateType()">
                                <label class="btn btn-outline-success" for="typeCredit"><i class="bi bi-plus-circle me-1"></i>Credit Voucher</label>
                            </div>
                        </div>

                        <div id="debitFields">
                            <div class="mb-3">
                                <label class="form-label">Expense Category (optional)</label>
                                <select name="expense_category_id" id="expenseCategory" class="form-select" onchange="onCategoryChange()">
                                    <option value="">— None, select account directly —</option>
                                    <?php foreach ($expenseCategories as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">If you pick a category, its configured GL account is used automatically. If the category has no GL account set, submission will be blocked with a clear message.</div>
                            </div>
                        </div>

                        <div class="mb-3" id="primaryAccountFieldGroup">
                            <label class="form-label" id="primaryAccountLabel">Debit Account</label>
                            <select name="primary_account_id" id="primaryAccount" class="form-select" onchange="updatePreview(); checkSubledgerRequirement();">
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>" data-code="<?= htmlspecialchars($a['code']) ?>" data-name="<?= htmlspecialchars($a['name']) ?>"
                                        data-requires-subledger="<?= (int)($a['requires_subledger'] ?? 0) ?>"
                                        data-subledger-type="<?= htmlspecialchars($a['subledger_type'] ?? '') ?>">
                                        <?= htmlspecialchars($a['name'] . ' (' . $a['code'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3" id="memberSubledgerBlock" style="display:none;">
                            <label class="form-label fw-semibold">
                                Member / Account <span class="text-muted fw-normal" id="voucherSubledgerAccountLabel"></span>
                            </label>
                            <input type="text" class="form-control" id="voucherMemberSearch" placeholder="Search by name, member number, or account number..." autocomplete="off">
                            <input type="hidden" name="member_id" id="voucherMemberId">
                            <div class="list-group position-absolute" id="voucherMemberResults" style="z-index:1000;display:none;max-height:220px;overflow-y:auto;"></div>

                            <select name="savings_account_id" id="voucherAccountSelect" class="form-select mt-2" disabled onchange="onVoucherAccountChange()">
                                <option value="">Select a member first...</option>
                            </select>
                            <div class="alert alert-secondary py-1 px-2 small mb-0 mt-2" id="voucherAccountBalanceText" style="display:none;"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" id="contraAccountLabel">Credit Account</label>
                            <select name="contra_account_id" id="contraAccount" class="form-select" onchange="updatePreview(); checkSubledgerRequirement();">
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>" data-code="<?= htmlspecialchars($a['code']) ?>" data-name="<?= htmlspecialchars($a['name']) ?>"
                                        data-requires-subledger="<?= (int)($a['requires_subledger'] ?? 0) ?>"
                                        data-subledger-type="<?= htmlspecialchars($a['subledger_type'] ?? '') ?>">
                                        <?= htmlspecialchars($a['name'] . ' (' . $a['code'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount (Shs)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" required oninput="updatePreview()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Voucher Date</label>
                                <input type="date" name="voucher_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Being</label>
                            <textarea name="narration" class="form-control" rows="2" required placeholder="e.g. Purchase of office stationery"></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Create Draft
                            </button>
                            <a href="<?= $base ?>?page=internal-vouchers" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-eye me-2"></i>Accounting Impact Preview</div>
                <div class="card-body">
                    <div id="preview" class="small">
                        <em class="text-muted">Select accounts and an amount to preview.</em>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
// ------------------------------------------------------------
// Searchable account combobox — wraps a native <select> with a
// type-to-filter text input, so the underlying field/value/onchange
// behavior everything else in this page already relies on keeps
// working completely unchanged. No AJAX: the account list is small
// and already fully rendered, so filtering is instant and client-side.
// ------------------------------------------------------------
function makeSearchableSelect(selectId) {
    const select = document.getElementById(selectId);
    const requiredAttr = select.required;
    select.required = false;
    select.classList.add('d-none');

    const wrapper = document.createElement('div');
    wrapper.className = 'position-relative';
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.placeholder = 'Type account name or click to browse...';
    input.autocomplete = 'off';
    input.required = requiredAttr;
    wrapper.insertBefore(input, select);

    const list = document.createElement('div');
    list.className = 'list-group position-absolute w-100';
    list.style.cssText = 'z-index:1000;display:none;max-height:260px;overflow-y:auto;';
    wrapper.appendChild(list);

    function options() {
        return Array.from(select.options).filter(o => o.value !== '');
    }

    function render(filterText) {
        const needle = (filterText || '').trim().toLowerCase();
        const matches = options().filter(o => !needle || o.text.toLowerCase().includes(needle));
        list.innerHTML = '';
        if (!matches.length) {
            list.innerHTML = '<div class="list-group-item text-muted small">No matching accounts</div>';
        } else {
            matches.forEach(o => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action' + (o.value === select.value ? ' active' : '');
                item.style.fontSize = '.85rem';
                item.textContent = o.text;
                item.onclick = (e) => {
                    e.preventDefault();
                    select.value = o.value;
                    input.value = o.text;
                    list.style.display = 'none';
                    select.dispatchEvent(new Event('change'));
                };
                list.appendChild(item);
            });
        }
        list.style.display = '';
    }

    input.addEventListener('focus', () => { input.select(); render(input.value === selectedText() ? '' : input.value); });
    input.addEventListener('input', () => {
        if (select.value && input.value !== selectedText()) {
            select.value = '';
            select.dispatchEvent(new Event('change'));
        }
        render(input.value);
    });
    document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) list.style.display = 'none';
    });

    function selectedText() {
        const opt = select.options[select.selectedIndex];
        return (opt && opt.value !== '') ? opt.text : '';
    }

    // Keep the input's text in sync whenever the select's value changes,
    // including programmatically (e.g. reset elsewhere on the page).
    select.addEventListener('change', () => { input.value = selectedText(); });
    input.value = selectedText();
}

function updateType() {
    const isDebit = document.getElementById('typeDebit').checked;
    document.getElementById('debitFields').style.display = isDebit ? '' : 'none';
    // Both fields always show their true accounting role (Debit Account /
    // Credit Account, never generic jargon), so the full double-entry
    // picture is visible at once without needing to infer it from type.
    document.getElementById('primaryAccountLabel').textContent = isDebit ? 'Debit Account' : 'Credit Account';
    document.getElementById('contraAccountLabel').textContent = isDebit ? 'Credit Account' : 'Debit Account';
    if (!isDebit) {
        document.getElementById('expenseCategory').value = '';
        onCategoryChange();
    }
    updatePreview();
    checkSubledgerRequirement();
}

function onCategoryChange() {
    const catSelected = document.getElementById('expenseCategory').value !== '';
    document.getElementById('primaryAccountFieldGroup').style.display = catSelected ? 'none' : '';
    updatePreview();
    checkSubledgerRequirement();
}

// ------------------------------------------------------------
// Member subledger — savings only, this phase. Works for whichever
// side (the Debit Account field or the Credit Account field) is
// flagged requires_subledger — never assumes it's always one side.
// Stale values are cleared whenever the block is hidden, so they can
// never be submitted for an account that doesn't need them.
// ------------------------------------------------------------
let voucherSelectedAccountBalance = null;

function checkSubledgerRequirement() {
    const catChosen = document.getElementById('expenseCategory').value !== '';
    const primarySel = document.getElementById('primaryAccount');
    const contraSel = document.getElementById('contraAccount');
    const primaryOpt = !catChosen ? primarySel.options[primarySel.selectedIndex] : null;
    const contraOpt = contraSel.options[contraSel.selectedIndex];

    const primaryNeeds = !!(primaryOpt && primaryOpt.value && primaryOpt.dataset.requiresSubledger === '1');
    const contraNeeds = !!(contraOpt && contraOpt.value && contraOpt.dataset.requiresSubledger === '1');
    const needsSubledger = primaryNeeds || contraNeeds;
    const activeOpt = primaryNeeds ? primaryOpt : (contraNeeds ? contraOpt : null);

    const block = document.getElementById('memberSubledgerBlock');
    const label = document.getElementById('voucherSubledgerAccountLabel');
    block.style.display = needsSubledger ? '' : 'none';
    label.textContent = activeOpt ? ('for ' + activeOpt.dataset.name) : '';
    if (!needsSubledger) {
        document.getElementById('voucherMemberSearch').value = '';
        document.getElementById('voucherMemberId').value = '';
        document.getElementById('voucherMemberResults').style.display = 'none';
        resetVoucherAccountSelect();
    }
    document.getElementById('voucherMemberSearch').required = needsSubledger;
    document.getElementById('voucherAccountSelect').required = needsSubledger;
    updatePreview();
}

document.getElementById('voucherMemberSearch').addEventListener('input', function () {
    document.getElementById('voucherMemberId').value = '';
    resetVoucherAccountSelect();
    clearTimeout(window.__voucherMemberTimer);
    const q = this.value.trim();
    const results = document.getElementById('voucherMemberResults');
    if (q.length < 2) { results.style.display = 'none'; return; }
    window.__voucherMemberTimer = setTimeout(() => {
        fetch('<?= $base ?>?page=internal-voucher-member-search&q=' + encodeURIComponent(q))
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
                        document.getElementById('voucherMemberSearch').value = m.full_name + ' (' + m.member_number + ')';
                        document.getElementById('voucherMemberId').value = m.id;
                        results.style.display = 'none';
                        loadVoucherAccounts(m.id);
                    };
                    results.appendChild(item);
                });
                results.style.display = '';
            });
    }, 250);
});
document.addEventListener('click', function (e) {
    const input = document.getElementById('voucherMemberSearch'), results = document.getElementById('voucherMemberResults');
    if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none';
});

function resetVoucherAccountSelect() {
    const sel = document.getElementById('voucherAccountSelect');
    sel.innerHTML = '<option value="">Select a member first...</option>';
    sel.disabled = true;
    voucherSelectedAccountBalance = null;
    const balanceEl = document.getElementById('voucherAccountBalanceText');
    balanceEl.textContent = '';
    balanceEl.style.display = 'none';
    updatePreview();
}

function loadVoucherAccounts(memberId) {
    fetch('<?= $base ?>?page=internal-voucher-member-accounts&member_id=' + memberId)
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('voucherAccountSelect');
            sel.innerHTML = '';
            if (!data.accounts || !data.accounts.length) {
                sel.innerHTML = '<option value="">This member has no eligible accounts</option>';
                sel.disabled = true;
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
        });
}

function onVoucherAccountChange() {
    const sel = document.getElementById('voucherAccountSelect');
    const opt = sel.options[sel.selectedIndex];
    voucherSelectedAccountBalance = opt && opt.dataset.balance !== undefined ? parseFloat(opt.dataset.balance) : null;
    const balanceEl = document.getElementById('voucherAccountBalanceText');
    if (voucherSelectedAccountBalance !== null) {
        balanceEl.innerHTML = 'Current Balance: <strong>Shs ' + voucherSelectedAccountBalance.toLocaleString(undefined, {minimumFractionDigits: 2}) + '</strong>';
        balanceEl.style.display = '';
    } else {
        balanceEl.textContent = '';
        balanceEl.style.display = 'none';
    }
    updatePreview();
}

function updatePreview() {
    const isDebit = document.getElementById('typeDebit').checked;
    const primarySel = document.getElementById('primaryAccount');
    const contraSel = document.getElementById('contraAccount');
    const catSel = document.getElementById('expenseCategory');
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const el = document.getElementById('preview');

    const catChosen = catSel.value !== '';
    const primaryOpt = primarySel.options[primarySel.selectedIndex];
    const contraOpt = contraSel.options[contraSel.selectedIndex];

    let primaryLabel = catChosen ? (catSel.options[catSel.selectedIndex].text + ' (category-mapped account)') : (primaryOpt ? primaryOpt.text : null);

    if ((!catChosen && !primaryOpt.value) || !contraOpt.value || amount <= 0) {
        el.innerHTML = '<em class="text-muted">Select accounts and an amount to preview.</em>';
        return;
    }

    const fmt = n => 'Shs ' + n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    let rows;
    if (isDebit) {
        rows = [['Dr', primaryLabel, amount], ['Cr', contraOpt.text, amount]];
    } else {
        rows = [['Dr', contraOpt.text, amount], ['Cr', primaryLabel, amount]];
    }

    let html = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th></th><th>Account</th><th class="text-end">Amount</th></tr></thead><tbody>';
    rows.forEach(r => {
        html += '<tr><td><strong>' + r[0] + '</strong></td><td>' + r[1] + '</td><td class="text-end">' + fmt(r[2]) + '</td></tr>';
    });
    html += '</tbody></table></div>';

    const block = document.getElementById('memberSubledgerBlock');
    if (block.style.display !== 'none' && voucherSelectedAccountBalance !== null) {
        const primaryNeeds = !catChosen && primaryOpt && primaryOpt.dataset.requiresSubledger === '1';
        // If the flagged account is on the primary side: debit voucher debits it (decrease),
        // credit voucher credits it (increase). If it's on the contra side, the roles flip:
        // debit voucher credits contra (increase), credit voucher debits contra (decrease).
        const memberIncreases = primaryNeeds ? !isDebit : isDebit;
        const newBalance = memberIncreases ? voucherSelectedAccountBalance + amount : voucherSelectedAccountBalance - amount;
        html += '<div class="table-responsive"><table class="table table-sm mb-0 mt-2"><tr><th>Member Balance Before</th><td>' + fmt(voucherSelectedAccountBalance) + '</td></tr>'
              + '<tr class="fw-bold"><th>Member Balance After</th><td class="' + (newBalance < 0 ? 'text-danger' : '') + '">' + fmt(newBalance) + '</td></tr></table></div>';
        if (newBalance < 0) {
            html += '<div class="alert alert-danger small mt-2 mb-0">This would take the member\'s savings account below zero — the server will reject it.</div>';
        }
    }

    el.innerHTML = html;
}

makeSearchableSelect('primaryAccount');
makeSearchableSelect('contraAccount');
updateType();
</script>
