#!/bin/bash
# @joinery-test
# name: ios_fortress_gate
# tier: live
# env: dev-only
# needs: [macmini]
# timeout: 1200
#
# End-to-end (Fortress) mail in the iOS app — specs/fortress_mobile_apps.md
# WP3, WP5, WP7, WP8. Drives FortressMailUITests in the Simulator on the Mac
# mini against dev.getjoinery.com:
#   1. walk_fixture.php makes a Fortress fixture with a known mail key (no
#      browser), one message, and one relay-sealed arrival (pending parse);
#   2. the suite signs in, sees the end-to-end banner (B1), shows an enroll
#      code, which this runner approves with `approve-device` exactly as the
#      link page would;
#   3. the phone opens the row, the body and the PDF, replies (source_open),
#      parses the relay-sealed arrival (WP7), finds a body word through its own
#      index (WP5), saves, reopens and sends a sealed draft (WP8), and locks;
#   4. rules on relay-sealed mail (R14): a star rule and a forward rule match
#      arrivals the phone parses; the row is starred and the forward attempted;
#   5. a Fortress forward carries its opened parts; a draft's saved attachment
#      is re-sent from its signed URL;
#   6. AI (R13): a stand-in OpenAI-compatible model on the mini judges the
#      mail from the phone; the verdicts land sealed with their log rows;
#   7. B6: the runner retires the mail key; the phone lets go of it, is
#      enrolled again, and reads mail sealed to the new key;
#   8. polling (R15): a new arrival notifies with the mailbox only;
#   9. this runner checks in psql what each leg left behind.
#
# Own build area and simulator on the mini (~/dev/fma-ios, "FMA iPhone"), so
# it never collides with the phase gates' ~/dev/joinery-ios.
#
# Version: 1.2

set -u
cd "$(dirname "$0")"
PUBLIC_HTML="$(cd ../../.. && pwd)"
REPO="$(cd "$PUBLIC_HTML/.." && pwd)"
PURPOSE="ios-fma"
FIXTURE="php $REPO/maintenance_scripts/dev_tools/walk_fixture.php"
KEYFILE="/tmp/joinery-app-fortress-$PURPOSE.json"
DEST='platform=iOS Simulator,name=FMA iPhone'
CODE_FILE="/Users/jeremytunnell/dev/fma-enroll-code.txt"
PSQL="psql -U postgres -d joinerytest -tAc"
STAMP=$(date +%s)
FAILS=0

fail() { echo "FAIL: $*"; FAILS=$((FAILS + 1)); }
pass() { echo "PASS: $*"; }

echo "== fixture =="
$FIXTURE create "$PURPOSE" --fortress --messages=0 || { echo "FATAL: fixture"; exit 1; }
WORD=$($FIXTURE deliver "$PURPOSE" 1 gate | sed -n 's/.*(\(.*\)).*/\1/p' | head -1)
RELAY_WORD=$($FIXTURE deliver-relay "$PURPOSE" 1 relay | sed -n 's/.*(\(.*\)).*/\1/p' | head -1)
# R14: a star rule and a forward rule, then arrivals that match them.
$FIXTURE add-rule "$PURPOSE" startag > /dev/null || { echo "FATAL: star rule"; exit 1; }
$FIXTURE add-rule "$PURPOSE" fwdtag --forward=walk-fwd@example.invalid > /dev/null || { echo "FATAL: forward rule"; exit 1; }
STAR_ID=$($FIXTURE deliver-relay "$PURPOSE" 1 startag | sed -n 's/^relay-sealed message \([0-9]*\).*/\1/p' | head -1)
FWD_ID=$($FIXTURE deliver-relay "$PURPOSE" 1 fwdtag | sed -n 's/^relay-sealed message \([0-9]*\).*/\1/p' | head -1)
[ -n "$STAR_ID" ] && [ -n "$FWD_ID" ] || { echo "FATAL: rule arrivals (star='$STAR_ID' fwd='$FWD_ID')"; exit 1; }
# R13: the model origin registered and the recipes made.
AI_PORT=18765
AI_BASE="http://127.0.0.1:$AI_PORT"
$FIXTURE ai-on "$PURPOSE" "$AI_BASE" > /dev/null || { echo "FATAL: ai-on"; exit 1; }
[ -n "$WORD" ] && [ -n "$RELAY_WORD" ] || { echo "FATAL: seeding (word='$WORD' relay='$RELAY_WORD')"; exit 1; }
EMAIL=$(php -r "echo json_decode(file_get_contents('$KEYFILE'), true)['email'];")
PASSWORD=$(php -r "echo json_decode(file_get_contents('$KEYFILE'), true)['password'];")
USER_ID=$(php -r "echo json_decode(file_get_contents('$KEYFILE'), true)['user_id'];")
ADDRESS="box@claude-walk-$PURPOSE.example"
ALIAS_ID=$($PSQL "SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases a
    JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
    WHERE d.ied_domain = 'claude-walk-$PURPOSE.example' AND a.iea_alias = 'box' AND a.iea_delete_time IS NULL LIMIT 1")
REPLY_TEXT="fma reply $STAMP"
DRAFT_SUBJECT="fma draft $STAMP"
ATTACH_NAME="fma-attach-$STAMP.txt"
CONTROL_FILE="/Users/jeremytunnell/dev/fma-control.txt"
echo "word=$WORD relay=$RELAY_WORD alias=$ALIAS_ID"

echo "== vectors current =="
bash "$REPO/ios/joinery-kit/Tests/sync_vectors.sh" --check || fail "shared vectors drifted"

echo "== build-for-testing (mini) =="
rsync -a --delete --exclude .build --exclude .swiftpm --exclude xcuserdata --exclude DerivedData \
    --exclude '*.xcodeproj' "$REPO/ios/" macmini:dev/fma-ios/ || exit 1
ssh macmini "rm -f '$CODE_FILE' '$CONTROL_FILE'; xcrun simctl boot 'FMA iPhone' 2>/dev/null; cd dev/fma-ios/joinery-member-ios && \
    ~/dev/.tools/xcodegen/xcodegen/bin/xcodegen generate > /dev/null && \
    xcodebuild build-for-testing -scheme JoineryMember -destination '$DEST' -derivedDataPath ~/dev/fma-dd-app 2>&1 | tail -1" || exit 1

echo "== stand-in model (mini) =="
ssh macmini "pkill -f fma_standin_model.py 2>/dev/null; cat > ~/dev/fma_standin_model.py" <<'PY'
# A scripted OpenAI-compatible model for the phone's AI leg: every answer is
# a verdict both device jobs accept (the device_ai_vectors reply shape).
import json, sys
from http.server import BaseHTTPRequestHandler, HTTPServer
VERDICT = {"summary": "Stand-in summary of this message.", "score": 1, "verdict": "safe", "red_flags": []}
class H(BaseHTTPRequestHandler):
    def _send(self, obj):
        b = json.dumps(obj).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
    def do_GET(self):
        self._send({"data": [{"id": "stand-in"}]})
    def do_POST(self):
        self.rfile.read(int(self.headers.get("Content-Length", 0)))
        with open("/tmp/fma_standin_calls.log", "a") as f: f.write(self.path + "\n")
        self._send({"model": "stand-in", "choices": [{"message": {"content": json.dumps(VERDICT)}, "finish_reason": "stop"}]})
    def log_message(self, *a): pass
HTTPServer(("127.0.0.1", int(sys.argv[1])), H).serve_forever()
PY
ssh macmini "rm -f /tmp/fma_standin_calls.log; nohup python3 ~/dev/fma_standin_model.py $AI_PORT > /dev/null 2>&1 &" && sleep 1

echo "== suite =="
ssh macmini "cd dev/fma-ios/joinery-member-ios && \
    TEST_RUNNER_JOINERY_TEST_EMAIL='$EMAIL' TEST_RUNNER_JOINERY_TEST_PASSWORD='$PASSWORD' \
    TEST_RUNNER_JOINERY_FMA_WORD='$WORD' TEST_RUNNER_JOINERY_FMA_RELAY_WORD='$RELAY_WORD' \
    TEST_RUNNER_JOINERY_FMA_CODE_FILE='$CODE_FILE' TEST_RUNNER_JOINERY_FMA_REPLY_TEXT='$REPLY_TEXT' \
    TEST_RUNNER_JOINERY_FMA_DRAFT_SUBJECT='$DRAFT_SUBJECT' TEST_RUNNER_JOINERY_FMA_ADDRESS='$ADDRESS' \
    TEST_RUNNER_JOINERY_FMA_CONTROL_FILE='$CONTROL_FILE' TEST_RUNNER_JOINERY_FMA_ATTACH_NAME='$ATTACH_NAME' \
    TEST_RUNNER_JOINERY_FMA_AI_BASE='$AI_BASE/v1' TEST_RUNNER_JOINERY_FMA_AFTER_WORD='afterretire' \
    xcodebuild test-without-building -scheme JoineryMember -destination '$DEST' -derivedDataPath ~/dev/fma-dd-app \
    -resultBundlePath ~/dev/fma-results/fortress-$STAMP.xcresult \
    -only-testing:JoineryMemberUITests/FortressMailUITests 2>&1" > /tmp/fma-ios-suite-$STAMP.log &
SUITE=$!

# Serve the suite while it runs: approve each enroll code it shows (the first
# enrollment and the one after the key is retired), and retire the key when
# it asks.
APPROVED=0
LAST_CODE=""
RETIRED=0
while kill -0 $SUITE 2>/dev/null; do
    CODE=$(ssh macmini "cat '$CODE_FILE' 2>/dev/null")
    if [ -n "$CODE" ] && [ "$CODE" != "$LAST_CODE" ]; then
        $FIXTURE approve-device "$PURPOSE" "$CODE" && APPROVED=$((APPROVED + 1))
        LAST_CODE="$CODE"
    fi
    if [ "$(ssh macmini "cat '$CONTROL_FILE' 2>/dev/null")" = "retire" ]; then
        $FIXTURE retire-key "$PURPOSE" > /dev/null && RETIRED=1
        $FIXTURE deliver "$PURPOSE" 1 afterretire > /dev/null
        ssh macmini "printf retired > '$CONTROL_FILE'"
    fi
    sleep 2
done
[ "$APPROVED" -ge 2 ] && pass "both enrollments approved from the dev box" || fail "approvals: $APPROVED (want the first and the re-enrollment)"
[ "$RETIRED" = 1 ] && pass "the mail key retired mid-suite" || fail "the key was never retired"
wait $SUITE
grep -E "Test Case|error:|Executed" /tmp/fma-ios-suite-$STAMP.log | tail -15
grep -q "Test Case .*FortressMailUITests.*passed" /tmp/fma-ios-suite-$STAMP.log \
    && pass "FortressMailUITests" || fail "FortressMailUITests (log /tmp/fma-ios-suite-$STAMP.log, xcresult ~/dev/fma-results/fortress-$STAMP.xcresult)"

echo "== polling leg (R15) =="
POLL_FILE="/Users/jeremytunnell/dev/fma-poll.txt"
ssh macmini "rm -f '$POLL_FILE'"
ssh macmini "cd dev/fma-ios/joinery-member-ios && \
    TEST_RUNNER_JOINERY_TEST_EMAIL='$EMAIL' TEST_RUNNER_JOINERY_TEST_PASSWORD='$PASSWORD' \
    TEST_RUNNER_JOINERY_FMA_POLL_FILE='$POLL_FILE' TEST_RUNNER_JOINERY_FMA_ADDRESS='$ADDRESS' \
    xcodebuild test-without-building -scheme JoineryMember -destination '$DEST' -derivedDataPath ~/dev/fma-dd-app \
    -resultBundlePath ~/dev/fma-results/poll-$STAMP.xcresult \
    -only-testing:JoineryMemberUITests/MailPollingUITests 2>&1" > /tmp/fma-ios-poll-$STAMP.log &
POLL=$!
for i in $(seq 1 90); do
    if [ "$(ssh macmini "cat '$POLL_FILE' 2>/dev/null")" = "ready" ]; then
        $FIXTURE deliver "$PURPOSE" 1 poll > /dev/null && ssh macmini "printf delivered > '$POLL_FILE'"
        break
    fi
    kill -0 $POLL 2>/dev/null || break
    sleep 2
done
wait $POLL
grep -E "Test Case|error:" /tmp/fma-ios-poll-$STAMP.log | tail -6
grep -q "Test Case .*MailPollingUITests.*passed" /tmp/fma-ios-poll-$STAMP.log \
    && pass "MailPollingUITests" || fail "MailPollingUITests (log /tmp/fma-ios-poll-$STAMP.log)"

echo "== server state =="
DEVICE=$($PSQL "SELECT COUNT(*) FROM sde_sync_devices WHERE sde_usr_user_id = $USER_ID AND sde_platform = 'ios'
    AND sde_vault_scopes LIKE '%mail%' AND sde_delete_time IS NULL")
[ "${DEVICE:-0}" -ge 1 ] && pass "an ios device row holds the mail scope" || fail "no ios device row with the mail scope"
REPLY=$($PSQL "SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID
    AND iem_direction = 'outbound' AND iem_create_time > (to_timestamp($STAMP) AT TIME ZONE 'UTC')")
[ "${REPLY:-0}" -ge 2 ] && pass "the reply and the draft were sent ($REPLY outbound rows)" || fail "outbound rows: ${REPLY:-0} (want the reply and the draft)"
RELAY=$($PSQL "SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID
    AND iem_relay_spool_id IS NOT NULL AND iem_pending_parse = false AND iem_subject LIKE 'v1.edge.%'
    AND iem_body_plain LIKE 'v1.edge.%' AND iem_search_text LIKE 'v1.edge.%'")
[ "${RELAY:-0}" -ge 1 ] && pass "the relay-sealed arrival was parsed on the phone, every field sealed" || fail "relay row not parsed by the phone"
DRAFTS=$($PSQL "SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID
    AND iem_direction = 'draft' AND iem_delete_time IS NULL")
[ "${DRAFTS:-0}" = 0 ] && pass "the sent draft is gone from Drafts" || fail "$DRAFTS draft rows remain"

STARRED=$($PSQL "SELECT iem_is_starred FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $STAR_ID AND iem_pending_parse = false")
[ "$STARRED" = "t" ] && pass "the star rule matched on the phone and the row is starred (R14)" || fail "star-rule row $STAR_ID: starred='$STARRED'"
FWD=$($PSQL "SELECT COUNT(*) FROM mst_mailbox_send_attempts WHERE mst_kind = 'forward' AND mst_source_iem_inbound_email_message_id = $FWD_ID")
[ "${FWD:-0}" -ge 1 ] && pass "the forward rule's forward was attempted from the phone's forward_raw" || fail "no forward attempt for row $FWD_ID"
PARTS=$($PSQL "SELECT COALESCE(MAX(n), 0) FROM (SELECT COUNT(a.*) AS n FROM iem_inbound_email_messages m
    JOIN ima_inbound_message_attachments a ON a.ima_iem_inbound_email_message_id = m.iem_inbound_email_message_id
    WHERE m.iem_iea_inbound_email_alias_id = $ALIAS_ID AND m.iem_direction = 'outbound'
    AND m.iem_create_time > (to_timestamp($STAMP) AT TIME ZONE 'UTC') GROUP BY m.iem_inbound_email_message_id) x")
[ "${PARTS:-0}" -ge 2 ] && pass "the Fortress forward's Sent row carries its parts ($PARTS)" || fail "forward Sent row parts: ${PARTS:-0}"
ONEPART=$($PSQL "SELECT COUNT(*) FROM (SELECT m.iem_inbound_email_message_id FROM iem_inbound_email_messages m
    JOIN ima_inbound_message_attachments a ON a.ima_iem_inbound_email_message_id = m.iem_inbound_email_message_id
    WHERE m.iem_iea_inbound_email_alias_id = $ALIAS_ID AND m.iem_direction = 'outbound'
    AND m.iem_create_time > (to_timestamp($STAMP) AT TIME ZONE 'UTC')
    GROUP BY m.iem_inbound_email_message_id HAVING COUNT(*) = 1) x")
[ "${ONEPART:-0}" -ge 1 ] && pass "the reopened draft sent its saved attachment" || fail "no one-part Sent row from the draft"
AI_SUM=$($PSQL "SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID
    AND iem_ai_summary LIKE 'v1.edge.%'")
AI_SCAN=$($PSQL "SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = $ALIAS_ID
    AND iem_ai_scan LIKE 'v1.edge.%'")
AI_LOG=$($PSQL "SELECT COUNT(*) FROM aip_recipe_item_log l JOIN rcp_recipes r ON r.rcp_recipe_id = l.aip_rcp_recipe_id
    WHERE r.rcp_owner_user_id = $USER_ID AND l.aip_status = 'done'")
[ "${AI_SUM:-0}" -ge 1 ] && [ "${AI_SCAN:-0}" -ge 1 ] && [ "${AI_LOG:-0}" -ge 2 ] \
    && pass "the phone's AI verdicts are sealed on the rows ($AI_SUM summaries, $AI_SCAN scans, $AI_LOG done)" \
    || fail "AI: summaries=${AI_SUM:-0} scans=${AI_SCAN:-0} done=${AI_LOG:-0}"
CALLS=$(ssh macmini "wc -l < /tmp/fma_standin_calls.log 2>/dev/null" | tr -d ' ')
[ "${CALLS:-0}" -ge 2 ] && pass "the stand-in model answered $CALLS calls" || fail "stand-in model calls: ${CALLS:-0}"
ssh macmini "pkill -f fma_standin_model.py" 2>/dev/null

echo ""
[ "$FAILS" = 0 ] && echo "ios_fortress_gate: PASS" || echo "ios_fortress_gate: FAIL ($FAILS)"
exit $FAILS
