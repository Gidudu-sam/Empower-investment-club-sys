<?php $base = APP_URL . '/index.php'; $s = $summary; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Fee Revenue Reports</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Summary of all fees collected</p>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
        <a href="<?= $base ?>?page=fees" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Registration Fees Collected</div>
            <div class="stat-value" style="font-size:1.1rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($s['registration_collected'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Annual Subscription Collected</div>
            <div class="stat-value" style="font-size:1.1rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($s['annual_collected'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Loan Processing Fees Collected</div>
            <div class="stat-value" style="font-size:1.1rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($s['loan_fees_collected'], 0) ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-6">
        <div class="stat-card">
            <div class="stat-label">Total Revenue Collected</div>
            <div class="stat-value" style="font-size:1.3rem;color:var(--green);"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($s['total_collected'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center justify-content-between">
                <div class="stat-label">Outstanding Unpaid Fees</div>
                <span class="stat-dot stat-dot-rust"></span>
            </div>
            <div class="stat-value" style="font-size:1.3rem;color:var(--rust);"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($s['total_outstanding'], 0) ?></div>
        </div>
    </div>
</div>

<style>@media print { .no-print { display: none !important; } }</style>
