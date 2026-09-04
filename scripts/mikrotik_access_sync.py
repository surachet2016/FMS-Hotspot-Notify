#!/usr/bin/env python3
"""Hermes MikroTik Access Log Sync Daemon.

Polls /ip hotspot active from MikroTik every 60s, computes diff vs
previous state, and POSTs login/logout/update events to fms.pnu.ac.th.

This is a DIFFERENTIAL POLLER — it does NOT re-send the full active
list each minute. Only changes (new sessions, ended sessions,
traffic counter updates) are sent, keeping traffic to ~50 MB/month
for 100 users (vs 10 GB/month for naive full-list polling).

State is held in /opt/hermes/state/mikrotik_active_cache.json
(atomic write + fcntl lock so multiple processes can coexist).

Required env (read from /etc/hermes/mikrotik.env):
  MIKROTIK_HOST       e.g. 10.11.0.1
  MIKROTIK_PORT       e.g. 8728  (default 8728)
  MIKROTIK_USER       e.g. admin
  MIKROTIK_PASS       password (plaintext in env file)
  FMS_LOG_API         e.g. https://fms.pnu.ac.th/hotspot/api/log_session.php
  FMS_SYNC_KEY        bearer token (from /var/www/html/hotspot/config.php)
  POLL_INTERVAL       seconds (default 60)

Routes: API uses routeros_api if available, otherwise raw socket API.
"""

from __future__ import annotations

import fcntl
import json
import logging
import os
import signal
import socket
import struct
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

try:
    from routeros_api import RouterOsApiPool
    HAVE_ROUTEROS_API = True
except ImportError:
    HAVE_ROUTEROS_API = False

STATE_FILE = Path("/opt/hermes/state/mikrotik_active_cache.json")
LOCK_FILE = Path("/opt/hermes/state/mikrotik_active_cache.lock")
ENV_FILE = Path("/etc/hermes/mikrotik.env")
LOG_FILE = Path("/var/log/mikrotik-access-sync.log")

POLL_INTERVAL_DEFAULT = 60


def load_env() -> dict:
    cfg = {}
    if not ENV_FILE.exists():
        sys.exit(f"missing config: {ENV_FILE} — run setup-mikrotik-creds.sh first")
    for line in ENV_FILE.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" in line:
            k, v = line.split("=", 1)
            cfg[k.strip()] = v.strip()
    required = ["MIKROTIK_HOST", "MIKROTIK_USER", "MIKROTIK_PASS", "FMS_LOG_API", "FMS_SYNC_KEY"]
    for k in required:
        if not cfg.get(k):
            sys.exit(f"missing env var: {k}")
    return cfg


def fetch_active(cfg) -> list:
    """Return list of dicts with current active sessions."""
    if HAVE_ROUTEROS_API:
        return _fetch_via_api(cfg)
    return _fetch_via_socket(cfg)


def _fetch_via_api(cfg) -> list:
    pool = RouterOsApiPool(
        cfg["MIKROTIK_HOST"],
        username=cfg["MIKROTIK_USER"],
        password=cfg["MIKROTIK_PASS"],
        port=int(cfg.get("MIKROTIK_PORT", 8728)),
        plaintext_login=True,
    )
    try:
        api = pool.get_api()
        try:
            items = api.get_resource("/ip/hotspot/active").get()
            return [_parse_active_row(r) for r in items]
        finally:
            pool.close()
    except Exception as exc:
        raise RuntimeError(f"MikroTik API error: {exc}")


def _parse_active_row(r: dict) -> dict:
    """Normalize MikroTik active row → unified dict."""
    return {
        "session_id": str(r.get(".id") or r.get("session-id") or ""),
        "username": r.get("user") or r.get("name") or "",
        "src_ip": r.get("address") or r.get("caller-id") or "",
        "mac_address": r.get("mac-address") or "",
        "login_at": r.get("login-by") or r.get("uptime") or "",
        "bytes_in": int(r.get("bytes-in") or 0),
        "bytes_out": int(r.get("bytes-out") or 0),
        "uptime": r.get("uptime") or "",
    }


def _fetch_via_socket(cfg) -> list:
    """Minimal MikroTik API client over plain TCP — no extra deps."""
    host = cfg["MIKROTIK_HOST"]
    port = int(cfg.get("MIKROTIK_PORT", 8728))
    user = cfg["MIKROTIK_USER"]
    pwd = cfg["MIKROTIK_PASS"]

    def send(sock, words: list):
        """Encode + send API sentence."""
        data = b"\x00".join(w.encode() for w in words) + b"\x00\x00"
        sock.sendall(struct.pack("!I", len(data)) + data)

    def recv(sock) -> list:
        """Read one API sentence."""
        raw = sock.recv(4)
        if not raw:
            return []
        length = struct.unpack("!I", raw)[0]
        buf = b""
        while len(buf) < length:
            chunk = sock.recv(length - len(buf))
            if not chunk:
                break
            buf += chunk
        return buf.decode("utf-8", errors="replace").rstrip("\x00").split("\x00")

    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.settimeout(15)
    s.connect((host, port))
    try:
        # Login
        send(s, ["/login", f"=name={user}", f"=password={pwd}"])
        resp = recv(s)
        if not resp or "=ret=" in resp and "=ret=-1" in ",".join(resp):
            raise RuntimeError("login failed")
        # Query
        send(s, ["/ip/hotspot/active/print", "=.proplist=.id,user,address,mac-address,login-by,bytes-in,bytes-out,uptime"])
        rows = []
        current = {}
        while True:
            resp = recv(s)
            if resp == ["!done"]:
                if current:
                    rows.append(_parse_active_row(current))
                break
            if resp == ["!re"]:
                if current:
                    rows.append(_parse_active_row(current))
                current = {}
                continue
            for item in resp:
                if item.startswith("="):
                    if current:
                        rows.append(_parse_active_row(current))
                    current = {}
                    break
            for item in resp:
                if item.startswith("="):
                    k, _, v = item[1:].partition("=")
                    current[k.replace("-", "_")] = v
        return rows
    finally:
        s.close()


def post_event(cfg: dict, event: dict) -> bool:
    """POST single event to fms log_session.php."""
    try:
        result = subprocess.run(
            ["curl", "-sk", "--max-time", "15",
             "-H", f"Authorization: Bearer {cfg['FMS_SYNC_KEY']}",
             "-H", "Content-Type: application/json",
             "-X", "POST",
             cfg["FMS_LOG_API"],
             "-d", json.dumps(event)],
            capture_output=True, text=True, timeout=20,
        )
        if result.returncode != 0:
            logging.error(f"curl error: {result.stderr[:200]}")
            return False
        resp = json.loads(result.stdout)
        if resp.get("ok"):
            return True
        logging.error(f"server rejected: {resp}")
        return False
    except Exception as exc:
        logging.error(f"post error: {exc}")
        return False


def load_cache() -> dict:
    if not STATE_FILE.exists():
        return {"active": {}, "last_run": 0}
    try:
        return json.loads(STATE_FILE.read_text())
    except Exception:
        return {"active": {}, "last_run": 0}


def save_cache(cache: dict) -> None:
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(cache, indent=2, ensure_ascii=False))
    tmp.replace(STATE_FILE)


def diff_and_post(cfg: dict, cache: dict, current: list) -> int:
    """Compute diff, POST events, return count posted."""
    now_iso = datetime.now(timezone.utc).isoformat()
    new_state = {item["session_id"]: item for item in current if item["session_id"]}
    old_state = cache.get("active", {})

    # Login events: in current but not in old
    posted = 0
    for sid, item in new_state.items():
        if sid not in old_state:
            event = {
                "event": "login",
                "username": item["username"],
                "src_ip": item["src_ip"],
                "mac_address": item["mac_address"],
                "session_id": sid,
                "nas_ip": cfg["MIKROTIK_HOST"],
                "nas_name": "MikroTik-Hotspot",
                "login_at": _parse_mikrotik_time(item.get("login_at", "")) or now_iso,
                "bytes_in": item["bytes_in"],
                "bytes_out": item["bytes_out"],
            }
            if post_event(cfg, event):
                logging.info(f"login: {item['username']} from {item['src_ip']}")
                posted += 1

    # Logout events: in old but not in current
    for sid, item in old_state.items():
        if sid not in new_state:
            logout_at = now_iso
            # Use previous login_at for duration calc
            login_at = item.get("login_at", "")
            duration_s = _duration_seconds(login_at, logout_at)
            event = {
                "event": "logout",
                "username": item.get("username", ""),
                "src_ip": item.get("src_ip", ""),
                "mac_address": item.get("mac_address", ""),
                "session_id": sid,
                "nas_ip": cfg["MIKROTIK_HOST"],
                "nas_name": "MikroTik-Hotspot",
                "login_at": _parse_mikrotik_time(login_at) or login_at,
                "logout_at": logout_at,
                "duration_s": duration_s,
                "bytes_in": item.get("bytes_in", 0),
                "bytes_out": item.get("bytes_out", 0),
            }
            if post_event(cfg, event):
                logging.info(f"logout: {item.get('username')} from {item.get('src_ip')} (duration={duration_s}s)")
                posted += 1

    # Update events: in both, but bytes changed significantly (>10 MB delta)
    for sid, new_item in new_state.items():
        if sid in old_state:
            old_item = old_state[sid]
            delta_in = new_item["bytes_in"] - old_item.get("bytes_in", 0)
            delta_out = new_item["bytes_out"] - old_item.get("bytes_out", 0)
            if delta_in + delta_out > 10_000_000:  # > 10 MB
                event = {
                    "event": "update",
                    "username": new_item["username"],
                    "src_ip": new_item["src_ip"],
                    "session_id": sid,
                    "login_at": _parse_mikrotik_time(new_item.get("login_at", "")) or now_iso,
                    "bytes_in": new_item["bytes_in"],
                    "bytes_out": new_item["bytes_out"],
                }
                if post_event(cfg, event):
                    logging.info(f"update: {new_item['username']} +{delta_in//1024}KB in, +{delta_out//1024}KB out")
                    posted += 1

    # Save new state
    cache["active"] = new_state
    cache["last_run"] = time.time()
    save_cache(cache)
    return posted


def _parse_mikrotik_time(s: str) -> str | None:
    """Convert MikroTik uptime like '1d2h3m4s' or ISO to ISO8601 UTC."""
    if not s:
        return None
    if "T" in s:
        return s  # already ISO
    try:
        # MikroTik uptime format: 1w2d3h4m5s
        total = 0
        num = ""
        for c in s:
            if c.isdigit() or c == ".":
                num += c
            elif c == "w":
                total += float(num or 0) * 604800
                num = ""
            elif c == "d":
                total += float(num or 0) * 86400
                num = ""
            elif c == "h":
                total += float(num or 0) * 3600
                num = ""
            elif c == "m":
                total += float(num or 0) * 60
                num = ""
            elif c == "s":
                total += float(num or 0)
                num = ""
        # uptime is relative to "now" → subtract to get login_at
        from datetime import timedelta
        login_at = datetime.now(timezone.utc) - timedelta(seconds=total)
        return login_at.isoformat()
    except Exception:
        return None


def _duration_seconds(login_at: str, logout_at: str) -> int:
    if not login_at:
        return 0
    try:
        from datetime import datetime
        s = datetime.fromisoformat(login_at.replace("Z", "+00:00"))
        e = datetime.fromisoformat(logout_at.replace("Z", "+00:00"))
        return max(0, int((e - s).total_seconds()))
    except Exception:
        return 0


def main() -> int:
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(message)s",
        handlers=[
            logging.FileHandler(LOG_FILE),
            logging.StreamHandler(sys.stdout),
        ],
    )

    cfg = load_env()
    interval = int(cfg.get("POLL_INTERVAL", POLL_INTERVAL_DEFAULT))

    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_FILE.open("w") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            logging.warning("another instance is running, exiting")
            return 0

        cache = load_cache()
        logging.info(f"sync daemon started (poll every {interval}s, target: {cfg['MIKROTIK_HOST']})")

        stop = False

        def stop_handler(*_):
            nonlocal stop
            stop = True

        signal.signal(signal.SIGTERM, stop_handler)
        signal.signal(signal.SIGINT, stop_handler)

        while not stop:
            try:
                current = fetch_active(cfg)
                posted = diff_and_post(cfg, cache, current)
                logging.info(f"polled {len(current)} active users, posted {posted} events")
            except Exception as exc:
                logging.error(f"poll failed: {exc}")

            for _ in range(interval):
                if stop:
                    break
                time.sleep(1)

        logging.info("sync daemon stopped")
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
