# Sourced by the phone gates (android/fortress_gate.sh, ios/fortress_gate.sh).
#
# The gates reply, forward and send drafts from the site under test. A
# workspace box's own mail server never delivers off the machine, so for the
# run the site's SMTP settings point at phone_smtp_sink.py on a local port,
# which accepts every message and drops it: nothing leaves the box, and a send
# completes as it would against a real server. mail_sink_off puts the saved
# settings back and stops the sink; call it from the gate's EXIT trap.
#
# Needs SETTING_CTL (tests/functional/ios/setting_ctl.php) set by the caller.

MAIL_SINK_PORT="${MAIL_SINK_PORT:-18425}"
MAIL_SINK_PID=""
MAIL_SAVED_SERVICE=""
MAIL_SINK_LOG="${TMPDIR:-/tmp}/phone_mail_sink.$$.log"

mail_sink_on() {
    python3 "$(dirname "${BASH_SOURCE[0]}")/phone_smtp_sink.py" "$MAIL_SINK_PORT" >"$MAIL_SINK_LOG" 2>&1 &
    MAIL_SINK_PID=$!
    for _ in 1 2 3 4 5 6 7 8 9 10; do grep -q listening "$MAIL_SINK_LOG" 2>/dev/null && break; sleep 0.3; done
    grep -q listening "$MAIL_SINK_LOG" || { echo "mail sink did not start: $(cat "$MAIL_SINK_LOG")"; return 1; }
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
    if [ -n "$MAIL_SAVED_SERVICE" ]; then
        $SETTING_CTL set email_service "$MAIL_SAVED_SERVICE"
        $SETTING_CTL set smtp_host "$MAIL_SAVED_HOST"
        $SETTING_CTL set smtp_port "$MAIL_SAVED_PORT"
        $SETTING_CTL set smtp_auth "$MAIL_SAVED_AUTH"
        MAIL_SAVED_SERVICE=""
    fi
    if [ -n "$MAIL_SINK_PID" ]; then
        kill "$MAIL_SINK_PID" 2>/dev/null
        echo "mail sink: $(grep -c '^sink: .* -> ' "$MAIL_SINK_LOG" 2>/dev/null || echo 0) message(s) accepted and dropped"
        rm -f "$MAIL_SINK_LOG"
        MAIL_SINK_PID=""
    fi
}
