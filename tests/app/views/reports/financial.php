<?php
$base = APP_URL . '/index.php';
$r = $report;
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-clipboard-data-fill me-2 text-primary"></i>Financial Summary
        </h1>
        <p class="text-muted mb-0 small">Complete club financial position as at <?= date('d F Y') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <button onclick="exportTable('financialTable','financial_summary')" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </button>
        <a href="<?= $base ?>?page=reports" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Financial Position Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-4">
        <div class="stat-card stat-card-success h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-piggy-bank-fill"></i></div>
                <div>
                    <div class="stat-label">Total Savings</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['total_savings'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="stat-card stat-card-info h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-pie-chart-fill"></i></div>
                <div>
                    <div class="stat-label">Total Share Capital</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['total_share_capital'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="stat-card stat-card-warning h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-bank2"></i></div>
                <div>
                    <div class="stat-label">Total Loans Issued</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['total_loans_issued'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="stat-card stat-card-danger h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-exclamation-circle-fill"></i></div>
                <div>
                    <div class="stat-label">Outstanding Loan Balance</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['outstanding_balance'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
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
    <div class="col-6 col-xl-4">
        <div class="stat-card stat-card-danger h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-box-arrow-up-right"></i></div>
                <div>
                    <div class="stat-label">Withdrawals Paid</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['withdrawals_paid'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Net Club Position -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-primary">
            <div class="card-body text-center py-4">
                <h5 class="text-muted mb-2">Net Club Position</h5>
                <h2 class="fw-bold <?= $r['net_position'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    Shs <?= number_format($r['net_position'], 2) ?>
                </h2>
                <p class="text-muted small mb-0">
                    (Total Savings + Share Capital + Outstanding Loans + Interest) − Withdrawals Paid
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Financial Summary Table -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-table me-2"></i>Financial Position Statement</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="financialTable">
            <thead>
                <tr>
                    <th class="ps-3">Item</th>
                    <th class="text-end pe-3">Amount (Shs)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="ps-3"><i class="bi bi-piggy-bank me-2 text-success"></i>Total Member Savings</td>
                    <td class="text-end pe-3 fw-bold text-success"><?= number_format($r['total_savings'], 2) ?></td>
                </tr>
                <tr>
                    <td class="ps-3"><i class="bi bi-pie-chart me-2 text-info"></i>Total Share Capital (Retained)</td>
                    <td class="text-end pe-3 fw-bold text-info"><?= number_format($r['total_share_capital'], 2) ?></td>
                </tr>
                <tr>
                    <td class="ps-3"><i class="bi bi-bank2 me-2 text-warning"></i>Total Loans Issued (All Time)</td>
                    <td class="text-end pe-3 fw-bold text-warning"><?= number_format($r['total_loans_issued'], 2) ?></td>
                </tr>
                <tr>
                    <td class="ps-3"><i class="bi bi-exclamation-circle me-2 text-danger"></i>Outstanding Loan Balance</td>
                    <td class="text-end pe-3 fw-bold text-danger"><?= number_format($r['outstanding_balance'], 2) ?></td>
                </tr>
                <tr>
                    <td class="ps-3"><i class="bi bi-percent me-2" style="color:#F47920"></i>Interest Earned</td>
                    <td class="text-end pe-3 fw-bold" style="color:#F47920"><?= number_format($r['interest_earned'], 2) ?></td>
                </tr>
                <tr>
                    <td class="ps-3"><i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Total Withdrawals Paid</td>
                    <td class="text-end pe-3 fw-bold text-danger">(<?= number_format($r['withdrawals_paid'], 2) ?>)</td>
                </tr>
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold fs-6">
                    <td class="ps-3">NET CLUB POSITION</td>
                    <td class="text-end pe-3 <?= $r['net_position'] >= 0 ? 'text-success' : 'text-danger' ?>">
                        Shs <?= number_format($r['net_position'], 2) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Monthly Trends Charts -->
<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-graph-up me-2 text-success"></i>Savings Trend (12 Months)</h6>
            </div>
            <div class="card-body">
                <canvas id="savingsTrend" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-arrow-down-circle me-2" style="color:#F47920"></i>Repayments Trend (12 Months)</h6>
            </div>
            <div class="card-body">
                <canvas id="repaymentsTrend" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-bank2 me-2 text-warning"></i>Loans Issued (12 Months)</h6>
            </div>
            <div class="card-body">
                <canvas id="loansTrend" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-person-plus me-2 text-primary"></i>Member Growth (12 Months)</h6>
            </div>
            <div class="card-body">
                <canvas id="membersTrend" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const monthlySavings = <?= json_encode($r['monthly_savings']) ?>;
    const monthlyLoans = <?= json_encode($r['monthly_loans']) ?>;
    const monthlyRepayments = <?= json_encode($r['monthly_repayments']) ?>;
    const monthlyMembers = <?= json_encode($r['monthly_members']) ?>;

    if (monthlySavings.length > 0) {
        new Chart(document.getElementById('savingsTrend'), {
            type: 'line',
            data: {
                labels: monthlySavings.map(d => d.month),
                datasets: [{
                    label: 'Savings (Shs)',
                    data: monthlySavings.map(d => parseFloat(d.total)),
                    borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)',
                    fill: true, tension: 0.3
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'Shs ' + v.toLocaleString() } } } }
        });
    }

    if (monthlyRepayments.length > 0) {
        new Chart(document.getElementById('repaymentsTrend'), {
            type: 'line',
            data: {
                labels: monthlyRepayments.map(d => d.month),
                datasets: [{
                    label: 'Repayments (Shs)',
                    data: monthlyRepayments.map(d => parseFloat(d.total)),
                    borderColor: '#F47920', backgroundColor: 'rgba(244,121,32,0.1)',
                    fill: true, tension: 0.3
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'Shs ' + v.toLocaleString() } } } }
        });
    }

    if (monthlyLoans.length > 0) {
        new Chart(document.getElementById('loansTrend'), {
            type: 'bar',
            data: {
                labels: monthlyLoans.map(d => d.month),
                datasets: [{
                    label: 'Loans Issued (Shs)',
                    data: monthlyLoans.map(d => parseFloat(d.total)),
                    backgroundColor: 'rgba(255,193,7,0.6)', borderColor: '#ffc107', borderWidth: 1
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'Shs ' + v.toLocaleString() } } } }
        });
    }

    if (monthlyMembers.length > 0) {
        new Chart(document.getElementById('membersTrend'), {
            type: 'bar',
            data: {
                labels: monthlyMembers.map(d => d.month),
                datasets: [{
                    label: 'New Members',
                    data: monthlyMembers.map(d => parseInt(d.new_members)),
                    backgroundColor: 'rgba(13,110,253,0.6)', borderColor: '#0d6efd', borderWidth: 1
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
        });
    }
});
</script>

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
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: 1px solid #dee2e6; }
}
</style>
