#!/usr/bin/env bash
#
# unjoined_refresh.sh - a machine whose agent never joined a management node
# keeps its agent and its support bundle at the current release
# (specs/security_fixes_on_relay_and_dns.md WP5).
#
# Version: 1.0
#
# A joined machine gets each new agent and bundle from its management node.
# One that never joined (the ScrollDaddy DNS servers, a Docker host installed
# with no management node) has nobody to hand it one, and would keep the
# release it was installed from for good. Its package fixes still arrive, but
# after a release key rotation that old agent would refuse the new fix list,
# and the fixes would stop without a word.
#
# So once a day this asks the upgrade server which release is current, and
# when it is newer than the one this machine took last:
#   1. downloads the release archive and takes its agent_dist folder only;
#   2. puts it in place at /var/lib/joinery-agent/dist by rename (never a
#      write in place: the agent reading a manifest beside a half-written
#      binary would refuse that release until the next one);
#   3. the running agent, pointed there by AGENT_DIST_DIR, updates itself:
#      it checks the binary's signature and the public log, tries the new
#      binary before swapping, and rolls back one that does not start;
#   4. installs the release's support bundle (joinery-agent bundle install,
#      checked the same way).
# Nothing here vouches for the bytes: the agent refuses what is not signed and
# logged, and never goes back to an older release. This script only fetches.
#
# Never on the mail relay: its code changes only with a rebuild, which carries
# the owner's trust step (owner, RQ1 of the spec). The relay's own marker
# (/opt/joinery-relay) stops it.
#
#   unjoined_refresh.sh run       the daily look (the timer's)
#   unjoined_refresh.sh install   write the daily timer and point the agent at the folder
#   unjoined_refresh.sh remove    the opposite, for a machine that joined
#
# The host converger runs install on an unjoined machine with no site, and
# remove once it has joined: a local folder wins over the management node
# (artifactsource.go), so a joined machine left pointed at it would never take
# its management node's agent.
#
# State: /var/lib/joinery-agent/dist/RELEASE, the release last put in place.
# A test, never root, runs it under UNJOINED_REFRESH_ROOT, which stands in
# for / in every path it keeps, with stand-ins for curl, tar, systemctl and
# joinery-agent on PATH.

set -uo pipefail
export LC_ALL=C

CMD="${1:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TROOT=""
if [ "$(id -u)" -ne 0 ] && [ -n "${UNJOINED_REFRESH_ROOT:-}" ]; then
    TROOT="${UNJOINED_REFRESH_ROOT%/}"
fi
SERVER="${UPGRADE_SERVER:-https://getjoinery.com}"
SERVER="${SERVER%/}"
AGENT_LIB="${TROOT}/var/lib/joinery-agent"
DIST="${AGENT_LIB}/dist"
# What the agent's unit reads; DIST as the agent sees it (no test root).
ENV_FILE="${TROOT}/etc/joinery-agent/joinery-agent.env"
DIST_LINE="AGENT_DIST_DIR=/var/lib/joinery-agent/dist"
IDENTITY="${TROOT}/etc/joinery-agent/node_identity.json"
RELAY_HOME="${TROOT}/opt/joinery-relay"
UNIT_DIR="${TROOT}/etc/systemd/system"
UNIT=joinery-unjoined-refresh

say() { echo "unjoined refresh: $*"; }
die() { echo "unjoined refresh: $*" >&2; exit 1; }

case "$CMD" in run|install|remove) ;; *)
    echo "usage: unjoined_refresh.sh run|install|remove" >&2; exit 2 ;;
esac
[ "$(id -u)" -eq 0 ] || [ -n "$TROOT" ] || die "run as root"

# The agent reads its env file once, at start.
restart_agent() {
    systemctl restart joinery-agent > /dev/null 2>&1 || say "WARNING - the agent could not be restarted; it reads the change at its next start"
}

remove_all() {
    local changed=0
    if [ -f "${UNIT_DIR}/${UNIT}.timer" ] || [ -f "${UNIT_DIR}/${UNIT}.service" ]; then
        systemctl disable --now "${UNIT}.timer" > /dev/null 2>&1 || true
        rm -f "${UNIT_DIR}/${UNIT}.timer" "${UNIT_DIR}/${UNIT}.service"
        systemctl daemon-reload > /dev/null 2>&1 || true
        say "removed the daily refresh"
    fi
    if [ -f "$ENV_FILE" ] && grep -qx "$DIST_LINE" "$ENV_FILE"; then
        grep -vx "$DIST_LINE" "$ENV_FILE" > "${ENV_FILE}.new" && chmod 640 "${ENV_FILE}.new" && mv -f "${ENV_FILE}.new" "$ENV_FILE" \
            || die "${ENV_FILE} could not be rewritten"
        changed=1
        say "the agent takes its binary from its management node again"
    fi
    [ "$changed" -eq 1 ] && restart_agent
    return 0
}

if [ "$CMD" = remove ]; then
    remove_all
    exit 0
fi

if [ -f "$IDENTITY" ]; then
    # Joined since the timer was written: the management node delivers now.
    remove_all
    exit 0
fi
[ -e "$RELAY_HOME" ] && die "this is a mail relay: its code changes only with a relay update"

# --- install: the daily timer and the agent's folder -----------------------------
if [ "$CMD" = install ]; then
    # A root timer runs this script as root: it must be root's alone, as the
    # bundle it ships in is.
    if [ -z "$TROOT" ]; then
        for p in "$HERE" "${HERE}/unjoined_refresh.sh"; do
            read -r owner mode < <(stat -L -c '%u %a' "$p")
            [ "$owner" = 0 ] && [ $(( 8#$mode & 8#022 )) -eq 0 ] || die "${p} is not root's alone; the timer was not installed"
        done
    fi
    svc="$(cat <<EOF
# Written by unjoined_refresh.sh install (specs/security_fixes_on_relay_and_dns.md WP5).
[Unit]
Description=Joinery: keep an unjoined machine's agent and support bundle at the current release
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/bash ${HERE}/unjoined_refresh.sh run
Nice=10
TimeoutStartSec=30min
EOF
)"
    tmr="$(cat <<'EOF'
# Written by unjoined_refresh.sh install: daily, spread over two hours.
[Unit]
Description=Joinery: daily look for a newer release on an unjoined machine

[Timer]
OnCalendar=daily
RandomizedDelaySec=2h
Persistent=true

[Install]
WantedBy=timers.target
EOF
)"
    changed=0
    mkdir -p "$UNIT_DIR" || die "${UNIT_DIR} could not be made"
    for pair in "service:$svc" "timer:$tmr"; do
        kind="${pair%%:*}"; text="${pair#*:}"
        f="${UNIT_DIR}/${UNIT}.${kind}"
        if [ ! -f "$f" ] || [ "$(cat "$f")" != "$text" ]; then
            printf '%s\n' "$text" > "${f}.new" && chmod 644 "${f}.new" && mv -f "${f}.new" "$f" || die "${f} could not be written"
            changed=1
        fi
    done
    [ "$changed" -eq 1 ] && { systemctl daemon-reload || die "systemd did not reload"; }
    if ! systemctl is-enabled --quiet "${UNIT}.timer" 2> /dev/null || ! systemctl is-active --quiet "${UNIT}.timer" 2> /dev/null; then
        systemctl enable --now "${UNIT}.timer" > /dev/null 2>&1 || die "the timer could not be enabled"
        changed=1
    fi
    mkdir -p "$(dirname "$ENV_FILE")"
    if ! { [ -f "$ENV_FILE" ] && grep -qx "$DIST_LINE" "$ENV_FILE"; }; then
        { [ -f "$ENV_FILE" ] && grep -v '^AGENT_DIST_DIR=' "$ENV_FILE"; echo "$DIST_LINE"; } > "${ENV_FILE}.new" \
            && chmod 640 "${ENV_FILE}.new" && mv -f "${ENV_FILE}.new" "$ENV_FILE" || die "${ENV_FILE} could not be written"
        restart_agent
        changed=1
    fi
    [ "$changed" -eq 1 ] && say "installed: a daily look at ${SERVER} for a newer release" || say "the daily refresh is current"
    exit 0
fi

# --- run: the daily look -----------------------------------------------------------
version_ok() { [[ "$1" =~ ^[0-9]{1,4}\.[0-9]{1,5}\.[0-9]{1,6}$ ]]; }
# True when $1 is a lower release than $2.
older() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]; }

current="$(curl -sS -o /dev/null -D - --max-time 60 "${SERVER}/utils/latest_release" 2> /dev/null \
    | tr -d '\r' | sed -n 's/^[Xx]-[Jj]oinery-[Vv]ersion: *//p' | head -n 1)"
version_ok "$current" || die "${SERVER} did not say which release is current"
taken=""
[ -f "${DIST}/RELEASE" ] && taken="$(head -n 1 "${DIST}/RELEASE")"
if [ "$current" = "$taken" ]; then
    say "release ${current} is in place"
    exit 0
fi
if [ -n "$taken" ] && version_ok "$taken" && older "$current" "$taken"; then
    die "${SERVER} says ${current} is current, older than ${taken} in place; an older release is never put back"
fi

mkdir -p "$AGENT_LIB" && chmod 700 "$AGENT_LIB" || die "${AGENT_LIB} could not be made"
work="$(mktemp -d "${AGENT_LIB}/refresh.XXXXXX")" || die "no working folder"
trap 'rm -rf "${work:?}"' EXIT
# The archive is fetched by its own name, so a release published between the
# two asks cannot hand over bytes of another version than the one checked.
if ! curl -sSf --max-time 900 -o "${work}/release.tar.gz" "${SERVER}/static_files/joinery-core-${current}.tar.gz"; then
    die "the release ${current} archive could not be downloaded from ${SERVER}"
fi
mkdir "${work}/x"
tar -C "${work}/x" -xzf "${work}/release.tar.gz" --no-same-owner ./public_html/agent_dist 2> /dev/null \
    || die "the release ${current} archive has no agent_dist"
new="${work}/x/public_html/agent_dist"
[ -z "$(find "$new" ! -type f ! -type d | head -n 1)" ] || die "the release ${current} agent_dist holds something that is not a file"
for f in manifest.json support_bundle.tar.gz RELEASE_STATEMENT; do
    [ -f "${new}/${f}" ] || die "the release ${current} agent_dist has no ${f}"
done
printf '%s\n' "$current" > "${new}/RELEASE"
chmod -R go-w "$new"

# Into place by rename: the agent never sees a half-written folder.
rm -rf "${DIST}.new" "${DIST}.old"
mv "$new" "${DIST}.new" || die "the new agent_dist could not be staged"
[ -e "$DIST" ] && { mv "$DIST" "${DIST}.old" || die "the previous agent_dist could not be moved aside"; }
mv "${DIST}.new" "$DIST" || { mv "${DIST}.old" "$DIST" 2> /dev/null; die "the new agent_dist could not be put in place"; }
rm -rf "${DIST}.old"
say "release ${current}'s agent is in place; the agent checks it and updates itself within a minute"

if out="$(joinery-agent bundle install "${DIST}/support_bundle.tar.gz" --statement="${DIST}/RELEASE_STATEMENT" 2>&1)"; then
    say "release ${current}'s support bundle is installed"
else
    die "release ${current}'s support bundle was not installed: $(printf '%s' "$out" | tail -n 2 | tr '\n' ' ')"
fi
exit 0
