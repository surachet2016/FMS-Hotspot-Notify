<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json');
requireAdmin();
verifyCsrfOrigin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body            = json_decode(file_get_contents('php://input'), true);
$currentPassword = $body['current_password'] ?? '';
$newPassword     = $body['new_password']     ?? '';
$confirmPassword = $body['confirm_password'] ?? '';

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    http_response_code(400);
    echo json_encode(['error' => 'กรุณากรอกข้อมูลให้ครบทุกช่อง']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'รหัสผ่านใหม่และการยืนยันไม่ตรงกัน']);
    exit;
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร']);
    exit;
}

if ($newPassword === $currentPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'รหัสผ่านใหม่ต้องต่างจากรหัสเดิม']);
    exit;
}

try {
    $pdo = db();

    // Verify current password
    $stmt = $pdo->prepare('SELECT id, password_hash FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        exit;
    }

    // Update to new password
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $upd = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
    $upd->execute([$newHash, $admin['id']]);

    // Regenerate session ID to prevent session fixation after credential change
    session_regenerate_id(true);

    echo json_encode(['ok' => true, 'message' => 'เปลี่ยนรหัสผ่านสำเร็จ']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error.']);
}
