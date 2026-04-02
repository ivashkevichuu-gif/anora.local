<?php
/**
 * Init migration — idempotent creation of tables required by the
 * production architecture overhaul.
 *
 * Safe to run multiple times: uses CREATE TABLE IF NOT EXISTS.
 * Intended to be executed on container startup or manually.
 *
 * Tables created:
 *   - refresh_tokens (JWT authentication)
 *   - audit_logs (structured security audit logging)
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 10.6
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/structured_logger.php';

$logger = StructuredLogger::getInstance();

$logger->info('Running init migration', [], ['component' => 'migration']);

// ── refresh_tokens ──────────────────────────────────────────────────────────

$refreshTokensSql = <<<'SQL'
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
    $pdo->exec($refreshTokensSql);
    $logger->info('Table refresh_tokens: OK', [], ['component' => 'migration']);
} catch (PDOException $e) {
    $logger->error('Failed to create refresh_tokens table', [
        'error' => $e->getMessage(),
    ], ['component' => 'migration'], $e);
    exit(1);
}

// ── audit_logs ──────────────────────────────────────────────────────────────

$auditLogsSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    timestamp   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    level       ENUM('debug','info','warning','error','critical') NOT NULL,
    action      VARCHAR(64) NOT NULL,
    user_id     INT DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    user_agent  TEXT DEFAULT NULL,
    request_id  VARCHAR(36) DEFAULT NULL,
    result      ENUM('success','failure') DEFAULT NULL,
    context     JSON DEFAULT NULL,
    INDEX idx_user_id   (user_id),
    INDEX idx_action    (action),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

try {
    $pdo->exec($auditLogsSql);
    $logger->info('Table audit_logs: OK', [], ['component' => 'migration']);
} catch (PDOException $e) {
    $logger->error('Failed to create audit_logs table', [
        'error' => $e->getMessage(),
    ], ['component' => 'migration'], $e);
    exit(1);
}

$logger->info('Init migration completed successfully', [], ['component' => 'migration']);
