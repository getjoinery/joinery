#!/bin/bash
# @joinery-test
# name: android_fortress_gate
# tier: live
# env: dev-only
# needs: [macmini]
# timeout: 3600
#
# Android end-to-end (Fortress) mail gate — specs/fortress_mobile_apps.md.
#
# Drives FortressGateTest leg by leg on the Android emulator (AVD
# `joinery_test`, which needs a screen-lock PIN and an enrolled fingerprint:
# the Keystore key the mail secret is wrapped under opens only to a strong
# biometric) against dev.getjoinery.com, with a walk fixture made for the
# purpose (walk_fixture.php create android-fma --fortress). One app sign-in
# runs through every leg: the key a phone holds is bound to the session key it
# enrolled with.
#
#   1. enroll_and_list       rows say "encrypted" without the key; Enroll
#                            (approve-device, as the link page would); the list
#                            opens; a relay-sealed arrival is parsed on the
#                            phone; phone-side search finds a body word
#   2. read_reply_forward    a message and its PDF open; the opened PDF is
#                            staged privately and swept once the viewer returns;
#                            forward (with its parts) and reply
#   3. draft_with_attachment a draft with a file picked in the system picker,
#                            saved sealed, reopened from Drafts and sent
#   4. rules_and_ai          a star rule and a forward rule run on relay-sealed
#                            mail the phone parses; AI on a stand-in model
#                            (ai_standin.py on the mini) writes a sealed summary
#   5. retired_key_reenroll  retire-key (B6): the phone wipes the key and offers
#                            Enroll; enrolled again, it reads new mail
#   6. polling_worker        the new-mail check is scheduled with JobScheduler;
#                            its worker, run now (WorkManager skips a forced run
#                            of periodic work that is not due), posts a
#                            notification naming the mailbox
#
# Throughout, this script touches the emulator's finger every two seconds and
# approves each new enrollment code the test logs (tag FmaGate). After each
# leg it checks the server's side in psql (reads only).
#
# Version: 2.0.0

set -u
cd "$(dirname "$0")"
PUBLIC_HTML="$(cd ../../.. && pwd)"
REPO="$(cd "$PUBLIC_HTML/.." && pwd)"
WALK="php $REPO/maintenance_scripts/dev_tools/walk_fixture.php"
SETTING_CTL="php $PUBLIC_HTML/tests/functional/ios/setting_ctl.php"
PSQL="psql -U postgres -d joinerytest -tAc"
PURPOSE="android-fma"
KEYFILE="/tmp/joinery-app-fortress-$PURPOSE.json"
MAILBOX="box@claude-walk-$PURPOSE.example"

MINI_BUILD="dev/fma"
GRADLE="~/gradle-8.9/bin/gradle"
AVD="joinery_test"
APP_PKG="com.getjoinery.member"
APP_APK="$MINI_BUILD/android/joinery-member-android/build/outputs/apk/debug/joinery-member-android-debug.apk"
TEST_APK="$MINI_BUILD/android/joinery-member-android/build/outputs/apk/androidTest/debug/joinery-member-android-debug-androidTest.apk"
TEST_PKG="com.getjoinery.member.test"
RUNNER="androidx.test.runner.AndroidJUnitRunner"
BASE_URL="https://dev.getjoinery.com"
DEVICE_CREDS="/data/local/tmp/joinery_member_gate.creds"
STANDIN_PORT=18431
AI_SUMMARY="Walk stand-in summary"
ATTACH_NAME="fma-draft-attach.txt"
ATTACH_SIZE=1234

PASS_COUNT=0
FAIL_COUNT=0
FAILED_LEGS=""
record_fail() { FAIL_COUNT=$((FAIL_COUNT+1)); FAILED_LEGS="$FAILED_LEGS $1;"; echo "-- $1: FAIL"; }
record_pass() { PASS_COUNT=$((PASS_COUNT+1)); echo "-- $1: PASS"; }
check() { if [ "$2" = "1" ]; then record_pass "$1"; else record_fail "$1"; fi; }
q() { $PSQL "$1" | tr -d '[:space:]'; }
mini() { ssh macmini "source ~/.android-env; $1"; }

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

HELPER_PID=""
cleanup() {
    [ -n "$HELPER_PID" ] && kill "$HELPER_PID" 2>/dev/null
    restore_smtp
    mini "adb shell rm -f $DEVICE_CREDS /sdcard/Download/$ATTACH_NAME; pkill -f 'ai_standin.py $STANDIN_PORT'" 2>/dev/null
    echo "restored: smtp; device creds, picker file and stand-in model removed"
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

# run_leg <label> <method> [extra -e pairs]
run_leg() {
    local label="$1" method="$2"; shift 2
    echo ""
    echo "== $label =="
    local out
    out=$(mini "adb shell \"am instrument -w \
        -e class com.getjoinery.member.FortressGateTest#$method \
        -e base_url '$BASE_URL' -e client_version '9.9.9' $* \
        $TEST_PKG/$RUNNER\" 2>&1")
    echo "$out" | grep -E "OK \(|Failures|Tests run|at com.getjoinery.member.Fortress|Error|Assertion|Exception" | head -10
    if echo "$out" | grep -q "OK ("; then
        record_pass "$label"
    else
        record_fail "$label"
        echo "-- on screen at the failure:"
        mini "adb logcat -d -s FmaGate:V" | grep -oE "Text = '\[[^]]*\]'|Tag = '[^']*'" | tail -50
    fi
}

echo "== fixture =="
$WALK create "$PURPOSE" --fortress --messages=2 || { echo "FATAL: fixture"; exit 1; }
RELAY_ID=$($WALK deliver-relay "$PURPOSE" 1 relaya | sed -n 's/^relay-sealed message \([0-9]*\).*/\1/p')
ALIAS_ID=$(q "SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases a
    JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
    WHERE d.ied_domain = 'claude-walk-$PURPOSE.example' AND a.iea_delete_time IS NULL LIMIT 1")
echo "mailbox $ALIAS_ID, relay row $RELAY_ID"

echo "== sync, build, boot, install =="
ssh macmini "mkdir -p $MINI_BUILD/public_html/tests/vault $MINI_BUILD/public_html/plugins/mailbox/tests $MINI_BUILD/public_html/assets/js"
rsync -a --delete --exclude '.gradle' --exclude 'build' --exclude '.idea' --exclude '*.iml' \
    --exclude 'local.properties' --exclude '.kotlin' "$REPO/android/" macmini:"$MINI_BUILD/android/" || exit 1
rsync -a --delete "$PUBLIC_HTML/tests/vault/fixtures/" macmini:"$MINI_BUILD/public_html/tests/vault/fixtures/"
rsync -a --delete "$PUBLIC_HTML/plugins/mailbox/tests/fixtures/" macmini:"$MINI_BUILD/public_html/plugins/mailbox/tests/fixtures/"
rsync -a "$PUBLIC_HTML/tests/functional/android/ai_standin.py" macmini:"$MINI_BUILD/"
mini "cd $MINI_BUILD/android && echo sdk.dir=\$ANDROID_HOME > local.properties && $GRADLE \
    :joinery-member-android:assembleDebug :joinery-member-android:assembleDebugAndroidTest --console=plain -q 2>&1 | grep -v '^w: ' | tail -5" || exit 1
mini "(adb devices | grep -q emulator) || (nohup ~/android-sdk/emulator/emulator @$AVD -no-window -no-audio -no-boot-anim -no-snapshot -gpu swiftshader_indirect >~/emulator.log 2>&1 &); \
    adb wait-for-device; \
    until [ \"\$(adb shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')\" = '1' ]; do sleep 3; done; \
    adb shell input keyevent KEYCODE_WAKEUP; \
    adb install -r -t $APP_APK >/dev/null && adb install -r -t $TEST_APK >/dev/null && echo installed" || { echo "FATAL: install"; exit 1; }
# A fresh phone: nothing held from an earlier run.
mini "adb shell pm clear $APP_PKG >/dev/null; adb logcat -c; adb shell pm grant $APP_PKG android.permission.POST_NOTIFICATIONS"

# The creds go to a device-only file over stdin, never argv.
python3 - "$KEYFILE" <<'PYEOF' | mini "adb shell 'cat > $DEVICE_CREDS && chmod 644 $DEVICE_CREDS'"
import json, sys
k = json.load(open(sys.argv[1]))
print("email=" + k["email"])
print("password=" + k["password"])
PYEOF

flip_smtp_local
STAMP=$(date +%s)

# The helper: the finger every two seconds, and each new enrollment code approved.
(
    done_codes=" "
    while true; do
        ssh macmini "source ~/.android-env; adb emu finger touch 1 >/dev/null 2>&1"
        for CODE in $(ssh macmini "source ~/.android-env; adb logcat -d -s FmaGate:I 2>/dev/null" | sed -n 's/.*enroll_code=\([A-Z0-9-]*\).*/\1/p'); do
            case "$done_codes" in *" $CODE "*) ;; *)
                $WALK approve-device "$PURPOSE" "$CODE" && done_codes="$done_codes$CODE " ;;
            esac
        done
        sleep 2
    done
) &
HELPER_PID=$!

# ---- 1 ------------------------------------------------------------------------
PENDING_BEFORE=$(q "SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID AND iem_pending_parse")
run_leg "1. Enroll, list, relay parse, search" enroll_and_list \
    "-e mail_subject 'Fortress message 1: zebracornfortress' -e relay_subject 'message 1: zebracornrelaya' \
     -e search_word quokkafigfortress -e search_subject 'Fortress message 2: quokkafigfortress'"
check "Relay-sealed arrival parsed and stored by the phone ($PENDING_BEFORE pending before)" \
    "$(q "SELECT CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID AND iem_pending_parse")"
check "Parsed relay row stored as ciphertext, with its parts" \
    "$(q "SELECT CASE WHEN m.iem_subject LIKE 'v1.edge.%' AND m.iem_body_plain LIKE 'v1.edge.%'
        AND (SELECT COUNT(*) FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = m.iem_inbound_email_message_id) >= 2
        THEN 1 ELSE 0 END FROM iem_inbound_email_messages m WHERE m.iem_inbound_email_message_id = ${RELAY_ID:-0}")"

# ---- 2 ------------------------------------------------------------------------
run_leg "2. Read, viewer sweep, forward with parts, reply" read_reply_forward \
    "-e mail_subject 'Fortress message 1: zebracornfortress' -e pdf_name zebracornfortress-report.pdf \
     -e forward_to fwd-target@example.test -e reply_text AndroidFortressReply-$STAMP"
check "Forward sent with the source's parts (a Sent row with 2+ parts)" \
    "$(q "SELECT CASE WHEN COUNT(*) >= 1 THEN 1 ELSE 0 END FROM iem_inbound_email_messages m WHERE m.iem_iea_inbound_email_alias_id = $ALIAS_ID
        AND m.iem_direction = 'outbound' AND (SELECT COUNT(*) FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = m.iem_inbound_email_message_id) >= 2")"
check "Reply and forward both stored as Sent rows" \
    "$(q "SELECT CASE WHEN COUNT(*) >= 2 THEN 1 ELSE 0 END FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID AND iem_direction = 'outbound'")"

# ---- 3 ------------------------------------------------------------------------
python3 -c "import sys; sys.stdout.write(('fma draft attachment ' * 200)[:$ATTACH_SIZE])" \
    | mini "adb shell 'cat > /sdcard/Download/$ATTACH_NAME'"
mini "adb shell content call --method scan_volume --uri content://media --arg external_primary >/dev/null 2>&1"
run_leg "3. Draft with a picked file: saved sealed, reopened, sent" draft_with_attachment \
    "-e draft_subject 'Fortress draft $STAMP' -e attach_name $ATTACH_NAME"
check "The draft's file went out with the send (a Sent part of $ATTACH_SIZE bytes)" \
    "$(q "SELECT CASE WHEN COUNT(*) >= 1 THEN 1 ELSE 0 END FROM ima_inbound_message_attachments ima JOIN iem_inbound_email_messages m
        ON m.iem_inbound_email_message_id = ima.ima_iem_inbound_email_message_id
        WHERE m.iem_iea_inbound_email_alias_id = $ALIAS_ID AND m.iem_direction = 'outbound' AND ima.ima_size_bytes = $ATTACH_SIZE")"
check "No draft left, and none was ever stored in the clear" \
    "$(q "SELECT CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID
        AND (iem_direction = 'draft' OR iem_subject = 'Fortress draft $STAMP')")"

# ---- 4 ------------------------------------------------------------------------
mini "pkill -f 'ai_standin.py $STANDIN_PORT'; nohup python3 $MINI_BUILD/ai_standin.py $STANDIN_PORT '$AI_SUMMARY' >/tmp/fma_standin.log 2>&1 &"
$WALK add-rule "$PURPOSE" starit
$WALK add-rule "$PURPOSE" fwdit --forward=walk-fwd@example.invalid
$WALK ai-on "$PURPOSE" "http://10.0.2.2:$STANDIN_PORT"
STAR_ID=$($WALK deliver-relay "$PURPOSE" 1 starit | sed -n 's/^relay-sealed message \([0-9]*\).*/\1/p')
FWD_ID=$($WALK deliver-relay "$PURPOSE" 1 fwdit | sed -n 's/^relay-sealed message \([0-9]*\).*/\1/p')
echo "star row $STAR_ID, forward row $FWD_ID"
run_leg "4. Rules on relay-sealed mail, AI on a stand-in model" rules_and_ai \
    "-e star_subject zebracornstarit -e forward_subject zebracornfwdit -e ai_model walk-standin -e ai_summary '$AI_SUMMARY'"
check "Star rule applied to the row the phone parsed" \
    "$(q "SELECT CASE WHEN iem_is_starred AND NOT iem_pending_parse THEN 1 ELSE 0 END FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ${STAR_ID:-0}")"
check "Forward rule: forward_raw posted and a forward attempt recorded" \
    "$(q "SELECT CASE WHEN COUNT(*) >= 1 THEN 1 ELSE 0 END FROM mst_mailbox_send_attempts WHERE mst_source_iem_inbound_email_message_id = ${FWD_ID:-0} AND mst_kind = 'forward'")"
check "AI summary sealed on a row (v1.edge.)" \
    "$(q "SELECT CASE WHEN COUNT(*) >= 1 THEN 1 ELSE 0 END FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID AND iem_ai_summary LIKE 'v1.edge.%'")"
check "The done log row exists for the judged item" \
    "$(q "SELECT CASE WHEN COUNT(*) >= 1 THEN 1 ELSE 0 END FROM aip_recipe_item_log l JOIN rcp_recipes r ON r.rcp_recipe_id = l.aip_rcp_recipe_id
        JOIN usr_users u ON u.usr_user_id = r.rcp_owner_user_id WHERE u.usr_email = 'claude-walk-$PURPOSE@example.com' AND l.aip_status = 'done'")"
check "The stand-in model was asked (requests reached it)" \
    "$(mini "grep -c 'POST /v1/chat/completions' /tmp/fma_standin.log" | awk '{print ($1 > 0) ? 1 : 0}')"

# ---- 5 ------------------------------------------------------------------------
$WALK retire-key "$PURPOSE"
$WALK deliver "$PURPOSE" 1 afterkey
run_leg "5. Retired key (B6): wiped, enrolled again, new mail read" retired_key_reenroll \
    "-e after_subject zebracornafterkey"

# ---- 6 ------------------------------------------------------------------------
echo ""
echo "== 6. Polling: the new-mail worker posts a notification =="
SCHEDULED=$(mini "adb shell dumpsys jobscheduler" | grep -cE "JOB #u0a[0-9]+/[0-9]+: .* $APP_PKG/androidx.work")
check "The new-mail check is scheduled with JobScheduler" "$([ "${SCHEDULED:-0}" -ge 1 ] && echo 1 || echo 0)"
mini "adb logcat -c"
NEW_ID=$($WALK deliver "$PURPOSE" 1 polltag | sed -n 's/^delivered message \([0-9]*\).*/\1/p')
run_leg "6. The worker runs" polling_worker
sleep 3
LOGGED=$(mini "adb logcat -d -s JoineryMailPoll:I" | grep -c "notified alias=$ALIAS_ID newest=$NEW_ID")
SHOWN=$(mini "adb shell dumpsys notification --noredact" | grep -c "New message in $MAILBOX")
check "Worker saw message $NEW_ID as new" "$([ "${LOGGED:-0}" -ge 1 ] && echo 1 || echo 0)"
check "The notification names the mailbox" "$([ "${SHOWN:-0}" -ge 1 ] && echo 1 || echo 0)"

echo ""
echo "== android_fortress_gate: $PASS_COUNT passed, $FAIL_COUNT failed =="
[ $FAIL_COUNT -eq 0 ] || { echo "failed:$FAILED_LEGS"; exit 1; }
exit 0
