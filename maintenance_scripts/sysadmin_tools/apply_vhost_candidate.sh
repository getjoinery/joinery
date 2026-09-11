#!/bin/bash
# apply_vhost_candidate.sh 1.0 - drive one node's hand-apply of the vhost
# candidate render_vhost.sh left behind (specs/read_only_tree.md).
#
# Run from the management box (SSH_KEY=~/.ssh/<key> if the default key is not on the node):
#   bash apply_vhost_candidate.sh user1@45.79.204.178 jeremytunnell jeremytunnell.com
#
# Steps, each one printed before it runs:
#   1. read-only: show the diff between the live vhost and the candidate
#   2. ask before touching anything
#   3. on the node as root: back up the live vhost, disable the certbot
#      <site>-le-ssl vhost if enabled (it would shadow the managed :443 host),
#      copy the candidate over, apache2ctl -t; on failure put everything back
#      and stop; on success graceful reload, then restart postfix to resync its
#      chroot libraries (the upgrade transcript warned they differ)
#   4. from here: probe the site - / and /login must be 200, PHP under
#      static_files must be refused (403)
#   5. on the node: show the converger's last run and the request queue
set -euo pipefail

TARGET="${1:?usage: $0 user@host site_name domain [ssh-port]}"
SITE="${2:?site name (the /var/www/html/<site> directory and the vhost file name)}"
DOMAIN="${3:?public domain to probe}"
PORT="${4:-22}"
SSH=(ssh -t -p "${PORT}" -o ConnectTimeout=15)
[[ -n "${SSH_KEY:-}" ]] && SSH+=(-i "${SSH_KEY}")
SSH+=("${TARGET}")

CONF="/etc/apache2/sites-available/${SITE}.conf"
NEW="${CONF}.new"
LESSL="${SITE}-le-ssl"
SITE_ROOT="/var/www/html/${SITE}"

say() { printf '\n== %s\n' "$*"; }

say "1. Diff of live vhost vs candidate on ${TARGET}"
"${SSH[@]}" "sudo test -f '${NEW}' || { echo 'no candidate at ${NEW} - nothing to apply'; exit 3; }
echo '--- sites-enabled:'; ls /etc/apache2/sites-enabled/
echo '--- diff (live -> candidate):'; sudo diff -u '${CONF}' '${NEW}' || true"

say "2. Confirm"
read -r -p "Apply the candidate on ${TARGET}? [y/N] " answer
[[ "${answer}" == "y" || "${answer}" == "Y" ]] || { echo "not applied"; exit 0; }

say "3. Apply on the node"
REMOTE=$(cat <<REMOTE_SCRIPT
set -euo pipefail
STAMP=\$(date +%Y%m%d-%H%M%S)
BACKUP="${CONF}.before-\${STAMP}"
cp -a '${CONF}' "\${BACKUP}"
echo "backup: \${BACKUP}"
LESSL_WAS_ON=0
if [ -e '/etc/apache2/sites-enabled/${LESSL}.conf' ]; then
    a2dissite '${LESSL}' >/dev/null && LESSL_WAS_ON=1 && echo "disabled ${LESSL} (it would shadow the managed :443 host)"
fi
cp '${NEW}' '${CONF}'
if ! apache2ctl -t; then
    echo "apache2ctl -t FAILED - restoring the previous vhost"
    cp -a "\${BACKUP}" '${CONF}'
    [ "\${LESSL_WAS_ON}" = 1 ] && a2ensite '${LESSL}' >/dev/null && echo "re-enabled ${LESSL}"
    apache2ctl -t
    exit 4
fi
apache2ctl graceful
echo "applied ${CONF} and reloaded Apache"
rm -f '${NEW}'
systemctl restart postfix && echo "postfix restarted (chroot libraries resynced)"
REMOTE_SCRIPT
)
# Passed as an argument, not on stdin: sudo may need the terminal for its
# password prompt, and a script on stdin would be eaten by that prompt.
"${SSH[@]}" "echo '$(printf '%s' "${REMOTE}" | base64 -w0)' | base64 -d | sudo bash"

say "4. Probe https://${DOMAIN} from here"
fail=0
probe() {
    local path="$1" want="$2" code
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 "https://${DOMAIN}${path}" || echo 000)
    if [[ "${code}" == "${want}" ]]; then printf '  ok   %-28s %s\n' "${path}" "${code}"
    else printf '  FAIL %-28s %s (wanted %s)\n' "${path}" "${code}" "${want}"; fail=1; fi
}
probe / 200
probe /login 200
probe /static_files/index.php 403
probe /static_files/x.phtml 403

say "5. Converger state on the node"
"${SSH[@]}" "echo -n 'last run: '; cat '${SITE_ROOT}/cache/host_converger.last' 2>/dev/null || echo '(none yet)'
echo -n 'pending requests: '; ls '${SITE_ROOT}/cache/root_requests/'*.json 2>/dev/null | wc -l
systemctl is-active joinery-host-converger.timer 2>/dev/null || true"

if [[ "${fail}" == 1 ]]; then
    echo; echo "A probe failed. The previous vhost is still on the node as the .before-* backup."
    exit 5
fi
echo; echo "Done. Next: save a help doc at https://${DOMAIN}/admin/admin_help_edit and watch the request panel reach done within a minute."
