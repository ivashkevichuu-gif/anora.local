<?php
// Uses PHP's built-in mail(). For production, replace with PHPMailer + SMTP.
function sendVerificationEmail(string $to, string $token): bool {
    $subject = 'Verify your email address';
    $link = 'http://' . $_SERVER['HTTP_HOST'] . '/verify.php?token=' . urlencode($token);
    $body = "Hello,\n\nPlease verify your email by clicking the link below:\n\n$link\n\nIf you did not register, ignore this email.";
    $headers = 'From: noreply@' . $_SERVER['HTTP_HOST'];
    return mail($to, $subject, $body, $headers);
}
