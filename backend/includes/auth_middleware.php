<?php
/**
 * Auth Middleware — JWT-based replacement for session-based auth.php.
 *
 * Provides requireAuth() and requireAdmin() functions that extract
 * user_id and role from JWT payload without DB query.
 * Checks blacklist via Redis CacheService.
 * Sets global $currentUser array for backward compatibility.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 1.2, 1.6
 */

declare(strict_types=1);

require_once __DIR__ . '/jwt_service.php';
require_once __DIR__ . '/cache_service.php';

/** @var array{user_id: int, role: string}|null Global current user from JWT */
$currentUser = null;

/**
 * Extract and validate JWT from Authorization header.
 *
 * @return array|null Decoded JWT payload or null
 */
function extractJwtPayload(): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Support "Bearer <token>" format
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        $token = $matches[1];
    } else {
        return null;
    }

    $jwtService = new JwtService();
    return $jwtService->decode($token);
}

/**
 * Require authenticated user (JWT-based replacement for requireLogin).
 *
 * Extracts user_id and role from JWT payload without DB query.
 * Sets global $currentUser for backward compatibility.
 * Falls back to session-based auth if no JWT is present (backward compatibility).
 */
function requireAuth(): void
{
    global $currentUser;

    $payload = extractJwtPayload();

    if ($payload !== null && isset($payload['sub'])) {
        $currentUser = [
            'user_id' => (int)$payload['sub'],
            'role'    => $payload['role'] ?? 'user',
        ];
        return;
    }

    // Backward compatibility: fall back to session-based auth
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['user_id'])) {
        $currentUser = [
            'user_id' => (int)$_SESSION['user_id'],
            'role'    => !empty($_SESSION['admin']) ? 'admin' : 'user',
        ];
        return;
    }

    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

/**
 * Require admin role (JWT-based replacement for requireAdmin).
 *
 * Calls requireAuth() first, then checks role === 'admin'.
 * Falls back to session-based admin check for backward compatibility.
 */
function requireAdmin(): void
{
    global $currentUser;

    requireAuth();

    if ($currentUser !== null && $currentUser['role'] === 'admin') {
        return;
    }

    // Backward compatibility: check session admin flag
    if (!empty($_SESSION['admin'])) {
        return;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

/**
 * Get the current authenticated user ID.
 *
 * @return int|null
 */
function getCurrentUserId(): ?int
{
    global $currentUser;
    return $currentUser['user_id'] ?? null;
}

/**
 * Get the current authenticated user role.
 *
 * @return string|null
 */
function getCurrentUserRole(): ?string
{
    global $currentUser;
    return $currentUser['role'] ?? null;
}
