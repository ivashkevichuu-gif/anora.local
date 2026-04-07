<?php
/**
 * POST /api/auth/logout
 *
 * Destroys session and revokes refresh tokens.
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/structured_logger.php';

$logger = StructuredLogger::getInstance();
$userId = $_SESSION['user_id'] ?? null;

// Destroy session
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

// Revoke JWT refresh tokens if user was authenticated
if ($userId) {
    require_once __DIR__ . '/../../includes/jwt_service.php';
    $jwtService = new JwtService();
    $jwtService->revokeAllForUser($pdo, (int)$userId);
    $logger->audit('logout', 'success', (int)$userId, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
}

echo json_encode(['success' => true]);
