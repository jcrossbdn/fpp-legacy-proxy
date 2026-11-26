#!/bin/bash
# Runs from FPP's Plugin Manager on install, reinstall and update.
# Safe to run any number of times. No services, ports or system files are touched.
. "${FPPDIR:-/opt/fpp}/scripts/common"

PLUGIN_DATA="${MEDIADIR:-/home/fpp/media}/plugindata/fpp-legacy-proxy"
mkdir -p "${PLUGIN_DATA}/locks"
chown -R fpp:fpp "${PLUGIN_DATA}" 2>/dev/null || true

PLUGIN_LOG="${LOGDIR:-${MEDIADIR:-/home/fpp/media}/logs}/plugin-fpp-legacy-proxy.log"
touch "${PLUGIN_LOG}"
chown fpp:fpp "${PLUGIN_LOG}" 2>/dev/null || true
chmod 664 "${PLUGIN_LOG}" 2>/dev/null || true

if ! command -v python3 >/dev/null 2>&1; then
    echo "WARNING: python3 was not found. The Plugin Manager normally installs it from pluginInfo.json;"
    echo "until it is present, device pages are passed through without the modern view."
fi
exit 0
