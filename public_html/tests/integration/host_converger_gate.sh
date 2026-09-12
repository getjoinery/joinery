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
# The certificate summary the runner writes for the admin notice is pinned from
# a fixture lineage (specs/implemented/tls_and_origin_trust.md WP11). The
# release verification key the runner writes from the agent bundle is pinned
# from a throwaway key (specs/package_signing.md WP1).

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
    { cat "$T/public_html/VERSION" "$tools"/*.sh "$tools"/default_*.conf "$tools"/vhost_history/*.conf 2>/dev/null
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
chk "no letsencrypt directory still writes the summary" "$(echo "$out" | grep -c 'certificates: summary written to cache/certificates.json (0 lineage')" "1"
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
# The runner as it really runs, without root: it must not write the file.
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
rm -rf "$PH/plugins/signedp" "$PH/plugins/unsignedp" "$T/config/agent_signing_key" "$PH/agent_dist"
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
    "$( [ "$(grep -n 'plugin_package_verified "\${PLUGIN}"' "$RUNNER" | cut -d: -f1)" -gt "$(grep -n 'installer_is_trusted "\${INSTALLER}"' "$RUNNER" | cut -d: -f1)" ] && echo yes )" "yes"
chk "and precedes the run" \
    "$( [ "$(grep -n 'plugin_package_verified "\${PLUGIN}"' "$RUNNER" | cut -d: -f1)" -lt "$(grep -n 'running \${INSTALLER_REL}' "$RUNNER" | cut -d: -f1)" ] && echo yes )" "yes"
chk "the kind list carries install_package and install_theme" "$(grep -c 'upgrade|install_plugin|install_theme|install_package|reconcile_composer' "$RUNNER")" "1"

echo
echo "host_converger gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
