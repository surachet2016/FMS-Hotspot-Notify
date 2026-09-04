@echo off
title Mikrotik Hotspot Sync (Windows)

echo ================================
echo  Mikrotik Hotspot Sync (Windows)
echo ================================
echo.

:: ติดตั้ง dependencies ถ้ายังไม่มี
echo Checking dependencies...
pip install routeros-api requests --quiet 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] pip not found. Please install Python from https://python.org
    pause
    exit /b 1
)
echo Dependencies OK
echo.

:: เขียน sync.py ไปที่ temp แล้วรัน — self-contained ไม่ต้องมีไฟล์แยก
set TMPPY=%TEMP%\mikrotik_sync.py
(
echo import routeros_api, requests, time, logging
echo SYNC_API_URL  = "https://fms.pnu.ac.th/hotspot/api/sync.php"
echo SYNC_KEY      = "SyncKey!2025@PnuHotspot"
echo MIKROTIK_HOST = "10.11.0.1"
echo MIKROTIK_USER = "admin"
echo MIKROTIK_PASS = ""
echo MIKROTIK_PORT = 8728
echo POLL_INTERVAL = 60
echo logging.basicConfig^(level=logging.INFO, format="%(asctime^)s [%(levelname^)s] %(message^)s", datefmt="%%Y-%%m-%%d %%H:%%M:%%S"^)
echo log = logging.getLogger^(__name__^)
echo def get_pending^(^):
echo     try:
echo         r = requests.get^(SYNC_API_URL, params={"action":"pending","key":SYNC_KEY}, timeout=10^)
echo         r.raise_for_status^(^)
echo         return r.json^(^)
echo     except Exception as e:
echo         log.error^(f"Fetch error: {e}"^)
echo         return []
echo def mark_active^(mid^):
echo     try:
echo         r = requests.post^(SYNC_API_URL, params={"action":"activate","key":SYNC_KEY}, json={"id":mid}, timeout=10^)
echo         r.raise_for_status^(^)
echo         return True
echo     except Exception as e:
echo         log.error^(f"Activate error: {e}"^)
echo         return False
echo def create_user^(conn, username^):
echo     try:
echo         api = conn.get_resource^("/ip/hotspot/user"^)
echo         if api.get^(name=username^): log.info^(f"'{username}' already exists"^); return True
echo         api.add^(name=username, password=username^)
echo         log.info^(f"Created: {username}"^)
echo         return True
echo     except Exception as e:
echo         log.error^(f"Mikrotik error: {e}"^)
echo         return False
echo def sync^(^):
echo     members = get_pending^(^)
echo     if not members: log.info^("No pending members."^); return
echo     log.info^(f"Found {len^(members^)} pending member^(s^) — connecting to Mikrotik..."^)
echo     try:
echo         pool = routeros_api.RouterOsApiPool^(MIKROTIK_HOST, username=MIKROTIK_USER, password=MIKROTIK_PASS, port=MIKROTIK_PORT, plaintext_login=True^)
echo         conn = pool.get_api^(^)
echo     except Exception as e:
echo         log.error^(f"Cannot connect to Mikrotik: {e}"^); return
echo     try:
echo         for m in members:
echo             if create_user^(conn, m["username"^]^):
echo                 if mark_active^(m["id"^]^): log.info^(f"Synced: {m['username'^]} -^> ACTIVE"^)
echo     finally:
echo         pool.disconnect^(^)
echo log.info^("Mikrotik Hotspot Sync started"^)
echo log.info^(f"Polling every {POLL_INTERVAL}s  ^|  Mikrotik: {MIKROTIK_HOST}"^)
echo while True: sync^(^); time.sleep^(POLL_INTERVAL^)
) > "%TMPPY%"

python "%TMPPY%"

echo.
pause
