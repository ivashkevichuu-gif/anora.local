<?php
// Uses PHP built-in mail(). Swap for PHPMailer + SMTP in production.
function sendVerificationEmail(string $to, string $token): bool {
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $subject = 'Verify your email address';
    $link    = 'https://anora.bet/verify?token=' . urlencode($token);
    $body    = "Hello,\n\nVerify your email:\n\n$link\n\nIf you did not register, ignore this email.";
    $headers = 'From: noreply@' . $host;
    return mail($to, $subject, $body, $headers);
}
