<?php
$base = APP_URL . '/index.php';
$r = $report;
$typeLabels = ['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','annual'=>'Annual','financial_year'=>'Financial Year','custom'=>'Custom Range'];
$typeLabel  = $typeLabels[$type] ?? ucfirst($type);
$periodLabel = match($type) {
    'daily'   => date('d F Y', strtotime($dateFrom ?: date('Y-m-d'))),
    'weekly'  => date('d M', strtotime($dateFrom ?: date('Y-m-d', strtotime('monday this week')))) . ' – ' . date('d M Y', strtotime($dateTo ?: date('Y-m-d', strtotime('sunday this week')))),
    'monthly' => date('F', mktime(0,0,0,$month,1)) . ' ' . $year,
    'annual'  => 'Year ' . $year,
    'financial_year' => 'FY ' . $year,
    'custom'  => ($dateFrom && $dateTo) ? date('d M', strtotime($dateFrom)) . ' – ' . date('d M Y', strtotime($dateTo)) : 'Custom',
    default   => 'All Time',
};
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-piggy-bank-fill me-2 text-success"></i>Savings Reports
        </h1>
        <p class="text-muted mb-0 small"><?= $typeLabel ?> Report · <?= $periodLabel ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <button onclick="exportTable('savingsTable','savings_report')" class="btn btn-outline-success btn-sm">
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
            <input type="hidden" name="page" value="report-savings">
            <div class="col-md-2">
                <label class="form-label fw-semibold">Report Type</label>
                <select name="type" id="reportType" class="form-select">
                    <?php foreach ($typeLabels as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2" id="yearGroup">
                <label class="form-label fw-semibold">Year</label>
                <select name="year" class="form-select">
                    <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2" id="monthGroup">
                <label class="form-label fw-semibold">Month</label>
                <select name="month" class="form-select">
                    <?php for ($mo = 1; $mo <= 12; $mo++): ?>
                    <option value="<?= $mo ?>" <?= $month === $mo ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$mo,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2" id="dateFromGroup">
                <label class="form-label fw-semibold">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-2" id="dateToGroup">
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
    <div class="col-6 col-xl-4 col-md-4">
        <div class="stat-card stat-card-success">
            <div class="stat-label">Total Deposits</div>
            <div class="stat-value" style="font-size:1rem">Shs <?= number_format($r['total_amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-4 col-md-4">
        <div class="stat-card stat-card-primary">
            <div class="stat-label">No. of Deposits</div>
            <div class="stat-value"><?= number_format($r['total_deposits']) ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-4 col-md-4">
        <div class="stat-card stat-card-info">
            <div class="stat-label">Average Deposit</div>
            <div class="stat-value" style="font-size:1rem">Shs <?= number_format($r['avg_deposit'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-4 col-md-4">
        <div class="stat-card stat-card-warning">
            <div class="stat-label">Highest Deposit</div>
            <div class="stat-value" style="font-size:1rem">Shs <?= number_format($r['highest_deposit'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-4 col-md-4">
        <div class="stat-card stat-card-danger">
            <div class="stat-label">Lowest Deposit</div>
            <div class="stat-value" style="font-size:1rem">Shs <?= number_format($r['lowest_deposit'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-4 col-md-4">
        <div class="stat-card stat-card-accent">
            <div class="stat-label">Members Saved</div>
            <div class="stat-value"><?= number_format($r['members_saved']) ?></div>
            <div class="stat-sub"><?= number_format($r['members_not_saved']) ?> did not save</div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><?= $typeLabel ?> Savings Report — <?= $periodLabel ?></h6>
        <span class="badge bg-success-subtle text-success"><?= count($r['records']) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="savingsTable">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Member</th>
                    <th>Member No.</th>
                    <th class="text-end">Amount (Shs)</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($r['records'])): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No savings records for this period.</td></tr>
                <?php else: foreach ($r['records'] as $i => $s): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($s['member_number']) ?></td>
                    <td class="text-end fw-bold text-success"><?= number_format($s['amount'], 2) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($s['transaction_date'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($r['records'])): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="3">TOTAL</td>
                    <td class="text-end text-success">Shs <?= number_format($r['total_amount'], 2) ?></td>
                    <td></td>
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

// Toggle filter fields based on report type
document.getElementById('reportType').addEventListener('change', function() {
    const val = this.value;
    document.getElementById('yearGroup').style.display = ['monthly','annual','financial_year'].includes(val) ? '' : 'none';
    document.getElementById('monthGroup').style.display = val === 'monthly' ? '' : 'none';
    document.getElementById('dateFromGroup').style.display = ['daily','weekly','custom'].includes(val) ? '' : 'none';
    document.getElementById('dateToGroup').style.display = ['weekly','custom'].includes(val) ? '' : 'none';
});
document.getElementById('reportType').dispatchEvent(new Event('change'));
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: 1px solid #dee2e6; }
}
</style>
