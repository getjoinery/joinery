#!/bin/bash
# @joinery-test
# name: site_quiet
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/_site_state.sh, maintenance_scripts/sysadmin_tools/site_quiet.sh, maintenance_scripts/install_tools/_plugin_installers_start.sh]
#
# The quiet state (specs/site_copy.md WP5): a site that runs nothing and sends
# nothing, enforced by the machine. Driven unprivileged against a fixture /etc
# (JOINERY_SITE_STATE_ROOT) with nft, systemctl, apache2ctl, postconf, postfix
# and pgrep stubbed on PATH, each recording what it was asked. What the real
# kernel and Apache do with the files is the live check's (needs root).

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/install_tools"
SYSTOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi
if ! id -u www-data >/dev/null 2>&1; then echo "  SKIP: no www-data account here"; echo "RESULT: PASS 0 0"; exit 0; fi

# --- Stubs ---------------------------------------------------------------
B="$T/bin"; ST="$T/stub"; mkdir -p "$B" "$ST/pf"
cat > "$B/nft" <<EOF
#!/bin/bash
echo "nft \$*" >> "$ST/calls"
case "\$1" in
  -f) cp "\$2" "$ST/nft_loaded" ;;
  list) [ -f "$ST/nft_loaded" ] ;;
  delete) rm -f "$ST/nft_loaded" ;;
esac
EOF
cat > "$B/systemctl" <<EOF
#!/bin/bash
echo "systemctl \$*" >> "$ST/calls"
args=("\$@"); [ "\${args[1]:-}" = "--quiet" ] && unit="\${args[2]}" || unit="\${args[1]:-}"
[ "\$2" = "--now" ] && unit="\$3"
case "\$1" in
  is-enabled|is-active) [ -f "$ST/on.\$unit" ] ;;
  enable) touch "$ST/on.\$unit" ;;
  disable) rm -f "$ST/on.\$unit" ;;
  *) exit 0 ;;
esac
EOF
cat > "$B/apache2ctl" <<EOF
#!/bin/bash
echo "apache2ctl \$*" >> "$ST/calls"; exit 0
EOF
cat > "$B/postconf" <<EOF
#!/bin/bash
echo "postconf \$*" >> "$ST/calls"
case "\$1" in
  -M) cat "$ST/master" ;;
  -Ph) f="$ST/pf/\$(echo "\$2" | tr / _)"; [ -f "\$f" ] && cat "\$f"; exit 0 ;;
  -P) k="\${2%%=*}"; v="\${2#*=}"; printf '%s\n' "\$v" > "$ST/pf/\$(echo "\$k" | tr / _)" ;;
  -PX) rm -f "$ST/pf/\$(echo "\$2" | tr / _)" ;;
esac
EOF
cat > "$B/postfix" <<EOF
#!/bin/bash
echo "postfix \$*" >> "$ST/calls"; exit 0
EOF
cat > "$B/pgrep" <<EOF
#!/bin/bash
[ -s "$ST/pgrep" ] && cat "$ST/pgrep"
EOF
chmod +x "$B"/*
export PATH="$B:$PATH"
printf 'smtp      inet  n  -  y  -  -  smtpd\nsubmission inet n  -  y  -  -  smtpd -o smtpd_client_restrictions=$mua_client_restrictions\npickup    unix  n  -  y  60 1  pickup\n' > "$ST/master"
printf '%s\n' '$mua_client_restrictions' > "$ST/pf/submission_inet_smtpd_client_restrictions"
touch "$ST/on.certbot.timer"

# --- A fixture site and /etc ----------------------------------------------
R="$T/fs"; mkdir -p "$R/etc/cron.d" "$R/etc/apache2/conf-available" "$R/etc/apache2/conf-enabled" "$R/etc/systemd/system"
SITE="$T/html/qsite"
mkdir -p "$SITE/maintenance_scripts/install_tools" "$SITE/maintenance_scripts/sysadmin_tools" "$SITE/public_html"
cp "$TOOLS/_site_state.sh" "$SITE/maintenance_scripts/install_tools/"
cp "$SYSTOOLS/site_quiet.sh" "$SYSTOOLS/host_runner_lock.sh" "$SITE/maintenance_scripts/sysadmin_tools/"
echo "* * * * * www-data php my own edit" > "$R/etc/cron.d/joinery-qsite"
export JOINERY_SITE_STATE_ROOT="$R" JOINERY_SITE_STATE_WAIT=2 JOINERY_LOCK_WAIT_SECONDS=2
Q="$SITE/maintenance_scripts/sysadmin_tools/site_quiet.sh"
SD="$R/etc/joinery/sites/qsite"

echo "=== on: quiet switchover, every measure ==="
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "the state file says quiet switchover" "$(cat "$SD/state")" "quiet switchover"
chk "the state directory is root's alone" "$(stat -c %a "$SD")" "700"
chk "the cron file is held, not deleted" "$([ -e "$R/etc/cron.d/joinery-qsite" ] && echo live || echo gone):$(cat "$SD/held/cron.d/joinery-qsite")" "gone:* * * * * www-data php my own edit"
chk "the firewall rule is loaded" "$(grep -c 'meta skuid' "$ST/nft_loaded")" "2"
uid_www="$(id -u www-data)"
chk "it names the web user's uid" "$(grep -c "skuid { ${uid_www}" "$ST/nft_loaded")" "2"
chk "loopback is let through before the reject" "$(grep -n -m1 'oifname "lo" accept' "$ST/nft_loaded" | cut -d: -f1)" "$(( $(grep -n -m1 'meta skuid' "$ST/nft_loaded" | cut -d: -f1) - 2 ))"
chk "and so are replies to a connection someone else opened, so a visitor sees the 503 and the owner the look" "$(grep -n -m1 'ct direction reply accept' "$ST/nft_loaded" | cut -d: -f1)" "$(( $(grep -n -m1 'meta skuid' "$ST/nft_loaded" | cut -d: -f1) - 1 ))"
chk "the file replaces the table atomically" "$(grep -c '^delete table inet joinery_site_state' "$SD/quiet.nft")" "1"
chk "the boot unit is written and enabled" "$([ -f "$R/etc/systemd/system/joinery-site-state.service" ] && echo yes):$([ -f "$ST/on.joinery-site-state.service" ] && echo on)" "yes:on"
chk "after any other firewall that loads a ruleset at boot" "$(grep -c '^After=local-fs.target nftables.service firewalld.service$' "$R/etc/systemd/system/joinery-site-state.service")" "1"
chk "and it runs before the web server" "$(grep -c '^Before=network-pre.target apache2.service' "$R/etc/systemd/system/joinery-site-state.service")" "1"
chk "the web drop-in is enabled" "$(readlink "$R/etc/apache2/conf-enabled/joinery-quiet-qsite.conf")" "../conf-available/joinery-quiet-qsite.conf"
chk "switchover: everyone gets the maintenance page" "$(grep -c 'Redirect 503' "$R/etc/apache2/conf-available/joinery-quiet-qsite.conf"):$(grep -c 'joinery_look' "$R/etc/apache2/conf-available/joinery-quiet-qsite.conf")" "1:0"
chk "Apache was checked, then reloaded" "$(grep -c 'apache2ctl configtest' "$ST/calls"):$(grep -c 'systemctl reload apache2' "$ST/calls")" "1:1"
chk "certbot's timer is off, and remembered as on" "$([ -f "$ST/on.certbot.timer" ] && echo on || echo off):$([ -f "$SD/held/certbot.timer" ] && echo held)" "off:held"
chk "every smtpd service defers" "$(cat "$ST/pf/smtp_inet_smtpd_client_restrictions"):$(cat "$ST/pf/submission_inet_smtpd_client_restrictions")" "defer:defer"
chk "and what each had before is kept" "$(cat "$SD/held/postfix" | tr '\t' '|' | tr '\n' ' ')" 'smtp/inet|- submission/inet|$mua_client_restrictions '
chk "no look secret for a switch-over" "$([ -e "$SD/look_secret" ] && echo yes || echo no)" "no"

echo "=== on again: nothing new, and a table deleted by hand is back ==="
rm -f "$ST/nft_loaded"; : > "$ST/calls"
echo "* * * * * www-data php platform default" > "$R/etc/cron.d/joinery-qsite"
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "the rule is loaded again" "$([ -f "$ST/nft_loaded" ] && echo yes)" "yes"
chk "Apache is not reloaded when nothing changed" "$(grep -c 'systemctl reload apache2' "$ST/calls")" "0"
chk "a cron file written meanwhile is removed, the held one kept" "$([ -e "$R/etc/cron.d/joinery-qsite" ] && echo live || echo gone):$(cat "$SD/held/cron.d/joinery-qsite")" "gone:* * * * * www-data php my own edit"
chk "Postfix is not touched again" "$(grep -c 'postconf -P ' "$ST/calls")" "0"

echo "=== on waits for command-line PHP ==="
echo "4242" > "$ST/pgrep"
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "a worker still running when the wait ends: exit 3, and named" "$rc:$(printf '%s' "$out" | grep -c 'pids 4242')" "3:1"
chk "the site stays quiet" "$(cat "$SD/state")" "quiet switchover"
: > "$ST/pgrep"

echo "=== off: everything back as it was ==="
: > "$ST/calls"
out="$(bash "$Q" off 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "no state file" "$([ -e "$SD/state" ] && echo yes || echo no)" "no"
chk "the owner's cron file is back as it was" "$(cat "$R/etc/cron.d/joinery-qsite")" "* * * * * www-data php my own edit"
chk "the rule is gone, and so is the unit" "$([ -f "$ST/nft_loaded" ] && echo loaded || echo gone):$([ -f "$R/etc/systemd/system/joinery-site-state.service" ] && echo unit || echo nounit)" "gone:nounit"
chk "the web drop-in is gone" "$(ls "$R/etc/apache2/conf-enabled" "$R/etc/apache2/conf-available" | grep -c quiet)" "0"
chk "certbot's timer is on again" "$([ -f "$ST/on.certbot.timer" ] && echo on || echo off)" "on"
chk "Postfix: smtp has no override, submission its own again" "$([ -e "$ST/pf/smtp_inet_smtpd_client_restrictions" ] && echo set || echo unset):$(cat "$ST/pf/submission_inet_smtpd_client_restrictions")" 'unset:$mua_client_restrictions'
chk "the state directory is gone" "$([ -e "$SD" ] && echo yes || echo no)" "no"

echo "=== A timer that was off stays off ==="
rm -f "$ST/on.certbot.timer"
bash "$Q" on >/dev/null 2>&1; bash "$Q" off >/dev/null 2>&1
chk "certbot's timer was never turned on" "$([ -f "$ST/on.certbot.timer" ] && echo on || echo off)" "off"
touch "$ST/on.certbot.timer"

echo "=== quiet copy: set by the installer, never by on, never cleared by a bare off ==="
. "$TOOLS/_site_state.sh"
site_state_init qsite
QKEY="$(printf 'k%.0s' $(seq 32) | base64)"
site_state_write copy 417 "$QKEY"
site_state_assert copy >/dev/null 2>&1
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "on over a copy: refused" "$rc:$(cat "$SD/state")" "2:quiet copy"
out="$(bash "$Q" off 2>&1)"; rc=$?
chk "off over a copy: refused" "$rc:$(cat "$SD/state")" "2:quiet copy"
chk "the copy remembers its source's node id" "$(cat "$SD/copy_of")" "417"
chk "and its source's agent key (WP4)" "$(cat "$SD/copy_of_key")" "$QKEY"
secret="$(cat "$SD/look_secret")"
chk "a copy has a look secret, 32 hex, 0600" "$([[ "$secret" =~ ^[0-9a-f]{32}$ ]] && echo ok):$(stat -c %a "$SD/look_secret")" "ok:600"
CONF="$R/etc/apache2/conf-available/joinery-quiet-qsite.conf"
chk "the look path sets the cookie" "$(grep -c "<Location \"/.joinery-look/${secret}\">" "$CONF"):$(grep -c "Set-Cookie \"joinery_look=${secret};" "$CONF")" "1:1"
chk "without the cookie, 503" "$(grep -c "HTTP_COOKIE} =~ /(?:^|;\\\\s\*)joinery_look=${secret}(?:;|\\$)/" "$CONF"):$(grep -c 'Redirect 503' "$CONF")" "1:1"
chk "on --copy-promoted is refused" "$(bash "$Q" on --copy-promoted >/dev/null 2>&1; echo $?)" "2"
# The runs its source vouched for (WP2), and the last export it took (WP4), go
# with the copy's other records: a promoted copy trusts its source's key no more.
printf '%s chain-20260927_120000\n' "$(printf '0%.0s' $(seq 64))" > "$SD/vouched"; chmod 600 "$SD/vouched"
echo "2026-10-01T00:00:00Z" > "$SD/copy_import_issued"; chmod 600 "$SD/copy_import_issued"
out="$(bash "$Q" off --copy-promoted 2>&1)"; rc=$?
chk "off --copy-promoted clears a copy, its vouched runs, source key and last import included" "$rc:$([ -e "$SD" ] && echo left || echo gone)" "0:gone"

echo "=== A state file that says anything else is quiet, the strict way ==="
site_state_write switchover; echo "garbage" > "$SD/state"
chk "read as switchover" "$(site_state_read 2>/dev/null)" "switchover"
echo 417 > "$SD/copy_of"
chk "unless a copy_of record is beside it: then a copy" "$(site_state_read 2>/dev/null)" "copy"
chk "and a bare off does not clear it" "$(bash "$Q" off >/dev/null 2>&1; echo $?):$([ -e "$SD/state" ] && echo kept)" "2:kept"
rm -rf "$SD"

echo "=== The converger's gate: a quiet site runs no installer ==="
# The real runner against a temp tree (as host_converger_gate.sh does): the
# installers skip without root, but each prints its 'running' line first.
C="$T/html/csite"
mkdir -p "$C/public_html/plugins" "$C/config" "$C/cache" "$C/maintenance_scripts/install_tools"
cp "$TOOLS"/*.sh "$C/maintenance_scripts/install_tools/"
echo 0.8.384 > "$C/public_html/VERSION"; echo '<?php' > "$C/config/Globalvars_site.php"
RUNNER="$C/maintenance_scripts/install_tools/_plugin_installers_start.sh"
CD="$R/etc/joinery/sites/csite"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"
chk "a live site converges" "$([ "$(printf '%s' "$out" | grep -c 'core installers: running')" -gt 0 ] && echo yes)" "yes"
. "$TOOLS/_site_state.sh"; site_state_init csite; site_state_write copy 417
echo "* * * * * www-data php csite" > "$R/etc/cron.d/joinery-csite"
: > "$ST/calls"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"; rc=$?
chk "quiet: exit 0, and not one installer runs" "$rc:$(printf '%s' "$out" | grep -c 'core installers: running')" "0:0"
chk "the measures are asserted" "$([ -f "$CD/quiet.nft" ] && echo nft):$([ -L "$R/etc/apache2/conf-enabled/joinery-quiet-csite.conf" ] && echo web):$([ -e "$R/etc/cron.d/joinery-csite" ] && echo cron || echo held)" "nft:web:held"
chk "the run is recorded as quiet" "$(cut -d' ' -f2 "$C/cache/host_converger.last")" "quiet-copy"
chk "no converge stamp is kept while quiet" "$([ -e "$C/cache/host_converger.stamp" ] && echo kept || echo none)" "none"
out="$(bash "$RUNNER" --only=render_vhost.sh --site-root="$C" 2>&1)"
chk "--only runs nothing either" "$(printf '%s' "$out" | grep -c 'core installers: running')" "0"
mv "$C/maintenance_scripts/install_tools/_site_state.sh" "$T/helper.aside"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"; rc=$?
chk "a quiet site whose tree lacks the helper runs nothing (fails closed)" "$rc:$(printf '%s' "$out" | grep -c 'core installers: running'):$(cut -d' ' -f2 "$C/cache/host_converger.last")" "0:0:quiet-no-helper"
mv "$T/helper.aside" "$C/maintenance_scripts/install_tools/_site_state.sh"
rm -f "$CD/state"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"
chk "state gone: its leftovers are cleared" "$(printf '%s' "$out" | grep -c 'no longer quiet'):$([ -e "$CD" ] && echo left || echo gone):$(cat "$R/etc/cron.d/joinery-csite")" "1:gone:* * * * * www-data php csite"
chk "and the site converges at once, with no release change" "$([ "$(printf '%s' "$out" | grep -c 'core installers: running')" -gt 0 ] && echo yes)" "yes"

echo "=== Not root and no fixture: nothing to do, refused ==="
out="$(JOINERY_SITE_STATE_ROOT= bash "$Q" on 2>&1)"; rc=$?
chk "refused, exit 2" "$rc" "2"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
