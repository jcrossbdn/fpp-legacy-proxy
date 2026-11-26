#!/bin/bash
# Runs from FPP's Plugin Manager. Safe to run more than once.
# The plugin changes nothing outside its own folder, plugindata and its log.
. "${FPPDIR:-/opt/fpp}/scripts/common"

PLUGIN_DATA="${MEDIADIR:-/home/fpp/media}/plugindata/fpp-legacy-proxy"

# FPP 10.4+: keep the device list across a Reinstall.
if [ "${FPP_PLUGIN_REINSTALL}" != "1" ]; then
    rm -rf "${PLUGIN_DATA}"
    rm -f "${CFGDIR:-${MEDIADIR:-/home/fpp/media}/config}/plugin.fpp-legacy-proxy"
else
    rm -rf "${PLUGIN_DATA}/locks"
fi
exit 0
