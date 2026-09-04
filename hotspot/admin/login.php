<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Login — Hotspot Management</title>
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
          <h1>Admin Login</h1>
          <p>Hotspot Management System</p>
        </div>
      </div>

      <div id="alert" class="alert"></div>

      <form id="loginForm" novalidate>
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" placeholder="Admin username" autocomplete="username" />
          <span class="field-error" id="username-err"></span>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="password-wrapper">
            <input type="password" id="password" name="password" placeholder="Password" autocomplete="current-password" />
            <button type="button" class="password-toggle" data-target="password" aria-label="แสดง/ซ่อนรหัสผ่าน"></button>
          </div>
          <span class="field-error" id="password-err"></span>
        </div>

        <button type="submit" class="btn btn-primary" id="submitBtn">Sign In</button>
      </form>

      <p style="text-align:center;margin-top:1.2rem;font-size:.85rem;color:#6b7280;">
        <a href="../index.php" class="text-link">Back to member registration</a>
      </p>
    </div>
  </div>

  <script src="../js/admin-login.js"></script>
  <script>
    const EYE_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_CLOSED = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    document.querySelectorAll('.password-toggle').forEach(btn => {
      btn.innerHTML = EYE_CLOSED;
      btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        if (input.type === 'password') { input.type = 'text'; btn.innerHTML = EYE_OPEN; }
        else { input.type = 'password'; btn.innerHTML = EYE_CLOSED; }
      });
    });
  </script>
</body>
</html>
