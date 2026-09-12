<?php
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$rootDir = dirname(__DIR__);
require_once $rootDir . '/app/config/config.php';
require_once $rootDir . '/app/config/database.php';
require_once $rootDir . '/app/config/mail.php';
if (file_exists($rootDir . '/vendor/autoload.php')) require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/core/Database.php';
require_once $rootDir . '/core/Model.php';
require_once $rootDir . '/core/Autoloader.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$firstName = 'Samuel';
$fullName  = 'Samuel';
$member    = ['first_name' => $firstName, 'last_name' => ''];
ob_start();
include VIEW_PATH . '/birthday/email.php';
$html = ob_get_clean();

$logoPath = PUBLIC_PATH . '/images/logo-email.png';

$mail = new PHPMailer(true);
try {
    $mail->CharSet    = PHPMailer::CHARSET_UTF8;
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->setFrom(SMTP_USER, 'Empower Investment Club');
    $mail->addAddress('samuelgidudu99@gmail.com', 'Samuel');
    $mail->isHTML(true);
    $mail->Subject = 'Happy Birthday, Samuel! 🎉';
    $mail->Body    = $html;
    $mail->AltBody = "Dear Samuel,\n\nHappy Birthday! Warm wishes from the entire Empower Investment Club family.\n\n— Empower Investment Club";
    if (is_file($logoPath)) $mail->addEmbeddedImage($logoPath, 'logo');
    $mail->send();
    echo "SENT OK\n";
} catch (PHPMailerException $e) {
    fwrite(STDERR, "FAILED: " . $mail->ErrorInfo . "\n"); exit(1);
}
