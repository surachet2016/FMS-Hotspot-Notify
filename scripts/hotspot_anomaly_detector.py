#!/usr/bin/env python3
"""Detect anomalies in hotspot access logs.

Checks (every minute via cron):
1. Long sessions: login > 6h without logout (likely forgotten)
2. Same user logging in from many IPs in <5 min (credential sharing)
3. Same IP used by many users in <5 min (NAT or shared device)
4. First-time IP for established user (possible session hijack)

Sends Telegram alert via DeZee Hermes bot when anomaly detected.
Uses deduplication state file to avoid repeated alerts.
"""

from __future__ import annotations

import fcntl
import json
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

STATE_FILE = Path("/opt/hermes/state/hotspot_anomaly_detector.json")
LOCK_FILE = Path("/opt/hermes/state/hotspot_anomaly_detector.lock")

# Thresholds
LONG_SESSION_HOURS = 6
IP_BURST_WINDOW_MIN = 5
IP_BURST_USER_THRESHOLD = 3
IP_BURST_USERS_AT_SAME_IP = 5


def query_mysql(sql: str) -> list:
    result = subprocess.run(
        ["mysql", "-u", "hotspot_user",
         "-pupBddJMOTfKa9BZyoO4AKHeRKcSAI", "mikrotik_hotspot",
         "-N", "-B", "-e", sql],
        capture_output=True, text=True, timeout=30,
    )
    if result.returncode != 0:
        raise RuntimeError(f"mysql error: {result.stderr}")
    rows = []
    for line in result.stdout.strip().splitlines():
        if not line:
            continue
        rows.append(line.split("\t"))
    return rows


def send_telegram(text: str) -> bool:
    try:
        result = subprocess.run(
            ["docker", "exec", "fms-hermes", "hermes", "send", "-t", "telegram", text],
            capture_output=True, text=True, timeout=20,
        )
        return result.returncode == 0
    except Exception as exc:
        print(f"send_telegram error: {exc}", file=sys.stderr)
        return False


def check_long_sessions(state: dict) -> list:
    cutoff = (datetime.now(timezone.utc) - timedelta(hours=LONG_SESSION_HOURS)).strftime('%Y-%m-%d %H:%M:%S')
    rows = query_mysql(
        "SELECT username, src_ip, login_at FROM hotspot_access_logs "
        f"WHERE event='login' AND logout_at IS NULL AND login_at < '{cutoff}' "
        "ORDER BY login_at LIMIT 20"
    )
    alerts = []
    for username, src_ip, login_at in rows:
        key = f"long:{username}:{login_at}"
        if state.get(key):
            continue
        alerts.append(("long_session", key,
            f"[Anomaly] Long session (>{LONG_SESSION_HOURS}h)\n"
            f"- User: {username}\n"
            f"- IP: {src_ip}\n"
            f"- Login at: {login_at}"))
    return alerts


def check_ip_burst_per_user(state: dict) -> list:
    cutoff = (datetime.now(timezone.utc) - timedelta(minutes=IP_BURST_WINDOW_MIN)).strftime('%Y-%m-%d %H:%M:%S')
    rows = query_mysql(
        "SELECT username, COUNT(DISTINCT src_ip) AS n FROM hotspot_access_logs "
        f"WHERE login_at >= '{cutoff}' AND event='login' "
        "GROUP BY username HAVING n >= " + str(IP_BURST_USER_THRESHOLD) + " LIMIT 10"
    )
    alerts = []
    for username, n in rows:
        key = f"burst_user:{username}"
        if state.get(key):
            continue
        alerts.append(("burst_user", key,
            f"[Anomaly] Same user from {n} IPs in {IP_BURST_WINDOW_MIN} min\n"
            f"- User: {username}\n"
            f"- Possible credential sharing or session hijack"))
    return alerts


def check_ip_burst_users(state: dict) -> list:
    cutoff = (datetime.now(timezone.utc) - timedelta(minutes=IP_BURST_WINDOW_MIN)).strftime('%Y-%m-%d %H:%M:%S')
    rows = query_mysql(
        "SELECT src_ip, COUNT(DISTINCT username) AS n FROM hotspot_access_logs "
        f"WHERE login_at >= '{cutoff}' AND event='login' "
        "GROUP BY src_ip HAVING n >= " + str(IP_BURST_USERS_AT_SAME_IP) + " LIMIT 10"
    )
    alerts = []
    for src_ip, n in rows:
        key = f"burst_ip:{src_ip}"
        if state.get(key):
            continue
        alerts.append(("burst_ip", key,
            f"[Anomaly] IP {src_ip} used by {n} users in {IP_BURST_WINDOW_MIN} min\n"
            f"- Possible NAT, shared device, or open access point"))
    return alerts


def check_first_time_ip(state: dict) -> list:
    """A user logging in from a brand new IP for the first time."""
    cutoff = (datetime.now(timezone.utc) - timedelta(minutes=10)).strftime('%Y-%m-%d %H:%M:%S')
    rows = query_mysql(
        "SELECT a.username, a.src_ip, a.login_at FROM hotspot_access_logs a "
        "WHERE a.event='login' AND a.login_at >= '" + cutoff + "' "
        "AND NOT EXISTS ("
        "  SELECT 1 FROM hotspot_access_logs b "
        "  WHERE b.username = a.username AND b.src_ip = a.src_ip "
        "  AND b.login_at < a.login_at AND b.login_at > DATE_SUB(a.login_at, INTERVAL 30 DAY)"
        ") LIMIT 20"
    )
    alerts = []
    for username, src_ip, login_at in rows:
        key = f"new_ip:{username}:{src_ip}"
        if state.get(key):
            continue
        # Don't alert on first-time login (no history); only after they have history
        has_history = query_mysql(
            f"SELECT COUNT(*) FROM hotspot_access_logs WHERE username='{username}' AND login_at < '{login_at}' LIMIT 1"
        )
        if has_history and int(has_history[0][0]) > 0:
            alerts.append(("new_ip", key,
                f"[Anomaly] New IP for user {username}\n"
                f"- IP: {src_ip}\n"
                f"- First seen: {login_at}"))
    return alerts


def main() -> int:
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_FILE.open("w") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0

        # Load state + dedupe (clear after 6h)
        state = {}
        if STATE_FILE.exists():
            try:
                state = json.loads(STATE_FILE.read_text())
            except Exception:
                pass

        now = datetime.now(timezone.utc)
        cutoff_ts = (now - timedelta(hours=6)).isoformat()
        state = {k: v for k, v in state.items() if v > cutoff_ts}

        all_alerts = []
        try:
            all_alerts.extend(check_long_sessions(state))
            all_alerts.extend(check_ip_burst_per_user(state))
            all_alerts.extend(check_ip_burst_users(state))
            all_alerts.extend(check_first_time_ip(state))
        except Exception as exc:
            print(f"check error: {exc}", file=sys.stderr)
            send_telegram(f"[Anomaly Detector] ERROR: {exc}")
            return 1

        if all_alerts:
            ts = now.isoformat()
            for kind, key, msg in all_alerts:
                state[key] = ts
                send_telegram(msg)
                print(f"alert sent [{kind}] {key}")
            print(f"alerts sent: {len(all_alerts)}")

        STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
        tmp = STATE_FILE.with_suffix(".tmp")
        tmp.write_text(json.dumps(state))
        tmp.replace(STATE_FILE)
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
