<?php
require_once __DIR__ . '/../lib/auth.php';
requireAdminPage();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>เปลี่ยนรหัสผ่าน — Hotspot Admin</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    .password-wrapper {
      position: relative;
    }
    .password-wrapper input {
      padding-right: 2.5rem;
      width: 100%;
      box-sizing: border-box;
    }
    .password-toggle {
      position: absolute;
      right: .6rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      padding: .3rem;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #6b7280;
      border-radius: 4px;
    }
    .password-toggle:hover {
      color: #1a56db;
      background: rgba(26, 86, 219, .08);
    }
    .password-toggle svg {
      width: 18px;
      height: 18px;
      display: block;
    }
  </style>
</head>
<body>
  <div class="card-wrapper">
    <div class="card">
      <div class="card-logo">
        <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect width="40" height="40" rx="10" fill="#1a56db"/>
          <path d="M20 8C13.373 8 8 13.373 8 20s5.373 12 12 12 12-5.373 12-12S26.627 8 20 8zm0 4a8 8 0 1 1 0 16A8 8 0 0 1 20 12zm0 3a5 5 0 1 0 0 10A5 5 0 0 0 20 15zm0 3a2 2 0 1 1 0 4 2 2 0 0 1 0-4z" fill="#fff"/>
        </svg>
        <div class="card-logo-text">
          <h1>เปลี่ยนรหัสผ่าน</h1>
          <p>สวัสดี, <?= htmlspecialchars($_SESSION['admin_username'] ?? '') ?></p>
        </div>
      </div>

      <div id="alert" class="alert"></div>

      <form id="changePasswordForm" novalidate>
        <div class="form-group">
          <label for="current_password">รหัสผ่านปัจจุบัน</label>
          <div class="password-wrapper">
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" />
            <button type="button" class="password-toggle" data-target="current_password" aria-label="แสดง/ซ่อนรหัสผ่าน"></button>
          </div>
        </div>

        <div class="form-group">
          <label for="new_password">รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)</label>
          <div class="password-wrapper">
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" />
            <button type="button" class="password-toggle" data-target="new_password" aria-label="แสดง/ซ่อนรหัสผ่าน"></button>
          </div>
        </div>

        <div class="form-group">
          <label for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
          <div class="password-wrapper">
            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" />
            <button type="button" class="password-toggle" data-target="confirm_password" aria-label="แสดง/ซ่อนรหัสผ่าน"></button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary" id="submitBtn">บันทึกรหัสผ่านใหม่</button>
      </form>

      <p style="text-align:center;margin-top:1.2rem;font-size:.85rem;color:#6b7280;">
        <a href="index.php" class="text-link">← กลับหน้าจัดการสมาชิก</a>
      </p>
    </div>
  </div>

  <script>
    // SVG icons for eye open / closed
    const EYE_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_CLOSED = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    // Initialize all password toggles
    document.querySelectorAll('.password-toggle').forEach(btn => {
      btn.innerHTML = EYE_CLOSED;
      btn.addEventListener('click', () => {
        const targetId = btn.dataset.target;
        const input = document.getElementById(targetId);
        if (input.type === 'password') {
          input.type = 'text';
          btn.innerHTML = EYE_OPEN;
        } else {
          input.type = 'password';
          btn.innerHTML = EYE_CLOSED;
        }
      });
    });

    const form = document.getElementById('changePasswordForm');
    const alertBox = document.getElementById('alert');
    const btn = document.getElementById('submitBtn');

    function showAlert(msg, type) {
      alertBox.textContent = msg;
      alertBox.className = 'alert ' + type;
      alertBox.style.display = 'block';
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      alertBox.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'กำลังบันทึก...';

      const payload = {
        current_password: document.getElementById('current_password').value,
        new_password: document.getElementById('new_password').value,
        confirm_password: document.getElementById('confirm_password').value,
      };

      try {
        const res = await fetch('../api/change_password.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload),
          credentials: 'same-origin',
        });
        const data = await res.json();
        if (res.ok) {
          showAlert(data.message || 'เปลี่ยนรหัสผ่านสำเร็จ', 'success');
          form.reset();
        } else {
          showAlert(data.error || 'เกิดข้อผิดพลาด', 'error');
        }
      } catch (err) {
        showAlert('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
      } finally {
        btn.disabled = false;
        btn.textContent = 'บันทึกรหัสผ่านใหม่';
      }
    });
  </script>
</body>
</html>
