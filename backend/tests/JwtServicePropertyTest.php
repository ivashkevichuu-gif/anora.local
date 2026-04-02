<?php
/**
 * Property-based tests for JwtService (Properties P1–P5).
 *
 * Uses mt_rand() for randomized input generation, 100 iterations per property.
 * Tests the JwtService class at backend/includes/jwt_service.php.
 *
 * - P1: JWT encode/decode round-trip
 * - P2: Refresh token rotation invalidates old token
 * - P3: Replay attack invalidates entire token family
 * - P4: Blacklisted user tokens are rejected
 * - P5: JWT signature tamper detection
 *
 * Feature: production-architecture-overhaul, Properties 1-5: JWT Authentication
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.6, 1.7
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/structured_logger.php';
require_once __DIR__ . '/../includes/redis_client.php';
require_once __DIR__ . '/../includes/cache_service.php';
require_once __DIR__ . '/../includes/jwt_service.php';

class JwtServicePropertyTest extends TestCase
{
    private const ROLES = ['user', 'admin'];

    protected function setUp(): void
    {
        StructuredLogger::resetInstance();
        RedisClient::resetInstance();
    }

    protected function tearDown(): void
    {
        StructuredLogger::resetInstance();
        RedisClient::resetInstance();
    }

    /**
     * Create an in-memory SQLite PDO with the refresh_tokens schema.
     */
    private function createSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Create users table (minimal, for FK simulation)
        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                is_banned INTEGER NOT NULL DEFAULT 0
            )
        ');

        // Create refresh_tokens table (SQLite-compatible)
        $pdo->exec('
            CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                family_id TEXT NOT NULL,
                device_fingerprint TEXT DEFAULT NULL,
                expires_at TEXT NOT NULL,
                revoked_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');

        return $pdo;
    }

    /**
     * Insert a test user into the SQLite database.
     */
    private function insertTestUser(PDO $pdo, int $userId): void
    {
        // SQLite doesn't support inserting with specific auto-increment IDs easily,
        // so we insert with explicit ID
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO users (id, email, password, is_banned) VALUES (?, ?, ?, 0)');
        $stmt->execute([$userId, "user{$userId}@test.com", password_hash('test', PASSWORD_BCRYPT)]);
    }

    /**
     * Create a mock CacheService that reports no blacklisted users.
     */
    private function createNonBlacklistCacheService(): CacheService
    {
        $mock = $this->createMock(CacheService::class);
        $mock->method('isBlacklisted')->willReturn(false);
        return $mock;
    }

    /**
     * Create a mock CacheService that reports ALL users as blacklisted.
     */
    private function createBlacklistCacheService(): CacheService
    {
        $mock = $this->createMock(CacheService::class);
        $mock->method('isBlacklisted')->willReturn(true);
        return $mock;
    }

    // =========================================================================
    // Property 1: JWT encode/decode round-trip
    // Feature: production-architecture-overhaul, Property 1
    //
    // For any user_id (positive int) and role ("user" or "admin"),
    // encode → decode returns same values, exp = iat + 900.
    //
    // **Validates: Requirements 1.1, 1.2**
    // =========================================================================
    public function testProperty1_JwtEncodeDecodeRoundTrip(): void
    {
        $iterations = 100;
        $failures = [];
        $secret = 'test-secret-' . bin2hex(random_bytes(16));
        $cacheService = $this->createNonBlacklistCacheService();

        for ($i = 0; $i < $iterations; $i++) {
            $userId = mt_rand(1, 999999);
            $role = self::ROLES[mt_rand(0, 1)];

            $jwtService = new JwtService($secret, $cacheService);
            $token = $jwtService->encode($userId, $role);

            // Decode should succeed
            $payload = $jwtService->decode($token);

            if ($payload === null) {
                $failures[] = sprintf('iter=%d: decode returned null for userId=%d role=%s', $i, $userId, $role);
                continue;
            }

            // Check sub matches
            if (!isset($payload['sub']) || (int)$payload['sub'] !== $userId) {
                $failures[] = sprintf(
                    'iter=%d: sub mismatch: expected=%d got=%s',
                    $i, $userId, $payload['sub'] ?? 'null'
                );
            }

            // Check role matches
            if (!isset($payload['role']) || $payload['role'] !== $role) {
                $failures[] = sprintf(
                    'iter=%d: role mismatch: expected=%s got=%s',
                    $i, $role, $payload['role'] ?? 'null'
                );
            }

            // Check exp = iat + 900
            if (isset($payload['iat']) && isset($payload['exp'])) {
                $expectedExp = $payload['iat'] + 900;
                if ((int)$payload['exp'] !== $expectedExp) {
                    $failures[] = sprintf(
                        'iter=%d: exp mismatch: expected iat(%d)+900=%d got=%d',
                        $i, $payload['iat'], $expectedExp, $payload['exp']
                    );
                }
            } else {
                $failures[] = sprintf('iter=%d: missing iat or exp', $i);
            }

            // Check jti is present and non-empty
            if (!isset($payload['jti']) || empty($payload['jti'])) {
                $failures[] = sprintf('iter=%d: jti is missing or empty', $i);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 1 (JWT encode/decode round-trip) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 2: Refresh token rotation invalidates old token
    // Feature: production-architecture-overhaul, Property 2
    //
    // After refresh — old token revoked_at != null, reuse fails.
    // Uses in-memory SQLite for DB operations.
    //
    // **Validates: Requirements 1.3**
    // =========================================================================
    public function testProperty2_RefreshTokenRotationInvalidatesOldToken(): void
    {
        $iterations = 100;
        $failures = [];
        $secret = 'test-secret-' . bin2hex(random_bytes(16));
        $cacheService = $this->createNonBlacklistCacheService();

        for ($i = 0; $i < $iterations; $i++) {
            $pdo = $this->createSqlitePdo();
            $userId = mt_rand(1, 999999);
            $this->insertTestUser($pdo, $userId);

            $jwtService = new JwtService($secret, $cacheService);

            // Create initial refresh token
            $refreshData = $jwtService->createRefreshToken($pdo, $userId);
            $oldToken = $refreshData['token'];
            $oldTokenHash = hash('sha256', $oldToken);

            // Perform refresh
            $result = $jwtService->refresh($oldToken, $pdo);

            if ($result === null) {
                $failures[] = sprintf('iter=%d: refresh returned null for userId=%d', $i, $userId);
                continue;
            }

            // Verify old token is revoked in DB
            $stmt = $pdo->prepare('SELECT revoked_at FROM refresh_tokens WHERE token_hash = ?');
            $stmt->execute([$oldTokenHash]);
            $row = $stmt->fetch();

            if (!$row || $row['revoked_at'] === null) {
                $failures[] = sprintf('iter=%d: old token revoked_at is null after refresh', $i);
            }

            // Verify new tokens are returned
            if (empty($result['access_token'])) {
                $failures[] = sprintf('iter=%d: no access_token in refresh result', $i);
            }
            if (empty($result['refresh_token'])) {
                $failures[] = sprintf('iter=%d: no refresh_token in refresh result', $i);
            }

            // Verify reusing old token fails
            $reuse = $jwtService->refresh($oldToken, $pdo);
            if ($reuse !== null) {
                $failures[] = sprintf('iter=%d: reusing old refresh token should fail but succeeded', $i);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 2 (Refresh token rotation) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 3: Replay attack invalidates entire token family
    // Feature: production-architecture-overhaul, Property 3
    //
    // When revoked refresh token is reused — ALL tokens with same family_id
    // get revoked_at set.
    // Uses in-memory SQLite.
    //
    // **Validates: Requirements 1.4**
    // =========================================================================
    public function testProperty3_ReplayAttackInvalidatesFamily(): void
    {
        $iterations = 100;
        $failures = [];
        $secret = 'test-secret-' . bin2hex(random_bytes(16));
        $cacheService = $this->createNonBlacklistCacheService();

        for ($i = 0; $i < $iterations; $i++) {
            $pdo = $this->createSqlitePdo();
            $userId = mt_rand(1, 999999);
            $this->insertTestUser($pdo, $userId);

            $jwtService = new JwtService($secret, $cacheService);

            // Create initial refresh token
            $refreshData = $jwtService->createRefreshToken($pdo, $userId);
            $originalToken = $refreshData['token'];
            $familyId = $refreshData['family_id'];

            // Perform legitimate refresh (rotates token)
            $result = $jwtService->refresh($originalToken, $pdo);
            if ($result === null) {
                $failures[] = sprintf('iter=%d: first refresh failed', $i);
                continue;
            }

            $newToken = $result['refresh_token'];

            // Now replay the original (already revoked) token
            $replayResult = $jwtService->refresh($originalToken, $pdo);

            // Replay should fail
            if ($replayResult !== null) {
                $failures[] = sprintf('iter=%d: replay attack should return null', $i);
            }

            // ALL tokens in the family should be revoked
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) as total, SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) as revoked
                 FROM refresh_tokens WHERE family_id = ?'
            );
            $stmt->execute([$familyId]);
            $counts = $stmt->fetch();

            if ((int)$counts['total'] !== (int)$counts['revoked']) {
                $failures[] = sprintf(
                    'iter=%d: not all family tokens revoked: total=%d revoked=%d family_id=%s',
                    $i, $counts['total'], $counts['revoked'], $familyId
                );
            }

            // The new token (from legitimate refresh) should also be revoked
            $newTokenHash = hash('sha256', $newToken);
            $stmt2 = $pdo->prepare('SELECT revoked_at FROM refresh_tokens WHERE token_hash = ?');
            $stmt2->execute([$newTokenHash]);
            $newRow = $stmt2->fetch();

            if ($newRow && $newRow['revoked_at'] === null) {
                $failures[] = sprintf('iter=%d: new token in family not revoked after replay attack', $i);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 3 (Replay attack invalidates family) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 4: Blacklisted user tokens are rejected
    // Feature: production-architecture-overhaul, Property 4
    //
    // For user_id in Redis blacklist — JWT validation returns failure.
    // Uses mock Redis.
    //
    // **Validates: Requirements 1.6**
    // =========================================================================
    public function testProperty4_BlacklistedUserTokensRejected(): void
    {
        $iterations = 100;
        $failures = [];
        $secret = 'test-secret-' . bin2hex(random_bytes(16));

        for ($i = 0; $i < $iterations; $i++) {
            $userId = mt_rand(1, 999999);
            $role = self::ROLES[mt_rand(0, 1)];

            // Create a service with NO blacklist → encode succeeds
            $nonBlacklistCache = $this->createNonBlacklistCacheService();
            $jwtService = new JwtService($secret, $nonBlacklistCache);
            $token = $jwtService->encode($userId, $role);

            // Verify token is valid with non-blacklisted service
            $payload = $jwtService->decode($token);
            if ($payload === null) {
                $failures[] = sprintf('iter=%d: token should be valid when not blacklisted', $i);
                continue;
            }

            // Now create a service with blacklist → decode should fail
            $blacklistCache = $this->createBlacklistCacheService();
            $blacklistedService = new JwtService($secret, $blacklistCache);
            $blacklistedPayload = $blacklistedService->decode($token);

            if ($blacklistedPayload !== null) {
                $failures[] = sprintf(
                    'iter=%d: blacklisted user %d token should be rejected but was accepted',
                    $i, $userId
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 4 (Blacklisted user tokens rejected) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 5: JWT signature tamper detection
    // Feature: production-architecture-overhaul, Property 5
    //
    // Modify any single character in payload → validation failure.
    //
    // **Validates: Requirements 1.7**
    // =========================================================================
    public function testProperty5_JwtSignatureTamperDetection(): void
    {
        $iterations = 100;
        $failures = [];
        $secret = 'test-secret-' . bin2hex(random_bytes(16));
        $cacheService = $this->createNonBlacklistCacheService();

        for ($i = 0; $i < $iterations; $i++) {
            $userId = mt_rand(1, 999999);
            $role = self::ROLES[mt_rand(0, 1)];

            $jwtService = new JwtService($secret, $cacheService);
            $token = $jwtService->encode($userId, $role);

            // Split token into parts
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                $failures[] = sprintf('iter=%d: token does not have 3 parts', $i);
                continue;
            }

            $payloadB64 = $parts[1];

            // Pick a random position in the payload to tamper
            $pos = mt_rand(0, strlen($payloadB64) - 1);
            $originalChar = $payloadB64[$pos];

            // Change to a different character
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
            $newChar = $originalChar;
            $attempts = 0;
            while ($newChar === $originalChar && $attempts < 100) {
                $newChar = $chars[mt_rand(0, strlen($chars) - 1)];
                $attempts++;
            }

            if ($newChar === $originalChar) {
                // Extremely unlikely, skip this iteration
                continue;
            }

            // Create tampered token
            $tamperedPayload = substr($payloadB64, 0, $pos) . $newChar . substr($payloadB64, $pos + 1);
            $tamperedToken = $parts[0] . '.' . $tamperedPayload . '.' . $parts[2];

            // Tampered token should fail validation
            $result = $jwtService->decode($tamperedToken);

            if ($result !== null) {
                $failures[] = sprintf(
                    'iter=%d: tampered token was accepted (pos=%d, char %s→%s)',
                    $i, $pos, $originalChar, $newChar
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 5 (JWT signature tamper detection) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
