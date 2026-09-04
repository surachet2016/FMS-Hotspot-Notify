<?php
require_once __DIR__ . '/../lib/auth.php';
requireAdminPage();

$allowed = [
    'mac'     => ['path' => '../downloads/sync_mac.command',  'name' => 'sync_mac.command',  'mime' => 'application/octet-stream'],
    'windows' => ['path' => '../downloads/sync_windows.ps1',  'name' => 'sync_windows.ps1',  'mime' => 'application/octet-stream'],
    'windows-py' => ['path' => '../downloads/sync_windows.py', 'name' => 'sync_windows.py',  'mime' => 'text/x-python'],
    'python'  => ['path' => '../downloads/sync.py',           'name' => 'sync.py',           'mime' => 'text/x-python'],
];

$key  = $_GET['file'] ?? '';

if (!isset($allowed[$key])) {
    http_response_code(404);
    exit('File not found.');
}

$file = $allowed[$key];
$path = realpath(__DIR__ . '/' . $file['path']);

if (!$path || !file_exists($path)) {
    http_response_code(404);
    exit('File not found.');
}

header('Content-Type: '        . $file['mime']);
header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
header('Content-Length: '      . filesize($path));
header('Cache-Control: no-cache');
readfile($path);
exit;
