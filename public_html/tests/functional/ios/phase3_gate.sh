#!/bin/bash
# Phase 3 gate runner — specs/ios_app_platform.md § Phase 3.
#
# Drives the JoineryMember XCUITest suites in the iOS Simulator on the Mac
# mini (ssh alias `macmini`) against dev.getjoinery.com, orchestrating the
# server-side state each leg needs:
#   1. Regression: Phase 2 auth + account-form suites under the new shell
#   2. Navigation: tab bar + More list from /api/v1/app/navigation
#   3. Webviews: calendar usable, orders load, conversations via in-webview
#      navigation
#   4. Mailbox: grant + seeded message (local SMTP), read + reply in-app,
#      reply arrival verified in iem_inbound_email_messages
#   5. Menu probe: plugin profileMenu entry synced server-side appears with
#      NO app rebuild, then pruned
#   6. Link probe: staged off-site link on /profile opens Safari
#   7. Revocation (LAST): Revoke All on the App Sessions page signs out the
#      webview AND native layers (app_bridge_key_check_seconds=0 for the leg)
#
# Requirements (dev box): ssh macmini, psql joinerytest, the fixture creds
# file ~/.joinery_app_test_creds, and the iOS source tree in this repo at
# {repo root}/ios/ (synced to the mini build area ~/dev/joinery-ios before
# building).
#
# Version: 1.1.0

set -u
cd "$(dirname "$0")"
PUBLIC_HTML="$(cd ../../.. && pwd)"
CREDS_FILE="$HOME/.joinery_app_test_creds"
DEST='platform=iOS Simulator,name=iPhone 16'
SETTING_CTL="php $PUBLIC_HTML/tests/functional/ios/setting_ctl.php"
PSQL="psql -U postgres -d joinerytest -tAc"
SENDER_LOCAL="phase3.sender"

PASS_COUNT=0
FAIL_COUNT=0
FAILED_SUITES=""

if [ ! -f "$CREDS_FILE" ]; then
    echo "FATAL: $CREDS_FILE missing (fixture account credentials)"; exit 1
fi
TEST_EMAIL=$(grep '^email=' "$CREDS_FILE" | cut -d= -f2-)
TEST_PASSWORD=$(grep '^password=' "$CREDS_FILE" | cut -d= -f2-)
MAIL_DOMAIN="${TEST_EMAIL#*@}"
SENDER_EMAIL="$SENDER_LOCAL@$MAIL_DOMAIN"

# ---- helpers ---------------------------------------------------------------

# run_suite <label> <-only-testing target> [extra TEST_RUNNER_ env assignments...]
run_suite() {
    local label="$1"; shift
    local only="$1"; shift
    local extra_env="$*"
    echo ""
    echo "== $label =="
    ssh macmini "cd dev/joinery-ios/joinery-member-ios && \
        TEST_RUNNER_JOINERY_TEST_EMAIL='$TEST_EMAIL' \
        TEST_RUNNER_JOINERY_TEST_PASSWORD='$TEST_PASSWORD' \
        $extra_env \
        xcodebuild test-without-building -scheme JoineryMember \
        -destination '$DEST' -only-testing:'$only' 2>&1" \
        | grep -E "Test Case|Test Suite '.*' (passed|failed)|error:|TEST EXECUTE" \
        | tail -20
    local rc=${PIPESTATUS[0]}
    if [ $rc -eq 0 ]; then
        PASS_COUNT=$((PASS_COUNT+1))
        echo "-- $label: PASS"
    else
        FAIL_COUNT=$((FAIL_COUNT+1))
        FAILED_SUITES="$FAILED_SUITES $label;"
        echo "-- $label: FAIL (xcodebuild rc=$rc)"
    fi
    return $rc
}

record_fail() {
    FAIL_COUNT=$((FAIL_COUNT+1)); FAILED_SUITES="$FAILED_SUITES $1;"
    echo "-- $1: FAIL"
}

# ---- link probe (temporary off-site anchor on /profile) --------------------

PROFILE_VIEW="$PUBLIC_HTML/views/profile/profile.php"
add_link_probe() {
    if grep -q PHASE3_LINK_PROBE "$PROFILE_VIEW"; then return 0; fi
    python3 - "$PROFILE_VIEW" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = '        <div class="stats-grid jy-mb-5">\n'
probe = ('        <!-- PHASE3_LINK_PROBE_START (temporary; removed by phase3_gate.sh) -->\n'
         '        <a href="https://example.com/" class="jy-profile-actionlink">External Probe Link</a>\n'
         '        <!-- PHASE3_LINK_PROBE_END -->\n')
if anchor not in src:
    sys.exit("link probe anchor not found in profile.php")
open(path, 'w').write(src.replace(anchor, probe + anchor, 1))
PYEOF
    php -l "$PROFILE_VIEW" > /dev/null || { echo "FATAL: link probe broke syntax"; exit 1; }
}
remove_link_probe() {
    if grep -q PHASE3_LINK_PROBE "$PROFILE_VIEW"; then
        sed -i '/PHASE3_LINK_PROBE_START/,/PHASE3_LINK_PROBE_END/d' "$PROFILE_VIEW"
        php -l "$PROFILE_VIEW" > /dev/null || echo "WARNING: check $PROFILE_VIEW after probe removal"
    fi
}

# ---- outbound SMTP flip (reply delivery must be local + fast) ---------------

SMTP_SAVED_SERVICE=""
flip_smtp_local() {
    SMTP_SAVED_SERVICE=$($SETTING_CTL get email_service)
    SMTP_SAVED_HOST=$($SETTING_CTL get smtp_host)
    SMTP_SAVED_PORT=$($SETTING_CTL get smtp_port)
    SMTP_SAVED_AUTH=$($SETTING_CTL get smtp_auth)
    $SETTING_CTL set email_service smtp
    $SETTING_CTL set smtp_host localhost
    $SETTING_CTL set smtp_port 25
    $SETTING_CTL set smtp_auth 0
}
restore_smtp() {
    if [ -n "$SMTP_SAVED_SERVICE" ]; then
        $SETTING_CTL set email_service "$SMTP_SAVED_SERVICE"
        $SETTING_CTL set smtp_host "$SMTP_SAVED_HOST"
        $SETTING_CTL set smtp_port "$SMTP_SAVED_PORT"
        $SETTING_CTL set smtp_auth "$SMTP_SAVED_AUTH"
        SMTP_SAVED_SERVICE=""
    fi
}

cleanup() {
    echo ""
    echo "== restore server state =="
    restore_smtp
    remove_link_probe
    php "$PUBLIC_HTML/tests/functional/ios/menu_probe.php" remove
    $SETTING_CTL set app_bridge_key_check_seconds "$BRIDGE_CHECK_BEFORE"
    echo "restored: smtp, link probe removed, menu probe removed, app_bridge_key_check_seconds=$BRIDGE_CHECK_BEFORE"
}

# ---- server state ----------------------------------------------------------

BRIDGE_CHECK_BEFORE=$($SETTING_CTL get app_bridge_key_check_seconds)
[ -z "$BRIDGE_CHECK_BEFORE" ] && BRIDGE_CHECK_BEFORE=60
trap cleanup EXIT

echo "== fixtures (mailbox grant + sender alias) =="
FIXTURES=$(php "$PUBLIC_HTML/tests/functional/ios/phase3_fixtures.php" ensure "$TEST_EMAIL" "$SENDER_LOCAL") || {
    echo "FATAL: fixtures failed: $FIXTURES"; exit 1; }
echo "$FIXTURES"

# ---- sync sources + build once ----------------------------------------------

echo "== sync ios/ to the mini build area =="
rsync -a --delete --exclude '.build' --exclude '.swiftpm' --exclude 'xcuserdata' \
    --exclude 'DerivedData' --exclude 'JoineryMember.xcodeproj' \
    --exclude 'failure_shots' --exclude '.DS_Store' \
    "$PUBLIC_HTML/../ios/" macmini:dev/joinery-ios/ || exit 1

echo "== build-for-testing (mini) =="
ssh macmini "xcrun simctl boot 'iPhone 16' 2>/dev/null; cd dev/joinery-ios/joinery-member-ios && \
    ~/dev/.tools/xcodegen/xcodegen/bin/xcodegen generate > /dev/null && \
    xcodebuild build-for-testing -scheme JoineryMember -destination '$DEST' 2>&1 | tail -2" || exit 1

# ---- 1. phase 2 regression ---------------------------------------------------

run_suite "Regression: auth (login/logout, invalid credentials)" "JoineryMemberUITests/AuthUITests"
run_suite "Regression: account form render" "JoineryMemberUITests/AccountFormUITests/testAccountEditRendersFromServerDefinition"
run_suite "Regression: account form submit" "JoineryMemberUITests/AccountFormUITests/testAccountEditSubmitRoundTrip"

# ---- 2. navigation shell -------------------------------------------------------

run_suite "Navigation shell (tabs + More from server)" \
    "JoineryMemberUITests/NavigationShellUITests/testTabsAndMoreRenderFromServerNavigation"

# ---- 3. webviews ----------------------------------------------------------------

run_suite "Webview: calendar usable in-app" "JoineryMemberUITests/WebviewUITests/testCalendarIsUsableInApp"
run_suite "Webview: orders load" "JoineryMemberUITests/WebviewUITests/testOrdersLoads"
run_suite "Webview: conversations via in-webview navigation" \
    "JoineryMemberUITests/WebviewUITests/testConversationsLoadViaInWebviewNavigation"

# ---- 4. mailbox read + reply ------------------------------------------------------

STAMP=$(date +%s)
MAIL_SUBJECT="Phase3 Gate Mail $STAMP"
MAIL_BODY="Phase3GateBody-$STAMP"
MAIL_REPLY="Phase3GateReply-$STAMP"

flip_smtp_local
echo ""
echo "== seed mailbox message =="
python3 - "$SENDER_EMAIL" "$TEST_EMAIL" "$MAIL_SUBJECT" "$MAIL_BODY" <<'PYEOF'
import smtplib, sys
from email.message import EmailMessage
sender, to, subject, body = sys.argv[1:5]
msg = EmailMessage()
msg['From'] = f'Phase3 Sender <{sender}>'
msg['To'] = to
msg['Subject'] = subject
msg.set_content(body)
s = smtplib.SMTP('localhost', 25, timeout=30)
s.send_message(msg)
s.quit()
PYEOF

SEEDED=""
for i in $(seq 1 18); do
    SEEDED=$($PSQL "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
                    WHERE iem_recipient = '$TEST_EMAIL' AND iem_subject = '$MAIL_SUBJECT' LIMIT 1")
    [ -n "$SEEDED" ] && break
    sleep 5
done
if [ -z "$SEEDED" ]; then
    record_fail "mailbox-seed (message never arrived)"
    restore_smtp
else
    echo "-- seeded message stored (iem id $SEEDED)"
    run_suite "Mailbox: read + reply in-app" "JoineryMemberUITests/MailboxUITests" \
        "TEST_RUNNER_JOINERY_MAIL_SUBJECT='$MAIL_SUBJECT' \
         TEST_RUNNER_JOINERY_MAIL_BODY_SNIPPET='$MAIL_BODY' \
         TEST_RUNNER_JOINERY_MAIL_REPLY_TEXT='$MAIL_REPLY'"

    echo "-- verifying the reply arrived at $SENDER_EMAIL..."
    REPLY_ROW=""
    for i in $(seq 1 18); do
        REPLY_ROW=$($PSQL "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
                           WHERE iem_recipient = '$SENDER_EMAIL' AND iem_direction = 'inbound'
                             AND (iem_body_plain LIKE '%$MAIL_REPLY%' OR iem_body_html LIKE '%$MAIL_REPLY%') LIMIT 1")
        [ -n "$REPLY_ROW" ] && break
        sleep 5
    done
    restore_smtp
    if [ -n "$REPLY_ROW" ]; then
        PASS_COUNT=$((PASS_COUNT+1))
        echo "-- Mailbox reply delivery (server-side): PASS (iem id $REPLY_ROW)"
    else
        record_fail "mailbox-reply-delivery (reply never stored)"
    fi
fi

# ---- 5. menu probe (no rebuild) ---------------------------------------------------

echo ""
echo "== stage menu probe =="
PROBE_SLUG=$(php "$PUBLIC_HTML/tests/functional/ios/menu_probe.php" add) || { echo "menu probe add failed"; exit 1; }
run_suite "Plugin menu entry appears (no rebuild)" \
    "JoineryMemberUITests/NavigationShellUITests/testPluginMenuEntryAppearsWithoutRebuild" \
    "TEST_RUNNER_JOINERY_EXPECT_MENU_PROBE=1 TEST_RUNNER_JOINERY_MENU_PROBE_SLUG='$PROBE_SLUG'"
php "$PUBLIC_HTML/tests/functional/ios/menu_probe.php" remove

# ---- 6. link probe (external → Safari) ---------------------------------------------

add_link_probe
run_suite "External link opens Safari" "JoineryMemberUITests/ExternalLinkUITests" \
    "TEST_RUNNER_JOINERY_EXPECT_LINK_PROBE=1"
remove_link_probe

# ---- 7. revocation (LAST — revokes every session key for the fixture user) ---------

$SETTING_CTL set app_bridge_key_check_seconds 0
run_suite "Revocation from web signs out both layers" "JoineryMemberUITests/RevocationUITests"
$SETTING_CTL set app_bridge_key_check_seconds "$BRIDGE_CHECK_BEFORE"

# ---- summary -------------------------------------------------------------------

echo ""
echo "===================================="
echo "Phase 3 gate: $PASS_COUNT checks passed, $FAIL_COUNT failed"
[ -n "$FAILED_SUITES" ] && echo "Failed:$FAILED_SUITES"
echo "===================================="
[ $FAIL_COUNT -eq 0 ]
