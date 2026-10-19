#!/bin/bash
# @joinery-test
# name: sync_cross_build
# tier: safe
# env: any
# needs: [rust]
# covers: [sync/**]
# timeout: 900
#
# The macOS and Windows halves of the sync client, compiled from here.
#
# Phase 3 of specs/drive_sync_clients.md is per-OS code, and almost none of it
# can be run on this machine: the Windows file-identity call, the registry
# autostart write, the macOS Keychain, the FSEvents backend. What CAN be done
# here is compile it, for those exact targets, on every safe-tier run — and that
# catches the failure that actually happens to per-OS code, which is that
# somebody edits the shared path and the branch nobody builds stops compiling.
# It is found six weeks later by whoever tries to cut a release.
#
# Every crate of the workspace is checked, tests included. Two of them build C:
# `jd-core` bundles SQLite and `jd-proto` builds `ring`, and this box has no C
# compiler for either target. A check never links, so nothing reads the objects
# a build script compiles, and a stand-in compiler that writes empty ones is
# enough: it answers cc-rs's family probe as gcc and makes an empty file for
# every `-o`. The Rust in those crates, and in jd-daemon above them, is then
# checked for each target like the rest, so a broken Windows-only or macOS-only
# branch of the engine or the daemon fails here and not on the next VM build.
#
# What this does NOT claim: it runs nothing (there is no Windows machine here),
# and it says nothing about the C itself. Behavior is covered by the simulator's
# per-platform scenarios, which run the real engine over macOS and Windows
# filesystem personalities on Linux, and by the soak lines on real machines.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYNC_DIR="$(cd "$HERE/../../../.." && pwd)/sync"

if ! command -v cargo >/dev/null 2>&1; then
	if [ -x "$HOME/.cargo/bin/cargo" ]; then
		export PATH="$HOME/.cargo/bin:$PATH"
	elif [ -x /home/user1/.cargo/bin/cargo ]; then
		export PATH="/home/user1/.cargo/bin:$PATH"
	else
		echo "FAIL: cargo unavailable (declare/enforce needs:[rust])"
		exit 1
	fi
fi

# The host's archiver takes the stand-in's empty objects. Missing, it is a setup
# gap, and is said as one rather than as a panic inside a build script.
if ! command -v ar >/dev/null 2>&1; then
	echo "FAIL: ar is not installed (the cross-build gate needs the host archiver)"
	exit 1
fi

# The stand-in C compiler (above), written fresh for each run.
FAKECC_DIR="$(mktemp -d)"
trap 'rm -rf "$FAKECC_DIR"' EXIT
cat > "$FAKECC_DIR/cc" <<'FAKECC'
#!/bin/bash
for a in "$@"; do [ "$a" = "-?" ] && exit 1; done
case " $* " in *detect_compiler_family*) printf '"gcc"\n'; exit 0;; esac
out=""; prev=""
for a in "$@"; do
	[ "$prev" = "-o" ] && out="$a"
	prev="$a"
done
[ -n "$out" ] && : > "$out"
exit 0
FAKECC
chmod +x "$FAKECC_DIR/cc"

status=0
for target in x86_64-pc-windows-gnu aarch64-apple-darwin; do
	# A target whose standard library was never installed is a setup gap, not a
	# code failure, and saying which is the difference between a fix and an
	# afternoon. Install it if rustup is here; report clearly if it is not.
	if ! rustup target list --installed 2>/dev/null | grep -qx "$target"; then
		if command -v rustup >/dev/null 2>&1; then
			echo "installing missing target $target"
			rustup target add "$target" >/dev/null 2>&1 || true
		fi
	fi
	if ! rustup target list --installed 2>/dev/null | grep -qx "$target"; then
		echo "FAIL: the $target standard library is not installed."
		echo "      fix with: rustup target add $target"
		status=1
		continue
	fi

	echo "--- $target ---"
	# cc-rs reads the target's own compiler and archiver: the stand-in, and the
	# host's ar over its empty objects.
	triple="${target//-/_}"
	if env "CC_$triple=$FAKECC_DIR/cc" "AR_$triple=ar" \
		nice -n 19 cargo check --workspace --all-targets --target "$target" \
		--manifest-path "$SYNC_DIR/Cargo.toml" --quiet; then
		echo "ok"
	else
		echo "FAIL: the sync client no longer compiles for $target"
		status=1
	fi
done

if [ "$status" -ne 0 ]; then
	exit "$status"
fi
echo "sync cross-build gate: the whole sync client compiles for Windows and macOS"
