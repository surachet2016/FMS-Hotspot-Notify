<?php
// Copy this file to config.php and fill in your values
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

define('SESSION_NAME', 'hotspot_admin');
define('SESSION_LIFETIME', 28800); // 8 hours

// MikroTik RouterOS API
define('MIKROTIK_HOST', 'your.mikrotik.host');
define('MIKROTIK_PORT', 8728);
define('MIKROTIK_USER', 'admin');
define('MIKROTIK_PASS', '');  // ใส่ password MikroTik จริง
// Interfaces reported by the admin network-status endpoint
define('MIKROTIK_WAN_INTERFACE', 'ether1');
define('MIKROTIK_LAN_INTERFACE', 'bridge1');
define('MIKROTIK_INTERNET_PROBE', '1.1.1.1');
define('NETWORK_STATUS_MAX_AGE', 150); // seconds before the dashboard marks data stale
// Optional override; the default is a private file in PHP's system temp directory.
define('NETWORK_SPEEDTEST_STATE_FILE', sys_get_temp_dir() . '/hotspot_network_speedtest.json');
define('NETWORK_REPORT_FILE', sys_get_temp_dir() . '/hotspot_network_report.pdf');
define('MIKROTIK_DELETION_QUEUE_FILE', sys_get_temp_dir() . '/hotspot_mikrotik_deletions.json');

// Sync key
define('SYNC_KEY', 'change-this-secret-key');

// Email
define('MAIL_FROM',      'hotspot@yourdomain.ac.th');
define('MAIL_FROM_NAME', 'Hotspot System');
