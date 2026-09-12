<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $isWithdrawal = ($saving['transaction_type'] ?? 'deposit') === 'withdrawal'; ?>
    <title>Receipt <?= htmlspecialchars($saving['receipt_number']) ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --navy: #1B2B6B;
            --navy-dark: #0B1229;
            --orange:#B8892B;
            --gold: #B8892B;
            --paper: #FAFBFE;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #eef0f5; font-family: 'Inter', sans-serif; font-size: .85rem; color: #1f2937; }
        .receipt-wrap { max-width: 520px; margin: 1.5rem auto; padding: 0 1rem; }

        .receipt {
            background: #fff;
            border-radius: .5rem;
            overflow: hidden;
            box-shadow: 0 2px 20px rgba(11,18,41,.1);
            border: 1px solid #e5e7f0;
        }

        /* ── HEADER (Headed paper) ─────────────────────── */
        .receipt-header {
            padding: 1.5rem 2rem 1rem;
            border-bottom: 3px solid var(--orange);
            background: #fff;
        }
        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: .75rem;
        }
        .header-brand img {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }
        .header-brand .brand-text h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: var(--navy);
            line-height: 1.2;
            margin: 0;
            text-transform: uppercase;
        }
        .header-brand .brand-text .slogan {
            font-size: .6rem;
            color: #6E7689;
            font-style: italic;
            font-weight: 500;
        }
        .header-contact {
            text-align: right;
            font-size: .62rem;
            color: #4b5563;
            line-height: 1.6;
        }
        .header-contact strong { color: var(--navy); }

        /* Receipt type banner */
        .receipt-type {
            background: var(--navy);
            color: #fff;
            text-align: center;
            padding: .6rem;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        /* ── BODY ──────────────────────────────────────── */
        .receipt-body { padding: 1.5rem 2rem; }

        .receipt-amount {
            background: linear-gradient(135deg, rgba(27,43,107,.03), rgba(244,121,32,.03));
            border: 1px solid rgba(244,121,32,.15);
            border-radius: .4rem;
            padding: 1.25rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .receipt-amount .amount-label {
            font-size: .6rem;
            font-weight: 700;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: .1em;
            margin-bottom: .25rem;
        }
        .receipt-amount .amount-value {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--navy);
            line-height: 1.1;
        }
        .receipt-amount .amount-value .currency {
            font-size: .85rem;
            font-weight: 500;
            color: #6B7280;
        }

        /* Detail rows */
        .receipt-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 0 16px;
            align-items: baseline;
            padding: .5rem 0;
            border-bottom: 1px dotted #e5e7f0;
        }
        .receipt-row:last-child { border-bottom: none; }
        .receipt-row .label { color: #6b7280; font-size: .78rem; white-space: nowrap; }
        .receipt-row .value { font-weight: 600; font-size: .82rem; color: var(--navy-dark); }

        /* ── FOOTER ────────────────────────────────────── */
        .receipt-footer {
            border-top: 1px solid #e5e7f0;
            padding: 1.25rem 2rem;
            text-align: center;
        }
        .receipt-footer .footer-msg {
            font-size: .72rem;
            color: #6b7280;
            margin-bottom: .5rem;
        }
        .receipt-footer .signature-line {
            margin: 1.25rem auto .25rem;
            width: 180px;
            border-bottom: 1px solid #d1d5db;
        }
        .receipt-footer .sig-name {
            font-size: .65rem;
            color: #6E7689;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .receipt-footer .barcode {
            font-family: monospace;
            font-size: .65rem;
            color: #c0c4cc;
            letter-spacing: .1em;
            margin-top: .75rem;
        }
        .receipt-footer .org-footer {
            font-size: .6rem;
            color: #6E7689;
            margin-top: .5rem;
        }

        /* Print */
        @media print {
            body { background: #fff; }
            .receipt-wrap { margin: 0; max-width: 100%; padding: 0; }
            .receipt { box-shadow: none; border: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="receipt-wrap">

    <!-- Print buttons -->
    <div class="d-flex gap-2 mb-3 no-print">
        <button onclick="window.print()" class="btn btn-sm flex-grow-1" style="background:var(--navy);color:#fff;border:none;font-size:.8rem;padding:.5rem;">
            <i class="bi bi-printer me-1"></i>Print Receipt
        </button>
        <button onclick="window.close()" class="btn btn-sm" style="background:#f3f4f6;color:#4b5563;border:1px solid #e5e7f0;font-size:.8rem;padding:.5rem .75rem;">
            <i class="bi bi-x-lg me-1"></i>Close
        </button>
    </div>

    <div class="receipt">

        <!-- ── HEADER — Headed paper ─────────────────────── -->
        <div class="receipt-header">
            <div class="header-top">
                <div class="header-brand">
                    <img src="<?= APP_URL ?>/public/images/logo.png" alt="Logo">
                    <div class="brand-text">
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

        <!-- Receipt type -->
        <div class="receipt-type"><?= $isWithdrawal ? 'Withdrawal Receipt' : 'Savings Receipt' ?></div>

        <!-- ── BODY ──────────────────────────────────────── -->
        <div class="receipt-body">

            <!-- Amount highlight -->
            <div class="receipt-amount">
                <div class="amount-label"><?= $isWithdrawal ? 'Amount Withdrawn' : 'Amount Received' ?></div>
                <div class="amount-value"><span class="currency">Shs</span> <?= number_format($saving['amount_abs'] ?? abs($saving['amount']), 2) ?></div>
            </div>

            <!-- Details -->
            <div class="receipt-row">
                <span class="label">Receipt Number</span>
                <span class="value" style="color:#171B2E;"><?= htmlspecialchars($saving['receipt_number']) ?></span>
            </div>
            <?php if (!empty($saving['account_number'])): ?>
            <div class="receipt-row">
                <span class="label">Savings Account</span>
                <span class="value"><?= htmlspecialchars($saving['account_number']) ?> (<?= htmlspecialchars(ucfirst($saving['account_type'])) ?>)</span>
            </div>
            <?php endif; ?>
            <div class="receipt-row">
                <span class="label">Member Number</span>
                <span class="value"><?= htmlspecialchars($saving['member_number']) ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Member Name</span>
                <span class="value"><?= htmlspecialchars($saving['first_name'] . ' ' . $saving['last_name']) ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Payment Method</span>
                <span class="value"><?= htmlspecialchars($saving['payment_method']) ?></span>
            </div>
            <?php if ($saving['reference_number']): ?>
            <div class="receipt-row">
                <span class="label">Reference No.</span>
                <span class="value"><?= htmlspecialchars($saving['reference_number']) ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-row">
                <span class="label">Transaction Date</span>
                <span class="value"><?= date('d F Y', strtotime($saving['transaction_date'])) ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Financial Year</span>
                <span class="value"><?= htmlspecialchars($saving['financial_year']) ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Received By</span>
                <span class="value"><?= htmlspecialchars($saving['cashier_name'] ?? 'System') ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Date Issued</span>
                <span class="value"><?= date('d M Y, H:i', strtotime($saving['created_at'])) ?></span>
            </div>
        </div>

        <!-- ── FOOTER ────────────────────────────────────── -->
        <div class="receipt-footer">
            <p class="footer-msg"><?= $isWithdrawal ? 'Thank you.' : 'Thank you for your savings contribution.' ?><br>Please keep this receipt for your records.</p>

            <div class="signature-line"></div>
            <div class="sig-name">Authorized Signatory</div>

            <div class="barcode"><?= htmlspecialchars($saving['receipt_number']) ?> · <?= date('YmdHis', strtotime($saving['created_at'])) ?></div>
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
