<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<h3 class="fw-bold mb-1"><i class="bi bi-file-earmark-text me-2"></i>My Statement</h3>
<p class="text-muted mb-4">Full record for <?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?> (<?= htmlspecialchars($member['member_number'] ?? '') ?>)</p>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Download Statement</h6></div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label small text-muted mb-1" for="stmtPeriod">Period</label>
                <select id="stmtPeriod" class="form-select form-select-sm" style="min-width:170px;">
                    <option value="fy">Financial Year</option>
                    <option value="7d">Last 7 Days</option>
                    <option value="1m">Last 1 Month</option>
                    <option value="3m">Last 3 Months</option>
                    <option value="6m">Last 6 Months</option>
                    <option value="custom">Custom Range</option>
                </select>
            </div>
            <div id="stmtYearWrap">
                <label class="form-label small text-muted mb-1" for="stmtYear">Financial Year</label>
                <select id="stmtYear" class="form-select form-select-sm" style="min-width:180px;">
                    <?php foreach ($years as $y): ?>
                    <option value="<?= (int)$y ?>" <?= $y == $currentFY ? 'selected' : '' ?>><?= htmlspecialchars(StatementModel::fyLabel($y)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="stmtCustomWrap" class="d-none gap-2">
                <div>
                    <label class="form-label small text-muted mb-1" for="stmtFrom">From</label>
                    <input type="date" id="stmtFrom" class="form-control form-control-sm">
                </div>
                <div>
                    <label class="form-label small text-muted mb-1" for="stmtTo">To</label>
                    <input type="date" id="stmtTo" class="form-control form-control-sm">
                </div>
            </div>
            <div>
                <label class="form-label small text-muted mb-1" for="stmtType">Statement Type</label>
                <select id="stmtType" class="form-select form-select-sm" style="min-width:140px;">
                    <option value="savings">Savings</option>
                    <option value="shares">Shares</option>
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-primary" id="downloadStmtBtn">
                <i class="bi bi-download me-1"></i>Download / Print
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" id="emailStmtBtn">
                <i class="bi bi-envelope me-1"></i>Email Me a Copy
            </button>
        </div>
        <div class="small text-muted mt-2">Opens a printable statement — use "Print / Save PDF" there to download. "Email Me a Copy" sends it to <?= htmlspecialchars($member['email'] ?? 'the email on file') ?>.</div>
    </div>
</div>

<form id="emailStmtForm" method="POST" action="<?= APP_URL ?>/index.php?page=portal-statement-email" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="statement_type" id="emailStmtType">
    <input type="hidden" name="year" id="emailStmtYear">
    <input type="hidden" name="range_mode" id="emailStmtRangeMode">
    <input type="hidden" name="date_from" id="emailStmtFrom">
    <input type="hidden" name="date_to" id="emailStmtTo">
</form>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Savings Transactions</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Type</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
                <?php if (empty($savings)): ?>
                <tr><td colspan="4" class="text-center text-muted py-2">None.</td></tr>
                <?php else: foreach ($savings as $t): ?>
                <tr>
                    <td class="small"><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                    <td class="small text-capitalize"><?= htmlspecialchars(str_replace('_',' ',$t['transaction_type'])) ?><?php if ($t['transaction_type'] === 'opening_balance' && !empty($t['notes'])): ?><br><span class="text-muted fst-italic" style="font-size:.68rem;"><?= htmlspecialchars($t['notes']) ?></span><?php endif; ?></td>
                    <td class="text-end small">Shs <?= number_format((float)$t['amount'], 2) ?></td>
                    <td class="text-end small">Shs <?= number_format((float)($t['running_balance'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Loans</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Loan Number</th><th class="text-end">Amount</th><th class="text-end">Outstanding</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="4" class="text-center text-muted py-2">None.</td></tr>
                <?php else: foreach ($loans as $l): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars($l['loan_number']) ?></td>
                    <td class="text-end small">Shs <?= number_format((float)$l['loan_amount'], 2) ?></td>
                    <td class="text-end small">Shs <?= number_format((float)$l['outstanding'], 2) ?></td>
                    <td class="small text-capitalize"><?= htmlspecialchars(str_replace('_',' ',$l['status'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Loan Repayments</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Loan</th><th class="text-end">Amount Paid</th></tr></thead>
            <tbody>
                <?php if (empty($repayments)): ?>
                <tr><td colspan="3" class="text-center text-muted py-2">None.</td></tr>
                <?php else: foreach ($repayments as $r): ?>
                <tr>
                    <td class="small"><?= date('d M Y', strtotime($r['payment_date'])) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($r['loan_number']) ?></td>
                    <td class="text-end small">Shs <?= number_format((float)$r['amount_paid'], 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Fees</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Fee</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($fees)): ?>
                <tr><td colspan="3" class="text-center text-muted py-2">None.</td></tr>
                <?php else: foreach ($fees as $f): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars($f['fee_name']) ?></td>
                    <td class="text-end small">Shs <?= number_format((float)$f['amount'], 2) ?></td>
                    <td class="small text-capitalize"><?= htmlspecialchars($f['status']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var periodSelect = document.getElementById('stmtPeriod');
    var yearWrap      = document.getElementById('stmtYearWrap');
    var customWrap    = document.getElementById('stmtCustomWrap');

    function toggleWrap(el, show) {
        el.classList.toggle('d-none', !show);
        el.classList.toggle('d-flex', show);
    }

    periodSelect.addEventListener('change', function () {
        toggleWrap(yearWrap, this.value === 'fy');
        toggleWrap(customWrap, this.value === 'custom');
    });

    function fmtDate(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    // Shared by both Download and Email — resolves the current Period
    // selection into the same {statement_type, year | range_mode+dates}
    // parameter shape MemberPortalController::statementPrint()/
    // emailStatement() both read via StatementModel::resolvePeriod().
    // Returns null (after alerting) if a custom range is incomplete/invalid.
    function resolveParams() {
        var type   = document.getElementById('stmtType').value;
        var period = periodSelect.value;
        var params = { statement_type: type };

        if (period === 'fy') {
            params.year = document.getElementById('stmtYear').value;
        } else if (period === 'custom') {
            var from = document.getElementById('stmtFrom').value;
            var to   = document.getElementById('stmtTo').value;
            if (!from || !to) { alert('Please choose both a start and end date.'); return null; }
            if (from > to) { alert('The start date must be before the end date.'); return null; }
            params.range_mode = 'custom';
            params.date_from  = from;
            params.date_to    = to;
        } else {
            var to = new Date();
            var from = new Date();
            if (period === '7d') { from.setDate(from.getDate() - 7); }
            else { from.setMonth(from.getMonth() - { '1m': 1, '3m': 3, '6m': 6 }[period]); }
            params.range_mode = 'custom';
            params.date_from  = fmtDate(from);
            params.date_to    = fmtDate(to);
        }
        return params;
    }

    document.getElementById('downloadStmtBtn').addEventListener('click', function () {
        var params = resolveParams();
        if (!params) { return; }
        var url = '<?= APP_URL ?>/index.php?page=portal-statement-print';
        Object.keys(params).forEach(function (k) { url += '&' + k + '=' + encodeURIComponent(params[k]); });
        window.open(url, '_blank');
    });

    document.getElementById('emailStmtBtn').addEventListener('click', function () {
        var params = resolveParams();
        if (!params) { return; }
        document.getElementById('emailStmtType').value      = params.statement_type || '';
        document.getElementById('emailStmtYear').value      = params.year || '';
        document.getElementById('emailStmtRangeMode').value = params.range_mode || '';
        document.getElementById('emailStmtFrom').value      = params.date_from || '';
        document.getElementById('emailStmtTo').value        = params.date_to || '';
        document.getElementById('emailStmtForm').submit();
    });
})();
</script>
