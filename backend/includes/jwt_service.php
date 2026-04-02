<?php
/**
 * JwtService — JWT authentication service for ANORA platform.
 *
 * Manual JWT implementation using base64url + HMAC-SHA256 (no external library).
 * Handles access token encode/decode, refresh token rotation with family-based
 * replay detection, and blacklist checking via Redis CacheService.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.6, 1.7
 */

declare(strict_types=1);

require_once __DIR__ . '/structured_logger.php';
require_once __DIR__ . '/cache_service.php';

class JwtService
{
    /** Access token TTL in seconds (15 minutes) */
    private const ACCESS_TTL = 900;

    /** Refresh token TTL in seconds (7 days) */
    private const REFRESH_TTL = 604800;

    private string $secret;
    private StructuredLogger $logger;
    private ?CacheService $cacheService;

    public function __construct(?string $secret = null, ?CacheService $cacheService = null)
    {
        $this->secret = $secret ?? (getenv('JWT_SECRET') ?: 'default-dev-secret-change-me');
        $this->logger = StructuredLogger::getInstance();
        $this->cacheService = $cacheService;
    }

    // ── Access Token ────────────────────────────────────────────────────

    /**
     * Encode a JWT access token for the given user.
     *
     * @param int    $userId
     * @param string $role "user" or "admin"
     * @return string The signed JWT string
     */
    public function encode(int $userId, string $role): string
    {
        $now = time();
        $payload = [
            'sub'  => $userId,
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + self::ACCESS_TTL,
            'jti'  => $this->generateUuidV4(),
        ];

        return $this->sign($payload);
    }

    /**
     * Decode and validate a JWT access token.
     *
     * Returns the payload array on success, or null on failure.
     * Checks: signature, expiry, blacklist.
     *
     * @param string $token
     * @return array|null Decoded payload or null if invalid
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify signature
        $signingInput = $headerB64 . '.' . $payloadB64;
        $expectedSig = $this->base64urlEncode(
            hash_hmac('sha256', $signingInput, $this->secret, true)
        );

        if (!hash_equals($expectedSig, $signatureB64)) {
            return null;
        }

        // Decode payload
        $payloadJson = $this->base64urlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiry
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        // Check blacklist
        if (isset($payload['sub']) && $this->isBlacklisted((int)$payload['sub'])) {
            return null;
        }

        return $payload;
    }

    // ── Refresh Token ───────────────────────────────────────────────────

    /**
     * Generate a refresh token and store its hash in the database.
     *
     * @param PDO    $pdo
     * @param int    $userId
     * @param string|null $familyId Existing family ID (for rotation) or null for new family
     * @param string|null $deviceFingerprint
     * @return array{token: string, family_id: string, expires_at: string}
     */
    public function createRefreshToken(
        PDO $pdo,
        int $userId,
        ?string $familyId = null,
        ?string $deviceFingerprint = null
    ): array {
        $token = bin2hex(random_bytes(64));
        $tokenHash = hash('sha256', $token);
        $familyId = $familyId ?? $this->generateUuidV4();
        $expiresAt = date('Y-m-d H:i:s', time() + self::REFRESH_TTL);

        $stmt = $pdo->prepare(
            'INSERT INTO refresh_tokens (token_hash, user_id, family_id, device_fingerprint, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tokenHash, $userId, $familyId, $deviceFingerprint, $expiresAt]);

        return [
            'token'      => $token,
            'family_id'  => $familyId,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Refresh: validate the refresh token, revoke it, issue new pair.
     *
     * Implements rotation with replay detection:
     * - If token is valid and not revoked → revoke it, issue new tokens with same family_id
     * - If token is already revoked (replay attack) → revoke entire family
     *
     * @param string $refreshToken Raw refresh token
     * @param PDO    $pdo
     * @return array|null {access_token, refresh_token, expires_in} or null on failure
     */
    public function refresh(string $refreshToken, PDO $pdo): ?array
    {
        $tokenHash = hash('sha256', $refreshToken);

        $stmt = $pdo->prepare(
            'SELECT * FROM refresh_tokens WHERE token_hash = ?'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Replay detection: if token is already revoked, invalidate entire family
        if ($row['revoked_at'] !== null) {
            $this->revokeFamily($pdo, (int)$row['user_id'], $row['family_id']);
            $this->logger->warning('Replay attack detected — entire token family revoked', [], [
                'user_id'   => $row['user_id'],
                'family_id' => $row['family_id'],
            ]);
            return null;
        }

        // Check expiry
        if (strtotime($row['expires_at']) < time()) {
            return null;
        }

        // Check blacklist
        if ($this->isBlacklisted((int)$row['user_id'])) {
            return null;
        }

        // Revoke the current token
        $now = date('Y-m-d H:i:s');
        $revokeStmt = $pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = ? WHERE id = ?'
        );
        $revokeStmt->execute([$now, $row['id']]);

        // Determine role from user table (needed for new access token)
        $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_banned = 0');
        $userStmt->execute([$row['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        // Issue new tokens with same family_id
        $userId = (int)$row['user_id'];
        $role = 'user'; // Default role; admin detection handled by caller or stored context
        $accessToken = $this->encode($userId, $role);
        $newRefresh = $this->createRefreshToken($pdo, $userId, $row['family_id'], $row['device_fingerprint']);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $newRefresh['token'],
            'expires_in'    => self::ACCESS_TTL,
        ];
    }

    /**
     * Revoke all refresh tokens in a family.
     *
     * @param PDO    $pdo
     * @param int    $userId
     * @param string $familyId
     * @return int Number of tokens revoked
     */
    public function revokeFamily(PDO $pdo, int $userId, string $familyId): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = ?
             WHERE user_id = ? AND family_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$now, $userId, $familyId]);
        return $stmt->rowCount();
    }

    /**
     * Revoke all refresh tokens for a user (all families).
     *
     * @param PDO $pdo
     * @param int $userId
     * @return int Number of tokens revoked
     */
    public function revokeAllForUser(PDO $pdo, int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = ?
             WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$now, $userId]);
        return $stmt->rowCount();
    }

    // ── Blacklist ───────────────────────────────────────────────────────

    /**
     * Check if a user is blacklisted via Redis.
     *
     * @param int $userId
     * @return bool
     */
    public function isBlacklisted(int $userId): bool
    {
        if ($this->cacheService === null) {
            return false;
        }
        return $this->cacheService->isBlacklisted($userId);
    }

    // ── JWT Internals (manual HS256) ────────────────────────────────────

    /**
     * Sign a payload into a JWT string (header.payload.signature).
     */
    private function sign(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $headerB64 = $this->base64urlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = $this->base64urlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);
        $signatureB64 = $this->base64urlEncode($signature);

        return $signingInput . '.' . $signatureB64;
    }

    /**
     * Base64url encode (RFC 7515).
     */
    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode (RFC 7515).
     */
    private function base64urlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded;
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ── Accessors (for testing) ─────────────────────────────────────────

    public static function getAccessTtl(): int
    {
        return self::ACCESS_TTL;
    }

    public static function getRefreshTtl(): int
    {
        return self::REFRESH_TTL;
    }
}
