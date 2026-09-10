<?php $base = APP_URL . '/index.php'; ?>

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

<h3 class="fw-bold mb-1">Welcome, <?= htmlspecialchars($member['first_name'] ?? '') ?></h3>
<p class="text-muted mb-4">Member number <?= htmlspecialchars($member['member_number'] ?? '') ?></p>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-accent">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-accent"><i class="bi bi-piggy-bank-fill"></i></div>
                <div>
                    <div class="stat-label">Savings Balance</div>
                    <div class="stat-value">Shs <?= number_format($savingsBalance, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="stat-label">Active Loan</div>
                    <div class="stat-value" style="font-size:1.1rem">
                        <?= $activeLoan ? 'Shs ' . number_format($activeLoan['outstanding'], 2) . ' due' : 'None' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-warning">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-tag-fill"></i></div>
                <div>
                    <div class="stat-label">Pending Fees</div>
                    <div class="stat-value"><?= count($pendingFees) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-pie-chart-fill"></i></div>
                <div>
                    <div class="stat-label">Shares</div>
                    <div class="stat-value" style="font-size:1.1rem"><?= number_format($sharePosition['count']) ?></div>
                    <div class="stat-sub">Shs <?= number_format($sharePosition['total_retained'], 2) ?> retained</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">Savings Trend</h6>
                <span class="text-muted small">Last 6 months</span>
            </div>
            <div class="card-body">
                <canvas id="savingsTrendChart" height="90"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">Recent Transactions</h6>
        <a href="<?= $base ?>?page=portal-savings" class="small text-decoration-none">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Receipt</th><th>Type</th><th>Method</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
                <?php if (empty($recentTxns)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No transactions on record.</td></tr>
                <?php else: foreach ($recentTxns as $t): ?>
                <tr>
                    <td class="small text-muted"><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                    <td class="small"><?= htmlspecialchars($t['receipt_number'] ?? '—') ?></td>
                    <td class="text-capitalize small"><?= htmlspecialchars(str_replace('_', ' ', $t['transaction_type'])) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($t['payment_method'] ?? '—') ?></td>
                    <td class="text-end <?= (float)$t['amount'] < 0 ? 'text-danger' : 'text-success' ?>">
                        <?= (float)$t['amount'] < 0 ? '-' : '+' ?>Shs <?= number_format(abs((float)$t['amount']), 2) ?>
                    </td>
                    <td class="text-end small">Shs <?= number_format((float)($t['running_balance'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row row-cols-2 g-2">
    <div class="col">
        <a href="<?= $base ?>?page=portal-savings" class="card text-decoration-none text-center h-100 py-3">
            <i class="bi bi-piggy-bank fs-4 text-primary mb-1"></i>
            <div class="small fw-semibold">My Savings</div>
        </a>
    </div>
    <div class="col">
        <a href="<?= $base ?>?page=portal-loans" class="card text-decoration-none text-center h-100 py-3">
            <i class="bi bi-cash-coin fs-4 text-success mb-1"></i>
            <div class="small fw-semibold">My Loans</div>
        </a>
    </div>
    <div class="col">
        <a href="<?= $base ?>?page=portal-fees" class="card text-decoration-none text-center h-100 py-3">
            <i class="bi bi-tag fs-4 text-warning mb-1"></i>
            <div class="small fw-semibold">My Fees</div>
        </a>
    </div>
    <div class="col">
        <a href="<?= $base ?>?page=portal-statement" class="card text-decoration-none text-center h-100 py-3">
            <i class="bi bi-file-earmark-text fs-4 text-secondary mb-1"></i>
            <div class="small fw-semibold">My Statement</div>
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels  = <?= json_encode(array_column($savingsTrend, 'label')) ?>;
    const balance = <?= json_encode(array_map(fn($r) => round($r['balance'], 2), $savingsTrend)) ?>;

    new Chart(document.getElementById('savingsTrendChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Savings Balance',
                data: balance,
                borderColor: '#2F6B4F',
                backgroundColor: 'rgba(47,107,79,.08)',
                borderWidth: 2,
                tension: 0.35,
                fill: true,
                pointRadius: 3,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#2F6B4F',
                pointBorderWidth: 2,
                pointHoverRadius: 5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#334155',
                    titleFont: { weight: 'bold' },
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: ctx => 'Shs ' + ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,.05)' },
                    ticks: { callback: v => 'Shs ' + v.toLocaleString() }
                }
            }
        }
    });
})();
</script>
