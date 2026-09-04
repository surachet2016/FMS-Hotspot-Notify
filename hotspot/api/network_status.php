<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/sync_auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

$cacheFile = defined('NETWORK_STATUS_CACHE_FILE')
    ? NETWORK_STATUS_CACHE_FILE
    : sys_get_temp_dir() . '/hotspot_network_status.json';
$speedtestFile = defined('NETWORK_SPEEDTEST_STATE_FILE')
    ? NETWORK_SPEEDTEST_STATE_FILE
    : sys_get_temp_dir() . '/hotspot_network_speedtest.json';
$speedtestLockFile = $speedtestFile . '.lock';

function normalizeNetworkInterface(array $value, bool $isWan): array
{
    $name = substr(trim((string) ($value['name'] ?? '')), 0, 64);
    if ($name === '') {
        throw new InvalidArgumentException('Missing interface name');
    }

    $result = [
        'name' => $name,
        'running' => ($value['running'] ?? false) === true,
        'disabled' => ($value['disabled'] ?? true) === true,
        'link' => ($value['link'] ?? false) === true,
        'rx_bps' => max(0, (int) ($value['rx_bps'] ?? 0)),
        'tx_bps' => max(0, (int) ($value['tx_bps'] ?? 0)),
    ];
    if ($isWan) {
        $result['internet_reachable'] = ($value['internet_reachable'] ?? false) === true;
    }
    return $result;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 65536) {
        throw new InvalidArgumentException('Invalid request body');
    }
    if (trim($raw) === '') {
        return [];
    }
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($body) || ($body !== [] && array_keys($body) === range(0, count($body) - 1))) {
        throw new InvalidArgumentException('Invalid request body');
    }
    return $body;
}

function newSpeedtestState(): array
{
    return ['current' => null, 'history' => []];
}

function readSpeedtestState(string $file): array
{
    if (!is_file($file)) {
        return newSpeedtestState();
    }
    $state = json_decode((string) file_get_contents($file), true);
    if (!is_array($state) || (!array_key_exists('current', $state)) || !is_array($state['history'] ?? null)) {
        throw new RuntimeException('Invalid speedtest state');
    }
    $state['history'] = array_values(array_slice($state['history'], 0, 20));
    return $state;
}

function writeSpeedtestState(string $file, array $state): void
{
    $dir = dirname($file);
    $tmp = tempnam($dir, '.hotspot_speedtest_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create speedtest state');
    }
    try {
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $encoded) === false || !rename($tmp, $file)) {
            throw new RuntimeException('Unable to write speedtest state');
        }
        @chmod($file, 0600);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function writeNetworkStatusAtomically(string $file, string $encoded): void
{
    $dir = dirname($file);
    $tmp = tempnam($dir, '.hotspot_network_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create status cache');
    }
    try {
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false || !rename($tmp, $file)) {
            throw new RuntimeException('Unable to write status cache');
        }
        @chmod($file, 0600);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function withSpeedtestState(string $file, string $lockFile, callable $change): array
{
    $lock = fopen($lockFile, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Unable to lock speedtest state');
    }
    try {
        $state = readSpeedtestState($file);
        $result = $change($state);
        writeSpeedtestState($file, $state);
        return is_array($result) ? $result : [];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function validRequestId($value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) === 1;
}

function requestId(): string
{
    return bin2hex(random_bytes(16));
}

function speedtestRequest(array $current): array
{
    $request = [
        'request_id' => $current['request_id'],
        'status' => $current['status'],
        'requested_at' => $current['requested_at'],
    ];
    if (isset($current['started_at'])) $request['started_at'] = $current['started_at'];
    if (isset($current['source'])) $request['source'] = $current['source'];
    return $request;
}

function normalizeSpeedtestResult(array $body): array
{
    $id = $body['request_id'] ?? null;
    if (!validRequestId($id)) {
        throw new InvalidArgumentException('Invalid request id');
    }
    $success = $body['success'] ?? null;
    if ($success === null && isset($body['status']) && is_string($body['status'])) {
        if ($body['status'] === 'success') $success = true;
        if ($body['status'] === 'failed') $success = false;
    }
    if (!is_bool($success)) {
        throw new InvalidArgumentException('Invalid speedtest result');
    }
    $result = [
        'request_id' => $id,
        'status' => $success ? 'success' : 'failed',
        'success' => $success,
        'completed_at' => gmdate('c'),
    ];
    foreach (['download_mbps', 'upload_mbps', 'ping_ms', 'jitter_ms', 'packet_loss_pct'] as $key) {
        if (array_key_exists($key, $body)) {
            if (!is_int($body[$key]) && !is_float($body[$key])) {
                throw new InvalidArgumentException('Invalid speedtest result');
            }
            $value = (float) $body[$key];
            if (!is_finite($value) || $value < 0 || $value > 100000000) {
                throw new InvalidArgumentException('Invalid speedtest result');
            }
            $result[$key] = $value;
        }
    }
    foreach (['source', 'server_name', 'server_location', 'isp'] as $key) {
        if (array_key_exists($key, $body)) {
            if (!is_string($body[$key]) || strlen($body[$key]) > 120) {
                throw new InvalidArgumentException('Invalid speedtest result');
            }
            $result[$key] = $body[$key];
        }
    }
    if (array_key_exists('result_url', $body)) {
        if (!is_string($body['result_url']) || strlen($body['result_url']) > 500
            || ($body['result_url'] !== '' && filter_var($body['result_url'], FILTER_VALIDATE_URL) === false)) {
            throw new InvalidArgumentException('Invalid speedtest result');
        }
        $result['result_url'] = $body['result_url'];
    }
    if (array_key_exists('error', $body)) {
        if (!is_string($body['error']) || strlen($body['error']) > 256) {
            throw new InvalidArgumentException('Invalid speedtest result');
        }
        $result['error'] = $body['error'];
    }
    return $result;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');

if ($method === 'POST' && $action === 'request_speedtest') {
    requireAdmin();
    verifyCsrfOrigin();
    try {
        $body = readJsonBody();
        if ($body !== []) {
            throw new InvalidArgumentException('Invalid request body');
        }
        $request = withSpeedtestState($speedtestFile, $speedtestLockFile, function (array &$state): array {
            if (is_array($state['current']) && in_array($state['current']['status'] ?? '', ['queued', 'running'], true)) {
                http_response_code(409);
                return ['conflict' => true, 'request' => speedtestRequest($state['current'])];
            }
            $current = [
                'request_id' => requestId(),
                'status' => 'queued',
                'requested_at' => gmdate('c'),
                'source' => 'manual',
            ];
            $state['current'] = $current;
            return ['request' => speedtestRequest($current)];
        });
        if (!empty($request['conflict'])) {
            echo json_encode(['ok' => false, 'error' => 'Speedtest already queued or running', 'speedtest' => $request['request']]);
        } else {
            http_response_code(202);
            echo json_encode(['ok' => true, 'speedtest' => $request['request']]);
        }
    } catch (Throwable $e) {
        error_log('Speedtest request failed');
        if (http_response_code() < 400) http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unable to request speedtest']);
    }
    exit;
}

if ($method === 'POST') {
    requireSyncBearerAuth();
    try {
        $body = readJsonBody();
        if ($action === 'speedtest_started') {
            if (!validRequestId($body['request_id'] ?? null)) throw new InvalidArgumentException('Invalid request id');
            $result = withSpeedtestState($speedtestFile, $speedtestLockFile, function (array &$state) use ($body): array {
                $current = $state['current'];
                if (!is_array($current) && ($body['source'] ?? '') === 'scheduled') {
                    $current = [
                        'request_id' => $body['request_id'],
                        'status' => 'queued',
                        'requested_at' => gmdate('c'),
                        'source' => 'scheduled',
                    ];
                    $state['current'] = $current;
                }
                if (!is_array($current) || $current['status'] !== 'queued' || $current['request_id'] !== $body['request_id']) {
                    throw new InvalidArgumentException('Invalid speedtest transition');
                }
                $state['current']['status'] = 'running';
                $state['current']['started_at'] = gmdate('c');
                return speedtestRequest($state['current']);
            });
            echo json_encode(['ok' => true, 'speedtest' => $result]);
            exit;
        }
        if ($action === 'speedtest_result') {
            $result = normalizeSpeedtestResult($body);
            withSpeedtestState($speedtestFile, $speedtestLockFile, function (array &$state) use ($result): array {
                $current = $state['current'];
                if (!is_array($current) || $current['status'] !== 'running' || $current['request_id'] !== $result['request_id']) {
                    throw new InvalidArgumentException('Invalid speedtest transition');
                }
                array_unshift($state['history'], $result);
                $state['history'] = array_slice($state['history'], 0, 20);
                $state['current'] = null;
                return [];
            });
            echo json_encode(['ok' => true]);
            exit;
        }

        $payload = [
            'ok' => true,
            'wan' => normalizeNetworkInterface((array) ($body['wan'] ?? []), true),
            'lan' => normalizeNetworkInterface((array) ($body['lan'] ?? []), false),
            'fetched_at' => gmdate('c'),
        ];
        $payload['healthy'] = $payload['wan']['running'] && !$payload['wan']['disabled']
            && $payload['wan']['link'] && $payload['wan']['internet_reachable']
            && $payload['lan']['running'] && !$payload['lan']['disabled'] && $payload['lan']['link'];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        writeNetworkStatusAtomically($cacheFile, $encoded);
        $state = readSpeedtestState($speedtestFile);
        $payload['report_data'] = ['speedtest' => $state];
        if (is_array($state['current'] ?? null) && ($state['current']['status'] ?? '') === 'queued') {
            $payload['speedtest'] = speedtestRequest($state['current']);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('Network status report failed');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid network status report']);
    }
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

requireAdmin();
$speedtest = ['current' => null, 'history' => []];
try {
    $speedtest = readSpeedtestState($speedtestFile);
} catch (Throwable $e) {
    error_log('Speedtest state read failed');
}
if (!is_file($cacheFile)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้รับข้อมูลสถานะจาก OpenClaw server', 'speedtest' => $speedtest]);
    exit;
}
$payload = json_decode((string) file_get_contents($cacheFile), true);
if (!is_array($payload) || empty($payload['fetched_at'])) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'ข้อมูลสถานะเครือข่ายไม่สมบูรณ์', 'speedtest' => $speedtest]);
    exit;
}
$maxAge = defined('NETWORK_STATUS_MAX_AGE') ? (int) NETWORK_STATUS_MAX_AGE : 150;
$age = time() - (int) strtotime($payload['fetched_at']);
$payload['stale'] = $age > max(30, $maxAge);
$payload['age_seconds'] = max(0, $age);
$payload['speedtest'] = $speedtest;
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
