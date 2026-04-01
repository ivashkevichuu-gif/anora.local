<?php
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/webhook_handler.php';

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '';

$npConfig = require __DIR__ . '/../../config/nowpayments.php';
$handler  = new WebhookHandler($pdo, $npConfig['ipn_secret']);

try {
    $result = $handler->handle($rawBody, $signature);
    http_response_code(200);
    echo json_encode($result);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
