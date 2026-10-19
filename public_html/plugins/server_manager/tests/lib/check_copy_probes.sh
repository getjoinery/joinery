#!/usr/bin/env bash
#
# check_copy_probes.sh - the security rows that act as a site or change the
# machine, probed on the machine itself (specs/site_copy_final_test.md, the
# rig, rows marked ‡). TEST PLANE ONLY: the live tests copy it to a test box
# over SSH and run it as root there. Never on a production machine: it loosens
# a kernel setting, and acts as a site's web user.
#
# Version: 1.1 - the trust row follows the symlinks it reads (grep -R); the namespace row asks for a capability inside one (own servers allow the
#               namespace and give it none); the packet socket is asked with Perl, which PHP cannot
# Version: 1.0
#
#   check_copy_probes.sh SITE [--neighbour SITE2] [--old-address IP] [--loosen]
#
#   SITE            the site (a container on this host, or this server's own site)
#   --neighbour     another site on this host whose files SITE's root must not read
#   --old-address   the site's old server's address, which this machine must no
#                   longer believe forwarded addresses from
#   --loosen        loosen kernel.yama.ptrace_scope and see it put back within
#                   70 seconds (B37); it is set back by hand if it is not
#
# One line per row: PASS, FAIL or SKIP, what, and its evidence. Exit 0 when
# nothing failed.

set -u
export LC_ALL=C

[[ "$(id -u)" == "0" ]] || { echo "check_copy_probes: run as root" >&2; exit 2; }
SITE="${1:-}"; shift || true
[[ "$SITE" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || { echo "usage: check_copy_probes.sh SITE [--neighbour SITE2] [--old-address IP] [--loosen]" >&2; exit 2; }
NEIGHBOUR=""; OLD=""; LOOSEN=0
while (( $# )); do
    case "$1" in
        --neighbour)   NEIGHBOUR="${2:-}"; shift 2 ;;
        --old-address) OLD="${2:-}"; shift 2 ;;
        --loosen)      LOOSEN=1; shift ;;
        *) echo "check_copy_probes: unknown argument $1" >&2; exit 2 ;;
    esac
done

failed=0
row() {  # PASS|FAIL|SKIP WHAT EVIDENCE
    printf '%-6s %s - %s\n' "$1" "$2" "$3"
    [[ "$1" == FAIL ]] && failed=1
    return 0
}

ENGINE=""
for e in /usr/local/bin/podman podman docker; do
    command -v "$e" >/dev/null 2>&1 && { ENGINE="$e"; break; }
done
CONTAINER=0
if [[ -n "$ENGINE" ]] && "$ENGINE" inspect "$SITE" >/dev/null 2>&1; then CONTAINER=1; fi

# As the site's web user: inside its container, or on its own server.
as_www() {
    if [[ "$CONTAINER" == 1 ]]; then
        timeout 30 "$ENGINE" exec -u www-data "$SITE" "$@"
    else
        timeout 30 runuser -u www-data -- "$@"
    fi
}
SITE_ROOT="/var/www/html/${SITE}"

# --- A loosened kernel setting is put back within a minute (B37) ------------
if [[ "$LOOSEN" == 1 ]]; then
    was="$(sysctl -n kernel.yama.ptrace_scope 2>/dev/null)"
    sysctl -q -w kernel.yama.ptrace_scope=0 >/dev/null 2>&1
    sleep 70
    now="$(sysctl -n kernel.yama.ptrace_scope 2>/dev/null)"
    if [[ "$now" == "$was" && "$now" != 0 ]]; then
        row PASS "A loosened kernel setting is put back within a minute" "ptrace_scope 0 for 70 s, back to ${now}"
    else
        row FAIL "A loosened kernel setting is put back within a minute" "ptrace_scope ${now} after 70 s (was ${was}); set back by hand"
        sysctl -q -w "kernel.yama.ptrace_scope=${was:-1}" >/dev/null 2>&1
    fi
else
    row SKIP "A loosened kernel setting is put back within a minute" "not asked (--loosen)"
fi

# --- The site filter, as the web user ---------------------------------------
# A user namespace gives the web user nothing: inside a container the site
# filter refuses it outright; on a server of its own the kernel
# (kernel.apparmor_restrict_unprivileged_userns) gives it no capability, so it
# cannot even map itself to root. Either way a mount inside one is refused.
ns="$(as_www unshare -Urm sh -c 'mkdir -p /tmp/ccp_ns && mount -t tmpfs none /tmp/ccp_ns && echo MOUNTED' 2>&1 | head -1)"
if [[ "$ns" == MOUNTED ]]; then
    row FAIL "A user namespace gives the web user no capability" "a mount inside one succeeded"
else
    row PASS "A user namespace gives the web user no capability" "refused: ${ns:-no answer}"
fi
if as_www sh -c 'mkdir -p /tmp/ccp_mnt && mount -t tmpfs none /tmp/ccp_mnt' >/dev/null 2>&1; then
    row FAIL "The web user cannot mount" "mount succeeded"
else
    row PASS "The web user cannot mount" "mount refused"
fi
# Asked with Perl (perl-base is on every Ubuntu machine and image): PHP's
# socket_create() refuses AF_PACKET as an argument before the kernel is asked.
if as_www perl -e 1 >/dev/null 2>&1; then
    packet="$(as_www perl -e 'if (socket(my $s, 17, 3, 0)) { print "opened" } else { print "refused: $!" }' 2>/dev/null)"
    [[ "$packet" == refused* ]] && row PASS "The web user cannot open a packet socket" "AF_PACKET ${packet}" \
        || row FAIL "The web user cannot open a packet socket" "AF_PACKET ${packet:-unanswered}"
else
    row SKIP "The web user cannot open a packet socket" "no perl here to ask with"
fi
if as_www php -r 'echo "ok";' 2>/dev/null | grep -q ok; then
    row PASS "The site's PHP runs as its web user" "php -r answered"
else
    row FAIL "The site's PHP runs as its web user" "php -r did not answer"
fi

# --- The read-only tree -------------------------------------------------------
target="${SITE_ROOT}/public_html/serve.php"
[[ "$CONTAINER" == 1 ]] && target="/var/www/html/${SITE}/public_html/serve.php"
if as_www sh -c "printf '' >> '${target}'" >/dev/null 2>&1; then
    row FAIL "The web user cannot write the site's code" "${target} took a write"
else
    row PASS "The web user cannot write the site's code" "${target} refused a write"
fi

# --- Walls by id: the site's root reads nothing of its neighbour's -----------
if [[ -n "$NEIGHBOUR" && "$CONTAINER" == 1 ]]; then
    pid="$("$ENGINE" inspect -f '{{.State.Pid}}' "$SITE" 2>/dev/null)"
    base="$(awk 'NR == 1 && $1 == 0 { print $2 }' "/proc/${pid}/uid_map" 2>/dev/null)"
    vol="$("$ENGINE" volume inspect -f '{{.Mountpoint}}' "${NEIGHBOUR}_config" 2>/dev/null)"
    if [[ ! "$base" =~ ^[0-9]+$ || -z "$vol" ]]; then
        row FAIL "Walls by id" "could not read ${SITE}'s id map or ${NEIGHBOUR}'s config volume"
    elif setpriv --reuid="$base" --regid="$base" --clear-groups ls "$vol" >/dev/null 2>&1; then
        row FAIL "Walls by id" "${SITE}'s root (host id ${base}) listed ${NEIGHBOUR}'s config volume"
    else
        row PASS "Walls by id" "${SITE}'s root (host id ${base}) is refused ${NEIGHBOUR}'s config volume"
    fi
else
    row SKIP "Walls by id" "no neighbour named, or not a container"
fi

# --- No trust left for the old address -----------------------------------------
if [[ -n "$OLD" ]]; then
    # -R: conf-enabled and sites-enabled hold symlinks, which -r passes over.
    if grep -Rqs -F "RemoteIPTrustedProxy ${OLD}" /etc/apache2/conf-enabled/ /etc/apache2/sites-enabled/; then
        row FAIL "No trust left for the old address" "RemoteIPTrustedProxy ${OLD} is enabled"
    else
        row PASS "No trust left for the old address" "no RemoteIPTrustedProxy ${OLD}"
    fi
else
    row SKIP "No trust left for the old address" "no old address named"
fi

# --- The database answers on loopback alone --------------------------------------
if [[ "$CONTAINER" == 1 ]]; then
    publish="$("$ENGINE" port "$SITE" 5432/tcp 2>/dev/null | tr '\n' ' ')"
    if [[ -z "$publish" || "$publish" =~ ^(127\.0\.0\.1:[0-9]+\ ?)+$ ]]; then
        row PASS "The database answers on loopback alone" "published: ${publish:-nowhere}"
    else
        row FAIL "The database answers on loopback alone" "published: ${publish}"
    fi
else
    listen="$(ss -ltnH 'sport = :5432' 2>/dev/null | awk '{print $4}' | tr '\n' ' ')"
    if [[ -n "$listen" && ! "$listen" =~ (0\.0\.0\.0|\[::\]|\*): ]]; then
        row PASS "The database answers on loopback alone" "listens on ${listen}"
    else
        row FAIL "The database answers on loopback alone" "listens on ${listen:-nothing}"
    fi
fi

exit "$failed"
