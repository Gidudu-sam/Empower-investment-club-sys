<?php
$base = APP_URL . '/index.php';
$s = $stats;
// Loan/repayment/aggregate-financial content on this shared dashboard is
// hidden from Office Administrator -- matches ReportController's own
// requireFinancialReportAccess() gate exactly (that's loan officer/
// treasurer/chairman territory, not Office Administrator's).
$canSeeFinancialReports = Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman']);
?>

<div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-sm-between gap-2 mt-4 mb-4">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-bar-chart-line-fill me-2 text-primary"></i>Reports Dashboard
        </h1>
        <p class="text-muted mb-0 small">Comprehensive overview of club performance</p>
    </div>
    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2">
        <i class="bi bi-calendar3 me-1"></i><?= date('l, d F Y') ?>
    </span>
</div>

<!-- Summary Cards Row 1 -->
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-members" class="text-decoration-none">
            <div class="stat-card stat-card-primary h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="stat-label">Total Members</div>
                        <div class="stat-value"><?= number_format($s['total_members']) ?></div>
                        <div class="stat-sub"><?= number_format($s['active_members']) ?> active · <?= number_format($s['inactive_members']) ?> inactive · <?= number_format($s['dormant_members']) ?> dormant</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-savings" class="text-decoration-none">
            <div class="stat-card stat-card-success h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box stat-icon-success"><i class="bi bi-piggy-bank-fill"></i></div>
                    <div>
                        <div class="stat-label">Total Savings</div>
                        <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($s['total_savings'], 2) ?></div>
                        <div class="stat-sub">All deposits combined</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-savings&type=monthly" class="text-decoration-none">
            <div class="stat-card stat-card-success h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box stat-icon-success"><i class="bi bi-calendar-check-fill"></i></div>
                    <div>
                        <div class="stat-label">This Month's Savings</div>
                        <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($s['month_savings'], 2) ?></div>
                        <div class="stat-sub"><?= date('F Y') ?></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-shares" class="text-decoration-none">
            <div class="stat-card stat-card-info h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box stat-icon-info"><i class="bi bi-pie-chart-fill"></i></div>
                    <div>
                        <div class="stat-label">Total Shares</div>
                        <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($s['total_shares'], 2) ?></div>
                        <div class="stat-sub">Retained share capital</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php if ($canSeeFinancialReports): ?>
    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-loans" class="text-decoration-none">
            <div class="stat-card stat-card-warning h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box stat-icon-warning"><i class="bi bi-bank2"></i></div>
                    <div>
                        <div class="stat-label">Active Loans</div>
                        <div class="stat-value"><?= number_format($s['active_loans']) ?></div>
                        <div class="stat-sub">Shs <?= number_format($s['outstanding_balance'], 2) ?> outstanding</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-withdrawals" class="text-decoration-none">
            <div class="stat-card stat-card-danger h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box stat-icon-danger"><i class="bi bi-box-arrow-up-right"></i></div>
                    <div>
                        <div class="stat-label">Total Withdrawals</div>
                        <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($s['total_withdrawals'], 2) ?></div>
                        <div class="stat-sub">All time cash paid out</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php if ($canSeeFinancialReports): ?>
    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-repayments" class="text-decoration-none">
            <div class="stat-card stat-card-highlight h-100">
                <div class="stat-card-highlight-bar"></div>
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box-lg">
                        <i class="bi bi-currency-exchange"></i>
                    </div>
                    <div>
                        <div class="stat-label">Interest Earned</div>
                        <div class="stat-value" style="font-size:1.1rem;color:#F47920">Shs <?= number_format($s['total_interest'], 2) ?></div>
                        <div class="stat-sub">From all loans</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-success h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Cash Collected Today</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($s['cash_today'], 2) ?></div>
                    <div class="stat-sub">Savings + Repayments</div>
                </div>
            </div>
        </div>
    </div>
    <?php if ($canSeeFinancialReports): ?>
    <div class="col-6 col-xl-3">
        <a href="<?= $base ?>?page=report-financial" class="text-decoration-none">
            <div class="stat-card stat-card-primary h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon-box stat-icon-primary"><i class="bi bi-clipboard-data-fill"></i></div>
                    <div>
                        <div class="stat-label">Financial Summary</div>
                        <div class="stat-value" style="font-size:.9rem"><i class="bi bi-arrow-right"></i> View</div>
                        <div class="stat-sub">Complete club position</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Report Links -->
<div class="row g-4 mb-4">
    <div class="col-xl-4 col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-lightning-charge me-2 text-warning"></i>Quick Reports</h6>
            </div>
            <div class="card-body">
                <!-- 2-per-row on small screens, single column from sm up
                     -- same convention as the Dashboard's Quick Actions. -->
                <div class="row row-cols-2 row-cols-sm-1 g-2">
                    <div class="col">
                        <a href="<?= $base ?>?page=report-members" class="btn btn-outline-primary d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-people-fill"></i> <span>Member Reports</span>
                        </a>
                    </div>
                    <div class="col">
                        <a href="<?= $base ?>?page=report-savings" class="btn btn-outline-success d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-piggy-bank-fill"></i> <span>Savings Reports</span>
                        </a>
                    </div>
                    <?php if ($canSeeFinancialReports): ?>
                    <div class="col">
                        <a href="<?= $base ?>?page=report-loans" class="btn btn-outline-warning d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-bank2"></i> <span>Loan Reports</span>
                        </a>
                    </div>
                    <div class="col">
                        <a href="<?= $base ?>?page=report-repayments" class="btn btn-outline-secondary d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-arrow-down-circle-fill"></i> <span>Repayment Reports</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="col">
                        <a href="<?= $base ?>?page=report-shares" class="btn btn-outline-info d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-pie-chart-fill"></i> <span>Share Reports</span>
                        </a>
                    </div>
                    <div class="col">
                        <a href="<?= $base ?>?page=report-withdrawals" class="btn btn-outline-danger d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-box-arrow-up-right"></i> <span>Withdrawal Reports</span>
                        </a>
                    </div>
                    <?php if ($canSeeFinancialReports): ?>
                    <div class="col">
                        <a href="<?= $base ?>?page=report-financial" class="btn btn-primary d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-clipboard-data-fill"></i> <span>Financial Summary</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="col-xl-8 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-graph-up me-2 text-success"></i>Savings Growth (Last 12 Months)</h6>
            </div>
            <div class="card-body">
                <canvas id="savingsChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<?php if ($canSeeFinancialReports): ?>
<!-- Row: Loan & Repayment Charts -->
<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-bank2 me-2 text-warning"></i>Monthly Loan Issuance</h6>
            </div>
            <div class="card-body">
                <canvas id="loanChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-arrow-down-circle me-2" style="color:#F47920"></i>Loan Repayments</h6>
            </div>
            <div class="card-body">
                <canvas id="repaymentChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Member Growth Chart -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-person-plus-fill me-2 text-primary"></i>Member Growth (Last 12 Months)</h6>
            </div>
            <div class="card-body">
                <canvas id="memberChart" height="120"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartData = <?= json_encode($chartData) ?>;

    // Savings Growth Chart
    if (chartData.savings_growth && chartData.savings_growth.length > 0) {
        new Chart(document.getElementById('savingsChart'), {
            type: 'line',
            data: {
                labels: chartData.savings_growth.map(d => d.label),
                datasets: [{
                    label: 'Savings (Shs)',
                    data: chartData.savings_growth.map(d => parseFloat(d.total)),
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25,135,84,0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'Shs ' + v.toLocaleString() } } }
            }
        });
    }

    // Loan Issuance Chart -- canvas doesn't exist for Office Administrator (this row is hidden for that role)
    if (document.getElementById('loanChart') && chartData.loan_issuance && chartData.loan_issuance.length > 0) {
        new Chart(document.getElementById('loanChart'), {
            type: 'bar',
            data: {
                labels: chartData.loan_issuance.map(d => d.label),
                datasets: [{
                    label: 'Amount Loaned (Shs)',
                    data: chartData.loan_issuance.map(d => parseFloat(d.total)),
                    backgroundColor: 'rgba(255,193,7,0.6)',
                    borderColor: '#ffc107',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'Shs ' + v.toLocaleString() } } }
            }
        });
    }

    // Repayment Chart -- canvas doesn't exist for Office Administrator (this row is hidden for that role)
    if (document.getElementById('repaymentChart') && chartData.repayments && chartData.repayments.length > 0) {
        new Chart(document.getElementById('repaymentChart'), {
            type: 'bar',
            data: {
                labels: chartData.repayments.map(d => d.label),
                datasets: [{
                    label: 'Repayments (Shs)',
                    data: chartData.repayments.map(d => parseFloat(d.total)),
                    backgroundColor: 'rgba(244,121,32,0.6)',
                    borderColor: '#F47920',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'Shs ' + v.toLocaleString() } } }
            }
        });
    }

    // Member Growth Chart
    if (chartData.member_growth && chartData.member_growth.length > 0) {
        new Chart(document.getElementById('memberChart'), {
            type: 'bar',
            data: {
                labels: chartData.member_growth.map(d => d.label),
                datasets: [{
                    label: 'New Members',
                    data: chartData.member_growth.map(d => parseInt(d.new_members)),
                    backgroundColor: 'rgba(13,110,253,0.6)',
                    borderColor: '#0d6efd',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }
});
</script>
