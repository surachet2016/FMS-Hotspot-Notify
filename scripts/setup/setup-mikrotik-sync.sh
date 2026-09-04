#!/usr/bin/env bash
# Setup MikroTik access log sync daemon.
# - Prompts for MikroTik + fms credentials (hidden input)
# - Saves to /etc/hermes/mikrotik.env (mode 600)
# - Installs Python script to /opt/hermes/scripts/
# - Creates + starts systemd service
# - Tests one poll cycle
#
# Run as root on Hermes (the OpenClaw machine).

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: please run as root (sudo $0)" >&2
  exit 1
fi

echo "=== Hermes MikroTik Access Log Sync Setup ==="
echo

# Collect credentials
read -rp "MikroTik host [10.11.0.1]: " MIKROTIK_HOST
MIKROTIK_HOST=${MIKROTIK_HOST:-10.11.0.1}

read -rp "MikroTik port [8728]: " MIKROTIK_PORT
MIKROTIK_PORT=${MIKROTIK_PORT:-8728}

read -rp "MikroTik username [admin]: " MIKROTIK_USER
MIKROTIK_USER=${MIKROTIK_USER:-admin}

read -rsp "MikroTik password: " MIKROTIK_PASS
echo

read -rp "FMS log endpoint [https://fms.pnu.ac.th/hotspot/api/log_session.php]: " FMS_LOG_API
FMS_LOG_API=${FMS_LOG_API:-https://fms.pnu.ac.th/hotspot/api/log_session.php}

read -rsp "FMS sync key (Bearer token): " FMS_SYNC_KEY
echo

read -rp "Poll interval seconds [60]: " POLL_INTERVAL
POLL_INTERVAL=${POLL_INTERVAL:-60}

# Save env file
mkdir -p /etc/hermes
chmod 700 /etc/hermes
cat > /etc/hermes/mikrotik.env <<EOF
MIKROTIK_HOST=$MIKROTIK_HOST
MIKROTIK_PORT=$MIKROTIK_PORT
MIKROTIK_USER=$MIKROTIK_USER
MIKROTIK_PASS=$MIKROTIK_PASS
FMS_LOG_API=$FMS_LOG_API
FMS_SYNC_KEY=$FMS_SYNC_KEY
POLL_INTERVAL=$POLL_INTERVAL
EOF
chmod 600 /etc/hermes/mikrotik.env
chown root:root /etc/hermes/mikrotik.env
unset MIKROTIK_PASS FMS_SYNC_KEY
echo "Saved credentials to /etc/hermes/mikrotik.env (mode 600)"
echo

# Install Python script
mkdir -p /opt/hermes/scripts /opt/hermes/state
cp /opt/hermes/scripts/mikrotik_access_sync.py 2>/dev/null || true
# Caller should place mikrotik_access_sync.py at /opt/hermes/scripts/
if [ ! -f /opt/hermes/scripts/mikrotik_access_sync.py ]; then
  echo "ERROR: /opt/hermes/scripts/mikrotik_access_sync.py not found" >&2
  echo "Please copy it first: cp mikrotik_access_sync.py /opt/hermes/scripts/" >&2
  exit 1
fi
chmod 755 /opt/hermes/scripts/mikrotik_access_sync.py
echo "Installed /opt/hermes/scripts/mikrotik_access_sync.py"
echo

# Check routeros_api availability
echo "=== Checking Python deps ==="
if python3 -c "import routeros_api" 2>/dev/null; then
  echo "  routeros_api: available (preferred path)"
  PY_MODULE=ok
else
  echo "  routeros_api: NOT available (will use raw socket fallback)"
  PY_MODULE=missing
fi

# Try installing routeros_api if missing
if [ "$PY_MODULE" = "missing" ]; then
  echo
  read -rp "Install routeros_api via pip? [Y/n]: " INSTALL
  INSTALL=${INSTALL:-Y}
  if [[ "$INSTALL" =~ ^[Yy]?$ ]]; then
    pip install --quiet routeros_api 2>&1 | tail -3 || echo "pip install failed (will use raw socket)"
  fi
fi

# Create systemd service
cat > /etc/systemd/system/mikrotik-access-sync.service <<EOF
[Unit]
Description=Hermes MikroTik Access Log Sync
Documentation=https://github.com/surachet2016/FMS-Hotspot-Notify
After=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /opt/hermes/scripts/mikrotik_access_sync.py
Restart=always
RestartSec=10
StandardOutput=append:/var/log/mikrotik-access-sync.log
StandardError=append:/var/log/mikrotik-access-sync.log

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/hermes/state /var/log/mikrotik-access-sync.log
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
echo "Created systemd service: /etc/systemd/system/mikrotik-access-sync.service"
echo

# Test one poll before enabling
echo "=== Testing one poll cycle ==="
/usr/bin/python3 /opt/hermes/scripts/mikrotik_access_sync.py &
TEST_PID=$!
sleep 8
kill $TEST_PID 2>/dev/null || true
wait $TEST_PID 2>/dev/null || true
echo
echo "Last 30 lines of test log:"
tail -30 /var/log/mikrotik-access-sync.log 2>/dev/null
echo

# Enable + start service
read -rp "Enable + start service now? [Y/n]: " ENABLE
ENABLE=${ENABLE:-Y}
if [[ "$ENABLE" =~ ^[Yy]?$ ]]; then
  systemctl enable mikrotik-access-sync.service
  systemctl start mikrotik-access-sync.service
  sleep 3
  echo
  echo "=== Service status ==="
  systemctl status mikrotik-access-sync.service --no-pager | head -15
fi

echo
echo "=== Setup complete ==="
echo "Logs: tail -f /var/log/mikrotik-access-sync.log"
echo "Service: systemctl status mikrotik-access-sync.service"
echo "Stop: systemctl stop mikrotik-access-sync.service"
