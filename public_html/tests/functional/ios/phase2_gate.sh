#!/bin/bash
# @joinery-test
# name: ios_phase2_gate
# tier: live
# env: dev-only
# needs: [macmini]
# timeout: 900
#
# Phase 2 gate runner — specs/ios_app_platform.md § Phase 2.
#
# Drives the JoineryMember XCUITest suites in the iOS Simulator on the Mac
# mini (ssh alias `macmini`) against dev.getjoinery.com, orchestrating the
# server-side state each suite needs:
#   1. Auth: login/logout, invalid-credential error
#   2. Forms: account_edit renders + submits from the server definition
#   3. Server-driven change: probe field added server-side appears with NO
#      app rebuild, then is removed
#   4. Password reset: both steps native; the reset code is read from the
#      fixture inbox (iem_inbound_email_messages) between the two steps
#   5. Upgrade gate: api_min_client_versions raised -> login renders 426 screen
#   6. Rate limit (LAST - trips the 15-min failed-auth window for the mini's IP)
#
# Requirements (dev box): ssh macmini, psql joinerytest, the fixture creds
# file ~/.joinery_app_test_creds (created by the Phase 2 setup), and the iOS
# source tree in this repo at {repo root}/ios/ (synced to the mini build
# area ~/dev/joinery-ios before building).
#
# The email-domain MX check is disabled for the duration of the run:
# account/password saves re-validate the fixture address, and the run must
# not depend on public DNS.
#
# Version: 1.1.0

set -u
cd "$(dirname "$0")"
PUBLIC_HTML="$(cd ../../.. && pwd)"
CREDS_FILE="$HOME/.joinery_app_test_creds"
DEST='platform=iOS Simulator,name=iPhone 16'
CLIENT_APP='joinery-member-ios'
SETTING_CTL="php $PUBLIC_HTML/tests/functional/ios/setting_ctl.php"
PSQL="psql -U postgres -d joinerytest -tAc"

PASS_COUNT=0
FAIL_COUNT=0
FAILED_SUITES=""

if [ ! -f "$CREDS_FILE" ]; then
    echo "FATAL: $CREDS_FILE missing (fixture account credentials)"; exit 1
fi
TEST_EMAIL=$(grep '^email=' "$CREDS_FILE" | cut -d= -f2-)
TEST_PASSWORD=$(grep '^password=' "$CREDS_FILE" | cut -d= -f2-)

# ---- helpers ---------------------------------------------------------------

# run_suite <label> <-only-testing target> [extra TEST_RUNNER_ env assignments...]
run_suite() {
    local label="$1"; shift
    local only="$1"; shift
    local extra_env="$*"
    echo ""
    echo "== $label =="
    # TEST_RUNNER_-prefixed variables are forwarded (prefix stripped) into the
    # UI test runner's environment by xcodebuild.
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

cleanup() {
    echo ""
    echo "== restore server state =="
    $SETTING_CTL set email_validation_mx_check "$MX_BEFORE"
    $SETTING_CTL set api_min_client_versions "$MIN_VERSIONS_BEFORE"
    # If the run died inside the reset leg, put the outbound provider back.
    if [ -n "${SMTP_SAVED_SERVICE:-}" ]; then restore_smtp; fi
    remove_probe
    echo "restored: email_validation_mx_check=$MX_BEFORE, api_min_client_versions restored, probe removed"
}

LOGIC_FILE="$PUBLIC_HTML/logic/account_edit_logic.php"
add_probe() {
    if grep -q PHASE2_PROBE "$LOGIC_FILE"; then return 0; fi
    python3 - "$LOGIC_FILE" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "\t$formwriter->dropinput('usr_timezone', 'Your Time Zone', [\n\t\t'options' => Address::get_timezone_drop_array()\n\t]);\n"
probe = "\t// PHASE2_PROBE_START (temporary; removed by phase2_gate.sh)\n\t$formwriter->textinput('phase2_probe', 'Phase2 Probe');\n\t// PHASE2_PROBE_END\n"
if anchor not in src:
    sys.exit("probe anchor not found in account_edit_logic.php")
open(path, 'w').write(src.replace(anchor, anchor + probe, 1))
PYEOF
    php -l "$LOGIC_FILE" > /dev/null || { echo "FATAL: probe insertion broke syntax"; exit 1; }
}
remove_probe() {
    if grep -q PHASE2_PROBE "$LOGIC_FILE"; then
        sed -i '/PHASE2_PROBE_START/,/PHASE2_PROBE_END/d' "$LOGIC_FILE"
        php -l "$LOGIC_FILE" > /dev/null || echo "WARNING: check $LOGIC_FILE after probe removal"
    fi
}

# ---- server state ----------------------------------------------------------

MX_BEFORE=$($SETTING_CTL get email_validation_mx_check)
MIN_VERSIONS_BEFORE=$($SETTING_CTL get api_min_client_versions)
[ -z "$MIN_VERSIONS_BEFORE" ] && MIN_VERSIONS_BEFORE='{}'
trap cleanup EXIT
# A runner timeout sends SIGTERM (then SIGKILL after 5s). An untrapped SIGTERM
# kills bash WITHOUT running the EXIT trap, stranding flipped settings and the
# source-file probes on the live site. Trapping the signals to exit fires the
# EXIT trap, so cleanup runs exactly once on both normal and killed exits.
trap 'exit 143' TERM
trap 'exit 130' INT
$SETTING_CTL set email_validation_mx_check 0

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

# ---- 1. auth ----------------------------------------------------------------

run_suite "Auth (login/logout, invalid credentials)" "JoineryMemberUITests/AuthUITests"

# ---- 2. forms ----------------------------------------------------------------

run_suite "Account form render" "JoineryMemberUITests/AccountFormUITests/testAccountEditRendersFromServerDefinition"
run_suite "Account form submit" "JoineryMemberUITests/AccountFormUITests/testAccountEditSubmitRoundTrip"

# ---- 3. server-driven change, no rebuild -------------------------------------

add_probe
run_suite "Server-driven field change (no rebuild)" \
    "JoineryMemberUITests/AccountFormUITests/testServerDrivenFieldChangeAppearsWithoutRebuild" \
    "TEST_RUNNER_JOINERY_EXPECT_PROBE=1"
remove_probe

# ---- 4. password reset (two orchestrated invocations) ------------------------

# The default outbound path (Mailgun) loops off-box and back and can take
# 10+ erratic minutes to reach our own Postfix. For the reset leg only, send
# via localhost SMTP — Postfix delivers the locally-hosted domain in seconds.
SMTP_SAVED_SERVICE=$($SETTING_CTL get email_service)
SMTP_SAVED_HOST=$($SETTING_CTL get smtp_host)
SMTP_SAVED_PORT=$($SETTING_CTL get smtp_port)
SMTP_SAVED_AUTH=$($SETTING_CTL get smtp_auth)
restore_smtp() {
    $SETTING_CTL set email_service "$SMTP_SAVED_SERVICE"
    $SETTING_CTL set smtp_host "$SMTP_SAVED_HOST"
    $SETTING_CTL set smtp_port "$SMTP_SAVED_PORT"
    $SETTING_CTL set smtp_auth "$SMTP_SAVED_AUTH"
}
$SETTING_CTL set email_service smtp
$SETTING_CTL set smtp_host localhost
$SETTING_CTL set smtp_port 25
$SETTING_CTL set smtp_auth 0

RESET_START_UTC=$(date -u '+%Y-%m-%d %H:%M:%S')
run_suite "Password reset step 1 (request email)" "JoineryMemberUITests/PasswordResetUITests/testRequestResetEmail"

echo "-- waiting for the reset email in the fixture inbox..."
RESET_CODE=""
for i in $(seq 1 18); do
    BODY=$($PSQL "SELECT iem_body_plain || ' ' || COALESCE(iem_body_html,'') FROM iem_inbound_email_messages
                  WHERE iem_recipient = '$TEST_EMAIL' AND iem_received_time >= '$RESET_START_UTC'
                  ORDER BY iem_received_time DESC LIMIT 1")
    RESET_CODE=$(echo "$BODY" | grep -oE 'act_code=[A-Za-z0-9]+' | head -1 | cut -d= -f2)
    [ -n "$RESET_CODE" ] && break
    sleep 5
done
restore_smtp
if [ -z "$RESET_CODE" ]; then
    echo "-- Password reset: FAIL (no reset email with act_code arrived within 90s)"
    FAIL_COUNT=$((FAIL_COUNT+1)); FAILED_SUITES="$FAILED_SUITES reset-email;"
else
    echo "-- reset code received (length ${#RESET_CODE})"
    NEW_PASSWORD="App2_$(openssl rand -hex 9)"
    if run_suite "Password reset step 2 (native completion + re-login)" \
        "JoineryMemberUITests/PasswordResetUITests/testCompleteResetWithCode" \
        "TEST_RUNNER_JOINERY_RESET_CODE='$RESET_CODE' TEST_RUNNER_JOINERY_NEW_PASSWORD='$NEW_PASSWORD'"; then
        printf 'email=%s\npassword=%s\n' "$TEST_EMAIL" "$NEW_PASSWORD" > "$CREDS_FILE"
        chmod 600 "$CREDS_FILE"
        TEST_PASSWORD="$NEW_PASSWORD"
        echo "-- fixture credentials rotated"
    fi
fi

# ---- 5. upgrade gate ----------------------------------------------------------

$SETTING_CTL set_min_version "$CLIENT_APP" "99.0.0"
run_suite "Upgrade gate (426 at login)" "JoineryMemberUITests/UpgradeGateUITests"
$SETTING_CTL set api_min_client_versions "$MIN_VERSIONS_BEFORE"

# ---- 6. rate limit (LAST) -----------------------------------------------------

run_suite "Rate limit (failed-auth limiter)" "JoineryMemberUITests/RateLimitUITests"
echo "NOTE: the mini's IP is now inside the 15-minute failed-auth window; auth-dependent suites will fail until it expires."

# ---- summary -------------------------------------------------------------------

echo ""
echo "===================================="
echo "Phase 2 gate: $PASS_COUNT suites passed, $FAIL_COUNT failed"
[ -n "$FAILED_SUITES" ] && echo "Failed:$FAILED_SUITES"
echo "===================================="
[ $FAIL_COUNT -eq 0 ]
