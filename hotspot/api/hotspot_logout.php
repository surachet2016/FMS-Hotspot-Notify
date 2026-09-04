<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/mikrotik.php';

header('Content-Type: application/json');
// Restrict CORS to the MikroTik hotspot login origin only
header('Access-Control-Allow-Origin: http://fmswifi.login');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $mt = new MikrotikClient();
    $mt->connect(MIKROTIK_HOST, MIKROTIK_PORT);
    $mt->login(MIKROTIK_USER, MIKROTIK_PASS);

    // Resolve identity server-side from the caller's IP — never trust client-supplied values.
    // The caller must have an active hotspot session; this also restricts to hotspot clients only.
    $session = $mt->getActiveSessionByIp($clientIp);
    if (!$session) {
        $mt->close();
        http_response_code(403);
        echo json_encode(['error' => 'No active hotspot session for this IP']);
        exit;
    }

    $username = $session['user'] ?? '';
    $mac      = $session['mac-address'] ?? '';

    $mt->cleanupHotspotSession($username, $mac);
    $mt->close();
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    // Don't block the logout even if cleanup fails
    echo json_encode(['ok' => true, 'warning' => $e->getMessage()]);
}
