<?php
/**
 * @deprecated CORS handling has been moved to the nginx API Gateway (docker/nginx/nginx.conf).
 * This file is retained for backward compatibility during the transition period.
 * Do NOT add new CORS logic here — all CORS configuration is centralized in nginx.
 * This file will be removed in a future release once all endpoints are served through the API Gateway.
 */
header('Content-Type: application/json');

// Allow requests from the same origin (relative API calls) and any explicit origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['http://anora.bet', 'https://anora.bet', 'http://anora.local', 'http://localhost:5173'];
if (in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // Same-origin requests (no Origin header) are always fine
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Surface PHP errors as JSON so the frontend can show them
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
});
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    http_response_code(500);
    echo json_encode(['error' => "$errstr in $errfile:$errline"]);
    exit;
});
