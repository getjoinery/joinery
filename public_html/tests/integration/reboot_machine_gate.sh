#!/bin/bash
# @joinery-test
# name: reboot_machine
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/reboot_machine.sh]
#
# reboot_machine.sh is what the agent's reboot_machine operate word runs as
# root (specs/agent_recipes_and_vocabulary.md, the running list). This gate
# pins its contract: one reboot, twenty seconds out, through a transient
# systemd timer that runs /bin/systemctl reboot and nothing else; a refusal,
# with nothing scheduled, in a site container, with no systemd, on an
# encrypted data root that names no escrow holder or is mid-run, while dpkg or
# apt runs, and while the host's installers hold their lock; a reboot already
# scheduled is reported and not doubled; every key always present, and the
# boot id the management node watches change. Every systemd command here goes
# to a stub on PATH: the gate never reboots the machine it runs on.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/reboot_machine.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
field() { php -r '$o=json_decode(stream_get_contents(STDIN),true); if(!is_array($o)){echo "NOTJSON";exit;} $v=$o[$argv[1]] ?? "ABSENT"; echo is_bool($v)?($v?"true":"false"):$v;' "$1"; }
keys() { php -r '$o=json_decode(stream_get_contents(STDIN),true); echo is_array($o)?implode(",",array_keys($o)):"NOTJSON";'; }

# Stubs: not in a container, no package manager running, no reboot scheduled,
# and a systemd-run that only records what it was asked.
mkdir -p "$T/bin" "$T/etc/joinery/data-root" "$T/run/joinery"
cat > "$T/bin/systemd-detect-virt" <<'STUB'
#!/bin/bash
[ -n "${GATE_IN_CONTAINER:-}" ] && { echo docker; exit 0; }
echo none; exit 1
STUB
cat > "$T/bin/pgrep" <<'STUB'
#!/bin/bash
[ -n "${GATE_DPKG:-}" ] && { echo 4242; exit 0; }
exit 1
STUB
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "systemctl $*" >> "$GATE_LOG"
[ "$1 $2" = "is-active --quiet" ] && [ -n "${GATE_SCHEDULED:-}" ] && exit 0
exit 3
STUB
cat > "$T/bin/systemd-run" <<'STUB'
#!/bin/bash
echo "systemd-run $*" >> "$GATE_LOG"
exit 0
STUB
chmod 755 "$T/bin"/*
export GATE_LOG="$T/log"
BOOT="$(tr -cd '0-9a-f-' < /proc/sys/kernel/random/boot_id)"

reboot_run() {  # writes $T/out, $T/err; echoes the exit code
    : > "$GATE_LOG"
    PATH="$T/bin:$PATH" REBOOT_MACHINE_ETC="$T/etc" REBOOT_MACHINE_RUN="$T/run" REBOOT_MACHINE_LOCK_WAIT=1 \
        bash "$SCRIPT" > "$T/out" 2> "$T/err"; echo $?
}
ALL_KEYS="scheduled,already_scheduled,in_seconds,boot_id_before,refused,reason"

echo "=== A plain machine: one reboot, twenty seconds out ==="
rc="$(reboot_run)"
chk "exit 0" "$rc" "0"
chk "every key" "$(keys < "$T/out")" "$ALL_KEYS"
chk "scheduled, twenty seconds out, not refused" "$(field scheduled < "$T/out")/$(field in_seconds < "$T/out")/$(field refused < "$T/out")" "true/20/"
chk "the boot id before is the kernel's" "$(field boot_id_before < "$T/out")" "$BOOT"
chk "systemd-run asked once, for a timer that runs systemctl reboot" \
    "$(grep -c '^systemd-run ' "$GATE_LOG")/$(grep -c -- '--on-active=20s .*/bin/systemctl reboot$' "$GATE_LOG")" "1/1"

echo "=== A reboot already scheduled is reported, not doubled ==="
rc="$(GATE_SCHEDULED=1 reboot_run)"
chk "exit 0, already scheduled" "$rc/$(field already_scheduled < "$T/out")/$(field scheduled < "$T/out")" "0/true/true"
chk "no second timer" "$(grep -c '^systemd-run ' "$GATE_LOG")" "0"

echo "=== Refusals: nothing scheduled, a code and a sentence ==="
refused() {  # code: runs with the caller's env, checks exit 2, the code, every key, and no timer
    local rc; rc="$(reboot_run)"
    printf '%s/%s/%s/%s/%s' "$rc" "$(field refused < "$T/out")" "$(field scheduled < "$T/out")" \
        "$(grep -c '^systemd-run ' "$GATE_LOG")" "$([ -s "$T/err" ] && echo said || echo silent)"
}
chk "in a site container" "$(GATE_IN_CONTAINER=1 refused)" "2/container/false/0/said"
chk "the refusal still carries every key" "$(keys < "$T/out")" "$ALL_KEYS"

printf 'backing=luks-file\nsource=/srv/joinery.img\n' > "$T/etc/joinery/data_root"
chk "an encrypted data root naming no escrow holder" "$(refused)" "2/no_escrow/false/0/said"
chk "and it says the boot would wait for the recovery key" "$(grep -c 'recovery key' "$T/err")" "1"
printf 'url=https://mgr.example.com\nslot=0\n' > "$T/etc/joinery/data-root/holder"
printf 'backing=luks-file\nsource=/srv/joinery.img\nreencrypt=encrypt\n' > "$T/etc/joinery/data_root"
chk "an encrypted data root mid-run, even with a holder" "$(refused)" "2/data_root_run/false/0/said"
printf 'backing=luks-file\nsource=/srv/joinery.img\nreencrypt=\n' > "$T/etc/joinery/data_root"
rc="$(reboot_run)"
chk "an encrypted data root with a holder and no run: scheduled" "$rc/$(field scheduled < "$T/out")" "0/true"
printf 'backing=file\nsource=/srv/joinery.img\n' > "$T/etc/joinery/data_root"; rm -f "$T/etc/joinery/data-root/holder"
rc="$(reboot_run)"
chk "a plain data root needs no holder" "$rc/$(field scheduled < "$T/out")" "0/true"

chk "while dpkg or apt runs" "$(GATE_DPKG=1 refused)" "2/packages/false/0/said"

: > "$T/run/joinery/host-installers.site.lock"
# One process holds the lock (bash exec'd into sleep keeps the descriptor), so
# killing it lets the lock go.
bash -c 'exec 9>"$1"; flock 9; exec sleep 15' _ "$T/run/joinery/host-installers.site.lock" &
holder=$!
sleep 0.3
chk "while the host's installers hold their lock" "$(refused)" "2/installers/false/0/said"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
rc="$(reboot_run)"
chk "a lock nobody holds is no reason" "$rc/$(field scheduled < "$T/out")" "0/true"

echo "=== Static pins ==="
chk "the only reboot is systemctl reboot, under the timer" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '/bin/systemctl reboot')" "1"
chk "no shutdown, poweroff, halt, kexec or a forced reboot" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '\b(shutdown|poweroff|halt|kexec)\b|reboot -f|systemctl (poweroff|halt|kexec)')" "0"
chk "the delay is compiled, not taken from anywhere" "$(grep -c '^REBOOT_DELAY=20 ' "$SCRIPT")" "1"
: > "$GATE_LOG"
PATH="$T/bin:$PATH" REBOOT_MACHINE_ETC="$T/etc" REBOOT_MACHINE_RUN="$T/run" bash "$SCRIPT" 0 --now poweroff > "$T/out" 2>/dev/null
chk "arguments change nothing: still twenty seconds out, still systemctl reboot" \
    "$(field in_seconds < "$T/out")/$(grep -c -- '--on-active=20s .*/bin/systemctl reboot$' "$GATE_LOG")" "20/1"

echo
echo "reboot_machine gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
