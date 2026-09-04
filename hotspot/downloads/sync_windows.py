"""
Mikrotik Hotspot Sync - Windows
Run: python sync_windows.py
"""
import sys, logging

try:
    import routeros_api, requests
except ImportError:
    print("Installing dependencies...")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "routeros-api", "requests", "-q"])
    import routeros_api, requests

import time, getpass

SYNC_API_URL  = "https://fms.pnu.ac.th/hotspot/api/sync.php"
SYNC_KEY      = "do2UHOw04iz4vaTEpoFVKXc0uIb8qJyZTV-H3Ewza6g"
MIKROTIK_HOST = "10.12.0.1"
MIKROTIK_USER = "admin"
MIKROTIK_PORT = 8728
POLL_INTERVAL = 60

logging.basicConfig(level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S")
log = logging.getLogger(__name__)


def build_password(dob):
    parts = dob.split("-")
    if len(parts) == 3:
        return parts[2] + parts[1] + parts[0]
    return dob


def get_pending():
    try:
        r = requests.get(SYNC_API_URL,
            params={"action": "pending", "key": SYNC_KEY}, timeout=10)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        log.error(f"Cannot fetch members: {e}")
        return []


def mark_synced(member_id):
    try:
        r = requests.post(SYNC_API_URL,
            params={"action": "activate", "key": SYNC_KEY},
            json={"id": member_id}, timeout=10)
        r.raise_for_status()
        return True
    except Exception as e:
        log.error(f"Cannot update DB: {e}")
        return False


def sync(mikrotik_pass):
    members = get_pending()
    if not members:
        log.info("No pending members.")
        return
    log.info(f"Found {len(members)} member(s) - connecting to MikroTik...")
    try:
        pool = routeros_api.RouterOsApiPool(
            MIKROTIK_HOST, username=MIKROTIK_USER, password=mikrotik_pass,
            port=MIKROTIK_PORT, plaintext_login=True)
        api = pool.get_api()
        log.info("Connected to MikroTik OK")
    except Exception as e:
        log.error(f"Cannot connect to MikroTik: {e}")
        return
    try:
        for m in members:
            cid      = m["citizen_id"]
            password = build_password(m["dob"])
            profile  = m.get("profile", "default")
            name     = m.get("full_name", "")
            try:
                res = api.get_resource("/ip/hotspot/user")
                if res.get(name=cid):
                    log.info(f"'{cid}' already in MikroTik - skipping")
                else:
                    res.add(name=cid, password=password, profile=profile, comment=name[:200])
                    log.info(f"Created: {cid} ({name})")
                if mark_synced(m["id"]):
                    log.info(f"Synced: {cid} -> ACTIVE")
            except Exception as e:
                log.error(f"Error on {cid}: {e}")
    finally:
        pool.disconnect()


if __name__ == "__main__":
    print("=" * 40)
    print(" Mikrotik Hotspot Sync (Windows)")
    print("=" * 40)
    print()
    mikrotik_pass = getpass.getpass("MikroTik password: ")
    print()
    log.info(f"Starting sync | MikroTik: {MIKROTIK_HOST}")
    while True:
        sync(mikrotik_pass)
        log.info(f"Waiting {POLL_INTERVAL}s...")
        time.sleep(POLL_INTERVAL)
