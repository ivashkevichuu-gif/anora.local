<?php
/**
 * POST /api/auth/logout
 *
 * Revokes the refresh token family for the authenticated user.
 * Uses JWT from Authorization header.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 1.1, 1.3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/jwt_service.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/structured_logger.php';

$logger = StructuredLogger::getInstance();

requireAuth();

$userId = getCurrentUserId();

if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

// Revoke all refresh tokens for this user
$jwtService = new JwtService();
$revoked = $jwtService->revokeAllForUser($pdo, $userId);

$logger->audit('logout', 'success', $userId, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

// Also destroy session if it exists (backward compatibility)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

echo json_encode(['success' => true, 'revoked_tokens' => $revoked]);
