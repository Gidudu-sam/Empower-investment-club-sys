<?php
$base = APP_URL . '/index.php';
$r = $report;
$typeLabels = ['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','custom'=>'Custom Range'];
$typeLabel  = $typeLabels[$type] ?? ucfirst($type);
$periodLabel = match($type) {
    'daily'   => date('d F Y', strtotime($dateFrom ?: date('Y-m-d'))),
    'weekly'  => date('d M', strtotime($dateFrom ?: date('Y-m-d', strtotime('monday this week')))) . ' – ' . date('d M Y', strtotime($dateTo ?: date('Y-m-d', strtotime('sunday this week')))),
    'monthly' => date('F', mktime(0,0,0,$month,1)) . ' ' . $year,
    'custom'  => ($dateFrom && $dateTo) ? date('d M', strtotime($dateFrom)) . ' – ' . date('d M Y', strtotime($dateTo)) : 'Custom',
    default   => 'All Time',
};
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-arrow-down-circle-fill me-2" style="color:#F47920"></i>Loan Repayment Reports
        </h1>
        <p class="text-muted mb-0 small"><?= $typeLabel ?> Report · <?= $periodLabel ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <button onclick="exportTable('repaymentTable','repayment_report')" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </button>
        <a href="<?= $base ?>?page=reports" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Filter Form -->
<div class="card mb-4 no-print">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i>Report Parameters</h6></div>
    <div class="card-body p-4">
        <form method="GET" action="<?= $base ?>" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="report-repayments">
            <div class="col-md-2">
                <label class="form-label fw-semibold">Report Type</label>
                <select name="type" id="repType" class="form-select">
                    <?php foreach ($typeLabels as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2" id="repYearGroup">
                <label class="form-label fw-semibold">Year</label>
                <select name="year" class="form-select">
                    <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2" id="repMonthGroup">
                <label class="form-label fw-semibold">Month</label>
                <select name="month" class="form-select">
                    <?php for ($mo = 1; $mo <= 12; $mo++): ?>
                    <option value="<?= $mo ?>" <?= $month === $mo ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$mo,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2" id="repFromGroup">
                <label class="form-label fw-semibold">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-2" id="repToGroup">
                <label class="form-label fw-semibold">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Total Repayments</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['total_repaid'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-highlight h-100">
            <div class="stat-card-highlight-bar"></div>
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box-lg">
                    <i class="bi bi-percent"></i>
                </div>
                <div>
                    <div class="stat-label">Interest Earned</div>
                    <div class="stat-value" style="font-size:1.1rem;color:#F47920">Shs <?= number_format($r['interest_earned'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-label">Members Who Paid</div>
                    <div class="stat-value"><?= number_format($r['members_paid']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><?= $typeLabel ?> Repayment Report — <?= $periodLabel ?></h6>
        <span class="badge bg-success-subtle text-success"><?= count($r['records']) ?> payments</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="repaymentTable">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Member</th>
                    <th>Loan No.</th>
                    <th class="text-end">Amount Paid</th>
                    <th class="text-end">Outstanding</th>
                    <th>Payment Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($r['records'])): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No repayment records for this period.</td></tr>
                <?php else: foreach ($r['records'] as $i => $rp): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name']) ?></td>
                    <td>
                        <a href="<?= $base ?>?page=loan-view&id=<?= $rp['loan_id'] ?>" class="text-decoration-none small">
                            <?= htmlspecialchars($rp['loan_number']) ?>
                        </a>
                    </td>
                    <td class="text-end fw-bold text-success small">Shs <?= number_format($rp['amount_paid'], 2) ?></td>
                    <td class="text-end small <?= $rp['balance_after'] > 0 ? 'text-danger' : 'text-success' ?>">
                        Shs <?= number_format($rp['balance_after'], 2) ?>
                    </td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($rp['payment_date'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($r['records'])): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="3">TOTAL</td>
                    <td class="text-end text-success">Shs <?= number_format($r['total_repaid'], 2) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
function exportTable(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    let csv = [];
    table.querySelectorAll('tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('td, th').forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""') + '"'));
        csv.push(rowData.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

document.getElementById('repType').addEventListener('change', function() {
    const val = this.value;
    document.getElementById('repYearGroup').style.display = val === 'monthly' ? '' : 'none';
    document.getElementById('repMonthGroup').style.display = val === 'monthly' ? '' : 'none';
    document.getElementById('repFromGroup').style.display = ['daily','weekly','custom'].includes(val) ? '' : 'none';
    document.getElementById('repToGroup').style.display = ['weekly','custom'].includes(val) ? '' : 'none';
});
document.getElementById('repType').dispatchEvent(new Event('change'));
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: 1px solid #dee2e6; }
}
</style>
