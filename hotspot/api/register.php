<?php
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Read multipart fields ──────────────────────────────────────────────────
$fullName  = trim($_POST['fullName']  ?? '');
$email     = trim($_POST['email']     ?? '');
$citizenId = trim($_POST['citizenId'] ?? '');
$dob       = trim($_POST['dob']       ?? '');
$profile   =      $_POST['profile']   ?? '';
$pdpaConsent = trim($_POST['pdpaConsent'] ?? '');

// PDPA: user must explicitly consent before registration can proceed
if ($pdpaConsent !== 'on') {
    http_response_code(400);
    echo json_encode(['error' => 'กรุณายอมรับเงื่อนไขการเก็บรวบรวมข้อมูลส่วนบุคคล (PDPA) ก่อนสมัคร']);
    exit;
}

// Record consent evidence: IP + user agent + timestamp + policy version
$pdpaConsentAt    = gmdate('c');
$pdpaConsentIp    = $_SERVER['REMOTE_ADDR'] ?? '';
$pdpaConsentUa    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256);
$pdpaConsentVer   = '1.0';

// ── Rate limiting by citizenId (แก้ปัญหา NAT: หลาย IP แชร์ IP เดียวกัน) ───
// ใช้ citizenId เป็น key แทน IP — ถ้าไม่มี citizenId ใช้ IP เป็น fallback
try {
    $pdo = db();

    $pdo->exec("DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

    $rl_key = $citizenId !== '' ? 'cid:' . $citizenId : 'ip:' . $_SERVER['REMOTE_ADDR'];
    $stmt = $pdo->prepare("SELECT attempts FROM rate_limits WHERE ip = ? AND endpoint = 'register'");
    $stmt->execute([$rl_key]);
    $rl = $stmt->fetch();

    if ($rl && $rl['attempts'] >= 5) {
        http_response_code(429);
        echo json_encode(['error' => 'คุณส่งข้อมูลเกินกำหนด กรุณารอ 10 นาที']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error. Please try again.']);
    exit;
}

// ── Validate text fields ───────────────────────────────────────────────────
if ($fullName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'กรุณากรอกชื่อ-สกุล']);
    exit;
}

if ($email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'กรุณากรอกอีเมล']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'รูปแบบอีเมลไม่ถูกต้อง']);
    exit;
}

if ($citizenId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'กรุณากรอกรหัสบัตรประชาชน หรือ รหัสนักศึกษา']);
    exit;
}

if ($dob === '') {
    http_response_code(400);
    echo json_encode(['error' => 'กรุณากรอกวันเกิด']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !strtotime($dob)) {
    http_response_code(400);
    echo json_encode(['error' => 'รูปแบบวันที่ไม่ถูกต้อง']);
    exit;
}

if ($profile === '' || !in_array($profile, ['student', 'teacher', 'employee', 'default'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'กรุณาเลือกประเภทผู้ใช้']);
    exit;
}

// ── Validate uploaded file ─────────────────────────────────────────────────
$uploadErrors = [
    UPLOAD_ERR_INI_SIZE  => 'ไฟล์มีขนาดเกินที่กำหนด',
    UPLOAD_ERR_FORM_SIZE => 'ไฟล์มีขนาดเกินที่กำหนด',
    UPLOAD_ERR_NO_FILE   => 'กรุณาแนบรูปบัตรประจำตัว',
];

$fileErr = $_FILES['idCard']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($fileErr !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => $uploadErrors[$fileErr] ?? 'อัปโหลดไฟล์ไม่สำเร็จ']);
    exit;
}

if ($_FILES['idCard']['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'ขนาดไฟล์ต้องไม่เกิน 5 MB']);
    exit;
}

$tmpPath = $_FILES['idCard']['tmp_name'];
if (!is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['error' => 'อัปโหลดไฟล์ไม่ถูกต้อง']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($tmpPath);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'รองรับเฉพาะไฟล์ JPEG และ PNG เท่านั้น']);
    exit;
}

$blob = file_get_contents($tmpPath);

// ── Count this as a real attempt (after all validation passes) ─────────────
try {
    $pdo->prepare(
        "INSERT INTO rate_limits (ip, endpoint) VALUES (?, 'register')
         ON DUPLICATE KEY UPDATE attempts = attempts + 1"
    )->execute([$rl_key]);
} catch (PDOException $e) {
    // Non-fatal — proceed with registration even if rate limit tracking fails
}

// ── Database insert ────────────────────────────────────────────────────────
try {
    $check = $pdo->prepare(
        'SELECT email, citizen_id FROM members
         WHERE email = ? OR citizen_id = ? LIMIT 1'
    );
    $check->execute([$email, $citizenId]);
    $existing = $check->fetch();

    if ($existing) {
        http_response_code(409);
        if ($existing['email'] === $email) {
            echo json_encode(['error' => 'อีเมลนี้ถูกลงทะเบียนแล้ว']);
        } else {
            echo json_encode(['error' => 'รหัสบัตรนี้ถูกลงทะเบียนแล้ว']);
        }
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO members (id, full_name, email, citizen_id, dob, profile, id_card_image, id_card_mime, status, pdpa_consent_at, pdpa_consent_ip, pdpa_consent_ua, pdpa_consent_ver)
         VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?, ?)"
    );
    $stmt->execute([$fullName, $email, $citizenId, $dob, $profile, $blob, $mime, $pdpaConsentAt, $pdpaConsentIp, $pdpaConsentUa, $pdpaConsentVer]);

    http_response_code(201);
    echo json_encode(['message' => 'ลงทะเบียนสำเร็จ สามารถเชื่อมต่อ Hotspot ได้ทันที (ผู้ดูแลจะตรวจสอบภาพบัตรประชาชนภายหลัง)']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error. Please try again.']);
}
