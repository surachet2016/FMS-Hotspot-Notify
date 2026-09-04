#!/usr/bin/env python3
"""
Mikrotik Hotspot Sync Script
ดึงสมาชิก ACTIVE+unsynced จาก hosting DB แล้วสร้าง user ใน Mikrotik อัตโนมัติ

วิธีใช้ (Windows):
  1. double-click ไฟล์นี้
  2. พิมพ์ username และ password ของ MikroTik
  3. Script จะ sync ทุก 60 วินาทีอัตโนมัติ

ต้องติดตั้งก่อน:  pip install routeros_api requests
"""

import time
import logging
import sys
import getpass

# ── Settings ──────────────────────────────────────────────────────────────────
SYNC_API_URL  = "https://fms.pnu.ac.th/hotspot/api/sync.php"
SYNC_KEY      = "do2UHOw04iz4vaTEpoFVKXc0uIb8qJyZTV-H3Ewza6g"   # ← ใส่ key ที่ได้รับจากผู้ดูแลระบบ
MIKROTIK_HOST = "10.12.0.1"
MIKROTIK_USER = "admin"
MIKROTIK_PORT = 8728
POLL_INTERVAL = 60
# ─────────────────────────────────────────────────────────────────────────────

try:
    import routeros_api
    import requests
except ImportError as e:
    print(f"\nERROR: missing package — {e}")
    print("Run:  pip install routeros_api requests\n")
    input("Press Enter to close...")
    sys.exit(1)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger(__name__)


def pause_exit(code=0):
    input("\nPress Enter to close...")
    sys.exit(code)


def build_password(dob: str) -> str:
    parts = dob.split("-")
    if len(parts) == 3:
        return parts[2] + parts[1] + parts[0]
    return dob


def get_pending_members():
    try:
        res = requests.get(
            SYNC_API_URL,
            params={"action": "pending", "key": SYNC_KEY},
            timeout=10,
            verify=True,
        )
        res.raise_for_status()
        return res.json()
    except Exception as e:
        log.error(f"Failed to fetch pending members: {e}")
        return []


def mark_synced(member_id):
    try:
        res = requests.post(
            SYNC_API_URL,
            params={"action": "activate", "key": SYNC_KEY},
            json={"id": member_id},
            timeout=10,
            verify=True,
        )
        res.raise_for_status()
        return True
    except Exception as e:
        log.error(f"Failed to mark member {member_id} synced: {e}")
        return False


def create_mikrotik_user(api, citizen_id, password, profile, full_name):
    try:
        resource = api.get_resource("/ip/hotspot/user")
        if resource.get(name=citizen_id):
            log.info(f"User '{citizen_id}' already exists — skipping")
            return True
        resource.add(
            name=citizen_id,
            password=password,
            profile=profile,
            comment=full_name[:200],
        )
        log.info(f"Created: {citizen_id} (profile={profile})")
        return True
    except Exception as e:
        log.error(f"Failed to create '{citizen_id}': {e}")
        return False


def sync(mikrotik_user, mikrotik_pass):
    members = get_pending_members()
    if not members:
        log.info("No pending members.")
        return

    log.info(f"Found {len(members)} member(s) — connecting to MikroTik...")

    try:
        pool = routeros_api.RouterOsApiPool(
            MIKROTIK_HOST,
            username=mikrotik_user,
            password=mikrotik_pass,
            port=MIKROTIK_PORT,
            plaintext_login=True,
        )
        api = pool.get_api()
    except Exception as e:
        log.error(f"Cannot connect to MikroTik: {e}")
        return

    try:
        for m in members:
            citizen_id = m["citizen_id"]
            password   = build_password(m["dob"])
            profile    = m.get("profile", "default")
            full_name  = m.get("full_name", "")
            if create_mikrotik_user(api, citizen_id, password, profile, full_name):
                if mark_synced(m["id"]):
                    log.info(f"Synced: {citizen_id} ({full_name})")
                else:
                    log.warning(f"MikroTik OK but DB update failed: {citizen_id}")
    finally:
        pool.disconnect()


if __name__ == "__main__":
    if not SYNC_KEY:
        print("\nERROR: SYNC_KEY ยังไม่ได้กำหนด")
        print("เปิดไฟล์นี้แล้วใส่ SYNC_KEY ที่ได้รับจากผู้ดูแลระบบ\n")
        pause_exit(1)

    user_input = input(f"MikroTik username [{MIKROTIK_USER}]: ").strip()
    mikrotik_user = user_input if user_input else MIKROTIK_USER
    mikrotik_pass = getpass.getpass(f"MikroTik password for '{mikrotik_user}': ")

    log.info(f"Sync started | MikroTik: {MIKROTIK_HOST} | polling every {POLL_INTERVAL}s")
    try:
        while True:
            sync(mikrotik_user, mikrotik_pass)
            time.sleep(POLL_INTERVAL)
    except KeyboardInterrupt:
        log.info("Stopped.")
        pause_exit(0)
