<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

requireAdmin();

// GET ?id=<uuid>
$id = $_GET['id'] ?? '';
if (!$id) { http_response_code(400); exit; }

$pdo  = db();
$stmt = $pdo->prepare('SELECT id_card_image, id_card_mime FROM members WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row || !$row['id_card_image']) { http_response_code(404); exit; }

$allowedMimes = ['image/jpeg' => true, 'image/png' => true];
$mime = $row['id_card_mime'];
if (!isset($allowedMimes[$mime])) { http_response_code(415); exit; }

header('Content-Type: ' . $mime);
header('Cache-Control: no-store');
echo $row['id_card_image'];
