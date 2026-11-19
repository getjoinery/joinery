#!/bin/bash
# @joinery-test
# name: sandbox_teardown
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/site_housekeeping.sh]
#
# site_housekeeping.sh takes the retired sandboxed-plugin runner off a machine
# (specs/wasm_plugins_removal.md WP5): the site's service or keepalive, its
# supervise script, log and socket folder; each plugin folder of the shape the
# runner ran (exactly manifest.json and one .wasm); and, once no site has a
# service or keepalive left, the binary and its user. It deletes as root from
# a tree the web user may write, so it never follows a link and never deletes
# recursively inside plugins/. Driven unprivileged against a fixture through
# JOINERY_SITE_HOUSEKEEPING_ROOT; machine commands are recorded, not run.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/install_tools/site_housekeeping.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; exit 0; fi

RAN="$T/ran"; : > "$RAN"
REC="$T/rec.sh"; printf '#!/bin/bash\nprintf "%%s\\n" "$*" >> "%s"\n' "$RAN" > "$REC"; chmod +x "$REC"
run() { JOINERY_SITE_HOUSEKEEPING_ROOT="$1" JOINERY_SITE_HOUSEKEEPING_RUN="$REC" bash "$SCRIPT" mysite "$2" 2>&1; }
fixture() {   # $1 = fs root, $2 = site root
    mkdir -p "$1/etc/logrotate.d" "$1/etc/cron.d" "$1/etc/systemd/system" "$1/usr/local/bin" "$1/var/log" \
        "$2/public_html/plugins" "$2/run/sandbox-host" "$2/cache/sandbox"
    echo "joinery-sbx:x:995:995::/nonexistent:/usr/sbin/nologin" > "$1/etc/passwd"
    echo bin > "$1/usr/local/bin/sandbox-host"
    : > "$2/run/sandbox-host/host.sock"
    : > "$2/cache/sandbox/ui_slot_0.lock"
}
old_plugin() {   # $1 = folder
    mkdir -p "$1"; echo '{}' > "$1/manifest.json"; printf '\0asm' > "$1/$(basename "$1").wasm"
}

echo "=== A site with a service, a module plugin, a present-shape plugin and a native plugin ==="
R="$T/fs"; SITE="$T/html/mysite"; fixture "$R" "$SITE"
echo unit > "$R/etc/systemd/system/joinery-sandbox-host-mysite.service"
echo log > "$R/var/log/joinery-sandbox-host-mysite.log"
old_plugin "$SITE/public_html/plugins/notifier"
mkdir -p "$SITE/public_html/plugins/newshape/src"; echo '{}' > "$SITE/public_html/plugins/newshape/manifest.json"
echo '<?php' > "$SITE/public_html/plugins/newshape/src/main.php"
mkdir -p "$SITE/public_html/plugins/native"; echo '{}' > "$SITE/public_html/plugins/native/plugin.json"
echo '<?php' > "$SITE/public_html/plugins/native/x.php"
out="$(run "$R" "$SITE")"; rc=$?
chk "exit 0" "$rc" "0"
chk "the unit file is gone" "$([ -e "$R/etc/systemd/system/joinery-sandbox-host-mysite.service" ] && echo there || echo gone)" "gone"
chk "the service was stopped and disabled, then systemd reloaded" \
    "$(grep -c -x -e 'systemctl disable --now joinery-sandbox-host-mysite' -e 'systemctl daemon-reload' "$RAN")" "2"
chk "its log is gone" "$([ -e "$R/var/log/joinery-sandbox-host-mysite.log" ] && echo there || echo gone)" "gone"
chk "the socket folder is gone" "$([ -e "$SITE/run/sandbox-host" ] && echo there || echo gone)" "gone"
chk "and its emptied parent" "$([ -e "$SITE/run" ] && echo there || echo gone)" "gone"
chk "the module plugin folder is gone" "$([ -e "$SITE/public_html/plugins/notifier" ] && echo there || echo gone)" "gone"
chk "it says so" "$(grep -c 'removed plugins/notifier' <<<"$out")" "1"
chk "the present-shape plugin is untouched" "$(ls "$SITE/public_html/plugins/newshape" | paste -sd,):$(cat "$SITE/public_html/plugins/newshape/src/main.php")" "manifest.json,src:<?php"
chk "the native plugin is untouched" "$(ls "$SITE/public_html/plugins/native" | paste -sd,)" "plugin.json,x.php"
chk "the UI lock folder stays" "$([ -e "$SITE/cache/sandbox/ui_slot_0.lock" ] && echo there || echo gone)" "there"
chk "the binary is gone" "$([ -e "$R/usr/local/bin/sandbox-host" ] && echo there || echo gone)" "gone"
chk "what ran as the user is killed, then the user removed" \
    "$(grep -n -x -e 'pkill -KILL -u joinery-sbx' -e 'userdel joinery-sbx' "$RAN" | cut -d: -f2 | paste -sd,)" "pkill -KILL -u joinery-sbx,userdel joinery-sbx"

echo "=== Again: nothing to do, nothing said, nothing run ==="
echo "" > "$R/etc/passwd"; : > "$RAN"
out="$(run "$R" "$SITE")"; rc=$?
chk "exit 0" "$rc" "0"
chk "silent about the runner" "$(grep -c -i 'sandbox\|joinery-sbx\|plugins/' <<<"$out")" "0"
chk "no machine command" "$(wc -l < "$RAN" | tr -d ' ')" "0"

echo "=== No systemd: the keepalive and what it started ==="
R="$T/fsk"; SITE="$T/htmlk/mysite"; fixture "$R" "$SITE"; : > "$RAN"
echo cron > "$R/etc/cron.d/joinery-sandbox-host-mysite"
echo sup > "$R/usr/local/bin/joinery-sandbox-host-supervise-mysite"
out="$(run "$R" "$SITE")"
chk "the keepalive and its script are gone" "$(ls "$R/etc/cron.d" "$R/usr/local/bin" | grep -c 'sandbox-host')" "0"
chk "the program is stopped by this site's socket path" "$(grep -c -x -F -- "pkill -u joinery-sbx -f -- -socket $SITE/run/sandbox-host/host.sock" "$RAN")" "1"

echo "=== Another site still has its service: the machine's share stays ==="
R="$T/fso"; SITE="$T/htmlo/mysite"; fixture "$R" "$SITE"; : > "$RAN"
echo unit > "$R/etc/systemd/system/joinery-sandbox-host-mysite.service"
echo unit > "$R/etc/systemd/system/joinery-sandbox-host-othersite.service"
out="$(run "$R" "$SITE")"
chk "this site's unit is gone, the other's is not" "$(ls "$R/etc/systemd/system" | paste -sd,)" "joinery-sandbox-host-othersite.service"
chk "the binary stays for it" "$([ -e "$R/usr/local/bin/sandbox-host" ] && echo there || echo gone)" "there"
chk "and the user" "$(grep -c 'userdel' "$RAN")" "0"

echo "=== Links are never followed ==="
R="$T/fsl"; SITE="$T/htmll/mysite"; fixture "$R" "$SITE"; : > "$RAN"
P="$SITE/public_html/plugins"
# A plugin folder that is a link to a module-shaped folder outside plugins/.
old_plugin "$T/outside/linked"
ln -s "$T/outside/linked" "$P/linked"
# A real folder whose module is a link to a file outside plugins/.
mkdir -p "$P/modlink" "$T/outside2"; echo '{}' > "$P/modlink/manifest.json"
echo precious > "$T/outside2/precious.wasm"; ln -s "$T/outside2/precious.wasm" "$P/modlink/modlink.wasm"
# A real folder whose manifest is a link.
mkdir -p "$P/manlink"; echo keep > "$T/outside2/manifest.json"
ln -s "$T/outside2/manifest.json" "$P/manlink/manifest.json"; printf '\0asm' > "$P/manlink/manlink.wasm"
# A module folder with a third entry is a native plugin, not ours to remove.
old_plugin "$P/third"; echo '<?php' > "$P/third/run.php"
# A module folder with a folder in place of the module file.
mkdir -p "$P/dirmod/dirmod.wasm"; echo '{}' > "$P/dirmod/manifest.json"; echo x > "$P/dirmod/dirmod.wasm/inner"
# The socket folder is a link to a folder with something in it.
rm -rf "$SITE/run/sandbox-host"; mkdir -p "$T/outside3"; echo keep > "$T/outside3/file"
ln -s "$T/outside3" "$SITE/run/sandbox-host"; echo other > "$SITE/run/other"
out="$(run "$R" "$SITE")"; rc=$?
chk "a linked folder is left in place" "$([ -L "$P/linked" ] && echo there || echo gone)" "there"
chk "and what it points at is untouched" "$(ls "$T/outside/linked" | paste -sd,)" "linked.wasm,manifest.json"
chk "it is named" "$(grep -c 'plugins/linked looks like a retired sandboxed plugin but is a link' <<<"$out")" "1"
chk "a folder whose module is a link is left in place" "$(ls "$P/modlink" | paste -sd,)" "manifest.json,modlink.wasm"
chk "and the link's target is untouched" "$(cat "$T/outside2/precious.wasm")" "precious"
chk "a folder whose manifest is a link is left in place" "$(ls "$P/manlink" | paste -sd,):$(cat "$T/outside2/manifest.json")" "manifest.json,manlink.wasm:keep"
chk "each is named" "$(grep -c 'holds a link or something that is not a file' <<<"$out")" "3"
chk "a folder with a third entry is left alone, unnamed" "$(ls "$P/third" | paste -sd,):$(grep -c 'plugins/third' <<<"$out")" "manifest.json,run.php,third.wasm:0"
chk "a folder standing where the module would be is never emptied" "$(cat "$P/dirmod/dirmod.wasm/inner")" "x"
chk "a run folder holding something else stays" "$(cat "$SITE/run/other")" "other"
chk "a linked socket folder: the link goes, its target stays" "$([ -e "$SITE/run/sandbox-host" ] || [ -L "$SITE/run/sandbox-host" ] && echo there || echo gone):$(cat "$T/outside3/file")" "gone:keep"

echo "=== the run folder itself a link: the socket folder in its target is left ==="
R="$T/fsr"; SITE="$T/htmlr/mysite"; fixture "$R" "$SITE"; : > "$RAN"
mkdir -p "$T/elsewhere"; mv "$SITE/run/sandbox-host" "$T/elsewhere/sandbox-host"; rmdir "$SITE/run"
ln -s "$T/elsewhere" "$SITE/run"
out="$(run "$R" "$SITE")"
chk "the folder in the link's target stays" "$([ -e "$T/elsewhere/sandbox-host/host.sock" ] && echo there || echo gone)" "there"
chk "the link stays" "$([ -L "$SITE/run" ] && echo there || echo gone)" "there"
chk "it is named" "$(grep -c 'run is a link' <<<"$out")" "1"

echo "=== plugins/ itself a link to elsewhere: folders in it are still only removed in place ==="
R="$T/fsp"; SITE="$T/htmlp/mysite"; fixture "$R" "$SITE"; : > "$RAN"
rmdir "$SITE/public_html/plugins"; mkdir -p "$T/realplugins"; ln -s "$T/realplugins" "$SITE/public_html/plugins"
old_plugin "$T/realplugins/notifier"
mkdir -p "$T/far"; old_plugin "$T/far/sneaky"; ln -s "$T/far/sneaky" "$T/realplugins/sneaky"
out="$(run "$R" "$SITE")"
chk "the one directly inside is removed" "$([ -e "$T/realplugins/notifier" ] && echo there || echo gone)" "gone"
chk "the one reached through a link is not" "$(ls "$T/far/sneaky" | paste -sd,)" "manifest.json,sneaky.wasm"

echo "=== The script's own rules ==="
chk "no recursive delete under plugins/" "$(grep -c 'rm -rf[^\n]*old_plugin\|rm -rf[^\n]*OLD_RUNNER_PLUGINS' "$SCRIPT")" "0"
chk "the stanza runs before the code check" "$(awk '/^# --- the retired sandboxed-plugin runner/{a=NR} /^# --- the code check/{b=NR} END{print (a>0 && a<b) ? "before" : "after"}' "$SCRIPT")" "before"
chk "the runner's installer is no longer a core installer" "$(grep -c '^CORE_INSTALLERS=.*install_sandbox_host' "$ROOT/maintenance_scripts/install_tools/_plugin_installers_start.sh")" "0"

echo
echo "sandbox_teardown gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
