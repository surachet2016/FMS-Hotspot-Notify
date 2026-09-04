<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password =      $body['password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password required.']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials.']);
        exit;
    }

    startAdminSession();
    session_regenerate_id(true);
    $_SESSION['admin_id']       = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];

    echo json_encode(['ok' => true, 'username' => $admin['username']]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error.']);
}
