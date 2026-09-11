#!/bin/bash
# @joinery-test
# name: host_converger
# tier: safe
# env: any
# needs: []
# timeout: 120
#
# The host converger's change detection (specs/host_converger.md), run against
# a temporary site tree as an unprivileged user: the installers themselves
# skip without root (each says so), which is exactly what lets the stamp logic
# be exercised here. What is pinned: a fresh stamp costs nothing, a changed
# release converges, a day-old stamp converges, an unreachable database is
# retried next tick, and every run records cache/host_converger.last.
#
# The installer's unit text is rendered and parsed too, without installing it.

set -u
TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/maintenance_scripts/install_tools"
RUNNER="$TOOLS/_plugin_installers_start.sh"
INSTALLER="$TOOLS/install_host_converger.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

mkdir -p "$T/public_html/plugins" "$T/config" "$T/cache"
# A real site carries its own maintenance_scripts, and that is where the runner
# resolves its installers from — not from wherever the runner itself was
# invoked, which is /usr/local/sbin under the converger's timer.
mkdir -p "$T/maintenance_scripts/install_tools"
cp "$(dirname "$RUNNER")"/*.sh "$T/maintenance_scripts/install_tools/" 2>/dev/null || true
echo 0.8.384 > "$T/public_html/VERSION"
echo '<?php' > "$T/config/Globalvars_site.php"
LAST="$T/cache/host_converger.last"
STAMP="$T/cache/host_converger.stamp"

echo "== the runner and the installer exist and parse =="
chk "runner parses" "$(bash -n "$RUNNER" && echo ok)" "ok"
chk "installer parses" "$(bash -n "$INSTALLER" && echo ok)" "ok"
chk "the installer is a core installer" "$(grep -c 'CORE_INSTALLERS="[^"]*install_host_converger.sh' "$RUNNER")" "1"

# Derived, not counted by hand: adding a core installer is a normal thing to do
# and should not fail this gate. What matters is that a converging run runs them
# all, not that there happen to be N of them.
CORE_COUNT="$(sed -n 's/^CORE_INSTALLERS="\([^"]*\)".*/\1/p' "$RUNNER" | wc -w)"
chk "the core installer list is readable" "$( [ "$CORE_COUNT" -gt 0 ] && echo yes )" "yes"

echo "== first tick: no stamp, so it converges =="
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "runs the installers" "$(echo "$out" | grep -c 'core installers: running')" "$CORE_COUNT"
chk "says why" "$(echo "$out" | grep -c 'release or installers changed')" "1"
chk "records the run (database unreachable in a temp tree)" "$(cut -d' ' -f2 "$LAST")" "db-unreachable"

echo "== an unreachable database is retried next tick =="
chk "the stamp was dropped" "$(test -e "$STAMP" && echo kept || echo dropped)" "dropped"

echo "== a fresh, unchanged stamp costs nothing =="
# Simulate a converged run: stamp present, last fresh.
bash "$RUNNER" --when-changed --site-root="$T" >/dev/null 2>&1
printf '%s converged\n' "$(date -u +%s)" > "$LAST"
bash "$RUNNER" --site-root="$T" >/dev/null 2>&1   # a plain run writes no stamp
# The stamp the runner would compute for this tree; written by hand so the
# next tick sees "unchanged". Mirrors converge_hash() in the runner: VERSION,
# every script in the fixture's install_tools, the vhost templates and their
# history, every plugin manifest, and the active-plugin list (unreachable here).
expected_stamp() {
    local tools="$T/maintenance_scripts/install_tools"
    { cat "$T/public_html/VERSION" "$tools"/*.sh "$tools"/default_*vhost.conf "$tools"/vhost_history/*.conf 2>/dev/null
      for m in "$T"/public_html/plugins/*/plugin.json; do [ -f "$m" ] && cat "$m"; done
      echo "db-unreachable"; } | sha256sum | cut -d' ' -f1
}
expected_stamp > "$STAMP"
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "runs nothing" "$(echo "$out" | grep -c 'core installers: running')" "0"
chk "prints nothing" "${#out}" "0"

echo "== a changed release converges =="
echo 0.8.385 > "$T/public_html/VERSION"
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "runs the installers again" "$(echo "$out" | grep -c 'core installers: running')" "$CORE_COUNT"

echo "== a day-old run converges regardless =="
expected_stamp > "$STAMP"
printf '%s converged\n' "$(( $(date -u +%s) - 90000 ))" > "$LAST"
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "says daily" "$(echo "$out" | grep -c 'converging.*(daily)')" "1"

echo "== without the flag every run converges =="
out=$(bash "$RUNNER" --site-root="$T" 2>&1)
chk "runs the installers" "$(echo "$out" | grep -c 'core installers: running')" "$CORE_COUNT"

echo "== the installer refuses politely without root =="
out=$(bash "$INSTALLER" x "$T" 2>&1)
chk "skips, naming the sudo command" "$(echo "$out" | grep -c 'not root - skipping (run: sudo bash')" "1"
chk "touches nothing" "$(test -e /etc/cron.d/joinery-host-converger-test-$$ && echo touched || echo clean)" "clean"

echo "== a queued root request converges even when nothing else changed =="
# The hash covers the release, the runner and the installers; none of those move
# when somebody presses Upgrade. Behind the stamp, a request on a healthy box sat
# at Queued until the next release or the daily tick.
bash "$RUNNER" --when-changed --site-root="$T" >/dev/null 2>&1   # write a fresh stamp
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
STAMPED_QUIET="$(echo "$out" | grep -c 'converging')"
mkdir -p "$T/cache/root_requests"
printf '{"kind":"write_agent_files","args":{},"requested_at":%s}\n' "$(date -u +%s)" \
    > "$T/cache/root_requests/$(date -u +%s)-aaaaaaaa.json"
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "a queued request makes the run converge" \
    "$(echo "$out" | grep -c 'converging.*a root request is queued')" "1"
chk "and the queue is checked before the stamp exit" \
    "$( [ "$(grep -n 'REQUESTS_WAITING=0' "$RUNNER" | cut -d: -f1)" -lt "$(grep -n 'Nothing changed, nothing queued' "$RUNNER" | cut -d: -f1)" ] && echo yes )" "yes"
rm -f "$T/cache/root_requests"/*.json

echo "== a converging run applies the tree's permissions =="
# Nothing else does. On a self-hosted box the browser upgrade lands the code as
# the web user, so the ownership model only arrives when something runs
# fix_permissions.sh as root — and without it config/tree_owner is never written
# and the data set keeps whatever modes it had.
cat > "$T/maintenance_scripts/install_tools/fix_permissions.sh" <<'FP'
#!/usr/bin/env bash
echo "$2" > "$(dirname "$0")/../../called_with"
exit 0
FP
chmod 755 "$T/maintenance_scripts/install_tools/fix_permissions.sh"
rm -f "$T/called_with" "$T/cache/host_converger.stamp"
# The root gates stripped, so the harness (which is not root, and must not be)
# reaches the steps that only root would otherwise run.
sed 's/\[\[ "$(id -u)" == "0" \]\] || return 0//' "$RUNNER" > "$T/nogate.sh"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --when-changed --site-root="$T" 2>&1)
chk "a converging run runs fix_permissions.sh" \
    "$(test -f "$T/called_with" && echo yes || echo no)" "yes"
chk "and derives the mode from who owns the tree, not from a guess" \
    "$(cat "$T/called_with" 2>/dev/null)" "--dev"

# The one that guessing wrong destroys: --production on a developer box hands
# the whole checkout to root. That happened once already.
chk "it never assumes --production" \
    "$(echo "$out" | grep -c 'applied --production')" "0"

echo "== the runner's own stderr survives =="
# `exec 9>f 2>/dev/null` applies that redirect to the WHOLE shell, permanently,
# and every later message on stderr disappears — including every refusal this
# gate and the request suite rely on.
chk "the queue lock does not redirect the shell's stderr" \
    "$(grep -c 'exec 9>"\${queue}/.runner.lock" 2>/dev/null' "$RUNNER")" "0"
chk "and uses a descriptor below 10, which PHP subprocesses do not inherit" \
    "$(grep -c 'exec 9>"\${queue}/.runner.lock"' "$RUNNER")" "1"

echo "== the unit text the installer writes =="
chk "a oneshot service" "$(grep -c '^Type=oneshot' "$INSTALLER")" "1"
chk "a persistent timer on the declared interval" "$(grep -c 'OnUnitActiveSec=\${INTERVAL_MIN}min' "$INSTALLER")$(grep -c '^Persistent=true' "$INSTALLER")" "11"
chk "the interval is one minute" "$(grep -c '^INTERVAL_MIN=1$' "$INSTALLER")" "1"
chk "runs the runner in --when-changed mode" "$(grep -c -- '--when-changed \${SITENAME} \${SITE_ROOT}' "$INSTALLER")" "1"
chk "cron form for a box without systemd" "$(grep -c '^\* \* \* \* \* root' "$INSTALLER")" "1"

# specs/read_only_tree.md: the timer's entry point is a root-owned copy outside
# the tree. A root timer pointed straight at a file in the tree would make every
# tree write a root-execution primitive — on the developer box, where the tree
# owner is not root, that is the whole difference.
chk "the entry point is a root-owned copy outside the tree" \
    "$(grep -c 'ENTRY="/usr/local/sbin/\${UNIT_NAME}"' "$INSTALLER")" "1"
chk "the installer places it when there is none" \
    "$(grep -c 'install -o root -g root -m 755 "\${RUNNER}" "\${ENTRY}"' "$INSTALLER")" "1"
# ...and replaces it whenever it differs from the tree's runner. Placing it only
# when absent left whichever release happened to install it running as root
# forever: the first such copy resolved its tools against /usr/local/sbin, found
# no installers, and could not reach the runner that would have replaced it.
chk "and refreshes it whenever it differs from the tree's runner" \
    "$(grep -c 'cmp -s "\${RUNNER}" "\${ENTRY}"' "$INSTALLER")" "1"
chk "only from a runner it would be willing to run" \
    "$(grep -c 'joinery_file_is_trusted "\${RUNNER}" "\${TREE_OWNER}"' "$INSTALLER")" "1"

# Refreshing is the RUNNER's job: it is the one thing that always knows where
# the site is. A copy too stale to find the installer could otherwise never be
# replaced, which is a self-lock only root-by-hand gets out of.
chk "the runner refreshes the entry point" \
    "$(grep -c 'refresh_converger_entry' "$RUNNER")" "2"
chk "and only from a source it would be willing to run" \
    "$(sed -n '/^refresh_converger_entry() {/,/^}$/p' "$RUNNER" | grep -c 'installer_is_trusted')" "1"

# Bash resolves a function at CALL time, so the call has to sit below both the
# definition it uses and TREE_OWNER. Above them it was `command not found`, rc
# 127, which the guard read as "untrusted" — every tick logged a refusal and the
# copy stayed stale for good. Text order first, then the behaviour.
DEF_AT="$(grep -n '^refresh_converger_entry() {' "$RUNNER" | cut -d: -f1)"
TRUST_AT="$(grep -n '^installer_is_trusted() {' "$RUNNER" | cut -d: -f1)"
CALL_AT="$(grep -n '^refresh_converger_entry$' "$RUNNER" | cut -d: -f1)"
chk "the refresh is called after the trust helper it uses" \
    "$( [ "$CALL_AT" -gt "$TRUST_AT" ] && [ "$CALL_AT" -gt "$DEF_AT" ] && echo yes )" "yes"

# Executed, because the ordering bug looked fine in the source.
STALE="$T/entry_stale.sh"
cp "$RUNNER" "$STALE"; echo '# stale' >> "$STALE"; chmod 755 "$STALE"
out=$(JOINERY_CONVERGER_ENTRY="$STALE" bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "it does not refuse its own source as untrusted" \
    "$(echo "$out" | grep -c 'refusing to refresh')" "0"
chk "and nothing in it is an unresolved function" \
    "$(echo "$out" | grep -ci 'command not found')" "0"
chk "a stale copy is brought up to date" \
    "$(cmp -s "$RUNNER" "$STALE" && echo current || echo stale)" "current"
chk "the unit runs the copy, not the tree" \
    "$(grep -c 'COMMAND="/bin/bash \${RUN_TARGET}' "$INSTALLER")" "1"

# A queued root request should not wait for the next tick: someone pressed
# Upgrade and is watching a transcript.
chk "a path unit watches the request queue" \
    "$(grep -c 'PathChanged=\${SITE_ROOT}/cache/root_requests' "$INSTALLER")" "1"

# The copy runs from /usr/local/sbin, so anything the runner resolves against
# its own directory is missing there. It has to find the installers, the
# secrets helper and the files it hashes through the SITE.
echo "== the copy outside the tree still finds the site's tools =="
COPY="$(mktemp -d)/joinery-host-converger"
cp "$RUNNER" "$COPY"
out=$(bash "$COPY" --site-root="$T" 2>&1)
chk "core installers are found from the site, not the script's directory" \
    "$(echo "$out" | grep -c 'core installers: running')" "$CORE_COUNT"
chk "and none reported missing" "$(echo "$out" | grep -c 'missing - skipping')" "0"
chk "the config-secrets helper is found too" \
    "$(echo "$out" | grep -c '_config_secrets.sh missing')" "0"
out=$(bash "$COPY" 2>&1)
chk "a stray copy with no site named refuses rather than guessing /usr" \
    "$(echo "$out" | grep -c 'does not ship inside one')" "1"
rm -rf "$(dirname "$COPY")"
chk "and fires the same oneshot service" \
    "$(grep -c 'Unit=\${UNIT_NAME}.service' "$INSTALLER")" "1"

echo
echo "host_converger gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
