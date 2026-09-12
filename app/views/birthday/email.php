<?php
/**
 * Birthday email template — standalone HTML email body.
 *
 * Rendered by BirthdayController via Controller::renderToString()
 * and by birthday-cli.php via cli_renderBirthdayEmail().
 *
 * Variables injected by caller:
 *   $member    — full member row from members table
 *   $firstName — member first name
 *   $fullName  — member full name
 *
 * SECURITY RULES:
 *   - No account balances, loan amounts, savings data, or financial info
 *   - No member IDs, account numbers, or internal system references
 *   - All output HTML-escaped
 *
 * DESIGN NOTES (redesigned):
 *   - Greeting IS the hero — largest visual element
 *   - Logo is small, secondary, bottom-anchored branding
 *   - Contact details moved to quiet footer only
 *   - 4-colour palette: deep navy, warm gold, white, light cream
 *   - No emoji mixed with corporate wordmark
 *   - Generous whitespace, single decorative accent line
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Happy Birthday, <?= htmlspecialchars($firstName ?? '') ?>!</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f8;font-family:Georgia,'Times New Roman',Times,serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background:#f0f2f8;padding:32px 16px;">
<tr><td align="center">

<!-- ── Outer card ──────────────────────────────────────────────────── -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="max-width:580px;background:#ffffff;
              border-radius:8px;overflow:hidden;
              box-shadow:0 4px 24px rgba(11,18,41,0.10);">

    <!-- ── Thin gold top accent bar ───────────────────────────────── -->
    <tr>
        <td style="background:#B8892B;height:4px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>

    <!-- ── Minimal brand strip ────────────────────────────────────── -->
    <tr>
        <td style="padding:20px 32px 0;text-align:left;">
            <table role="presentation" cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align:middle;padding-right:10px;">
                    <img src="cid:logo" alt="Empower Investment Club"
                         width="32" height="32"
                         style="display:block;border-radius:5px;">
                </td>
                <td style="vertical-align:middle;">
                    <span style="font-family:Arial,Helvetica,sans-serif;
                                 font-size:13px;font-weight:700;
                                 color:#0B1229;letter-spacing:.06em;
                                 text-transform:uppercase;">Empower</span>
                    <span style="font-family:Arial,Helvetica,sans-serif;
                                 font-size:13px;font-weight:400;
                                 color:#B8892B;letter-spacing:.04em;
                                 text-transform:uppercase;"> Investment Club</span>
                </td>
            </tr>
            </table>
        </td>
    </tr>

    <!-- ── Hero greeting ──────────────────────────────────────────── -->
    <tr>
        <td style="padding:36px 32px 8px;text-align:center;">

            <!-- Gold accent divider above name -->
            <table role="presentation" cellpadding="0" cellspacing="0"
                   style="margin:0 auto 20px;">
            <tr>
                <td style="width:40px;border-top:2px solid #B8892B;vertical-align:middle;"></td>
                <td style="padding:0 12px;font-size:16px;color:#B8892B;
                           vertical-align:middle;line-height:1;">&#10022;</td>
                <td style="width:40px;border-top:2px solid #B8892B;vertical-align:middle;"></td>
            </tr>
            </table>

            <!-- Member name — the primary hero element -->
            <p style="margin:0 0 6px;
                      font-family:Georgia,'Times New Roman',Times,serif;
                      font-size:34px;font-weight:700;
                      color:#0B1229;line-height:1.15;
                      letter-spacing:-.01em;">
                <?= htmlspecialchars($firstName ?? '') ?>
            </p>

            <!-- Birthday message — secondary headline -->
            <p style="margin:0 0 24px;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:14px;font-weight:600;
                      color:#B8892B;letter-spacing:.12em;
                      text-transform:uppercase;">
                Happy Birthday
            </p>

        </td>
    </tr>

    <!-- ── Navy accent band with year ────────────────────────────── -->
    <tr>
        <td style="background:#0B1229;padding:22px 32px;text-align:center;">
            <p style="margin:0;
                      font-family:Georgia,'Times New Roman',Times,serif;
                      font-size:20px;font-weight:700;color:#ffffff;
                      line-height:1.3;">
                Wishing you a wonderful birthday
            </p>
            <p style="margin:8px 0 0;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:12px;color:#8a96b8;
                      letter-spacing:.06em;text-transform:uppercase;">
                From the Empower Investment Club Family
            </p>
        </td>
    </tr>

    <!-- ── Body copy ──────────────────────────────────────────────── -->
    <tr>
        <td style="padding:36px 36px 28px;">

            <p style="margin:0 0 18px;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:15px;color:#1e293b;line-height:1.75;">
                Dear <?= htmlspecialchars($firstName ?? '') ?>,
            </p>

            <p style="margin:0 0 18px;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:14px;color:#475569;line-height:1.8;">
                On behalf of everyone at Empower Investment Club, we wish you
                a joyful and memorable birthday. Today is a special day — a moment
                to celebrate you and everything you bring to our community.
            </p>

            <p style="margin:0 0 18px;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:14px;color:#475569;line-height:1.8;">
                Your commitment and presence as a member inspire us, and we are
                genuinely grateful to have you as part of the Empower family.
                We look forward to growing and succeeding <strong>together</strong>
                for many years to come.
            </p>

            <p style="margin:0 0 28px;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:14px;color:#475569;line-height:1.8;">
                May this new year of your life bring you good health, happiness,
                and the fulfilment of every goal you have set your heart on.
            </p>

            <!-- Sign-off -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="border-left:3px solid #B8892B;
                            padding:12px 16px;
                            background:#faf8f3;
                            border-radius:0 4px 4px 0;">
                    <p style="margin:0 0 3px;
                               font-family:Georgia,'Times New Roman',Times,serif;
                               font-size:14px;font-weight:700;color:#0B1229;">
                        Warm wishes,
                    </p>
                    <p style="margin:0;
                               font-family:Arial,Helvetica,sans-serif;
                               font-size:13px;color:#64748b;">
                        The Empower Investment Club Team
                    </p>
                </td>
            </tr>
            </table>

        </td>
    </tr>

    <!-- ── Thin gold divider ──────────────────────────────────────── -->
    <tr>
        <td style="padding:0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="border-top:1px solid #e8e0cc;font-size:0;line-height:0;">&nbsp;</td></tr>
            </table>
        </td>
    </tr>

    <!-- ── Quiet footer ───────────────────────────────────────────── -->
    <tr>
        <td style="padding:16px 36px 24px;text-align:center;">
            <p style="margin:0;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:10px;color:#94a3b8;
                      line-height:1.7;letter-spacing:.03em;">
                <strong style="color:#6b7280;">Empower Investment Club</strong>
                &nbsp;&bull;&nbsp; Gayaza, Gitta, Wakiso
                &nbsp;&bull;&nbsp; Tel: 0702970129 / 0701486161
                &nbsp;&bull;&nbsp; empowerclub2024@gmail.com
            </p>
            <p style="margin:6px 0 0;
                      font-family:Arial,Helvetica,sans-serif;
                      font-size:10px;color:#b0bac8;
                      font-style:italic;">
                Unleash your financial potential
            </p>
        </td>
    </tr>

    <!-- ── Thin gold bottom accent bar ───────────────────────────── -->
    <tr>
        <td style="background:#B8892B;height:3px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>

</table>
<!-- /outer card -->

</td></tr>
</table>

</body>
</html>
