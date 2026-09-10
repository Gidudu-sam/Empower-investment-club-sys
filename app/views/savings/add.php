<?php
/**
 * "Record Savings" — single-page flow (2026-09): search the member, pick
 * their account, then record the deposit right here, matching the same
 * search-then-record pattern already used for Loan Repayments
 * (repayments/form.php). Previously this page only picked the account and
 * then navigated to a separate page (SavingsAccountController::
 * depositForm()) to actually record the deposit -- two page loads for one
 * task. The actual deposit is still recorded through the exact same,
 * unchanged depositStore() endpoint and business logic; only the
 * front-end journey was consolidated. Joint accounts still let the user
 * choose which holder is depositing, exactly as the old two-page flow did
 * -- the account picker's AJAX response now carries each joint account's
 * holder list for this inline form to use.
 */
$base    = APP_URL . '/index.php';
$title   = 'Record Savings';
$icon    = 'bi-plus-circle-fill';
$subtitle = 'Find the member and their account, then record the deposit';
$methods = ['Cash', 'Airtel Money', 'MTN Mobile Money', 'Bank Transfer', 'Cheque', 'Other'];
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">

            <!-- ── STEP 1: FIND MEMBER + ACCOUNT ─────────────────────────── -->
            <div class="card mb-3">
                <div class="card-body p-4">
                    <!-- Arriving with ?member_id= (e.g. the "New Deposit" button on a
                         member's own profile) skips straight past the search --
                         showing it again here would make the cashier re-find someone
                         they were just looking at. "Change Member" reveals it if the
                         wrong member was pre-selected or a different one is needed. -->
                    <div id="preselectedMemberBanner" class="d-flex align-items-center justify-content-between p-3 rounded-3 bg-light border mb-3" style="<?= $preselectedMember ? '' : 'display:none;' ?>">
                        <div>
                            <div class="text-muted small">Recording for</div>
                            <div class="fw-semibold"><?= $preselectedMember ? htmlspecialchars($preselectedMember['full_name'] . ' (' . $preselectedMember['member_number'] . ')') : '' ?></div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="changeMemberBtn">
                            <i class="bi bi-arrow-repeat me-1"></i>Change Member
                        </button>
                    </div>

                    <div id="memberSearchGroup" style="<?= $preselectedMember ? 'display:none;' : '' ?>">
                        <label class="form-label fw-semibold">Find Member</label>
                        <div class="position-relative mb-3">
                            <input type="text" class="form-control" id="memberSearch"
                                   placeholder="Search by name, member number, or phone…" autocomplete="off">
                            <div class="list-group position-absolute" id="memberResults"
                                 style="z-index:1000;display:none;max-height:220px;overflow-y:auto;width:100%;"></div>
                        </div>
                    </div>

                    <div id="accountsPanel" style="display:none;">
                        <label class="form-label fw-semibold">Select Account</label>
                        <div id="accountsList" class="list-group mb-2"></div>
                        <div id="noAccountsMsg" class="alert alert-warning small" style="display:none;">
                            This member has no active savings account eligible for a deposit.
                            <a href="<?= $base ?>?page=savings-account-open">Open a savings account →</a>
                        </div>
                    </div>

                    <div id="emptyState" class="text-center text-muted py-4" style="<?= $preselectedMember ? 'display:none;' : '' ?>">
                        <i class="bi bi-search fs-2 d-block mb-2 opacity-50"></i>
                        Search for a member above to record their savings.
                    </div>
                </div>
            </div>

            <!-- ── STEP 2: RECORD DEPOSIT (revealed once an account is picked) ── -->
            <div class="card" id="depositCard" style="display:none;">
                <div class="card-header">
                    <i class="bi bi-plus-circle me-2"></i>Record Deposit — <span id="depositAccountNumber"></span>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary small d-flex justify-content-between">
                        <span>Account Type</span>
                        <strong id="depositAccountType"></strong>
                    </div>
                    <div class="alert alert-secondary small d-flex justify-content-between">
                        <span>Current Balance</span>
                        <strong id="depositAccountBalance"></strong>
                    </div>

                    <?php if (!$canDeposit): ?>
                    <div class="alert alert-warning small mb-0">You do not have permission to record a deposit — contact an Admin, Treasurer, or Cashier.</div>
                    <?php else: ?>
                    <form method="POST" action="<?= $base ?>?page=savings-account-deposit-store" id="depositForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="account_id" id="depositAccountId" value="">

                        <div class="mb-3" id="jointHolderGroup" style="display:none;">
                            <label class="form-label">Holder Making This Deposit</label>
                            <select name="member_id" id="jointHolderSelect" class="form-select">
                                <option value="">Select Holder</option>
                            </select>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount (Shs)</label>
                                <div class="input-group">
                                    <span class="input-group-text">Shs</span>
                                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" id="paymentMethodSelect" class="form-select" required>
                                    <?php foreach ($methods as $pm): ?>
                                        <option value="<?= $pm ?>" <?= $pm === 'Cash' ? 'selected' : '' ?>><?= $pm ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6" id="cashReferenceNote">
                                <label class="form-label">Cash Reference</label>
                                <input type="text" class="form-control" value="Will be generated automatically" disabled readonly>
                            </div>
                            <div class="col-md-6" id="externalReferenceField" style="display:none;">
                                <label class="form-label">Reference Number <small class="text-muted">(optional)</small></label>
                                <input type="text" name="reference_number" class="form-control" maxlength="100" placeholder="Mobile money code, cheque no.">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Transaction Date</label>
                                <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                                <textarea name="notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">Record Deposit</button>
                            <button type="button" class="btn btn-secondary" id="changeAccountBtn">
                                <i class="bi bi-arrow-left me-1"></i>Change Account
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

<script>
const CAN_DEPOSIT = <?= $canDeposit ? 'true' : 'false' ?>;

document.getElementById('memberSearch').addEventListener('input', function () {
    resetAccounts();
    clearTimeout(window.__savingsMemberTimer);
    const q = this.value.trim();
    const results = document.getElementById('memberResults');
    if (q.length < 2) { results.style.display = 'none'; return; }
    window.__savingsMemberTimer = setTimeout(() => {
        fetch('<?= $base ?>?page=savings-member-search&q=' + encodeURIComponent(q))
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

function resetAccounts() {
    document.getElementById('accountsPanel').style.display = 'none';
    document.getElementById('emptyState').style.display = '';
    document.getElementById('accountsList').innerHTML = '';
    document.getElementById('noAccountsMsg').style.display = 'none';
    hideDepositCard();
}

function hideDepositCard() {
    const card = document.getElementById('depositCard');
    if (card) card.style.display = 'none';
}

function loadAccounts(memberId) {
    fetch('<?= $base ?>?page=savings-add-member-accounts&member_id=' + memberId)
        .then(r => r.json())
        .then(data => {
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('accountsPanel').style.display = '';
            const list = document.getElementById('accountsList');
            const noAccounts = document.getElementById('noAccountsMsg');
            list.innerHTML = '';
            hideDepositCard();
            if (!data.accounts || !data.accounts.length) {
                noAccounts.style.display = '';
                return;
            }
            noAccounts.style.display = 'none';
            data.accounts.forEach(a => {
                const row = document.createElement('div');
                row.className = 'list-group-item d-flex align-items-center justify-content-between';
                const balance = Number(a.balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                row.innerHTML = '<div>'
                    + '<div class="fw-semibold">' + a.account_number + '</div>'
                    + '<div class="text-muted small">' + a.account_type + ' &middot; Shs ' + balance + '</div>'
                    + '</div>';
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = CAN_DEPOSIT ? 'btn btn-sm btn-success' : 'btn btn-sm btn-outline-secondary';
                btn.innerHTML = CAN_DEPOSIT
                    ? '<i class="bi bi-plus-circle me-1"></i>Record Deposit'
                    : '<i class="bi bi-eye me-1"></i>View Account';
                btn.onclick = () => {
                    if (CAN_DEPOSIT) {
                        selectAccount(a, balance);
                    } else {
                        window.location.href = '<?= $base ?>?page=savings-account-view&id=' + a.id;
                    }
                };
                row.appendChild(btn);
                list.appendChild(row);
            });
        });
}

function selectAccount(a, balanceFormatted) {
    document.getElementById('depositAccountId').value = a.id;
    document.getElementById('depositAccountNumber').textContent = a.account_number;
    document.getElementById('depositAccountType').textContent = a.account_type;
    document.getElementById('depositAccountBalance').textContent = 'Shs ' + balanceFormatted;

    const jointGroup  = document.getElementById('jointHolderGroup');
    const jointSelect = document.getElementById('jointHolderSelect');
    if (jointGroup && jointSelect) {
        if (a.account_type === 'Joint' && Array.isArray(a.holders) && a.holders.length) {
            jointSelect.innerHTML = '<option value="">Select Holder</option>';
            a.holders.forEach(h => {
                const opt = document.createElement('option');
                opt.value = h.member_id;
                opt.textContent = h.name + ' (' + h.member_number + ')' + (h.role === 'primary' ? ' — Primary' : '');
                jointSelect.appendChild(opt);
            });
            jointGroup.style.display = '';
            jointSelect.required = true;
        } else {
            jointGroup.style.display = 'none';
            jointSelect.required = false;
        }
    }

    const card = document.getElementById('depositCard');
    if (card) {
        card.style.display = '';
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

const changeBtn = document.getElementById('changeAccountBtn');
if (changeBtn) {
    changeBtn.addEventListener('click', hideDepositCard);
}

const changeMemberBtn = document.getElementById('changeMemberBtn');
if (changeMemberBtn) {
    changeMemberBtn.addEventListener('click', function () {
        document.getElementById('preselectedMemberBanner').style.display = 'none';
        document.getElementById('memberSearchGroup').style.display = '';
        document.getElementById('memberSearch').focus();
        resetAccounts();
        document.getElementById('emptyState').style.display = '';
    });
}

<?php if ($preselectedMember): ?>
// Arrived with ?member_id= (e.g. from a member's own profile) -- load
// their accounts immediately instead of waiting for a search.
loadAccounts(<?= (int)$preselectedMember['id'] ?>);
<?php endif; ?>

(function () {
    const methodSel   = document.getElementById('paymentMethodSelect');
    const cashNote    = document.getElementById('cashReferenceNote');
    const externalRef = document.getElementById('externalReferenceField');
    if (!methodSel || !cashNote || !externalRef) { return; }
    function toggle() {
        const isCash = methodSel.value === 'Cash';
        cashNote.style.display    = isCash ? '' : 'none';
        externalRef.style.display = isCash ? 'none' : '';
    }
    methodSel.addEventListener('change', toggle);
    toggle();
})();
</script>
