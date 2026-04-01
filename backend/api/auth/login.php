<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';

$input    = json_decode(file_get_contents('php://input'), true);
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']); exit;
}
if ((int)$user['is_bot'] === 1) {
    http_response_code(403);
    echo json_encode(['error' => 'This account cannot log in.']); exit;
}
if (!$user['is_verified']) {
    http_response_code(403);
    echo json_encode(['error' => 'Please verify your email before logging in.']); exit;
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['email']   = $user['email'];

echo json_encode([
    'user' => [
        'id'                  => (int)$user['id'],
        'email'               => $user['email'],
        'balance'             => (float)$user['balance'],
        'nickname'            => $user['nickname'],
        'nickname_changed_at' => $user['nickname_changed_at'],
        'can_change_nickname' => !$user['nickname_changed_at'] || (time() - strtotime($user['nickname_changed_at'])) >= 86400,
    ]
]);
