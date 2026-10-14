#!/usr/bin/env bash
#
# reboot_machine.sh - reboot this machine a few seconds from now, so the job
# that asked reports first, or say why not. ONE JSON object on stdout.
#
# Version: 1.0 - the reboot_machine operate word of
#                specs/agent_recipes_and_vocabulary.md (the running list,
#                2026-10-10: a data root move ends with a reboot, and every
#                host had shown "Reboot required" for days with nothing on the
#                plane that could act on it). The agent runs this file,
#                verified against the release manifest, with no argv and no
#                stdin.
#
# THE CONTRACT, which tests/integration/reboot_machine_gate.sh pins:
#
#   - It takes NOTHING: no argument, no stdin. No delay, no "power off", no
#     kernel to boot: one reboot, REBOOT_DELAY seconds out, by a transient
#     systemd timer (joinery-reboot-machine.timer).
#   - It refuses, changing nothing, where the reboot cannot be proved safe
#     (rule 3, fail closed), each with a code and a sentence:
#       container      a site container: its server reboots from its own node
#       no_systemd     nothing here can schedule the reboot
#       data_root_run  the data root is being encrypted, decrypted or re-keyed,
#                      and a boot mid-run opens only with the recovery key
#       no_escrow      the data root is encrypted and names no escrow holder,
#                      so the boot would wait for someone to type its recovery key
#       packages       dpkg or apt is installing; a reboot mid-install leaves
#                      a half-configured package
#       installers     the host's installers or the data root script hold
#                      their lock past LOCK_WAIT seconds
#   - A reboot already scheduled is reported (already_scheduled), and no
#     second one is added.
#   - Every key is ALWAYS present. The exit code is 0 when the reboot is
#     scheduled and 2 when it is refused; a refusal also says why on stderr,
#     which the job's error carries.
#   - boot_id_before is the kernel's boot id now. The management node keeps it
#     and reads the next host report's boot id: a different one is the proof
#     the machine came back.

set -u
export LC_ALL=C

REBOOT_DELAY=20          # seconds: the job's result reaches the plane first
LOCK_WAIT=30             # seconds a lock may be held before the reboot is refused
[[ "${REBOOT_MACHINE_LOCK_WAIT:-}" =~ ^[0-9]{1,3}$ ]] && LOCK_WAIT="$REBOOT_MACHINE_LOCK_WAIT"
UNIT=joinery-reboot-machine
CMD_TIMEOUT=20

ETC="${REBOOT_MACHINE_ETC:-/etc}"
RUN="${REBOOT_MACHINE_RUN:-/run}"

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

BOOT_ID="$(tr -cd '0-9a-f-' < /proc/sys/kernel/random/boot_id 2>/dev/null | head -c 36)"

emit() {  # scheduled already_scheduled refused reason
    local reason="${4//[^A-Za-z0-9 ,.:;()\/_-]/}"
    printf '{"scheduled":%s,"already_scheduled":%s,"in_seconds":%d,"boot_id_before":"%s","refused":"%s","reason":"%s"}\n' \
        "$1" "$2" "$REBOOT_DELAY" "$BOOT_ID" "$3" "${reason:0:300}"
}

refuse() {  # code sentence
    emit false false "$1" "$2"
    printf 'reboot_machine: %s\n' "$2" >&2
    exit 2
}

# --- A site container is not a machine. -------------------------------------
if [[ -f /.dockerenv || -f /run/.containerenv ]] || run systemd-detect-virt --container >/dev/null; then
    refuse container "this is a site container; its server reboots from the server's own node"
fi

# --- systemd schedules it. ----------------------------------------------------
[[ "$(cat /proc/1/comm 2>/dev/null)" == "systemd" ]] && command -v systemd-run >/dev/null 2>&1 \
    || refuse no_systemd "this machine has no systemd to schedule the reboot"

# --- An encrypted data root opens by itself, or the reboot waits for a person. --
conf="${ETC}/joinery/data_root"
backing="$(awk -F= '$1=="backing" { print $2; exit }' "$conf" 2>/dev/null)"
if [[ "$backing" == luks-* ]]; then
    mode="$(awk -F= '$1=="reencrypt" { print $2; exit }' "$conf" 2>/dev/null | tr -cd 'a-z')"
    [[ -z "$mode" ]] || refuse data_root_run \
        "an encryption, its undo or a re-key of the data root is running (${mode}); a reboot now opens only with the recovery key. Reboot when it has finished"
    holder="$(awk -F= '$1=="url" { sub(/^[^=]*=/, ""); print; exit }' "${ETC}/joinery/data-root/holder" 2>/dev/null)"
    [[ -n "$holder" ]] || refuse no_escrow \
        "the data root is encrypted and names no escrow holder, so the machine would wait at boot for its recovery key"
fi

# --- Nothing half-installed. ----------------------------------------------------
# dpkg is what unattended-upgrades runs to install, too; its own
# unattended-upgrade-shutdown is always running and already holds a shutdown
# back while an upgrade is under way, so it is not asked about here.
if pgrep -x 'dpkg|apt|apt-get' >/dev/null 2>&1; then
    refuse packages "a package install is running (dpkg or apt); reboot when it has finished"
fi
for lock in "${RUN}"/joinery/host-installers.*.lock "${RUN}/joinery/data-root.lock"; do
    [[ -f "$lock" ]] || continue
    # 75 is a lock still held when the wait ran out; a file that cannot be
    # opened is not a run in progress.
    flock -E 75 -w "$LOCK_WAIT" "$lock" true 2>/dev/null
    (( $? != 75 )) || refuse installers "the host's installers or the data root script are running ($(basename "$lock")); reboot when they have finished"
done

# --- One reboot, a few seconds out. -------------------------------------------------
# REBOOT_MACHINE_DRY_RUN=1 stops here as if it had scheduled one: the gate runs
# every check on a real machine without rebooting it. The agent passes no such
# variable, and nothing on the wire can set one.
if [[ "${REBOOT_MACHINE_DRY_RUN:-}" == "1" ]]; then
    emit true false "" ""
    exit 0
fi
if run systemctl is-active --quiet "${UNIT}.timer"; then
    emit true true "" ""
    exit 0
fi
run systemd-run --unit="$UNIT" --description="Reboot asked for by the management node (reboot_machine)" \
    --on-active="${REBOOT_DELAY}s" --timer-property=AccuracySec=1s /bin/systemctl reboot >/dev/null \
    || refuse no_systemd "systemd did not take the reboot timer"
emit true false "" ""
exit 0
