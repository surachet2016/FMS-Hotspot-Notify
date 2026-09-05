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
  // DB stores UTC ("YYYY-MM-DD HH:MM:SS"). Append 'Z' so JS parses it as
  // UTC, then toLocaleString renders it in Thailand time (UTC+7).
  const iso = s.replace(' ', 'T') + (/[Z+]/.test(s) ? '' : 'Z');
  const d = new Date(iso);
  return d.toLocaleString('th-TH', {
    dateStyle: 'short',
    timeStyle: 'short',
    timeZone: 'Asia/Bangkok'
  });
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

let _logRows = [];
let _logPage = 1;
const LOG_PAGE_SIZE = 20;

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

  // Store rows globally + reset to page 1, then render current page
  _logRows = rows;
  _logPage = 1;
  renderLogPage();
}

function renderLogPage() {
  const tbody = document.getElementById('logTableBody');
  const rows = _logRows;
  if (rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="padding:1rem;text-align:center;color:#9ca3af;">ไม่พบรายการ</td></tr>';
    renderLogPagination();
    return;
  }

  const totalPages = Math.max(1, Math.ceil(rows.length / LOG_PAGE_SIZE));
  if (_logPage > totalPages) _logPage = totalPages;
  if (_logPage < 1) _logPage = 1;

  const start = (_logPage - 1) * LOG_PAGE_SIZE;
  const pageRows = rows.slice(start, start + LOG_PAGE_SIZE);

  tbody.innerHTML = pageRows.map(r => {
    const isLong = r.duration_s && r.duration_s > 6 * 3600;
    const isActive = r.event === 'login' && !r.logout_at;
    let pill;
    if (isActive) {
      pill = '<span class="alog-pill p-active">Active</span>';
    } else if (isLong) {
      pill = '<span class="alog-pill p-long" title="Long session (>6h)">⚠️ Long</span>';
    } else if (r.event === 'update') {
      pill = '<span class="alog-pill p-update">update</span>';
    } else {
      pill = '<span class="alog-pill p-logout">' + escapeHtml(r.event) + '</span>';
    }
    const bytes = fmtBytes((+r.bytes_in || 0) + (+r.bytes_out || 0));
    return '<tr>' +
      '<td data-label="When" class="alog-col-time">' + fmtDate(r.login_at) + '</td>' +
      '<td data-label="User" class="alog-col-user">' + escapeHtml(r.username || '-') + '</td>' +
      '<td data-label="IP" class="alog-col-ip">' + escapeHtml(r.src_ip || '-') + '</td>' +
      '<td data-label="Duration" class="alog-col-dur">' + fmtDuration(r.duration_s) + '</td>' +
      '<td data-label="Bytes" class="alog-col-bytes">' + bytes + '</td>' +
      '<td data-label="Status" class="alog-col-status">' + pill + '</td>' +
      '</tr>';
  }).join('');

  renderLogPagination();
}

function renderLogPagination() {
  let bar = document.getElementById('logPagination');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'logPagination';
    const wrap = document.getElementById('logTableBody').closest('.alog-table-wrap') ||
                 document.getElementById('logTableBody').closest('table');
    wrap.parentNode.insertBefore(bar, wrap.nextSibling);
  }

  const rows = _logRows;
  const totalPages = Math.max(1, Math.ceil(rows.length / LOG_PAGE_SIZE));
  if (rows.length === 0) { bar.innerHTML = ''; return; }

  const start = (_logPage - 1) * LOG_PAGE_SIZE + 1;
  const end = Math.min(_logPage * LOG_PAGE_SIZE, rows.length);

  const btn = (label, page, disabled, active) =>
    '<button type="button" data-page="' + page + '"' +
    (disabled ? ' disabled' : '') +
    (active ? ' class="active"' : '') +
    '>' + label + '</button>';

  // Build compact windowed page list
  let pages = [];
  const win = 2;
  for (let p = 1; p <= totalPages; p++) {
    if (p === 1 || p === totalPages || (p >= _logPage - win && p <= _logPage + win)) {
      pages.push(p);
    } else if (pages[pages.length - 1] !== '...') {
      pages.push('...');
    }
  }

  let html = '<span class="pg-info">' + start + '-' + end + ' / ' + rows.length + ' รายการ</span>';
  html += btn('&laquo;', _logPage - 1, _logPage <= 1, false);
  for (const p of pages) {
    if (p === '...') {
      html += '<span class="pg-ellipsis">…</span>';
    } else {
      html += btn(String(p), p, false, p === _logPage);
    }
  }
  html += btn('&raquo;', _logPage + 1, _logPage >= totalPages, false);
  bar.innerHTML = html;

  bar.querySelectorAll('button[data-page]').forEach(b => {
    b.addEventListener('click', () => {
      const p = parseInt(b.getAttribute('data-page'), 10);
      if (!isNaN(p)) { _logPage = p; renderLogPage(); }
    });
  });
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
