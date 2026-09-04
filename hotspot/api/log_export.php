<?php
/**
 * Export hotspot access logs to CSV.
 *
 * Used by admin to download logs for compliance reporting
 * (Computer Crime Act B.E. 2550 Section 26).
 *
 * Query params:
 *   - from (date YYYY-MM-DD, default: 90 days ago)
 *   - to   (date YYYY-MM-DD, default: today)
 *   - username (optional filter)
 *   - citizen_id (optional filter)
 *   - src_ip   (optional filter)
 *   - format (csv | json, default: csv)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: text/csv; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo "Method not allowed";
    exit;
}

requireAdmin();

// ── Parse filters ──
$today = new DateTimeImmutable('now');
$defaultFrom = $today->modify('-90 days');

$fromDate = $_GET['from'] ?? $defaultFrom->format('Y-m-d');
$toDate   = $_GET['to'] ?? $today->format('Y-m-d');
$username = trim($_GET['username'] ?? '');
$citizenId = trim($_GET['citizen_id'] ?? '');
$srcIp    = trim($_GET['src_ip'] ?? '');
$format   = strtolower($_GET['format'] ?? 'csv');

// Validate dates
try {
    new DateTimeImmutable($fromDate);
} catch (Exception $e) {
    http_response_code(400);
    echo "Invalid 'from' date";
    exit;
}
try {
    new DateTimeImmutable($toDate);
} catch (Exception $e) {
    http_response_code(400);
    echo "Invalid 'to' date";
    exit;
}

if (!in_array($format, ['csv', 'json'], true)) {
    $format = 'csv';
}

// ── Build query ──
$where = ['login_at >= ?', 'login_at < ? + INTERVAL 1 DAY'];
$params = [$fromDate, $toDate];

if ($username !== '') {
    $where[] = 'username = ?';
    $params[] = $username;
}
if ($citizenId !== '') {
    $where[] = 'citizen_id = ?';
    $params[] = $citizenId;
}
if ($srcIp !== '') {
    $where[] = 'src_ip = ?';
    $params[] = $srcIp;
}

$sql = 'SELECT id, event, username, full_name, citizen_id, src_ip, mac_address, '
    . 'session_id, nas_ip, nas_name, login_at, logout_at, duration_s, '
    . 'bytes_in, bytes_out, destination_count, user_agent, received_at '
    . 'FROM hotspot_access_logs WHERE ' . implode(' AND ', $where)
    . ' ORDER BY login_at DESC, id DESC LIMIT 100000';

try {
    $pdo = db();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('log_export db error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Database error';
    exit;
}

// ── Output ──
$filename = sprintf(
    'hotspot-logs_%s_to_%s_%s.%s',
    $fromDate,
    $toDate,
    date('Ymd-His'),
    $format
);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Total-Rows: ' . count($rows));

if ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'filters' => [
            'from' => $fromDate, 'to' => $toDate,
            'username' => $username, 'citizen_id' => $citizenId, 'src_ip' => $srcIp,
        ],
        'rows' => $rows,
        'count' => count($rows),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// CSV with UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
$headers = [
    'id', 'event', 'username', 'full_name', 'citizen_id',
    'src_ip', 'mac_address', 'session_id', 'nas_ip', 'nas_name',
    'login_at', 'logout_at', 'duration_s',
    'bytes_in', 'bytes_out', 'destination_count',
    'user_agent', 'received_at',
];
fputcsv($out, $headers);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['id'],
        $row['event'],
        $row['username'],
        $row['full_name'],
        $row['citizen_id'],
        $row['src_ip'],
        $row['mac_address'],
        $row['session_id'],
        $row['nas_ip'],
        $row['nas_name'],
        $row['login_at'],
        $row['logout_at'],
        $row['duration_s'],
        $row['bytes_in'],
        $row['bytes_out'],
        $row['destination_count'],
        $row['user_agent'],
        $row['received_at'],
    ]);
}
fclose($out);
