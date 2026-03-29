<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
session_destroy();
echo json_encode(['message' => 'Logged out.']);
