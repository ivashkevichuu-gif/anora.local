<?php
/**
 * POST /api/auth/login
 *
 * Authenticates user with email/password, returns JWT access_token + refresh_token.
 * Also creates PHP session for backward compatibility with frontend.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 1.1, 1.3
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/jwt_service.php';
require_once __DIR__ . '/../../includes/structured_logger.php';

$logger = StructuredLogger::getInstance();
$input  = json_decode(file_get_contents('php://input'), true);
$email  = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    $logger->audit('login', 'failure', null, $ipAddress, $userAgent, ['email' => $email]);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
    exit;
}

if ((int)$user['is_bot'] === 1) {
    $logger->audit('login', 'failure', (int)$user['id'], $ipAddress, $userAgent, ['reason' => 'bot_account']);
    http_response_code(403);
    echo json_encode(['error' => 'This account cannot log in.']);
    exit;
}

if (!$user['is_verified']) {
    $logger->audit('login', 'failure', (int)$user['id'], $ipAddress, $userAgent, ['reason' => 'not_verified']);
    http_response_code(403);
    echo json_encode(['error' => 'Please verify your email before logging in.']);
    exit;
}

// Determine role
$role = 'user'; // Default; admin endpoints use separate login

// Issue JWT tokens
$jwtService = new JwtService();
$accessToken = $jwtService->encode((int)$user['id'], $role);
$refreshData = $jwtService->createRefreshToken($pdo, (int)$user['id']);

$logger->audit('login', 'success', (int)$user['id'], $ipAddress, $userAgent);

// Set session for backward compatibility with frontend (credentials: 'include')
$_SESSION['user_id'] = (int)$user['id'];

echo json_encode([
    'access_token'  => $accessToken,
    'refresh_token' => $refreshData['token'],
    'expires_in'    => JwtService::getAccessTtl(),
    'user' => [
        'id'                  => (int)$user['id'],
        'email'               => $user['email'],
        'balance'             => (float)$user['balance'],
        'nickname'            => $user['nickname'],
        'nickname_changed_at' => $user['nickname_changed_at'],
        'can_change_nickname' => !$user['nickname_changed_at'] || (time() - strtotime($user['nickname_changed_at'])) >= 86400,
    ],
]);
