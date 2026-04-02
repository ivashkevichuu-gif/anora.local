<?php
/**
 * Property-based test for fingerprint insertion round-trip (Property 1).
 *
 * Uses an in-memory SQLite database to simulate the device_fingerprints table.
 * The insertion logic is inlined (mirrors fingerprint.php).
 *
 * Feature: platform-observability, Property 1: Fingerprint Insertion Round-Trip
 * Validates: Requirements 1.3
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FingerprintPropertyTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                is_bot INTEGER NOT NULL DEFAULT 0
            )
        ");

        $this->db->exec("
            CREATE TABLE device_fingerprints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_id TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                user_agent TEXT NOT NULL,
                canvas_hash TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
    }

    // =========================================================================
    // Property 1: Fingerprint Insertion Round-Trip
    // Feature: platform-observability, Property 1: Fingerprint Insertion Round-Trip
    //
    // For any authenticated user and any canvas_hash string (including null),
    // submitting a fingerprint and querying the most recent row should return
    // matching user_id, ip_address, user_agent, and canvas_hash.
    //
    // Validates: Requirements 1.3
    // =========================================================================
    public function testProperty1_FingerprintInsertionRoundTrip(): void
    {
        // Feature: platform-observability, Property 1: Fingerprint Insertion Round-Trip
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Create a random user
            $email = 'user' . mt_rand(1, 99999) . '_' . $i . '@test.com';
            $this->db->prepare("INSERT INTO users (email, is_bot) VALUES (?, 0)")->execute([$email]);
            $userId = (int) $this->db->lastInsertId();

            // Generate random fingerprint data
            $sessionId = bin2hex(random_bytes(16));
            $ipAddress = mt_rand(1, 254) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
            $userAgent = 'Mozilla/5.0 TestAgent/' . mt_rand(1, 100) . '.' . mt_rand(0, 9);

            // Randomly decide if canvas_hash is null or a hex string
            $canvasHash = mt_rand(0, 1) === 1 ? substr(md5((string) mt_rand()), 0, 64) : null;

            // Inline the insertion logic from fingerprint.php
            $stmt = $this->db->prepare(
                "INSERT INTO device_fingerprints (user_id, session_id, ip_address, user_agent, canvas_hash)
                 VALUES (:user_id, :session_id, :ip_address, :user_agent, :canvas_hash)"
            );
            $stmt->execute([
                ':user_id'     => $userId,
                ':session_id'  => $sessionId,
                ':ip_address'  => $ipAddress,
                ':user_agent'  => $userAgent,
                ':canvas_hash' => $canvasHash,
            ]);

            // Query the most recent row for this user
            $query = $this->db->prepare(
                "SELECT user_id, ip_address, user_agent, canvas_hash
                 FROM device_fingerprints
                 WHERE user_id = ?
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $query->execute([$userId]);
            $row = $query->fetch();

            if (!$row) {
                $failures[] = sprintf('iter=%d: no row found for user_id=%d after insert', $i, $userId);
                continue;
            }

            if ((int) $row['user_id'] !== $userId) {
                $failures[] = sprintf('iter=%d: user_id mismatch: expected=%d got=%s', $i, $userId, $row['user_id']);
            }
            if ($row['ip_address'] !== $ipAddress) {
                $failures[] = sprintf('iter=%d: ip_address mismatch: expected=%s got=%s', $i, $ipAddress, $row['ip_address']);
            }
            if ($row['user_agent'] !== $userAgent) {
                $failures[] = sprintf('iter=%d: user_agent mismatch: expected=%s got=%s', $i, $userAgent, $row['user_agent']);
            }
            if ($row['canvas_hash'] !== $canvasHash) {
                $failures[] = sprintf(
                    'iter=%d: canvas_hash mismatch: expected=%s got=%s',
                    $i,
                    $canvasHash ?? 'NULL',
                    $row['canvas_hash'] ?? 'NULL'
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 1 (Fingerprint Insertion Round-Trip) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
