<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'ivash536_anora');
define('DB_PASS', 'QjMVmHxVh73cQwnaXUQz');
define('DB_NAME', 'ivash536_anora');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
