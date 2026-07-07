#!/bin/bash
# Android member gate runner — specs/implemented/android_native_member_screens.md.
#
# Drives the joinery-member-android Compose instrumented suites on the Android
# emulator (AVD `joinery_test`) on the Mac mini (ssh alias `macmini`) against
# dev.getjoinery.com, orchestrating the server-side state each leg needs. It
# mirrors tests/functional/ios/phase3_gate.sh leg for leg and REUSES its
# platform-neutral seed scripts unchanged (phase3_fixtures.php,
# phase3_conversation_fixtures.php).
#
# Legs:
#   1. Native member screens render with NO webview present (dashboard, orders,
#      subscriptions, events, conversations, security)
#   2. Deliberately-web surfaces load through the bridge from their native
#      entry points (change-tier from subscriptions, notifications from the
#      dashboard)
#   3. A build without the member module lands every flipped entry on its web
#      fallback (disable_member_module intent extra)
#   4. Conversation round-trip: seeded peer + 1:1 thread, native reply, the
#      reply row verified in msg_messages
#   5. Mailbox read + reply, reply arrival verified in
#      iem_inbound_email_messages; then the folder picker files its own seeded
#      thread into a fresh label, verified in ilm_inbound_label_members
#   6. Revocation (LAST): Sign Out All Devices on the native security screen
#      kills every session key and signs the app out
#      (app_bridge_key_check_seconds=0 so bridged web sessions die too)
#
# Requirements (dev box): ssh macmini, psql joinerytest, the fixture creds file
# ~/.joinery_app_test_creds, and the android/ source tree in this repo (synced
# to the mini build area ~/dev/joinery-android before building). Gradle 8.9 at
# ~/gradle-8.9 on the mini; Android env at ~/.android-env.
#
# NOTE: do not run the emulator while the mini's Ollama is serving a
# generation — they starve each other (16GB box).
#
# Version: 1.0.0

set -u
cd "$(dirname "$0")"
PUBLIC_HTML="$(cd ../../.. && pwd)"
CREDS_FILE="$HOME/.joinery_app_test_creds"
SETTING_CTL="php $PUBLIC_HTML/tests/functional/ios/setting_ctl.php"
PSQL="psql -U postgres -d joinerytest -tAc"
SENDER_LOCAL="phase3.sender"

# Mini-side coordinates.
MINI_BUILD="dev/joinery-android"
GRADLE="~/gradle-8.9/bin/gradle"
AVD="joinery_test"
APP_APK="$MINI_BUILD/joinery-member-android/build/outputs/apk/debug/joinery-member-android-debug.apk"
TEST_APK="$MINI_BUILD/joinery-member-android/build/outputs/apk/androidTest/debug/joinery-member-android-debug-androidTest.apk"
TEST_PKG="com.getjoinery.member.test"
RUNNER="androidx.test.runner.AndroidJUnitRunner"
BASE_URL="https://dev.getjoinery.com"

PASS_COUNT=0
FAIL_COUNT=0
FAILED_LEGS=""

if [ ! -f "$CREDS_FILE" ]; then
    echo "FATAL: $CREDS_FILE missing (fixture account credentials)"; exit 1
fi
TEST_EMAIL=$(grep '^email=' "$CREDS_FILE" | cut -d= -f2-)
TEST_PASSWORD=$(grep '^password=' "$CREDS_FILE" | cut -d= -f2-)
MAIL_DOMAIN="${TEST_EMAIL#*@}"
SENDER_EMAIL="$SENDER_LOCAL@$MAIL_DOMAIN"

# ---- helpers ---------------------------------------------------------------

# run_leg <label> <test-class#method> [extra "-e key value" pairs...]
# Runs one instrumented test on the emulator over ssh and checks for "OK (".
# The fixture password is NOT passed as an `-e` arg (it would land in argv on
# the dev box, the mini, and the device); it is streamed to a device file in
# push_device_creds and read by MemberGate on-device.
run_leg() {
    local label="$1"; shift
    local target="$1"; shift
    local extra_args="$*"
    echo ""
    echo "== $label =="
    local out
    # The am command is one escaped-double-quoted string so adb hands the
    # device shell a single command and the inner single quotes survive to be
    # parsed THERE — values with spaces (seeded display names, reply text)
    # otherwise shatter into stray am tokens on the device.
    out=$(ssh macmini "source ~/.android-env; adb shell \"am instrument -w \
        -e class $target \
        -e base_url '$BASE_URL' \
        -e email '$TEST_EMAIL' \
        -e client_version '9.9.9' \
        $extra_args \
        $TEST_PKG/$RUNNER\" 2>&1")
    echo "$out" | grep -E "OK \(|Failures|Error|Tests run" | tail -6
    if echo "$out" | grep -q "OK ("; then
        PASS_COUNT=$((PASS_COUNT+1)); echo "-- $label: PASS"
    else
        FAIL_COUNT=$((FAIL_COUNT+1)); FAILED_LEGS="$FAILED_LEGS $label;"; echo "-- $label: FAIL"
    fi
}

record_fail() { FAIL_COUNT=$((FAIL_COUNT+1)); FAILED_LEGS="$FAILED_LEGS $1;"; echo "-- $1: FAIL"; }
record_pass() { PASS_COUNT=$((PASS_COUNT+1)); echo "-- $1: PASS"; }

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

DEVICE_CREDS="/data/local/tmp/joinery_member_gate.creds"

# Stream the fixture creds to a device-only file over stdin — no secret ever
# appears in a command line (project secret-handling rule). Readable only on
# the throwaway emulator; deleted in cleanup.
push_device_creds() {
    ssh macmini "source ~/.android-env; adb shell 'cat > $DEVICE_CREDS && chmod 644 $DEVICE_CREDS'" < "$CREDS_FILE"
}

cleanup() {
    echo ""
    echo "== restore server state =="
    restore_smtp
    $SETTING_CTL set app_bridge_key_check_seconds "$BRIDGE_CHECK_BEFORE"
    ssh macmini "source ~/.android-env; adb shell rm -f $DEVICE_CREDS" 2>/dev/null
    echo "restored: smtp, app_bridge_key_check_seconds=$BRIDGE_CHECK_BEFORE, device creds removed"
}

BRIDGE_CHECK_BEFORE=$($SETTING_CTL get app_bridge_key_check_seconds)
[ -z "$BRIDGE_CHECK_BEFORE" ] && BRIDGE_CHECK_BEFORE=60
trap cleanup EXIT

# ---- fixtures --------------------------------------------------------------

echo "== fixtures (mailbox grant + sender alias + base label) =="
FIXTURES=$(php "$PUBLIC_HTML/tests/functional/ios/phase3_fixtures.php" ensure "$TEST_EMAIL" "$SENDER_LOCAL") || {
    echo "FATAL: fixtures failed: $FIXTURES"; exit 1; }
echo "$FIXTURES"

# ---- sync sources + build once + boot emulator + install --------------------

echo "== sync android/ to the mini build area =="
rsync -a --delete --exclude '.gradle' --exclude 'build' --exclude '.idea' \
    --exclude '*.iml' --exclude 'local.properties' --exclude '.kotlin' \
    "$PUBLIC_HTML/../android/" macmini:"$MINI_BUILD/" || exit 1

echo "== build app + androidTest APKs (mini) =="
ssh macmini "source ~/.android-env; cd $MINI_BUILD && $GRADLE \
    :joinery-member-android:assembleDebug \
    :joinery-member-android:assembleDebugAndroidTest --console=plain 2>&1 | tail -3" || exit 1

echo "== boot emulator + install =="
ssh macmini "source ~/.android-env; \
    (adb devices | grep -q emulator) || (nohup emulator -avd $AVD -no-window -no-audio -no-snapshot >/tmp/emulator.log 2>&1 &); \
    adb wait-for-device; \
    until [ \"\$(adb shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')\" = '1' ]; do sleep 3; done; \
    adb install -r -t $APP_APK >/dev/null && adb install -r -t $TEST_APK >/dev/null && echo 'installed'" || {
    echo "FATAL: emulator/install failed"; exit 1; }

echo "== push fixture creds to the device (stdin, never argv) =="
push_device_creds || { echo "FATAL: could not stage device creds"; exit 1; }

# ---- 1. native member screens render (no webview) ---------------------------

run_leg "Native member screens render (no webview)" \
    "com.getjoinery.member.MemberScreensTest#nativeMemberScreensRenderWithoutWebview"

run_leg "Security: Manage on the Website row exists and opens" \
    "com.getjoinery.member.MemberScreensTest#securityManageWebRowOpensWebPage"

# ---- 2. deliberately-web surfaces via native entry points -------------------

run_leg "Deliberately-web surfaces load through the bridge" \
    "com.getjoinery.member.MemberScreensTest#deliberatelyWebSurfacesLoadThroughBridge"

# ---- 3. module-less fallback -------------------------------------------------

run_leg "Build without the module falls back to web" \
    "com.getjoinery.member.MemberScreensTest#buildWithoutModuleFallsBackToWeb"

# Prove F8 the hard way: the whole MemberScreensTest class in ONE process — the
# fallback method must still pass even after earlier methods registered the
# screens (unregisterScreens makes the flag authoritative).
run_leg "Fallback holds in a shared instrumentation process (whole class)" \
    "com.getjoinery.member.MemberScreensTest"

# ---- 4. conversation round-trip ---------------------------------------------

echo ""
echo "== conversation fixtures (peer user + seeded thread) =="
CONV_FIXTURES=$(php "$PUBLIC_HTML/tests/functional/ios/phase3_conversation_fixtures.php" ensure "$TEST_EMAIL") || {
    echo "FATAL: conversation fixtures failed: $CONV_FIXTURES"; exit 1; }
echo "$CONV_FIXTURES"
CONV_ID=$(echo "$CONV_FIXTURES" | sed -n 's/.*conversation=\([0-9]*\).*/\1/p')
CONV_OTHER_NAME=$(echo "$CONV_FIXTURES" | sed -n 's/.*other_name=//p')
CONV_REPLY="AndroidConvReply-$(date +%s)"

run_leg "Conversation: native reply in seeded thread" \
    "com.getjoinery.member.ConversationGateTest#sendReplyInSeededThread" \
    "-e conversation_other_name '$CONV_OTHER_NAME' -e conversation_reply_text '$CONV_REPLY'"

run_leg "Conversation: swipe-delete requires confirmation" \
    "com.getjoinery.member.ConversationGateTest#swipeDeleteRequiresConfirmation"

CONV_ROW=$($PSQL "SELECT msg_message_id FROM msg_messages
                  WHERE msg_cnv_conversation_id = ${CONV_ID:-0}
                    AND msg_body = '$CONV_REPLY' LIMIT 1")
if [ -n "$CONV_ROW" ]; then
    record_pass "Conversation reply round-trip (server-side, msg id $CONV_ROW)"
else
    record_fail "conversation-reply-roundtrip (no msg_messages row for reply text)"
fi

# ---- 5. mailbox read + reply + folder picker --------------------------------

STAMP=$(date +%s)
MAIL_SUBJECT="Android Gate Mail $STAMP"
MAIL_BODY="AndroidGateBody-$STAMP"
MAIL_REPLY="AndroidGateReply-$STAMP"
# The picker leg gets its own message: the read+reply leg's reply retitles that
# thread's list row ("Re: …"), so an exact subject lookup needs a thread the
# reply never touched.
PICKER_SUBJECT="Android Picker Mail $STAMP"

flip_smtp_local
echo ""
echo "== seed mailbox messages (read+reply and picker) =="
python3 - "$SENDER_EMAIL" "$TEST_EMAIL" "$MAIL_SUBJECT" "$MAIL_BODY" "$PICKER_SUBJECT" <<'PYEOF'
import smtplib, sys
from email.message import EmailMessage
sender, to, subject, body, picker_subject = sys.argv[1:6]
s = smtplib.SMTP('localhost', 25, timeout=30)
for subj in (subject, picker_subject):
    msg = EmailMessage()
    msg['From'] = f'Phase3 Sender <{sender}>'
    msg['To'] = to
    msg['Subject'] = subj
    msg.set_content(body)
    s.send_message(msg)
s.quit()
PYEOF

SEEDED=""; PICKER_SEEDED=""
for i in $(seq 1 18); do
    SEEDED=$($PSQL "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
                    WHERE iem_recipient = '$TEST_EMAIL' AND iem_subject = '$MAIL_SUBJECT' LIMIT 1")
    PICKER_SEEDED=$($PSQL "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
                    WHERE iem_recipient = '$TEST_EMAIL' AND iem_subject = '$PICKER_SUBJECT' LIMIT 1")
    [ -n "$SEEDED" ] && [ -n "$PICKER_SEEDED" ] && break
    sleep 5
done
if [ -z "$SEEDED" ] || [ -z "$PICKER_SEEDED" ]; then
    record_fail "mailbox-seed (message never arrived)"
    restore_smtp
else
    echo "-- seeded messages stored (iem ids $SEEDED, $PICKER_SEEDED)"
    run_leg "Mailbox: read + reply in-app" \
        "com.getjoinery.member.MailGateTest#readAndReply" \
        "-e mail_subject '$MAIL_SUBJECT' -e mail_reply_text '$MAIL_REPLY'"

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
        record_pass "Mailbox reply delivery (server-side, iem id $REPLY_ROW)"
    else
        record_fail "mailbox-reply-delivery (reply never stored)"
    fi

    MAIL_FOLDER="AndroidFolder$STAMP"
    run_leg "Mailbox: folder picker files thread" \
        "com.getjoinery.member.MailGateTest#folderPickerFilesThread" \
        "-e mail_picker_subject '$PICKER_SUBJECT' -e mail_folder_name '$MAIL_FOLDER'"

    FOLDER_ROW=$($PSQL "SELECT ilm.ilm_inbound_label_member_id
                        FROM ilm_inbound_label_members ilm
                        JOIN ilb_inbound_email_labels ilb
                          ON ilb.ilb_inbound_email_label_id = ilm.ilm_ilb_inbound_email_label_id
                        WHERE ilb.ilb_name = '$MAIL_FOLDER'
                          AND ilb.ilb_delete_time IS NULL
                          AND ilm.ilm_iem_inbound_email_message_id = ${PICKER_SEEDED:-0}
                          AND ilm.ilm_present_local = true LIMIT 1")
    if [ -n "$FOLDER_ROW" ]; then
        record_pass "Folder membership round-trip (server-side, ilm id $FOLDER_ROW)"
    else
        record_fail "folder-membership-roundtrip (no membership row for $MAIL_FOLDER)"
    fi
fi

# ---- 6. revocation (LAST — revokes every session key for the fixture user) --

$SETTING_CTL set app_bridge_key_check_seconds 0
run_leg "Revocation: Sign Out All Devices signs the app out" \
    "com.getjoinery.member.RevocationGateTest#revokeAllSignsAppOut"
$SETTING_CTL set app_bridge_key_check_seconds "$BRIDGE_CHECK_BEFORE"

# ---- summary ----------------------------------------------------------------

echo ""
echo "===================================="
echo "Android member gate: $PASS_COUNT checks passed, $FAIL_COUNT failed"
[ -n "$FAILED_LEGS" ] && echo "Failed:$FAILED_LEGS"
echo "===================================="
[ $FAIL_COUNT -eq 0 ]
