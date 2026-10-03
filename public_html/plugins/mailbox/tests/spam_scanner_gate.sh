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
# script's own logic is what is tested (spam_learning_in_core.md):
#
#   - install leaves the five stateless files, deletes redis.conf and
#     worker-controller.inc, and purges redis-server;
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
#   - install_email.sh calls the provisioner unconditionally, and writes a
#     milter list that already ends with rspamd, so a re-run never drops it.

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
mkdir -p "$T/prov" "$T/bin" "$T/state" "$T/local.d" "$T/relay.d"
cp "$SHARED" "$T/prov/rspamd_stateless.sh"
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
for c in systemctl service postconf postfix pgrep; do
    printf '#!/bin/bash\necho "%s $*" >> "$STUB_LOG"\n[ "%s" = "postconf" ] && [ "$1" = "-h" ] && echo "inet:localhost:8891, inet:localhost:8893"\n' "$c" "$c" > "$T/bin/$c"
    # STUB_NOINIT=1: a container with no init — nothing can reload or start a service.
    if [ "$c" = "systemctl" ] || [ "$c" = "service" ] || [ "$c" = "pgrep" ]; then
        printf '[ -n "$STUB_NOINIT" ] && exit 1\n' >> "$T/bin/$c"
    fi
    printf 'exit 0\n' >> "$T/bin/$c"
done
chmod +x "$T/bin/"*
export STUB_LOG="$LOG" STUB_STATE="$T/state"

# An upgrading box: rspamd and redis installed, the learning-era files present.
touch "$T/state/rspamd" "$T/state/redis-server"
echo 'servers = "127.0.0.1:6379";' > "$T/local.d/redis.conf"
echo 'bind_socket = "127.0.0.1:11334";' > "$T/local.d/worker-controller.inc"
echo 'backend = "redis";' > "$T/local.d/classifier-bayes.conf"
echo '# an operator override' > "$T/local.d/custom.conf"

echo "== install on an upgrading box =="
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "install exits 0"            "$rc" "0"
for f in milter_headers.conf actions.conf classifier-bayes.conf rbl.conf worker-proxy.inc; do
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

echo "== a second install succeeds and changes nothing =="
: > "$LOG"
before=$(cat "$T/local.d"/* | sha256sum)
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "second install exits 0"     "$rc" "0"
chk "files unchanged"            "$(cat "$T/local.d"/* | sha256sum)" "$before"
chk "nothing purged"             "$(grep -c 'apt-get purge' "$LOG")" "0"
chk "says redis is gone"         "$(echo "$out" | grep -c 'redis-server: not installed')" "1"

echo "== a fresh box never gets redis =="
rm -rf "${T:?}/state"/* "${T:?}/local.d"/*; : > "$LOG"
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "fresh install exits 0"      "$rc" "0"
chk "installs rspamd without recommends" "$(grep -c '^apt-get install -y --no-install-recommends rspamd$' "$LOG")" "1"
chk "redis never appears"        "$([ -f "$T/state/redis-server" ] && echo present || echo absent)" "absent"

echo "== with no init, redis stays until rspamd can take the new configuration =="
rm -rf "${T:?}/state"/* "${T:?}/local.d"/*; : > "$LOG"
touch "$T/state/rspamd" "$T/state/redis-server"
echo 'servers = "127.0.0.1:6379";' > "$T/local.d/redis.conf"
out=$(STUB_NOINIT=1 PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/local.d" bash "$T/prov/provision_spam_scanner.sh" install 2>&1); rc=$?
chk "install still exits 0"      "$rc" "0"
chk "redis is not purged"        "$(grep -c 'apt-get purge' "$LOG")" "0"
chk "and it says why"            "$(echo "$out" | grep -c 'redis-server left in place')" "1"

echo "== status answers in key=value =="
out=$(PATH="$T/bin:$PATH" RSPAMD_LOCAL_D="$T/local.d" bash "$SCANNER" status 2>&1); rc=$?
chk "status exits 0"             "$rc" "0"
for key in package_rspamd package_redis_server service_rspamd managed_configs retired_configs milter_wired milter; do
    chk "status reports $key"    "$(echo "$out" | grep -c "^$key=")" "1"
done

echo "== the relay writes the same five files =="
# The relay's own write loop, run against a scratch dir: from the line that
# sources the shared file to the end of its loop.
loop=$(awk '/source "\$\{SCRIPT_DIR\}\/rspamd_stateless.sh"/{f=1} f{print} f && /^done$/{exit}' "$RELAY")
chk "relay sources the shared file" "$([ -n "$loop" ] && echo yes || echo no)" "yes"
chk "relay writes no rspamd heredoc of its own" "$(grep -c "<<'RSPAMD" "$RELAY")" "0"
(
    SCRIPT_DIR="$T/prov"
    mark_changed() { :; }
    write_if_changed() { local d="$1"; d="$T/relay.d/$(basename "$d")"; cat > "$d"; return 0; }
    eval "$loop"
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
    [milter_headers.conf]=9a5f92b0d8288a840a671f12b3ef32c37fffadb89ab0a4abb15d1c2931bd2514
    [actions.conf]=b7ea57bf5f74e6154dd35834746c05fc960132256244ebf7014365e92171d855
    [classifier-bayes.conf]=22106cf3a87d2eb7e81afd2b73827c1e18c287b1eb1926bdcae554fa0c80641d
    [rbl.conf]=3eb3f2c71552d6c6e4a0844d27c261c524764443172dd04ddef6ff3fc6568f78
    [worker-proxy.inc]=ed8cabdc339e26bd43ae314b414fbc8a9323d28e37fd4cf0b483512ef5786eb9
)
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
# The installer's own milter list, computed by its own lines: with the
# provisioner beside it the list must already carry the scanner, so the
# scanner finds it wired instead of re-appending after a gap.
milters=$(SCRIPT_DIR="$PROVISIONING" bash -c "$(sed -n '/^SMTPD_MILTERS=/,/^fi$/p' "$INSTALLER"); echo \"\$SMTPD_MILTERS\"")
chk "installer list ends with rspamd" "$milters" "inet:localhost:8891, inet:localhost:8893, inet:localhost:11332"
chk "installer writes that list"  "$(grep -c 'postconf -e "smtpd_milters = ${SMTPD_MILTERS}"' "$INSTALLER")" "1"
chk "scanner reads it as wired"   "$(grep -c 'MILTER_ENTRY="inet:localhost:11332"' "$SCANNER")" "1"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
