<?php
/**
 * Email-safe statement NOTIFICATION -- the HTML sent as the email BODY
 * itself (Member Statement Emailing / PDF Attachment stage). The full
 * transaction-level statement now lives in the PDF attachment
 * (statements/pdf.php, rendered by PdfService); this is deliberately
 * short -- a greeting, why they're receiving it, a couple of headline
 * figures, and a pointer to the attachment -- rather than repeating the
 * whole ledger table in both places. Built from the exact same
 * StatementModel::buildMemberStatement() data as the PDF, so the
 * headline figures shown here can never drift from the attached detail.
 *
 * The logo is embedded via CID (src="cid:logo"), not a plain http(s) URL
 * -- a linked image would never load while APP_URL is localhost (no
 * external mail server can fetch that back), and CID is also the most
 * broadly-compatible inline-image mechanism regardless of hosting
 * (Outlook desktop in particular often blocks data: URIs).
 */
$isSharesOnly = ($statementType ?? 'savings') === 'shares';
$m        = $member;
$fullName = $m['first_name'] . ' ' . $m['last_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Statement — <?= htmlspecialchars($fullName) ?></title>
</head>
<body style="margin:0;padding:0;background:#e8eaf0;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e8eaf0;padding:20px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #1B2B6B;border-radius:6px;overflow:hidden;">

    <!-- Header -->
    <tr>
        <td style="padding:20px 24px;border-bottom:4px solid #F47920;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align:middle;">
                    <img src="cid:logo" alt="Empower" width="40" height="40" style="vertical-align:middle;border-radius:6px;">
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
    <tr><td style="padding:28px 24px;">

        <p style="font-size:16px;color:#1e293b;margin:0 0 14px;">Dear <?= htmlspecialchars($fullName) ?>,</p>

        <p style="font-size:14px;color:#475569;margin:0 0 16px;line-height:1.7;">
            As part of keeping your account transparent and up to date, please find attached your
            <strong><?= $isSharesOnly ? 'Shares' : 'Savings' ?> Statement</strong> for
            <strong><?= htmlspecialchars($fyLabel) ?></strong>. This document summarizes your
            <?= $isSharesOnly ? 'share capital position' : 'account activity and balance' ?>
            with Empower Investment Club as Membership No. <strong><?= htmlspecialchars($m['member_number']) ?></strong>,
            generated on <?= htmlspecialchars($dateIssued) ?>.
        </p>

        <!-- Headline figures -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
        <tr>
            <?php if ($isSharesOnly): ?>
            <td width="33%" style="background:#f8f9fc;border:1px solid #e2e8f0;padding:12px;text-align:center;">
                <div style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Shares Held</div>
                <div style="font-size:16px;font-weight:800;color:#1e293b;"><?= number_format($shareCount) ?></div>
            </td>
            <td width="2%"></td>
            <td width="30%" style="background:#f8f9fc;border:1px solid #e2e8f0;padding:12px;text-align:center;">
                <div style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Share Capital</div>
                <div style="font-size:16px;font-weight:800;color:#1e293b;">Shs <?= number_format($shareCapital, 0) ?></div>
            </td>
            <td width="2%"></td>
            <td width="33%" style="background:#fff7ec;border:1px solid #f4dcb8;padding:12px;text-align:center;">
                <div style="font-size:10px;color:#8f6a1e;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Total Assets</div>
                <div style="font-size:16px;font-weight:800;color:#FF7E06;">Shs <?= number_format($totalMemberAssets, 0) ?></div>
            </td>
            <?php else: ?>
            <td width="48%" style="background:#f8f9fc;border:1px solid #e2e8f0;padding:12px;text-align:center;">
                <div style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Opening Balance</div>
                <div style="font-size:16px;font-weight:800;color:#1e293b;">Shs <?= number_format($openingBalance, 0) ?></div>
            </td>
            <td width="4%"></td>
            <td width="48%" style="background:#e4f0e9;border:1px solid #cfe3d8;padding:12px;text-align:center;">
                <div style="font-size:10px;color:#2f6b4f;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Closing Balance</div>
                <div style="font-size:16px;font-weight:800;color:#2f6b4f;">Shs <?= number_format($closingBalance, 0) ?></div>
            </td>
            <?php endif; ?>
        </tr>
        </table>

        <p style="font-size:13px;color:#475569;margin:0 0 4px;line-height:1.7;">
            The full transaction-by-transaction detail is in the attached PDF
            (<strong><?= htmlspecialchars($fullName) ?> — <?= $isSharesOnly ? 'Shares' : 'Savings' ?> Statement.pdf</strong>).
        </p>
        <p style="font-size:13px;color:#475569;margin:0;line-height:1.7;">
            Please review it at your convenience. If anything looks incorrect or you have any questions, reach out any time.
        </p>

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
