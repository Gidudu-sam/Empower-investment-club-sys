<?php $base = APP_URL . '/index.php'; $s = $summary; ?>

<?php ob_start(); ?>
<button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer me-1"></i>Print</button>
<a href="<?= $base ?>?page=fees" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-arrow-left me-1"></i>Back</a>
<?php $cta = ob_get_clean(); ?>
<?php extract([
    'title'    => 'Fee Revenue Reports',
    'subtitle' => 'Summary of all fees collected',
    'cta'      => $cta,
]); include VIEW_PATH . '/layouts/page-title.php'; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Registration Fees Collected</div>
            <div class="stat-value" style="font-size:1.1rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">Shs</span> <?= number_format($s['registration_collected'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Annual Subscription Collected</div>
            <div class="stat-value" style="font-size:1.1rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">Shs</span> <?= number_format($s['annual_collected'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Loan Processing Fees Collected</div>
            <div class="stat-value" style="font-size:1.1rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">Shs</span> <?= number_format($s['loan_fees_collected'], 0) ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-6">
        <div class="stat-card">
            <div class="stat-label">Total Revenue Collected</div>
            <div class="stat-value" style="font-size:1.3rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">Shs</span> <?= number_format($s['total_collected'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center justify-content-between">
                <div class="stat-label">Outstanding Unpaid Fees</div>
                <span class="stat-dot stat-dot-rust"></span>
            </div>
            <div class="stat-value" style="font-size:1.3rem;color:var(--rust);"><span style="font-size:.7rem;color:var(--slate-soft);">Shs</span> <?= number_format($s['total_outstanding'], 0) ?></div>
        </div>
    </div>
</div>

