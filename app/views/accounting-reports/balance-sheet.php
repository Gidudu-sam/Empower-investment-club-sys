<?php
$pageTitle = $pageTitle ?? 'Balance Sheet';
$title = $pageTitle;
$icon  = 'bi-bank';

$periodLabel = AccountingReportModel::describeFilters($filters, $financialYears, $periods);
$base = APP_URL . '/index.php?page=report-balance-sheet';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card mb-3 no-print">
        <div class="card-header">Filters</div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="report-balance-sheet">
                <div class="col-md-4">
                    <label class="form-label small">Financial Year <span class="text-danger">*</span></label>
                    <select name="financial_year_id" class="form-select form-select-sm" required>
                        <option value="">Select a financial year</option>
                        <?php foreach ($financialYears as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= (int)($filters['financial_year_id'] ?? 0) === (int)$fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['name']) ?><?= $fy['is_legacy'] ? ' (Legacy — Historical Development/Test Ledger)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">As Of (To Date)</label>
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

            <?php if (empty($filters['financial_year_id'])): ?>
                <div class="alert alert-info">Select a financial year above to view its Balance Sheet.</div>
            <?php elseif (!$report['openingBalancesEstablished']): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Opening balances have not been established for this financial year.</strong>
                    A Balance Sheet cannot be legitimately presented until an opening balance batch has been prepared,
                    approved and posted through the Opening Balances module. No figures are fabricated here.
                    <div class="small text-muted mt-2">
                        This is not the same thing as a calculation error. A forensic review (Stage 24) found that
                        most of this system's historical loan and savings journal entries are test/development
                        activity, not genuine club transactions — they are preserved for audit purposes but are not
                        treated as authoritative accounting history. Establishing real opening balances requires an
                        accountant-authorized decision, not a report-layer fix. See the accounting policy decision
                        pack for details.
                    </div>
                    <div class="mt-3">
                        <span class="text-muted small d-block mb-2">To resolve this:</span>
                        <ol class="small mb-3 ps-3">
                            <li>Go to Accounting &rsaquo; Opening Balances and <strong>prepare</strong> a batch for this financial year.</li>
                            <li>Have a different authorized user <strong>approve</strong> it.</li>
                            <li><strong>Post</strong> it — only then will this report show figures.</li>
                        </ol>
                        <a href="<?= APP_URL ?>/index.php?page=opening-balance-create" class="btn btn-sm btn-warning">
                            <i class="bi bi-clipboard2-plus me-1"></i>Prepare Opening Balances
                        </a>
                        <a href="<?= APP_URL ?>/index.php?page=opening-balances" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-list-check me-1"></i>View Opening Balance Batches
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-end gap-2 mb-3 no-print">
                    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
                </div>

                <?php if (!$report['balanced']): ?>
                    <div class="alert alert-danger">
                        Balance Sheet does NOT balance — Assets vs Liabilities+Equity differ by Shs <?= number_format($report['difference'], 2) ?>.
                        <div class="small mt-2">
                            This is not necessarily a data error. This system does not yet have a year-end closing
                            mechanism, so any income or expense posted during the period sits outside Assets =
                            Liabilities + Equity until the period is formally closed (a documented, pending
                            accounting-policy item — see Stage 24's accounting baseline report). Check whether the
                            difference above matches the period's net income/expense before treating this as a defect.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="text-uppercase text-muted small">Assets</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                            <tbody>
                                <?php foreach ($report['assets'] as $a): ?>
                                    <?php $isContra = !empty($a['is_contra_asset']); ?>
                                    <tr>
                                        <td><?= $isContra ? 'Less: ' : '' ?><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></td>
                                        <td class="text-end<?= $isContra ? ' text-danger' : '' ?>">
                                            <?= $isContra ? '(' . number_format($a['closing_balance'], 2) . ')' : number_format($a['closing_balance'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light"><tr><th>Total Assets</th><th class="text-end">Shs <?= number_format($report['total_assets'], 2) ?></th></tr></tfoot>
                        </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-uppercase text-muted small">Liabilities</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                            <tbody>
                                <?php foreach ($report['liabilities'] as $a): ?>
                                    <tr><td><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></td><td class="text-end"><?= number_format($a['closing_balance'], 2) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light"><tr><th>Total Liabilities</th><th class="text-end">Shs <?= number_format($report['total_liabilities'], 2) ?></th></tr></tfoot>
                        </table>
                        </div>

                        <h6 class="text-uppercase text-muted small mt-3">Equity</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                            <tbody>
                                <?php foreach ($report['equity'] as $a): ?>
                                    <tr><td><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></td><td class="text-end"><?= number_format($a['closing_balance'], 2) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light"><tr><th>Total Equity</th><th class="text-end">Shs <?= number_format($report['total_equity'], 2) ?></th></tr></tfoot>
                        </table>
                        </div>
                    </div>
                </div>

                <div class="alert <?= $report['balanced'] ? 'alert-success' : 'alert-danger' ?> d-flex justify-content-between mt-3">
                    <strong>Assets = Liabilities + Equity</strong>
                    <strong>
                        Shs <?= number_format($report['total_assets'], 2) ?> =
                        Shs <?= number_format($report['total_liabilities'] + $report['total_equity'], 2) ?>
                        <?= $report['balanced'] ? '(Balanced)' : '(NOT balanced)' ?>
                    </strong>
                </div>
            <?php endif; ?>
        </div>
    </div>

<style>
@media print { .no-print { display: none !important; } }
</style>
