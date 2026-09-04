# FMS-Hotspot-Notify

Cron-driven email notification for new [FMS Hotspot](https://fms.pnu.ac.th/hotspot/) registrations.
Watches the `mikrotik_hotspot.members` table for users whose status is ACTIVE + synced to MikroTik,
then sends:

1. A welcome email to the registrant with their username/password reminder.
2. A status report to two admin addresses.

## Stack

- Python 3, stdlib only (no pip deps)
- Gmail SMTP relay (port 465 SSL) using an App Password
- MariaDB / MySQL client (`mysql` CLI)
- Cron (every minute)

## Files

- `scripts/hotspot_new_signups.py` — main poller
- `cron/hotspot-signup-notify.cron` — crontab snippet
- `cron/fms-wp-audit.cron` — sibling cron (WordPress audit)
- `.env.example` — template for SMTP credentials

## Setup

1. Create a Gmail App Password at <https://myaccount.google.com/apppasswords>.
2. Copy `.env.example` to `/opt/hermes/state/gmail_smtp.env` (mode 600):
   ```
   GMAIL_SMTP_HOST=smtp.gmail.com
   GMAIL_SMTP_PORT=465
   GMAIL_SMTP_USER=your-email@gmail.com
   GMAIL_SMTP_PASS=xxxx xxxx xxxx xxxx
   ```
3. Edit the DB credentials in `scripts/hotspot_new_signups.py` (DB_HOST / DB_NAME / DB_USER / DB_PASS).
4. Install crontab:
   ```
   sudo cp cron/hotspot-signup-notify.cron /etc/cron.d/
   ```

## State

`/opt/hermes/state/hotspot_new_signups.json` records member IDs that have been notified so each signup is emailed only once.
