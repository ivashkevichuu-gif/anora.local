<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

$input    = json_decode(file_get_contents('php://input'), true);
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'Invalid email address.']); exit;
}
if (strlen($password) < 6) {
    echo json_encode(['error' => 'Password must be at least 6 characters.']); exit;
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['error' => 'Email already registered.']); exit;
}

$hash  = password_hash($password, PASSWORD_DEFAULT);
$token = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO users (email, password, verify_token) VALUES (?, ?, ?)')
    ->execute([$email, $hash, $token]);

sendVerificationEmail($email, $token);
echo json_encode(['message' => 'Registration successful. Please check your email to verify your account.']);
