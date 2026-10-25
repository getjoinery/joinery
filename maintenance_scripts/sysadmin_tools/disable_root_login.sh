#!/usr/bin/env bash
#
# disable_root_login.sh - turn root SSH login off on this machine, for good,
# and say what sshd will do now. ONE JSON object on stdout.
#
# Version: 1.0 - the disable_root_login operate word of
#                specs/implemented/beta_break_glass_root_access.md (5.3): a box whose kept
#                root password can no longer be retired by its holder, one
#                whose cleanup failed, or any machine an operator wants closed
#                to root. The agent runs this file, verified against the
#                release manifest, with no argv and no stdin.
#
# THE CONTRACT, which tests/integration/disable_root_login_gate.sh pins:
#
#   - It takes NOTHING: no argument, no stdin.
#   - It writes PermitRootLogin no in its own drop-in, DROPIN, which sorts
#     first under C and en_US collation, ahead of every other file in
#     sshd_config.d: sshd takes the first value it reads, so no later step
#     (install.sh, the retire job, a provider's drop-in) reopens root login.
#     Where sshd_config has no Include of sshd_config.d it puts the line first
#     in the main file instead. Nothing removes either: the word is one-way.
#   - sshd -t checks the result before any reload. A configuration sshd
#     refuses is put back as it was, and the run refuses (sshd_refused).
#   - The reload happens only when sshd's unit is active. A socket-activated
#     sshd that nobody has started since boot reads the file when it starts.
#     A reload restarts the listener only; open sessions stay up.
#   - The run is done only when sshd -T reads permitrootlogin no. Anything
#     else fails the run (not_effective, reload_failed).
#   - match_blocks says whether any Match block exists, which can let root in
#     for some address or user whatever the global value (an accepted
#     residual: sshd -T without -C prints only the global section).
#   - It changes nothing else: other accounts' password login, root's
#     password and keys stay as they are.
#   - A site container is refused: it is not the machine, and its server's
#     sshd is changed from the server's own node.
#   - Every key is ALWAYS present. Exit 0 when root login is off and in force,
#     2 when refused with nothing changed, 1 when the change was made and is
#     not in force. A non-zero exit also says why on stderr.

set -u
export LC_ALL=C

CMD_TIMEOUT=20
ETC="${DISABLE_ROOT_LOGIN_ETC:-/etc/ssh}"
DROPIN_NAME="00-joinery-0-no-root-login.conf"
DROPIN="${ETC}/sshd_config.d/${DROPIN_NAME}"
MAIN="${ETC}/sshd_config"

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

effective() {
    local v
    v="$(run sshd -T | awk 'tolower($1) == "permitrootlogin" { print tolower($2); exit }')"
    v="${v//[^a-z-]/}"
    printf '%s' "${v:-unknown}"
}

match_blocks() {
    local f
    for f in "$MAIN" "${ETC}"/sshd_config.d/*.conf; do
        [[ -f "$f" ]] || continue
        grep -qiE '^[[:space:]]*Match[[:space:]]' "$f" 2>/dev/null && { printf 'true'; return; }
    done
    printf 'false'
}

BEFORE="unknown"
AFTER="unknown"
WRITTEN=""
RELOAD="none"

emit() {  # changed refused reason
    local reason="${3//[^A-Za-z0-9 ,.:;()\/_-]/}"
    printf '{"changed":%s,"written":"%s","permitrootlogin_before":"%s","permitrootlogin_after":"%s","reload":"%s","match_blocks":%s,"refused":"%s","reason":"%s"}\n' \
        "$1" "$WRITTEN" "$BEFORE" "$AFTER" "$RELOAD" "$(match_blocks)" "$2" "${reason:0:300}"
}

refuse() {  # code sentence: nothing was changed
    emit false "$1" "$2"
    printf 'disable_root_login: %s\n' "$2" >&2
    exit 2
}

fail() {  # code sentence: the change was made, and is not in force
    emit true "$1" "$2"
    printf 'disable_root_login: %s\n' "$2" >&2
    exit 1
}

# --- A site container is not the machine. ------------------------------------
if [[ -f /.dockerenv || -f /run/.containerenv ]] || run systemd-detect-virt --container >/dev/null; then
    refuse container "this is a site container; turn root login off from its server's own node"
fi

command -v sshd >/dev/null 2>&1 || refuse no_sshd "this machine has no sshd"
[[ -f "$MAIN" ]] || refuse no_sshd "this machine has no ${MAIN}"

BEFORE="$(effective)"

# --- Write it, keeping what was there to put back. ------------------------------
backup=""
had_dropin=0
if grep -qE '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' "$MAIN"; then
    WRITTEN="dropin"
    install -d -m 755 "${ETC}/sshd_config.d"
    if [[ -f "$DROPIN" ]]; then
        had_dropin=1
        backup="$(mktemp)"
        cp -p "$DROPIN" "$backup"
    fi
    printf '%s\n' '# Written by the Joinery agent (disable_root_login): root may not log in over SSH.' \
        '# One-way: nothing removes this file, and it sorts first so nothing after it reopens root login.' \
        'PermitRootLogin no' > "$DROPIN"
    chmod 644 "$DROPIN"
else
    WRITTEN="main"
    backup="$(mktemp)"
    cp -p "$MAIN" "$backup"
    # The first line of the file: sshd takes the first value it reads, and a
    # line added at the end could fall inside a Match block. Every other line
    # stays as it is.
    if [[ "$(head -n 1 "$MAIN")" != "PermitRootLogin no" ]]; then
        sed -i '1i PermitRootLogin no' "$MAIN"
    fi
fi

put_back() {
    if [[ "$WRITTEN" == "dropin" ]]; then
        if (( had_dropin )); then cp -p "$backup" "$DROPIN"; else rm -f "$DROPIN"; fi
    else
        cp -p "$backup" "$MAIN"
    fi
    WRITTEN=""
}

if ! run sshd -t; then
    put_back
    [[ -n "$backup" ]] && rm -f "$backup"
    refuse sshd_refused "sshd refused the configuration with root login off, so it was put back as it was"
fi
[[ -n "$backup" ]] && rm -f "$backup"

# --- Reload, only a running sshd. -------------------------------------------------
unit=""
for u in ssh sshd; do
    if [[ "$(run systemctl show -p LoadState --value "${u}.service")" == "loaded" ]]; then
        unit="$u"
        break
    fi
done
if [[ -n "$unit" ]] && run systemctl is-active --quiet "${unit}.service"; then
    if run systemctl reload "${unit}.service"; then
        RELOAD="reloaded"
    else
        RELOAD="failed"
    fi
else
    RELOAD="not_running"
fi

AFTER="$(effective)"
[[ "$RELOAD" != "failed" ]] || fail reload_failed "sshd did not reload, so the running sshd may still let root in"
[[ "$AFTER" == "no" ]] || fail not_effective "sshd reads permitrootlogin ${AFTER}, not no: another setting still wins"
emit true "" ""
exit 0
