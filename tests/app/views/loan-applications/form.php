<?php
$isEdit = $formMode === 'edit';
$v   = fn(string $k, string $d='') => htmlspecialchars((string)($application[$k] ?? $d));
$err = fn(string $k) => $errors[$k] ?? '';
$cls = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-<?= $isEdit?'pencil-square':'plus-circle-fill' ?> me-2 text-warning"></i>
            <?= $isEdit ? 'Edit Loan Application' : 'New Loan Application' ?>
        </h1>
        <p class="text-muted mb-0 small">Application Number: <strong style="color:var(--brand-navy)"><?= htmlspecialchars($applicationNumber) ?></strong></p>
    </div>
    <a href="<?= APP_URL ?>/index.php?page=loan-applications" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Applications
    </a>
</div>

<form method="POST" action="<?= $formAction ?>" novalidate>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<?php if ($err('product_rules') || $err('member_id') || $err('requested_amount') || $err('requested_period_months')): ?>
<div class="alert alert-danger">
    <?php foreach (['product_rules','member_id','requested_amount','requested_period_months'] as $k): ?>
        <?php if ($err($k)): ?><div><?= htmlspecialchars($err($k)) ?></div><?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-person-circle text-warning"></i><h6 class="mb-0 fw-semibold">Member & Product</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
                <input type="hidden" name="member_id" id="memberId" value="<?= $v('member_id') ?>">
                <div class="position-relative">
                    <input type="text" id="memberSearch" class="form-control<?= $cls('member_id') ?>" placeholder="Type name, member no., or phone…" autocomplete="off">
                    <div id="memberDropdown" class="list-group shadow-sm" style="position:absolute;z-index:1055;width:100%;display:none;max-height:220px;overflow-y:auto;"></div>
                </div>
                <div id="memberSelectedLabel" class="small text-success mt-1"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="loan_type_id">Loan Product <span class="text-danger">*</span></label>
                <select id="loan_type_id" name="loan_type_id" class="form-select" required>
                    <?php foreach ($loanTypes as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= (string)($application['loan_type_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="requested_amount">Requested Amount (Shs) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">Shs</span>
                    <input type="number" id="requested_amount" name="requested_amount" class="form-control<?= $cls('requested_amount') ?>" step="1" min="1" value="<?= $v('requested_amount') ?>" required>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="requested_period_months">Requested Period (months) <span class="text-danger">*</span></label>
                <input type="number" id="requested_period_months" name="requested_period_months" class="form-control<?= $cls('requested_period_months') ?>" step="1" min="1" value="<?= $v('requested_period_months') ?>" required>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="purpose">Purpose</label>
                <textarea id="purpose" name="purpose" rows="2" class="form-control" placeholder="Purpose of this loan…"><?= $v('purpose') ?></textarea>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-shield-check text-warning"></i><h6 class="mb-0 fw-semibold">Product Eligibility Requirements</h6>
    </div>
    <div class="card-body p-4">
        <p class="text-muted small mb-3">Only required for the products that ask for them — Asset Financing (income source, 30% member contribution, security), Start-Up (chattel security), Business Loan (weekly savings commitment). Leave blank for products with no such requirement.</p>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="income_source">Source of Income</label>
                <select id="income_source" name="income_source" class="form-select">
                    <option value="" <?= $v('income_source') === '' ? 'selected' : '' ?>>— Not applicable —</option>
                    <option value="salary" <?= $v('income_source') === 'salary' ? 'selected' : '' ?>>Salary</option>
                    <option value="business" <?= $v('income_source') === 'business' ? 'selected' : '' ?>>Business</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold" for="income_details">Income Details</label>
                <input type="text" id="income_details" name="income_details" class="form-control" value="<?= $v('income_details') ?>" maxlength="255">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="asset_purchase_price">Asset Purchase Price (Shs)</label>
                <div class="input-group"><span class="input-group-text">Shs</span>
                    <input type="number" id="asset_purchase_price" name="asset_purchase_price" class="form-control" step="1" min="0" value="<?= $v('asset_purchase_price') ?>">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="member_contribution">Member Contribution (Shs)</label>
                <div class="input-group"><span class="input-group-text">Shs</span>
                    <input type="number" id="member_contribution" name="member_contribution" class="form-control" step="1" min="0" value="<?= $v('member_contribution') ?>">
                </div>
                <div class="form-text">Asset Financing requires at least 30% of the asset purchase price.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="security_type">Security Type</label>
                <input type="text" id="security_type" name="security_type" class="form-control" value="<?= $v('security_type') ?>" placeholder="e.g. chattel, financed asset" maxlength="100">
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold" for="security_description">Security Description</label>
                <textarea id="security_description" name="security_description" rows="2" class="form-control"><?= $v('security_description') ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="weekly_savings_commitment">Weekly Savings Commitment (Shs)</label>
                <div class="input-group"><span class="input-group-text">Shs</span>
                    <input type="number" id="weekly_savings_commitment" name="weekly_savings_commitment" class="form-control" step="1" min="0" value="<?= $v('weekly_savings_commitment') ?>">
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mb-5">
    <a href="<?= APP_URL ?>/index.php?page=loan-applications" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-warning text-white fw-semibold"><i class="bi bi-save me-1"></i>Save Draft</button>
</div>
</form>

<script>
(function() {
    const memberSearch = document.getElementById('memberSearch');
    const memberDropdown = document.getElementById('memberDropdown');
    const memberIdField = document.getElementById('memberId');
    const memberSelectedLabel = document.getElementById('memberSelectedLabel');
    let debounceTimer = null;

    <?php if (!empty($application['member_id'])): ?>
    memberSelectedLabel.textContent = 'Member selected (id <?= (int)$application['member_id'] ?>).';
    <?php endif; ?>

    memberSearch.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const term = this.value.trim();
        if (term.length < 2) { memberDropdown.style.display = 'none'; return; }
        debounceTimer = setTimeout(function() {
            fetch('<?= APP_URL ?>/index.php?page=loan-member-search&q=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(data => {
                    const members = data.members || [];
                    memberDropdown.innerHTML = '';
                    if (members.length === 0) { memberDropdown.style.display = 'none'; return; }
                    members.forEach(function(m) {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action';
                        item.textContent = m.full_name + ' (' + m.member_number + ')';
                        item.addEventListener('click', function() {
                            memberIdField.value = m.id;
                            memberSearch.value = m.full_name + ' (' + m.member_number + ')';
                            memberSelectedLabel.textContent = 'Selected: ' + m.full_name;
                            memberDropdown.style.display = 'none';
                        });
                        memberDropdown.appendChild(item);
                    });
                    memberDropdown.style.display = 'block';
                });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!memberDropdown.contains(e.target) && e.target !== memberSearch) {
            memberDropdown.style.display = 'none';
        }
    });
})();
</script>
