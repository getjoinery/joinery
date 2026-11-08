#!/usr/bin/env bash
# remove_office_editor.sh - the office_editor plugin's host remover: the editor
# leaves this server.
# Version: 1.0 - specs/plugin_host_removal.md WP4
#
# Declared as the plugin's host_remover (plugin.json). A site removal runs it as
# root before the site's folder goes (the host runner's --remove-site mode), and
# a plugin uninstall that leaves the site in place runs it through the
# run_plugin_remover root request (docs/plugin_developer_guide.md § host_remover).
#
# Thin by design: the installer's root helper carries the teardown already,
# `office-editor-ctl remove` (install_office_editor.sh), which switches the
# editor off and removes its packages, the Apache drop-in, the idle-stop cron
# job, the sudoers rule and the helper itself. The editor is one per server, so
# the site's remover removes it; it takes no site name.
#
# Exit 0 when the helper is not here: at the uninstall moment uninstall.php has
# already run `remove` through sudo, and the verb deletes the helper; a site
# container is never served by the editor. Non-zero means the helper left
# something, said above its last line.
#
# Usage:  remove_office_editor.sh [SITENAME] [SITE_ROOT]
set -uo pipefail

HELPER="/usr/local/sbin/office-editor-ctl"

if [[ ! -x "${HELPER}" ]]; then
    echo "remove_office_editor: ${HELPER} is not on this server (the editor is not installed here, or is already gone): nothing to take off."
    exit 0
fi
if [[ "${EUID}" -ne 0 ]]; then
    echo "remove_office_editor: must run as root to run ${HELPER}" >&2
    exit 1
fi

echo "remove_office_editor: ${HELPER} remove"
rc=0
"${HELPER}" remove || rc=$?
if (( rc == 0 )); then
    echo "remove_office_editor: the editor has left this server"
    exit 0
fi
echo "remove_office_editor: left: office-editor-ctl remove exited ${rc} (above)"
exit "${rc}"
