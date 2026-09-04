<?php
require_once __DIR__ . '/../config.php';

function startAdminSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isAdminLoggedIn(): bool {
    startAdminSession();
    return !empty($_SESSION['admin_id']);
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function requireAdminPage(): void {
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function verifyCsrfOrigin(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $parsed     = parse_url($origin);
    $originHost = $parsed['host'] ?? '';
    // Strip port from HTTP_HOST (e.g. "example.com:443" → "example.com")
    $serverHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
    if ($originHost !== $serverHost) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}
