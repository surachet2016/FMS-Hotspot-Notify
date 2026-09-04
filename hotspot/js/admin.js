const API = '../api/members.php';
const NETWORK_API = '../api/network_status.php';

let currentPage   = 1;
let currentStatus = '';
let currentSearch = '';
let selectedIds   = new Set();
let pendingSuspendId = null;

const globalAlert = document.getElementById('globalAlert');

// ── Utility helpers ─────────────────────────────────────────────────────────

function showGlobalAlert(type, msg) {
  globalAlert.className = `alert alert-${type} show`;
  globalAlert.textContent = msg;
  setTimeout(() => { globalAlert.className = 'alert'; globalAlert.textContent = ''; }, 5000);
}

function formatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

const profileColors = { student: '#6366f1', teacher: '#0891b2', employee: '#059669' };

function buildProfileBadge(profile) {
  const span = document.createElement('span');
  span.className = 'badge';
  span.style.background = profileColors[profile] || '#6b7280';
  span.style.color = '#fff';
  span.textContent = profile ? profile.charAt(0).toUpperCase() + profile.slice(1) : '—';
  return span;
}

function buildBadge(status) {
  const span = document.createElement('span');
  span.className = `badge badge-${status.toLowerCase()}`;
  span.textContent = status.charAt(0) + status.slice(1).toLowerCase();
  return span;
}

function buildBtn(label, cssClass, onClick) {
  const btn = document.createElement('button');
  btn.className = `btn btn-sm ${cssClass}`;
  btn.textContent = label;
  btn.addEventListener('click', onClick);
  return btn;
}

// ── Stats ────────────────────────────────────────────────────────────────────

async function loadStats() {
  try {
    const [allRes, activeRes, pendingRes, suspendedRes] = await Promise.all([
      fetch(`${API}?page=1`, { credentials: 'same-origin' }),
      fetch(`${API}?page=1&status=ACTIVE`, { credentials: 'same-origin' }),
      fetch(`${API}?page=1&status=PENDING`, { credentials: 'same-origin' }),
      fetch(`${API}?page=1&status=SUSPENDED`, { credentials: 'same-origin' }),
    ]);

    if (allRes.status === 401) { window.location.replace('login.php'); return; }

    const [allData, activeData, pendingData, suspendedData] = await Promise.all([
      allRes.json(), activeRes.json(), pendingRes.json(), suspendedRes.json(),
    ]);

    document.getElementById('statTotal').textContent     = allData.total       ?? '—';
    document.getElementById('statActive').textContent    = activeData.total    ?? '—';
    document.getElementById('statPending').textContent   = pendingData.total   ?? '—';
    document.getElementById('statSuspended').textContent = suspendedData.total ?? '—';
  } catch {
    // Silently fail — stats are non-critical
  }
}

// ── Network status ───────────────────────────────────────────────────────────

let networkLoading = false;
let consecutiveNetworkFailures = 0;
let lastChartTimestamp = '';
const trafficCharts = {};

function createTrafficChart(canvasId, labels, colors) {
  const canvas = document.getElementById(canvasId);
  if (typeof Chart === 'undefined') {
    canvas.parentElement.classList.add('chart-unavailable');
    return null;
  }
  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: labels.map((label, index) => ({
        label,
        data: [],
        borderColor: colors[index],
        backgroundColor: `${colors[index]}1a`,
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: .32,
        fill: true,
      })),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'bottom',
          labels: { usePointStyle: true, boxWidth: 7, font: { size: 10 } },
        },
        tooltip: {
          callbacks: {
            label: context => `${context.dataset.label}: ${context.parsed.y.toFixed(2)} Mbps`,
          },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { maxTicksLimit: 6, maxRotation: 0, font: { size: 9 } },
        },
        y: {
          beginAtZero: true,
          grace: '8%',
          ticks: {
            maxTicksLimit: 5,
            font: { size: 9 },
            callback: value => `${value} Mb`,
          },
          grid: { color: '#eef2f7' },
        },
      },
    },
  });
}

function initTrafficCharts() {
  trafficCharts.wan = createTrafficChart(
    'wanTrafficChart', ['Download', 'Upload'], ['#2563eb', '#e11d48']
  );
  trafficCharts.lan = createTrafficChart(
    'lanTrafficChart', ['รับจากผู้ใช้', 'ส่งไปผู้ใช้'], ['#0f766e', '#d97706']
  );
}

function appendTrafficPoint(json) {
  if (!json.fetched_at || json.fetched_at === lastChartTimestamp) return;
  if (!trafficCharts.wan || !trafficCharts.lan) return;
  if (!json.wan || !json.lan) return;
  const rates = [json.wan.rx_bps, json.wan.tx_bps, json.lan.rx_bps, json.lan.tx_bps];
  if (!rates.every(value => Number.isFinite(Number(value)))) return;
  lastChartTimestamp = json.fetched_at;
  const label = new Date(json.fetched_at).toLocaleTimeString('th-TH', {
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  });

  const samples = {
    wan: [Number(json.wan.rx_bps) / 1000000, Number(json.wan.tx_bps) / 1000000],
    lan: [Number(json.lan.rx_bps) / 1000000, Number(json.lan.tx_bps) / 1000000],
  };
  Object.entries(samples).forEach(([key, values]) => {
    const chart = trafficCharts[key];
    chart.data.labels.push(label);
    values.forEach((value, index) => chart.data.datasets[index].data.push(value));
    if (chart.data.labels.length > 60) {
      chart.data.labels.shift();
      chart.data.datasets.forEach(dataset => dataset.data.shift());
    }
    chart.update('none');
  });
}

function formatMbps(bitsPerSecond) {
  const value = Number(bitsPerSecond);
  if (!Number.isFinite(value) || value < 0) return '--';
  return (value / 1000000).toLocaleString('th-TH', {
    minimumFractionDigits: value >= 1000000 ? 1 : 2,
    maximumFractionDigits: value >= 100000000 ? 0 : 2,
  });
}

function renderNetworkInterface(elementId, data) {
  const card = document.getElementById(elementId);
  const available = data && typeof data === 'object';
  const disabled = available && data.disabled === true;
  const linkRunning = available && data.running === true && data.link !== false && !disabled;
  const internetDown = elementId === 'wanStatus' && available && data.internet_reachable === false;
  const running = linkRunning && !internetDown;

  card.classList.remove('is-loading', 'is-up', 'is-down', 'is-stale');
  card.classList.add(running ? 'is-up' : 'is-down');
  card.querySelector('.network-state-text').textContent = !available
    ? 'ไม่มีข้อมูล'
    : disabled ? 'ปิดใช้งาน'
      : internetDown ? 'Internet ออกไม่ได้'
        : running ? 'พร้อมใช้งาน' : 'Link ขัดข้อง';
  card.querySelector('.network-interface-name span').textContent = available ? data.name : '--';
  card.querySelector('.traffic-rx').textContent = available ? formatMbps(data.rx_bps) : '--';
  card.querySelector('.traffic-tx').textContent = available ? formatMbps(data.tx_bps) : '--';
}

function renderNetworkError(message) {
  document.getElementById('networkSummary').textContent = message;
  document.getElementById('networkUpdatedAt').textContent = 'อ่านข้อมูลไม่สำเร็จ';
  renderNetworkInterface('wanStatus', null);
  renderNetworkInterface('lanStatus', null);
}

function speedtestData(json) {
  return json && (json.speedtest || json.wan_speedtest || json.speed_test) || {};
}

function firstSpeedtestValue(result, keys) {
  for (const key of keys) {
    if (result && result[key] !== undefined && result[key] !== null && result[key] !== '') return result[key];
  }
  return null;
}

function formatSpeedtestNumber(value, decimals = 2) {
  const number = Number(value);
  return Number.isFinite(number) ? number.toLocaleString('th-TH', { maximumFractionDigits: decimals }) : '--';
}

function formatSpeedtestTime(value) {
  if (!value) return '--';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('th-TH', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
  });
}

function speedtestStatusLabel(status) {
  const normalized = String(status || '').toLowerCase();
  return {
    queued: 'รอคิว', pending: 'รอคิว', running: 'กำลังทดสอบ',
    error: 'ผิดพลาด', failed: 'ผิดพลาด', complete: 'สำเร็จ', completed: 'สำเร็จ', success: 'สำเร็จ',
  }[normalized] || (status ? String(status) : 'ยังไม่มีผลทดสอบ');
}

function createSpeedtestStatus(status) {
  const span = document.createElement('span');
  const normalized = String(status || '').toLowerCase();
  span.className = `speedtest-status-badge speedtest-status-${normalized || 'idle'}`;
  span.textContent = speedtestStatusLabel(status);
  return span;
}

function renderSpeedtest(json) {
  const result = speedtestData(json);
  const history = Array.isArray(result.history) ? result.history.slice(0, 10) : [];
  const current = result.current && typeof result.current === 'object' ? result.current : null;
  const latest = result.latest || result.last || history[0] || result;
  const status = firstSpeedtestValue(current, ['status', 'state'])
    || firstSpeedtestValue(latest, ['status', 'state']) || '';
  document.getElementById('speedtestDownload').textContent = formatSpeedtestNumber(firstSpeedtestValue(latest, ['download_mbps', 'download', 'downloadMbps']));
  document.getElementById('speedtestUpload').textContent = formatSpeedtestNumber(firstSpeedtestValue(latest, ['upload_mbps', 'upload', 'uploadMbps']));
  document.getElementById('speedtestPing').textContent = formatSpeedtestNumber(firstSpeedtestValue(latest, ['ping_ms', 'ping', 'latency_ms']));
  document.getElementById('speedtestTestedAt').textContent = formatSpeedtestTime(firstSpeedtestValue(latest, ['tested_at', 'completed_at', 'created_at', 'timestamp']));
  document.getElementById('speedtestServer').textContent = firstSpeedtestValue(latest, ['server', 'server_name', 'serverName']) || '--';

  const statusEl = document.getElementById('speedtestStatus');
  statusEl.className = `speedtest-status speedtest-status-${String(status).toLowerCase() || 'idle'}`;
  statusEl.textContent = speedtestStatusLabel(status);
  const active = ['queued', 'pending', 'running'].includes(String(status).toLowerCase());
  const button = document.getElementById('speedtestBtn');
  button.disabled = active || speedtestRequesting;
  button.setAttribute('aria-busy', active || speedtestRequesting ? 'true' : 'false');

  const tbody = document.getElementById('speedtestHistoryBody');
  const empty = document.getElementById('speedtestHistoryEmpty');
  tbody.textContent = '';
  empty.hidden = history.length > 0;
  history.forEach(item => {
    const row = document.createElement('tr');
    const values = [
      formatSpeedtestTime(firstSpeedtestValue(item, ['tested_at', 'completed_at', 'created_at', 'timestamp'])),
      formatSpeedtestNumber(firstSpeedtestValue(item, ['download_mbps', 'download', 'downloadMbps'])),
      formatSpeedtestNumber(firstSpeedtestValue(item, ['upload_mbps', 'upload', 'uploadMbps'])),
      formatSpeedtestNumber(firstSpeedtestValue(item, ['ping_ms', 'ping', 'latency_ms'])),
      firstSpeedtestValue(item, ['server', 'server_name', 'serverName']) || '--',
    ];
    values.forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell); });
    const statusCell = document.createElement('td');
    statusCell.appendChild(createSpeedtestStatus(firstSpeedtestValue(item, ['status', 'state'])));
    row.appendChild(statusCell);
    tbody.appendChild(row);
  });
}

let speedtestRequesting = false;

async function requestSpeedtest() {
  if (speedtestRequesting) return;
  speedtestRequesting = true;
  renderSpeedtest({ speedtest: { status: 'queued' } });
  try {
    const res = await fetch(`${NETWORK_API}?action=request_speedtest`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({}),
    });
    const text = await res.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; } catch { json = {}; }
    if (res.status === 409) {
      showGlobalAlert('warning', json.error || 'มีการทดสอบ WAN อยู่แล้ว');
    } else if (!res.ok) {
      showGlobalAlert('error', json.error || 'ไม่สามารถเริ่มการทดสอบ WAN ได้');
    } else {
      if (json.speedtest) renderSpeedtest(json);
      showGlobalAlert('success', 'เพิ่มการทดสอบ WAN เข้าคิวแล้ว');
    }
    await loadNetworkStatus();
  } catch {
    showGlobalAlert('error', 'ไม่สามารถติดต่อบริการทดสอบ WAN ได้');
    await loadNetworkStatus();
  } finally {
    speedtestRequesting = false;
    renderSpeedtest(window.lastNetworkStatus || {});
  }
}

async function loadNetworkStatus() {
  if (networkLoading) return;
  networkLoading = true;
  const refreshBtn = document.getElementById('networkRefreshBtn');
  refreshBtn.disabled = true;
  refreshBtn.classList.add('is-refreshing');

  try {
    const res = await fetch(NETWORK_API, {
      credentials: 'same-origin',
      cache: 'no-store',
    });
    if (res.status === 401) {
      renderNetworkError('API ต้องล็อกอิน');
      return;
    }
    const json = await res.json();
    if (!res.ok || json.ok !== true) {
      consecutiveNetworkFailures++;
      renderNetworkError(json.error || 'ไม่สามารถติดต่อ MikroTik RouterOS ได้');
      return;
    }

    consecutiveNetworkFailures = 0;

    renderNetworkInterface('wanStatus', json.wan);
    renderNetworkInterface('lanStatus', json.lan);
    window.lastNetworkStatus = json;
    renderSpeedtest(json);

    const fetchedAt = json.fetched_at ? new Date(json.fetched_at) : new Date();
    if (json.stale === true) {
      document.getElementById('wanStatus').classList.add('is-stale');
      document.getElementById('lanStatus').classList.add('is-stale');
      document.getElementById('networkSummary').textContent = 'ข้อมูลเก่า: OpenClaw server หยุดส่งสถานะเครือข่าย';
      document.getElementById('networkUpdatedAt').textContent = `ข้อมูลล่าสุด ${fetchedAt.toLocaleTimeString('th-TH')}`;
      return;
    }
    appendTrafficPoint(json);

    const wanLinkUp = json.wan && json.wan.running && json.wan.link !== false && !json.wan.disabled;
    const internetUp = wanLinkUp && json.wan.internet_reachable !== false;
    const lanUp = json.lan && json.lan.running && json.lan.link !== false && !json.lan.disabled;

    // Build per-section status: WAN (university) and LAN (faculty) separately
    let wanSection, lanSection, rootCause;

    if (wanLinkUp && internetUp) {
      wanSection = '✅ WAN (มหาวิทยาลัย): ปกติ - ออก Internet ได้';
    } else if (wanLinkUp && !internetUp) {
      wanSection = '⚠️ WAN (มหาวิทยาลัย): Link ปกติ แต่ Internet ออกไม่ได้';
      rootCause = 'มหาวิทยาลัย:';
    } else {
      wanSection = '❌ WAN (มหาวิทยาลัย): Link ไม่ขึ้น - สาย uplink หรืออุปกรณ์ต้นทางมีปัญหา';
      rootCause = 'มหาวิทยาลัย:';
    }

    if (lanUp) {
      lanSection = '✅ LAN/Hotspot (คณะ): ปกติ - link พร้อมใช้งาน';
    } else {
      lanSection = '❌ LAN/Hotspot (คณะ): Link ไม่ขึ้น - bridge หรืออุปกรณ์กระจายสัญญาณมีปัญหา';
      if (!rootCause) rootCause = 'คณะ:';
    }

    // Pick primary diagnosis line
    let summary;
    if (wanLinkUp && internetUp && lanUp) {
      summary = 'Router ออก Internet ได้ และ Hotspot LAN link พร้อมใช้งาน';
    } else if (!wanLinkUp && !lanUp) {
      summary = 'ทั้ง WAN และ LAN มีปัญหา: ตรวจสอบ MikroTik router หรือสายอัปลิงค์ - ' + wanSection + ' / ' + lanSection;
    } else if (!internetUp && !lanUp) {
      summary = 'Internet ไม่ได้และ LAN มีปัญหา - ตรวจสอบ MikroTik: WAN link ขึ้นแต่ออก Internet ไม่ได้ + LAN link ไม่ขึ้น';
    } else if (!internetUp) {
      summary = wanSection + ' - สาเหตุ: ' + rootCause + ' upstream provider / gateway ของมหาวิทยาลัย';
    } else if (!lanUp) {
      summary = lanSection + ' - สาเหตุ: อุปกรณ์ภายในคณะ (bridge/AP/switch)';
    } else {
      summary = wanSection + ' / ' + lanSection;
    }

    document.getElementById('networkSummary').textContent = summary;

    document.getElementById('networkUpdatedAt').textContent = `อัปเดต ${fetchedAt.toLocaleTimeString('th-TH')}`;
  } catch (error) {
    consecutiveNetworkFailures++;
    console.warn('Network status refresh failed', error);
    if (window.lastNetworkStatus) {
      // Keep showing last data with stale label
      const age = Math.floor((Date.now() - new Date(window.lastNetworkStatus.fetched_at)) / 1000);
      document.getElementById('networkUpdatedAt').textContent =
        `ข้อมูลล่าสุดอายุ ${age} วินาที (กำลังลองเชื่อมต่อใหม่)`;
    } else if (consecutiveNetworkFailures >= 3) {
      renderNetworkError('ไม่สามารถติดต่อบริการตรวจสอบเครือข่ายได้');
    } else {
      document.getElementById('networkUpdatedAt').textContent =
        `การเชื่อมต่อสะดุด กำลังลองใหม่ (${consecutiveNetworkFailures}/3)`;
    }
  } finally {
    networkLoading = false;
    refreshBtn.disabled = false;
    refreshBtn.classList.remove('is-refreshing');
  }
}

document.getElementById('networkRefreshBtn').addEventListener('click', loadNetworkStatus);
document.getElementById('speedtestBtn').addEventListener('click', requestSpeedtest);

// ── Members list ─────────────────────────────────────────────────────────────

async function loadMembers(page = 1, status = '', search = '') {
  currentPage   = page;
  currentStatus = status;
  currentSearch = search;

  const params = new URLSearchParams({ page });
  if (status) params.set('status', status);
  if (search) params.set('search', search);

  try {
    const res = await fetch(`${API}?${params}`, { credentials: 'same-origin' });
    if (res.status === 401) {
      renderNetworkError('API ต้องล็อกอิน');
      return;
    }
    const json = await res.json();
    if (!res.ok) { showGlobalAlert('error', json.error || 'Failed to load members.'); return; }

    renderRows(json.data, page);
    renderPagination(json.page, json.pages);
  } catch {
    showGlobalAlert('error', 'Failed to load members. Check server connection.');
  }
}

// ── Row rendering ─────────────────────────────────────────────────────────────

function renderRows(members, page) {
  const tbody = document.getElementById('membersBody');
  const empty = document.getElementById('emptyState');
  tbody.textContent = '';

  if (!members || members.length === 0) {
    empty.style.display = 'block';
    selectedIds.clear();
    updateSelectedUI();
    return;
  }
  empty.style.display = 'none';

  const startIndex = (page - 1) * 20;

  members.forEach((m, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.id = m.id;

    // Checkbox
    const checkTd = document.createElement('td');
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.value = m.id;
    cb.checked = selectedIds.has(m.id);
    cb.addEventListener('change', () => {
      if (cb.checked) {
        selectedIds.add(m.id);
      } else {
        selectedIds.delete(m.id);
        document.getElementById('selectAll').checked = false;
      }
      updateSelectedUI();
    });
    checkTd.appendChild(cb);
    tr.appendChild(checkTd);

    // Row number
    const numTd = document.createElement('td');
    numTd.textContent = String(startIndex + idx + 1);
    tr.appendChild(numTd);

    // Full name
    const nameTd = document.createElement('td');
    nameTd.textContent = m.full_name;
    tr.appendChild(nameTd);

    // Email
    const emailTd = document.createElement('td');
    emailTd.textContent = m.email;
    tr.appendChild(emailTd);

    // Citizen ID
    const cidTd = document.createElement('td');
    cidTd.textContent = m.citizen_id;
    tr.appendChild(cidTd);

    // DOB
    const dobTd = document.createElement('td');
    dobTd.textContent = m.dob ? formatDate(m.dob) : '—';
    tr.appendChild(dobTd);

    // Profile
    const profileTd = document.createElement('td');
    profileTd.appendChild(buildProfileBadge(m.profile));
    tr.appendChild(profileTd);

    // Status + sync warning
    const statusTd = document.createElement('td');
    statusTd.appendChild(buildBadge(m.status));
    if (m.status === 'ACTIVE' && m.mikrotik_synced === false) {
      const warn = document.createElement('span');
      warn.className = 'sync-warning';
      warn.textContent = 'Sync pending';
      statusTd.appendChild(warn);
    }
    tr.appendChild(statusTd);

    // Created at
    const dateTd = document.createElement('td');
    dateTd.textContent = formatDate(m.created_at);
    tr.appendChild(dateTd);

    // Actions
    const actionsTd = document.createElement('td');
    actionsTd.className = 'actions-cell';

    if (m.has_image) {
      actionsTd.appendChild(buildBtn('View ID', 'btn-primary', () => viewImage(m.id)));
    }
    if (m.status !== 'ACTIVE') {
      actionsTd.appendChild(buildBtn('Activate', 'btn-success', () => activateMember(m.id)));
    }
    if (m.status !== 'SUSPENDED') {
      actionsTd.appendChild(buildBtn('Suspend', 'btn-warning', () => suspendMember(m.id)));
    }
    actionsTd.appendChild(buildBtn('Delete', 'btn-danger', () => deleteMember(m.id, m.full_name)));

    tr.appendChild(actionsTd);
    tbody.appendChild(tr);
  });
}

// ── Pagination ────────────────────────────────────────────────────────────────

function renderPagination(page, totalPages) {
  const container = document.getElementById('pagination');
  container.textContent = '';

  if (totalPages <= 1) return;

  const prevBtn = document.createElement('button');
  prevBtn.textContent = 'Prev';
  prevBtn.disabled = page <= 1;
  prevBtn.addEventListener('click', () => loadMembers(page - 1, currentStatus, currentSearch));
  container.appendChild(prevBtn);

  const info = document.createElement('span');
  info.textContent = `หน้า ${page} / ${totalPages}`;
  container.appendChild(info);

  const nextBtn = document.createElement('button');
  nextBtn.textContent = 'Next';
  nextBtn.disabled = page >= totalPages;
  nextBtn.addEventListener('click', () => loadMembers(page + 1, currentStatus, currentSearch));
  container.appendChild(nextBtn);
}

// ── Bulk select UI ────────────────────────────────────────────────────────────

function updateSelectedUI() {
  const count = selectedIds.size;
  const btn = document.getElementById('bulkApproveBtn');
  const countSpan = document.getElementById('selectedCount');
  countSpan.textContent = String(count);
  btn.style.display = count > 0 ? '' : 'none';
}

// ── Image modal ───────────────────────────────────────────────────────────────

function viewImage(id) {
  const modal = document.getElementById('imageModal');
  const img   = document.getElementById('modalImg');
  img.src = `../api/image.php?id=${encodeURIComponent(id)}`;
  modal.showModal();
}

document.getElementById('closeImageModal').addEventListener('click', () => {
  document.getElementById('imageModal').close();
});

// ── Activate member ───────────────────────────────────────────────────────────

async function activateMember(id) {
  try {
    const res = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'activate_single' }),
    });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch {
      showGlobalAlert('error', `Server error: ${text.slice(0, 200)}`);
      return;
    }
    if (!res.ok) { showGlobalAlert('error', json.error || 'Activate failed.'); return; }

    if (json.mikrotik_synced === false) {
      const mtErr = json._mikrotik_error ? ` (${json._mikrotik_error})` : '';
      showGlobalAlert('warning', `อนุมัติสำเร็จ แต่ MikroTik sync ยังไม่สำเร็จ${mtErr} — sync script จะ sync ให้อัตโนมัติ`);
    } else {
      showGlobalAlert('success', 'อนุมัติสำเร็จ และ sync MikroTik แล้ว');
    }

    await Promise.all([
      loadMembers(currentPage, currentStatus, currentSearch),
      loadStats(),
    ]);
  } catch {
    showGlobalAlert('error', 'Failed to activate member.');
  }
}

// ── Suspend modal ─────────────────────────────────────────────────────────────

function suspendMember(id) {
  pendingSuspendId = id;
  document.getElementById('suspendNote').value = '';
  document.getElementById('suspendModal').showModal();
}

document.getElementById('cancelSuspendBtn').addEventListener('click', () => {
  document.getElementById('suspendModal').close();
  pendingSuspendId = null;
});

document.getElementById('confirmSuspendBtn').addEventListener('click', async () => {
  if (!pendingSuspendId) return;
  const note = document.getElementById('suspendNote').value.trim();
  const id   = pendingSuspendId;
  document.getElementById('suspendModal').close();
  pendingSuspendId = null;

  try {
    const res = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'suspend_single', note }),
    });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch {
      showGlobalAlert('error', `Server error: ${text.slice(0, 200)}`);
      return;
    }
    if (!res.ok) { showGlobalAlert('error', json.error || 'Suspend failed.'); return; }

    showGlobalAlert('success', 'ระงับสมาชิกสำเร็จ');
    await Promise.all([
      loadMembers(currentPage, currentStatus, currentSearch),
      loadStats(),
    ]);
  } catch {
    showGlobalAlert('error', 'Failed to suspend member.');
  }
});

// ── Bulk approve ──────────────────────────────────────────────────────────────

async function bulkApprove(ids) {
  try {
    const res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'bulk_activate', ids: [...ids] }),
    });
    const json = await res.json();
    if (!res.ok) { showGlobalAlert('error', json.error || 'Bulk activate failed.'); return; }

    const { succeeded, failed } = json;
    selectedIds.clear();
    updateSelectedUI();

    if (failed.length === 0) {
      showGlobalAlert('success', `อนุมัติสำเร็จ ${succeeded.length} รายการ`);
    } else {
      showGlobalAlert('warning', `อนุมัติสำเร็จ ${succeeded.length} รายการ, ล้มเหลว ${failed.length} รายการ`);
    }

    await Promise.all([
      loadMembers(currentPage, currentStatus, currentSearch),
      loadStats(),
    ]);
  } catch {
    showGlobalAlert('error', 'Failed to bulk activate members.');
  }
}

// ── Delete member ─────────────────────────────────────────────────────────────

async function deleteMember(id, name) {
  if (!confirm(`ลบสมาชิก "${name}" ใช่หรือไม่?`)) return;
  try {
    const res = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'delete_single' }),
    });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch {
      showGlobalAlert('error', `Server error: ${text.slice(0, 200)}`);
      return;
    }
    if (!res.ok) { showGlobalAlert('error', json.error || 'Delete failed.'); return; }

    selectedIds.delete(id);
    updateSelectedUI();
    if (json.pending === true) {
      showGlobalAlert('warning', `กำลังลบ "${name}" ออกจาก MikroTik และระบบ โปรดรอสักครู่`);
      setTimeout(() => {
        loadMembers(currentPage, currentStatus, currentSearch);
        loadStats();
      }, 7000);
    } else {
      showGlobalAlert('success', `ลบสมาชิก "${name}" สำเร็จ`);
    }

    await Promise.all([
      loadMembers(currentPage, currentStatus, currentSearch),
      loadStats(),
    ]);
  } catch {
    showGlobalAlert('error', 'Failed to delete member.');
  }
}

// ── Filter tabs ───────────────────────────────────────────────────────────────

document.querySelectorAll('.filter-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    selectedIds.clear();
    updateSelectedUI();
    loadMembers(1, tab.dataset.status, currentSearch);
  });
});

// ── Search (debounced) ────────────────────────────────────────────────────────

let searchTimer = null;
document.getElementById('searchInput').addEventListener('input', e => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    selectedIds.clear();
    updateSelectedUI();
    loadMembers(1, currentStatus, e.target.value.trim());
  }, 300);
});

// ── Select all ────────────────────────────────────────────────────────────────

document.getElementById('selectAll').addEventListener('change', e => {
  const checked = e.target.checked;
  document.querySelectorAll('#membersBody input[type="checkbox"]').forEach(cb => {
    cb.checked = checked;
    if (checked) {
      selectedIds.add(cb.value);
    } else {
      selectedIds.delete(cb.value);
    }
  });
  updateSelectedUI();
});

// ── Bulk approve button ───────────────────────────────────────────────────────

document.getElementById('bulkApproveBtn').addEventListener('click', () => {
  if (selectedIds.size === 0) return;
  bulkApprove(selectedIds);
});

// ── Auto-refresh ─────────────────────────────────────────────────────────────

let refreshTimer    = null;
let countdownTimer  = null;
let countdownRemain = 0;
const countdownEl   = document.getElementById('refreshCountdown');

function updateCountdownDisplay() {
  if (countdownRemain <= 0) { countdownEl.textContent = ''; return; }
  const m = Math.floor(countdownRemain / 60);
  const s = countdownRemain % 60;
  countdownEl.textContent = `next: ${m}:${String(s).padStart(2, '0')}`;
}

function setAutoRefresh(seconds) {
  clearInterval(refreshTimer);
  clearInterval(countdownTimer);
  refreshTimer = countdownTimer = null;
  countdownRemain = 0;
  countdownEl.textContent = '';

  document.querySelectorAll('.refresh-btn').forEach(btn => {
    btn.classList.toggle('active', parseInt(btn.dataset.interval) === seconds);
  });

  if (seconds > 0) {
    countdownRemain = seconds;
    updateCountdownDisplay();
    countdownTimer = setInterval(() => {
      countdownRemain--;
      updateCountdownDisplay();
    }, 1000);
    refreshTimer = setInterval(() => {
      loadStats();
      loadMembers(currentPage, currentStatus, currentSearch);
      loadNetworkStatus();
      countdownRemain = seconds;
    }, seconds * 1000);
  }

  localStorage.setItem('adminRefreshInterval', seconds);
}

document.querySelectorAll('.refresh-btn').forEach(btn => {
  btn.addEventListener('click', () => setAutoRefresh(parseInt(btn.dataset.interval)));
});

const savedInterval = parseInt(localStorage.getItem('adminRefreshInterval') ?? '0');
setAutoRefresh(savedInterval);

// ── Init ──────────────────────────────────────────────────────────────────────

initTrafficCharts();
loadStats();
loadMembers();
loadNetworkStatus();
setInterval(() => {
  if (!document.hidden) loadNetworkStatus();
}, 5000);
