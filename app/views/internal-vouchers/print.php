<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $isDebit = $voucher['voucher_type'] === 'debit'; $voucherLabel = $isDebit ? 'Internal Debit Voucher' : 'Internal Credit Voucher'; ?>
    <title><?= $voucherLabel ?> <?= htmlspecialchars($voucher['voucher_number']) ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --navy:#1B2B6B; --navy-dark:#0B1229; --orange:#B8892B; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { background:#eef0f5; font-family:'Inter',sans-serif; font-size:.85rem; color:#1f2937; }
        .voucher-wrap { max-width:640px; margin:1.5rem auto; padding:0 1rem; }
        .voucher { background:#fff; border-radius:.5rem; overflow:hidden; box-shadow:0 2px 20px rgba(11,18,41,.1); border:1px solid #e5e7f0; }

        .voucher-header { padding:1.5rem 2rem 1rem; border-bottom:3px solid var(--orange); }
        .header-top { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
        .header-brand { display:flex; align-items:center; gap:.75rem; }
        .header-brand img { width:52px; height:52px; object-fit:contain; }
        .header-brand h1 { font-family:'Space Grotesk',sans-serif; font-size:1rem; font-weight:700; color:var(--navy); text-transform:uppercase; line-height:1.2; }
        .header-brand .slogan { font-size:.6rem; color:#6E7689; font-style:italic; font-weight:500; }
        .header-contact { text-align:right; font-size:.62rem; color:#4b5563; line-height:1.6; }
        .header-contact strong { color:var(--navy); }

        .voucher-type { background:var(--navy); color:#fff; text-align:center; padding:.7rem; font-size:.85rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; }

        .voucher-body { padding:1.5rem 2rem; }
        .voucher-topline { display:flex; justify-content:space-between; margin-bottom:1.25rem; font-size:.8rem; }
        .voucher-topline .label { color:#6b7280; }
        .voucher-topline .value { font-weight:700; color:var(--navy-dark); }

        .voucher-amount { background:linear-gradient(135deg,rgba(27,43,107,.03),rgba(244,121,32,.03)); border:1px solid rgba(244,121,32,.15); border-radius:.4rem; padding:1.25rem; text-align:center; margin-bottom:1.5rem; }
        .voucher-amount .amount-label { font-size:.6rem; font-weight:700; color:var(--navy); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.25rem; }
        .voucher-amount .amount-value { font-family:'Space Grotesk',sans-serif; font-size:1.9rem; font-weight:700; color:var(--navy); }
        .voucher-amount .amount-value .currency { font-size:.85rem; font-weight:500; color:#6B7280; }
        .voucher-amount .amount-words { font-size:.72rem; color:#4b5563; font-style:italic; margin-top:.5rem; }

        .voucher-row { display:grid; grid-template-columns:150px 1fr; gap:0 16px; align-items:baseline; padding:.55rem 0; border-bottom:1px dotted #e5e7f0; }
        .voucher-row:last-child { border-bottom:none; }
        .voucher-row .label { color:#6b7280; font-size:.78rem; white-space:nowrap; }
        .voucher-row .value { font-weight:600; font-size:.82rem; color:var(--navy-dark); }

        .signatures { display:flex; justify-content:space-between; margin-top:2rem; padding-top:1rem; }
        .signatures .sig-block { text-align:center; width:45%; }
        .signatures .signature-line { border-bottom:1px solid #d1d5db; margin-bottom:.35rem; height:2.2rem; }
        .signatures .sig-name { font-size:.68rem; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }

        .voucher-footer { border-top:1px solid #e5e7f0; padding:1rem 2rem; text-align:center; }
        .voucher-footer .barcode { font-family:monospace; font-size:.65rem; color:#c0c4cc; letter-spacing:.1em; }
        .voucher-footer .org-footer { font-size:.6rem; color:#6E7689; margin-top:.5rem; }

        @media print { body{background:#fff;} .voucher-wrap{margin:0;max-width:100%;padding:0;} .voucher{box-shadow:none;border:none;} .no-print{display:none!important;} }
    </style>
</head>
<body>
<?php $v = $voucher; ?>
<div class="voucher-wrap">

    <div class="d-flex gap-2 mb-3 no-print">
        <button onclick="window.print()" class="btn btn-sm flex-grow-1" style="background:var(--navy);color:#fff;border:none;font-size:.8rem;padding:.5rem;">
            <i class="bi bi-printer me-1"></i>Print Voucher
        </button>
        <button onclick="window.close()" class="btn btn-sm" style="background:#f3f4f6;color:#4b5563;border:1px solid #e5e7f0;font-size:.8rem;padding:.5rem .75rem;">
            <i class="bi bi-x-lg me-1"></i>Close
        </button>
    </div>

    <div class="voucher">
        <!-- Header -->
        <div class="voucher-header">
            <div class="header-top">
                <div class="header-brand">
                    <img src="<?= APP_URL ?>/public/images/logo.png" alt="Logo">
                    <div>
                        <h1>Empower<br>Investment Club</h1>
                        <div class="slogan">Unleash your financial potential</div>
                    </div>
                </div>
                <div class="header-contact">
                    <strong>GAYAZA, GITTA, WAKISO</strong><br>
                    TEL: 0702970129 / 0701486161<br>
                    EMAIL: empowerclub2024@gmail.com<br>
                    REG. NO. WCBO/24/24/4290
                </div>
            </div>
        </div>

        <div class="voucher-type"><?= $voucherLabel ?></div>

        <!-- Body -->
        <div class="voucher-body">
            <div class="voucher-topline">
                <div><span class="label">Serial No: </span><span class="value"><?= htmlspecialchars($v['voucher_number']) ?></span></div>
                <div><span class="label">Date: </span><span class="value"><?= date('d/m/Y', strtotime($v['voucher_date'])) ?></span></div>
            </div>

            <div class="voucher-row">
                <span class="label"><?= $isDebit ? 'Debit' : 'Credit' ?></span>
                <span class="value"><?= htmlspecialchars($v['primary_account_name']) ?></span>
            </div>
            <div class="voucher-row">
                <span class="label">A/C No.</span>
                <span class="value"><?= htmlspecialchars($v['primary_account_code']) ?></span>
            </div>
            <div class="voucher-row">
                <span class="label">Being</span>
                <span class="value"><?= nl2br(htmlspecialchars($v['narration'])) ?></span>
            </div>
            <?php if (!empty($v['member_id'])): ?>
            <div class="voucher-row">
                <span class="label">Member Account</span>
                <span class="value"><?= htmlspecialchars($v['member_first_name'] . ' ' . $v['member_last_name'] . ' — ' . $v['savings_account_number']) ?></span>
            </div>
            <?php endif; ?>
            <div class="voucher-row">
                <span class="label"><?= $isDebit ? 'Credit' : 'Debit' ?> Account</span>
                <span class="value"><?= htmlspecialchars($v['contra_account_name'] . ' (' . $v['contra_account_code'] . ')') ?></span>
            </div>

            <div class="voucher-amount">
                <div class="amount-label">Amount</div>
                <div class="amount-value"><span class="currency">Shs</span> <?= number_format((float)$v['amount'], 2) ?></div>
                <div class="amount-words">Amount in Words: <?= htmlspecialchars($amountInWords) ?></div>
            </div>

            <div class="signatures">
                <div class="sig-block">
                    <div class="signature-line"><?= $v['recorded_by_name'] ? htmlspecialchars($v['recorded_by_name']) : '' ?></div>
                    <div class="sig-name">Prepared By</div>
                </div>
                <div class="sig-block">
                    <div class="signature-line"><?= $v['approved_by_name'] ? htmlspecialchars($v['approved_by_name']) : '' ?></div>
                    <div class="sig-name">Approved By</div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="voucher-footer">
            <div class="barcode"><?= htmlspecialchars($v['voucher_number']) ?> · <?= date('YmdHis', strtotime($v['created_at'])) ?></div>
            <div class="org-footer">&copy; <?= date('Y') ?> Empower Investment Club · Gayaza, Gitta, Wakiso</div>
        </div>
    </div>
</div>

<script>
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.addEventListener('load', function() { window.print(); });
}
</script>
</body>
</html>
