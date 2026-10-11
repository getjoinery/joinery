#!/usr/bin/env bash
#
# build.sh - produce the static mail-gateway binaries shipped with the
# mail_apps plugin.
#
#   bash build.sh                 # both architectures, into
#                                 # ../bin/mail-gateway-x86_64 and ../bin/mail-gateway-aarch64
#   bash build.sh OUT [ARCH]      # one binary; ARCH is x86_64 or aarch64
#                                 # (default: this machine's, uname -m)
#
# The host installer picks ../bin/mail-gateway-$(uname -m). CGO is disabled for
# a fully static binary with no libc coupling. A host never runs this script.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

build() {
	local out="$1" arch="$2" goarch
	case "${arch}" in
		x86_64|amd64)  goarch=amd64 ;;
		aarch64|arm64) goarch=arm64 ;;
		*) echo "build.sh: unknown architecture ${arch}" >&2; exit 1 ;;
	esac
	CGO_ENABLED=0 GOOS=linux GOARCH="${goarch}" go build -trimpath -ldflags="-s -w" -o "${out}" .
	echo "built: ${out} (${arch})"
}

if [ "$#" -ge 1 ]; then
	build "$1" "${2:-$(uname -m)}"
else
	mkdir -p "${SCRIPT_DIR}/../bin"
	build "${SCRIPT_DIR}/../bin/mail-gateway-x86_64" x86_64
	build "${SCRIPT_DIR}/../bin/mail-gateway-aarch64" aarch64
fi
