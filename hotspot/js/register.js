const form      = document.getElementById('registerForm');
const alertEl   = document.getElementById('alert');
const submitBtn = document.getElementById('submitBtn');

// ── DOB picker setup ─────────────────────────────────────────────────────────
const dobDay    = document.getElementById('dobDay');
const dobMonth  = document.getElementById('dobMonth');
const dobYear   = document.getElementById('dobYear');
const dobHidden = document.getElementById('dob');

// Populate day options 1–31
for (let d = 1; d <= 31; d++) {
  const opt = document.createElement('option');
  opt.value = String(d).padStart(2, '0');
  opt.textContent = d;
  dobDay.appendChild(opt);
}

// Populate year options: current พ.ศ. down to 2480
const currentBE = new Date().getFullYear() + 543;
for (let y = currentBE; y >= 2480; y--) {
  const opt = document.createElement('option');
  opt.value = String(y);
  opt.textContent = y;
  dobYear.appendChild(opt);
}

function getDaysInMonth(month, beYear) {
  if (!month || !beYear) return 31;
  const ceYear = beYear - 543;
  return new Date(ceYear, month, 0).getDate(); // day-0 of next month = last day of this month
}

function refreshDayOptions() {
  const m       = parseInt(dobMonth.value, 10) || 0;
  const y       = parseInt(dobYear.value,  10) || 0;
  const maxDays = getDaysInMonth(m, y);
  const cur     = parseInt(dobDay.value, 10) || 0;

  Array.from(dobDay.options).forEach(opt => {
    if (!opt.value) return;
    const day = parseInt(opt.value, 10);
    opt.disabled = day > maxDays;
  });

  if (cur > maxDays) {
    dobDay.value = '';
    syncDob();
  }
}

function syncDob() {
  const d = dobDay.value, m = dobMonth.value, y = dobYear.value;
  dobHidden.value = (d && m && y) ? `${y}-${m}-${d}` : '';
}

[dobMonth, dobYear].forEach(el => el.addEventListener('change', () => {
  refreshDayOptions();
  syncDob();
}));
dobDay.addEventListener('change', syncDob);

// ── Alert / field error helpers ───────────────────────────────────────────────
function showAlert(type, msg) {
  alertEl.className = `alert alert-${type} show`;
  alertEl.textContent = msg;
}

function setFieldError(fieldId, msg) {
  // For DOB, highlight all three selects
  if (fieldId === 'dob') {
    [dobDay, dobMonth, dobYear].forEach(el => el.classList.toggle('error', !!msg));
  } else {
    const el = document.getElementById(fieldId);
    if (el) el.classList.toggle('error', !!msg);
  }
  const err = document.getElementById(`${fieldId}-err`);
  if (err) { err.textContent = msg || ''; err.classList.toggle('show', !!msg); }
}

function clearAll() {
  ['fullName', 'email', 'citizenId', 'dob', 'profile', 'idCard']
    .forEach(id => setFieldError(id, ''));
  alertEl.className = 'alert';
}

// ── Validation ────────────────────────────────────────────────────────────────
function validate() {
  let ok = true;

  const fullName  = document.getElementById('fullName').value;
  const email     = document.getElementById('email').value.trim();
  const citizenId = document.getElementById('citizenId').value.trim();
  const dob       = dobHidden.value;
  const profile   = document.getElementById('profile').value;
  const file      = document.getElementById('idCard').files[0];

  if (!fullName.trim())  { setFieldError('fullName',  'กรุณากรอกชื่อ-สกุล');                              ok = false; }
  if (!email)            { setFieldError('email',     'กรุณากรอกอีเมล');                                   ok = false; }
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setFieldError('email', 'รูปแบบอีเมลไม่ถูกต้อง'); ok = false; }
  if (!citizenId)        { setFieldError('citizenId', 'กรุณากรอกรหัสบัตรประชาชน หรือ รหัสนักศึกษา');      ok = false; }
  if (!dob)              { setFieldError('dob',       'กรุณาเลือกวันเกิด');                                 ok = false; }
  if (!profile)          { setFieldError('profile',   'กรุณาเลือกประเภทผู้ใช้');                            ok = false; }

  if (!file) {
    setFieldError('idCard', 'กรุณาแนบรูปบัตรประจำตัว');
    ok = false;
  } else if (!['image/jpeg', 'image/png'].includes(file.type)) {
    setFieldError('idCard', 'รองรับเฉพาะ JPEG และ PNG เท่านั้น');
    ok = false;
  } else if (file.size > 5 * 1024 * 1024) {
    setFieldError('idCard', 'ขนาดไฟล์ต้องไม่เกิน 5 MB');
    ok = false;
  }

  return ok;
}

// ── Form submit ───────────────────────────────────────────────────────────────
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearAll();

  if (!validate()) return;

  submitBtn.disabled = true;
  submitBtn.innerHTML = '<span class="spinner"></span> Registering…';

  try {
    const res  = await fetch('api/register.php', {
      method: 'POST',
      body: new FormData(form),
    });
    const json = await res.json();

    if (res.ok) {
      showAlert('success', json.message);
      form.reset();
      // Reset DOB dropdowns (form.reset() resets selects but syncDob won't fire)
      dobHidden.value = '';
    } else {
      showAlert('error', json.error || 'Something went wrong.');
    }
  } catch {
    showAlert('error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ กรุณาตรวจสอบการเชื่อมต่อ');
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = 'Register';
  }
});
