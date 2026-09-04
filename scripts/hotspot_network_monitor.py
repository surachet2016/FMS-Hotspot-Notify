#!/usr/bin/env python3
"""Watch the hotspot network status cache and alert on staleness or outages.

The OpenClaw server (on the LAN with MikroTik) POSTs WAN/LAN/speedtest
state to fms.pnu.ac.th every ~5 seconds. This script verifies that the
cache file is fresh and sends alerts if it goes stale, if the MikroTik
loses internet, or if the LAN/Hotspot link drops.

Alert channels:
- Telegram (immediate, via DeZee Hermes bot) — for cache staleness + recovery
- Email (immediate, via Gmail SMTP) — for actual internet/LAN outages

Thresholds:
- STALE_THRESHOLD_S: 150 seconds (5 min, matches PHP max age)
- DOWN_THRESHOLD_S: 600 seconds (10 min — page is no longer trustworthy)

Root cause attribution (matches frontend JS):
- wan.link=false | wan.running=false | wan.disabled=true -> university side (uplink cable)
- wan.link=true && internet_reachable=false -> university side (upstream gateway)
- lan.link=false | lan.running=false | lan.disabled=true -> faculty side (bridge/AP)
"""

from __future__ import annotations

import fcntl
import json
import smtplib
import subprocess
import sys
import time
from email.message import EmailMessage
from datetime import datetime, timezone
from pathlib import Path

STALE_THRESHOLD_S = 150
DOWN_THRESHOLD_S = 600

CACHE_FILE = Path("/var/lib/hotspot/network_status.json")

STATE_FILE = Path("/opt/hermes/state/hotspot_network_monitor.json")
LOCK_FILE = Path("/opt/hermes/state/hotspot_network_monitor.lock")

SMTP_ENV_FILE = Path("/opt/hermes/state/gmail_smtp.env")

ADMIN_EMAILS = [
    "surachetsungkhapan@gmail.com",
    "khongpitak@gmail.com",
]
MAIL_FROM_NAME = "FMS Hotspot Network Monitor"


def load_smtp_config() -> dict:
    if not SMTP_ENV_FILE.exists():
        return {}
    cfg = {}
    for line in SMTP_ENV_FILE.read_text().splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            cfg[k.strip()] = v.strip()
    return cfg


def send_email(smtp_cfg: dict, subject: str, body: str) -> bool:
    if not smtp_cfg:
        return False
    msg = EmailMessage()
    msg["From"] = f"{MAIL_FROM_NAME} <{smtp_cfg['GMAIL_SMTP_USER']}>"
    msg["To"] = ", ".join(ADMIN_EMAILS)
    msg["Subject"] = subject
    msg.set_content(body, charset="utf-8")
    try:
        with smtplib.SMTP_SSL(
            smtp_cfg["GMAIL_SMTP_HOST"],
            int(smtp_cfg["GMAIL_SMTP_PORT"]),
            timeout=20,
        ) as server:
            server.login(smtp_cfg["GMAIL_SMTP_USER"], smtp_cfg["GMAIL_SMTP_PASS"])
            server.send_message(msg)
        return True
    except Exception as exc:
        print(f"email error: {exc}", file=sys.stderr)
        return False


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


def read_cache() -> dict:
    """Read full cache, return dict with wan/lan/fetched_at or empty dict."""
    if not CACHE_FILE.exists():
        return {}
    try:
        return json.loads(CACHE_FILE.read_text())
    except (OSError, json.JSONDecodeError):
        return {}


def diagnose(cache: dict) -> dict:
    """Return dict of {wan_state, lan_state, root_cause, severity}."""
    wan = cache.get("wan") or {}
    lan = cache.get("lan") or {}

    wan_link = wan.get("link") is True
    wan_running = wan.get("running") is True
    wan_disabled = wan.get("disabled") is True
    internet = wan.get("internet_reachable") is True

    lan_link = lan.get("link") is True
    lan_running = lan.get("running") is True
    lan_disabled = lan.get("disabled") is True

    internet_ok = wan_link and wan_running and not wan_disabled and internet
    lan_ok = lan_link and lan_running and not lan_disabled

    if not internet_ok and not lan_ok:
        if not wan_link and not lan_link:
            return {
                "severity": "critical",
                "root_cause": "mikrotik",
                "summary": "ทั้ง WAN และ LAN มีปัญหา",
                "details": "ตรวจสอบ MikroTik router, สาย uplink, หรือ power supply",
            }
        if wan_link and not internet:
            return {
                "severity": "high",
                "root_cause": "university",
                "summary": "Internet มหาวิทยาลัยเสีย + LAN มีปัญหา",
                "details": "WAN link ขึ้น แต่ออก Internet ไม่ได้ (มหาวิทยาลัย) และ LAN link ไม่ขึ้น (คณะ)",
            }
        return {
            "severity": "high",
            "root_cause": "mixed",
            "summary": "Internet มหาวิทยาลัยเสีย + LAN คณะเสีย",
            "details": "ทั้ง upstream gateway และ MikroTik มีปัญหา",
        }

    if not internet_ok:
        if not wan_link:
            return {
                "severity": "high",
                "root_cause": "university",
                "summary": "WAN link มหาวิทยาลัยเสีย",
                "details": "สาย uplink หรืออุปกรณ์ต้นทางของมหาวิทยาลัยมีปัญหา",
            }
        return {
            "severity": "high",
            "root_cause": "university",
            "summary": "Internet มหาวิทยาลัยเสีย (upstream)",
            "details": "WAN link ปกติ แต่ upstream gateway ของมหาวิทยาลัยไม่ตอบ",
        }

    if not lan_ok:
        return {
            "severity": "high",
            "root_cause": "faculty",
            "summary": "LAN/Hotspot คณะเสีย",
            "details": "bridge, AP หรือ switch ภายในคณะมีปัญหา",
        }

    return {
        "severity": "ok",
        "root_cause": None,
        "summary": "Internet และ LAN ปกติ",
        "details": "",
    }


def format_email_body(state: dict, fetched_at: str) -> str:
    return (
        f"เรียน Admin,\n\n"
        f"ระบบ Hotspot ตรวจพบปัญหาเครือข่าย:\n\n"
        f"ปัญหา: {state['summary']}\n"
        f"สาเหตุ: {state['details']}\n"
        f"ความรุนแรง: {state['severity'].upper()}\n"
        f"เวลาอัปเดตล่าสุด: {fetched_at}\n\n"
        f"ตรวจสอบ:\n"
        f"- https://fms.pnu.ac.th/hotspot/admin/\n"
        f"- MikroTik dashboard\n\n"
        f"--\n"
        f"{MAIL_FROM_NAME}\n"
        f"(Auto-generated at {datetime.now(timezone.utc).isoformat()})\n"
    )


def format_telegram(diag: dict, fetched_at: str | None) -> str:
    if diag["severity"] == "ok":
        return f"[Hotspot Network Monitor] กลับมาปกติแล้ว"
    lines = [
        f"[Hotspot Network Monitor] ❌ {diag['summary']}",
        f"สาเหตุ: {diag['details']}",
    ]
    if fetched_at:
        lines.append(f"อัปเดตล่าสุด: {fetched_at}")
    if diag["root_cause"] == "university":
        lines.append("→ ติดต่อ: IT มหาวิทยาลัย (upstream)")
    elif diag["root_cause"] == "faculty":
        lines.append("→ ติดต่อ: ทีมคณะ (bridge/AP/switch)")
    elif diag["root_cause"] == "mikrotik":
        lines.append("→ ตรวจสอบ: MikroTik router และสาย uplink")
    return "\n".join(lines)


def main() -> int:
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_FILE.open("w") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0

        cache = read_cache()
        fetched_at = cache.get("fetched_at")
        now = int(time.time())
        age = None
        if fetched_at:
            try:
                dt = datetime.fromisoformat(fetched_at.replace("Z", "+00:00"))
                age = int((datetime.now(timezone.utc) - dt).total_seconds())
            except (ValueError, TypeError):
                pass

        # --- Staleness check (cache freshness) ---
        if age is None:
            cache_level = "down"
        elif age >= DOWN_THRESHOLD_S:
            cache_level = "down"
        elif age >= STALE_THRESHOLD_S:
            cache_level = "stale"
        else:
            cache_level = "ok"

        # --- Diagnostic check (network status) ---
        if cache_level == "ok":
            diag = diagnose(cache)
            net_level = diag["severity"]
        else:
            # Cache stale — can't determine network state
            diag = {
                "severity": "down",
                "root_cause": None,
                "summary": "ไม่ทราบสถานะเครือข่าย (cache เก่า)",
                "details": f"cache อายุ {age}s — รอข้อมูลใหม่จาก OpenClaw daemon",
            }
            net_level = "down"

        # --- Load state ---
        state = load_state_with_defaults()
        smtp_cfg = load_smtp_config()

        # --- Decide what to send ---
        prev_cache = state.get("last_cache_level", "ok")
        prev_net = state.get("last_net_level", "ok")
        prev_email_at = int(state.get("last_email_at", 0))

        cache_changed = cache_level != prev_cache
        net_changed = net_level != prev_net
        # For serious outages, re-email every 30 minutes
        re_email_due = (
            net_level in ("critical", "high")
            and now - prev_email_at >= 1800
        )

        # Telegram alert (any change, or recovery)
        tg_msg = None
        if cache_changed:
            if cache_level == "down":
                tg_msg = "[Hotspot Network Monitor] ไม่พบ cache file - OpenClaw daemon อาจไม่ได้ส่งข้อมูลมาเลย"
            elif cache_level == "stale":
                tg_msg = (
                    f"[Hotspot Network Monitor] Cache เก่า {age} วินาที\n"
                    f"อัปเดตล่าสุด: {fetched_at}\n"
                    f"หน้าเว็บยังแสดงข้อมูลเก่าอยู่ - user อาจสับสนได้"
                )
            elif cache_level == "ok" and prev_cache in ("stale", "down"):
                tg_msg = "[Hotspot Network Monitor] กลับมาปกติแล้ว (cache fresh)"

        if net_changed and net_level != "ok":
            tg_msg = format_telegram(diag, fetched_at)
        if net_changed and net_level == "ok" and prev_net in ("critical", "high"):
            tg_msg = format_telegram(diag, fetched_at)

        # Email alert (only for actual network issues, not cache staleness)
        should_email = False
        if net_changed and net_level in ("critical", "high"):
            should_email = True
        elif re_email_due and net_level in ("critical", "high"):
            should_email = True

        # --- Send ---
        tg_ok = False
        if tg_msg:
            tg_ok = send_telegram(tg_msg)
            print(f"telegram: level={cache_level}/{net_level} sent={tg_ok}")

        email_ok = False
        if should_email:
            subject = f"[FMS Hotspot] {diag['summary']} - {diag['severity'].upper()}"
            body = format_email_body(diag, fetched_at or "unknown")
            email_ok = send_email(smtp_cfg, subject, body)
            print(f"email: sent={email_ok}")

        # --- Save state ---
        state["last_cache_level"] = cache_level
        state["last_net_level"] = net_level
        state["last_check_ts"] = now
        state["last_age"] = age
        state["last_fetched_at"] = fetched_at
        state["last_root_cause"] = diag.get("root_cause")
        if should_email and email_ok:
            state["last_email_at"] = now
        save_state(state)

        summary = f"[{datetime.now(timezone.utc).isoformat()}] cache={cache_level} net={net_level} age={age}s tg={tg_ok} email={email_ok}"
        print(summary)
        return 0


def load_state_with_defaults() -> dict:
    if STATE_FILE.exists():
        try:
            return json.loads(STATE_FILE.read_text())
        except (OSError, json.JSONDecodeError):
            pass
    return {"last_cache_level": "ok", "last_net_level": "ok", "last_email_at": 0}


def save_state(state: dict) -> None:
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(state))
    tmp.replace(STATE_FILE)


if __name__ == "__main__":
    raise SystemExit(main())
