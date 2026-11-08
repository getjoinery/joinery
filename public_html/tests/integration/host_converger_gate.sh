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
# retried next tick, every run records cache/host_converger.last, and a plugin
# switched off while a run is under way is not set up again by it.
#
# The installer's unit text is rendered and parsed too, without installing it.
# The certificate summary the runner writes for the admin notice is pinned from
# a fixture lineage (specs/implemented/tls_and_origin_trust.md WP11). The
# release verification key the runner writes from the agent bundle is pinned
# from a throwaway key (specs/package_signing.md WP1). A host whose declared data
# root is not mounted runs nothing (specs/one_data_root.md WP1). --remove-site
# runs every plugin's declared host remover under the caller's lock, and only
# under it (specs/plugin_host_removal.md WP1).

set -u
TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/maintenance_scripts/install_tools"
RUNNER="$TOOLS/_plugin_installers_start.sh"
INSTALLER="$TOOLS/install_host_converger.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
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
# every script in the fixture's install_tools with its owner and mode, the
# vhost templates and their history, every plugin manifest and sandboxed
# manifest, and the active-plugin list (unreachable here).
expected_stamp() {
    local tools="$T/maintenance_scripts/install_tools"
    { cat "$T/public_html/VERSION" "$tools"/*.sh "$tools"/default_*.conf "$tools"/vhost_history/*.conf 2>/dev/null
      stat -c '%U %a %n' "$tools"/*.sh 2>/dev/null
      for m in "$T"/public_html/plugins/*/plugin.json; do [ -f "$m" ] && cat "$m"; done
      for m in "$T"/public_html/plugins/*/manifest.json; do [ -f "$m" ] && cat "$m"; done
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
# `exec 9>>f 2>/dev/null` applies that redirect to the WHOLE shell, permanently,
# and every later message on stderr disappears — including every refusal this
# gate and the request suite rely on. The lock itself (taken once, at the top,
# for every mode) is pinned in host_runner_lock_gate.sh.
chk "the runner lock does not redirect the shell's stderr" \
    "$(grep -c 'exec 9>>"\${LOCK_FILE}" 2>/dev/null' "$RUNNER")" "0"
chk "and uses a descriptor below 10, which PHP subprocesses do not inherit" \
    "$(grep -c 'exec 9>>"\${LOCK_FILE}"' "$RUNNER")" "1"

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

# The run that refreshed the copy is the old release: it hands itself to the
# new copy, once, with the arguments it was called with, before the stamp. Run
# as the stale copy itself (the timer runs the copy), with an install that
# can place it unprivileged.
mkdir -p "$T/instbin"
printf '#!/bin/bash
src="${@: -2:1}"; dst="${@: -1}"; cp "$src" "$dst" && chmod 755 "$dst"
' > "$T/instbin/install"
chmod 755 "$T/instbin/install"
SELF="$T/entry_self.sh"
cp "$T/nogate.sh" "$SELF"; echo '# stale' >> "$SELF"; chmod 755 "$SELF"
out=$(PATH="$T/instbin:$PATH" JOINERY_CONVERGER_ENTRY="$SELF" bash "$SELF" --site-root="$T" 2>&1)
chk "the run that refreshed its own copy runs the refreshed copy, with its arguments" \
    "$(echo "$out" | grep -c 'running the refreshed copy')|$(echo "$out" | grep -c 'core installers: running')" "1|$CORE_COUNT"
cp "$T/nogate.sh" "$SELF"; echo '# stale again' >> "$SELF"
out=$(PATH="$T/instbin:$PATH" JOINERY_CONVERGER_ENTRY="$SELF" JOINERY_CONVERGER_REEXEC=1 bash "$SELF" --site-root="$T" 2>&1)
chk "and only once" "$(echo "$out" | grep -c 'running the refreshed copy')" "0"
chk "the unit runs the copy, not the tree (a site's command and a machine's)" \
    "$(grep -c 'COMMAND="/bin/bash \${RUN_TARGET}' "$INSTALLER")" "2"

# A queued root request should not wait for the next tick: someone pressed
# Upgrade and is watching a transcript.
chk "a path unit watches the request queue" \
    "$(grep -c 'PathChanged=\${STATE_DIR}/root_requests' "$INSTALLER")" "1"
# Root's queue is root's directory, never the site's cache/ (shell_command_audit D1).
chk "the queue root watches is under /var/lib/joinery/sites" \
    "$(grep -c '^    STATE_DIR="/var/lib/joinery/sites/\${SITENAME}"$' "$INSTALLER")" "1"
chk "and nothing of root's is made under the site's cache/ or logs/" \
    "$(grep -c 'SITE_ROOT}/cache/root_requests\|SITE_ROOT}/logs/host_converger' "$INSTALLER")" "0"

# The copy runs from /usr/local/sbin, so anything the runner resolves against
# its own directory is missing there. It has to find the installers, the
# secrets helper and the files it hashes through the SITE.
echo "== the copy outside the tree still finds the site's tools =="
COPY_DIR="$(mktemp -d)" || exit 1
[ -n "$COPY_DIR" ] && [ -d "$COPY_DIR" ] || exit 1
COPY="${COPY_DIR}/joinery-host-converger"
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
rm -rf "${COPY_DIR:?}"
chk "and fires the same oneshot service" \
    "$(grep -c 'Unit=\${UNIT_NAME}.service' "$INSTALLER")" "1"

echo "== a converging run writes the certificate summary (specs/implemented/tls_and_origin_trust.md WP11) =="
# /etc/letsencrypt is root's on a root-owned tree, so the admin notice reads a
# summary the converger writes. A fixture lineage with a self-signed cert
# stands in for /etc/letsencrypt; the root gate is stripped as above.
LE="$T/letsencrypt"
mkdir -p "$LE/live/example.test" "$LE/renewal" "$T/sites" "$T/cache"
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
    -keyout "$LE/live/example.test/privkey.pem" -out "$LE/live/example.test/cert.pem" -days 90 \
    -subj "/CN=example.test" -addext "subjectAltName=DNS:example.test,DNS:www.example.test" >/dev/null 2>&1
printf '[renewalparams]\ninstaller = None\n' > "$LE/renewal/example.test.conf"
printf 'ServerName example.test\n' > "$T/sites/$(basename "$T").conf"
rm -f "$T/cache/certificates.json"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_LETSENCRYPT_DIR="$LE" JOINERY_APACHE_SITES_DIR="$T/sites" \
    bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "the run says it wrote the summary" "$(echo "$out" | grep -c 'certificates: summary written')" "1"
chk "the summary exists" "$(test -f "$T/cache/certificates.json" && echo yes || echo no)" "yes"
SUMMARY="$T/cache/certificates.json"
read_summary() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(eval(sys.argv[2]))' "$SUMMARY" "$1" 2>/dev/null; }
chk "it parses as JSON" "$(read_summary 'True')" "True"
chk "it carries the lineage by its directory name" "$(read_summary 'd["lineages"][0]["name"]')" "example.test"
chk "with every name the certificate carries" "$(read_summary '",".join(d["lineages"][0]["names"])')" "example.test,www.example.test"
chk "and dates ninety days apart" "$(read_summary '(d["lineages"][0]["not_after"]-d["lineages"][0]["not_before"])//86400')" "90"
chk "and what the renewal conf says the installer is" "$(read_summary 'd["lineages"][0]["renewal_installer"]')" "None"
chk "the site's name is read from its vhost" "$(read_summary 'd["site"]["name"]')" "example.test"
chk "a name that resolves nowhere has no addresses" "$(read_summary 'len(d["site"]["apex_addresses"])')" "0"
chk "written is now" "$(read_summary 'abs(d["written"]-'"$(date -u +%s)"')<120')" "True"
chk "the summary is not world-readable" "$(stat -c '%a' "$SUMMARY")" "640"

# No letsencrypt directory at all: the empty form, never a missing file.
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_LETSENCRYPT_DIR="$T/no-such-dir" JOINERY_APACHE_SITES_DIR="$T/sites" \
    bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "no letsencrypt directory still writes the summary" "$(echo "$out" | grep -c 'certificates: summary written to .*/certificates.json (0 lineage')" "1"
chk "with no lineages" "$(read_summary 'len(d["lineages"])')" "0"
chk "and says letsencrypt is absent" "$(read_summary 'd["letsencrypt"]')" "False"

# The runner as it really runs, without root: it must not write a summary
# claiming there are no lineages on a box where it simply could not look.
rm -f "$SUMMARY"
bash "$RUNNER" --site-root="$T" >/dev/null 2>&1
chk "a run without root writes no summary" "$(test -f "$SUMMARY" && echo written || echo none)" "none"

echo "== a run writes config/release_verify_keys from the agent bundle (specs/package_signing.md WP1) =="
# Root verifies every package against this file before it goes into the tree.
# The key is the agent bundle's, read from the tree; written when absent,
# appended when the bundle carries one the file lacks, never replaced. A
# throwaway key stands in for the bundle's; nothing here is a real key.
mkdir -p "$T/public_html/agent_dist"
KEY_A="$(php -r 'echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));')"
KEY_B="$(php -r 'echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));')"
KEYS="$T/config/release_verify_keys"
rm -f "$KEYS"
printf '{"version":"1.0","signing_public_key":"%s"}\n' "$KEY_A" > "$T/public_html/agent_dist/manifest.json"
# The writer lives in _host_files.sh, shared with _site_init.sh; its root gate
# is stripped the same way as the runner's.
sed -i 's/\[\[ "$(id -u)" == "0" \]\] || return 0//' "$T/maintenance_scripts/install_tools/_host_files.sh"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "the run says it wrote the key" "$(echo "$out" | grep -c "release key: config/release_verify_keys carries")" "1"
chk "the file holds the bundle's key" "$(cat "$KEYS" 2>/dev/null)" "$KEY_A"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "a second tick writes nothing" "$(echo "$out" | grep -c "release key:")" "0"
chk "and the file still holds one key" "$(wc -l < "$KEYS")" "1"
# A new bundle with a new key: appended, the old one kept, so packages signed
# under either keep verifying across a channel change.
printf '{"version":"1.1","signing_public_key":"%s"}\n' "$KEY_B" > "$T/public_html/agent_dist/manifest.json"
JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" >/dev/null 2>&1
chk "a bundle with a new key appends it" "$(wc -l < "$KEYS")" "2"
chk "and keeps the old one" "$(grep -cxF "$KEY_A" "$KEYS")" "1"
# A manifest with no usable key writes nothing rather than an empty line.
printf '{"version":"1.2","signing_public_key":"not-a-key"}\n' > "$T/public_html/agent_dist/manifest.json"
JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" >/dev/null 2>&1
chk "a malformed bundle key is not written" "$(wc -l < "$KEYS")" "2"
# Once a verdict has recorded the trusted set (config/release_trust,
# specs/release_key_rotation.md R1, F3), the set is the record's: a bundle
# manifest, which any installed release puts here, adds nothing to it.
KEY_C="$(php -r 'echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));')"
printf '{"format":1}\n' > "$T/config/release_trust"
printf '{"version":"1.2","signing_public_key":"%s","release_keys":["%s"]}\n' "$KEY_C" "$KEY_C" > "$T/public_html/agent_dist/manifest.json"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "with the set recorded, a bundle's new release key is not appended (R1)" "$(grep -cxF "$KEY_C" "$KEYS")|$(echo "$out" | grep -c "release key: config/release_verify_keys carries")" "0|0"
rm -f "$T/config/release_trust"

echo "== the release log's keys, and the switch that requires it (release_transparency D5, D6) =="
# The statement and log keys come from the same manifest, appended like the
# release key. config/release_log_required is created once both files hold a
# key and never before, and never over a fork's release_log_optional.
SKEYS="$T/config/release_statement_keys"
LKEYS="$T/config/transparency_log_keys"
REQ="$T/config/release_log_required"
OPT="$T/config/release_log_optional"
rm -f "$SKEYS" "$LKEYS" "$REQ" "$OPT"
P256="$(php -r '$k = openssl_pkey_new(array("private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "prime256v1")); $d = openssl_pkey_get_details($k); echo preg_replace("/-----[^-]+-----|\s+/", "", $d["key"]);')"
ED_SPKI="$(php -r 'echo base64_encode(hex2bin("302a300506032b6570032100") . random_bytes(32));')"
printf '{"version":"1.3","signing_public_key":"%s","release_keys":["%s"],"statement_keys":[],"log_keys":[]}\n' "$KEY_A" "$KEY_A" > "$T/public_html/agent_dist/manifest.json"
JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" >/dev/null 2>&1
chk "no statement or log key yet: the log is not required" "$(test -f "$REQ" && echo written || echo none)" "none"
printf '{"version":"1.4","signing_public_key":"%s","release_keys":["%s"],"statement_keys":["%s","%s"],"log_keys":[{"origin":"log2025-1.rekor.sigstore.dev","key":"%s"}]}\n' \
    "$KEY_A" "$KEY_A" "$P256" "$ED_SPKI" "$ED_SPKI" > "$T/public_html/agent_dist/manifest.json"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "the statement key file holds the P-256 key" "$(cat "$SKEYS" 2>/dev/null)" "$P256"
chk "and not the Ed25519 key listed beside it" "$(grep -c "$ED_SPKI" "$SKEYS")" "0"
chk "the log key file holds origin and key" "$(cat "$LKEYS" 2>/dev/null)" "log2025-1.rekor.sigstore.dev $ED_SPKI"
chk "with keys to check by, the log is required" "$(test -f "$REQ" && echo written || echo none)" "written"
chk "and the run says so" "$(echo "$out" | grep -c "release log: config/release_log_required written")" "1"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "a second tick writes nothing new" "$(echo "$out" | grep -c "release key:\|release log:")" "0"
printf '{"version":"1.5","signing_public_key":"%s","statement_keys":[],"log_keys":[]}\n' "$KEY_A" > "$T/public_html/agent_dist/manifest.json"
JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" >/dev/null 2>&1
chk "a later bundle without them removes nothing: keys kept" "$(wc -l < "$SKEYS")" "1"
chk "and the requirement stays: a release can only tighten" "$(test -f "$REQ" && echo written || echo none)" "written"
rm -f "$REQ"
touch "$OPT"
JOINERY_CONVERGER_ENTRY=/dev/null bash "$T/nogate.sh" --site-root="$T" >/dev/null 2>&1
chk "a fork's release_log_optional: the requirement is not created" "$(test -f "$REQ" && echo written || echo none)" "none"
chk "and the opt-out is left as the owner made it" "$(test -f "$OPT" && echo kept || echo gone)" "kept"
rm -f "$SKEYS" "$LKEYS" "$REQ" "$OPT"
# The runner as it really runs, without root: it must not write the file.
cp "$TOOLS/_host_files.sh" "$T/maintenance_scripts/install_tools/_host_files.sh"
rm -f "$KEYS"
printf '{"version":"1.0","signing_public_key":"%s"}\n' "$KEY_A" > "$T/public_html/agent_dist/manifest.json"
bash "$RUNNER" --site-root="$T" >/dev/null 2>&1
chk "a run without root writes no key file" "$(test -f "$KEYS" && echo written || echo none)" "none"
# fix_permissions.sh must leave it root's: the config/ data sweep would hand
# it to the web user 0770, and a key file the pool can write is a key file the
# pool can add a key to.
FIXP="$TOOLS/fix_permissions.sh"
chk "fix_permissions.sh pins the key file" "$(grep -c 'VERIFY_KEYS="\$SITE_ROOT/config/release_verify_keys"' "$FIXP")" "1"
chk "root:root 0644" "$(sed -n '/^VERIFY_KEYS=/,/^fi$/p' "$FIXP" | grep -c 'chown root:root "\$VERIFY_KEYS"\|chmod 644 "\$VERIFY_KEYS"')" "2"
chk "and prunes it from the config/ data sweep" "$(sed -n '/^PINNED=(/,/^)$/p' "$FIXP" | grep -c 'config/release_verify_keys')" "1"

echo "== a host installer runs only out of a package we built (specs/package_signing.md WP5) =="
# Ownership says root put the plugin there; the signature says we built it. A
# plugin installed on the owner's acknowledgement stays installed and never
# has a script run as root out of its directory. A throwaway key signs the
# fixture; the real verifier is pointed at from the fixture tree.
PH="$T/public_html"
# The bundle manifest from the key-file section above would have the runner
# rewrite the key file on every tick; this section supplies its own key.
rm -rf "${PH:?}/plugins/signedp" "${PH:?}/plugins/unsignedp" "${T:?}/config/agent_signing_key" "${PH:?}/agent_dist"
php -r '
    $T = $argv[1];
    require $argv[2] . "/includes/PathHelper.php";
    $pair = sodium_crypto_sign_keypair();
    $keys = array("secret" => sodium_crypto_sign_secretkey($pair), "public" => sodium_crypto_sign_publickey($pair));
    file_put_contents("$T/config/release_verify_keys", base64_encode($keys["public"]) . "\n");
    foreach (array("signedp", "unsignedp") as $n) {
        $d = "$T/public_html/plugins/$n";
        @mkdir("$d/install", 0755, true);
        file_put_contents("$d/plugin.json", json_encode(array("name" => $n, "host_installer" => "install/host.sh")));
        file_put_contents("$d/install/host.sh", "#!/bin/bash\necho ran-$n\n");
        chmod("$d/install/host.sh", 0755);
        chmod("$d/plugin.json", 0644);
    }
    TreeManifestPublisher::write("$T/public_html/plugins/signedp", $T, $keys);
' "$T" "$(dirname "$(dirname "$TOOLS")")/public_html" >/dev/null 2>&1
export JOINERY_VERIFY_PACKAGE="$(dirname "$(dirname "$TOOLS")")/public_html/utils/verify_package.php"
export JOINERY_VERIFY_KEYS="$T/config/release_verify_keys"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS=$'signedp\nunsignedp' bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "the signed plugin's installer runs" "$(echo "$out" | grep -c '^ran-signedp$')" "1"
chk "the unsigned plugin's installer does not" "$(echo "$out" | grep -c '^ran-unsignedp$')" "0"
chk "and the log says why" "$(echo "$out" | grep -c 'unsignedp: not a package we built - host installer skipped (verdict: unsigned')" "1"
# Switched off while the run worked through the installers before it: the
# list the run began with names it, what is active now does not.
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='signedp' JOINERY_ACTIVE_PLUGINS_NOW='otherp' bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "a plugin switched off since the run began is not set up again" "$(echo "$out" | grep -c '^ran-signedp$')" "0"
chk "and the log says so" "$(echo "$out" | grep -c '^plugin installers: signedp: switched off since this run began - skipped$')" "1"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='signedp' JOINERY_ACTIVE_PLUGINS_NOW=$'otherp\nsignedp' bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "one still active runs" "$(echo "$out" | grep -c '^ran-signedp$')" "1"
# One byte changed in a signed plugin, and its installer no longer runs.
echo '# edited' >> "$PH/plugins/signedp/install/host.sh"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='signedp' bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "a signed plugin edited after signing is skipped" "$(echo "$out" | grep -c '^ran-signedp$')" "0"
chk "with the tampered verdict" "$(echo "$out" | grep -c 'host installer skipped (verdict: tampered')" "1"
# No key file: nothing verifies, nothing runs, and the reason is the key.
mv "$T/config/release_verify_keys" "$T/config/release_verify_keys.off"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='signedp' bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "with no key file nothing runs" "$(echo "$out" | grep -c '^ran-')" "0"
chk "and the reason is no_keys" "$(echo "$out" | grep -c 'host installer skipped (verdict: no_keys')" "1"
mv "$T/config/release_verify_keys.off" "$T/config/release_verify_keys"
# The publishing box trusts its own tree: the key's secret half is the tell.
touch "$T/config/agent_signing_key"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='unsignedp' bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "the publishing box runs its own unsigned plugin's installer" "$(echo "$out" | grep -c '^ran-unsignedp$')" "1"
echo "== --only-plugin=NAME: one active plugin's installer and nothing else =="
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS=$'signedp\nunsignedp' bash "$T/nogate.sh" --only-plugin=unsignedp --site-root="$T" 2>&1); rc=$?
chk "--only-plugin runs that plugin's installer, exit 0" "$rc:$(echo "$out" | grep -c '^ran-unsignedp$')" "0:1"
chk "and no other plugin's" "$(echo "$out" | grep -c '^ran-signedp$')" "0"
chk "and says ok on the runner's own line" "$(echo "$out" | grep -c '^plugin installers: unsignedp: ok$')" "1"
chk "no core installer, no certificate summary, no key file" "$(echo "$out" | grep -c 'core installers:\|certificates:\|release key:')" "0"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='signedp' bash "$T/nogate.sh" --only-plugin=unsignedp --site-root="$T" 2>&1); rc=$?
chk "a plugin that is not active here is refused" "$rc:$(echo "$out" | grep -c 'unsignedp: not an active plugin here - refused')" "0:1"
out=$(bash "$T/nogate.sh" "--only-plugin=../x" --site-root="$T" 2>&1); rc=$?
chk "a name that is not a plugin identifier is refused with exit 2" "$rc:$(echo "$out" | grep -c 'is not a plugin name - refused')" "2:1"
mkdir -p "$PH/plugins/emptyp"; printf '{"name":"emptyp","host_installer":""}' > "$PH/plugins/emptyp/plugin.json"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='emptyp' bash "$T/nogate.sh" --only-plugin=emptyp --site-root="$T" 2>&1); rc=$?
chk "an empty host_installer is refused with a line, not run as nothing" "$rc:$(echo "$out" | grep -c 'emptyp: declares no host_installer - refused')" "0:1"
out=$(bash "$T/nogate.sh" --only-plugin=signedp --when-changed --site-root="$T" 2>&1); rc=$?
chk "--only-plugin beside another mode is refused with exit 2" "$rc" "2"
rm -f "$T/config/agent_signing_key"
# A tree without the verifier runs nothing rather than everything.
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='signedp' JOINERY_VERIFY_PACKAGE="$T/no-such-tool.php" bash "$T/nogate.sh" --site-root="$T" 2>&1)
chk "no verifier means no installer runs" "$(echo "$out" | grep -c '^ran-')" "0"
chk "and says so" "$(echo "$out" | grep -c 'no verify_package.php to check the package with')" "1"
# The hooks are honoured only because this gate is not root: every check above
# depended on them, and none of them was reported ignored. Root's refusal is
# text-pinned in installer_contract_test, since this gate cannot run as root.
chk "an unprivileged run honours the hooks without saying it ignored one" "$(echo "$out" | grep -c 'hook ignored')" "0"
unset JOINERY_VERIFY_PACKAGE JOINERY_VERIFY_KEYS
# The verification sits after the ownership refusal and before the run.
chk "verification follows the ownership refusal" \
    "$( [ "$(grep -n 'plugin_package_verified "\${PLUGIN}" 2>&1' "$RUNNER" | cut -d: -f1)" -gt "$(grep -n 'installer_is_trusted "\${INSTALLER}"' "$RUNNER" | cut -d: -f1)" ] && echo yes )" "yes"
chk "and precedes the run" \
    "$( [ "$(grep -n 'plugin_package_verified "\${PLUGIN}" 2>&1' "$RUNNER" | cut -d: -f1)" -lt "$(grep -n 'running \${INSTALLER_REL}' "$RUNNER" | cut -d: -f1)" ] && echo yes )" "yes"
chk "the kind list carries install_package and install_theme" "$(grep -c 'upgrade|install_plugin|install_theme|install_package|reconcile_composer' "$RUNNER")" "1"

echo "== remove_plugin: the file half of an uninstall, refused unless the row and the manifest agree =="
# specs/post_release_fleet_defects.md B1. The runner admits the kind; the
# dispatcher deletes only after PluginRemoval::refusal() passes (a plugin name,
# a real directory directly under plugins/, a manifest that is not is_system, a
# row that is uninstalled and not active). The checks themselves run in
# tests/unit/root_request_test.php against a scratch tree; here the wiring.
DISPATCHER="$(dirname "$RUNNER")/../../public_html/utils/root_request.php"
# In the runner's known-kinds case list, wherever that list wraps.
chk "the runner admits remove_plugin" "$(grep -cE '^[[:space:]]*write_agent_files\|save_doc\|set_receives_upgrades\|remove_plugin[|)]' "$RUNNER")" "1"
chk "the dispatcher handles it" "$(grep -c "^	case 'remove_plugin':" "$DISPATCHER")" "1"
chk "and asks the checks before it removes" \
    "$( [ "$(grep -n 'PluginRemoval::refusal(\$name, \$plugins_dir, \$row)' "$DISPATCHER" | cut -d: -f1)" -lt "$(grep -n 'PluginRemoval::remove(\$name, \$plugins_dir)' "$DISPATCHER" | cut -d: -f1)" ] && echo yes )" "yes"
chk "a refusal exits non-zero with the reason" "$(grep -A2 'refused: \$why' "$DISPATCHER" | grep -c 'exit(1)')" "1"
chk "the row is read from the database, not the request" "$(grep -c 'Plugin::get_by_plugin_name(\$name)' "$DISPATCHER")" "1"
chk "the checks refuse an is_system manifest" "$(grep -c "manifest\['is_system'\]" "$(dirname "$RUNNER")/../../public_html/includes/PluginRemoval.php")" "1"
chk "and a row that is not uninstalled" "$(grep -c 'is_uninstalled()' "$(dirname "$RUNNER")/../../public_html/includes/PluginRemoval.php")" "1"

echo "== --machine: the timer for a host with no site =="
chk "the installer takes --machine ROOT" "$(grep -c '^if \[\[ "\${1:-}" == "--machine" \]\]; then$' "$INSTALLER")" "1"
chk "and names the machine host" "$(sed -n '/^if \[\[ "\${1:-}" == "--machine" \]\]; then$/,/^else$/p' "$INSTALLER" | grep -c '^    SITENAME="host"$')" "1"
chk "the machine unit runs the entry point with --when-changed --machine and the bundle root" "$(grep -c '^\[\[ "\${MACHINE}" == "0" \]\] || COMMAND="/bin/bash \${RUN_TARGET} --when-changed --machine --site-root=\${SITE_ROOT}"$' "$INSTALLER")" "1"
chk "the machine log is under /var/log/joinery" "$(grep -c '^    LOG_FILE="/var/log/joinery/host_converger.log"$' "$INSTALLER")" "1"
chk "no path unit on a machine (nothing queues a root request)" "$(grep -B1 'write_if_changed "\${PATH_FILE}"' "$INSTALLER" | grep -c '^    if \[\[ "\${MACHINE}" == "0" \]\]; then$')" "1"
chk "nor is one enabled" "$(grep -c '^    if \[\[ "\${MACHINE}" == "0" \]\] && ! systemctl is-active --quiet "\${UNIT_NAME}.path"' "$INSTALLER")" "1"
chk "a site's unit is left alone" "$(grep -c "a site's converger owns \${UNIT_NAME}.service - leaving it" "$INSTALLER")" "1"
chk "decided by whether the unit already says --machine" "$(grep -c "grep -q -- '--machine' \"\${SERVICE_FILE}\"" "$INSTALLER")" "1"
chk "the machine branch neither checks a site config nor makes a root_requests queue" "$(sed -n '/^if \[\[ "\${MACHINE}" == "1" \]\]; then$/,/^else$/p' "$INSTALLER" | grep -c 'Globalvars_site.php\|root_requests')" "0"
chk "the site branch still does both" "$(grep -c '^    \[\[ -f "\${SITE_ROOT}/config/Globalvars_site.php" \]\] || { say "site not initialised yet - skipping"; exit 0; }$\|^    install -d -m 770 -o root -g "\${WEB_GROUP}" "\${STATE_DIR}/root_requests"$' "$INSTALLER")" "2"
chk "the bundle carries no specs, so the machine unit has no Documentation line" "$(grep -c '^        DOC_LINE=""' "$INSTALLER")" "1"
out="$(bash "$INSTALLER" --machine "$T" 2>&1)"; rc=$?
chk "not root: --machine ROOT skips with exit 0" "$rc:$(printf '%s\n' "$out" | grep -c 'not root - skipping')" "0:1"
out="$(bash "$INSTALLER" --machine 2>&1)"; rc=$?
chk "--machine without a root skips, exit 0, and says what it needed" "$rc:$(printf '%s\n' "$out" | grep -c -- '--machine needs the bundle root')" "0:1"

echo "== a declared data root that is not mounted stops every run (specs/one_data_root.md WP1) =="
# The data root tool reads a fixture's /etc when this is not root. Nothing is
# mounted at the fixture's /srv/joinery, so the host is declared and not ready.
DR="$T/hostroot"
mkdir -p "$DR/etc/joinery" "$DR/srv"
printf 'backing=file\nsource=/srv/joinery.img\n' > "$DR/etc/joinery/data_root"
: > "$DR/srv/joinery.img"
rm -f "$STAMP"
out=$(JOINERY_HOST_ROOT="$DR" bash "$RUNNER" --site-root="$T" 2>&1)
chk "no installer runs" "$(echo "$out" | grep -c 'core installers: running')" "0"
chk "the transcript says the data root is not ready, and why" \
    "$(echo "$out" | grep -c 'the data root is not ready')|$(echo "$out" | grep -c '/srv/joinery is not mounted')" "1|1"
chk "and the run records data-root-not-ready" "$(cut -d' ' -f2 "$LAST")" "data-root-not-ready"
out=$(JOINERY_HOST_ROOT="$DR" bash "$RUNNER" --only=host_housekeeping.sh --site-root="$T" 2>&1)
chk "--only is held too" "$(echo "$out" | grep -c 'core installers: running')|$(echo "$out" | grep -c 'the data root is not ready')" "0|1"
out=$(JOINERY_HOST_ROOT="$DR" bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "and the timer's tick" "$(echo "$out" | grep -c 'core installers: running')|$(echo "$out" | grep -c 'the data root is not ready')" "0|1"
rm -f "$DR/etc/joinery/data_root"
out=$(JOINERY_HOST_ROOT="$DR" bash "$RUNNER" --site-root="$T" 2>&1)
chk "a host with no data root runs as it always has" "$(echo "$out" | grep -c 'core installers: running')|$(echo "$out" | grep -c 'data root')" "$CORE_COUNT|0"

echo "== --remove-site: every plugin's host remover, under the caller's lock (specs/plugin_host_removal.md WP1) =="
# A fresh fixture site. Six plugins on disk: one whose remover records that it
# ran (and what it was told), one whose remover exits 3, one that declares a
# remover outside its directory, one that declares none, one the active list
# does not name (the mode reads no row: it runs), and one not signed. The
# caller holds the site's cache lock on descriptor 9, as hold_host_runner_lock
# does, and the mode runs as its child.
R="$T/rmsite"
mkdir -p "$R/public_html/plugins" "$R/config" "$R/cache" "$R/maintenance_scripts/install_tools"
cp "$(dirname "$RUNNER")"/*.sh "$R/maintenance_scripts/install_tools/" 2>/dev/null || true
echo 0.8.384 > "$R/public_html/VERSION"
echo '<?php' > "$R/config/Globalvars_site.php"
php -r '
    $R = $argv[1];
    require $argv[2] . "/includes/PathHelper.php";
    $pair = sodium_crypto_sign_keypair();
    $keys = array("secret" => sodium_crypto_sign_secretkey($pair), "public" => sodium_crypto_sign_publickey($pair));
    file_put_contents("$R/config/release_verify_keys", base64_encode($keys["public"]) . "\n");
    $plugins = array(
        "okp"       => array("host_remover" => "provisioning/remove.sh", "body" => "echo \"\$1 \$2\" > \"\$(dirname \"\$0\")/../../../../told\"; echo ran-okp\n"),
        "failp"     => array("host_remover" => "provisioning/remove.sh", "body" => "echo ran-failp; echo still-here; exit 3\n"),
        "escapep"   => array("host_remover" => "../okp/provisioning/remove.sh", "body" => "echo ran-escapep\n"),
        "nonep"     => array("host_installer" => "provisioning/install.sh", "body" => "echo ran-nonep\n"),
        "inactivep" => array("host_remover" => "provisioning/remove.sh", "body" => "echo ran-inactivep\n"),
        "unsignedp" => array("host_remover" => "provisioning/remove.sh", "body" => "echo ran-unsignedp\n"),
    );
    foreach ($plugins as $n => $p) {
        $d = "$R/public_html/plugins/$n";
        @mkdir("$d/provisioning", 0755, true);
        $manifest = array("name" => $n);
        foreach (array("host_installer", "host_remover") as $k) { if (isset($p[$k])) { $manifest[$k] = $p[$k]; } }
        file_put_contents("$d/plugin.json", json_encode($manifest));
        file_put_contents("$d/provisioning/remove.sh", "#!/bin/bash\n" . $p["body"]);
        chmod("$d/provisioning/remove.sh", 0755);
        chmod("$d/plugin.json", 0644);
        if ($n !== "unsignedp") { TreeManifestPublisher::write($d, $R, $keys); }
    }
' "$R" "$(dirname "$(dirname "$TOOLS")")/public_html" >/dev/null 2>&1
export JOINERY_VERIFY_PACKAGE="$(dirname "$(dirname "$TOOLS")")/public_html/utils/verify_package.php"
export JOINERY_VERIFY_KEYS="$R/config/release_verify_keys"
RLOCK="$R/cache/host_installers.lock"
# The caller: holds the lock on 9 and runs the mode as a child; RC= carries the child's exit.
remove_site_under_lock() { ( exec 9>>"$RLOCK"; flock -n 9 || { echo "fixture: lock not taken"; exit 99; }; bash "$RUNNER" --remove-site --site-root="$R" "$@" 2>&1; echo "RC=$?" ); }
out=$(JOINERY_ACTIVE_PLUGINS='okp' remove_site_under_lock)
chk "under the caller's lock the mode runs and exits 1 (a remover left something behind)" "$(echo "$out" | grep -c '^RC=1$')" "1"
chk "the remover that finishes ran, told the site name and the site root" "$(cat "$R/told" 2>/dev/null)" "rmsite $R"
chk "and is said ok" "$(echo "$out" | grep -c '^plugin removers: okp: ok$')" "1"
chk "the failing remover ran, its output in the transcript" "$(echo "$out" | grep -c '^still-here$')" "1"
chk "and is said to have left something behind, with its exit code" "$(echo "$out" | grep -c '^plugin removers: failp: left something behind (exit 3)$')" "1"
chk "a remover declared outside its plugin directory is refused, never run" "$(echo "$out" | grep -c '^ran-escapep$')|$(echo "$out" | grep -c '^plugin removers: escapep: left something behind (host_remover escapes plugin directory - refused)$')" "0|1"
chk "a plugin with no remover is skipped and said" "$(echo "$out" | grep -c '^plugin removers: nonep: declares no host remover - skipped$')|$(echo "$out" | grep -c '^ran-nonep$')" "1|0"
chk "a plugin the active list does not name runs anyway (every plugin on disk)" "$(echo "$out" | grep -c '^ran-inactivep$')" "1"
chk "an unsigned plugin's remover is refused and counted as left" "$(echo "$out" | grep -c '^ran-unsignedp$')|$(echo "$out" | grep -c '^plugin removers: unsignedp: left something behind (.*not a package we built - host remover skipped')" "0|1"
chk "the last line names every plugin that left something" "$(echo "$out" | grep -c '^plugin removers: machine state left behind by: escapep failp unsignedp$')" "1"
chk "nothing below the lock block ran: no lock taken, no core installer, no record, no stamp" \
    "$(echo "$out" | grep -c 'core installers:\|another run holds the lock\|certificates:\|release key:')|$(test -e "$R/cache/host_converger.last" && echo record || echo none)|$(ls "$R/cache/plugin_installers" 2>/dev/null | wc -l)" "0|none|0"
# The lock not held: nothing runs, exit 2.
rm -f "$R/told"
out=$(bash "$RUNNER" --remove-site --site-root="$R" 2>&1); rc=$?
chk "with the lock not held the mode is refused with exit 2" "$rc:$(echo "$out" | grep -c 'is not held by a caller of this run - refused, nothing ran')" "2:1"
chk "and no remover ran" "$(test -e "$R/told" && echo ran || echo none)|$(echo "$out" | grep -c '^ran-')" "none|0"
# Descriptor 9 open on the lock file but not locked: flock -n 9 would pass
# trivially; the fresh descriptor is not refused, so this is not held.
out=$( ( exec 9>>"$RLOCK"; bash "$RUNNER" --remove-site --site-root="$R" 2>&1; echo "RC=$?" ) )
chk "descriptor 9 open on the file without the lock does not count as held" "$(echo "$out" | grep -c '^RC=2$')|$(echo "$out" | grep -c '^ran-')" "1|0"
# Descriptor 9 locked on some other file: the lock file itself is free.
out=$( ( exec 9>>"$R/cache/other.lock"; flock -n 9; bash "$RUNNER" --remove-site --site-root="$R" 2>&1; echo "RC=$?" ) )
chk "a lock held on another file does not count either" "$(echo "$out" | grep -c '^RC=2$')|$(echo "$out" | grep -c '^ran-')" "1|0"
# The lock held by someone who is not an ancestor: refused on the fresh
# descriptor, but 9 is not the file.
( exec 9>>"$RLOCK"; flock -n 9; sleep 3 ) &
sleep 0.5
out=$(bash "$RUNNER" --remove-site --site-root="$R" 2>&1); rc=$?
wait
chk "a lock held by a stranger (not in this process's line) is not the caller's" "$rc:$(echo "$out" | grep -c '^ran-')" "2:0"
# No site config: the removers read it, so nothing runs.
mv "$R/config/Globalvars_site.php" "$R/config/Globalvars_site.php.off"
out=$(remove_site_under_lock)
chk "a site root without config/Globalvars_site.php is refused with exit 2, said" "$(echo "$out" | grep -c '^RC=2$')|$(echo "$out" | grep -c 'has no config/Globalvars_site.php, which the removers read - refused, nothing ran')|$(echo "$out" | grep -c '^ran-')" "1|1|0"
mv "$R/config/Globalvars_site.php.off" "$R/config/Globalvars_site.php"
# A site with no plugins directory: nothing declares a remover, exit 0.
R2="$T/rmsite2"; mkdir -p "$R2/public_html" "$R2/config" "$R2/cache"; echo '<?php' > "$R2/config/Globalvars_site.php"
out=$( ( exec 9>>"$R2/cache/host_installers.lock"; flock -n 9; bash "$RUNNER" --remove-site --site-root="$R2" 2>&1; echo "RC=$?" ) )
chk "a site with no plugins directory exits 0 with nothing to take off" "$(echo "$out" | grep -c '^RC=0$')|$(echo "$out" | grep -c 'no plugins directory - nothing to take off the machine')" "1|1"
# Every remover finishing: exit 0.
rm -rf "${R:?}/public_html/plugins/failp" "${R:?}/public_html/plugins/escapep" "${R:?}/public_html/plugins/unsignedp"
out=$(remove_site_under_lock)
chk "with every declared remover finishing the mode exits 0" "$(echo "$out" | grep -c '^RC=0$')|$(echo "$out" | grep -c '^plugin removers: every declared remover finished$')" "1|1"
out=$(bash "$RUNNER" --remove-site --when-changed --site-root="$R" 2>&1); rc=$?
chk "--remove-site beside another mode is refused with exit 2" "$rc" "2"
# Source order: the mode branches before the lock block, with the definitions it
# needs above it, and proves the lock on a fresh descriptor, never with flock -n 9.
MODE_LINE="$(grep -n '^if \[\[ "\${REMOVE_SITE}" == "1" \]\]; then$' "$RUNNER" | tail -1 | cut -d: -f1)"
LOCK_LINE="$(grep -n '^# --- One runner at a time' "$RUNNER" | cut -d: -f1)"
chk "the mode sits before the lock block" "$( [ "$MODE_LINE" -lt "$LOCK_LINE" ] && echo yes )" "yes"
for def in 'TREE_OWNER_TARGET="$(joinery_tree_owner_record' '^installer_is_trusted() {' '^plugin_package_verified() {' '^run_one_plugin_remover() {' '^caller_holds_runner_lock() {'; do
    chk "defined above it: ${def:0:40}" "$( [ "$(grep -n -- "$def" "$RUNNER" | head -1 | cut -d: -f1)" -lt "$MODE_LINE" ] && echo yes )" "yes"
done
for below in '^assert_tree_ownership$' 'bash "\${DATA_ROOT_TOOL}" tick || DATA_ROOT_RC' '^run_root_requests$' '^refresh_converger_entry$' '^    run_core_installer "\${ONLY_INSTALLER}"$'; do
    chk "below it, never reached: ${below:0:40}" "$( [ "$(grep -n -- "$below" "$RUNNER" | head -1 | cut -d: -f1)" -gt "$LOCK_LINE" ] && echo yes )" "yes"
done
chk "the lock is never tried on descriptor 9 itself" "$(grep -c '^[^#]*flock -n 9' "$RUNNER")" "0"
chk "the fresh descriptor is closed before any remover runs" "$( [ "$(grep -n 'exec 8<&-' "$RUNNER" | cut -d: -f1)" -lt "$(grep -n 'run_one_plugin_remover "\$(basename' "$RUNNER" | cut -d: -f1)" ] && echo yes )" "yes"
unset JOINERY_VERIFY_PACKAGE JOINERY_VERIFY_KEYS

echo "== run_plugin_remover: the uninstall moment, carried out by the request loop (specs/plugin_host_removal.md WP5) =="
# A fresh fixture site whose dispatcher is a stand-in: it answers --check and
# --remover-ran from a row state the gate writes per plugin ($RQ/rows/NAME:
# uninstalled or active; absent = no row), removing the directory on
# --remover-ran, and refuses the kind handed over whole as the real one does.
# The runner's own request loop is what runs here (root gate stripped).
RQ="$T/rqsite"; RPH="$RQ/public_html"; RQQ="$RQ/cache/root_requests"
mkdir -p "$RPH/plugins" "$RPH/utils" "$RQ/config" "$RQQ" "$RQ/logs" "$RQ/maintenance_scripts/install_tools" "$RQ/rows"
cp "$(dirname "$RUNNER")"/*.sh "$RQ/maintenance_scripts/install_tools/" 2>/dev/null || true
echo 0.8.999 > "$RPH/VERSION"
echo '<?php' > "$RQ/config/Globalvars_site.php"
cat > "$RPH/utils/root_request.php" <<'PHP'
<?php
// Stand-in dispatcher for the gate: the real one's answers, from a fixture.
$site = dirname(dirname(__DIR__));
$kind = $argv[1] ?? ''; $id = '';
foreach (array_slice($argv, 2) as $a) { if (strpos($a, '--request=') === 0) { $id = substr($a, 10); } }
$req = json_decode((string)@file_get_contents("$site/cache/root_requests/running/$id.json"), true);
$name = (string)($req['args']['plugin'] ?? '');
echo "DISPATCHER $kind " . implode(' ', array_slice($argv, 3)) . "\n";
if ($kind !== 'run_plugin_remover') { echo "DISPATCHER WHOLE\n"; exit(2); }
$check = in_array('--check', $argv, true); $ran = in_array('--remover-ran', $argv, true);
if (!$check && !$ran) { fwrite(STDERR, "root_request: this runner is older than the tree\n"); exit(2); }
$dir = "$site/public_html/plugins/$name";
if (!is_dir($dir)) { echo "plugins/$name is already gone\n"; exit($check ? 3 : 0); }
$row = @file_get_contents("$site/rows/$name");
if ($row === false) { fwrite(STDERR, "root_request: run_plugin_remover refused: plugin '$name' has no database row\n"); exit(1); }
if (trim($row) !== 'uninstalled') { fwrite(STDERR, "root_request: run_plugin_remover refused: plugin '$name' is '" . trim($row) . "', not uninstalled\n"); exit(1); }
if ($check) { echo "plugins/$name may be removed: its row says uninstalled\n"; exit(0); }
exec('rm -rf ' . escapeshellarg($dir));
echo "removed plugins/$name\n"; exit(0);
PHP
# Signed fixture plugins (a throwaway key), each with a remover that records
# that it ran, except as named.
php -r '
    $R = $argv[1];
    require $argv[2] . "/includes/PathHelper.php";
    $pair = sodium_crypto_sign_keypair();
    $keys = array("secret" => sodium_crypto_sign_secretkey($pair), "public" => sodium_crypto_sign_publickey($pair));
    file_put_contents("$R/config/release_verify_keys", base64_encode($keys["public"]) . "\n");
    $plugins = array(
        "remp"     => "echo ran-remp; echo x >> \"$R/remp.count\"\n",
        "activep"  => "echo ran-activep\n",
        "failremp" => "echo ran-failremp; echo role-left; exit 3\n",
        "gonep"    => "echo ran-gonep\n",
    );
    foreach ($plugins as $n => $body) {
        $d = "$R/public_html/plugins/$n";
        @mkdir("$d/provisioning", 0755, true);
        file_put_contents("$d/plugin.json", json_encode(array("name" => $n, "host_remover" => "provisioning/remove.sh")));
        file_put_contents("$d/provisioning/remove.sh", "#!/bin/bash\n" . $body);
        chmod("$d/provisioning/remove.sh", 0755); chmod("$d/plugin.json", 0644);
        TreeManifestPublisher::write($d, $R, $keys);
    }
    @mkdir("$R/public_html/plugins/nonep", 0755, true);
    file_put_contents("$R/public_html/plugins/nonep/plugin.json", json_encode(array("name" => "nonep")));
    TreeManifestPublisher::write("$R/public_html/plugins/nonep", $R, $keys);
' "$RQ" "$(dirname "$(dirname "$TOOLS")")/public_html" >/dev/null 2>&1
rm -rf "${RPH:?}/plugins/gonep"                       # its row says uninstalled, its files are already gone
for p in remp failremp gonep nonep; do echo uninstalled > "$RQ/rows/$p"; done
echo active > "$RQ/rows/activep"
export JOINERY_VERIFY_PACKAGE="$(dirname "$(dirname "$TOOLS")")/public_html/utils/verify_package.php"
export JOINERY_VERIFY_KEYS="$RQ/config/release_verify_keys"
sed 's/\[\[ "$(id -u)" == "0" \]\] || return 0//' "$RUNNER" > "$RQ/nogate.sh"
rq_request() { printf '{"kind":"run_plugin_remover","args":{"plugin":"%s"},"requested_by":1,"requested_at":%s}' "$2" "$(date +%s)" > "$RQQ/$1.json"; }
rq_exit() { cat "$RQQ/$1/$2.exit" 2>/dev/null; }
rq_log() { cat "$RQ/logs/root_requests/$1.log" 2>/dev/null; }
N=$(date +%s)
rq_request "$N-b0000001" remp
rq_request "$N-b0000002" activep
rq_request "$N-b0000003" failremp
rq_request "$N-b0000004" gonep
rq_request "$N-b0000005" nonep
rq_request "$N-b0000006" 'Bad;name'
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_ACTIVE_PLUGINS='' timeout 120 bash "$RQ/nogate.sh" --site-root="$RQ" 2>&1); rc=$?
chk "the pass finishes, exit 0" "$rc" "0"
chk "the runner admits the kind beside run_plugin_installer" "$(grep -c '^[[:space:]]*run_plugin_installer|run_plugin_remover) : ;;$' "$RUNNER")" "1"
chk "an uninstalled plugin: the request is done, its remover ran once, its directory is gone" \
    "$(rq_exit done "$N-b0000001")|$(wc -l < "$RQ/remp.count" 2>/dev/null | tr -d ' ')|$(test -d "$RPH/plugins/remp" && echo kept || echo gone)" "0|1|gone"
chk "in that order: the check, the remover, then the files" \
    "$(rq_log "$N-b0000001" | grep -n -e 'DISPATCHER run_plugin_remover --check' -e '^ran-remp$' -e 'DISPATCHER run_plugin_remover --remover-ran' -e '^removed plugins/remp$' | cut -d: -f2- | tr '\n' '|')" \
    "DISPATCHER run_plugin_remover --check|ran-remp|DISPATCHER run_plugin_remover --remover-ran|removed plugins/remp|"
chk "a plugin whose row is active: refused before anything ran, exit 2, directory kept" \
    "$(rq_exit failed "$N-b0000002")|$(rq_log "$N-b0000002" | grep -c '^ran-activep$')|$(rq_log "$N-b0000002" | grep -c "is 'active', not uninstalled")|$(test -d "$RPH/plugins/activep" && echo kept || echo gone)" "2|0|1|kept"
chk "a remover that left something: exit 1, its output in the transcript, directory kept, the files never asked for" \
    "$(rq_exit failed "$N-b0000003")|$(rq_log "$N-b0000003" | grep -c '^role-left$')|$(rq_log "$N-b0000003" | grep -c 'left something behind (failed); plugins/failremp is kept so the removal can be run again')|$(test -d "$RPH/plugins/failremp" && echo kept || echo gone)|$(rq_log "$N-b0000003" | grep -c -- '--remover-ran')" "1|1|1|kept|0"
chk "a directory already gone: done, exit 0, nothing run" "$(rq_exit done "$N-b0000004")|$(rq_log "$N-b0000004" | grep -c 'already gone - done')|$(rq_log "$N-b0000004" | grep -c '^ran-')" "0|1|0"
chk "a plugin that declares no remover: said, and its files go" "$(rq_exit done "$N-b0000005")|$(rq_log "$N-b0000005" | grep -c 'declares no host remover; its files go')|$(test -d "$RPH/plugins/nonep" && echo kept || echo gone)" "0|1|gone"
chk "a name that is not a plugin name: refused, exit 2" "$(rq_exit failed "$N-b0000006")|$(rq_log "$N-b0000006" | grep -c 'names no plugin this box could have - refused')" "2|1"
chk "the dispatcher was never handed the kind whole" "$(cat "$RQ"/logs/root_requests/*.log | grep -c 'DISPATCHER WHOLE')" "0"
chk "the real dispatcher carries both halves and refuses the whole" \
    "$(grep -c "in_array('--check', \$argv, true)" "$DISPATCHER")|$(grep -c "in_array('--remover-ran', \$argv, true)" "$DISPATCHER")|$(grep -c 'this runner is older than the tree' "$DISPATCHER")" "1|1|2"
unset JOINERY_VERIFY_PACKAGE JOINERY_VERIFY_KEYS

echo
echo "host_converger gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
