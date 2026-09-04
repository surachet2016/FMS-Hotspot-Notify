<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/deletion_queue.php';
require_once __DIR__ . '/../lib/sync_auth.php';

header('Content-Type: application/json');

requireSyncBearerAuth();

$action = $_GET['action'] ?? '';
$pdo    = db();

// GET members that need to be synced to MikroTik:
// - ONLY admin-approved members (status='ACTIVE') that have not yet been
//   pushed to RouterOS (mikrotik_synced=0). PENDING members must never be
//   auto-synced here — that would bypass admin review of the ID card image.
if ($action === 'pending') {
    $rows = $pdo->query(
        "SELECT id, full_name, citizen_id, dob, profile FROM members
         WHERE status = 'ACTIVE' AND mikrotik_synced = 0"
    )->fetchAll();
    echo json_encode($rows);
    exit;
}

// POST mark member as ACTIVE and synced to MikroTik
if ($action === 'activate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = $body['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

    $stmt = $pdo->prepare("UPDATE members SET status = 'ACTIVE', mikrotik_synced = 1 WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'deletions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(pendingMikrotikDeletions());
    exit;
}

if ($action === 'delete_complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = is_array($body) ? (string) ($body['id'] ?? '') : '';
    if ($id === '') { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM members WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->commit();
        removeMikrotikDeletion($id);
        echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to complete deletion']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
