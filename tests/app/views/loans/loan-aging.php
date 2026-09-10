<?php
$base = APP_URL . '/index.php';
$a = $agingData;
?>

<?php ob_start(); ?>
<button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
    <i class="bi bi-printer me-1"></i>Print
</button>
<button onclick="exportAllLoans()" class="btn btn-outline-success btn-sm no-print">
    <i class="bi bi-file-earmark-excel me-1"></i>Excel
</button>
<a href="<?= $base ?>?page=reports" class="btn btn-outline-secondary btn-sm no-print">
    <i class="bi bi-arrow-left me-1"></i>Back
</a>
<?php $cta = ob_get_clean(); ?>
<?php extract([
    'icon'     => 'bi-hourglass-split text-danger',
    'title'    => 'Loan Aging Report',
    'subtitle' => 'Analyze overdue loans by aging buckets',
    'cta'      => $cta,
]); include VIEW_PATH . '/layouts/page-title.php'; ?>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-6 col-lg-2 col-md-4">
        <div class="stat-card stat-card-success h-100">
            <div class="stat-label">Current</div>
            <div class="stat-value"><?= $a['current']['count'] ?></div>
            <div class="stat-sublabel">Shs <?= number_format($a['current']['amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2 col-md-4">
        <div class="stat-card stat-card-warning h-100">
            <div class="stat-label">1-30 Days</div>
            <div class="stat-value"><?= $a['days_1_30']['count'] ?></div>
            <div class="stat-sublabel">Shs <?= number_format($a['days_1_30']['amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2 col-md-4">
        <div class="stat-card stat-card-danger h-100">
            <div class="stat-label">31-60 Days</div>
            <div class="stat-value"><?= $a['days_31_60']['count'] ?></div>
            <div class="stat-sublabel">Shs <?= number_format($a['days_31_60']['amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2 col-md-4">
        <div class="stat-card stat-card-danger h-100">
            <div class="stat-label">61-90 Days</div>
            <div class="stat-value"><?= $a['days_61_90']['count'] ?></div>
            <div class="stat-sublabel">Shs <?= number_format($a['days_61_90']['amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2 col-md-4">
        <div class="stat-card stat-card-danger h-100">
            <div class="stat-label">90+ Days</div>
            <div class="stat-value"><?= $a['days_90_plus']['count'] ?></div>
            <div class="stat-sublabel">Shs <?= number_format($a['days_90_plus']['amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2 col-md-4">
        <div class="stat-card stat-card-primary h-100">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= $a['total_count'] ?></div>
            <div class="stat-sublabel">Shs <?= number_format($a['total_amount'], 2) ?></div>
        </div>
    </div>
</div>

<!-- Aging Buckets Chart -->
<?php if ($a['total_count'] > 0): ?>
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-bar-chart-fill me-2 text-danger"></i>Aging Buckets Distribution
        </h6>
    </div>
    <div class="card-body">
        <canvas id="agingBucketsChart" height="80"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Detailed Tables by Bucket -->
<?php 
$buckets = [
    'current' => ['label' => 'Current (Not Yet Due)', 'class' => 'success'],
    'days_1_30' => ['label' => '1-30 Days Overdue', 'class' => 'warning'],
    'days_31_60' => ['label' => '31-60 Days Overdue', 'class' => 'danger'],
    'days_61_90' => ['label' => '61-90 Days Overdue', 'class' => 'danger'],
    'days_90_plus' => ['label' => '90+ Days Overdue', 'class' => 'danger'],
];

foreach ($buckets as $key => $bucket):
    if (empty($a[$key]['loans'])) continue;
?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><?= $bucket['label'] ?></h6>
        <span class="badge bg-<?= $bucket['class'] ?>-subtle text-<?= $bucket['class'] ?>">
            <?= count($a[$key]['loans']) ?> loans · Shs <?= number_format($a[$key]['amount'], 2) ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 aging-table">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Loan No.</th>
                    <th>Member</th>
                    <th>Member No.</th>
                    <th class="text-end">Outstanding</th>
                    <th>Due Date</th>
                    <th class="text-end">Days Overdue</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($a[$key]['loans'] as $i => $l): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>" class="fw-semibold text-decoration-none small">
                            <?= htmlspecialchars($l['loan_number']) ?>
                        </a>
                    </td>
                    <td class="fw-semibold small"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($l['member_number']) ?></td>
                    <td class="text-end fw-bold text-danger small">Shs <?= number_format($l['outstanding'], 2) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($l['due_date'])) ?></td>
                    <td class="text-end small">
                        <?php if ($l['days_overdue'] < 0): ?>
                            <span class="text-success"><?= abs($l['days_overdue']) ?> days remaining</span>
                        <?php else: ?>
                            <span class="text-danger fw-bold"><?= $l['days_overdue'] ?> days</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= $l['status'] === 'overdue' ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>">
                            <?= ucfirst($l['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if ($a['total_count'] === 0): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-check-circle text-success" style="font-size:3rem"></i>
        <h5 class="mt-3 text-muted">No Outstanding Loans</h5>
        <p class="text-muted mb-0">All loans are up to date or completed.</p>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function exportAllLoans() {
    const tables = document.querySelectorAll('.aging-table');
    if (tables.length === 0) return;
    
    let csv = ['Bucket,#,Loan No.,Member,Member No.,Outstanding,Due Date,Days Overdue,Status'];
    
    const buckets = ['Current (Not Yet Due)', '1-30 Days Overdue', '31-60 Days Overdue', 
                     '61-90 Days Overdue', '90+ Days Overdue'];
    let bucketIndex = 0;
    
    tables.forEach(table => {
        const bucketName = buckets[bucketIndex++] || 'Unknown';
        table.querySelectorAll('tbody tr').forEach(row => {
            let rowData = [bucketName];
            row.querySelectorAll('td').forEach(col => {
                rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
            });
            csv.push(rowData.join(','));
        });
    });
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'loan_aging_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

// Aging Buckets Chart
<?php if ($a['total_count'] > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('agingBucketsChart');
    if (!ctx) return;
    
    const agingData = {
        current: <?= $a['current']['count'] ?>,
        days_1_30: <?= $a['days_1_30']['count'] ?>,
        days_31_60: <?= $a['days_31_60']['count'] ?>,
        days_61_90: <?= $a['days_61_90']['count'] ?>,
        days_90_plus: <?= $a['days_90_plus']['count'] ?>
    };
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days'],
            datasets: [{
                label: 'Number of Loans',
                data: [
                    agingData.current,
                    agingData.days_1_30,
                    agingData.days_31_60,
                    agingData.days_61_90,
                    agingData.days_90_plus
                ],
                backgroundColor: [
                    'rgba(25, 135, 84, 0.8)',    // Current - Green
                    'rgba(255, 193, 7, 0.8)',    // 1-30 - Yellow/Warning
                    'rgba(255, 152, 0, 0.8)',    // 31-60 - Orange
                    'rgba(220, 53, 69, 0.8)',    // 61-90 - Red
                    'rgba(139, 0, 0, 0.8)'       // 90+ - Dark Red
                ],
                borderColor: [
                    'rgb(25, 135, 84)',
                    'rgb(255, 193, 7)',
                    'rgb(255, 152, 0)',
                    'rgb(220, 53, 69)',
                    'rgb(139, 0, 0)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 13 },
                    callbacks: {
                        label: function(context) {
                            return context.parsed.x + ' loans';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
<?php endif; ?>
</script>

<style>
/* app.css already hides .no-print and flattens .card for print — this page
   additionally needs its stacked bucket cards to not split across pages. */
@media print {
    .card { page-break-inside: avoid; }
}
</style>
