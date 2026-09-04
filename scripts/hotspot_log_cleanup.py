#!/usr/bin/env python3
"""Archive / purge old hotspot access logs.

Compliance with Thailand Computer Crime Act B.E. 2550 (amended 2560):
- Keep logs >= 90 days (mandatory minimum)
- Allow up to 2 years on special officer order

Strategy:
- Compress daily-rolled logs older than 90 days into gzip archive
- Keep archives for 2 years total (730 days)
- Delete anything older than 730 days
- Send Telegram alert if anything went wrong
"""

from __future__ import annotations

import fcntl
import gzip
import subprocess as _sp_for_gpg

# GPG encryption helper (optional - uses AES-256 if gpg is available)
import json
import shutil
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

ARCHIVE_DIR = Path("/var/log/hotspot-logs")
STATE_FILE = Path("/opt/hermes/state/hotspot_log_cleanup.json")
LOCK_FILE = Path("/opt/hermes/state/hotspot_log_cleanup.lock")

RETENTION_DAYS = 730        # 2 years max per Section 26
ARCHIVE_AFTER_DAYS = 90     # compress after minimum retention


def archive_old_logs():
    """Find DB rows older than ARCHIVE_AFTER_DAYS, export to gzipped JSON per day."""
    cutoff = (datetime.now(timezone.utc) - timedelta(days=ARCHIVE_AFTER_DAYS))
    cutoff_str = cutoff.strftime("%Y-%m-%d")

    query = (
        "SELECT DATE(login_at) AS d, "
        "       JSON_ARRAYAGG(JSON_OBJECT("
        "         'id', id, 'event', event, 'username', username, "
        "         'full_name', full_name, 'citizen_id', citizen_id, "
        "         'src_ip', src_ip, 'mac_address', mac_address, "
        "         'session_id', session_id, 'nas_ip', nas_ip, 'login_at', login_at, "
        "         'logout_at', logout_at, 'duration_s', duration_s, "
        "         'bytes_in', bytes_in, 'bytes_out', bytes_out, "
        "         'destination_count', destination_count, 'user_agent', user_agent, "
        "         'raw_mikrotik', raw_mikrotik, 'received_at', received_at"
        "       )) AS payload, COUNT(*) AS n "
        f"FROM hotspot_access_logs WHERE login_at < '{cutoff_str}' "
        "GROUP BY DATE(login_at) ORDER BY d"
    )
    result = subprocess.run(
        ["mysql", "-u", "hotspot_user",
         "-pupBddJMOTfKa9BZyoO4AKHeRKcSAI", "mikrotik_hotspot",
         "-N", "-B", "-e", query],
        capture_output=True, text=True, timeout=120,
    )
    if result.returncode != 0:
        raise RuntimeError(f"mysql query failed: {result.stderr}")

    ARCHIVE_DIR.mkdir(parents=True, exist_ok=True)
    archived_days = 0
    archived_rows = 0
    deleted_rows = 0

    for line in result.stdout.strip().splitlines():
        if not line:
            continue
        parts = line.split("\t", 2)
        if len(parts) < 3:
            continue
        date_str, n_str, rows_json = parts
        n = int(n_str)
        archive_file = ARCHIVE_DIR / f"hotspot-{date_str}.json.gz"
        archive_file_gpg = ARCHIVE_DIR / f"hotspot-{date_str}.json.gz.gpg"
        if not archive_file_gpg.exists() and not archive_file.exists():
            # Write gzipped json first
            with gzip.open(archive_file, "wt", encoding="utf-8") as f:
                f.write(rows_json)
            # Try to encrypt with GPG (AES-256) if available
            try:
                r = _sp_for_gpg.run(
                    ["gpg", "--batch", "--yes", "--symmetric",
                     "--cipher-algo", "AES256",
                     "--compress-algo", "none",
                     "--passphrase-file", "/etc/hermes/gpg-archive-passphrase",
                     "-o", str(archive_file_gpg),
                     str(archive_file)],
                    check=False,
                    capture_output=True, timeout=60,
                )
                if r.returncode == 0:
                    archive_file.unlink()  # remove plaintext
                else:
                    # Keep plaintext if GPG fails (better than losing data)
                    print(f"GPG encrypt failed for {date_str}, keeping plaintext: {r.stderr.decode()[:200]}", file=__import__('sys').stderr)
            except FileNotFoundError:
                pass  # gpg not installed, keep plaintext
            except Exception as e:
                print(f"GPG encrypt error: {e}", file=__import__('sys').stderr)
            archived_days += 1
            archived_rows += n

        # Delete from DB after successful archive
        delete_query = f"DELETE FROM hotspot_access_logs WHERE DATE(login_at) = '{date_str}'"
        r = subprocess.run(
            ["mysql", "-u", "hotspot_user",
             "-pupBddJMOTfKa9BZyoO4AKHeRKcSAI", "mikrotik_hotspot",
             "-e", delete_query],
            capture_output=True, text=True, timeout=60,
        )
        if r.returncode == 0:
            deleted_rows += n
        else:
            print(f"delete failed for {date_str}: {r.stderr}", file=sys.stderr)

    return archived_days, archived_rows, deleted_rows


def purge_archives():
    """Delete archived log files older than RETENTION_DAYS."""
    cutoff = datetime.now(timezone.utc) - timedelta(days=RETENTION_DAYS)
    purged = 0
    freed_bytes = 0
    for f in ARCHIVE_DIR.glob("hotspot-*.json.gz"):
        try:
            date_part = f.stem.split("-", 1)[1].replace(".json", "")
            file_date = datetime.strptime(date_part, "%Y-%m-%d").replace(tzinfo=timezone.utc)
        except (ValueError, IndexError):
            continue
        if file_date < cutoff:
            freed_bytes += f.stat().st_size
            f.unlink()
            purged += 1
    return purged, freed_bytes


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


def main() -> int:
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_FILE.open("w") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0

        try:
            archived_days, archived_rows, deleted_rows = archive_old_logs()
            purged, freed_bytes = purge_archives()
        except Exception as exc:
            print(f"cleanup error: {exc}", file=sys.stderr)
            send_telegram(f"[Hotspot Log Cleanup] ERROR: {exc}")
            return 1

        total_remaining = len(list(ARCHIVE_DIR.glob("hotspot-*.json.gz")))
        summary = (
            f"[Hotspot Log Cleanup] เสร็จเรียบร้อย\n"
            f"- Archived days: {archived_days}\n"
            f"- Archived rows: {archived_rows}\n"
            f"- Deleted from DB: {deleted_rows}\n"
            f"- Purged old archives (>2y): {purged}\n"
            f"- Archive files remaining: {total_remaining}\n"
            f"- Disk used: {sum(f.stat().st_size for f in ARCHIVE_DIR.glob('hotspot-*.json.gz')) / 1024 / 1024:.2f} MB"
        )
        # Only send telegram on day when there was activity
        if archived_days > 0 or purged > 0:
            send_telegram(summary)

        print(summary)
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
