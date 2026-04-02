<?php
/**
 * Property-based tests for StructuredLogger (Properties P15–P19).
 *
 * Uses mt_rand() for randomized input generation, 100 iterations per property.
 * Tests the StructuredLogger class at backend/includes/structured_logger.php.
 *
 * Feature: production-architecture-overhaul, Properties 15-19: Structured Logger
 * Validates: Requirements 6.1, 6.2, 6.3, 6.4, 6.6, 6.7
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/structured_logger.php';

class StructuredLoggerPropertyTest extends TestCase
{
    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    protected function tearDown(): void
    {
        StructuredLogger::resetInstance();
    }

    /**
     * Generate a random alphanumeric string.
     */
    private function randomString(int $minLen = 1, int $maxLen = 50): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 _-';
        $len = mt_rand($minLen, $maxLen);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * Pick a random log level.
     */
    private function randomLevel(): string
    {
        return self::LEVELS[mt_rand(0, count(self::LEVELS) - 1)];
    }

    // =========================================================================
    // Property 15: Structured log JSON format with required fields
    // Feature: production-architecture-overhaul, Property 15
    //
    // For any message and level, output is valid JSON with fields:
    // timestamp, level, message, context, source.
    //
    // **Validates: Requirements 6.1**
    // =========================================================================
    public function testProperty15_StructuredLogJsonFormatWithRequiredFields(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            StructuredLogger::resetInstance();
            $logger = new StructuredLogger('debug');

            $level = $this->randomLevel();
            $message = $this->randomString(1, 80);
            $context = ['iter' => $i];

            // Call the appropriate log method
            $json = null;
            switch ($level) {
                case 'debug':
                    $json = $logger->debug($message, [], $context);
                    break;
                case 'info':
                    $json = $logger->info($message, [], $context);
                    break;
                case 'warning':
                    $json = $logger->warning($message, [], $context);
                    break;
                case 'error':
                    $json = $logger->error($message, [], $context);
                    break;
                case 'critical':
                    $json = $logger->critical($message, [], $context);
                    break;
            }

            if ($json === null) {
                $failures[] = sprintf('iter=%d: log returned null for level=%s with minLevel=debug', $i, $level);
                continue;
            }

            // Must be valid JSON
            $decoded = json_decode($json, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $failures[] = sprintf('iter=%d: invalid JSON output for level=%s', $i, $level);
                continue;
            }

            // Check required top-level fields
            $requiredFields = ['timestamp', 'level', 'message', 'context', 'source'];
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $decoded)) {
                    $failures[] = sprintf('iter=%d: missing field "%s" for level=%s', $i, $field, $level);
                }
            }

            // Validate timestamp is ISO 8601-like
            if (isset($decoded['timestamp']) && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $decoded['timestamp'])) {
                $failures[] = sprintf('iter=%d: timestamp "%s" not ISO 8601 format', $i, $decoded['timestamp']);
            }

            // Validate level is one of the known levels
            if (isset($decoded['level']) && !in_array($decoded['level'], self::LEVELS, true)) {
                $failures[] = sprintf('iter=%d: unexpected level "%s"', $i, $decoded['level']);
            }

            // Validate message is a non-empty string
            if (isset($decoded['message']) && (!is_string($decoded['message']) || $decoded['message'] === '')) {
                $failures[] = sprintf('iter=%d: message is empty or not a string', $i);
            }

            // Validate context is an object/array
            if (isset($decoded['context']) && !is_array($decoded['context'])) {
                $failures[] = sprintf('iter=%d: context is not an object', $i);
            }

            // Validate source matches pattern filename.php:line
            if (isset($decoded['source']) && !preg_match('/^.+\.php:\d+$/', $decoded['source'])) {
                $failures[] = sprintf('iter=%d: source "%s" does not match pattern filename.php:line', $i, $decoded['source']);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 15 (Structured log JSON format) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 16: Log level filtering
    // Feature: production-architecture-overhaul, Property 16
    //
    // For any combination of message level L and minimum level M,
    // message is output iff severity(L) >= severity(M).
    //
    // **Validates: Requirements 6.2**
    // =========================================================================
    public function testProperty16_LogLevelFiltering(): void
    {
        $iterations = 100;
        $failures = [];
        $levels = StructuredLogger::getLevels();

        for ($i = 0; $i < $iterations; $i++) {
            $msgLevel = $this->randomLevel();
            $minLevel = $this->randomLevel();

            StructuredLogger::resetInstance();
            $logger = new StructuredLogger($minLevel);

            $message = $this->randomString(1, 40);

            // Call the appropriate log method
            $json = null;
            switch ($msgLevel) {
                case 'debug':
                    $json = $logger->debug($message);
                    break;
                case 'info':
                    $json = $logger->info($message);
                    break;
                case 'warning':
                    $json = $logger->warning($message);
                    break;
                case 'error':
                    $json = $logger->error($message);
                    break;
                case 'critical':
                    $json = $logger->critical($message);
                    break;
            }

            $shouldOutput = $levels[$msgLevel] >= $levels[$minLevel];
            $wasOutput = $json !== null;

            // Also verify via the static helper
            $shouldLogResult = StructuredLogger::shouldLog($msgLevel, $minLevel);

            if ($shouldOutput !== $wasOutput) {
                $failures[] = sprintf(
                    'iter=%d: msgLevel=%s(%d) minLevel=%s(%d) expected=%s got=%s',
                    $i, $msgLevel, $levels[$msgLevel], $minLevel, $levels[$minLevel],
                    $shouldOutput ? 'output' : 'filtered',
                    $wasOutput ? 'output' : 'filtered'
                );
            }

            if ($shouldLogResult !== $shouldOutput) {
                $failures[] = sprintf(
                    'iter=%d: shouldLog(%s,%s) returned %s, expected %s',
                    $i, $msgLevel, $minLevel,
                    $shouldLogResult ? 'true' : 'false',
                    $shouldOutput ? 'true' : 'false'
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 16 (Log level filtering) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 17: Domain-specific log entries contain required fields
    // Feature: production-architecture-overhaul, Property 17
    //
    // Financial logs contain: user_id, type, amount, direction, balance_after, reference_id
    // Audit logs contain: action, user_id, ip_address, user_agent, result
    //
    // **Validates: Requirements 6.3, 6.7**
    // =========================================================================
    public function testProperty17_DomainSpecificLogEntriesContainRequiredFields(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            StructuredLogger::resetInstance();
            $logger = new StructuredLogger('debug');

            // --- Financial log test ---
            $userId = mt_rand(1, 100000);
            $types = ['bet', 'deposit', 'withdraw', 'payout', 'referral'];
            $type = $types[mt_rand(0, count($types) - 1)];
            $amount = round(mt_rand(1, 999999) / 100, 2);
            $directions = ['credit', 'debit'];
            $direction = $directions[mt_rand(0, 1)];
            $balanceAfter = round(mt_rand(0, 999999) / 100, 2);
            $referenceId = mt_rand(1, 999999) . ':' . $userId . ':' . mt_rand(1, 100);

            $financialData = [
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'direction' => $direction,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
            ];

            $json = $logger->info('Ledger entry created', $financialData, ['user_id' => $userId]);

            if ($json !== null) {
                $decoded = json_decode($json, true);
                if ($decoded !== null && isset($decoded['data'])) {
                    $requiredFinancialFields = ['user_id', 'type', 'amount', 'direction', 'balance_after', 'reference_id'];
                    foreach ($requiredFinancialFields as $field) {
                        if (!array_key_exists($field, $decoded['data'])) {
                            $failures[] = sprintf('iter=%d: financial log missing data.%s', $i, $field);
                        }
                    }
                } else {
                    $failures[] = sprintf('iter=%d: financial log missing "data" block', $i);
                }
            } else {
                $failures[] = sprintf('iter=%d: financial log returned null at info level with minLevel=debug', $i);
            }

            // --- Audit log test ---
            $actions = ['login', 'logout', 'password_change', 'withdraw', 'deposit'];
            $action = $actions[mt_rand(0, count($actions) - 1)];
            $results = ['success', 'failure'];
            $result = $results[mt_rand(0, 1)];
            $auditUserId = mt_rand(1, 100000);
            $ipAddress = mt_rand(1, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
            $userAgent = 'Mozilla/5.0 Test/' . $this->randomString(3, 10);

            $auditJson = $logger->audit($action, $result, $auditUserId, $ipAddress, $userAgent);

            if ($auditJson !== null) {
                $auditDecoded = json_decode($auditJson, true);
                if ($auditDecoded !== null && isset($auditDecoded['audit'])) {
                    $requiredAuditFields = ['action', 'user_id', 'ip_address', 'user_agent', 'result'];
                    foreach ($requiredAuditFields as $field) {
                        if (!array_key_exists($field, $auditDecoded['audit'])) {
                            $failures[] = sprintf('iter=%d: audit log missing audit.%s', $i, $field);
                        }
                    }
                } else {
                    $failures[] = sprintf('iter=%d: audit log missing "audit" block', $i);
                }
            } else {
                $failures[] = sprintf('iter=%d: audit log returned null', $i);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 17 (Domain-specific log entries) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 18: Error logs include stack trace
    // Feature: production-architecture-overhaul, Property 18
    //
    // For any Exception at error/critical level, JSON contains trace field
    // with non-empty string.
    //
    // **Validates: Requirements 6.4**
    // =========================================================================
    public function testProperty18_ErrorLogsIncludeStackTrace(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            StructuredLogger::resetInstance();
            $logger = new StructuredLogger('debug');

            // Pick error or critical
            $level = mt_rand(0, 1) === 0 ? 'error' : 'critical';
            $message = $this->randomString(5, 60);
            $exceptionMessage = 'Exception_' . $this->randomString(5, 30);
            $exception = new \RuntimeException($exceptionMessage);

            $json = null;
            if ($level === 'error') {
                $json = $logger->error($message, [], [], $exception);
            } else {
                $json = $logger->critical($message, [], [], $exception);
            }

            if ($json === null) {
                $failures[] = sprintf('iter=%d: %s log returned null with minLevel=debug', $i, $level);
                continue;
            }

            $decoded = json_decode($json, true);
            if ($decoded === null) {
                $failures[] = sprintf('iter=%d: invalid JSON for %s log', $i, $level);
                continue;
            }

            if (!array_key_exists('trace', $decoded)) {
                $failures[] = sprintf('iter=%d: %s log missing "trace" field', $i, $level);
                continue;
            }

            if (!is_string($decoded['trace']) || $decoded['trace'] === '') {
                $failures[] = sprintf('iter=%d: %s log "trace" field is empty or not a string', $i, $level);
                continue;
            }

            // Trace should contain the exception message
            if (strpos($decoded['trace'], $exceptionMessage) === false) {
                $failures[] = sprintf(
                    'iter=%d: %s log "trace" does not contain exception message "%s"',
                    $i, $level, $exceptionMessage
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 18 (Error logs include stack trace) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 19: Request ID propagation in logs
    // Feature: production-architecture-overhaul, Property 19
    //
    // For any HTTP context with X-Request-ID = V, all log entries contain
    // context.request_id = V.
    //
    // **Validates: Requirements 6.6**
    // =========================================================================
    public function testProperty19_RequestIdPropagationInLogs(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate a random UUID-like request ID
            $requestId = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            StructuredLogger::resetInstance();
            $logger = new StructuredLogger('debug', $requestId);

            // Log at a random level
            $level = $this->randomLevel();
            $message = $this->randomString(5, 40);

            $json = null;
            switch ($level) {
                case 'debug':
                    $json = $logger->debug($message);
                    break;
                case 'info':
                    $json = $logger->info($message);
                    break;
                case 'warning':
                    $json = $logger->warning($message);
                    break;
                case 'error':
                    $json = $logger->error($message);
                    break;
                case 'critical':
                    $json = $logger->critical($message);
                    break;
            }

            if ($json === null) {
                $failures[] = sprintf('iter=%d: log returned null for level=%s with minLevel=debug', $i, $level);
                continue;
            }

            $decoded = json_decode($json, true);
            if ($decoded === null) {
                $failures[] = sprintf('iter=%d: invalid JSON', $i);
                continue;
            }

            if (!isset($decoded['context']['request_id'])) {
                $failures[] = sprintf('iter=%d: missing context.request_id', $i);
                continue;
            }

            if ($decoded['context']['request_id'] !== $requestId) {
                $failures[] = sprintf(
                    'iter=%d: context.request_id mismatch: expected=%s got=%s',
                    $i, $requestId, $decoded['context']['request_id']
                );
            }

            // Also verify request_id propagates across multiple log calls
            $secondJson = $logger->info('Second message ' . $this->randomString(3, 10));
            if ($secondJson !== null) {
                $secondDecoded = json_decode($secondJson, true);
                if ($secondDecoded !== null && isset($secondDecoded['context']['request_id'])) {
                    if ($secondDecoded['context']['request_id'] !== $requestId) {
                        $failures[] = sprintf(
                            'iter=%d: second log context.request_id mismatch: expected=%s got=%s',
                            $i, $requestId, $secondDecoded['context']['request_id']
                        );
                    }
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 19 (Request ID propagation) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
