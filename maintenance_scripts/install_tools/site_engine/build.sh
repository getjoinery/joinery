#!/usr/bin/env bash
#
# build.sh - build the site container engine from the pinned sources in
# versions.env (specs/site_container_engine.md D1).
#
# Version: 1.0
#
# Runs inside Ubuntu 26.04, the hosts' release, as root: the toolchain and the
# libraries the engine links against come from Ubuntu's archive, so the build
# matches what a host has. On dev the publish pipeline runs it in a resolute
# build root; by hand, on any 26.04 machine:
#
#     sudo bash build.sh OUT_DIR
#
# Leaves in OUT_DIR, and nothing else:
#   joinery-site-engine-<podman version>-<arch>.tar.xz
#       joinery-site-engine-<version>-<arch>/
#           bin/podman
#           helpers/netavark, helpers/aardvark-dns
#           systemd/podman-restart.service
#           BUILDINFO          versions, commits, build tags, toolchain
#   joinery-site-engine-<podman version>-<arch>.tar.xz.sha256
#
# Refuses (exit 1, OUT_DIR left as it was) when a checkout is not its pinned
# commit, a part does not build, or the built Podman does not carry the build
# tags versions.env asks for. Without apparmor it refuses every
# AppArmor-confined container; without seccomp it would run them unfiltered.
# The tags and the commit are read back from the binary itself (go version -m),
# not taken on trust from the command line that built it.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-}"

die() { echo "site engine build: $*" >&2; exit 1; }

[[ -n "$OUT" ]] || die "usage: build.sh OUT_DIR"
[[ "$(id -u)" -eq 0 ]] || die "run as root (it installs the toolchain from Ubuntu's archive)"
. /etc/os-release
[[ "${VERSION_CODENAME:-}" == "resolute" ]] || die "builds on Ubuntu 26.04 only, the hosts' release (this is ${PRETTY_NAME:-unknown})"
# shellcheck source=versions.env
. "$HERE/versions.env"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends \
    ca-certificates git make gcc pkg-config golang-go cargo rustc protobuf-compiler \
    libgpgme-dev libseccomp-dev libsystemd-dev libapparmor-dev > /dev/null

# On disk, not /tmp: 26.04 mounts /tmp in memory (half the RAM), and the Go and
# Rust builds want several GB.
WORK="$(mktemp -d /var/tmp/site-engine-build.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT
export CARGO_HOME="$WORK/cargo" GOCACHE="$WORK/gocache" GOPATH="$WORK/gopath"
# The official toolchain versions.env pins, fetched and verified by Go itself;
# the checksum database is named, not inherited.
export GOTOOLCHAIN="$GO_TOOLCHAIN" GOPROXY="https://proxy.golang.org" GOSUMDB="sum.golang.org" \
    GONOSUMDB="" GOPRIVATE="" GONOPROXY="" GOINSECURE="" GOFLAGS="-modcacherw"
[[ "$(go env GOVERSION)" == "$GO_TOOLCHAIN" ]] || die "could not fetch the official ${GO_TOOLCHAIN} (go reports $(go env GOVERSION 2>&1))"

fetch() {  # NAME REPO TAG COMMIT
    git -c advice.detachedHead=false clone -q --depth 1 --branch "$3" "$2" "$WORK/$1"
    local head
    head="$(git -C "$WORK/$1" rev-parse HEAD)"
    [[ "$head" == "$4" ]] || die "$1 $3 is commit $head, not the pinned $4"
}
fetch podman "$PODMAN_REPO" "$PODMAN_TAG" "$PODMAN_COMMIT"
fetch netavark "$NETAVARK_REPO" "$NETAVARK_TAG" "$NETAVARK_COMMIT"
fetch aardvark-dns "$AARDVARK_REPO" "$AARDVARK_TAG" "$AARDVARK_COMMIT"

echo "site engine build: podman $PODMAN_TAG"
make -C "$WORK/podman" BUILDTAGS="$PODMAN_BUILDTAGS" bin/podman > "$WORK/podman.log" 2>&1 \
    || { tail -n 30 "$WORK/podman.log" >&2; die "podman did not build"; }
echo "site engine build: netavark $NETAVARK_TAG"
(cd "$WORK/netavark" && cargo build -q --release --locked) > "$WORK/netavark.log" 2>&1 \
    || { tail -n 30 "$WORK/netavark.log" >&2; die "netavark did not build"; }
echo "site engine build: aardvark-dns $AARDVARK_TAG"
(cd "$WORK/aardvark-dns" && cargo build -q --release --locked) > "$WORK/aardvark.log" 2>&1 \
    || { tail -n 30 "$WORK/aardvark.log" >&2; die "aardvark-dns did not build"; }

PODMAN_BIN="$WORK/podman/bin/podman"
built_info="$(go version -m "$PODMAN_BIN")"
built_tags="$(awk '$1 == "build" && $2 ~ /^-tags=/ {sub(/^-tags=/, "", $2); print $2}' <<< "$built_info")"
built_rev="$(awk '$1 == "build" && $2 ~ /^vcs.revision=/ {sub(/^vcs.revision=/, "", $2); print $2}' <<< "$built_info")"
for tag in $PODMAN_BUILDTAGS; do
    [[ ",${built_tags}," == *",${tag},"* ]] || die "the built podman does not carry the build tag '${tag}' (it carries: ${built_tags:-none})"
done
[[ "$built_rev" == "$PODMAN_COMMIT" ]] || die "the built podman says it is commit ${built_rev:-unknown}, not ${PODMAN_COMMIT}"
built_go="$(awk -F': ' 'NR == 1 {print $2}' <<< "$built_info")"
[[ "$built_go" == "$GO_TOOLCHAIN" ]] || die "the built podman was compiled by ${built_go:-unknown}, not ${GO_TOOLCHAIN}"

VERSION="$("$PODMAN_BIN" --version | awk '{print $3}')"
[[ "v${VERSION}" == "$PODMAN_TAG" ]] || die "the built podman reports ${VERSION}, not ${PODMAN_TAG#v}"
ARCH="$(dpkg --print-architecture)"
NAME="joinery-site-engine-${VERSION}-${ARCH}"
STAGE="$WORK/stage/$NAME"
mkdir -p "$STAGE/bin" "$STAGE/helpers" "$STAGE/systemd"
install -m 755 "$PODMAN_BIN" "$STAGE/bin/podman"
install -m 755 "$WORK/netavark/target/release/netavark" "$STAGE/helpers/netavark"
install -m 755 "$WORK/aardvark-dns/target/release/aardvark-dns" "$STAGE/helpers/aardvark-dns"
strip "$STAGE/bin/podman" "$STAGE/helpers/netavark" "$STAGE/helpers/aardvark-dns"
# The unit as upstream ships it, pointed at where the host installer puts podman.
sed 's;@@PODMAN@@;/usr/local/bin/podman;g' "$WORK/podman/contrib/systemd/system/podman-restart.service.in" \
    > "$STAGE/systemd/podman-restart.service"
{
    echo "engine=${NAME}"
    echo "podman=${PODMAN_TAG} ${PODMAN_COMMIT}"
    echo "netavark=${NETAVARK_TAG} ${NETAVARK_COMMIT}"
    echo "aardvark-dns=${AARDVARK_TAG} ${AARDVARK_COMMIT}"
    echo "podman_build_tags=${built_tags}"
    echo "go=$(go env GOVERSION)"
    echo "rustc=$(rustc --version | awk '{print $2}')"
    echo "built_on=${PRETTY_NAME}"
} > "$STAGE/BUILDINFO"

# A deterministic archive: names sorted, owner root, one timestamp.
tar -C "$WORK/stage" --sort=name --owner=0 --group=0 --numeric-owner --mtime='@0' -cf - "$NAME" \
    | xz -9 > "$WORK/$NAME.tar.xz"
(cd "$WORK" && sha256sum "$NAME.tar.xz") > "$WORK/$NAME.tar.xz.sha256"
mv -f "$WORK/$NAME.tar.xz" "$WORK/$NAME.tar.xz.sha256" "$OUT/"
echo "site engine build: $OUT/$NAME.tar.xz ($(du -h "$OUT/$NAME.tar.xz" | cut -f1))"
