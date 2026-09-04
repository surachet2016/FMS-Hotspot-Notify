<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/sync_auth.php';

$reportFile = defined('NETWORK_REPORT_FILE')
    ? NETWORK_REPORT_FILE
    : sys_get_temp_dir() . '/hotspot_network_report.pdf';
$metaFile = $reportFile . '.json';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private');
    requireSyncBearerAuth();
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (strpos($contentType, 'application/pdf') !== 0 || $length < 100 || $length > 5242880) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid report']);
        exit;
    }
    $pdf = file_get_contents('php://input');
    if ($pdf === false || substr($pdf, 0, 5) !== '%PDF-' || strlen($pdf) > 5242880) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid report']);
        exit;
    }
    $tmp = tempnam(dirname($reportFile), '.hotspot_report_');
    if ($tmp === false || file_put_contents($tmp, $pdf, LOCK_EX) === false || !rename($tmp, $reportFile)) {
        if ($tmp && is_file($tmp)) @unlink($tmp);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to store report']);
        exit;
    }
    @chmod($reportFile, 0600);
    file_put_contents($metaFile, json_encode(['generated_at' => gmdate('c')]), LOCK_EX);
    @chmod($metaFile, 0600);
    echo json_encode(['ok' => true, 'generated_at' => gmdate('c')]);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

requireAdmin();
if (!is_file($reportFile)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'รายงานยังไม่พร้อม กรุณารอสักครู่แล้วลองใหม่']);
    exit;
}
$filename = 'hotspot-network-report-' . date('Ymd-His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($reportFile));
header('Cache-Control: no-store, private');
readfile($reportFile);
