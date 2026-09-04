# OpenClaw → FMS Hotspot Access Log Integration

FMS Hotspot now accepts per-session access logs from the OpenClaw sync
daemon, in compliance with Thailand's Computer Crime Act B.E. 2550
(amended 2560) Section 26.

## Endpoint

```
POST https://fms.pnu.ac.th/hotspot/api/log_session.php
Authorization: Bearer <SYNC_KEY>
Content-Type: application/json
```

Use the same `SYNC_KEY` already configured in `/var/www/html/hotspot/config.php`.

## Payload

Send a single event object, OR an array of events (batch).

### Single event (login)
```json
{
  "event": "login",
  "username":    "1909800000001",
  "full_name":   "สมชาย ใจดี",
  "citizen_id":  "1-9098-00000-00-1",
  "src_ip":      "10.12.1.50",
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "session_id":  "MikroTik-8A0B1C2D",
  "nas_ip":      "10.12.0.1",
  "nas_name":    "MikroTik-Hotspot-01",
  "login_at":    "2026-09-04T03:30:00Z",
  "user_agent":  "Mozilla/5.0 (Macintosh) ..."
}
```

### Logout (session ended)
```json
{
  "event":       "logout",
  "username":    "1909800000001",
  "src_ip":      "10.12.1.50",
  "session_id":  "MikroTik-8A0B1C2D",
  "login_at":    "2026-09-04T03:30:00Z",
  "logout_at":   "2026-09-04T04:30:00Z",
  "duration_s":  3600,
  "bytes_in":    5000000,
  "bytes_out":   1500000,
  "destination_count": 42
}
```

### Update (mid-session traffic counter refresh)
```json
{
  "event":      "update",
  "username":   "1909800000001",
  "session_id": "MikroTik-8A0B1C2D",
  "src_ip":     "10.12.1.50",
  "login_at":   "2026-09-04T03:30:00Z",
  "bytes_in":   2500000,
  "bytes_out":  750000
}
```

### Batch (recommended for efficiency)
```json
[
  {"event": "login",  ...},
  {"event": "logout", ...},
  {"event": "update", ...}
]
```

## Required vs optional fields

| Field              | Required | Notes |
|--------------------|----------|-------|
| `event`            | yes      | `login` \| `logout` \| `update` (default: `login`) |
| `username`         | yes      | MikroTik username (often citizen_id) |
| `src_ip`           | yes      | IPv4 or IPv6 |
| `login_at`         | yes      | ISO 8601 UTC timestamp |
| `full_name`        | no       | For richer audit trail |
| `citizen_id`       | no       | For PDPA audit linkage |
| `mac_address`      | no       | AA:BB:CC:DD:EE:FF format |
| `session_id`       | no       | MikroTik session ID (for tracking multiple events) |
| `nas_ip`           | no       | MikroTik RouterOS IP |
| `nas_name`         | no       | MikroTik RouterOS hostname |
| `logout_at`        | logout only | ISO 8601 UTC |
| `duration_s`       | logout only | seconds since login |
| `bytes_in`         | no       | cumulative bytes received |
| `bytes_out`        | no       | cumulative bytes sent |
| `destination_count`| no       | number of unique remote IPs contacted |
| `user_agent`       | no       | truncated to 256 chars |
| `raw_mikrotik`     | no       | full MikroTik active row JSON for forensic |

## Response

```json
{"ok": true, "inserted": 2, "errors": []}
```

- HTTP 200 on success (inserted > 0)
- HTTP 400 if all events invalid
- HTTP 401 if Bearer token missing/wrong
- HTTP 405 if not POST

## What NOT to log

Thailand's PDPA + Computer Crime Act requires storing the minimum
necessary. Do NOT send:

- Page URLs visited (store destination_count only, not URLs)
- DNS queries
- Email/chat/message content
- Passwords (hash only)
- URLs with query strings containing personal data

## MikroTik API integration tips

If pulling from `/ip hotspot active` and `/ip hotspot user`:

```python
from routeros_api import RouterOsApiPool

api = RouterOsApiPool('10.12.0.1', username='admin', password='...')
resource = api.get_resource('/ip/hotspot/active')

for user in resource.get():
    yield {
        "event": "login",
        "username": user.get("user"),
        "src_ip":   user.get("address"),
        "mac_address": user.get("mac-address"),
        "session_id": f"MikroTik-{user.get('.id')}",
        "login_at":   user.get("login-by"),  # may need normalization
        "nas_ip":     "10.12.0.1",
        "bytes_in":   int(user.get("bytes-in", 0)),
        "bytes_out":  int(user.get("bytes-out", 0)),
    }
```

## Compliance notes

- Server clock is NTP-synced (systemd-timesyncd, Stratum 0)
- Logs stored in MySQL `hotspot_access_logs`
- Daily archive to `/var/log/hotspot-logs/hotspot-YYYY-MM-DD.json.gz`
  via cron at 03:17 (after 90 days minimum retention)
- Archives purged after 2 years (730 days)
- Server-side append-only flag (`chattr +a`) on archive dir
- File permissions: 640 (www-data owner, root group can read)
