#!/bin/bash
# Build the Linux artifacts: a static tarball, a .deb and an .rpm. Runs on
# x86_64 Linux, needs no root.
#
#   bash sync/packaging/linux/build.sh       -> sync/target/packaging/linux/
#
# One build serves all three. The binaries are linked statically against musl,
# so the same two files run on any x86_64 distribution of the last decade, and
# the packages carry no library dependencies: a .deb built on this year's
# Ubuntu would otherwise refuse to start on last year's Debian over a glibc
# symbol version. Nothing is lost by it — the tray speaks D-Bus in pure Rust,
# and the credential store's Linux default is a file, not libsecret (see
# docs/drive_sync.md § Credentials).
#
# The .deb and the .rpm come from one package description through nfpm, which
# writes both formats itself, so neither dpkg-deb's nor rpmbuild's packaging
# rules have to be kept in step with the other by hand. A pinned nfpm release
# is fetched and checksum-verified on first use unless NFPM names one already.
#
# Signing: the packages are unsigned. Distribution through an apt or dnf
# repository signs the repository metadata, which is where Linux expects the
# signature to live; a detached signature for the tarball can be added the same
# way when the update feed exists.
#
# Also the update payload for tarball installs (`joinery-drive-release pack` of
# the two binaries), which the client's updater applies; a .deb or .rpm install
# is updated by its package manager. The feed manifest and its signature are
# the publisher's step (docs/drive_sync.md § Updates).
#
# Optional: OUT_DIR, CARGO_TARGET_DIR, CARGO_BUILD_JOBS, NFPM.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JD_SYNC_DIR="$(cd "$HERE/../.." && pwd)"
# Resolved, not "$HERE/../icons": nfpm refuses source paths that climb with "..".
ICONS="$(cd "$HERE/../icons" && pwd)"
export PATH="$HOME/.cargo/bin:$PATH"
# shellcheck source=../version.sh
source "$HERE/../version.sh"

TARGET=x86_64-unknown-linux-musl
DEB_ARCH=amd64
TARGET_DIR="${CARGO_TARGET_DIR:-$JD_SYNC_DIR/target}"
OUT="${OUT_DIR:-$TARGET_DIR/packaging/linux}"
STAGE="$OUT/stage"
BIN="$TARGET_DIR/$TARGET/release"

# Every file in every artifact carries one time, the last commit's, so the
# archives depend on their contents alone and still show a real date. Set
# SOURCE_DATE_EPOCH to choose it; outside a git checkout it is the epoch.
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$JD_SYNC_DIR" log -1 --format=%ct 2>/dev/null || true)}"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-0}"
export SOURCE_DATE_EPOCH
STAMP="$(date -u -d "@$SOURCE_DATE_EPOCH" +%Y-%m-%dT%H:%M:%SZ)"

NFPM_VERSION=2.47.0
NFPM_SHA256=0660ca602b2d2d2ae4781a06c692b3eeb9d437ffea05b831d76e41f4a3188783

echo "=== Joinery Drive $JD_VERSION for Linux (x86_64, static) ==="

if ! rustup target list --installed | grep -qx "$TARGET"; then
	echo "error: the $TARGET standard library is not installed: rustup target add $TARGET" >&2
	exit 1
fi

# ring and the bundled SQLite are C, and C for musl has to be compiled against
# musl's headers rather than the host's glibc ones. musl-gcc (Debian/Ubuntu:
# musl-tools) is the host compiler pointed at them.
if [ -z "${CC_x86_64_unknown_linux_musl:-}" ]; then
	for cc in x86_64-linux-musl-gcc musl-gcc; do
		if command -v "$cc" >/dev/null 2>&1; then
			export CC_x86_64_unknown_linux_musl="$cc"
			break
		fi
	done
fi
if [ -z "${CC_x86_64_unknown_linux_musl:-}" ]; then
	echo "error: no C compiler for musl: install musl-tools (musl-gcc), or set CC_x86_64_unknown_linux_musl" >&2
	exit 1
fi

echo "--- cargo build $TARGET ---"
cargo build --release --locked --manifest-path "$JD_SYNC_DIR/Cargo.toml" \
	--target-dir "$TARGET_DIR" --target "$TARGET" -p jd-daemon -p jd-shell
# The unit the packages install is printed by the same function that writes the
# per-user copy for `joinery-drive autostart on`, so the two cannot differ.
cargo build --release --locked --manifest-path "$JD_SYNC_DIR/Cargo.toml" \
	--target-dir "$TARGET_DIR" --target "$TARGET" -p jd-platform --example systemd_unit

for bin in joinery-drive joinery-drive-tray; do
	# Static means static: a dynamic section here would bring back exactly the
	# glibc dependency this build exists to avoid.
	if readelf -d "$BIN/$bin" 2>/dev/null | grep -q NEEDED; then
		echo "error: $bin links a shared library; the musl build is supposed to be static" >&2
		exit 1
	fi
	jd_check_binary_version "$BIN/$bin" "$bin"
done

rm -rf "$STAGE"
mkdir -p "$STAGE"
"$BIN/examples/systemd_unit" /usr/bin/joinery-drive >"$STAGE/joinery-drive.service"

# ---------------------------------------------------------------------------
# The tarball: for a machine where nobody installs packages, or where there is
# no root. The binaries go anywhere on PATH; `joinery-drive autostart on` then
# writes a unit naming wherever that is.
# ---------------------------------------------------------------------------
TARNAME="joinery-drive-$JD_VERSION-linux-x86_64"
TARDIR="$STAGE/$TARNAME"
mkdir -p "$TARDIR/bin" "$TARDIR/share/applications" "$TARDIR/share/icons/hicolor/scalable/apps"
install -m 0755 "$BIN/joinery-drive" "$BIN/joinery-drive-tray" "$TARDIR/bin/"
install -m 0644 "$HERE/joinery-drive.desktop" "$TARDIR/share/applications/"
install -m 0644 "$ICONS"/*.svg "$TARDIR/share/icons/hicolor/scalable/apps/"
cat >"$TARDIR/INSTALL.txt" <<EOF
Joinery Drive $JD_VERSION for Linux (x86_64)

Both programs are static and need nothing else installed.

  1. Put bin/joinery-drive and bin/joinery-drive-tray somewhere on your PATH,
     for example ~/.local/bin.
  2. Link this computer:          joinery-drive login https://your-instance
  3. Start syncing at every login: joinery-drive autostart on
                                   systemctl --user start joinery-drive
     With joinery-drive-tray beside it, this also starts the status icon at
     each desktop login, for you only.
  4. Optional menu entry and icon theme: copy share/ into ~/.local/share/.

joinery-drive --version prints the installed version.
EOF
# Owner, modes and times fixed, so the archive's bytes depend on its contents.
tar --sort=name --owner=0 --group=0 --numeric-owner --mode=u+rwX,go+rX,go-w --mtime="@$SOURCE_DATE_EPOCH" \
	-C "$STAGE" -czf "$OUT/$TARNAME.tar.gz" "$TARNAME"

echo "--- update payload ---"
"$BIN/joinery-drive-release" pack --out "$OUT/joinery-drive-$JD_VERSION-linux-x86_64.update.tar.gz" \
	"$BIN/joinery-drive" "$BIN/joinery-drive-tray"

# ---------------------------------------------------------------------------
# .deb and .rpm
# ---------------------------------------------------------------------------
nfpm_bin() {
	if [ -n "${NFPM:-}" ]; then
		echo "$NFPM"
		return
	fi
	if command -v nfpm >/dev/null 2>&1 && nfpm --version 2>&1 | grep -q "$NFPM_VERSION"; then
		command -v nfpm
		return
	fi
	local cache="${XDG_CACHE_HOME:-$HOME/.cache}/joinery-drive-packaging/nfpm-$NFPM_VERSION"
	if [ ! -x "$cache/nfpm" ]; then
		local tgz="nfpm_${NFPM_VERSION}_Linux_x86_64.tar.gz"
		mkdir -p "$cache"
		curl -fsSL -o "$cache/$tgz" \
			"https://github.com/goreleaser/nfpm/releases/download/v$NFPM_VERSION/$tgz"
		echo "$NFPM_SHA256  $cache/$tgz" | sha256sum -c --quiet - >&2
		tar -xzf "$cache/$tgz" -C "$cache" nfpm
		rm -f "$cache/$tgz"
	fi
	echo "$cache/nfpm"
}
NFPM_BIN="$(nfpm_bin)"

# Nothing starts at login for anybody until they ask. A user's `joinery-drive
# autostart on` (or "start at login" in the setup) enables the packaged unit
# for that user and writes their own ~/.config/autostart entry for the tray.
# There is no /etc/xdg/autostart entry: that would put a status icon in front
# of every account on the machine, including the ones that never use Drive. The
# packaged unit also serves `systemctl --user enable --now joinery-drive`.
cat >"$STAGE/nfpm.yaml" <<EOF
name: joinery-drive
arch: $DEB_ARCH
platform: linux
version: $JD_VERSION
version_schema: semver
mtime: $STAMP
release: 1
section: net
priority: optional
maintainer: Joinery <support@getjoinery.com>
vendor: Joinery
homepage: https://getjoinery.com
license: Proprietary
description: |-
  Two-way sync between a folder on this computer and Joinery Drive.
  Includes joinery-drive, the sync daemon and command line, and
  joinery-drive-tray, the status icon.
contents:
  - src: $BIN/joinery-drive
    dst: /usr/bin/joinery-drive
    file_info: {mode: 0755}
  - src: $BIN/joinery-drive-tray
    dst: /usr/bin/joinery-drive-tray
    file_info: {mode: 0755}
  - src: $STAGE/joinery-drive.service
    dst: /usr/lib/systemd/user/joinery-drive.service
    file_info: {mode: 0644}
  - src: $HERE/joinery-drive.desktop
    dst: /usr/share/applications/joinery-drive.desktop
    file_info: {mode: 0644}
EOF
for icon in "$ICONS"/*.svg; do
	cat >>"$STAGE/nfpm.yaml" <<EOF
  - src: $icon
    dst: /usr/share/icons/hicolor/scalable/apps/$(basename "$icon")
    file_info: {mode: 0644}
EOF
done
cat >>"$STAGE/nfpm.yaml" <<EOF
rpm:
  summary: Joinery Drive desktop sync
  group: Applications/Internet
EOF

for format in deb rpm; do
	echo "--- nfpm $format ---"
	"$NFPM_BIN" package --config "$STAGE/nfpm.yaml" --packager "$format" --target "$OUT/"
done

(cd "$OUT" && sha256sum ./*.tar.gz ./*.deb ./*.rpm | sed 's| \./| |' >SHA256SUMS)
echo "built:"
sed 's/^/  /' "$OUT/SHA256SUMS"
