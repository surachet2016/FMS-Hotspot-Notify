# MikroTik Cookie Persistence — Compliance Notes

MikroTik HotSpot has two cookie mechanisms that affect our access log:

## 1. HTTP Cookie

When a user logs in successfully, MikroTik sets a cookie in their
browser. If they reconnect within `cookie-lifetime`, they are
**automatically logged in without re-entering credentials**.

- Default `cookie-lifetime` = 3 days
- Configurable per HotSpot profile: `/ip hotspot profile set <name> cookie-lifetime=3d`

**Impact on our logs:**
- `logout_at` is NOT recorded when cookie expires
- `event='login'` is recorded on each successful re-login
- A user can appear "online" for hours after walking away

**Mitigation in our system:**
- `check_cookie_lingering()` anomaly detector flags sessions with no
  traffic for >2h but still no `logout_at` (MikroTik cookie still valid)
- Optional: force `cookie-lifetime=0` for stricter accounting

## 2. MAC Cookie

MikroTik binds a cookie to the device's MAC address. When the same
device reconnects, it auto-logs in via MAC cookie.

- Default `mac-cookie-timeout` = 3 days
- Per profile: `/ip hotspot profile set <name> mac-cookie-timeout=3d`

**Impact:**
- Same as HTTP cookie — user may not appear as "logged in" in `/ip hotspot active`
  but their MAC cookie is valid and they will auto-login on reconnect

## 3. MikroTik Configuration Recommended for Compliance

To get **accurate session times** in our audit logs, we recommend:

```routeros
# Short cookie lifetime to force re-login more often
/ip hotspot profile set <name> \
    cookie-lifetime=8h \
    mac-cookie-timeout=8h \
    http-cookie-lifetime=8h

# Track all login attempts (including cookie-based)
/ip hotspot profile set <name> \
    login-by=http-chap,cookie \
    use-radius=yes

# Increase log verbosity for compliance
/system logging add topics=hotspot,debug action=memory
```

## 4. Active Sessions API

For real-time session tracking, our OpenClaw daemon polls:

```
/ip hotspot active print detail
  - returns: .id, user, address, mac-address, login-by, uptime,
             bytes-in, bytes-out
```

Polling this every 60 seconds lets us detect:
- Idle sessions (active but no bytes transferred)
- Long sessions (uptime > 6h)
- Sessions that ended (no longer in active list)

## 5. Endpoints to query

| MikroTik path | Returns |
|---|---|
| `/ip hotspot active` | currently active sessions |
| `/ip hotspot user` | registered hotspot users (with profiles) |
| `/ip hotspot cookie print` | HTTP cookies issued (with expiry time) |
| `/ip hotspot cookie print where mac-cookie=yes` | MAC cookies issued |
| `/log print where topics~"hotspot"` | login/logout events with timestamps |
| `/ip hotspot active remove [find]` | force-end a session (admin action) |

## 6. Force-logout a session (admin only)

```routeros
# Single session
/ip hotspot active remove [find user="alice"]

# All sessions for an IP
/ip hotspot active remove [find address="10.12.1.100"]

# Force-end all idle sessions (uptime > 6h, bytes < 1MB)
/ip hotspot active remove [find uptime>6h]
```

Or via our admin UI: an "End Session" button next to each active user.
