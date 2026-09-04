<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/mikrotik.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/deletion_queue.php';

header('Content-Type: application/json');
requireAdmin();
verifyCsrfOrigin();
set_time_limit(120); // prevent PHP timeout when MikroTik is slow to respond

$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

// ── Helper: build MikroTik password from DOB "YYYY-MM-DD" → "DDMMYYYY" ──
function buildMikrotikPassword(string $dob): string {
    $parts = explode('-', $dob); // [YYYY, MM, DD]
    $year  = $parts[0] ?? '';
    $month = $parts[1] ?? '';
    $day   = $parts[2] ?? '';
    return $day . $month . $year;
}

// ── Helper: activate a single member by id ──
// Returns: ['success' => bool, 'member' => array|null, 'error' => string|null]
function activateSingleMember(PDO $pdo, string $id): array {
    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, citizen_id, dob, profile FROM members WHERE id = ?'
    );
    $stmt->execute([$id]);
    $member = $stmt->fetch();

    if (!$member) {
        return ['success' => false, 'member' => null, 'error' => 'Member not found.'];
    }

    $password = buildMikrotikPassword($member['dob']);
    $synced      = false;
    $mikrotikErr = null;

    try {
        $mt = new MikrotikClient();
        $mt->connect(MIKROTIK_HOST, MIKROTIK_PORT);
        $mt->login(MIKROTIK_USER, MIKROTIK_PASS);
        $result = $mt->addHotspotUser(
            $member['citizen_id'],
            $password,
            $member['profile'],
            $member['full_name']
        );
        $mt->close();
        $synced = (bool) $result;
    } catch (\Throwable $e) {
        $synced      = false;
        $mikrotikErr = $e->getMessage();
    }

    // Always update DB status regardless of MikroTik outcome
    $upd = $pdo->prepare('UPDATE members SET status = \'ACTIVE\', mikrotik_synced = ? WHERE id = ?');
    $upd->execute([$synced ? 1 : 0, $id]);

    try {
        sendActivationEmail($member['email'], $member['full_name'], $member['citizen_id'], $password);
    } catch (Exception $e) {
        // ignored
    }

    $sel = $pdo->prepare(
        'SELECT id, full_name, email, citizen_id, dob, profile, admin_note, mikrotik_synced, status,
                (id_card_image IS NOT NULL) AS has_image, created_at
         FROM members WHERE id = ?'
    );
    $sel->execute([$id]);
    $updated = $sel->fetch();

    if ($updated) {
        $updated['mikrotik_synced'] = (bool) $updated['mikrotik_synced'];
        $updated['has_image']       = (bool) $updated['has_image'];
    }

    return ['success' => true, 'member' => $updated, 'error' => null, 'mikrotik_error' => $mikrotikErr];
}

try {
    $pdo = db();

    // ── GET — paginated + filtered list ──────────────────────────────────────
    if ($method === 'GET') {
        $page   = max(1, (int) ($_GET['page']   ?? 1));
        $status = $_GET['status'] ?? '';
        $search = trim($_GET['search'] ?? '');
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if (in_array($status, ['PENDING', 'ACTIVE', 'SUSPENDED'], true)) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $like     = '%' . $search . '%';
            $where[]  = '(full_name LIKE ? OR citizen_id LIKE ? OR email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM members $whereSQL");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));

        $dataStmt = $pdo->prepare(
            "SELECT id, full_name, email, citizen_id, dob, profile, admin_note, mikrotik_synced, status,
                    (id_card_image IS NOT NULL) AS has_image, created_at
             FROM members
             $whereSQL
             ORDER BY created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll();

        foreach ($rows as &$row) {
            $row['mikrotik_synced'] = (bool) $row['mikrotik_synced'];
            $row['has_image']       = (bool) $row['has_image'];
        }
        unset($row);

        echo json_encode([
            'data'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ]);
        exit;
    }

    // ── POST — bulk actions ───────────────────────────────────────────────────
    if ($method === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true);
        $action = $body['action'] ?? '';

        // Single activate (POST fallback — PATCH not supported on all hosts)
        if ($action === 'activate_single') {
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing id.']);
                exit;
            }
            $check = $pdo->prepare('SELECT id FROM members WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Member not found.']);
                exit;
            }
            $result = activateSingleMember($pdo, $id);
            if (!$result['success']) {
                http_response_code(404);
                echo json_encode(['error' => $result['error']]);
                exit;
            }
            $response = $result['member'];
            if ($result['mikrotik_error']) {
                $response['_mikrotik_error'] = $result['mikrotik_error'];
            }
            echo json_encode($response);
            exit;
        }

        // Single suspend
        if ($action === 'suspend_single') {
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id.']); exit; }
            $note = isset($body['note']) ? substr((string) $body['note'], 0, 512) : null;
            $sel = $pdo->prepare('SELECT citizen_id, mikrotik_synced FROM members WHERE id = ?');
            $sel->execute([$id]);
            $member = $sel->fetch();
            if (!$member) { http_response_code(404); echo json_encode(['error' => 'Member not found.']); exit; }

            // Disable in MikroTik if previously synced
            $mtErr = null;
            if ($member['mikrotik_synced']) {
                try {
                    $mt = new MikrotikClient();
                    $mt->connect(MIKROTIK_HOST, MIKROTIK_PORT);
                    $mt->login(MIKROTIK_USER, MIKROTIK_PASS);
                    $mt->disableHotspotUser($member['citizen_id']);
                    $mt->close();
                } catch (\Throwable $e) {
                    $mtErr = $e->getMessage();
                }
            }

            $stmt = $pdo->prepare('UPDATE members SET status = \'SUSPENDED\', admin_note = ? WHERE id = ?');
            $stmt->execute([$note, $id]);
            $sel2 = $pdo->prepare('SELECT id, full_name, email, citizen_id, dob, profile, admin_note, mikrotik_synced, status, (id_card_image IS NOT NULL) AS has_image, created_at FROM members WHERE id = ?');
            $sel2->execute([$id]);
            $updated = $sel2->fetch();
            $updated['mikrotik_synced'] = (bool) $updated['mikrotik_synced'];
            $updated['has_image']       = (bool) $updated['has_image'];
            if ($mtErr) $updated['_mikrotik_error'] = $mtErr;
            echo json_encode($updated);
            exit;
        }

        // Single delete
        if ($action === 'delete_single') {
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id.']); exit; }
            $sel = $pdo->prepare('SELECT citizen_id, mikrotik_synced FROM members WHERE id = ?');
            $sel->execute([$id]);
            $member = $sel->fetch();
            if (!$member) { http_response_code(404); echo json_encode(['error' => 'Member not found.']); exit; }

            if ($member['mikrotik_synced']) {
                enqueueMikrotikDeletion((string) $id, (string) $member['citizen_id']);
                http_response_code(202);
                echo json_encode([
                    'message' => 'Deletion queued.',
                    'pending' => true,
                ]);
                exit;
            }

            $stmt = $pdo->prepare('DELETE FROM members WHERE id = ?');
            $stmt->execute([$id]);
            $response = ['message' => 'Member deleted.'];
            echo json_encode($response);
            exit;
        }

        if ($action === 'bulk_activate') {
            $ids = $body['ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing or empty ids array.']);
                exit;
            }
            if (count($ids) > 100) {
                http_response_code(400);
                echo json_encode(['error' => 'Too many IDs. Max 100 per request.']);
                exit;
            }

            $succeeded = [];
            $failed    = [];

            foreach ($ids as $memberId) {
                $result = activateSingleMember($pdo, (string) $memberId);
                if ($result['success']) {
                    $succeeded[] = $memberId;
                } else {
                    $failed[] = ['id' => $memberId, 'error' => $result['error']];
                }
            }

            echo json_encode(['succeeded' => $succeeded, 'failed' => $failed]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        exit;
    }

    // ── PATCH — update status ─────────────────────────────────────────────────
    if ($method === 'PATCH') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id.']);
            exit;
        }

        $body   = json_decode(file_get_contents('php://input'), true);
        $status = $body['status'] ?? '';

        if (!in_array($status, ['PENDING', 'ACTIVE', 'SUSPENDED'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid status value.']);
            exit;
        }

        // Verify member exists first (needed for non-activate paths)
        $check = $pdo->prepare('SELECT id FROM members WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found.']);
            exit;
        }

        if ($status === 'ACTIVE') {
            $result = activateSingleMember($pdo, $id);
            if (!$result['success']) {
                http_response_code(404);
                echo json_encode(['error' => $result['error']]);
                exit;
            }
            $response = $result['member'];
            if ($result['mikrotik_error']) {
                $response['_mikrotik_error'] = $result['mikrotik_error'];
            }
            echo json_encode($response);
            exit;
        }

        if ($status === 'SUSPENDED') {
            $note = isset($body['note']) ? substr((string) $body['note'], 0, 512) : null;
            $stmt = $pdo->prepare('UPDATE members SET status = \'SUSPENDED\', admin_note = ? WHERE id = ?');
            $stmt->execute([$note, $id]);
        } else {
            // PENDING — simple status update
            $stmt = $pdo->prepare('UPDATE members SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
        }

        $sel = $pdo->prepare(
            'SELECT id, full_name, email, citizen_id, dob, profile, admin_note, mikrotik_synced, status,
                    (id_card_image IS NOT NULL) AS has_image, created_at
             FROM members WHERE id = ?'
        );
        $sel->execute([$id]);
        $updated = $sel->fetch();

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found.']);
            exit;
        }

        $updated['mikrotik_synced'] = (bool) $updated['mikrotik_synced'];
        $updated['has_image']       = (bool) $updated['has_image'];

        echo json_encode($updated);
        exit;
    }

    // ── DELETE ────────────────────────────────────────────────────────────────
    if ($method === 'DELETE') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id.']);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM members WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found.']);
            exit;
        }
        echo json_encode(['message' => 'Member deleted.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error.']);
}
