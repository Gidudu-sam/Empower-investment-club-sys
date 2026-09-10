<?php
$pageTitle   = $pageTitle ?? 'Trial Balance';
$title       = $pageTitle;
$icon        = 'bi-list-columns-reverse';
$periodLabel = AccountingReportModel::describeFilters($filters, $financialYears, $periods);
$base        = APP_URL . '/index.php?page=report-trial-balance';
$balanced    = $report['balanced'];

// Group accounts by type for section display
$grouped = [];
$typeLabels = [
    'asset'     => 'Assets',
    'liability' => 'Liabilities',
    'equity'    => 'Equity',
    'income'    => 'Income',
    'expense'   => 'Expenses',
];
foreach ($report['accounts'] as $a) {
    $grouped[$a['type']][] = $a;
}
// Subtotals per type
$subtotals = [];
foreach ($grouped as $type => $rows) {
    $subtotals[$type] = [
        'debit'  => array_sum(array_column($rows, 'debit')),
        'credit' => array_sum(array_column($rows, 'credit')),
    ];
}
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <!-- ── FILTERS ──────────────────────────────────────────────────────── -->
    <div class="card mb-4 no-print">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-funnel me-1"></i> Filters
        </div>
        <div class="card-body pb-2">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="report-trial-balance">

                <div class="col-sm-6 col-lg-3">
                    <label class="form-label fw-semibold small">Financial Year</label>
                    <select name="financial_year_id" class="form-select">
                        <option value="">All financial years</option>
                        <?php foreach ($financialYears as $fy): ?>
                            <option value="<?= $fy['id'] ?>"
                                    <?= (int)($filters['financial_year_id'] ?? 0) === (int)$fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['name']) ?>
                                <?= $fy['is_legacy'] ? ' ★ Legacy' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <label class="form-label fw-semibold small">Accounting Period</label>
                    <select name="accounting_period_id" class="form-select">
                        <option value="">All periods</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                    <?= (int)($filters['accounting_period_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label class="form-label fw-semibold small">From</label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label class="form-label fw-semibold small">To</label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                </div>

                <div class="col-12 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="bi bi-search me-1"></i>Apply
                    </button>
                    <a href="<?= $base ?>" class="btn btn-outline-secondary px-3" title="Reset filters">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- BALANCE STATUS BANNER -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-sm-4">
            <div class="card text-center h-100" style="border-left:4px solid #0d6efd;">
                <div class="card-body py-3">
                    <div class="small mb-1 text-uppercase fw-semibold text-muted" style="letter-spacing:.05em;font-size:.7rem;">Total Debits</div>
                    <div class="h5 fw-bold mb-0 text-primary">Shs <?= number_format($report['total_debit'], 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center h-100" style="border-left:4px solid #6f42c1;">
                <div class="card-body py-3">
                    <div class="small mb-1 text-uppercase fw-semibold text-muted" style="letter-spacing:.05em;font-size:.7rem;">Total Credits</div>
                    <div class="h5 fw-bold mb-0" style="color:#6f42c1;">Shs <?= number_format($report['total_credit'], 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center h-100"
                 style="border-left:4px solid <?= $balanced ? '#198754' : '#dc3545' ?>;background:<?= $balanced ? '#f0fdf4' : '#fff5f5' ?>;">
                <div class="card-body py-3">
                    <div class="small mb-1 text-uppercase fw-semibold text-muted" style="letter-spacing:.05em;font-size:.7rem;">Difference</div>
                    <div class="h5 fw-bold mb-0 <?= $balanced ? 'text-success' : 'text-danger' ?>">
                        <?php if ($balanced): ?>
                            <i class="bi bi-check-circle-fill me-1"></i>Balanced
                        <?php else: ?>
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Shs <?= number_format($report['difference'], 0) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$balanced): ?>
        <div class="alert alert-danger d-flex gap-2 no-print mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
            <div>
                <strong>Trial Balance does not balance.</strong>
                Difference of <strong>Shs <?= number_format($report['difference'], 2) ?></strong>.
                Check for missing or incorrectly posted journal entries.
            </div>
        </div>
    <?php endif; ?>

    <!-- ── REPORT CARD ───────────────────────────────────────────────────── -->
    <div class="card">

        <!-- Print header -->
        <!-- Print header — clean card-body with bottom border -->
        <div class="card-body border-bottom py-3">
            <?php require VIEW_PATH . '/layouts/report-print-header.php'; ?>
        </div>

        <!-- Toolbar -->
        <div class="card-body py-2 border-bottom no-print">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted small">
                    <i class="bi bi-table me-1"></i>
                    <?= count($report['accounts']) ?> account<?= count($report['accounts']) !== 1 ? 's' : '' ?>
                    &nbsp;&middot;&nbsp;
                    <?= htmlspecialchars($periodLabel) ?>
                </span>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <a href="<?= $base . '?' . http_build_query(array_merge(array_filter($filters), ['export' => 'csv'])) ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </a>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="table-dark">
                        <th class="ps-3" style="width:90px">Code</th>
                        <th>Account Name</th>
                        <th style="width:110px">Type</th>
                        <th class="text-end" style="width:150px">Debit</th>
                        <th class="text-end pe-3" style="width:150px">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report['accounts'])): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>
                                No transactions found for the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
                            <?php if (empty($grouped[$typeKey])): continue; endif; ?>

                            <!-- Section header -->
                            <tr class="table-light">
                                <td colspan="5" class="ps-3 fw-semibold text-uppercase small tracking-wide"
                                    style="letter-spacing:.05em; font-size:.72rem; color:#6c757d;">
                                    <?= $typeLabel ?>
                                </td>
                            </tr>

                            <?php foreach ($grouped[$typeKey] as $a): ?>
                                <tr class="<?= ($a['debit'] == 0 && $a['credit'] == 0) ? 'text-muted' : '' ?>">
                                    <td class="ps-3">
                                        <a href="<?= APP_URL ?>/index.php?page=report-general-ledger&account_id=<?= (int)$a['id'] ?>&<?= http_build_query(array_filter($filters)) ?>"
                                           class="text-decoration-none fw-semibold text-primary">
                                            <?= htmlspecialchars($a['code']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($a['name']) ?></td>
                                    <td>
                                        <span class="badge rounded-pill
                                            <?php
                                            echo match($a['type']) {
                                                'asset'     => 'bg-primary-subtle text-primary',
                                                'liability' => 'bg-danger-subtle text-danger',
                                                'equity'    => 'bg-success-subtle text-success',
                                                'income'    => 'bg-info-subtle text-info',
                                                'expense'   => 'bg-warning-subtle text-warning',
                                                default     => 'bg-secondary-subtle text-secondary',
                                            };
                                            ?>">
                                            <?= ucfirst($a['type']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end font-monospace">
                                        <?= $a['debit'] > 0 ? number_format($a['debit'], 0) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="text-end pe-3 font-monospace">
                                        <?= $a['credit'] > 0 ? number_format($a['credit'], 0) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Section subtotal -->
                            <tr class="border-top">
                                <td colspan="3" class="ps-3 text-muted small">
                                    <?= $typeLabel ?> subtotal
                                </td>
                                <td class="text-end font-monospace fw-semibold">
                                    <?= $subtotals[$typeKey]['debit'] > 0 ? number_format($subtotals[$typeKey]['debit'], 0) : '—' ?>
                                </td>
                                <td class="text-end pe-3 font-monospace fw-semibold">
                                    <?= $subtotals[$typeKey]['credit'] > 0 ? number_format($subtotals[$typeKey]['credit'], 0) : '—' ?>
                                </td>
                            </tr>
                            <tr><td colspan="5" class="p-0 border-0" style="height:8px;"></td></tr>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

                <?php if (!empty($report['accounts'])): ?>
                <tfoot>
                    <tr class="table-dark border-0">
                        <th colspan="3" class="ps-3">Grand Total</th>
                        <th class="text-end font-monospace">
                            Shs <?= number_format($report['total_debit'], 0) ?>
                        </th>
                        <th class="text-end pe-3 font-monospace">
                            Shs <?= number_format($report['total_credit'], 0) ?>
                        </th>
                    </tr>
                    <tr class="<?= $balanced ? 'table-success' : 'table-danger' ?> border-0">
                        <th colspan="3" class="ps-3">
                            <?= $balanced
                                ? '<i class="bi bi-check-circle-fill me-1"></i>Balanced'
                                : '<i class="bi bi-exclamation-triangle-fill me-1"></i>NOT Balanced' ?>
                        </th>
                        <th colspan="2" class="text-end pe-3 font-monospace">
                            <?= $balanced
                                ? 'Shs 0 difference'
                                : 'Difference: Shs ' . number_format($report['difference'], 2) ?>
                        </th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

    </div><!-- /card -->

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table-dark th { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; }
    .table-success th { background: #d1e7dd !important; -webkit-print-color-adjust: exact; }
    .table-danger  th { background: #f8d7da !important; -webkit-print-color-adjust: exact; }
    a { color: inherit !important; text-decoration: none !important; }
}
</style>
