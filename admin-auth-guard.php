<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['account_id']) || !isset($_SESSION['role'])) {
    header('Location: ../landing-page/log-in.php');
    exit;
}

if (($_SESSION['role'] ?? null) !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admins only.');
}