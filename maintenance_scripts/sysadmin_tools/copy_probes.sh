#!/usr/bin/env bash
#
# copy_probes.sh - the security rows of a site copy that only acting as the
# site can prove, probed on the machine that holds the site
# (specs/site_copy_final_test.md, the rig: the rows marked ‡). The plane reads
# every other row from the host report (CopySecurityCheck); these are what a
# report cannot say, because they are the attempts themselves.
#
# Run by the copy_probes agent word, as root, with an argv the agent composed
# from validated values.
#
# Version: 1.0
#
#   copy_probes.sh SITE [--neighbour SITE2] [--old-address IP]
#
#   SITE            the site (a container on this host, or this server's own site)
#   --neighbour     another site on this host whose files SITE's root must not read
#   --old-address   the site's old server's address, which this machine must no
#                   longer believe forwarded addresses from
#
# COPY_PROBES_SITES (where sites live, /var/www/html), COPY_PROBES_ETC (/etc)
# and COPY_PROBES_ENGINES (the container engines looked for) exist for the
# gate's fixture; nothing in production sets them.
#
# One line per row: PASS, FAIL or SKIP, what, and its evidence; then
# RESULT: PASS or RESULT: FAIL. Exit 0 whenever the rows were taken, so the
# job carries them either way; 2 for bad usage.
#
# Every probe is an attempt the site must be refused. Nothing is created on
# the way: the namespace probe mounts over /tmp inside a mount namespace of its
# own, which ends with it; a mount the web user is let make outside one (a
# failure) is taken down again at once; and the write to the site's code
# appends nothing, so even a write that is let through changes no byte.

set -u
export LC_ALL=C

[[ "$(id -u)" == "0" ]] || { echo "copy_probes: run as root" >&2; exit 2; }
NAME_RE='^[a-z0-9][a-z0-9_-]{0,49}$'
SITE="${1:-}"; shift || true
[[ "$SITE" =~ $NAME_RE ]] || { echo "usage: copy_probes.sh SITE [--neighbour SITE2] [--old-address IP]" >&2; exit 2; }
NEIGHBOUR=""; OLD=""
while (( $# )); do
    case "$1" in
        --neighbour)   NEIGHBOUR="${2:-}"; shift 2 || shift ;;
        --old-address) OLD="${2:-}"; shift 2 || shift ;;
        *) echo "copy_probes: unknown argument $1" >&2; exit 2 ;;
    esac
done
[[ -z "$NEIGHBOUR" || "$NEIGHBOUR" =~ $NAME_RE ]] || { echo "copy_probes: --neighbour takes a site name" >&2; exit 2; }
[[ -z "$OLD" || "$OLD" =~ ^[0-9a-fA-F:.]{2,39}$ ]] || { echo "copy_probes: --old-address takes an address" >&2; exit 2; }

failed=0
row() {  # PASS|FAIL|SKIP WHAT EVIDENCE
    printf '%-6s %s - %s\n' "$1" "$2" "$3"
    [[ "$1" == FAIL ]] && failed=1
    return 0
}

SITES="${COPY_PROBES_SITES:-/var/www/html}"
ETC="${COPY_PROBES_ETC:-/etc}"
ENGINE=""
for e in ${COPY_PROBES_ENGINES:-/usr/local/bin/podman podman docker}; do
    command -v "$e" >/dev/null 2>&1 && { ENGINE="$e"; break; }
done
CONTAINER=0
if [[ -n "$ENGINE" ]] && "$ENGINE" inspect "$SITE" >/dev/null 2>&1; then CONTAINER=1; fi
if [[ "$CONTAINER" == 0 && ! -d "${SITES}/${SITE}/public_html" ]]; then
    echo "copy_probes: no site ${SITE} on this machine" >&2
    exit 2
fi

# As the site's web user: inside its container, or on its own server.
as_www() {
    if [[ "$CONTAINER" == 1 ]]; then
        timeout 30 "$ENGINE" exec -u www-data "$SITE" "$@"
    else
        timeout 30 runuser -u www-data -- "$@"
    fi
}
# As root where the site runs: inside its container, or here.
as_root_there() {
    if [[ "$CONTAINER" == 1 ]]; then
        timeout 30 "$ENGINE" exec -u root "$SITE" "$@"
    else
        timeout 30 "$@"
    fi
}

# --- The site filter, as the web user ---------------------------------------
# A user namespace gives the web user nothing: inside a container the site
# filter refuses it outright; on a server of its own the kernel
# (kernel.apparmor_restrict_unprivileged_userns) gives it no capability, so it
# cannot even map itself to root. Either way a mount inside one is refused.
ns="$(as_www unshare -Urm sh -c 'mount -t tmpfs none /tmp && echo MOUNTED' 2>&1 | head -1)"
if [[ "$ns" == MOUNTED ]]; then
    row FAIL "A user namespace gives the web user no capability" "a mount inside one succeeded"
else
    row PASS "A user namespace gives the web user no capability" "refused: ${ns:-no answer}"
fi
if as_www mount -t tmpfs none /mnt >/dev/null 2>&1; then
    as_root_there umount /mnt >/dev/null 2>&1
    row FAIL "The web user cannot mount" "a mount on /mnt succeeded (taken down again)"
else
    row PASS "The web user cannot mount" "mount refused"
fi
# Asked with Perl (perl-base is on every Ubuntu machine and image): PHP's
# socket_create() refuses AF_PACKET as an argument before the kernel is asked.
if as_www perl -e 1 >/dev/null 2>&1; then
    packet="$(as_www perl -e 'if (socket(my $s, 17, 3, 0)) { print "opened" } else { print "refused: $!" }' 2>/dev/null)"
    if [[ "$packet" == refused* ]]; then
        row PASS "The web user cannot open a packet socket" "AF_PACKET ${packet}"
    else
        row FAIL "The web user cannot open a packet socket" "AF_PACKET ${packet:-unanswered}"
    fi
else
    row SKIP "The web user cannot open a packet socket" "no perl here to ask with"
fi
if as_www php -r 'echo "ok";' 2>/dev/null | grep -q ok; then
    row PASS "The site's PHP runs as its web user" "php -r answered"
else
    row FAIL "The site's PHP runs as its web user" "php -r did not answer"
fi

# --- The read-only tree -------------------------------------------------------
target="${SITES}/${SITE}/public_html/serve.php"
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
    if grep -Rqs -F "RemoteIPTrustedProxy ${OLD}" "${ETC}/apache2/conf-enabled/" "${ETC}/apache2/sites-enabled/"; then
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

if [[ "$failed" == 1 ]]; then echo "RESULT: FAIL"; else echo "RESULT: PASS"; fi
exit 0
