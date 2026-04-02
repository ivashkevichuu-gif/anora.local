<?php
/**
 * POST /api/auth/refresh
 *
 * Endpoint for refresh token rotation.
 * Accepts a refresh_token, validates it, revokes the old one,
 * and issues a new access_token + refresh_token pair.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 1.1, 1.3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/jwt_service.php';
require_once __DIR__ . '/../../includes/structured_logger.php';

$logger = StructuredLogger::getInstance();
$input  = json_decode(file_get_contents('php://input'), true);
$refreshToken = $input['refresh_token'] ?? '';

if (empty($refreshToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'refresh_token is required']);
    exit;
}

$jwtService = new JwtService();
$result = $jwtService->refresh($refreshToken, $pdo);

if ($result === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired refresh token']);
    exit;
}

echo json_encode($result);
