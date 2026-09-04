#!/bin/bash
# Mikrotik Hotspot Sync — Mac Launcher
# ดับเบิลคลิกไฟล์นี้เพื่อรัน sync script

cd "$(dirname "$0")"

echo "=============================="
echo " Mikrotik Hotspot Sync (Mac)"
echo "=============================="
echo ""

echo "Checking dependencies..."
python3 -m pip install routeros-api requests --quiet --break-system-packages 2>/dev/null || \
python3 -m pip install routeros-api requests --quiet
echo "Dependencies OK"
echo ""

read -s -p "Mikrotik password: " MIKROTIK_PASS
echo ""
read -s -p "Sync Key (ขอจาก admin): " SYNC_KEY
echo ""
echo ""

export MIKROTIK_PASS SYNC_KEY

python3 - << 'PYEOF'
import routeros_api, requests, time, logging, os

SYNC_API_URL  = "https://fms.pnu.ac.th/hotspot/api/sync.php"
SYNC_KEY      = os.environ["SYNC_KEY"]

MIKROTIK_HOST = "10.12.0.1"
MIKROTIK_USER = "admin"
MIKROTIK_PASS = os.environ["MIKROTIK_PASS"]
MIKROTIK_PORT = 8728
POLL_INTERVAL = 60

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
log = logging.getLogger(__name__)


def build_password(dob):
    # dob stored as YYYY-MM-DD (BE year already) → DDMMYYYY
    parts = dob.split('-')
    if len(parts) == 3:
        return parts[2] + parts[1] + parts[0]
    return dob


def get_pending_members():
    try:
        r = requests.get(SYNC_API_URL, params={"action": "pending", "key": SYNC_KEY}, timeout=10)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        log.error(f"Failed to fetch pending members: {e}")
        return []


def mark_synced(member_id):
    try:
        r = requests.post(SYNC_API_URL, params={"action": "activate", "key": SYNC_KEY}, json={"id": member_id}, timeout=10)
        r.raise_for_status()
        return True
    except Exception as e:
        log.error(f"Failed to mark synced: {e}")
        return False


def create_mikrotik_user(api, citizen_id, password, profile, full_name):
    try:
        resource = api.get_resource("/ip/hotspot/user")
        if resource.get(name=citizen_id):
            log.info(f"'{citizen_id}' already exists in Mikrotik — skipping")
            return True
        resource.add(name=citizen_id, password=password, profile=profile, comment=full_name[:200])
        log.info(f"Created Mikrotik user: {citizen_id} (profile={profile})")
        return True
    except Exception as e:
        log.error(f"Failed to create Mikrotik user '{citizen_id}': {e}")
        return False


def sync():
    members = get_pending_members()
    if not members:
        log.info("No pending members.")
        return
    log.info(f"Found {len(members)} member(s) to sync — connecting to Mikrotik...")
    try:
        pool = routeros_api.RouterOsApiPool(
            MIKROTIK_HOST, username=MIKROTIK_USER, password=MIKROTIK_PASS,
            port=MIKROTIK_PORT, plaintext_login=True,
        )
        api = pool.get_api()
    except Exception as e:
        log.error(f"Cannot connect to Mikrotik: {e}")
        return
    try:
        for m in members:
            citizen_id = m["citizen_id"]
            password   = build_password(m["dob"])
            profile    = m.get("profile", "default")
            full_name  = m.get("full_name", "")
            if create_mikrotik_user(api, citizen_id, password, profile, full_name):
                if mark_synced(m["id"]):
                    log.info(f"Synced: {citizen_id} ({full_name}) → ACTIVE")
    finally:
        pool.disconnect()


log.info("Mikrotik Hotspot Sync started")
log.info(f"Polling every {POLL_INTERVAL}s  |  Mikrotik: {MIKROTIK_HOST}")
while True:
    sync()
    time.sleep(POLL_INTERVAL)
PYEOF

echo ""
read -p "Press Enter to close..."
