#!/usr/bin/env bash
# remove_mail_gateway.sh - the mail_apps plugin's host remover: this site leaves
# the mail gateway on this machine.
# Version: 1.0 - specs/plugin_host_removal.md WP4
#
# Declared as the plugin's host_remover (plugin.json). A site removal runs it as
# root before the site's folder goes (the host runner's --remove-site mode), and
# a plugin uninstall that leaves the site in place runs it through the
# run_plugin_remover root request (docs/plugin_developer_guide.md § host_remover).
#
# Thin by design: the installer's root helper carries the teardown already,
# `joinery-mail-gateway remove SITE` (install_mail_gateway.sh), which forgets
# this site's entry, credential and certificate and, once no site on the
# machine has the plugin, takes the unit, the binary, the joinery-mailgw user,
# the renewal hook, the sudoers rule and the helper itself off. The site is
# named as MailAppGateway::siteName() names it: the basename of the site root.
#
# Exit 0 when the helper is not here: at the uninstall moment uninstall.php has
# already run `remove` through sudo, and the verb deletes the helper with the
# last site; a site container has no helper of its own (its host's converge
# timer forgets a container whose run spec is gone). Non-zero means the helper
# left something, said above its last line.
#
# Usage:  remove_mail_gateway.sh [SITENAME] [SITE_ROOT]
set -uo pipefail

HELPER="/usr/local/sbin/joinery-mail-gateway"
SITE_ROOT="${2:-}"
[[ -n "${SITE_ROOT}" ]] || SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
SITE="$(basename "${SITE_ROOT}")"

if [[ ! -x "${HELPER}" ]]; then
    echo "remove_mail_gateway: ${HELPER} is not on this machine (the gateway is not installed here, or is already gone): nothing to take off."
    exit 0
fi
if [[ "${EUID}" -ne 0 ]]; then
    echo "remove_mail_gateway: must run as root to run ${HELPER}" >&2
    exit 1
fi
# The shape install.sh gives a site name; the helper builds paths from it.
if [[ ! "${SITE}" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]]; then
    echo "remove_mail_gateway: left: '${SITE:0:60}' is not a site name, so the gateway was not asked to forget it"
    exit 1
fi

echo "remove_mail_gateway: ${HELPER} remove ${SITE}"
rc=0
"${HELPER}" remove "${SITE}" || rc=$?
if (( rc == 0 )); then
    echo "remove_mail_gateway: ${SITE} has left the mail gateway"
    exit 0
fi
echo "remove_mail_gateway: left: the gateway helper's remove exited ${rc} (above)"
exit "${rc}"
