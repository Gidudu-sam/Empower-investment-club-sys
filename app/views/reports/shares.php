<?php
$base = APP_URL . '/index.php';
$r = $report;
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-pie-chart-fill me-2 text-info"></i>Share Reports
        </h1>
        <p class="text-muted mb-0 small">Share capital and shareholder analysis</p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <button onclick="exportTable('shareTable','share_report')" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </button>
        <a href="<?= $base ?>?page=reports" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
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
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-primary h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-label">Number of Shareholders</div>
                    <div class="stat-value"><?= number_format($r['total_shareholders']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-success h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-calculator"></i></div>
                <div>
                    <div class="stat-label">Average Shares Per Member</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['avg_shares'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Top Shareholders -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-trophy me-2 text-warning"></i>Top Shareholders</h6>
        <span class="badge bg-info-subtle text-info"><?= count($r['top_shareholders']) ?> shareholders</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="shareTable">
            <thead>
                <tr>
                    <th class="ps-3">Rank</th>
                    <th>Member No.</th>
                    <th>Name</th>
                    <th class="text-end">Total Shares (Shs)</th>
                    <th class="text-end">% of Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($r['top_shareholders'])): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No share data available.</td></tr>
                <?php else: foreach ($r['top_shareholders'] as $i => $sh): 
                    $pct = $r['total_share_capital'] > 0 ? ($sh['total_shares'] / $r['total_share_capital']) * 100 : 0;
                ?>
                <tr>
                    <td class="ps-3">
                        <?php if ($i < 3): ?>
                        <span class="badge <?= ['bg-warning text-dark','bg-secondary','bg-danger-subtle text-danger'][$i] ?> rounded-pill"><?= $i + 1 ?></span>
                        <?php else: ?>
                        <span class="text-muted"><?= $i + 1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold small"><?= htmlspecialchars($sh['member_number']) ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($sh['first_name'] . ' ' . $sh['last_name']) ?></td>
                    <td class="text-end fw-bold text-info">Shs <?= number_format($sh['total_shares'], 2) ?></td>
                    <td class="text-end">
                        <div class="d-flex align-items-center justify-content-end gap-2">
                            <div class="progress flex-grow-1" style="height:6px;max-width:100px;">
                                <div class="progress-bar bg-info" style="width:<?= min(100, $pct) ?>%"></div>
                            </div>
                            <span class="small text-muted"><?= number_format($pct, 1) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($r['top_shareholders'])): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="3">TOTAL SHARE CAPITAL</td>
                    <td class="text-end text-info">Shs <?= number_format($r['total_share_capital'], 2) ?></td>
                    <td class="text-end">100%</td>
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
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: 1px solid #dee2e6; }
}
</style>
