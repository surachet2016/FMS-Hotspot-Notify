<?php
/**
 * Hotspot access log endpoint — receives per-session events from the
 * OpenClaw sync daemon and persists them to hotspot_access_logs.
 *
 * Required by Thailand's Computer Crime Act B.E. 2550 (amended 2560)
 * Section 26: service providers must retain traffic data >= 90 days.
 *
 * Authentication: Bearer token via Authorization header.
 *
 * Payload schema (one event per POST):
 * {
 *   "event": "login" | "logout" | "update",
 *   "username":   "citizen_id",           (required)
 *   "full_name":  "optional",             (optional)
 *   "citizen_id": "1-2345-...",
 *   "src_ip":     "10.12.1.151",          (required)
 *   "mac_address":"AA:BB:CC:DD:EE:FF",   (optional)
 *   "session_id": "MikroTik-xxx",         (optional)
 *   "nas_ip":     "10.12.0.1",            (optional)
 *   "nas_name":   "MikroTik-Hotspot",     (optional)
 *   "login_at":   "2026-09-04T03:30:00Z", (required, ISO 8601)
 *   "logout_at":  "2026-09-04T04:15:00Z", (optional)
 *   "duration_s": 2700,                   (optional)
 *   "bytes_in":   12345,                  (optional)
 *   "bytes_out":  67890,                  (optional)
 *   "destination_count": 42,              (optional)
 *   "user_agent": "Mozilla/5.0 ...",     (optional, truncated to 256)
 *   "raw_mikrotik": { ... }               (optional, full MikroTik active row)
 * }
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/sync_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

requireSyncBearerAuth();

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65536) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request body']);
    exit;
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// Allow single event or batch (array of events)
$events = isset($body[0]) ? $body : [$body];

try {
    $pdo = db();
} catch (PDOException $e) {
    error_log('log_session db connect failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$inserted = 0;
$errors = [];

foreach ($events as $idx => $event) {
    if (!is_array($event)) {
        $errors[] = "event[$idx]: not an object";
        continue;
    }

    $username    = trim((string) ($event['username'] ?? ''));
    $srcIp       = trim((string) ($event['src_ip'] ?? ''));
    $loginAt     = trim((string) ($event['login_at'] ?? ''));
    $eventType   = trim((string) ($event['event'] ?? 'login'));

    if ($username === '' || $srcIp === '' || $loginAt === '') {
        $errors[] = "event[$idx]: missing required field (username/src_ip/login_at)";
        continue;
    }

    if (!in_array($eventType, ['login', 'logout', 'update'], true)) {
        $errors[] = "event[$idx]: invalid event type '$eventType'";
        continue;
    }

    // Normalize timestamp
    try {
        $dt = new DateTime($loginAt);
        $loginAtSql = $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $errors[] = "event[$idx]: invalid login_at timestamp";
        continue;
    }

    $logoutAtSql = null;
    if (!empty($event['logout_at'])) {
        try {
            $dt2 = new DateTime((string) $event['logout_at']);
            $logoutAtSql = $dt2->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // ignore logout_at parse error, keep null
        }
    }

    // Optional fields with safe defaults
    $fullName  = trim((string) ($event['full_name'] ?? '')) ?: null;
    $citizenId = trim((string) ($event['citizen_id'] ?? '')) ?: null;
    $mac       = trim((string) ($event['mac_address'] ?? '')) ?: null;
    $sessionId = trim((string) ($event['session_id'] ?? '')) ?: null;
    $nasIp     = trim((string) ($event['nas_ip'] ?? '')) ?: null;
    $nasName   = trim((string) ($event['nas_name'] ?? '')) ?: null;
    $durationS = isset($event['duration_s']) ? max(0, (int) $event['duration_s']) : null;
    $bytesIn   = isset($event['bytes_in']) ? max(0, (int) $event['bytes_in']) : 0;
    $bytesOut  = isset($event['bytes_out']) ? max(0, (int) $event['bytes_out']) : 0;
    $destCount = isset($event['destination_count']) ? max(0, (int) $event['destination_count']) : 0;
    $userAgent = trim((string) ($event['user_agent'] ?? ''));
    if (strlen($userAgent) > 256) $userAgent = substr($userAgent, 0, 256);
    $userAgent = $userAgent ?: null;

    $rawMikrotik = null;
    if (isset($event['raw_mikrotik']) && is_array($event['raw_mikrotik'])) {
        $rawMikrotik = json_encode($event['raw_mikrotik'], JSON_UNESCAPED_UNICODE);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO hotspot_access_logs (
                event, username, full_name, citizen_id, src_ip, mac_address,
                session_id, nas_ip, nas_name, login_at, logout_at, duration_s,
                bytes_in, bytes_out, destination_count, user_agent, raw_mikrotik
            ) VALUES (
                :event, :username, :full_name, :citizen_id, :src_ip, :mac,
                :session_id, :nas_ip, :nas_name, :login_at, :logout_at, :duration_s,
                :bytes_in, :bytes_out, :destination_count, :user_agent, :raw_mikrotik
            )'
        );
        $stmt->execute([
            ':event' => $eventType,
            ':username' => substr($username, 0, 100),
            ':full_name' => $fullName !== null ? substr($fullName, 0, 255) : null,
            ':citizen_id' => $citizenId !== null ? substr($citizenId, 0, 50) : null,
            ':src_ip' => substr($srcIp, 0, 45),
            ':mac' => $mac !== null ? substr($mac, 0, 17) : null,
            ':session_id' => $sessionId !== null ? substr($sessionId, 0, 64) : null,
            ':nas_ip' => $nasIp !== null ? substr($nasIp, 0, 45) : null,
            ':nas_name' => $nasName !== null ? substr($nasName, 0, 100) : null,
            ':login_at' => $loginAtSql,
            ':logout_at' => $logoutAtSql,
            ':duration_s' => $durationS,
            ':bytes_in' => $bytesIn,
            ':bytes_out' => $bytesOut,
            ':destination_count' => $destCount,
            ':user_agent' => $userAgent,
            ':raw_mikrotik' => $rawMikrotik,
        ]);
        $inserted++;
    } catch (PDOException $e) {
        error_log('log_session insert failed: ' . $e->getMessage());
        $errors[] = "event[$idx]: database error";
    }
}

http_response_code($inserted > 0 ? 200 : 400);
echo json_encode([
    'ok' => $inserted > 0,
    'inserted' => $inserted,
    'errors' => $errors,
]);
