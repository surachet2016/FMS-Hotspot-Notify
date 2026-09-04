sessionStorage.clear();

const form      = document.getElementById('loginForm');
const alertEl   = document.getElementById('alert');
const submitBtn = document.getElementById('submitBtn');

function showAlert(type, msg) {
  alertEl.className = `alert alert-${type} show`;
  alertEl.textContent = msg;
}

function setFieldError(fieldId, msg) {
  const input = document.getElementById(fieldId);
  const err   = document.getElementById(`${fieldId}-err`);
  input.classList.toggle('error', !!msg);
  err.textContent = msg || '';
  err.classList.toggle('show', !!msg);
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  setFieldError('username', '');
  setFieldError('password', '');
  alertEl.className = 'alert';

  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value;

  if (!username) { setFieldError('username', 'Username is required.'); return; }
  if (!password) { setFieldError('password', 'Password is required.');  return; }

  submitBtn.disabled = true;
  submitBtn.innerHTML = '<span class="spinner"></span> Signing in…';

  try {
    const res  = await fetch('../api/admin_login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
      credentials: 'same-origin',
    });
    const json = await res.json();

    if (res.ok) {
      window.location.href = 'index.php';
    } else {
      showAlert('error', json.error || 'Login failed.');
    }
  } catch {
    showAlert('error', 'Cannot reach the server. Check your connection.');
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = 'Sign In';
  }
});
