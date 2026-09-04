#!/usr/bin/env python3
"""Watch the hotspot network status cache and alert on staleness.

The OpenClaw server (on the LAN with MikroTik) POSTs WAN/LAN/speedtest
state to fms.pnu.ac.th every ~5 seconds. This script verifies that the
cache file is fresh and sends a Telegram alert via the DeZee Hermes bot
if it goes stale.

Cache path: /var/lib/hotspot/network_status.json (configurable via
NETWORK_STATUS_CACHE_FILE; was /tmp before — Apache PrivateTmp made
that path unwritable from PHP).

Thresholds:
- STALE_THRESHOLD_S: 150 seconds (5 min, matches PHP max age)
- DOWN_THRESHOLD_S: 600 seconds (10 min — page is no longer trustworthy)
"""

from __future__ import annotations

import fcntl
import json
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

STALE_THRESHOLD_S = 150
DOWN_THRESHOLD_S = 600

# Cache file written by PHP at the path set in config.php
CACHE_FILE = Path("/var/lib/hotspot/network_status.json")

STATE_FILE = Path("/opt/hermes/state/hotspot_network_monitor.json")
LOCK_FILE = Path("/opt/hermes/state/hotspot_network_monitor.lock")


def read_cache_age() -> tuple:
    """Return (age_seconds, fetched_at_iso) or (None, None) if no cache."""
    if not CACHE_FILE.exists():
        return None, None
    try:
        data = json.loads(CACHE_FILE.read_text())
    except (OSError, json.JSONDecodeError):
        return None, None
    fetched_at = data.get("fetched_at")
    if not fetched_at:
        return None, None
    try:
        dt = datetime.fromisoformat(fetched_at.replace("Z", "+00:00"))
        age = int((datetime.now(timezone.utc) - dt).total_seconds())
        return age, fetched_at
    except (ValueError, TypeError):
        return None, fetched_at


def load_state() -> dict:
    if STATE_FILE.exists():
        try:
            return json.loads(STATE_FILE.read_text())
        except (OSError, json.JSONDecodeError):
            pass
    return {"last_alert_level": "ok", "last_check_ts": 0}


def save_state(state: dict) -> None:
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(state))
    tmp.replace(STATE_FILE)


def send_telegram(text: str) -> bool:
    """Send via DeZee Hermes bot. Reuses credentials in /opt/data."""
    try:
        result = subprocess.run(
            ["docker", "exec", "fms-hermes", "hermes", "send", "-t", "telegram", text],
            capture_output=True, text=True, timeout=20,
        )
        return result.returncode == 0
    except Exception as exc:
        print(f"send_telegram error: {exc}", file=sys.stderr)
        return False


def main() -> int:
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_FILE.open("w") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0

        age, fetched_at = read_cache_age()
        state = load_state()
        now = int(time.time())

        if age is None:
            level = "down"
            msg = "[Hotspot Network Monitor] ไม่พบ cache file - OpenClaw daemon อาจไม่ได้ส่งข้อมูลมาเลย"
        elif age >= DOWN_THRESHOLD_S:
            minutes = age // 60
            msg = (
                "[Hotspot Network Monitor] Cache เก่ามาก ({m} นาที) - "
                "หน้า 'สถานะเครือข่าย' บน fms.pnu.ac.th/hotspot/admin/ ไม่น่าเชื่อถือ\n"
                "เวลาอัปเดตล่าสุด: {f}\n"
                "ตรวจสอบ OpenClaw sync daemon บน LAN server (10.11.8.23)"
            ).format(m=minutes, f=fetched_at)
            level = "down"
        elif age >= STALE_THRESHOLD_S:
            msg = (
                "[Hotspot Network Monitor] Cache เก่า {a} วินาที\n"
                "อัปเดตล่าสุด: {f}\n"
                "หน้าเว็บยังแสดงข้อมูลเก่าอยู่ - user อาจสับสนได้"
            ).format(a=age, f=fetched_at)
            level = "stale"
        else:
            level = "ok"
            msg = None
            if state.get("last_alert_level") in ("stale", "down"):
                msg = "[Hotspot Network Monitor] กลับมาปกติแล้ว (cache อายุ {a} วินาที)".format(a=age)

        last_level = state.get("last_alert_level", "ok")
        last_ts = int(state.get("last_check_ts", 0))
        should_alert = False
        if msg:
            if level != last_level:
                should_alert = True
            elif level == "down" and now - last_ts >= 900:
                should_alert = True
            elif level == "stale" and now - last_ts >= 1800:
                should_alert = True

        if should_alert:
            ok = send_telegram(msg)
            print(f"[{datetime.now(timezone.utc).isoformat()}] level={level} age={age}s sent={ok}")
        else:
            print(f"[{datetime.now(timezone.utc).isoformat()}] level={level} age={age}s (no alert)")

        state["last_alert_level"] = level
        state["last_check_ts"] = now
        state["last_age"] = age
        state["last_fetched_at"] = fetched_at
        save_state(state)
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
