#!/usr/bin/env bash
#
# build.sh - produce the static relay-sealer binary shipped to the relay.
#
# The publish pipeline is what normally produces these binaries — RelaySealerPublisher
# cross-compiles both architectures into ../bin/relay-sealer-<uname -m>, which is
# where provision_relay.sh looks. This script is the by-hand equivalent, for
# building one architecture outside a publish:
#
#   bash build.sh ../bin/relay-sealer-$(uname -m)
#
# A relay never runs it. CGO is disabled for a fully static binary with no libc
# coupling on the minimal Debian VPS.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-${SCRIPT_DIR}/relay-sealer}"

cd "${SCRIPT_DIR}"
CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${OUT}" .
echo "built: ${OUT}"
