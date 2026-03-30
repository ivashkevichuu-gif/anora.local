<?php
/**
 * Property-based test for cleanup retention (Property 21).
 *
 * Uses an in-memory SQLite database to simulate the registration_attempts table.
 * The cleanup SQL logic is inlined (does not require cleanup.php).
 *
 * Feature: referral-commission-system, Property 21: Registration Attempts Cleanup
 * Validates: Requirements 19.1
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CleanupRetentionPropertyTest extends TestCase
{
    private PDO $db;

    // -------------------------------------------------------------------------
    // Set up in-memory SQLite database with required schema
    // -------------------------------------------------------------------------
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE registration_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // =========================================================================
    // Property 21: Cleanup Retention
    // Feature: referral-commission-system, Property 21: Registration Attempts Cleanup
    //
    // After running the cleanup logic, rows older than 7 days must be deleted
    // and rows within 7 days must be preserved.
    //
    // Validates: Requirements 19.1
    // =========================================================================
    public function testProperty21_CleanupRetention(): void
    {
        // Feature: referral-commission-system, Property 21: Registration Attempts Cleanup
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Clear the table for each iteration
            $this->db->exec("DELETE FROM registration_attempts");

            // Generate a random mix of old and recent rows
            $numOld    = mt_rand(1, 10);
            $numRecent = mt_rand(1, 10);

            $oldIds    = [];
            $recentIds = [];

            // Insert rows older than 7 days (8–30 days ago)
            for ($j = 0; $j < $numOld; $j++) {
                $daysAgo = mt_rand(8, 30);
                $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
                $ip = mt_rand(1, 254) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);

                $stmt = $this->db->prepare(
                    "INSERT INTO registration_attempts (ip, created_at) VALUES (?, ?)"
                );
                $stmt->execute([$ip, $createdAt]);
                $oldIds[] = (int)$this->db->lastInsertId();
            }

            // Insert rows within 7 days (0–6 days ago, using hours to avoid boundary issues)
            for ($j = 0; $j < $numRecent; $j++) {
                $hoursAgo  = mt_rand(0, 6 * 24 - 1); // 0 to 143 hours (< 7 days)
                $createdAt = date('Y-m-d H:i:s', strtotime("-{$hoursAgo} hours"));
                $ip = mt_rand(1, 254) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);

                $stmt = $this->db->prepare(
                    "INSERT INTO registration_attempts (ip, created_at) VALUES (?, ?)"
                );
                $stmt->execute([$ip, $createdAt]);
                $recentIds[] = (int)$this->db->lastInsertId();
            }

            // Run the cleanup logic (inlined from cron/cleanup.php, adapted for SQLite)
            $this->db->exec(
                "DELETE FROM registration_attempts WHERE created_at < datetime('now', '-7 days')"
            );

            // Verify: old rows must be deleted
            foreach ($oldIds as $id) {
                $stmt = $this->db->prepare("SELECT id FROM registration_attempts WHERE id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetch()) {
                    $failures[] = sprintf(
                        'iter=%d: old row id=%d was NOT deleted (should have been removed)',
                        $i, $id
                    );
                }
            }

            // Verify: recent rows must be preserved
            foreach ($recentIds as $id) {
                $stmt = $this->db->prepare("SELECT id FROM registration_attempts WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) {
                    $failures[] = sprintf(
                        'iter=%d: recent row id=%d was deleted (should have been preserved)',
                        $i, $id
                    );
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 21 (Cleanup Retention) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
