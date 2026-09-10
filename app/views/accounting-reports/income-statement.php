<?php
$pageTitle = $pageTitle ?? 'Income Statement';
$title = $pageTitle;
$icon  = 'bi-graph-up-arrow';

$periodLabel = AccountingReportModel::describeFilters($filters, $financialYears, $periods);
$base = APP_URL . '/index.php?page=report-income-statement';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card mb-3 no-print">
        <div class="card-header">Filters</div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="report-income-statement">
                <div class="col-md-3">
                    <label class="form-label small">Financial Year</label>
                    <select name="financial_year_id" class="form-select form-select-sm">
                        <option value="">All financial years</option>
                        <?php foreach ($financialYears as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= (int)($filters['financial_year_id'] ?? 0) === (int)$fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['name']) ?><?= $fy['is_legacy'] ? ' (Legacy — Historical Development/Test Ledger)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Accounting Period</label>
                    <select name="accounting_period_id" class="form-select form-select-sm">
                        <option value="">All periods</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (int)($filters['accounting_period_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">Apply</button>
                    <a href="<?= $base ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?php require VIEW_PATH . '/layouts/report-print-header.php'; ?>

            <div class="d-flex justify-content-end gap-2 mb-3 no-print">
                <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
            </div>

            <h6 class="text-uppercase text-muted small">Income</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                    <tbody>
                        <?php if (empty($report['income'])): ?>
                            <tr><td class="text-muted text-center">No income accounts with activity.</td><td></td></tr>
                        <?php endif; ?>
                        <?php foreach ($report['income'] as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['code'] . ' — ' . $r['name']) ?><?= (int)$r['id'] === 77 ? ' <span class="badge bg-info text-dark">Loan Interest Income</span>' : '' ?></td>
                                <td class="text-end" style="width:180px;"><?= number_format($r['net_amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr><th>Total Income</th><th class="text-end">Shs <?= number_format($report['total_income'], 2) ?></th></tr>
                    </tfoot>
                </table>
            </div>

            <h6 class="text-uppercase text-muted small">Expenses</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                    <tbody>
                        <?php if (empty($report['expense'])): ?>
                            <tr><td class="text-muted text-center">No expense accounts with activity.</td><td></td></tr>
                        <?php endif; ?>
                        <?php foreach ($report['expense'] as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['code'] . ' — ' . $r['name']) ?></td>
                                <td class="text-end" style="width:180px;"><?= number_format($r['net_amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr><th>Total Expenses</th><th class="text-end">Shs <?= number_format($report['total_expense'], 2) ?></th></tr>
                    </tfoot>
                </table>
            </div>

            <div class="alert <?= $report['net_surplus'] >= 0 ? 'alert-success' : 'alert-danger' ?> d-flex justify-content-between">
                <strong>Net Surplus / (Deficit)</strong>
                <strong>Shs <?= number_format($report['net_surplus'], 2) ?></strong>
            </div>
        </div>
    </div>

<style>
@media print { .no-print { display: none !important; } }
</style>
