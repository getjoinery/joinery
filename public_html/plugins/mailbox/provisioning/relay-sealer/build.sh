#!/usr/bin/env bash
#
# build.sh - produce the static relay-sealer binary shipped to the relay.
#
# provision_relay.sh calls this on the relay after installing the Go toolchain,
# so the binary is built for the relay's own architecture. It can also be run on
# the control plane to pre-build for scp delivery. CGO is disabled for a fully
# static binary with no libc coupling on the minimal Debian VPS.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-${SCRIPT_DIR}/relay-sealer}"

cd "${SCRIPT_DIR}"
CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${OUT}" .
echo "built: ${OUT}"
