<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Hotspot Member Registration</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    select {
      width: 100%;
      padding: .6rem .85rem;
      border: 1.5px solid #d1d5db;
      border-radius: 8px;
      font-size: .95rem;
      background: #fff;
      transition: border-color .2s, box-shadow .2s;
      outline: none;
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7280' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right .85rem center;
    }
    select:focus { border-color: #1a56db; box-shadow: 0 0 0 3px rgba(26,86,219,.15); }
    select.error  { border-color: #ef4444; }
  </style>
</head>
<body>
  <div class="card-wrapper">
    <div class="card">
      <div class="card-logo">
        <img src="img/mgt.jpg" alt="FMS Logo" style="height:52px;border-radius:8px;object-fit:cover;" />
        <div class="card-logo-text">
          <h1>Hotspot Registration</h1>
          <p>คณะวิทยาการจัดการ มหาวิทยาลัยนราธิวาสราชนครินทร์</p>
        </div>
      </div>

      <div id="alert" class="alert"></div>

      <form id="registerForm" novalidate enctype="multipart/form-data">
        <div class="form-group">
          <label for="fullName">Full Name / ชื่อ-สกุล</label>
          <input type="text" id="fullName" name="fullName" placeholder="e.g. John Doe" autocomplete="name" />
          <span class="field-error" id="fullName-err"></span>
        </div>

        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" />
          <span class="field-error" id="email-err"></span>
        </div>

        <div class="form-group">
          <label for="citizenId">Citizen ID / Student ID / รหัสบัตรประชาชน หรือ รหัสนักศึกษา</label>
          <input type="text" id="citizenId" name="citizenId" placeholder="e.g. 1234567890123" />
          <span class="hint">รหัสนี้จะใช้เป็น <strong>Username</strong> สำหรับ Login Hotspot</span>
          <span class="field-error" id="citizenId-err"></span>
        </div>

        <div class="form-group">
          <label>Date of Birth / วันเดือนปีเกิด (พ.ศ.)</label>
          <div class="dob-row">
            <select id="dobDay" class="dob-select">
              <option value="">วัน</option>
            </select>
            <select id="dobMonth" class="dob-select">
              <option value="">เดือน</option>
              <option value="01">มกราคม</option>
              <option value="02">กุมภาพันธ์</option>
              <option value="03">มีนาคม</option>
              <option value="04">เมษายน</option>
              <option value="05">พฤษภาคม</option>
              <option value="06">มิถุนายน</option>
              <option value="07">กรกฎาคม</option>
              <option value="08">สิงหาคม</option>
              <option value="09">กันยายน</option>
              <option value="10">ตุลาคม</option>
              <option value="11">พฤศจิกายน</option>
              <option value="12">ธันวาคม</option>
            </select>
            <select id="dobYear" class="dob-select">
              <option value="">ปี พ.ศ.</option>
            </select>
          </div>
          <input type="hidden" id="dob" name="dob" />
          <span class="hint">วันเกิดนี้จะใช้เป็น <strong>Password</strong> สำหรับ Login Hotspot เช่น เกิด 1 มี.ค. 2540 → รหัสผ่าน 01032540</span>
          <span class="field-error" id="dob-err"></span>
        </div>

        <div class="form-group">
          <label for="profile">Profile / ประเภทผู้ใช้</label>
          <select id="profile" name="profile">
            <option value="student">Student / นักศึกษา</option>
            <option value="teacher">Teacher / อาจารย์</option>
            <option value="employee">Employee / เจ้าหน้าที่</option>
            <option value="default" selected>Other / อื่นๆ</option>
          </select>
          <span class="field-error" id="profile-err"></span>
        </div>

        <div class="form-group">
          <label for="idCard">รูปบัตรประจำตัว (บัตรประชาชน / บัตรนักศึกษา)</label>
          <input type="file" id="idCard" name="idCard" accept="image/jpeg,image/png,.jpg,.jpeg,.png" />
          <span class="hint">ไฟล์ JPEG หรือ PNG ขนาดไม่เกิน 5 MB</span>
          <span class="field-error" id="idCard-err"></span>
        </div>

        <div class="info-box">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:.1rem;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span>หลังจากสมัครสำเร็จ admin จะทำการตรวจสอบข้อมูลและอนุมัติ</span>
        </div>

        <div class="form-group" style="margin-top:1rem;">
          <label for="pdpaConsent" class="pdpa-consent-label">
            <input type="checkbox" id="pdpaConsent" name="pdpaConsent" required />
            <span>
              ข้าพเจ้ายินยอมให้ FMS Hotspot เก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลของข้าพเจ้าตาม
              <a href="#" id="pdpaPolicyLink" style="color:#0891b2;text-decoration:underline;">เอกสารแจ้งเจตนาการเก็บรวบรวมข้อมูลส่วนบุคคล (PDPA)</a>
              เพื่อวัตถุประสงค์ในการใช้บริการ Wi-Fi Hotspot เท่านั้น
            </span>
          </label>
          <span class="field-error" id="pdpaConsent-err"></span>
        </div>

        <button type="submit" class="btn btn-primary" id="submitBtn">Register</button>
      </form>

      <p style="text-align:center;margin-top:1.2rem;font-size:.85rem;color:#6b7280;">
        Are you an admin? <a href="admin/login.php" class="text-link">Sign in here</a>
      </p>
    </div>
  </div>

  <script src="js/register.js"></script>
  <div id="pdpaModal" class="pdpa-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
    <div class="pdpa-modal-content" style="background:#fff;max-width:640px;width:100%;max-height:90vh;overflow-y:auto;border-radius:12px;padding:1.5rem;box-shadow:0 10px 40px rgba(0,0,0,.25);">
      <h2 style="margin:0 0 1rem;font-size:1.2rem;color:#0891b2;">เอกสารแจ้งเจตนาการเก็บรวบรวมข้อมูลส่วนบุคคล</h2>
      <p style="font-size:.85rem;color:#6b7280;margin:0 0 1rem;">ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA)</p>

      <h3 style="font-size:.95rem;margin:1rem 0 .4rem;">1. ผู้ควบคุมข้อมูล</h3>
      <p style="margin:0;font-size:.9rem;">FMS Hotspot Management System<br>คณะวิทยาการจัดการ มหาวิทยาลัยนราธิวาสราชนครินทร์</p>

      <h3 style="font-size:.95rem;margin:1rem 0 .4rem;">2. ข้อมูลที่เก็บรวบรวม</h3>
      <ul style="margin:.3rem 0;font-size:.9rem;padding-left:1.2rem;">
        <li>ชื่อ-นามสกุล</li>
        <li>อีเมล</li>
        <li>รหัสบัตรประจำตัวประชาชน</li>
        <li>วันเกิด</li>
        <li>รูปภาพบัตรประจำตัว (เพื่อยืนยันตัวตน)</li>
        <li>ข้อมูลการเข้าใช้งาน Wi-Fi (username, IP, เวลาเชื่อมต่อ)</li>
      </ul>

      <h3 style="font-size:.95rem;margin:1rem 0 .4rem;">3. วัตถุประสงค์</h3>
      <ul style="margin:.3rem 0;font-size:.9rem;padding-left:1.2rem;">
        <li>ยืนยันตัวตนผู้ใช้บริการ Wi-Fi Hotspot</li>
        <li>ตรวจสอบสิทธิ์การเข้าถึงเครือข่าย</li>
        <li>บำรุงรักษาระบบและแก้ไขปัญหา</li>
        <li>ปฏิบัติตามกฎหมายและข้อบังคับของมหาวิทยาลัย</li>
      </ul>

      <h3 style="font-size:.95rem;margin:1rem 0 .4rem;">4. ระยะเวลาเก็บรักษา</h3>
      <p style="margin:0;font-size:.9rem;">ตลอดระยะเวลาที่ใช้บริการ และ 90 วันหลังยกเลิกการใช้งาน จากนั้นลบ/ทำลายตามระเบียบ</p>

      <h3 style="font-size:.95rem;margin:1rem 0 .4rem;">5. สิทธิ์ของท่าน</h3>
      <ul style="margin:.3rem 0;font-size:.9rem;padding-left:1.2rem;">
        <li>ขอเข้าถึง/แก้ไข/ลบข้อมูล</li>
        <li>ถอนความยินยอมได้ทุกเมื่อ (ติดต่อ admin)</li>
        <li>ร้องเรียนต่อหน่วยงานคุ้มครองข้อมูล</li>
      </ul>

      <h3 style="font-size:.95rem;margin:1rem 0 .4rem;">6. Cookies</h3>
      <p style="margin:0;font-size:.9rem;">เว็บไซต์ใช้ session cookie สำหรับยืนยันตัวตน admin เท่านั้น ไม่มีการติดตามหรือโฆษณา</p>

      <div style="margin-top:1.5rem;text-align:right;">
        <button type="button" id="pdpaCloseBtn" class="btn btn-primary">เข้าใจและปิด</button>
      </div>
    </div>
  </div>

  <footer style="margin-top:3rem;padding:1.5rem 1rem;text-align:center;color:#6b7280;font-size:.85rem;">
    <div style="margin-bottom:.4rem;">
      Create by <strong style="color:#374151;">FMS: Information Technology Team</strong>
    </div>
    <div>
      &copy; <?= date('Y') ?> FMS Hotspot Management System. All rights reserved.
    </div>
  </footer>

  <script>
    document.getElementById('pdpaPolicyLink').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('pdpaModal').style.display = 'flex';
    });
    document.getElementById('pdpaCloseBtn').addEventListener('click', function() {
      document.getElementById('pdpaModal').style.display = 'none';
    });
    document.getElementById('pdpaModal').addEventListener('click', function(e) {
      if (e.target === this) this.style.display = 'none';
    });
  </script>
</body>
</html>
