<?php
require_once __DIR__ . '/../lib/auth.php';
requireAdminPage();
$adminUsername = htmlspecialchars($_SESSION['admin_username'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard — Hotspot Management</title>
  <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__.'/../css/style.css') ?>" />
</head>
<body class="dashboard-body">

  <nav class="navbar">
    <span class="navbar-brand">
      <img src="../img/mgt.jpg" alt="FMS Logo" style="height:32px;border-radius:5px;object-fit:cover;vertical-align:middle;" />
      Hotspot Management
    </span>
    <div class="navbar-actions">
      <span id="adminName">Hi, <?= $adminUsername ?></span>
      <div class="auto-refresh-group">
        <span style="font-size:.8rem;opacity:.8;">Auto refresh:</span>
        <button type="button" class="refresh-btn" data-interval="0">Off</button>
        <button type="button" class="refresh-btn" data-interval="60">1m</button>
        <button type="button" class="refresh-btn" data-interval="180">3m</button>
        <button type="button" class="refresh-btn" data-interval="300">5m</button>
        <span id="refreshCountdown" style="font-size:.78rem;opacity:.75;min-width:4rem;"></span>
      </div>
      <div class="navbar-nav-links">
        <a href="change_password.php" class="btn btn-sm nav-link-btn">Change Password</a>
        <a href="logout.php" class="btn btn-sm nav-link-btn">Sign Out</a>
      </div>
    </div>
  </nav>

  <main class="dashboard-main">

    <div class="stats-row">
      <div class="stat-card stat-total">
        <div class="stat-label">Total Members</div>
        <div class="stat-value" id="statTotal">—</div>
      </div>
      <div class="stat-card stat-active">
        <div class="stat-label">Active</div>
        <div class="stat-value" id="statActive">—</div>
      </div>
      <div class="stat-card stat-pending">
        <div class="stat-label">Pending</div>
        <div class="stat-value" id="statPending">—</div>
      </div>
      <div class="stat-card stat-suspended">
        <div class="stat-label">Suspended</div>
        <div class="stat-value" id="statSuspended">—</div>
      </div>
    </div>

    <div id="globalAlert" class="alert" style="margin-bottom:1rem;"></div>

    <section class="network-panel" aria-labelledby="networkStatusTitle">
      <div class="network-panel-header">
        <div>
          <h2 id="networkStatusTitle">สถานะเครือข่าย</h2>
          <p id="networkSummary" aria-live="polite">กำลังอ่านข้อมูลจาก MikroTik RouterOS...</p>
        </div>
        <div class="network-panel-actions">
          <span id="networkUpdatedAt">ยังไม่มีข้อมูล</span>
          <button type="button" class="network-refresh" id="networkRefreshBtn" title="รีเฟรชสถานะเครือข่าย" aria-label="รีเฟรชสถานะเครือข่าย">
            &#8635;
          </button>
        </div>
      </div>

      <div class="network-grid" id="networkStatus">
        <article class="network-interface is-loading" id="wanStatus">
          <div class="network-interface-heading">
            <div>
              <span class="network-role">WAN</span>
              <h3>Internet</h3>
            </div>
            <span class="network-state"><span class="network-state-dot"></span><span class="network-state-text">กำลังตรวจสอบ</span></span>
          </div>
          <div class="network-interface-name">Interface: <span>--</span></div>
          <div class="traffic-metrics">
            <div><span class="traffic-label">Download</span><strong class="traffic-rx">--</strong><small>Mbps</small></div>
            <div><span class="traffic-label">Upload</span><strong class="traffic-tx">--</strong><small>Mbps</small></div>
          </div>
          <div class="traffic-chart-wrap">
            <canvas id="wanTrafficChart" aria-label="กราฟ Download และ Upload ของ WAN 5 นาทีล่าสุด" role="img"></canvas>
            <p class="chart-fallback">ไม่สามารถโหลดกราฟได้ กรุณาดูค่าปัจจุบันด้านบน</p>
          </div>
        </article>

        <article class="network-interface is-loading" id="lanStatus">
          <div class="network-interface-heading">
            <div>
              <span class="network-role">LAN</span>
              <h3>Hotspot WiFi</h3>
            </div>
            <span class="network-state"><span class="network-state-dot"></span><span class="network-state-text">กำลังตรวจสอบ</span></span>
          </div>
          <div class="network-interface-name">Interface: <span>--</span></div>
          <div class="traffic-metrics">
            <div><span class="traffic-label">รับจากผู้ใช้</span><strong class="traffic-rx">--</strong><small>Mbps</small></div>
            <div><span class="traffic-label">ส่งไปผู้ใช้</span><strong class="traffic-tx">--</strong><small>Mbps</small></div>
          </div>
          <div class="traffic-chart-wrap">
            <canvas id="lanTrafficChart" aria-label="กราฟรับและส่งข้อมูลของ Hotspot LAN 5 นาทีล่าสุด" role="img"></canvas>
            <p class="chart-fallback">ไม่สามารถโหลดกราฟได้ กรุณาดูค่าปัจจุบันด้านบน</p>
          </div>
        </article>
      </div>
    </section>

    <section class="speedtest-section" aria-labelledby="speedtestTitle">
      <div class="speedtest-header">
        <div>
          <h2 id="speedtestTitle">WAN Speedtest</h2>
          <p>ทดสอบจาก คณะวิทยาการจัดการ ผ่าน WAN ของ MikroTik</p>
        </div>
        <button type="button" class="btn btn-sm btn-primary speedtest-button" id="speedtestBtn">
          <span aria-hidden="true">&#9654;</span>
          <span>ทดสอบ WAN ตอนนี้</span>
        </button>
        <a class="btn btn-sm btn-secondary report-download-button" id="networkReportBtn"
           href="../api/network_report.php" download>
          <span aria-hidden="true">&#8681;</span>
          <span>Export PDF</span>
        </a>
      </div>

      <div class="speedtest-latest" aria-live="polite" aria-atomic="true">
        <div class="speedtest-metric">
          <span>Download</span>
          <strong id="speedtestDownload">--</strong><small>Mbps</small>
        </div>
        <div class="speedtest-metric">
          <span>Upload</span>
          <strong id="speedtestUpload">--</strong><small>Mbps</small>
        </div>
        <div class="speedtest-metric">
          <span>Ping</span>
          <strong id="speedtestPing">--</strong><small>ms</small>
        </div>
        <div class="speedtest-meta">
          <span>สถานะ <strong id="speedtestStatus" class="speedtest-status">ยังไม่มีผลทดสอบ</strong></span>
          <span>ทดสอบเมื่อ <strong id="speedtestTestedAt">--</strong></span>
          <span>Server <strong id="speedtestServer">--</strong></span>
        </div>
      </div>

      <p class="speedtest-note">การทดสอบอาจใช้แบนด์วิดท์ชั่วคราว และส่งผลต่อผู้ใช้งานในขณะทดสอบ</p>

      <div class="speedtest-history">
        <h3>ผลทดสอบล่าสุด</h3>
        <div class="table-wrapper">
          <table>
            <caption class="sr-only">ประวัติผลทดสอบ WAN ล่าสุดไม่เกิน 10 รายการ</caption>
            <thead>
              <tr><th scope="col">เวลา</th><th scope="col">Download</th><th scope="col">Upload</th><th scope="col">Ping</th><th scope="col">Server</th><th scope="col">สถานะ</th></tr>
            </thead>
            <tbody id="speedtestHistoryBody"></tbody>
          </table>
          <p class="speedtest-empty" id="speedtestHistoryEmpty">ยังไม่มีประวัติการทดสอบ</p>
        </div>
      </div>
    </section>

    <!-- Members Panel -->
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title">Members</span>
        <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">
          <input type="search" class="search-input" id="searchInput" placeholder="ค้นหาชื่อ, อีเมล, รหัสบัตร…" />
          <button class="btn btn-sm btn-primary" id="bulkApproveBtn" style="display:none;">อนุมัติที่เลือก (<span id="selectedCount">0</span>)</button>
        </div>
      </div>

      <!-- Filter tabs -->
      <div class="filter-tabs">
        <button class="filter-tab active" data-status="">ทั้งหมด</button>
        <button class="filter-tab" data-status="PENDING">Pending</button>
        <button class="filter-tab" data-status="ACTIVE">Active</button>
        <button class="filter-tab" data-status="SUSPENDED">Suspended</button>
      </div>

      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th><input type="checkbox" id="selectAll" /></th>
              <th>#</th>
              <th>ชื่อ-สกุล</th>
              <th>Email</th>
              <th>รหัสบัตร / นักศึกษา</th>
              <th>วันเกิด</th>
              <th>ประเภท</th>
              <th>สถานะ</th>
              <th>ลงทะเบียน</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="membersBody"></tbody>
        </table>
        <div class="empty-state" id="emptyState" style="display:none;">ไม่พบรายการ</div>
      </div>

      <!-- Pagination -->
      <div class="pagination" id="pagination"></div>
    </div>

    <section class="access-log-panel" aria-labelledby="accessLogTitle">
      <div class="network-panel-header">
        <h2 id="accessLogTitle" class="network-panel-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
          Access Logs (พ.ร.บ. คอมพิวเตอร์ ม. 26)
        </h2>
        <div class="network-panel-actions">
          <button type="button" class="btn btn-sm" id="refreshLogBtn">Refresh</button>
          <button type="button" class="btn btn-sm btn-primary" id="exportLogBtn">Export CSV</button>
        </div>
      </div>
      <div class="network-panel-body" style="padding:1rem 1.25rem 1.25rem;">
        <div class="alog-filters">
          <div class="alog-field">
            <label>From</label>
            <input type="date" id="logFromDate">
          </div>
          <div class="alog-field">
            <label>To</label>
            <input type="date" id="logToDate">
          </div>
          <div class="alog-field">
            <label>Username</label>
            <input type="text" id="logUsername" placeholder="citizen_id">
          </div>
          <div class="alog-field">
            <label>Source IP</label>
            <input type="text" id="logSrcIp" placeholder="10.12.x.x">
          </div>
        </div>
        <div class="alog-stats" id="logStats">
          <span class="alog-stat s-logs">Logs <strong id="logTotalCount">—</strong></span>
          <span class="alog-stat s-today">Today <strong id="logTodayCount">—</strong></span>
          <span class="alog-stat s-anom">Anomalies <strong id="logAnomalyCount">—</strong></span>
          <span class="alog-stat s-oldest">Oldest <strong id="logOldestDate">—</strong></span>
        </div>
        <div class="alog-table-wrap">
          <table class="alog-table">
            <thead>
              <tr>
                <th>When</th>
                <th>User</th>
                <th>IP</th>
                <th class="ta-r">Duration</th>
                <th class="ta-r">Bytes</th>
                <th class="ta-c">Status</th>
              </tr>
            </thead>
            <tbody id="logTableBody">
              <tr><td colspan="6" style="padding:1rem;text-align:center;color:#9ca3af;">กำลังโหลด…</td></tr>
            </tbody>
          </table>
        </div>
        <p class="alog-footnote">
          Logs retained &ge; 90 days per Thailand Computer Crime Act B.E. 2550 Section 26.
          Archived daily to /var/log/hotspot-logs/, purged after 2 years.
        </p>
      </div>
    </section>

  </main>

  <!-- Image modal -->
  <dialog id="imageModal">
    <div class="modal-inner">
      <button class="modal-close" id="closeImageModal">&times;</button>
      <img id="modalImg" src="" alt="ID Card" style="max-width:100%;max-height:80vh;display:block;margin:auto;" />
    </div>
  </dialog>

  <!-- Suspend modal -->
  <dialog id="suspendModal">
    <div class="modal-inner">
      <h3 style="margin:0 0 1rem;">ระบุเหตุผลการระงับ</h3>
      <textarea id="suspendNote" rows="3" style="width:100%;box-sizing:border-box;padding:.5rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:.9rem;" placeholder="เหตุผล (ไม่บังคับ)"></textarea>
      <div style="margin-top:1rem;display:flex;gap:.75rem;justify-content:flex-end;">
        <button class="btn btn-sm" id="cancelSuspendBtn">ยกเลิก</button>
        <button class="btn btn-sm btn-warning" id="confirmSuspendBtn">ยืนยันระงับ</button>
      </div>
    </div>
  </dialog>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
  <script src="../js/access_log.js?v=<?= filemtime(__DIR__."/../js/access_log.js") ?>"></script>
  <script src="../js/admin.js?v=<?= filemtime(__DIR__.'/../js/admin.js') ?>"></script>
<footer style="margin-top:3rem;padding:1.5rem 1rem;text-align:center;color:#6b7280;font-size:.85rem;border-top:1px solid #e5e7eb;">
    <div style="margin-bottom:.4rem;">
      Create by <strong style="color:#374151;">FMS: Information Technology Team</strong>
    </div>
    <div>
      &copy; <?= date('Y') ?> FMS Hotspot Management System. All rights reserved.
    </div>
  </footer>
</body>
</html>
