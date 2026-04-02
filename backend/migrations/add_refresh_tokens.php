<?php
/**
 * Migration: Create refresh_tokens table for JWT authentication.
 *
 * Stores hashed refresh tokens with family-based replay detection.
 * Each token belongs to a family (family_id) — on replay attack,
 * the entire family is invalidated.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 1.5
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "Running migration: add_refresh_tokens\n";

$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    token_hash          VARCHAR(64)  NOT NULL UNIQUE,
    user_id             INT          NOT NULL,
    family_id           VARCHAR(36)  NOT NULL,
    device_fingerprint  VARCHAR(64)  DEFAULT NULL,
    expires_at          DATETIME     NOT NULL,
    revoked_at          DATETIME     DEFAULT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id    (user_id),
    INDEX idx_family_id  (family_id),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

try {
    $pdo->exec($sql);
    echo "Migration complete: refresh_tokens table created.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
