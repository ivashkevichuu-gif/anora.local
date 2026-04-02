<?php
declare(strict_types=1);

/**
 * StructuredLogger — JSON structured logging for ANORA platform.
 *
 * Outputs JSON log lines to stdout (debug/info/warning) or stderr (error/critical).
 * Supports log level filtering via LOG_LEVEL env var, automatic source detection,
 * request_id propagation from X-Request-ID header, audit logs, and stack traces.
 */
class StructuredLogger
{
    /** Log level severity map (higher = more severe) */
    private const LEVELS = [
        'debug'    => 0,
        'info'     => 1,
        'warning'  => 2,
        'error'    => 3,
        'critical' => 4,
    ];

    /** Levels that write to stderr */
    private const STDERR_LEVELS = ['error', 'critical'];

    private int $minSeverity;
    private ?string $requestId;

    /** @var resource stdout handle */
    private $stdout;

    /** @var resource stderr handle */
    private $stderr;

    private static ?self $instance = null;

    public function __construct(?string $minLevel = null, ?string $requestId = null)
    {
        $envLevel = $minLevel ?? (getenv('LOG_LEVEL') ?: 'info');
        $envLevel = strtolower($envLevel);
        $this->minSeverity = self::LEVELS[$envLevel] ?? self::LEVELS['info'];

        $this->requestId = $requestId ?? self::detectRequestId();

        $this->stdout = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        $this->stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
    }

    /**
     * Get or create a singleton instance.
     */
    public static function getInstance(?string $minLevel = null, ?string $requestId = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($minLevel, $requestId);
        }
        return self::$instance;
    }

    /**
     * Reset singleton (useful for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // ── Public logging methods ──────────────────────────────────────────

    public function debug(string $message, array $data = [], array $context = []): ?string
    {
        return $this->log('debug', $message, $data, $context);
    }

    public function info(string $message, array $data = [], array $context = []): ?string
    {
        return $this->log('info', $message, $data, $context);
    }

    public function warning(string $message, array $data = [], array $context = []): ?string
    {
        return $this->log('warning', $message, $data, $context);
    }

    public function error(string $message, array $data = [], array $context = [], ?\Throwable $exception = null): ?string
    {
        return $this->log('error', $message, $data, $context, $exception);
    }

    public function critical(string $message, array $data = [], array $context = [], ?\Throwable $exception = null): ?string
    {
        return $this->log('critical', $message, $data, $context, $exception);
    }

    // ── Audit logging ───────────────────────────────────────────────────

    /**
     * Write an audit log entry for security-related actions.
     *
     * @param string      $action    e.g. "login", "password_change", "withdraw"
     * @param string      $result    "success" or "failure"
     * @param int|null    $userId
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @param array       $extra     Additional context data
     */
    public function audit(
        string $action,
        string $result,
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $extra = []
    ): ?string {
        $context = array_merge(['user_id' => $userId], $extra);

        $audit = [
            'action'     => $action,
            'user_id'    => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'result'     => $result,
        ];

        return $this->log('info', 'Security event', [], $context, null, $audit);
    }

    // ── Core log method ─────────────────────────────────────────────────

    /**
     * Build and write a structured JSON log line.
     *
     * @return string|null The JSON string written, or null if filtered out.
     */
    private function log(
        string $level,
        string $message,
        array $data = [],
        array $context = [],
        ?\Throwable $exception = null,
        ?array $audit = null
    ): ?string {
        // Level filtering
        $severity = self::LEVELS[$level] ?? self::LEVELS['info'];
        if ($severity < $this->minSeverity) {
            return null;
        }

        // Build context with request_id
        $mergedContext = array_merge(
            ['request_id' => $this->requestId],
            $context
        );

        // Build log entry
        $entry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . 'Z',
            'level'     => $level,
            'message'   => $message,
            'context'   => $mergedContext,
            'source'    => $this->detectSource(),
        ];

        // Add data if non-empty
        if (!empty($data)) {
            $entry['data'] = $data;
        }

        // Add audit block if provided
        if ($audit !== null) {
            $entry['audit'] = $audit;
        }

        // Add stack trace for error/critical when exception is provided
        if ($exception !== null && in_array($level, ['error', 'critical'], true)) {
            $entry['trace'] = $exception->getMessage() . "\n" . $exception->getTraceAsString();
        }

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // Fallback: encode with lossy conversion
            $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        $line = $json . "\n";

        // Route to stderr for error/critical, stdout otherwise
        if (in_array($level, self::STDERR_LEVELS, true)) {
            fwrite($this->stderr, $line);
        } else {
            fwrite($this->stdout, $line);
        }

        return $json;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Detect the calling source file and line via debug_backtrace().
     * Returns "filename.php:line" format.
     */
    private function detectSource(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        // Walk up the stack to find the first caller outside this class
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && $file !== __FILE__) {
                return basename($file) . ':' . ($frame['line'] ?? 0);
            }
        }

        // Fallback
        return 'unknown:0';
    }

    /**
     * Detect X-Request-ID from HTTP headers.
     */
    private static function detectRequestId(): ?string
    {
        // Check $_SERVER (standard for Apache/nginx)
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return $_SERVER['HTTP_X_REQUEST_ID'];
        }

        // Check getallheaders() if available (Apache)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false) {
                foreach ($headers as $name => $value) {
                    if (strtolower($name) === 'x-request-id') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get the current minimum log level name.
     */
    public function getMinLevel(): string
    {
        return array_search($this->minSeverity, self::LEVELS, true) ?: 'info';
    }

    /**
     * Get the current request ID.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Set the request ID (useful for CLI or testing).
     */
    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * Check if a given level would be logged at the current minimum level.
     */
    public static function shouldLog(string $level, string $minLevel): bool
    {
        $levelSeverity = self::LEVELS[$level] ?? -1;
        $minSeverity = self::LEVELS[$minLevel] ?? self::LEVELS['info'];
        return $levelSeverity >= $minSeverity;
    }

    /**
     * Get the severity order constant (useful for testing).
     */
    public static function getLevels(): array
    {
        return self::LEVELS;
    }
}
