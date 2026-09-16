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
        <div class="card-body p-0">
            <!-- Professional Statement Header (Print Only) -->
            <div class="stmt-print-header">
                <img class="stmt-watermark" src="<?= APP_URL ?>/public/images/logo.png" alt="">
                
                <div class="stmt-header-bar">
                    <div class="stmt-logo-section">
                        <img class="stmt-logo-img" src="<?= APP_URL ?>/public/images/logo.png" alt="Logo">
                        <div class="stmt-logo-divider"></div>
                        <div class="stmt-brand-title">
                            EMPOWER<span>INVESTMENT CLUB</span>
                            <div class="stmt-brand-tagline">Unleash your financial potential</div>
                        </div>
                    </div>
                    <div class="stmt-contact-info">
                        <strong>GAYAZA, GITTA, WAKISO</strong><br>
                        TEL: 0702970129 / 0701486161<br>
                        EMAIL: empowerclub2024@gmail.com<br>
                        REG. NO. WCBO/24/24/4290
                    </div>
                </div>

                <div class="stmt-doc-title-bar">
                    <div class="text-center">
                        <div class="stmt-doc-title">ANNUAL INCOME STATEMENT</div>
                        <div class="stmt-doc-subtitle">For the Period: <?= htmlspecialchars($periodLabel) ?></div>
                    </div>
                </div>
            </div>

            <!-- Content Body -->
            <div class="stmt-content-body">
                <?php require VIEW_PATH . '/layouts/report-print-header.php'; ?>

            <div class="d-flex justify-content-end gap-2 mb-4 no-print">
                <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
            </div>

            <!-- INCOME Section -->
            <div class="financial-section">
                <div class="section-header">INCOME</div>
                <table class="financial-table">
                    <tbody>
                        <?php if (empty($report['income'])): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">No income accounts with activity.</td></tr>
                        <?php else: ?>
                            <?php foreach ($report['income'] as $r): 
                                // Clean up account name - remove code prefix if present
                                $displayName = preg_replace('/^\d+\s*[—-]\s*/', '', $r['name']);
                                $displayName = ucfirst(strtolower($displayName));
                                
                                // Add specific descriptions for key accounts
                                $description = '';
                                if (stripos($displayName, 'loan interest') !== false) {
                                    $description = 'Interest earned on member loans';
                                } elseif (stripos($displayName, 'investment') !== false) {
                                    $description = 'Returns from investments';
                                } elseif (stripos($displayName, 'membership') !== false || stripos($displayName, 'registration') !== false) {
                                    $description = 'Member registration fees';
                                } else {
                                    $description = 'Miscellaneous income';
                                }
                            ?>
                            <tr>
                                <td class="account-name"><?= htmlspecialchars($displayName) ?></td>
                                <td class="account-amount"><?= number_format($r['net_amount'], 0) ?></td>
                                <td class="account-note"><?= htmlspecialchars($description) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td class="total-label">Total Income</td>
                            <td class="total-amount"><?= number_format($report['total_income'], 0) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- OPERATING EXPENSES Section -->
            <div class="financial-section mt-4">
                <div class="section-header">OPERATING EXPENSES</div>
                <table class="financial-table">
                    <tbody>
                        <?php if (empty($report['expense'])): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">No expense accounts with activity.</td></tr>
                        <?php else: ?>
                            <?php foreach ($report['expense'] as $r): 
                                // Clean up account name - remove code prefix if present
                                $displayName = preg_replace('/^\d+\s*[—-]\s*/', '', $r['name']);
                                $displayName = ucfirst(strtolower($displayName));
                                $description = '';
                            ?>
                            <tr>
                                <td class="account-name"><?= htmlspecialchars($displayName) ?></td>
                                <td class="account-amount"><?= number_format($r['net_amount'], 0) ?></td>
                                <td class="account-note"><?= htmlspecialchars($description) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td class="total-label">Total Expenses</td>
                            <td class="total-amount"><?= number_format($report['total_expense'], 0) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Net Surplus/Deficit -->
            <div class="net-result-box <?= $report['net_surplus'] >= 0 ? 'surplus' : 'deficit' ?> mt-4">
                <div class="net-result-label">Net Surplus / (Deficit)</div>
                <div class="net-result-amount"><?= number_format($report['net_surplus'], 0) ?></div>
            </div>
            
            <!-- Statement Footer -->
            <div class="stmt-footer-note">
                <p class="disclaimer">
                    This Income Statement has been generated from Empower Investment Club Management System.
                </p>
                <p class="end-mark">— END OF STATEMENT —</p>
            </div>
            
            </div><!-- /.stmt-content-body -->
        </div>
    </div>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap');

/* Statement Header Styling */
.stmt-print-header {
    position: relative;
    background: #fff;
    z-index: 1;
}
.stmt-watermark {
    display: none; /* Hidden on screen */
}
.stmt-header-bar {
    padding: 16px 20px 12px;
    border-bottom: 4px solid #FF7E06;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
}
.stmt-logo-section {
    display: flex;
    align-items: center;
    gap: 12px;
}
.stmt-logo-img {
    height: 42px;
    width: 42px;
    object-fit: contain;
}
.stmt-logo-divider {
    width: 2px;
    height: 36px;
    background: #E5E7F0;
}
.stmt-brand-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.1rem;
    font-weight: 900;
    color: #1B2B6B;
    text-transform: uppercase;
    line-height: 1.1;
    letter-spacing: 0.02em;
}
.stmt-brand-title span {
    color: #FF7E06;
}
.stmt-brand-tagline {
    font-size: 0.62rem;
    color: #6E7689;
    font-style: italic;
    margin-top: 2px;
}
.stmt-contact-info {
    text-align: right;
    font-size: 0.7rem;
    color: #6B7280;
    line-height: 1.5;
}
.stmt-contact-info strong {
    color: #1B2B6B;
}
.stmt-doc-title-bar {
    padding: 14px 20px;
    background: #fff;
    border-bottom: 1px solid #E5E7F0;
}
.stmt-doc-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1rem;
    font-weight: 800;
    color: #1B2B6B;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin-bottom: 4px;
}
.stmt-doc-subtitle {
    font-size: 0.78rem;
    color: #6E7689;
    font-weight: 500;
}
.stmt-content-body {
    padding: 20px;
    position: relative;
    z-index: 2;
    background: #fff;
}

/* Financial Statement Tables */
.financial-section {
    margin-bottom: 18px;
    position: relative;
    z-index: 3;
}
.section-header {
    background: #dbeafe;
    padding: 8px 14px;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #1B2B6B;
    border: 1px solid #bfdbfe;
    border-bottom: none;
    position: relative;
    z-index: 4;
}
.financial-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    border: 1px solid #E5E7F0;
}
.financial-table tbody tr {
    border-bottom: 1px solid #f3f4f6;
}
.financial-table tbody tr:last-child {
    border-bottom: 1px solid #E5E7F0;
}
.financial-table td {
    padding: 7px 14px;
    vertical-align: top;
}
.account-name {
    width: 40%;
    color: #1e293b;
    font-weight: 500;
}
.account-amount {
    width: 20%;
    text-align: right;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
}
.account-note {
    width: 40%;
    text-align: right;
    color: #64748b;
    font-size: 0.75rem;
    font-style: italic;
}
.financial-table tfoot .total-row {
    background: #dbeafe;
    border-top: 2px solid #1B2B6B;
}
.financial-table tfoot td {
    padding: 10px 14px;
    font-weight: 700;
    border: none;
}
.total-label {
    font-family: 'Space Grotesk', sans-serif;
    text-transform: uppercase;
    font-size: 0.82rem;
    color: #1B2B6B;
}
.total-amount {
    text-align: right;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.95rem;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
}

/* Net Result Box */
.net-result-box {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-radius: 6px;
    border: 2px solid;
    margin-top: 20px;
}
.net-result-box.surplus {
    background: #f0fdf4;
    border-color: #16a34a;
}
.net-result-box.deficit {
    background: #fef2f2;
    border-color: #dc2626;
}
.net-result-label {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.net-result-box.surplus .net-result-label {
    color: #15803d;
}
.net-result-box.deficit .net-result-label {
    color: #b91c1c;
}
.net-result-amount {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 800;
    font-size: 1.2rem;
    font-variant-numeric: tabular-nums;
}
.net-result-box.surplus .net-result-amount {
    color: #15803d;
}
.net-result-box.deficit .net-result-amount {
    color: #b91c1c;
}

.stmt-footer-note {
    text-align: center;
    margin-top: 20px;
    padding-top: 14px;
    border-top: 1px dashed #E5E7F0;
    font-size: 0.7rem;
    color: #6B7280;
    line-height: 1.5;
}
.stmt-footer-note p.disclaimer {
    font-style: italic;
    margin-bottom: 3px;
}
.stmt-footer-note p.end-mark {
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #1B2B6B;
    margin-top: 3px;
}

/* Hide professional header on screen, show on print */
@media screen {
    .stmt-print-header {
        display: none !important;
    }
    .stmt-content-body {
        padding: 1rem;
        position: static !important;
        z-index: auto !important;
    }
    .financial-section {
        position: static !important;
        z-index: auto !important;
    }
    .section-header {
        position: static !important;
        z-index: auto !important;
    }
}

@media print {
    @page {
        size: A4 portrait;
        margin: 12mm 10mm;
    }
    body {
        font-family: 'Inter', sans-serif;
        background: #fff;
        color: #000;
    }
    .no-print {
        display: none !important;
    }
    .stmt-print-header {
        display: block;
    }
    .stmt-watermark {
        display: block;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 320px;
        height: 320px;
        object-fit: contain;
        opacity: 0.04;
        pointer-events: none;
        z-index: 0;
    }
    .stmt-content-body {
        padding: 20px 24px;
        position: relative;
        z-index: 1;
    }
    .card {
        border: 2px solid #1B2B6B !important;
        box-shadow: none !important;
        page-break-inside: auto;
        position: relative !important;
    }
    .card-body {
        position: relative !important;
    }
    /* Keep report print header hidden in print mode */
    .report-print-header {
        display: none !important;
    }
    .financial-section {
        page-break-inside: auto;
    }
    .section-header {
        page-break-after: avoid;
    }
    .financial-table tbody tr {
        page-break-inside: avoid;
    }
}
</style>
