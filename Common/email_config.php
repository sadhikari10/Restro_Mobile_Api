<?php
// Common/email_config.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load .env file from project root
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');  // Points to C:\xampp\htdocs\Restaurant
$dotenv->load();

// Optional: Check for required vars (good for production)
$dotenv->required(['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME']);

function sendResetEmail(string $toEmail, string $token): bool {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USERNAME'];
        $mail->Password   = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'];

        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($toEmail);

        $resetLink = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/reset_password.php?token=" . $token;

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request';

        $mail->Body = "
            <h3>Password Reset</h3>
            <p>Click this link to reset your password:</p>
            <p><a href='$resetLink' style='padding:10px 20px; background:#007BFF; color:white; text-decoration:none; border-radius:5px;'>Reset Password</a></p>
            <p><small>Link expires in 30 minutes.</small></p>
        ";

        $mail->AltBody = "Reset link: $resetLink";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail error: " . $mail->ErrorInfo);
        return false;
    }
}