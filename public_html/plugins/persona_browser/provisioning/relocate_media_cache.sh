#!/usr/bin/env bash
#
# relocate_media_cache.sh - move this plugin's downloaded feed images out of the
# code tree, once.
#
# Version: 1.0 - The cache used to live at plugins/persona_browser/media_cache:
#                a directory the web server dropped files fetched from a
#                stranger's server into, sitting inside the directory the web
#                server runs. It lives at {site}/cache/persona_browser now
#                (specs/read_only_tree.md). The rows already in the database
#                name these files by basename, and nothing re-downloads a file
#                whose row still records it, so they are moved rather than
#                dropped - otherwise every image on the members' page 404s until
#                each post ages out.
#
# Contract for a host_installer: idempotent, root, non-interactive, exit 0 when
# not applicable.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"

OLD_DIR="${SITE_ROOT}/public_html/plugins/persona_browser/media_cache"
NEW_DIR="${SITE_ROOT}/cache/persona_browser"

# Nothing to do is the ordinary case, on every run after the first.
if [[ ! -d "${OLD_DIR}" ]]; then
    exit 0
fi

if [[ "$(id -u)" != "0" ]]; then
    echo "persona_browser: not root - leaving ${OLD_DIR} for the next root moment" >&2
    exit 0
fi

mkdir -p "${NEW_DIR}" || exit 0
chown www-data:www-data "${NEW_DIR}" 2>/dev/null || true
chmod 770 "${NEW_DIR}" 2>/dev/null || true

moved=0
shopt -s nullglob dotglob
for f in "${OLD_DIR}"/*; do
    base="$(basename "${f}")"
    # Never clobber: a file already at the destination is the current one.
    if [[ -e "${NEW_DIR}/${base}" ]]; then
        rm -f "${f}"
        continue
    fi
    if mv -f "${f}" "${NEW_DIR}/${base}" 2>/dev/null; then
        chown www-data:www-data "${NEW_DIR}/${base}" 2>/dev/null || true
        chmod 660 "${NEW_DIR}/${base}" 2>/dev/null || true
        moved=$((moved + 1))
    fi
done
shopt -u nullglob dotglob

# Only when it is empty: a file we could not move is a file whose bytes are
# still the only copy, and an empty-directory check is the one honest test of
# whether this finished.
if rmdir "${OLD_DIR}" 2>/dev/null; then
    echo "persona_browser: moved ${moved} cached image(s) to ${NEW_DIR}; the in-tree cache is gone"
else
    echo "persona_browser: moved ${moved} cached image(s); ${OLD_DIR} is not empty and was kept" >&2
fi

exit 0
