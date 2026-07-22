#!/bin/bash
# @joinery-test
# name: spam_scanner_provisioner
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# The spam scanner provisioner is a root script that installs packages and
# rewrites /etc/rspamd — it cannot be executed in a test. What CAN be checked
# without root is everything that has silently broken before: that the script
# parses, that its verb dispatch rejects nonsense instead of guessing, and that
# the config it writes still matches the contracts other code depends on.
#
# The contracts under guard:
#   - The X-Spam header names rspamd is told to stamp are the ones
#     InboundEmailRouter parses. Drift here is invisible: mail flows, the header
#     arrives under a name nothing reads, and every message silently scores ham.
#   - Rejection stays disabled. The whole spam model is a reviewable verdict —
#     a scanner that rejects turns a false positive into a lost message.
#   - The controller stays on loopback with no password, because that is the
#     entire authorization story for the privileged learn command.
#   - remove() strips the milter from Postfix. Purging rspamd while Postfix
#     still points at 11332 would leave every inbound message waiting on a
#     milter that no longer exists.
#
# Also gates install_email.sh, which calls through to this script
# UNCONDITIONALLY — the scanner ships with the mail stack, so no setting, SQL
# read, or policy gate may stand between the mail installer and the scanner.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../provisioning" && pwd)"
SCANNER="$SCRIPT_DIR/provision_spam_scanner.sh"
INSTALLER="$SCRIPT_DIR/install_email.sh"
ROUTER="$(cd "$(dirname "${BASH_SOURCE[0]}")/../includes" && pwd)/InboundEmailRouter.php"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== the script exists and parses =="
chk "provisioner present"        "$([ -f "$SCANNER" ] && echo yes || echo no)" "yes"
bash -n "$SCANNER" 2>/dev/null
chk "provisioner parses"         "$?" "0"
bash -n "$INSTALLER" 2>/dev/null
chk "install_email.sh parses"    "$?" "0"

echo "== verbs are dispatched, never guessed =="
# No root needed: the verb check runs before the privilege check.
out=$(bash "$SCANNER" 2>&1); rc=$?
chk "no verb exits 2"            "$rc" "2"
chk "no verb prints usage"       "$(echo "$out" | grep -c 'Usage:')" "1"
out=$(bash "$SCANNER" frobnicate 2>&1); rc=$?
chk "unknown verb exits 2"       "$rc" "2"
for verb in install remove status; do
    chk "declares the $verb verb" \
        "$(grep -cE "^\s+$verb\)" "$SCANNER")" "1"
done

echo "== status runs unprivileged and answers in key=value =="
out=$(bash "$SCANNER" status 2>&1); rc=$?
chk "status exits 0"             "$rc" "0"
for key in package_rspamd service_rspamd managed_configs milter_wired controller; do
    chk "status reports $key"    "$(echo "$out" | grep -c "^$key=")" "1"
done

echo "== the X-Spam header contract matches the router =="
# The router pins the names as constants; the scanner config must produce them.
chk "router pins X-Spam"         "$(grep -c "SPAM_FLAG_HEADER   = 'X-Spam'" "$ROUTER")" "1"
chk "router pins X-Spam-Status"  "$(grep -c "SPAM_STATUS_HEADER = 'X-Spam-Status'" "$ROUTER")" "1"
chk "config stamps the flag"     "$(grep -q '"spam-header"' "$SCANNER" && echo yes || echo no)" "yes"
chk "config stamps the status"   "$(grep -q '"x-spam-status"' "$SCANNER" && echo yes || echo no)" "yes"

echo "== rejection stays off (the reviewable-verdict model) =="
chk "reject disabled"            "$(grep -c '^reject = null;' "$SCANNER")" "1"
chk "greylist disabled"          "$(grep -c '^greylist = null;' "$SCANNER")" "1"
chk "add_header is the action"   "$(grep -c '^add_header = 6;' "$SCANNER")" "1"

echo "== the controller is loopback-only and password-free =="
chk "binds loopback"             "$(grep -c 'bind_socket = "127.0.0.1:11334";' "$SCANNER")" "1"
chk "trusts loopback v4"         "$(grep -c 'secure_ip = "127.0.0.1";' "$SCANNER")" "1"
chk "trusts loopback v6"         "$(grep -c 'secure_ip = "::1";' "$SCANNER")" "1"
# The word appears in comments explaining why there is none; what must not exist
# is an actual password directive in the controller config.
chk "no password directive"      "$(grep -ciE '^[[:space:]]*(enable_)?password[[:space:]]*=' "$SCANNER")" "0"

echo "== Bayes persists, so the corpus is real =="
chk "bayes on redis"             "$(grep -c 'backend = "redis";' "$SCANNER")" "1"
chk "autolearn on"               "$(grep -c 'autolearn = true;' "$SCANNER")" "1"

echo "== remove unwires Postfix before purging =="
chk "remove strips the milter"   "$(grep -c 'rspamd milter removed' "$SCANNER")" "1"
chk "remove purges the packages" "$(grep -c 'apt-get purge -y rspamd redis-server' "$SCANNER")" "1"
# The unwire must come first in the file — purging a milter Postfix still points
# at would stall inbound mail for as long as the drift lasted.
unwire_line=$(grep -n 'rspamd milter removed' "$SCANNER" | head -1 | cut -d: -f1)
purge_line=$(grep -n 'apt-get purge -y rspamd' "$SCANNER" | head -1 | cut -d: -f1)
chk "unwire precedes purge"      "$([ "$unwire_line" -lt "$purge_line" ] && echo yes || echo no)" "yes"

echo "== install_email.sh ships the scanner with the mail stack =="
chk "calls the provisioner"      "$(grep -c 'SPAM_SCANNER_SCRIPT}" install' "$INSTALLER")" "1"
# Unconditional: no setting, SQL read, or policy gate in front of the call.
chk "no policy gate"             "$(grep -c 'scanner-expected' "$INSTALLER")" "0"
chk "no SQL for the old setting" "$(grep -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'mailbox_content_spam_filtering_enabled'" "$INSTALLER")" "0"
chk "installs no rspamd itself"  "$(grep -c 'apt-get install -y "${CS_MISSING' "$INSTALLER")" "0"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
