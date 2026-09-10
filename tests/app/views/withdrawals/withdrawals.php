<?php
$base = APP_URL . '/index.php';
$r = $report;
?>

<?php ob_start(); ?>
<button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
    <i class="bi bi-printer me-1"></i>Print
</button>
<button onclick="exportTable('withdrawalTable','withdrawal_report')" class="btn btn-outline-success btn-sm no-print">
    <i class="bi bi-file-earmark-excel me-1"></i>Excel
</button>
<a href="<?= $base ?>?page=reports" class="btn btn-outline-secondary btn-sm no-print">
    <i class="bi bi-arrow-left me-1"></i>Back
</a>
<?php $cta = ob_get_clean(); ?>
<?php extract([
    'icon'     => 'bi-box-arrow-up-right text-danger',
    'title'    => 'Withdrawal Reports',
    'subtitle' => 'Annual Withdrawal Report · Financial Year ' . $year,
    'cta'      => $cta,
]); include VIEW_PATH . '/layouts/page-title.php'; ?>

<!-- Year Filter -->
<div class="card mb-4 no-print">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i>Select Financial Year</h6></div>
    <div class="card-body p-4">
        <form method="GET" action="<?= $base ?>" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="report-withdrawals">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Financial Year</label>
                <select name="year" class="form-select">
                    <?php
                    $years = $r['years'] ?: [(int)date('Y')];
                    if (!in_array((int)date('Y'), $years)) array_unshift($years, (int)date('Y'));
                    foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $year === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-danger h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Total Cash Paid</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['total_withdrawn'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-info h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-pie-chart-fill"></i></div>
                <div>
                    <div class="stat-label">Total Shares Retained</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($r['total_retained'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-primary h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-label">Members Withdrew</div>
                    <div class="stat-value"><?= number_format($r['total_count']) ?></div>
                    <div class="stat-sub"><?= count($r['not_withdrawn']) ?> have not yet withdrawn</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Withdrawals Table -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">Members Who Withdrew — <?= $year ?></h6>
        <span class="badge bg-danger-subtle text-danger"><?= $r['total_count'] ?> withdrawals</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="withdrawalTable">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Member</th>
                    <th>Member No.</th>
                    <th class="text-end">Withdrawal Amount</th>
                    <th class="text-end">Retained Shares</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($r['withdrawals'])): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No withdrawals for <?= $year ?>.</td></tr>
                <?php else: foreach ($r['withdrawals'] as $i => $w): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($w['member_number']) ?></td>
                    <td class="text-end fw-bold text-danger small">Shs <?= number_format($w['withdrawal_amount'], 2) ?></td>
                    <td class="text-end fw-bold text-info small">Shs <?= number_format($w['retained_amount'], 2) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($w['withdrawal_date'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($r['withdrawals'])): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="3">TOTALS</td>
                    <td class="text-end text-danger">Shs <?= number_format($r['total_withdrawn'], 2) ?></td>
                    <td class="text-end text-info">Shs <?= number_format($r['total_retained'], 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Members Who Have Not Withdrawn -->
<?php if (!empty($r['not_withdrawn'])): ?>
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2 text-warning"></i>Members Who Have Not Yet Withdrawn — <?= $year ?></h6>
        <span class="badge bg-warning-subtle text-warning"><?= count($r['not_withdrawn']) ?> members</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Member No.</th>
                    <th>Name</th>
                    <th class="text-end">Total Savings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['not_withdrawn'] as $i => $m): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($m['member_number']) ?></td>
                    <td class="small"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></td>
                    <td class="text-end text-success fw-bold small">Shs <?= number_format($m['total_savings'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

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


