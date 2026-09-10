<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * MailerService — Member Statement Emailing stage.
 *
 * Thin wrapper around PHPMailer/Gmail SMTP (app/config/mail.php), the
 * app's first outbound-email capability. Deliberately fails closed rather
 * than throwing: isConfigured() lets a caller show "email is not set up
 * yet" before attempting anything, and send() always returns a result
 * array rather than letting a bad address or an unreachable SMTP server
 * take down a bulk-send loop over many members.
 */
class MailerService
{
    public function isConfigured(): bool
    {
        return SMTP_USER !== '' && SMTP_PASS !== '';
    }

    /**
     * @param array<int, array{path: string, cid: string}> $embeddedImages
     *   Images referenced from $htmlBody as src="cid:<cid>" -- CID
     *   embedding (not a data: URI) because it's the one inline-image
     *   mechanism that reliably survives across clients, Outlook desktop
     *   included. A plain http(s) <img> URL would work once this app has
     *   a real public host, but not while APP_URL is localhost -- no
     *   external mail server can ever fetch that back.
     * @param array<int, array{content: string, filename: string}> $attachments
     *   In-memory file attachments (e.g. a PDF statement) -- content is
     *   the raw file bytes, never a filesystem path, so a caller never
     *   needs to write a temp file just to email it.
     * @return array{success: bool, error: ?string}
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $embeddedImages = [], array $attachments = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Email sending is not configured yet (missing SMTP credentials).'];
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid recipient email address.'];
        }

        $mail = new PHPMailer(true);
        try {
            // Without this, PHPMailer falls back to ISO-8859-1 for the
            // Subject header specifically (the HTML body's own <meta
            // charset="UTF-8"> tag was enough for email clients to render
            // it correctly regardless, which is why only the Subject --
            // e.g. its em dash -- came through as mojibake in testing).
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;

            $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            foreach ($embeddedImages as $img) {
                if (is_file($img['path'])) {
                    $mail->addEmbeddedImage($img['path'], $img['cid']);
                }
            }
            foreach ($attachments as $att) {
                $mail->addStringAttachment($att['content'], $att['filename']);
            }

            $mail->send();
            return ['success' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
        }
    }
}
