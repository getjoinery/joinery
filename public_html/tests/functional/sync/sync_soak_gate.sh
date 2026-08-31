#!/bin/bash
# @joinery-test
# name: sync_soak
# tier: live
# env: dev-only
# needs: [rust]
# timeout: 1800
#
# The soak rig itself ({repo root}/sync/jd-soak/), proven by running one bounded
# storm-and-settle cycle end to end.
#
# The multi-week campaign is operations, not a test tier — it lives on the soak
# VPS under systemd and is read from its report file. What belongs in the estate
# is the question this gate answers: **does the harness work?** Two real
# `joinery-drive` daemons, real persona actors writing to real disks, real kills
# and partitions, and then the six settle assertions run against the result. A
# verifier with a bug in it does not report a problem; it reports a clean run,
# which is worse than having no verifier at all.
#
# What it needs, and why it fails rather than skips without them: this is a live
# gate, named explicitly, and a live gate that quietly passes because its rig was
# not configured is the same silent-success failure the rig exists to catch.
#
#   JD_SOAK_SERVER    the soak instance (never dev.getjoinery.com — spec S1)
#   JD_SOAK_ACCOUNT   an ordinary account on it, with Drive enabled (its sign-in
#                     identifier, which the platform spells as an email address)
#   JD_SOAK_PASSWORD  its password
#
# Everything is created under a scratch directory and removed afterwards. The
# files it makes on the server are left behind on purpose: they are the soak
# account's, and a campaign that tidied up after itself would destroy the version
# history the no-loss oracle depends on.
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
		echo "FAIL: cargo unavailable — cannot build the soak rig (declare/enforce needs:[rust])"
		exit 1
	fi
fi

missing=""
[ -n "${JD_SOAK_SERVER:-}" ]   || missing="$missing JD_SOAK_SERVER"
[ -n "${JD_SOAK_ACCOUNT:-}" ]    || missing="$missing JD_SOAK_ACCOUNT"
[ -n "${JD_SOAK_PASSWORD:-}" ] || missing="$missing JD_SOAK_PASSWORD"
if [ -n "$missing" ]; then
	echo "FAIL: the soak gate has no rig to run against — set:$missing"
	echo "      (a live gate that passed without its rig would be the exact silent"
	echo "       success this harness exists to catch)"
	exit 1
fi

case "$JD_SOAK_SERVER" in
	*dev.getjoinery.com*)
		# Spec S1. Weeks of synthetic load do not belong on the box people work
		# against, and the rig needs liberty to crank settings and wipe.
		echo "FAIL: JD_SOAK_SERVER points at dev — the soak rig runs against its own instance"
		exit 1
		;;
esac

BASE="$(mktemp -d "${TMPDIR:-/tmp}/jd-soak-gate.XXXXXX")"
DAEMONS=""
cleanup() {
	for pid in $DAEMONS; do
		kill "$pid" 2>/dev/null || true
	done
	# Give each daemon a moment to close its store cleanly before the directory
	# goes; a half-written SQLite file in a scratch dir is harmless, but the
	# warning it prints on the way out reads like a failure.
	sleep 1
	for pid in $DAEMONS; do
		kill -9 "$pid" 2>/dev/null || true
	done
	rm -rf "$BASE"
}
trap cleanup EXIT

echo "building the workspace…"
nice -n 19 cargo build --release --manifest-path "$SYNC_DIR/Cargo.toml" \
	-p jd-daemon -p jd-soak --quiet
DRIVE="$SYNC_DIR/target/release/joinery-drive"
SOAK="$SYNC_DIR/target/release/jd-soak"
[ -x "$DRIVE" ] || { echo "FAIL: joinery-drive did not build"; exit 1; }
[ -x "$SOAK" ]  || { echo "FAIL: jd-soak did not build"; exit 1; }

# ---------------------------------------------------------------------------
# The fleet
# ---------------------------------------------------------------------------
#
# Two devices, both as this user rather than as separate accounts. That costs the
# gate its network faults — an owner-uid rule cannot tell two daemons apart when
# they share a uid, and the fault agent refuses rather than cutting both — so the
# gate runs kills and freezes only. The full matrix needs the per-account setup
# in sync/soak/setup-host.sh, which wants root and a dedicated box.

echo "laying out the fleet under $BASE…"
"$SOAK" init "$BASE/fleet.json" \
	--base "$BASE" --server "$JD_SOAK_SERVER" --devices 2 --poll-seconds 5 >/dev/null

python3 - "$BASE/fleet.json" <<'PY'
import json, sys
path = sys.argv[1]
fleet = json.load(open(path))
# Nothing to reach: no container, no unix account of its own, no unit. The fault
# agent will journal every partition and restart as `refused`, which is honest
# and is what the assertions below check for.
for device in fleet["devices"]:
    device.pop("unix_user", None)
    device.pop("service", None)
fleet["storm_seconds"] = 240
fleet["settle_deadline_seconds"] = 240
json.dump(fleet, open(path, "w"), indent=2)
PY

echo "linking both devices…"
"$SOAK" provision "$BASE/fleet.json"

for letter in a b; do
	JOINERY_DRIVE_HOME="$BASE/device-$letter/home" \
		"$DRIVE" daemon >"$BASE/device-$letter/home/logs/daemon.log" 2>&1 &
	DAEMONS="$DAEMONS $!"
done

# The control file appears once a daemon has bound its channel. Waiting for it
# rather than sleeping a fixed amount: a gate that started storming before the
# daemons were up would be measuring the rig's own startup.
for _ in $(seq 1 60); do
	if [ -f "$BASE/device-a/home/state/control.json" ] \
		&& [ -f "$BASE/device-b/home/state/control.json" ]; then
		break
	fi
	sleep 1
done
for letter in a b; do
	if [ ! -f "$BASE/device-$letter/home/state/control.json" ]; then
		echo "FAIL: device-$letter never opened its control channel"
		tail -20 "$BASE/device-$letter/home/logs/daemon.log" || true
		exit 1
	fi
done
echo "both daemons are up"

# ---------------------------------------------------------------------------
# One cycle
# ---------------------------------------------------------------------------

echo "storm (240s) and settle…"
set +e
"$SOAK" orchestrate "$BASE/fleet.json" \
	--cycles 1 --storm-seconds 240 --settle-seconds 240 \
	--fault-seconds 45 --pace-ms 150 --seed 20260806 --stop-on-violation
ORCHESTRATE=$?
set -e

REPORT="$BASE/journal/report.txt"
[ -f "$REPORT" ] || { echo "FAIL: the campaign wrote no report"; exit 1; }
echo
cat "$REPORT"
echo

fail() { echo "FAIL: $1"; exit 1; }

# The harness did something. A cycle that produced no work would pass every
# assertion by having nothing to check, which is the one way this gate could
# report a clean run over a broken rig.
OPS=$(grep -c '"type":"actor_commit"' "$BASE"/journal/actor-*.jsonl | awk -F: '{s+=$2} END {print s+0}')
[ "$OPS" -gt 200 ] || fail "the storm committed only $OPS operations — the actors were not working"

grep -q '"type":"fault"' "$BASE"/journal/chaos.jsonl \
	|| fail "the chaos agent injected nothing, so nothing was tested against an adversary"
grep -q '"kind":"kill"' "$BASE"/journal/chaos.jsonl \
	|| fail "no daemon was killed — the fault that finds data loss never ran"

# Every assertion reached a verdict. A settle that skipped one silently is the
# same failure as a settle that passed one it should not have.
for assertion in convergence audited-green no-loss no-ciphertext issues-honest leak-watch; do
	grep -q "\"assertion\":\"$assertion\"" "$BASE"/journal/orchestrator.jsonl \
		|| fail "the settle never reached a verdict on $assertion"
done

grep -q "INVARIANT VIOLATIONS: 0" "$REPORT" || {
	echo "--- verdicts ---"
	grep '"ok":false' "$BASE"/journal/orchestrator.jsonl || true
	if [ -d "$BASE/bundles" ]; then
		echo "--- bundles ---"
		ls -1 "$BASE/bundles" || true
		# Copied out before the trap removes the scratch directory: the whole
		# value of a failed run is the evidence it produced.
		KEEP="${TMPDIR:-/tmp}/jd-soak-gate-failure-$$"
		cp -r "$BASE/bundles" "$KEEP" 2>/dev/null && echo "evidence kept at $KEEP"
	fi
	fail "an invariant was broken during the cycle"
}

[ "$ORCHESTRATE" -eq 0 ] || fail "the campaign exited $ORCHESTRATE"

echo "sync soak gate: one storm+settle cycle green, $OPS actor operations, six assertions checked"
