<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';

$input = json_decode(file_get_contents('php://input'), true);
if (($input['username'] ?? '') === 'admin' && ($input['password'] ?? '') === 'admin') {
    $_SESSION['admin'] = true;
    echo json_encode(['message' => 'Admin logged in.']);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials.']);
}
