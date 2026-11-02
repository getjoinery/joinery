# Sourced by the phone gates (android/*_gate.sh, ios/*_gate.sh).
#
# The gates reply, forward and send drafts from the site under test. A
# workspace box's own mail server never delivers off the machine, so for the
# run the site's SMTP settings point at phone_smtp_sink.py on a local port,
# which accepts every message and drops it: nothing leaves the box, and a send
# completes as it would against a real server. mail_sink_off puts the saved
# settings back and stops the sink; call it from the gate's EXIT trap.
#
# A gate whose mail must arrive (a seeded message, a reply, a reset email) sets
# MAIL_SINK_DELIVER to the site's own mail domains before mail_sink_on: mail to
# them is stored in the site's mailbox through its inbound handler, as the mail
# server's pipe would store it. Send seeded mail to 127.0.0.1:$MAIL_SINK_PORT.
#
# Needs SETTING_CTL (tests/functional/ios/setting_ctl.php) set by the caller.

MAIL_SINK_PORT="${MAIL_SINK_PORT:-18425}"
MAIL_SINK_PID=""
MAIL_SINK_FLIPPED=""
MAIL_SINK_DELIVER="${MAIL_SINK_DELIVER:-}"
MAIL_SINK_LOG="${TMPDIR:-/tmp}/phone_mail_sink.$$.log"

mail_sink_on() {
    local here deliver=()
    here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    if [ -n "$MAIL_SINK_DELIVER" ]; then
        deliver=(--deliver "$MAIL_SINK_DELIVER" --handler "$here/../../plugins/mailbox/utils/inbound_email_handler.php")
    fi
    python3 "$here/phone_smtp_sink.py" "$MAIL_SINK_PORT" "${deliver[@]}" >"$MAIL_SINK_LOG" 2>&1 &
    MAIL_SINK_PID=$!
    for _ in 1 2 3 4 5 6 7 8 9 10; do grep -q listening "$MAIL_SINK_LOG" 2>/dev/null && break; sleep 0.3; done
    grep -q listening "$MAIL_SINK_LOG" || { echo "mail sink did not start: $(cat "$MAIL_SINK_LOG")"; return 1; }
    MAIL_SINK_FLIPPED=1
    MAIL_SAVED_SERVICE=$($SETTING_CTL get email_service)
    MAIL_SAVED_HOST=$($SETTING_CTL get smtp_host)
    MAIL_SAVED_PORT=$($SETTING_CTL get smtp_port)
    MAIL_SAVED_AUTH=$($SETTING_CTL get smtp_auth)
    $SETTING_CTL set email_service smtp
    $SETTING_CTL set smtp_host 127.0.0.1
    $SETTING_CTL set smtp_port "$MAIL_SINK_PORT"
    $SETTING_CTL set smtp_auth 0
}

mail_sink_off() {
    if [ -n "$MAIL_SINK_FLIPPED" ]; then
        $SETTING_CTL set email_service "$MAIL_SAVED_SERVICE"
        $SETTING_CTL set smtp_host "$MAIL_SAVED_HOST"
        $SETTING_CTL set smtp_port "$MAIL_SAVED_PORT"
        $SETTING_CTL set smtp_auth "$MAIL_SAVED_AUTH"
        MAIL_SINK_FLIPPED=""
    fi
    if [ -n "$MAIL_SINK_PID" ]; then
        kill "$MAIL_SINK_PID" 2>/dev/null
        echo "mail sink: $(grep -c '^sink: .* -> ' "$MAIL_SINK_LOG" 2>/dev/null || echo 0) message(s) accepted, $(grep -c 'delivered to' "$MAIL_SINK_LOG" 2>/dev/null || echo 0) of them stored here"
        grep 'handler for' "$MAIL_SINK_LOG" 2>/dev/null
        rm -f "$MAIL_SINK_LOG"
        MAIL_SINK_PID=""
    fi
}
