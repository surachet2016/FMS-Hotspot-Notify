// ── Access Log (Computer Crime Act B.E. 2550 Section 26) ──────────────────────

const LOG_API = '../api/log_export.php';

function fmtBytes(n) {
  if (!n) return '0';
  if (n > 1e9) return (n / 1e9).toFixed(2) + ' GB';
  if (n > 1e6) return (n / 1e6).toFixed(2) + ' MB';
  if (n > 1e3) return (n / 1e3).toFixed(1) + ' KB';
  return String(n);
}

function fmtDuration(s) {
  if (!s) return '-';
  if (s > 3600) return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
  if (s > 60)   return Math.floor(s / 60) + 'm ' + (s % 60) + 's';
  return s + 's';
}

function fmtDate(s) {
  if (!s) return '-';
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
}

async function loadAccessLogs() {
  const from = document.getElementById('logFromDate').value;
  const to = document.getElementById('logToDate').value;
  const username = document.getElementById('logUsername').value.trim();
  const srcIp = document.getElementById('logSrcIp').value.trim();
  const today = new Date().toISOString().slice(0, 10);
  const ninetyAgo = new Date(Date.now() - 90 * 86400000).toISOString().slice(0, 10);

  const params = new URLSearchParams({
    from: from || ninetyAgo,
    to: to || today,
    ...(username && { username }),
    ...(srcIp && { src_ip: srcIp }),
    format: 'json',
  });

  try {
    const res = await fetch(LOG_API + '?' + params.toString(), {
      credentials: 'same-origin',
    });
    if (res.status === 401) { window.location.replace('login.php'); return; }
    const json = await res.json();
    renderAccessLogs(json);
  } catch (err) {
    document.getElementById('logTableBody').innerHTML =
      '<tr><td colspan="6" style="padding:1rem;text-align:center;color:#dc2626;">Load failed: ' + err.message + '</td></tr>';
  }
}

function renderAccessLogs(json) {
  const rows = json.rows || [];
  const count = json.count || 0;

  // Stats
  const today = new Date().toISOString().slice(0, 10);
  const todayCount = rows.filter(r => r.login_at && r.login_at.slice(0, 10) === today).length;
  const oldestDate = rows.length ? rows[rows.length - 1].login_at.slice(0, 10) : '-';
  const longSessions = rows.filter(r => r.duration_s && r.duration_s > 6 * 3600).length;

  document.getElementById('logTotalCount').textContent = count.toLocaleString();
  document.getElementById('logTodayCount').textContent = todayCount.toLocaleString();
  document.getElementById('logAnomalyCount').textContent = longSessions.toLocaleString();
  document.getElementById('logOldestDate').textContent = oldestDate;

  // Table rows
  const tbody = document.getElementById('logTableBody');
  if (rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="padding:1rem;text-align:center;color:#9ca3af;">ไม่พบรายการ</td></tr>';
    return;
  }

  const top = rows.slice(0, 50);
  tbody.innerHTML = top.map(r => {
    const isLong = r.duration_s && r.duration_s > 6 * 3600;
    const isActive = r.event === 'login' && !r.logout_at;
    const statusBadge = isActive
      ? '<span class="badge" style="background:#dcfce7;color:#166534;">Active</span>'
      : (isLong
        ? '<span class="badge" style="background:#fee2e2;color:#991b1b;" title="Long session (>6h)">⚠️ Long</span>'
        : '<span class="badge" style="background:#f3f4f6;color:#374151;">' + r.event + '</span>');
    const bytes = fmtBytes((+r.bytes_in || 0) + (+r.bytes_out || 0));
    return '<tr style="border-bottom:1px solid #f3f4f6;">' +
      '<td style="padding:.5rem .6rem;">' + fmtDate(r.login_at) + '</td>' +
      '<td style="padding:.5rem .6rem;">' + escapeHtml(r.username || '-') + '</td>' +
      '<td style="padding:.5rem .6rem;font-family:monospace;font-size:.8rem;">' + escapeHtml(r.src_ip || '-') + '</td>' +
      '<td style="padding:.5rem .6rem;text-align:right;">' + fmtDuration(r.duration_s) + '</td>' +
      '<td style="padding:.5rem .6rem;text-align:right;">' + bytes + '</td>' +
      '<td style="padding:.5rem .6rem;text-align:center;">' + statusBadge + '</td>' +
      '</tr>';
  }).join('');
}

function escapeHtml(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[c]);
}

function exportAccessLogs() {
  const from = document.getElementById('logFromDate').value;
  const to = document.getElementById('logToDate').value;
  const username = document.getElementById('logUsername').value.trim();
  const srcIp = document.getElementById('logSrcIp').value.trim();
  const today = new Date().toISOString().slice(0, 10);
  const ninetyAgo = new Date(Date.now() - 90 * 86400000).toISOString().slice(0, 10);

  const params = new URLSearchParams({
    from: from || ninetyAgo,
    to: to || today,
    format: 'csv',
    ...(username && { username }),
    ...(srcIp && { src_ip: srcIp }),
  });

  // Use direct link so browser handles the file download
  window.location = LOG_API + '?' + params.toString();
}

// Set default dates on load
(function initLogFilters() {
  const today = new Date().toISOString().slice(0, 10);
  const ninetyAgo = new Date(Date.now() - 90 * 86400000).toISOString().slice(0, 10);
  document.getElementById('logFromDate').value = ninetyAgo;
  document.getElementById('logToDate').value = today;

  document.getElementById('refreshLogBtn')?.addEventListener('click', loadAccessLogs);
  document.getElementById('exportLogBtn')?.addEventListener('click', exportAccessLogs);

  loadAccessLogs();
  setInterval(loadAccessLogs, 30000);
})();
