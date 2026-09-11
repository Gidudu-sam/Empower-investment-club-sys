<?php
/**
 * Full Savings/Shares statement, rendered to PDF via PdfService/Dompdf --
 * attached to the statement email rather than sent as its body (Member
 * Statement Emailing / PDF Attachment stage). This is the same
 * table-based, inline-styled markup previously used as the email body
 * itself, repurposed as the PDF's source HTML -- Dompdf renders plain
 * tables reliably (the same reason this shape was chosen for email
 * clients originally), so no third layout was built. Built from the
 * exact same StatementModel::buildMemberStatement() data as every other
 * rendering of a statement in this app; only the markup differs.
 *
 * The logo is a base64 data URI, not a URL or a CID reference -- CID is
 * an email-MIME-only mechanism that means nothing to Dompdf, and
 * PdfService deliberately runs with isRemoteEnabled=false (PDF rendering
 * should never depend on this app's own web server being reachable from
 * itself). Computed once here from the same small pre-resized
 * public/images/logo-email.png used for the email body's CID image.
 */
$isSharesOnly = ($statementType ?? 'savings') === 'shares';
$m        = $member;
$fullName = $m['first_name'] . ' ' . $m['last_name'];
$acctNo   = ltrim($m['member_number'], 'EMP') ?: str_pad((string)$m['id'], 6, '0', STR_PAD_LEFT);
$nationalId = $m['national_id'] ?? '—';
$phone    = $m['phone'] ?? '—';
$address  = $m['address'] ?? $m['present_address'] ?? $m['home_address'] ?? '—';
$logoPath = PUBLIC_PATH . '/images/logo-email.png';
$logoSrc  = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Statement — <?= htmlspecialchars($fullName) ?></title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #1B2B6B;">

    <!-- Header -->
    <tr>
        <td style="padding:20px 24px;border-bottom:4px solid #F47920;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align:middle;">
                    <?php if ($logoSrc): ?><img src="<?= $logoSrc ?>" alt="Empower" width="40" height="40" style="vertical-align:middle;"><?php endif; ?>
                    <span style="font-size:18px;font-weight:900;color:#1B2B6B;vertical-align:middle;padding-left:10px;">EMPOWER<span style="color:#F47920;">INVESTMENT CLUB</span></span>
                </td>
                <td align="right" style="font-size:11px;color:#334155;line-height:1.6;vertical-align:middle;">
                    GAYAZA, GITTA, WAKISO<br>
                    TEL: 0702970129 / 0701486161<br>
                    EMAIL: empowerclub2024@gmail.com
                </td>
            </tr>
            </table>
        </td>
    </tr>

    <!-- Body -->
    <tr><td style="padding:24px;">

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
        <tr><td style="background:#1B2B6B;color:#fff;text-align:center;font-size:12px;font-weight:800;letter-spacing:2px;text-transform:uppercase;padding:9px;">
            <?= $isSharesOnly ? 'Shares Statement' : 'Savings Statement' ?>
        </td></tr>
        </table>

        <!-- Member Information -- matches the on-screen/print statement's info box -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:18px;">
        <tr>
            <td style="padding:14px 18px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:12px;table-layout:fixed;">
                <colgroup>
                    <col style="width:20%;"><col style="width:30%;">
                    <col style="width:20%;"><col style="width:30%;">
                </colgroup>
                <tr>
                    <td style="padding:4px 0;border-bottom:1px dashed #e2e8f0;color:#64748b;font-weight:500;">Member:</td>
                    <td style="padding:4px 12px 4px 8px;border-bottom:1px dashed #e2e8f0;color:#1e293b;font-weight:600;"><?= htmlspecialchars($fullName) ?></td>
                    <td style="padding:4px 0 4px 24px;border-bottom:1px dashed #e2e8f0;color:#64748b;font-weight:500;">Statement Period:</td>
                    <td style="padding:4px 0 4px 8px;border-bottom:1px dashed #e2e8f0;color:#1e293b;font-weight:600;"><?= htmlspecialchars($fyLabel) ?></td>
                </tr>
                <tr>
                    <td style="padding:4px 0;border-bottom:1px dashed #e2e8f0;color:#64748b;font-weight:500;">Membership No:</td>
                    <td style="padding:4px 12px 4px 8px;border-bottom:1px dashed #e2e8f0;color:#1e293b;font-weight:600;"><?= htmlspecialchars($m['member_number']) ?></td>
                    <td style="padding:4px 0 4px 24px;border-bottom:1px dashed #e2e8f0;color:#64748b;font-weight:500;">Generated:</td>
                    <td style="padding:4px 0 4px 8px;border-bottom:1px dashed #e2e8f0;color:#1e293b;font-weight:600;"><?= htmlspecialchars($dateIssued) ?></td>
                </tr>
                <tr>
                    <td style="padding:4px 0;border-bottom:1px dashed #e2e8f0;color:#64748b;font-weight:500;">Account Number:</td>
                    <td style="padding:4px 12px 4px 8px;border-bottom:1px dashed #e2e8f0;color:#1e293b;font-weight:600;"><?= htmlspecialchars($acctNo) ?></td>
                    <td style="padding:4px 0 4px 24px;border-bottom:1px dashed #e2e8f0;color:#64748b;font-weight:500;">Phone:</td>
                    <td style="padding:4px 0 4px 8px;border-bottom:1px dashed #e2e8f0;color:#1e293b;font-weight:600;"><?= htmlspecialchars($phone) ?></td>
                </tr>
                <tr>
                    <td style="padding:4px 0;color:#64748b;font-weight:500;">National ID:</td>
                    <td style="padding:4px 12px 4px 8px;color:#1e293b;font-weight:600;"><?= htmlspecialchars($nationalId) ?></td>
                    <td style="padding:4px 0 4px 24px;color:#64748b;font-weight:500;">Address:</td>
                    <td style="padding:4px 0 4px 8px;color:#1e293b;font-weight:600;"><?= htmlspecialchars($address) ?></td>
                </tr>
            </table>
            </td>
        </tr>
        </table>

        <?php if ($isSharesOnly): ?>
        <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:12px;border:1px solid #cbd5e1;margin-bottom:16px;">
            <tr>
                <th style="background:#1B2B6B;color:#fff;text-align:left;border:1px solid #142057;">Date</th>
                <th style="background:#1B2B6B;color:#fff;text-align:left;border:1px solid #142057;">Description</th>
                <th style="background:#1B2B6B;color:#fff;text-align:right;border:1px solid #142057;">Credit</th>
                <th style="background:#1B2B6B;color:#fff;text-align:right;border:1px solid #142057;">Balance</th>
            </tr>
            <?php if (empty($shareLedgerRows)): ?>
            <tr><td colspan="4" style="text-align:center;color:#64748b;font-style:italic;border:1px solid #e2e8f0;">No share entries recorded.</td></tr>
            <?php else: foreach ($shareLedgerRows as $row): ?>
            <tr>
                <td style="border:1px solid #e2e8f0;"><?= date('d-M-Y', strtotime($row['date'])) ?></td>
                <td style="border:1px solid #e2e8f0;"><?= htmlspecialchars($row['description']) ?></td>
                <td style="border:1px solid #e2e8f0;text-align:right;color:#16a34a;"><?= number_format($row['credit'], 2) ?></td>
                <td style="border:1px solid #e2e8f0;text-align:right;font-weight:700;color:#1e3a8a;"><?= number_format($row['balance'], 2) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </table>

        <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:13px;border:1px solid #cbd5e1;">
            <tr><td style="border:1px solid #e2e8f0;color:#475569;">Shares Held</td><td style="border:1px solid #e2e8f0;text-align:right;font-weight:700;"><?= number_format($shareCount) ?></td></tr>
            <tr><td style="border:1px solid #e2e8f0;color:#475569;">Share Capital</td><td style="border:1px solid #e2e8f0;text-align:right;font-weight:700;">UGX <?= number_format($shareCapital, 2) ?></td></tr>
            <tr><td style="border:1px solid #e2e8f0;color:#1B2B6B;font-weight:800;">Total Assets</td><td style="border:1px solid #e2e8f0;text-align:right;font-weight:900;color:#F47920;">UGX <?= number_format($totalMemberAssets, 2) ?></td></tr>
        </table>

        <?php else: ?>
        <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:12px;border:1px solid #cbd5e1;margin-bottom:16px;">
            <tr>
                <th style="background:#1B2B6B;color:#fff;text-align:left;border:1px solid #142057;">Date</th>
                <th style="background:#1B2B6B;color:#fff;text-align:left;border:1px solid #142057;">Description</th>
                <th style="background:#1B2B6B;color:#fff;text-align:right;border:1px solid #142057;">Debit</th>
                <th style="background:#1B2B6B;color:#fff;text-align:right;border:1px solid #142057;">Credit</th>
                <th style="background:#1B2B6B;color:#fff;text-align:right;border:1px solid #142057;">Balance</th>
            </tr>
            <tr style="background:#f1f5f9;">
                <td style="border:1px solid #e2e8f0;font-weight:600;"><?= htmlspecialchars($fyStart) ?></td>
                <td style="border:1px solid #e2e8f0;font-weight:600;">Opening Balance</td>
                <td style="border:1px solid #e2e8f0;">&mdash;</td>
                <td style="border:1px solid #e2e8f0;">&mdash;</td>
                <td style="border:1px solid #e2e8f0;text-align:right;font-weight:700;color:#1e3a8a;"><?= number_format($openingBalance, 2) ?></td>
            </tr>
            <?php if (empty($transactions)): ?>
            <tr><td colspan="5" style="text-align:center;color:#64748b;font-style:italic;border:1px solid #e2e8f0;padding:12px;">No transactions during this period.</td></tr>
            <?php else: foreach ($transactions as $tx): ?>
            <tr>
                <td style="border:1px solid #e2e8f0;"><?= date('d-M-Y', strtotime($tx['date'])) ?></td>
                <td style="border:1px solid #e2e8f0;"><?= htmlspecialchars($tx['description']) ?><?php if (($tx['type'] ?? null) === 'opening_balance' && !empty($tx['notes'])): ?><br><span style="font-size:10px;color:#64748b;font-style:italic;"><?= htmlspecialchars($tx['notes']) ?></span><?php endif; ?></td>
                <td style="border:1px solid #e2e8f0;text-align:right;<?= $tx['debit'] > 0 ? 'color:#dc2626;' : 'color:#94a3af;' ?>"><?= $tx['debit'] > 0 ? number_format($tx['debit'], 2) : '—' ?></td>
                <td style="border:1px solid #e2e8f0;text-align:right;<?= $tx['credit'] > 0 ? 'color:#16a34a;' : 'color:#94a3af;' ?>"><?= $tx['credit'] > 0 ? number_format($tx['credit'], 2) : '—' ?></td>
                <td style="border:1px solid #e2e8f0;text-align:right;font-weight:700;color:#1e3a8a;"><?= number_format($tx['balance'], 2) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            <tr style="background:#e0f2fe;">
                <td colspan="2" style="border:1px solid #e2e8f0;font-weight:700;color:#0369a1;">Closing Balance</td>
                <td style="border:1px solid #e2e8f0;text-align:right;font-weight:700;color:#dc2626;"><?= number_format($totalDebits, 2) ?></td>
                <td style="border:1px solid #e2e8f0;text-align:right;font-weight:700;color:#16a34a;"><?= number_format($totalCredits, 2) ?></td>
                <td style="border:1px solid #e2e8f0;text-align:right;font-weight:900;color:#1e3a8a;"><?= number_format($closingBalance, 2) ?></td>
            </tr>
        </table>
        <?php endif; ?>

        <p style="font-size:11px;color:#334155;font-style:italic;margin:16px 0 0;">This statement has been generated from Empower Investment Club Management System.</p>
    </td></tr>

    <!-- Notice -->
    <tr><td style="background:#1B2B6B;color:#fff;font-size:11px;padding:12px 24px;line-height:1.7;">
        <strong>IMPORTANT:</strong> Please examine your statement carefully. If we do not hear from you within 28 days, we shall assume the details shown are correct.
        Questions? Contact us at <strong>empowerclub2024@gmail.com</strong> or call <strong>0702970129 / 0701486161</strong>.
    </td></tr>

    <!-- Footer -->
    <tr><td style="text-align:center;padding:12px;border-top:3px solid #F47920;font-size:11px;color:#9ca3af;font-style:italic;">
        Unleash your financial potential
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
