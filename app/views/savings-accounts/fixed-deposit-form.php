<?php
$pageTitle = $pageTitle ?? 'Open Fixed Deposit Account';
$title     = $pageTitle;
$icon      = 'bi-bank';
$base      = APP_URL . '/index.php';
require_once VIEW_PATH . '/savings-accounts/partials/member-search-field.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-safe me-2 text-primary"></i>Open Fixed Deposit Account
                </div>
                <div class="card-body">
                    <div class="alert alert-info small d-flex gap-2 mb-4">
                        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                        <span>
                            A Fixed Deposit is opened with a single lump-sum principal for a fixed term.
                            No further deposits (top-ups) are accepted, and the principal cannot be
                            withdrawn before maturity. Simple interest only, at the agreed annual rate —
                            never compounded.
                        </span>
                    </div>

                    <form method="POST" action="<?= $base ?>?page=savings-account-fixed-deposit-store" id="fdForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Member <span class="text-danger">*</span>
                            </label>
                            <?= memberSearchField('member_id', 'fd', 'Search by name or member number…') ?>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Principal Amount (Shs) <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">Shs</span>
                                    <input type="number" id="fdPrincipal" name="principal_amount" class="form-control"
                                           min="1" step="0.01" placeholder="e.g. 5000000" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Deposit Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" id="fdDepositDate" name="deposit_date" class="form-control"
                                       value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Term <span class="text-danger">*</span>
                                </label>
                                <select id="fdTerm" name="term_months" class="form-select" required>
                                    <?php foreach ($terms as $t): ?>
                                        <option value="<?= $t ?>" <?= $t === 12 ? 'selected' : '' ?>><?= $t ?> Months</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Interest Rate (% per annum) <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number" id="fdRate" name="interest_rate" class="form-control"
                                           min="0" step="0.001" value="<?= htmlspecialchars((string)$defaultRate) ?>" required>
                                    <span class="input-group-text">% p.a.</span>
                                </div>
                                <div class="form-text">Suggested default: <?= htmlspecialchars((string)$defaultRate) ?>% p.a. Editable — the rate agreed here is locked to this account permanently; changing the default later never affects it.</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <?php foreach (['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'] as $m): ?>
                                    <option value="<?= $m ?>"><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-safe me-1"></i>Open Fixed Deposit
                            </button>
                            <a href="<?= $base ?>?page=savings-account-open" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Live preview — UX only. The server independently recalculates
             and validates everything on submit; nothing shown here is ever
             trusted from the browser. ─────────────────────────────────── -->
        <div class="col-12 col-lg-5">
            <div class="card border-warning border-opacity-25">
                <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
                    <i class="bi bi-calculator text-warning"></i>
                    <h6 class="mb-0 fw-semibold">Maturity Preview</h6>
                </div>
                <div class="card-body p-4">
                    <dl class="row mb-0 small">
                        <dt class="col-7 text-muted">Principal</dt>
                        <dd class="col-5 fw-bold" id="fdPrevPrincipal">Shs 0.00</dd>

                        <dt class="col-7 text-muted">Interest Rate</dt>
                        <dd class="col-5" id="fdPrevRate">0% p.a.</dd>

                        <dt class="col-7 text-muted">Term</dt>
                        <dd class="col-5" id="fdPrevTerm">—</dd>

                        <dt class="col-7 text-muted">Deposit Date</dt>
                        <dd class="col-5" id="fdPrevDeposit">—</dd>

                        <dt class="col-7 text-muted fw-semibold">Maturity Date</dt>
                        <dd class="col-5 fw-semibold text-danger" id="fdPrevMaturityDate">—</dd>

                        <hr class="my-2">

                        <dt class="col-7 text-muted">Expected Interest</dt>
                        <dd class="col-5 fw-semibold text-success" id="fdPrevInterest">Shs 0.00</dd>

                        <dt class="col-7 text-muted fw-bold">Expected Maturity Amount</dt>
                        <dd class="col-5 fw-bold fs-6 text-warning" id="fdPrevMaturity">Shs 0.00</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

<?php require VIEW_PATH . '/savings-accounts/partials/member-search-js.php'; ?>

<script>
(function () {
    function fmt(n) { return (Math.round(n * 100) / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function addMonths(dateStr, months) {
        var d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d.getTime())) return null;
        var day = d.getDate();
        d.setMonth(d.getMonth() + months);
        // Calendar-aware month-end handling (e.g. 31 Jan + 1 month lands on
        // 28/29 Feb, not 3 Mar) -- mirrors the server's DateTime::modify()
        // behavior for the preview only; the server figure is authoritative.
        if (d.getDate() !== day) { d.setDate(0); }
        return d;
    }
    function formatDate(d) {
        if (!d) return '—';
        return d.toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'});
    }

    var principalEl = document.getElementById('fdPrincipal');
    var depositDateEl = document.getElementById('fdDepositDate');
    var termEl = document.getElementById('fdTerm');
    var rateEl = document.getElementById('fdRate');

    function recalc() {
        var principal = parseFloat(principalEl.value) || 0;
        var termMonths = parseInt(termEl.value, 10) || 0;
        var rate = parseFloat(rateEl.value) || 0;
        var depositDate = depositDateEl.value;

        var interest = principal * (rate / 100) * (termMonths / 12);
        var maturityAmount = principal + interest;
        var maturityDate = depositDate ? addMonths(depositDate, termMonths) : null;

        document.getElementById('fdPrevPrincipal').textContent = 'Shs ' + fmt(principal);
        document.getElementById('fdPrevRate').textContent = rate + '% p.a.';
        document.getElementById('fdPrevTerm').textContent = termMonths + ' Months';
        document.getElementById('fdPrevDeposit').textContent = depositDate ? formatDate(new Date(depositDate + 'T00:00:00')) : '—';
        document.getElementById('fdPrevMaturityDate').textContent = formatDate(maturityDate);
        document.getElementById('fdPrevInterest').textContent = 'Shs ' + fmt(interest);
        document.getElementById('fdPrevMaturity').textContent = 'Shs ' + fmt(maturityAmount);
    }

    [principalEl, depositDateEl, termEl, rateEl].forEach(function (el) {
        el.addEventListener('input', recalc);
        el.addEventListener('change', recalc);
    });
    recalc();
})();
</script>
