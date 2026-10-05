#!/bin/bash
# @joinery-test
# name: spam_scanner_provisioner
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# The spam scanner provisioner is a root script that installs packages and
# rewrites /etc/rspamd. Here its install runs for real against a scratch
# local.d with every system command stubbed on PATH (apt-get, dpkg-query,
# systemctl, postconf ...), so nothing on this machine is touched and the
# script's own logic is what is tested (spam_learning_in_core.md,
# mail_checking_in_rspamd.md):
#
#   - install leaves the five stateless files and the signing file, deletes
#     redis.conf and worker-controller.inc, and purges redis-server;
#   - rspamd is the only milter on both of Postfix's lists, strips every
#     Authentication-Results line a message arrives with, and signs for exactly
#     the domains that have a key, including a key opendkim signed with;
#   - a second install succeeds on a box whose redis is already gone, and
#     changes nothing;
#   - classifier-bayes.conf is present with enabled = false (rspamd's stock
#     statistic.conf still declares a redis classifier; deleting the file would
#     log an error on every scan);
#   - the relay writes the same five files: its write loop runs against a
#     second scratch dir and the two are diffed with comments stripped;
#   - the X-Spam header contract matches InboundEmailRouter, rejection stays
#     off, remove unwires Postfix before purging and still purges redis-server
#     for a box that never upgraded;
#   - install_email.sh calls the provisioner unconditionally, names neither
#     opendkim nor opendmarc as a milter, and removes both only after it.

PROVISIONING="${PROVISIONING_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../provisioning" && pwd)}"
SCANNER="$PROVISIONING/provision_spam_scanner.sh"
SHARED="$PROVISIONING/rspamd_stateless.sh"
RELAY="$PROVISIONING/provision_relay.sh"
INSTALLER="$PROVISIONING/install_email.sh"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
ROUTER="$ROOT_DIR/plugins/mailbox/includes/InboundEmailRouter.php"
BUNDLE="$ROOT_DIR/plugins/server_manager/includes/SupportBundlePublisher.php"

T=$(mktemp -d) || { echo "mktemp failed"; exit 1; }
# Every rm below is under $T; refuse to go on unless it is a real temp dir.
[ -n "$T" ] && [ -d "$T" ] && [ "$T" != "/" ] || { echo "no scratch dir"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== the scripts exist and parse =="
for f in "$SCANNER" "$SHARED" "$RELAY" "$INSTALLER"; do
    chk "$(basename "$f") parses" "$(bash -n "$f" 2>/dev/null && echo yes || echo no)" "yes"
done

echo "== verbs are dispatched, never guessed =="
out=$(bash "$SCANNER" 2>&1); rc=$?
chk "no verb exits 2"            "$rc" "2"
chk "no verb prints usage"       "$(echo "$out" | grep -c 'Usage:')" "1"
out=$(bash "$SCANNER" frobnicate 2>&1); rc=$?
chk "unknown verb exits 2"       "$rc" "2"

# --- a stubbed machine -------------------------------------------------------
# A copy of the scanner beside the shared file, its root check made a no-op;
# every system command it calls is a stub that logs and answers from state.
mkdir -p "$T/prov" "$T/bin" "$T/state" "$T/rspamd/local.d" "$T/relay.d" "$T/dkim" "$T/opendkim/keys/old.example.test"
ln -s "$T/rspamd/local.d" "$T/local.d"
cp "$SHARED" "$T/prov/rspamd_stateless.sh"
# The test's copy answers "is rspamd up" from RSPAMD_MILTER_ANSWERS. Appended
# here, so the shipped file has no such switch on its safety check.
chk "the shipped check takes no answer from the environment" "$(grep -c 'RSPAMD_MILTER_ANSWERS' "$SHARED")" "0"
cat >> "$T/prov/rspamd_stateless.sh" <<'SEAM'
rspamd_milter_answers() { [ "${RSPAMD_MILTER_ANSWERS:-yes}" = "yes" ]; }
SEAM
sed 's/^need_root() {$/need_root() { return 0; }\nneed_root_unused() {/' "$SCANNER" > "$T/prov/provision_spam_scanner.sh"
LOG="$T/calls.log"
: > "$LOG"
cat > "$T/bin/dpkg-query" <<'STUB'
#!/bin/bash
pkg="${@: -1}"
[ -f "$STUB_STATE/$pkg" ] && echo -n "install ok installed" && exit 0
exit 1
STUB
cat > "$T/bin/apt-get" <<'STUB'
#!/bin/bash
echo "apt-get $*" >> "$STUB_LOG"
if [ "$1" = "purge" ]; then shift; for p in "$@"; do [ "$p" = "-y" ] || rm -f "${STUB_STATE:?}/$p"; done; fi
if [ "$1" = "install" ]; then for p in "$@"; do case "$p" in -*|install) ;; *) touch "$STUB_STATE/$p";; esac; done; fi
exit 0
STUB
for c in systemctl service postconf postfix pgrep chown rspamadm; do
    printf '#!/bin/bash\necho "%s $*" >> "$STUB_LOG"\n[ "%s" = "postconf" ] && [ "$1" = "-h" ] && echo "inet:localhost:8891, inet:localhost:8893"\n' "$c" "$c" > "$T/bin/$c"
    # STUB_NOINIT=1: a container with no init — nothing can reload or start a service.
    if [ "$c" = "systemctl" ] || [ "$c" = "service" ] || [ "$c" = "pgrep" ]; then
        printf '[ -n "$STUB_NOINIT" ] && exit 1\n' >> "$T/bin/$c"
    fi
    printf 'exit 0\n' >> "$T/bin/$c"
done
chmod +x "$T/bin/"*
export STUB_LOG="$LOG" STUB_STATE="$T/state"
# Signing keys live in a scratch directory, and the box "signed with opendkim"
# for one domain: its key sits where opendkim kept it.
export RSPAMD_DKIM_DIR="$T/dkim" RSPAMD_OLD_DKIM_DIR="$T/opendkim/keys" RSPAMD_MILTER_ANSWERS=yes
echo 'old private key' > "$T/opendkim/keys/old.example.test/mail.private"
echo '"v=DKIM1; k=rsa; " "p=OLD"' > "$T/opendkim/keys/old.example.test/mail.txt"
mkdir -p "$T/opendkim/keys/notakey.example.test"

# An upgrading box: rspamd and redis installed, the learning-era files present.
touch "$T/state/rspamd" "$T/state/redis-server"
echo 'servers = "127.0.0.1:6379";' > "$T/local.d/redis.conf"
echo 'bind_socket = "127.0.0.1:11334";' > "$T/local.d/worker-controller.inc"
echo 'backend = "redis";' > "$T/local.d/classifier-bayes.conf"
echo '# an operator override' > "$T/local.d/custom.conf"
# Its milter already listens where this version says, so nothing needs a restart.
( source "$T/prov/rspamd_stateless.sh"; rspamd_stateless_render worker-proxy.inc > "$T/local.d/worker-proxy.inc" )

echo "== install on an upgrading box =="
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/rspamd/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "install exits 0"            "$rc" "0"
for f in milter_headers.conf actions.conf classifier-bayes.conf rbl.conf worker-proxy.inc dkim_signing.conf; do
    chk "writes $f"              "$([ -f "$T/local.d/$f" ] && echo yes || echo no)" "yes"
done
chk "deletes redis.conf"         "$([ -f "$T/local.d/redis.conf" ] && echo present || echo gone)" "gone"
chk "deletes worker-controller.inc" "$([ -f "$T/local.d/worker-controller.inc" ] && echo present || echo gone)" "gone"
chk "leaves an operator file"    "$([ -f "$T/local.d/custom.conf" ] && echo yes || echo no)" "yes"
chk "bayes is off"               "$(grep -c '^enabled = false;' "$T/local.d/classifier-bayes.conf")" "1"
chk "autolearn is off"           "$(grep -c '^autolearn = false;' "$T/local.d/classifier-bayes.conf")" "1"
chk "no redis setting left"      "$(cat "$T/local.d"/*.conf "$T/local.d"/*.inc | grep -v '^[[:space:]]*#' | grep -c redis)" "0"
chk "purges redis-server"        "$(grep -c '^apt-get purge -y redis-server$' "$LOG")" "1"
chk "redis is absent after"      "$([ -f "$T/state/redis-server" ] && echo present || echo absent)" "absent"
chk "never installs redis"       "$(grep -E '^apt-get install' "$LOG" | grep -c redis)" "0"
chk "reloads rspamd (its milter stays up)" "$(grep -c 'systemctl reload rspamd' "$LOG")" "1"
chk "never restarts a running rspamd" "$(grep -c 'systemctl restart rspamd' "$LOG")" "0"
chk "no autoremove on install"   "$(grep -c 'autoremove' "$LOG")" "0"

echo "== rspamd is the only milter, and it signs =="
chk "arriving mail goes to rspamd alone" "$(grep -c '^postconf -e smtpd_milters = inet:localhost:11332$' "$LOG")" "1"
chk "outgoing mail goes to rspamd alone" "$(grep -c '^postconf -e non_smtpd_milters = inet:localhost:11332$' "$LOG")" "1"
chk "Postfix is told after rspamd has the configuration" \
    "$([ "$(grep -n 'systemctl reload rspamd' "$LOG" | head -1 | cut -d: -f1)" -lt "$(grep -n 'postconf -e smtpd_milters' "$LOG" | head -1 | cut -d: -f1)" ] && echo yes || echo no)" "yes"
chk "every arriving verdict line is stripped" \
    "$(tr -d ' \n' < "$T/local.d/milter_headers.conf" | grep -c 'routines{authentication-results{remove=0;}}')" "1"
chk "rspamd writes its own verdict line" "$(grep -c '"authentication-results"' "$T/local.d/milter_headers.conf")" "1"
chk "the key opendkim signed with is copied" "$(cat "$T/dkim/old.example.test/mail.private" 2>/dev/null)" "old private key"
chk "the old copy is left for the retire step" "$([ -f "$T/opendkim/keys/old.example.test/mail.private" ] && echo yes || echo no)" "yes"
chk "a directory with no key is not adopted" "$([ -e "$T/dkim/notakey.example.test" ] && echo yes || echo no)" "no"
chk "the signing list names that domain and its key" "$(cat "$T/dkim/signing.map")" "old.example.test $T/dkim/old.example.test/mail.private"
chk "signing reads the list"     "$(grep -c "^path_map = \"$T/dkim/signing.map\";$" "$T/local.d/dkim_signing.conf")" "1"
chk "a domain with no key is never signed" "$(grep -c '^try_fallback = false;$' "$T/local.d/dkim_signing.conf")" "1"
chk "the key is chosen by the exact From domain" "$(grep -c '^use_esld = false;$' "$T/local.d/dkim_signing.conf")" "1"
chk "the selector is mail"       "$(grep -c '^selector = "mail";$' "$T/local.d/dkim_signing.conf")" "1"
chk "the key is handed to rspamd and the web group" "$(grep -c "^chown _rspamd:www-data $T/dkim/old.example.test/mail.private$" "$LOG")" "1"
chk "the key is not world-readable" "$(stat -c '%a' "$T/dkim/old.example.test/mail.private")" "640"

echo "== rspamd is reachable, and counts as local, from this box alone =="
chk "the milter adds no listening address of its own" "$(grep -v '^[[:space:]]*#' "$T/local.d/worker-proxy.inc" | grep -c bind_socket)" "0"
chk "local means the loopback, written where it replaces rspamd's own list" \
    "$(grep -c '^local_addrs = \["127.0.0.0/8", "::1"\];$' "$T/rspamd/override.d/options.inc")" "1"
chk "the GTUBE test string refuses nothing" "$(grep -c '^gtube_patterns = "disable";$' "$T/rspamd/override.d/options.inc")" "1"
chk "no private range is local"  "$(grep -v '^[[:space:]]*#' "$T/rspamd/override.d/options.inc" | grep -c -E '192\.168|10\.0|172\.16|fd00')" "0"
chk "the configuration is tested before rspamd is given it" \
    "$([ "$(grep -n '^rspamadm configtest' "$LOG" | head -1 | cut -d: -f1)" -lt "$(grep -n 'systemctl reload rspamd' "$LOG" | head -1 | cut -d: -f1)" ] && echo yes || echo no)" "yes"

echo "== a second install succeeds and changes nothing =="
: > "$LOG"
before=$(cat "$T/local.d"/* | sha256sum)
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/rspamd/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "second install exits 0"     "$rc" "0"
chk "files unchanged"            "$(cat "$T/local.d"/* | sha256sum)" "$before"
chk "the signing list is unchanged" "$(cat "$T/dkim/signing.map")" "old.example.test $T/dkim/old.example.test/mail.private"
chk "nothing purged"             "$(grep -c 'apt-get purge' "$LOG")" "0"
chk "says redis is gone"         "$(echo "$out" | grep -c 'redis-server: not installed')" "1"

echo "== a fresh box never gets redis, and signs for nobody =="
rm -rf "${T:?}/state"/* "${T:?}/local.d"/* "${T:?}/dkim" "${T:?}/opendkim"; : > "$LOG"
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/rspamd/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "fresh install exits 0"      "$rc" "0"
chk "the signing list exists and is empty" "$([ -f "$T/dkim/signing.map" ] && wc -c < "$T/dkim/signing.map")" "0"
chk "installs rspamd without recommends" "$(grep -c '^apt-get install -y --no-install-recommends rspamd$' "$LOG")" "1"
chk "redis never appears"        "$([ -f "$T/state/redis-server" ] && echo present || echo absent)" "absent"

echo "== the milter's listening address changing means a restart =="
echo 'bind_socket = "*:11332";' >> "$T/local.d/worker-proxy.inc"; : > "$LOG"
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/rspamd/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "install exits 0"            "$rc" "0"
chk "the wide listening address is gone" "$(grep -v '^[[:space:]]*#' "$T/local.d/worker-proxy.inc" | grep -c bind_socket)" "0"
chk "rspamd is restarted, not reloaded" "$(grep -c 'systemctl restart rspamd' "$LOG"):$(grep -c 'systemctl reload rspamd' "$LOG")" "1:0"

echo "== rspamd not answering: Postfix keeps what it had =="
: > "$LOG"; echo '# drift' >> "$T/local.d/actions.conf"
out=$(RSPAMD_MILTER_ANSWERS=no PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/rspamd/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "install exits 0"            "$rc" "0"
chk "neither milter list is touched" "$(grep -c '^postconf -e' "$LOG")" "0"
chk "and it says why"            "$(echo "$out" | grep -c "Postfix's milter lists are left as they are")" "1"
out=$(RSPAMD_MILTER_ANSWERS=no bash -c 'set -euo pipefail; source "$1"; mail_checkers_retire_old' _ "$T/prov/rspamd_stateless.sh" 2>&1); rc=$?
chk "the old programs are not removed either" "$rc:$(echo "$out" | grep -c 'left as they are')" "0:1"

echo "== keys: adopted by file, dropped only when identical, forgotten on removal =="
K="$T/k"; mkdir -p "$K/new" "$K/opendkim/keys/a.example" "$K/opendkim/keys/b.example" "$K/new/b.example"
echo 'key a' > "$K/opendkim/keys/a.example/mail.private"
echo 'key b' > "$K/opendkim/keys/b.example/mail.private"; echo 'txt b' > "$K/opendkim/keys/b.example/mail.txt"
keys() { RSPAMD_DKIM_DIR="$K/new" RSPAMD_OLD_DKIM_DIR="$K/opendkim/keys" bash -c 'set -euo pipefail; source "$1"; shift; "$@"' _ "$T/prov/rspamd_stateless.sh" "$@" 2>&1; }
keys rspamd_dkim_adopt_old_keys >/dev/null
chk "a key is adopted"           "$(cat "$K/new/a.example/mail.private" 2>/dev/null)" "key a"
chk "a directory left without its key still gets it" "$(cat "$K/new/b.example/mail.private" 2>/dev/null)" "key b"
chk "and its record with it"     "$(cat "$K/new/b.example/mail.txt" 2>/dev/null)" "txt b"
chk "nothing half-copied is left behind" "$(ls -A "$K/new" | grep -c '^\.adopting')" "0"
chk "both are on the signing list" "$(keys rspamd_dkim_render_map | wc -l)" "2"
echo 'key a CHANGED' > "$K/new/a.example/mail.private"
keys rspamd_dkim_drop_old_keys >/dev/null; rc=$?
chk "a key with a differing copy keeps the old directory" "$rc:$([ -d "$K/opendkim" ] && echo kept)" "1:kept"
rm -f "$K/new/a.example/mail.private"
keys rspamd_dkim_drop_old_keys >/dev/null; rc=$?
chk "a key with no copy keeps the old directory" "$rc:$([ -d "$K/opendkim" ] && echo kept)" "1:kept"
keys rspamd_dkim_forget_old_key a.example >/dev/null
chk "removing a domain's key removes its old copy" "$([ -e "$K/opendkim/keys/a.example" ] && echo present || echo gone)" "gone"
chk "and only that domain's"     "$([ -f "$K/opendkim/keys/b.example/mail.private" ] && echo kept)" "kept"
keys rspamd_dkim_drop_old_keys >/dev/null; rc=$?
chk "every remaining key identical: the old directory goes" "$rc:$([ -d "$K/opendkim" ] && echo kept || echo gone)" "0:gone"
chk "the adopted keys stay"      "$(cat "$K/new/b.example/mail.private")" "key b"
mkdir -p "$K/x"; echo k > "$K/x/mail.private"
out=$(RSPAMD_DKIM_DIR="$K/new" RSPAMD_OLD_DKIM_DIR="/keys" bash -c 'source "$1"; rspamd_dkim_drop_old_keys' _ "$T/prov/rspamd_stateless.sh" 2>&1); rc=$?
chk "an old key directory one level below the root is refused, never deleted" "$rc:$(echo "$out" | grep -c 'refusing')" "1:1"
for f in provision_dkim.sh provision_relay_main.sh; do
    chk "$f removal also deletes the old copy" "$(grep -c -E 'rspamd_dkim_forget_old_key "\$\{DOMAIN\}"|rm -rf "\$\{OLD_KEY_ROOT:\?\}/\$\{DOMAIN:\?\}"' "$PROVISIONING/$f")" "1"
done

echo "== with no init, redis stays until rspamd can take the new configuration =="
rm -rf "${T:?}/state"/* "${T:?}/local.d"/*; : > "$LOG"
touch "$T/state/rspamd" "$T/state/redis-server"
echo 'servers = "127.0.0.1:6379";' > "$T/local.d/redis.conf"
out=$(STUB_NOINIT=1 PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/rspamd/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "install still exits 0"      "$rc" "0"
chk "redis is not purged"        "$(grep -c 'apt-get purge' "$LOG")" "0"
chk "and it says why"            "$(echo "$out" | grep -c 'redis-server left in place')" "1"

echo "== status answers in key=value =="
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/rspamd/local.d" bash "$SCANNER" status 2>&1); rc=$?
chk "status exits 0"             "$rc" "0"
for key in package_rspamd package_redis_server service_rspamd managed_configs retired_configs milter_wired signing_wired signing_domains milter; do
    chk "status reports $key"    "$(echo "$out" | grep -c "^$key=")" "1"
done

echo "== the relay writes the same five files =="
# The relay's own write loop, run against a scratch dir: from the line that
# sources the shared file to the end of its loop.
loop=$(awk '/source "\$\{SCRIPT_DIR\}\/rspamd_stateless.sh"/{f=1} f{print} f && /^done$/{exit}' "$RELAY")
chk "relay sources the shared file" "$([ -n "$loop" ] && echo yes || echo no)" "yes"
chk "relay writes no rspamd heredoc of its own" "$(grep -c "<<'RSPAMD" "$RELAY")" "0"
chk "relay names rspamd as its only milter" "$(grep -c '^postconf_set "smtpd_milters" "inet:localhost:11332"$' "$RELAY")" "1"
chk "relay hands rspamd nothing it sends" "$(grep -c '^postconf_set "non_smtpd_milters" ""$' "$RELAY")" "1"
(
    SCRIPT_DIR="$T/prov"
    mark_changed() { :; }
    write_if_changed() { local d="$1"; d="$T/relay.d/$(basename "$d")"; cat > "$d"; return 0; }
    eval "$loop"
    for f in "${RSPAMD_STATELESS_OVERRIDE_FILES[@]}"; do rspamd_stateless_render "$f" > "$T/relay.d/$f"; done
)
for f in milter_headers.conf actions.conf classifier-bayes.conf rbl.conf worker-proxy.inc; do
    strip() { grep -v '^[[:space:]]*#' "$1" | sed '/^[[:space:]]*$/d'; }
    chk "relay $f matches the tenant's" \
        "$(diff <(strip "$T/local.d/$f") <(strip "$T/relay.d/$f") >/dev/null 2>&1 && echo same || echo differs)" "same"
done
# The settings every relay already runs, pinned. Relays are not updated when
# this file changes (only rebuilt), so a settings change here has to come with
# a RELAY_VERSION bump in provision_relay.sh — and a new golden value below.
declare -A GOLDEN=(
    [milter_headers.conf]=b16e4b401d5a2e0bb5114aa5f74cb53b128bd86ec402ce74b8ff77edc9e5b0f1
    [actions.conf]=b7ea57bf5f74e6154dd35834746c05fc960132256244ebf7014365e92171d855
    [classifier-bayes.conf]=22106cf3a87d2eb7e81afd2b73827c1e18c287b1eb1926bdcae554fa0c80641d
    [rbl.conf]=3eb3f2c71552d6c6e4a0844d27c261c524764443172dd04ddef6ff3fc6568f78
    [worker-proxy.inc]=704cc233c92b9cede47023cfe5beef91d3a31e084cd3038af3c8deb11e31734f
    [options.inc]=626dd018bd6a77d183065d2dd03f07b4d4925180040730fc5792716daf16414f
)
chk "the relay writes the override file where it replaces rspamd's list" "$(grep -c 'write_if_changed "/etc/rspamd/override.d/${f}" 644' "$RELAY")" "1"
chk "the relay restarts rspamd when its listening address changed" "$(grep -c '^    sync_service rspamd restart$' "$RELAY")" "1"
chk "the relay gets no signing configuration" "$([ -e "$T/relay.d/dkim_signing.conf" ] && echo yes || echo no)" "no"
for f in "${!GOLDEN[@]}"; do
    got=$(grep -v '^[[:space:]]*#' "$T/relay.d/$f" | sed '/^[[:space:]]*$/d' | sha256sum | cut -d' ' -f1)
    chk "$f settings are the ones relays run (a settings change needs a RELAY_VERSION bump)" "$got" "${GOLDEN[$f]}"
done
chk "the support bundle carries the shared file" \
    "$(grep -c "'public_html/plugins/mailbox/provisioning/rspamd_stateless.sh'" "$BUNDLE")" "1"

echo "== the X-Spam header contract matches the router =="
chk "router pins X-Spam"         "$(grep -c "SPAM_FLAG_HEADER   = 'X-Spam'" "$ROUTER")" "1"
chk "router pins X-Spam-Status"  "$(grep -c "SPAM_STATUS_HEADER = 'X-Spam-Status'" "$ROUTER")" "1"
chk "config stamps the flag"     "$(grep -c '"spam-header"' "$T/local.d/milter_headers.conf")" "1"
chk "config stamps the status"   "$(grep -c '"x-spam-status"' "$T/local.d/milter_headers.conf")" "1"

echo "== rejection stays off (the reviewable-verdict model) =="
chk "reject disabled"            "$(grep -c '^reject = null;' "$T/local.d/actions.conf")" "1"
chk "greylist disabled"          "$(grep -c '^greylist = null;' "$T/local.d/actions.conf")" "1"
chk "add_header is the action"   "$(grep -c '^add_header = 6;' "$T/local.d/actions.conf")" "1"

echo "== remove unwires Postfix before purging =="
chk "remove strips the milter"   "$(grep -c 'rspamd milter removed' "$SCANNER")" "1"
chk "remove still purges redis"  "$(grep -c 'apt-get purge -y rspamd redis-server' "$SCANNER")" "1"
unwire_line=$(grep -n 'rspamd milter removed' "$SCANNER" | head -1 | cut -d: -f1)
purge_line=$(grep -n 'apt-get purge -y rspamd' "$SCANNER" | head -1 | cut -d: -f1)
chk "unwire precedes purge"      "$([ "$unwire_line" -lt "$purge_line" ] && echo yes || echo no)" "yes"

echo "== install_email.sh ships the scanner with the mail stack =="
chk "calls the provisioner"      "$(grep -c 'SPAM_SCANNER_SCRIPT}" install' "$INSTALLER")" "1"
chk "no policy gate"             "$(grep -c 'scanner-expected' "$INSTALLER")" "0"
chk "installs no rspamd itself"  "$(grep -c 'apt-get install -y "${CS_MISSING' "$INSTALLER")" "0"
chk "a missing provisioner stops the install" "$(grep -c 'refusing to leave Postfix without it' "$INSTALLER")" "1"
chk "scanner sets the list whole" "$(grep -c 'MILTER_ENTRY="inet:localhost:11332"' "$SCANNER")" "1"

echo "== opendkim and opendmarc are gone, in the right order =="
for f in "$INSTALLER" "$RELAY"; do
    name=$(basename "$f")
    chk "$name wires no opendkim or opendmarc milter" "$(grep -v '^[[:space:]]*#' "$f" | grep -c -E '8891|8893')" "0"
    chk "$name installs neither"  "$(grep -E '^(CS_)?PACKAGES=' "$f" | grep -c -E 'opendkim|opendmarc')" "0"
    chk "$name configures neither" "$(grep -v '^[[:space:]]*#' "$f" | grep -c -E 'opendkim|opendmarc')" "0"
    chk "$name removes them from a box that has them" "$(grep -c '^[[:space:]]*mail_checkers_retire_old$' "$f")" "1"
done
wire_line=$(grep -n 'SPAM_SCANNER_SCRIPT}" install' "$INSTALLER" | head -1 | cut -d: -f1)
retire_line=$(grep -n '^[[:space:]]*mail_checkers_retire_old$' "$INSTALLER" | head -1 | cut -d: -f1)
chk "a site box removes them after rspamd is the only milter" "$([ "$wire_line" -lt "$retire_line" ] && echo yes || echo no)" "yes"
wire_line=$(grep -n '^postconf_set "smtpd_milters"' "$RELAY" | head -1 | cut -d: -f1)
reload_line=$(grep -n 'systemctl reload postfix' "$RELAY" | head -1 | cut -d: -f1)
retire_line=$(grep -n '^mail_checkers_retire_old$' "$RELAY" | head -1 | cut -d: -f1)
chk "a relay tells Postfix, then removes them" "$([ "$wire_line" -lt "$reload_line" ] && [ "$reload_line" -lt "$retire_line" ] && echo yes || echo no)" "yes"
# The retire step itself: the two units stopped and purged by name, and the old
# key directory deleted only when every key has an identical copy.
retire=$(sed -n '/^mail_checkers_retire_old() {$/,/^}$/p' "$SHARED")
chk "retire stops both units"    "$(echo "$retire" | grep -c 'systemctl disable --now "${svc}"')" "1"
chk "retire purges by name, no autoremove" "$(echo "$retire" | grep -c 'autoremove -y')" "0"
chk "retire does nothing while rspamd is not answering" "$(echo "$retire" | grep -c 'if ! rspamd_milter_answers; then')" "1"
chk "retire deletes old keys through the comparing step" "$(echo "$retire" | grep -c 'rspamd_dkim_drop_old_keys')" "1"
chk "a site box retires only once Postfix names rspamd alone" "$(grep -c 'if \[\[ "$(postconf -h smtpd_milters 2>/dev/null || true)" == "inet:localhost:11332" \]\]; then' "$INSTALLER")" "1"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
