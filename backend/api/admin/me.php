<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();
echo json_encode(['admin' => true]);
