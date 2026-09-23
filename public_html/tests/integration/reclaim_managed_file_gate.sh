#!/bin/bash
# @joinery-test
# name: reclaim_managed_file
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/reclaim_managed_file.sh]
#
# reclaim_managed_file.sh is what the agent's reclaim_managed_file operate
# word runs as root (specs/agent_recipes_and_vocabulary.md, Host files). This
# gate pins its contract against a fixture tree, unprivileged, with a stub
# runner: only a name on the resettable list is accepted; the file is moved
# aside to a dated copy and never deleted; the owning installer runs through
# the runner's --only; a file the owner does not write back is put back;
# jail.local's reset is its removal; a site file is refused on a machine with
# no site; a link is never moved.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SRC="$ROOT/maintenance_scripts/sysadmin_tools/reclaim_managed_file.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; exit 0; fi

# A site tree whose runner is a stub: it records its arguments and, when asked
# for host_housekeeping.sh, writes the mpm file the way the real one would.
SITE="$T/html/mysite"; FS="$T/fs"
mkdir -p "$SITE/public_html" "$SITE/config" "$SITE/maintenance_scripts/sysadmin_tools" "$SITE/maintenance_scripts/install_tools"
echo '<?php' > "$SITE/config/Globalvars_site.php"
REC="$FS/var/lib/joinery/reclaimed"
cp "$SRC" "$SITE/maintenance_scripts/sysadmin_tools/"
cat > "$SITE/maintenance_scripts/install_tools/_plugin_installers_start.sh" <<STUB
#!/bin/bash
echo "\$*" >> "$T/runner.calls"
case "\$*" in
  *--only=host_housekeeping.sh*) [ -e "$FS/etc/apache2/mods-available/mpm_event.conf" ] || echo platform > "$FS/etc/apache2/mods-available/mpm_event.conf" ;;
esac
echo "core installers: \${1#--only=}: ok"
STUB
mkdir -p "$FS/etc/apache2/mods-available" "$FS/etc/fail2ban" "$FS/etc/cron.d"
S="$SITE/maintenance_scripts/sysadmin_tools/reclaim_managed_file.sh"
run() { JOINERY_RECLAIM_ROOT="$FS" bash "$S" "$@"; }

echo "=== Refusals: nothing moves, the runner never runs ==="
for bad in "" "apache2_conf" "postfix_main" "sysctl_security" "../x" "apache_site_ssl" "docker_daemon"; do
    rm -f "$T/runner.calls"
    out="$(run "$bad" 2>&1)"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' ran no runner" "$([ -f "$T/runner.calls" ] && echo ran || echo none)" "none"
done

echo "=== A hand-edited file: moved aside, the owner writes the platform's ==="
echo "hand edited" > "$FS/etc/apache2/mods-available/mpm_event.conf"
out="$(run apache_mpm_event 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "the runner ran the owner alone, through --only" "$(cat "$T/runner.calls")" "--only=host_housekeeping.sh"
chk "the owner's version is in place" "$(cat "$FS/etc/apache2/mods-available/mpm_event.conf")" "platform"
chk "the edit is kept, dated, under the reclaimed directory" "$(cat "$REC"/etc_apache2_mods-available_mpm_event.conf.reclaimed-* 2>/dev/null)" "hand edited"
chk "and nothing is left beside the file for a service to read" "$(ls "$FS/etc/apache2/mods-available" | grep -c reclaimed)" "0"
chk "the transcript says what moved where and where the previous is" "$(printf '%s\n' "$out" | grep -c '^reclaim: moved .*mpm_event.conf to .*/var/lib/joinery/reclaimed/etc_apache2_mods-available_mpm_event.conf.reclaimed-[0-9]\{14\}$')/$(printf '%s\n' "$out" | grep -c 'is the platform.s again')" "1/1"
chk "the reclaimed directory is root's alone" "$(stat -c %a "$REC")" "700"

echo "=== The owner does not write it here: the copy goes back ==="
echo "* * * * * root keepalive" > "$FS/etc/cron.d/joinery-agent"
rm -f "$T/runner.calls"
out="$(run cron_agent 2>&1)"
chk "the runner was asked for install_agent.sh" "$(cat "$T/runner.calls")" "--only=install_agent.sh"
chk "the file is back as it was" "$(cat "$FS/etc/cron.d/joinery-agent")" "* * * * * root keepalive"
chk "no dated copy is left behind" "$(ls "$REC" | grep -c 'etc_cron.d_joinery-agent')" "0"
chk "and the transcript says so" "$(printf '%s\n' "$out" | grep -c 'the moved copy is back in place')" "1"

echo "=== jail.local: the reset is its removal ==="
printf '[DEFAULT]\nbantime = 1h\n' > "$FS/etc/fail2ban/jail.local"
run fail2ban_jail_local >/dev/null 2>&1
chk "jail.local stays removed" "$([ -e "$FS/etc/fail2ban/jail.local" ] && echo present || echo absent)" "absent"
chk "its copy is kept" "$(ls "$REC" | grep -c '^etc_fail2ban_jail.local.reclaimed-')" "1"

echo "=== A link is never moved ==="
ln -s /etc/hostname "$FS/etc/cron.d/joinery-mysite"
out="$(run cron_site 2>&1)"; rc=$?
chk "a link is refused, exit 2" "$rc:$([ -L "$FS/etc/cron.d/joinery-mysite" ] && echo still-a-link)" "2:still-a-link"

echo "=== A site vhost: a one-shot marker for render_vhost.sh, removed after the run ==="
echo "<VirtualHost *:80>" > "$FS/etc/apache2/sites-available/mysite.conf" 2>/dev/null || { mkdir -p "$FS/etc/apache2/sites-available"; echo "<VirtualHost *:80>" > "$FS/etc/apache2/sites-available/mysite.conf"; }
cat > "$SITE/maintenance_scripts/install_tools/_plugin_installers_start.sh" <<STUB
#!/bin/bash
echo "\$*" >> "$T/runner.calls"
[ -f "$REC/vhost-pending.mysite" ] && echo "marker names: \$(cat "$REC/vhost-pending.mysite")"
echo "core installers: \${1#--only=}: ok"
STUB
out="$(run apache_site 2>&1)"
chk "the runner saw the marker naming the copy" "$(printf '%s\n' "$out" | grep -c "marker names: $REC/etc_apache2_sites-available_mysite.conf.reclaimed-")" "1"
chk "and the marker is gone afterwards" "$([ -e "$REC/vhost-pending.mysite" ] && echo left || echo gone)" "gone"

echo "=== The move waits for the runner's lock ==="
echo "held" > "$FS/etc/apache2/mods-available/mpm_event.conf"
( exec 9>>"$SITE/cache/host_installers.lock"; flock 9; sleep 3 ) &
sleep 1
start=$(date +%s)
run apache_mpm_event >/dev/null 2>&1
chk "it waited for the holder to let go" "$(( $(date +%s) - start >= 1 ))" "1"
wait

echo "=== A machine with no site: host files only ==="
MROOT="$T/bundle"; mkdir -p "$MROOT/public_html/includes" "$MROOT/maintenance_scripts/sysadmin_tools" "$MROOT/maintenance_scripts/install_tools"
cp "$SRC" "$MROOT/maintenance_scripts/sysadmin_tools/"
cp "$SITE/maintenance_scripts/install_tools/_plugin_installers_start.sh" "$MROOT/maintenance_scripts/install_tools/"
rm -f "$T/runner.calls"
out="$(JOINERY_RECLAIM_ROOT="$FS" bash "$MROOT/maintenance_scripts/sysadmin_tools/reclaim_managed_file.sh" logrotate_site 2>&1)"; rc=$?
chk "a site file on a siteless machine is refused" "$rc:$(printf '%s' "$out" | grep -c 'belongs to a site')" "2:1"
JOINERY_RECLAIM_ROOT="$FS" bash "$MROOT/maintenance_scripts/sysadmin_tools/reclaim_managed_file.sh" apache_mpm_event >/dev/null 2>&1
chk "a host file runs the runner in --machine mode" "$(cat "$T/runner.calls")" "--machine --only=host_housekeeping.sh"

echo "=== Static pins ==="
CODE="$(grep -v -E '^\s*#' "$SRC")"
chk "nothing is deleted but the one-shot marker and copies past the prune age" "$(printf '%s\n' "$CODE" | grep -c -E '\brm\b')/$(printf '%s\n' "$CODE" | grep -c 'rm -f "\${RECLAIM_DIR}/vhost-pending')/$(printf '%s\n' "$CODE" | grep -c -- "-mtime +\"\${PRUNE_DAYS}\" -delete")" "1/1/1"
chk "the list never names apache2.conf, a mail file, sysctl, apt or docker" "$(printf '%s\n' "$CODE" | grep -c -E 'apache2\.conf|postfix|opendkim|sysctl|apt\.conf|daemon\.json|le-ssl')" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SRC")" "exit 0"

echo
echo "reclaim_managed_file gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
