# Mikrotik Hotspot Sync — Windows Launcher
# วิธีรัน: คลิกขวา → "Run with PowerShell"

Write-Host "================================"
Write-Host " Mikrotik Hotspot Sync (Windows)"
Write-Host "================================"
Write-Host ""

$SYNC_KEY      = Read-Host "Sync Key (ขอจาก admin)"
$MIKROTIK_PASS = Read-Host "Mikrotik password" -AsSecureString
$MIKROTIK_PASS = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
    [Runtime.InteropServices.Marshal]::SecureStringToBSTR($MIKROTIK_PASS)
)

if (-not $SYNC_KEY -or -not $MIKROTIK_PASS) {
    Write-Host "ERROR: กรุณาใส่ทั้ง Sync Key และ Mikrotik password" -ForegroundColor Red
    Read-Host "Press Enter to close"
    exit 1
}

# ── หา Python ────────────────────────────────────────────────────────────────
$python = $null
foreach ($cmd in @("python", "python3", "py")) {
    try {
        $ver = & $cmd --version 2>&1
        if ($LASTEXITCODE -eq 0) { $python = $cmd; break }
    } catch {}
}
if (-not $python) {
    Write-Host ""
    Write-Host "ERROR: ไม่พบ Python — กรุณาติดตั้งจาก https://python.org" -ForegroundColor Red
    Write-Host "       ติ๊ก 'Add Python to PATH' ตอนติดตั้ง แล้วปิด/เปิด PowerShell ใหม่"
    Read-Host "Press Enter to close"
    exit 1
}
Write-Host "Found Python: $python"

# ── ติดตั้ง dependencies ──────────────────────────────────────────────────────
Write-Host "Checking dependencies..."
& $python -m pip install routeros-api requests -q
if ($LASTEXITCODE -ne 0) {
    Write-Host "WARNING: pip install มีปัญหา — ลองต่อไปก่อน" -ForegroundColor Yellow
}
Write-Host "Dependencies OK"
Write-Host ""

# ── ทดสอบ network ───────────────────────────────────────────────────────────
Write-Host "Testing network to MikroTik (10.12.0.1)..."
$ping = Test-Connection -ComputerName "10.12.0.1" -Count 1 -Quiet -ErrorAction SilentlyContinue
if (-not $ping) {
    Write-Host "WARNING: ping 10.12.0.1 ไม่ได้ — เครื่องนี้อาจไม่ได้อยู่ใน network เดียวกับ MikroTik" -ForegroundColor Yellow
    Write-Host "         ถ้า MikroTik block ICMP ก็ยังรันต่อได้"
}
Write-Host ""

$pythonCode = @"
import routeros_api, requests, time, logging

SYNC_API_URL  = "https://fms.pnu.ac.th/hotspot/api/sync.php"
SYNC_KEY      = "$SYNC_KEY"
MIKROTIK_HOST = "10.12.0.1"
MIKROTIK_USER = "admin"
MIKROTIK_PASS = "$MIKROTIK_PASS"
MIKROTIK_PORT = 8728
POLL_INTERVAL = 60

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
log = logging.getLogger(__name__)


def build_password(dob):
    parts = dob.split('-')
    if len(parts) == 3:
        return parts[2] + parts[1] + parts[0]
    return dob


def get_pending_members():
    try:
        r = requests.get(SYNC_API_URL, params={"action": "pending", "key": SYNC_KEY}, timeout=10)
        r.raise_for_status()
        data = r.json()
        log.info(f"API returned {len(data)} member(s) to sync")
        return data
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
        log.info("No pending members to sync.")
        return
    log.info(f"Connecting to Mikrotik {MIKROTIK_HOST}:{MIKROTIK_PORT}...")
    try:
        pool = routeros_api.RouterOsApiPool(
            MIKROTIK_HOST, username=MIKROTIK_USER, password=MIKROTIK_PASS,
            port=MIKROTIK_PORT, plaintext_login=True,
        )
        api = pool.get_api()
        log.info("Connected to Mikrotik OK")
    except Exception as e:
        log.error(f"Cannot connect to Mikrotik: {e}")
        return
    try:
        for m in members:
            citizen_id = m['citizen_id']
            password   = build_password(m['dob'])
            profile    = m.get('profile', 'default')
            full_name  = m.get('full_name', '')
            if create_mikrotik_user(api, citizen_id, password, profile, full_name):
                if mark_synced(m['id']):
                    log.info(f"Synced: {citizen_id} ({full_name}) -> ACTIVE")
    finally:
        pool.disconnect()


log.info("Mikrotik Hotspot Sync started")
log.info(f"Mikrotik: {MIKROTIK_HOST}  |  API: {SYNC_API_URL}")
while True:
    sync()
    log.info(f"Waiting {POLL_INTERVAL}s before next check...")
    time.sleep(POLL_INTERVAL)
"@

$tmpFile = "$env:TEMP\mikrotik_sync.py"
[System.IO.File]::WriteAllText($tmpFile, $pythonCode, [System.Text.Encoding]::UTF8)
Write-Host "Running sync script... (Ctrl+C to stop)"
Write-Host ""
& $python $tmpFile

Write-Host ""
Read-Host "Press Enter to close"
