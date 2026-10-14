#!/usr/bin/env bash
#
# build.sh - produce the static sandbox-host binary for one architecture.
#
# The publish pipeline (SandboxHostPublisher) is what normally produces the
# shipped binaries, cross-compiling both architectures into
# bin/sandbox-host-<uname -m>, which is where install_sandbox_host.sh looks.
# This is the by-hand equivalent:
#
#   bash build.sh bin/sandbox-host-$(uname -m)
#
# CGO is disabled: no libc coupling, nothing beneath the Go runtime.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-${SCRIPT_DIR}/sandbox-host}"

cd "${SCRIPT_DIR}"
CGO_ENABLED=0 go build -buildvcs=false -trimpath -ldflags="-s -w" -o "${OUT}" .
echo "built: ${OUT}"
