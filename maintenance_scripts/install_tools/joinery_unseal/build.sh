#!/usr/bin/env bash
#
# build.sh - produce the static joinery-unseal daemon for one architecture.
#
# The publish pipeline (UnsealDaemonPublisher) is what normally produces the
# shipped binaries, cross-compiling both architectures into
# bin/joinery-unseal-<uname -m>. This is the by-hand equivalent:
#
#   bash build.sh bin/joinery-unseal-$(uname -m)
#
# CGO is disabled: no libc coupling, nothing beneath the Go runtime.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-${SCRIPT_DIR}/joinery-unseal}"

cd "${SCRIPT_DIR}"
CGO_ENABLED=0 go build -buildvcs=false -trimpath -ldflags="-s -w" -o "${OUT}" .
echo "built: ${OUT}"
