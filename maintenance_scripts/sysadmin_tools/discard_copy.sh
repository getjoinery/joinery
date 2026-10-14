#!/usr/bin/env bash
#
# discard_copy.sh - remove a dormant copy of a site from this host
# (specs/site_container_engine.md D9). The agent's discard_copy word runs this
# file with one argv element it has already validated: the site name.
#
# Version: 1.0
#
# A dormant copy holds nothing its source does not: it was installed empty,
# filled from its source's backups, and has served no one. So it is removed
# without its own owner's ceremony, but only while both say it is one:
#
#   - its host records it as a copy: /etc/joinery/sites/SITE/quiet says
#     `copy`. Only install.sh's dormant install writes that record (the
#     site_quiet_host word sets `switchover`, never `copy`, and a record is
#     never turned into a copy's), and the copy's start clears it;
#   - and the copy itself is still quiet: its own state file, read out of its
#     container (running or stopped) with the engine's cp, says `quiet copy`.
#     A copy that has left its own quiet is serving and taking writes no
#     backup holds, from that moment, before its host lets its network go.
#     Absent, unreadable or a link: refused.
#
# Then remove_account.sh, beside this script, removes it whole: container,
# volumes, network, image, proxy vhost, placeholder certificate and marks.
#
# Exit: 0 removed (or nothing of it left); 1 the removal did not verify; 2
# refused, nothing changed.

set -u
export LC_ALL=C
ETC="${DISCARD_COPY_ETC:-/etc}"
[[ "$(id -u)" == "0" ]] && ETC="/etc"
SITE="${1:-}"
refuse() { echo "discard_copy: $1" >&2; exit 2; }

[[ "$SITE" =~ ^[a-z0-9][a-z0-9_-]{1,49}$ ]] || refuse "${SITE:0:64} is not a site name"
RECORD="${ETC}/joinery/sites/${SITE}/quiet"
[[ -f "$RECORD" && ! -L "$RECORD" ]] \
    || refuse "${SITE} is not recorded as a dormant copy on this host (${RECORD#"$ETC"} is not there); a live site is removed by its owner, not discarded. Nothing was changed"
[[ "$(head -c 64 "$RECORD" 2>/dev/null | head -1 | tr -d '\r')" == "copy" ]] \
    || refuse "${SITE} is quiet on this host for a switch-over, not as a dormant copy; nothing was changed"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "$HERE/remove_account.sh" ]] || refuse "remove_account.sh is not beside this script; nothing was changed"
[[ -f "$HERE/../install_tools/_site_engine.sh" ]] || refuse "there is no install_tools/_site_engine.sh beside this script's tree; nothing was changed"
# shellcheck source=../install_tools/_site_engine.sh
. "$HERE/../install_tools/_site_engine.sh"
ENGINE="$(site_engine_bin)" || refuse "this machine's container engine could not be named; nothing was changed"
# The copy's own word: one root-owned file, read as a tar stream (a link comes
# out as a link entry, which prints nothing).
OWN="$(timeout 30 "$ENGINE" cp "${SITE}:/etc/joinery/sites/${SITE}/state" - 2>/dev/null | tar -xO 2>/dev/null | head -c 64 | head -1 | tr -d '\r')"
[[ "$OWN" == "quiet copy" ]] \
    || refuse "${SITE} does not say it is a dormant copy (its own state file is not 'quiet copy'); a copy that has left its quiet is not discarded. Nothing was changed"

echo "discard_copy: ${SITE} is a dormant copy on this host; removing it"
exec bash "$HERE/remove_account.sh" "$SITE" -y
