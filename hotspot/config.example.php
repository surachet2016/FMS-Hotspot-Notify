<?php
define("DB_HOST", "localhost");
define("DB_NAME", "mikrotik_hotspot");
define("DB_USER", "hotspot_user");
define("DB_PASS", "upBddJMOTfKa9BZyoO4AKHeRKcSAI");
define("DB_CHARSET", "utf8mb4");

define("SESSION_NAME", "hotspot_admin");
define("SESSION_LIFETIME", 28800);

define("MIKROTIK_HOST", "10.12.0.1");
define("MIKROTIK_PORT", 8728);
define("MIKROTIK_USER", "admin");
define("MIKROTIK_PASS", "PLACEHOLDER_NOT_USED_ON_FMS_PNU");
define("MIKROTIK_WAN_INTERFACE", "ether1");
define("MIKROTIK_LAN_INTERFACE", "bridge1");
define("MIKROTIK_INTERNET_PROBE", "1.1.1.1");
define("NETWORK_STATUS_MAX_AGE", 150);
define("NETWORK_SPEEDTEST_STATE_FILE", "/var/lib/hotspot/speedtest.json");
define("NETWORK_STATUS_CACHE_FILE", "/var/lib/hotspot/network_status.json");
define("MIKROTIK_DELETION_QUEUE_FILE", "/var/lib/hotspot/deletions.json");

define("SYNC_KEY", "xRP5TrixgNTjgO0M6I8omJKTDK62FBQGqlS3cOn5ac");

define("MAIL_FROM", "hotspot@fms.pnu.ac.th");
define("MAIL_FROM_NAME", "Hotspot System");
define("NETWORK_REPORT_FILE", "/var/lib/hotspot/network_report.pdf");
define("NETWORK_STATUS_CACHE_FILE", "/var/lib/hotspot/network_status.json");
