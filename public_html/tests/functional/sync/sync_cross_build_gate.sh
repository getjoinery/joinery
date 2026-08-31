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
# Two things this does NOT claim. It does not run anything (there is no Windows
# machine here), and it does not cover the crates that need a C cross-compiler —
# `jd-core` bundles SQLite and `jd-proto` builds `ring`, so both need a target
# toolchain this box does not have. Every piece of genuinely per-OS code is
# deliberately in a crate that does not: jd-vfs (filesystem behavior), jd-platform
# (keychain, directories, autostart, browser, control channel), and jd-shell (the
# tray). Behavior is covered by the simulator's per-platform scenarios, which run
# the real engine over macOS and Windows filesystem personalities on Linux.
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

# The crates carrying per-OS code, and no others.
CRATES="-p jd-vfs -p jd-platform -p jd-shell -p jd-crypto"

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
	if nice -n 19 cargo check $CRATES --all-targets --target "$target" \
		--manifest-path "$SYNC_DIR/Cargo.toml" --quiet; then
		echo "ok"
	else
		echo "FAIL: the per-OS code no longer compiles for $target"
		status=1
	fi
done

if [ "$status" -ne 0 ]; then
	exit "$status"
fi
echo "sync cross-build gate: per-OS code compiles for Windows and macOS"
