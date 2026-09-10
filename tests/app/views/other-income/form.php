<?php
$pageTitle = $pageTitle ?? 'Record Other Income';
$title = $pageTitle;
$icon  = 'bi-cash-stack';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($flash = Session::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($categories)): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                No active Other Income categories with GL account mappings exist.
                <a href="<?= APP_URL ?>/index.php?page=other-income-categories" class="alert-link">Set up categories first.</a>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= APP_URL ?>/index.php?page=other-income-store" id="incomeForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="row g-4">

            <!-- ── LEFT: Main form ───────────────────────────────────────── -->
            <div class="col-12 col-xl-8">

                <!-- Section 1: What & When -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-tag me-2"></i>Income Details
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="category_id" class="form-label fw-semibold">
                                    Income Category <span class="text-danger">*</span>
                                </label>
                                <select name="category_id" id="category_id" class="form-select" required
                                        <?= empty($categories) ? 'disabled' : '' ?>>
                                    <option value="">— Select Category —</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= $c['id'] ?>"
                                                data-gl="<?= htmlspecialchars($c['account_code'] ?? '') ?>"
                                                data-glname="<?= htmlspecialchars($c['account_name'] ?? '') ?>"
                                                data-hasgl="<?= $c['gl_account_id'] ? '1' : '0' ?>">
                                            <?= htmlspecialchars($c['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="gl_warning" class="form-text text-warning" style="display:none;">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    This category has no GL account mapped. The income can be saved as draft,
                                    but <strong>cannot be posted</strong> to the ledger until a GL account is set
                                    in <a href="<?= APP_URL ?>/index.php?page=other-income-categories" target="_blank">Other Income Categories</a>.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="income_date" class="form-label fw-semibold">
                                    Income Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" name="income_date" id="income_date"
                                       class="form-control" required value="<?= date('Y-m-d') ?>">
                                <div class="form-text">Date the income was received, not necessarily today.</div>
                            </div>

                            <div class="col-12">
                                <label for="description" class="form-label fw-semibold">
                                    Description <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="description" id="description"
                                       class="form-control" required
                                       placeholder="e.g. Sale of old office furniture">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Amount & Payment -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-cash-coin me-2"></i>Amount &amp; Payment
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="amount" class="form-label fw-semibold">
                                    Amount (Shs) <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">Shs</span>
                                    <input type="number" step="1" min="1" name="amount" id="amount"
                                           class="form-control form-control-lg"
                                           placeholder="0" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="payment_method" class="form-label fw-semibold">
                                    Payment Method <span class="text-danger">*</span>
                                </label>
                                <select name="payment_method" id="payment_method" class="form-select form-select-lg" required>
                                    <option value="Cash">Cash</option>
                                    <option value="MTN Mobile Money">MTN Mobile Money</option>
                                    <option value="Airtel Money">Airtel Money</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Supporting Info -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-person-lines-fill me-2"></i>Supporting Information
                        <small class="text-muted fw-normal ms-1">(optional)</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12" id="cashReferenceNote">
                                <label class="form-label fw-semibold">Cash Reference</label>
                                <input type="text" class="form-control" value="Will be generated automatically" disabled readonly>
                            </div>
                            <div class="col-12" id="externalReferenceField" style="display:none;">
                                <label for="reference_number" class="form-label fw-semibold">Reference / Receipt No.</label>
                                <input type="text" name="reference_number" id="reference_number"
                                       class="form-control" placeholder="Receipt or reference number">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg px-4"
                            <?= empty($categories) ? 'disabled' : '' ?>>
                        <i class="bi bi-floppy me-1"></i> Save as Draft
                    </button>
                    <a href="<?= APP_URL ?>/index.php?page=other-income" class="btn btn-outline-secondary btn-lg">
                        Cancel
                    </a>
                </div>
            </div>

            <!-- ── RIGHT: Live summary sidebar ──────────────────────────── -->
            <div class="col-12 col-xl-4">

                <div class="card mb-3 border-primary">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-eye me-2"></i>Summary
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-muted">Category</dt>
                            <dd class="col-7" id="sum_category">—</dd>

                            <dt class="col-5 text-muted">Date</dt>
                            <dd class="col-7" id="sum_date">—</dd>

                            <dt class="col-5 text-muted">Amount</dt>
                            <dd class="col-7 fw-bold fs-6" id="sum_amount">—</dd>

                            <dt class="col-5 text-muted">Via</dt>
                            <dd class="col-7" id="sum_method">—</dd>
                        </dl>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <i class="bi bi-journal-text me-2"></i>Journal Entry Preview
                        <small class="text-muted fw-normal">(on post)</small>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Account</th>
                                    <th class="text-end">Dr</th>
                                    <th class="text-end pe-3">Cr</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="ps-3 text-muted">Cash/Bank (by payment method)</td>
                                    <td class="text-end" id="jnl_dr">—</td>
                                    <td class="text-end pe-3 text-muted">—</td>
                                </tr>
                                <tr>
                                    <td class="ps-3 text-muted" id="jnl_income_acct">Income account</td>
                                    <td class="text-end text-muted">—</td>
                                    <td class="text-end pe-3" id="jnl_cr">—</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>

                <div class="card border-0 bg-light">
                    <div class="card-body small text-muted">
                        <p class="mb-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Other Income is saved as <strong>Draft</strong> first.
                        </p>
                        <p class="mb-2">
                            A treasurer or admin must then <strong>Post</strong> the draft to write it to the accounting ledger.
                        </p>
                        <p class="mb-0">
                            Posting creates a journal entry:
                            <strong>Dr</strong> Cash/Bank (by payment method),
                            <strong>Cr</strong> the category's income GL account.
                        </p>
                    </div>
                </div>

            </div><!-- /sidebar -->
        </div><!-- /row -->
    </form>

<script>
(function () {
    var catSel    = document.getElementById('category_id');
    var datePick  = document.getElementById('income_date');
    var amountIn  = document.getElementById('amount');
    var methodSel = document.getElementById('payment_method');
    var descIn    = document.getElementById('description');

    function fmt(n) {
        if (!n || isNaN(n)) return '—';
        return 'Shs ' + Number(n).toLocaleString('en-UG', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    }

    function update() {
        var catOpt = catSel.options[catSel.selectedIndex];
        var catName = catOpt && catSel.value ? catOpt.text.trim() : '—';
        var glCode  = catOpt && catSel.value ? (catOpt.dataset.gl    || '') : '';
        var glName  = catOpt && catSel.value ? (catOpt.dataset.glname || '') : '';
        var hasGl   = catOpt && catSel.value ? catOpt.dataset.hasgl === '1' : false;

        var glWarning = document.getElementById('gl_warning');
        if (glWarning) {
            glWarning.style.display = (catSel.value && !hasGl) ? '' : 'none';
        }

        document.getElementById('sum_category').textContent = catName;

        var d = datePick.value;
        document.getElementById('sum_date').textContent = d
            ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'})
            : '—';

        var amtVal = amountIn.value;
        document.getElementById('sum_amount').textContent = fmt(amtVal);
        document.getElementById('jnl_dr').textContent     = amtVal ? fmt(amtVal) : '—';
        document.getElementById('jnl_cr').textContent     = amtVal ? fmt(amtVal) : '—';

        document.getElementById('jnl_income_acct').textContent = glCode
            ? glCode + ' — ' + glName
            : 'Select a category above';

        var mOpt = methodSel.options[methodSel.selectedIndex];
        document.getElementById('sum_method').textContent = mOpt ? mOpt.text : '—';
    }

    [catSel, datePick, amountIn, methodSel, descIn].forEach(function (el) {
        if (el) el.addEventListener('input', update);
    });

    update();

    // Cash Reference / External Reference toggle
    var cashNote    = document.getElementById('cashReferenceNote');
    var externalRef = document.getElementById('externalReferenceField');
    function toggleCashReference() {
        if (!cashNote || !externalRef) { return; }
        var isCash = methodSel.value === 'Cash';
        cashNote.style.display    = isCash ? '' : 'none';
        externalRef.style.display = isCash ? 'none' : '';
    }
    if (methodSel) { methodSel.addEventListener('input', toggleCashReference); }
    toggleCashReference();
})();
</script>
