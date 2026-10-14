#!/bin/bash
# @joinery-test
# name: sandbox_host
# tier: safe
# env: any
# needs: []
# timeout: 300
#
# The sandboxed plugin host (maintenance_scripts/install_tools/sandbox_host,
# specs/plugin_tiers.md WP7): its Go tests, and the request and answer core
# and the host exchange.
#
# The host's own tests run a real WebAssembly module (testdata/fixture) in the
# interpreter under its memory cap and deadline, through every door: the API
# with a stand-in site, HTTPS through the guard, the log; the envelope checks;
# the busy refusal; the socket. The contract check catches the drift nothing
# else would: core (includes/SandboxHost.php) and the host (call.go) naming a
# field differently fails every call quietly, as "the plugin did not respond".
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST_SRC="${HERE}/../../../maintenance_scripts/install_tools/sandbox_host"
CORE="${HERE}/../../includes/SandboxHost.php"

pass=0
fail=0
ok()   { echo "  PASS: $1"; pass=$((pass+1)); }
bad()  { echo "  FAIL: $1"; fail=$((fail+1)); }

echo "== Core and the host name the same fields =="
# limits' own keys come from the manifest (SandboxManifest::limits()).
for field in memory_mb timeout_ms; do
    grep -q "\"${field}\"" "${HOST_SRC}/call.go" && grep -q "'${field}'" "${HERE}/../../includes/SandboxManifest.php" \
        || bad "the limit ${field} is not named alike in call.go and SandboxManifest.php"
done
for field in call_id plugin module export input limits settings outbound_hosts api base_url hostname public_key secret_key \
             ok output error timed_out duration_ms calls door target status detail; do
    if grep -q "\"${field}\"" "${HOST_SRC}/call.go" && grep -q "'${field}'" "${CORE}"; then
        :
    else
        bad "the field ${field} is not named alike in call.go and SandboxHost.php"
    fi
done
[ "${fail}" -eq 0 ] && ok "every envelope and answer field is named alike"

echo "== The host builds and its own tests pass =="
if ! command -v go >/dev/null 2>&1; then
    echo "  SKIP: no go toolchain on this host — the host's tests did not run"
else
    if (cd "${HOST_SRC}" && go vet ./... >/dev/null 2>&1); then
        ok "go vet is clean"
    else
        bad "go vet reported problems"
    fi
    if (cd "${HOST_SRC}" && go test ./... >/dev/null 2>&1); then
        ok "the host's Go tests pass (module run, limits, the three doors, the guard, the envelope, the socket)"
    else
        bad "the host's Go tests failed"
        (cd "${HOST_SRC}" && go test ./... 2>&1 | tail -20)
    fi
fi

echo "== What ships =="
for arch in x86_64 aarch64; do
    bin="${HOST_SRC}/bin/sandbox-host-${arch}"
    if [ -f "${bin}" ] && head -c 4 "${bin}" | grep -q 'ELF'; then
        ok "a prebuilt host for ${arch}"
    else
        bad "no prebuilt host for ${arch} at ${bin}"
    fi
done
if bash -n "${HERE}/../../../maintenance_scripts/install_tools/install_sandbox_host.sh"; then
    ok "install_sandbox_host.sh parses"
else
    bad "install_sandbox_host.sh does not parse"
fi
if grep -q 'install_sandbox_host.sh' "${HERE}/../../../maintenance_scripts/install_tools/_plugin_installers_start.sh"; then
    ok "and is a core host installer"
else
    bad "install_sandbox_host.sh is not in CORE_INSTALLERS"
fi

echo
echo "================================"
echo "sandbox_host [safe]"
echo "PASSED: ${pass}   FAILED: ${fail}   ($((pass+fail)) checks)"
[ "${fail}" -eq 0 ] || exit 1
exit 0
