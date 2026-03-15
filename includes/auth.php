<?php
function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin() {
    if (empty($_SESSION['admin'])) {
        header('Location: /admin/index.php');
        exit;
    }
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}
