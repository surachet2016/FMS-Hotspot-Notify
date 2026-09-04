#!/usr/bin/env python3
"""Watch new hotspot registrations and send notification emails via Gmail SMTP.

Sends two emails per successful signup:
1. To admin addresses (status report + citizen id + profile)
2. To the member's own email (welcome + hotspot login instructions)
"""

from __future__ import annotations

import fcntl
import json
import os
import smtplib
import subprocess
import sys
from email.message import EmailMessage
from pathlib import Path

DB_HOST = "localhost"
DB_NAME = "mikrotik_hotspot"
DB_USER = "hotspot_user"
DB_PASS = "REPLACE_WITH_DB_PASSWORD"

ADMIN_EMAILS = [
    "surachetsungkhapan@gmail.com",
    "khongpitak@gmail.com",
]
MAIL_FROM_NAME = "FMS Hotspot System"

SMTP_ENV_FILE = Path(os.environ.get("GMAIL_SMTP_ENV_FILE", "/opt/hermes/state/gmail_smtp.env"))
STATE_FILE = Path("/opt/hermes/state/hotspot_new_signups.json")
LOCK_FILE = Path("/opt/hermes/state/hotspot_new_signups.lock")

WELCOME_SUBJECT = "ยินดีต้อนรับสู่ FMS Hotspot — ลงทะเบียนสำเร็จแล้ว"
WELCOME_BODY = """เรียน {full_name},

ระบบได้รับการลงทะเบียน Hotspot ของท่านเรียบร้อยแล้ว ท่านสามารถเชื่อมต่อ WiFi Hotspot ได้ทันที

ข้อมูลสำหรับเข้าสู่ระบบ:
  • ชื่อผู้ใช้ (Username): {username}
  • รหัสผ่าน (Password): {password}

หมายเหตุ: เจ้าหน้าที่จะตรวจสอบภาพบัตรประจำตัวประชาชนที่ท่านแนบมาด้วยตนเอง
หากพบว่ารูปภาพไม่ถูกต้อง ระบบจะแจ้งกลับและลบบัญชีของท่านออกจาก MikroTik

หากมีปัญหาการใช้งาน กรุณาติดต่อผู้ดูแลระบบ
อีเมล: surachetsungkhapan@gmail.com

--
{from_name}
"""

ADMIN_SUBJECT = "[FMS Hotspot] สมาชิกใหม่ลงทะเบียนสำเร็จ — {full_name}"
ADMIN_BODY = """แจ้งเตือน: มีสมาชิกใหม่ลงทะเบียน Hotspot สำเร็จ

ข้อมูลสมาชิก:
  • ชื่อ-สกุล: {full_name}
  • อีเมล: {email}
  • รหัสบัตรประชาชน: {citizen_id}
  • Username: {username}
  • Profile: {profile}
  • สมัครเมื่อ: {created_at}

สถานะ: ACTIVE และ sync ไป MikroTik แล้ว

กรุณาเข้าสู่ระบบผู้ดูแลเพื่อตรวจสอบภาพบัตรประจำตัว:
https://fms.pnu.ac.th/hotspot/admin/

--
{from_name}
"""


def load_smtp_config() -> dict:
    cfg = {}
    for line in SMTP_ENV_FILE.read_text().splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            cfg[k.strip()] = v.strip()
    return cfg


def send_mail(smtp_cfg: dict, to: str, subject: str, body: str) -> bool:
    msg = EmailMessage()
    msg["From"] = f"{MAIL_FROM_NAME} <{smtp_cfg['GMAIL_SMTP_USER']}>"
    msg["To"] = to
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
        print(f"mail error to {to}: {exc}", file=sys.stderr)
        return False


def load_state() -> set:
    if not STATE_FILE.exists():
        return set()
    try:
        return set(json.loads(STATE_FILE.read_text()))
    except Exception:
        return set()


def save_state(state: set) -> None:
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(sorted(state)))
    tmp.replace(STATE_FILE)


def fetch_new_signups(seen: set) -> list:
    query = (
        f"SELECT id, full_name, email, citizen_id, username, password_hash, profile, created_at "
        f"FROM {DB_NAME}.members "
        f"WHERE status='ACTIVE' AND mikrotik_synced=1 AND email IS NOT NULL "
        f"ORDER BY created_at DESC LIMIT 50"
    )
    try:
        result = subprocess.run(
            ["mysql", "-u", DB_USER, f"-p{DB_PASS}", "-N", "-B", "-e", query],
            capture_output=True, text=True, timeout=15,
        )
    except Exception as exc:
        print(f"db error: {exc}", file=sys.stderr)
        return []

    rows = []
    for line in result.stdout.strip().splitlines():
        if not line:
            continue
        parts = line.split("\t")
        if len(parts) < 8:
            continue
        mid = parts[0]
        if mid in seen:
            continue
        rows.append({
            "id": mid,
            "full_name": parts[1],
            "email": parts[2],
            "citizen_id": parts[3],
            "username": parts[4] or parts[3],
            "password_hash": parts[5],
            "profile": parts[6],
            "created_at": parts[7],
        })
    return rows


def main() -> int:
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_FILE.open("w") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0

        if not SMTP_ENV_FILE.exists():
            print(f"missing SMTP config: {SMTP_ENV_FILE}", file=sys.stderr)
            return 1

        try:
            smtp_cfg = load_smtp_config()
        except Exception as exc:
            print(f"smtp config error: {exc}", file=sys.stderr)
            return 1

        seen = load_state()
        new_rows = fetch_new_signups(seen)

        sent = 0
        for row in new_rows:
            username = row["username"]
            body_member = WELCOME_BODY.format(
                full_name=row["full_name"],
                username=username,
                password="(รหัสผ่านที่ท่านตั้งตอนลงทะเบียน)",
                from_name=MAIL_FROM_NAME,
            )
            ok1 = send_mail(smtp_cfg, row["email"], WELCOME_SUBJECT, body_member)

            body_admin = ADMIN_BODY.format(
                full_name=row["full_name"],
                email=row["email"],
                citizen_id=row["citizen_id"],
                username=username,
                profile=row["profile"],
                created_at=row["created_at"],
                from_name=MAIL_FROM_NAME,
            )
            admin_subject = ADMIN_SUBJECT.format(full_name=row["full_name"])
            admin_results = [send_mail(smtp_cfg, addr, admin_subject, body_admin) for addr in ADMIN_EMAILS]

            if ok1 and all(admin_results):
                seen.add(row["id"])
                sent += 1
                print(f"notified: {row['email']} (id={row['id']})")
            else:
                print(f"partial notify: {row['email']} member={ok1} admin={admin_results}", file=sys.stderr)

        if sent:
            save_state(seen)
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
