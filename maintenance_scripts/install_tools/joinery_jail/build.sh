#!/usr/bin/env bash
#
# build.sh - produce the static joinery-jail launcher for one architecture.
#
# The publish pipeline (ParserJailPublisher) is what normally produces the
# shipped binaries, cross-compiling both architectures into bin/joinery-jail-<uname -m>,
# which is where install_parser_jail.sh looks. This is the by-hand equivalent:
#
#   bash build.sh bin/joinery-jail-$(uname -m)
#
# CGO is disabled: no libc coupling, nothing beneath the Go runtime.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-${SCRIPT_DIR}/joinery-jail}"

cd "${SCRIPT_DIR}"
CGO_ENABLED=0 go build -buildvcs=false -trimpath -ldflags="-s -w" -o "${OUT}" .
echo "built: ${OUT}"
