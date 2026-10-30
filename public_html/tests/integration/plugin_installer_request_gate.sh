#!/bin/bash
# @joinery-test
# name: plugin_installer_request
# tier: safe
# env: any
# needs: []
# timeout: 180
#
# Run setup again, from the runner's side (plugin_installer_from_the_browser.md
# D1, D2): a run_plugin_installer root request, carried out by the runner's own
# request loop, against a temporary site tree, run unprivileged with the
# runner's root-only gates stripped. What is pinned:
#   - a request for an active plugin runs its installer exactly once, even
#     though the pass it started already ran every installer, and writes the
#     record and the log;
#   - requests that should do nothing fail with exit 2 and run nothing;
#   - with the active set unreadable, a request stays queued;
#   - a failing installer records `failed` and its request exits 1 with the
#     output; refusals leave their reason in the log and the transcript;
#   - an installer that leaves a daemon holding stdout does not hang the run;
#   - the runner's own stdout lines are unchanged.

set -u
TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/maintenance_scripts/install_tools"
RUNNER="$TOOLS/_plugin_installers_start.sh"
T=$(mktemp -d)
trap 'kill "$(cat "$T/daemon.pid" 2>/dev/null)" 2>/dev/null; rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

PH="$T/public_html"
Q="$T/cache/root_requests"
mkdir -p "$PH/plugins" "$PH/utils" "$T/config" "$Q" "$T/logs" "$T/maintenance_scripts/install_tools"
cp "$TOOLS"/*.sh "$T/maintenance_scripts/install_tools/" 2>/dev/null || true
echo 0.8.999 > "$PH/VERSION"
echo '<?php' > "$T/config/Globalvars_site.php"
# The dispatcher must never be reached for this kind.
printf '<?php\necho "DISPATCHER RAN\\n"; exit(0);\n' > "$PH/utils/root_request.php"
sed 's/\[\[ "$(id -u)" == "0" \]\] || return 0//' "$RUNNER" > "$T/nogate.sh"

# A package verifier that refuses one plugin, by name.
cat > "$T/verify.php" <<'PHP'
<?php
if (basename($argv[1]) === 'unsigp') { echo "verdict: unsigned: no signed listing\n"; exit(1); }
exit(0);
PHP
export JOINERY_VERIFY_PACKAGE="$T/verify.php"
export JOINERY_CONVERGER_ENTRY=/dev/null

# plugin NAME [installer body]: a plugin with host_installer install/host.sh.
plugin() {
    mkdir -p "$PH/plugins/$1/install"
    printf '{"name":"%s","host_installer":"install/host.sh"}' "$1" > "$PH/plugins/$1/plugin.json"
    printf '#!/bin/bash\n%s\n' "$2" > "$PH/plugins/$1/install/host.sh"
    chmod 755 "$PH/plugins/$1/install/host.sh"
}
plugin alphap "echo ran-alphap; echo x >> '$T/alphap.count'"
plugin failp  "echo failp-output-line; exit 1"
plugin daemonp "sleep 60 & echo \$! > '$T/daemon.pid'; echo started-daemon"
plugin untrustp "echo ran-untrustp"
chmod 775 "$PH/plugins/untrustp/install/host.sh"
plugin unsigp "echo ran-unsigp"
plugin betap "echo ran-betap; echo x >> '$T/betap.count'"
mkdir -p "$PH/plugins/gammap"; printf '{"name":"gammap"}' > "$PH/plugins/gammap/plugin.json"

ACTIVE=$'alphap\nfailp\ndaemonp\nuntrustp\nunsigp\ngammap'

# request ID PLUGIN [extra json]
request() {
    printf '{"kind":"run_plugin_installer","args":{"plugin":"%s"%s},"requested_by":1,"requested_at":%s}' \
        "$2" "${3:-}" "$(date +%s)" > "$Q/$1.json"
}
exitcode() { cat "$Q/$1/$2.exit" 2>/dev/null; }
transcript() { cat "$T/logs/root_requests/$1.log" 2>/dev/null; }

N=$(date +%s)
request "$N-a0000001" alphap
request "$N-a0000002" failp
request "$N-a0000003" daemonp
request "$N-a0000004" untrustp
request "$N-a0000005" unsigp
request "$N-a0000006" betap
request "$N-a0000007" gammap
request "$N-a0000008" 'Bad;name'
request "$N-a0000009" deltap ',"host_installer":"../alphap/install/host.sh","installer":"/bin/true"'

echo "== one pass carries out every request =="
start=$(date +%s)
out=$(JOINERY_ACTIVE_PLUGINS="$ACTIVE" timeout 120 bash "$T/nogate.sh" --site-root="$T" 2>&1); rc=$?
elapsed=$(( $(date +%s) - start ))
chk "the run finished, exit 0" "$rc" "0"
chk "a daemon left holding an installer's stdout does not hang the run" "$([ "$elapsed" -lt 50 ] && echo yes || echo "no (${elapsed}s)")" "yes"
chk "the dispatcher is never reached for this kind" "$(echo "$out" | grep -c 'DISPATCHER RAN')$(cat "$T"/logs/root_requests/*.log | grep -c 'DISPATCHER RAN')" "00"

echo "== an active plugin: its installer runs exactly once =="
chk "the request is done, exit 0" "$(exitcode done "$N-a0000001")" "0"
chk "the installer ran once, though the pass ran every installer before the request" "$(wc -l < "$T/alphap.count" 2>/dev/null | tr -d ' ')" "1"
chk "the transcript is that run's output" "$(transcript "$N-a0000001" | grep -c '^ran-alphap$')|$(transcript "$N-a0000001" | grep -c 'ran in this pass')" "1|1"
rec="$T/cache/plugin_installers/alphap.json"
chk "the record says ok, with the installer and the release" \
    "$(php -r '$r=json_decode(file_get_contents($argv[1]),true); echo $r["outcome"],"|",$r["exit_code"],"|",$r["installer"],"|",$r["release"];' "$rec" 2>/dev/null)" \
    "ok|0|install/host.sh|0.8.999"
chk "the log holds the run's output" "$(grep -c '^ran-alphap$' "$T/logs/plugin_installers/alphap.log" 2>/dev/null)" "1"
chk "no .tmp is left behind" "$(ls "$T/logs/plugin_installers" "$T/cache/plugin_installers" | grep -c '\.tmp')" "0"

echo "== the runner's stdout lines are unchanged =="
chk "plugin installers: alphap: ok" "$(echo "$out" | grep -c '^plugin installers: alphap: ok$')" "1"
chk "the WARNING line for a failure" "$(echo "$out" | grep -c '^plugin installers: WARNING - failp installer failed')" "1"
chk "the installer's own output still reaches stdout, before its ok line" \
    "$(echo "$out" | grep -n -e '^ran-alphap$' -e '^plugin installers: alphap: ok$' | cut -d: -f2- | tr '\n' '|')" "ran-alphap|plugin installers: alphap: ok|"
chk "a refusal's line is unchanged" "$(echo "$out" | grep -c '^plugin installers: unsigp: not a package we built - host installer skipped')" "1"

echo "== a failing installer =="
chk "its request exits 1" "$(exitcode failed "$N-a0000002")" "1"
chk "with the output in the transcript" "$(transcript "$N-a0000002" | grep -c 'failp-output-line')" "1"
chk "and the record says failed, exit 1" \
    "$(php -r '$r=json_decode(file_get_contents($argv[1]),true); echo $r["outcome"],"|",$r["exit_code"];' "$T/cache/plugin_installers/failp.json" 2>/dev/null)" "failed|1"

echo "== refusals say why, in the log and the transcript =="
chk "untrusted: exit 2" "$(exitcode failed "$N-a0000004")" "2"
chk "untrusted: the reason is in the log" "$(grep -c 'installer refused: .*mode 775' "$T/logs/plugin_installers/untrustp.log" 2>/dev/null)" "1"
chk "untrusted: and in the transcript" "$(transcript "$N-a0000004" | grep -c 'installer refused: .*mode 775')" "1"
chk "untrusted: the record" "$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["outcome"];' "$T/cache/plugin_installers/untrustp.json" 2>/dev/null)" "refused-untrusted"
chk "unsigned: exit 2" "$(exitcode failed "$N-a0000005")" "2"
chk "unsigned: the reason is in the log and the transcript" \
    "$(grep -c 'not a package we built' "$T/logs/plugin_installers/unsigp.log" 2>/dev/null)|$(transcript "$N-a0000005" | grep -c 'not a package we built')" "1|1"
chk "unsigned: the record" "$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["outcome"];' "$T/cache/plugin_installers/unsigp.json" 2>/dev/null)" "refused-unsigned"
chk "neither refused installer ran" "$(echo "$out" | grep -c -e '^ran-untrustp$' -e '^ran-unsigp$')" "0"

echo "== requests that should do nothing run nothing, exit 2 =="
chk "an inactive plugin" "$(exitcode failed "$N-a0000006")|$(transcript "$N-a0000006" | grep -c 'not an active plugin')|$(test -e "$T/betap.count" && echo ran || echo none)" "2|1|none"
chk "a plugin with no installer" "$(exitcode failed "$N-a0000007")|$(transcript "$N-a0000007" | grep -c 'declares no host_installer')" "2|1"
chk "a name that fails the pattern" "$(exitcode failed "$N-a0000008")|$(transcript "$N-a0000008" | grep -c 'names no plugin')" "2|1"
chk "extra fields pointing elsewhere are ignored, and the name decides" \
    "$(exitcode failed "$N-a0000009")|$(wc -l < "$T/alphap.count" | tr -d ' ')" "2|1"
chk "the daemon plugin's request is done" "$(exitcode done "$N-a0000003")" "0"

echo "== the active set unreadable: the request waits =="
request "$N-b0000001" alphap
out=$(timeout 120 bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "it stays queued" "$(test -f "$Q/$N-b0000001.json" && echo queued || echo moved)" "queued"
chk "and says why" "$(echo "$out" | grep -c "$N-b0000001 (run_plugin_installer) waits")" "1"

echo "== a request for a plugin this pass did not run: the handler runs it =="
# A plugin switched on after the pass read its active set is not among the
# names the pass ran. The copy below gives the pass a set without betap and
# the request loop one with it, so the handler has to run the installer itself.
rm -f "$Q/$N-b0000001.json"
request "$N-c0000001" betap
sed 's/^run_plugin_installers$/JOINERY_ACTIVE_PLUGINS_SAVED="$JOINERY_ACTIVE_PLUGINS"; JOINERY_ACTIVE_PLUGINS=alphap; run_plugin_installers; JOINERY_ACTIVE_PLUGINS="$JOINERY_ACTIVE_PLUGINS_SAVED"/' \
    "$T/nogate.sh" > "$T/nogate_split.sh"
out=$(JOINERY_ACTIVE_PLUGINS=$'alphap\nbetap' timeout 120 bash "$T/nogate_split.sh" --site-root="$T" 2>&1)
chk "the request is done" "$(exitcode done "$N-c0000001")" "0"
chk "betap's installer ran, once" "$(wc -l < "$T/betap.count" 2>/dev/null | tr -d ' ')" "1"
chk "its output is in the transcript" "$(transcript "$N-c0000001" | grep -c '^ran-betap$')" "1"

echo "== a failed request does not hide the run's failed installer (reviewer2 B1) =="
# The converger's tick: the queued request is its reason to converge, and the
# run's outcome is what the header notice reads.
request "$N-d0000001" failp
JOINERY_ACTIVE_PLUGINS=failp timeout 120 bash "$T/nogate.sh" --when-changed --site-root="$T" >/dev/null 2>&1
chk "the request failed with the installer" "$(exitcode failed "$N-d0000001")" "1"
chk "and the run still records installer-failed, not request-failed" "$(cut -d' ' -f2 "$T/cache/host_converger.last" 2>/dev/null)" "installer-failed"

echo "== a link planted at a name root writes is never followed (reviewer2 B2) =="
echo victim > "$T/victim_log"; echo victim > "$T/victim_rec"; echo victim > "$T/victim_tx"
ln -sf "$T/victim_log" "$T/logs/plugin_installers/alphap.log.tmp"
ln -sf "$T/victim_rec" "$T/cache/plugin_installers/alphap.json.tmp"
ln -sf "$T/victim_tx" "$T/logs/root_requests/$N-e0000001.log"
request "$N-e0000001" alphap
JOINERY_ACTIVE_PLUGINS="$ACTIVE" timeout 120 bash "$T/nogate.sh" --site-root="$T" >/dev/null 2>&1
chk "the files the links pointed at are untouched" "$(cat "$T/victim_log")|$(cat "$T/victim_rec")|$(cat "$T/victim_tx")" "victim|victim|victim"
chk "the record and log were written as files of their own" \
    "$(test -L "$T/logs/plugin_installers/alphap.log" && echo link || grep -c '^ran-alphap$' "$T/logs/plugin_installers/alphap.log")|$(test -L "$T/cache/plugin_installers/alphap.json" && echo link || php -r 'echo json_decode(file_get_contents($argv[1]),true)["outcome"];' "$T/cache/plugin_installers/alphap.json")" "1|ok"
chk "and the request's transcript too" "$(test -L "$T/logs/root_requests/$N-e0000001.log" && echo link || echo file)|$(exitcode done "$N-e0000001")" "file|0"

echo
echo "plugin_installer_request gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
