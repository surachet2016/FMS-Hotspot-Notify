<?php
require_once __DIR__ . '/../config.php';

function sendActivationEmail(string $to, string $fullName, string $citizenId, string $password): bool
{
    $subject = '=?UTF-8?B?' . base64_encode('บัญชี Hotspot ของคุณได้รับการอนุมัติแล้ว') . '?=';

    $body = implode("\r\n", [
        'เรียน ' . $fullName,
        '',
        'บัญชี Hotspot ของคุณได้รับการอนุมัติแล้ว สามารถเชื่อมต่อ WiFi Hotspot ได้ทันที',
        '',
        'ชื่อผู้ใช้ (Username) : ' . $citizenId,
        'รหัสผ่าน (Password)   : ' . $password,
        '',
        'หากมีปัญหาการใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
        '',
        '--',
        MAIL_FROM_NAME,
    ]);

    $headers = implode("\r\n", [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);

    return mail($to, $subject, $body, $headers);
}
