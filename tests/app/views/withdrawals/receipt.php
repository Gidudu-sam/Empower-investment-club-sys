<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Receipt <?= htmlspecialchars($withdrawal['withdrawal_number']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body{background:#f4f4f4;font-family:'Inter',sans-serif;}
.receipt-wrap{max-width:480px;margin:2rem auto;}
.receipt{background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12);}
.receipt-header{background:linear-gradient(135deg,#1B2B6B,#253480);color:#fff;padding:2rem 2rem 1.5rem;text-align:center;position:relative;}
.receipt-header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#B8892B,#FFA14D);}
.receipt-row{display:grid;grid-template-columns:160px 1fr;gap:0 16px;align-items:baseline;padding:.45rem 0;border-bottom:1px dashed #e9ecef;font-size:.875rem;}
.receipt-row:last-child{border-bottom:none;}
.receipt-row .label{color:#6c757d;white-space:nowrap;}
.receipt-row .value{font-weight:600;}
.receipt-body{padding:1.75rem 2rem;}
.highlight-box{border-radius:8px;padding:1rem;text-align:center;margin:.5rem 0;}
.receipt-footer{background:#f8f9fa;border-top:1px solid #e9ecef;padding:1rem 2rem;text-align:center;font-size:.78rem;color:#6c757d;}
@media print{body{background:#fff;}.receipt-wrap{margin:0;max-width:100%;}.receipt{box-shadow:none;}.no-print{display:none!important;}}
</style>
</head>
<body>
<?php $w = $withdrawal; $isAnnual = ($w['withdrawal_type'] ?? 'annual_compulsory') === 'annual_compulsory'; ?>
<div class="receipt-wrap">
    <div class="d-flex gap-2 mb-3 no-print">
        <button onclick="window.print()" class="btn btn-success flex-grow-1"><i class="bi bi-printer me-1"></i>Print</button>
        <button onclick="window.close()" class="btn btn-outline-secondary">Close</button>
    </div>
    <div class="receipt">
        <div class="receipt-header">
            <img src="<?=APP_URL?>/public/images/logo.png" alt="Logo"
                 style="height:56px;width:56px;object-fit:contain;filter:brightness(0) invert(1);margin-bottom:.75rem;display:block;margin:0 auto .75rem;">
            <h4 class="fw-bold mb-1"><?= APP_NAME ?></h4>
            <p class="mb-0 small opacity-75"><?= $isAnnual ? 'Annual Compulsory Withdrawal Receipt' : 'Voluntary Withdrawal Receipt' ?></p>
        </div>
        <div class="receipt-body">

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <div class="highlight-box" style="background:#fee2e2;">
                        <div style="font-size:.68rem;text-transform:uppercase;color:#dc2626;font-weight:700;">Cash Paid</div>
                        <div style="font-size:1.6rem;font-weight:800;color:#dc2626;">Shs <?= number_format($w['withdrawal_amount'],2) ?></div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="highlight-box" style="background:#dcfce7;">
                        <div style="font-size:.68rem;text-transform:uppercase;color:#16a34a;font-weight:700;"><?= $isAnnual ? 'Converted to Shares' : 'Share Capital (n/a)' ?></div>
                        <div style="font-size:1.6rem;font-weight:800;color:#16a34a;">Shs <?= number_format($w['retained_amount'],2) ?></div>
                    </div>
                </div>
            </div>

            <div class="receipt-row"><span class="label">Withdrawal No.</span><span class="value text-danger"><?= htmlspecialchars($w['withdrawal_number']) ?></span></div>
            <div class="receipt-row"><span class="label">Type</span><span class="value"><?= $isAnnual ? 'Annual Compulsory' : 'Voluntary' ?></span></div>
            <div class="receipt-row"><span class="label">Member Number</span><span class="value"><?= htmlspecialchars($w['member_number']) ?></span></div>
            <div class="receipt-row"><span class="label">Member Name</span><span class="value"><?= htmlspecialchars($w['first_name'].' '.$w['last_name']) ?></span></div>
            <div class="receipt-row"><span class="label">Financial Year</span><span class="value"><?= $w['financial_year'] ?></span></div>
            <div class="receipt-row"><span class="label">Savings Before</span><span class="value">Shs <?= number_format($w['total_available_savings'],2) ?></span></div>
            <div class="receipt-row"><span class="label">Withdrawal % / Retained %</span><span class="value"><?= $w['withdrawal_percentage'] ?>% / <?= $w['retained_percentage'] ?>%</span></div>
            <div class="receipt-row"><span class="label">Payment Method</span><span class="value"><?= htmlspecialchars($w['payment_method']) ?></span></div>
            <?php if($w['reference_number']): ?>
            <div class="receipt-row"><span class="label">Reference No.</span><span class="value"><?= htmlspecialchars($w['reference_number']) ?></span></div>
            <?php endif; ?>
            <div class="receipt-row"><span class="label">Withdrawal Date</span><span class="value"><?= date('d F Y', strtotime($w['withdrawal_date'])) ?></span></div>
            <div class="receipt-row"><span class="label">Processed By</span><span class="value"><?= htmlspecialchars($w['cashier_name'] ?? 'System') ?></span></div>
            <div class="receipt-row"><span class="label">Issued On</span><span class="value"><?= date('d M Y H:i', strtotime($w['created_at'])) ?></span></div>
        </div>
        <div class="receipt-footer">
            <p class="mb-1">Thank you. This is your official withdrawal receipt.</p>
            <p class="mb-0 opacity-75">&copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; v<?= APP_VERSION ?></p>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</body>
</html>
