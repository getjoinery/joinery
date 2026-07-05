<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

/**
 * Asynchronous-chat plumbing shared by the chat send/confirm endpoints and the
 * poll endpoint.
 *
 * A chat turn can run for minutes on a slow local model. Holding the browser
 * connection open that long trips the proxy's idle ceiling, so instead the
 * endpoints send their response, release the client, and finish the turn in the
 * SAME fpm process via fastcgi_finish_request(). The page then polls a row that
 * carries its own lifecycle status (running → complete | failed).
 *
 * This class owns the three pieces that aren't turn logic: detaching from the
 * client, deciding when a still-running row is too old to be real, and reaping
 * such a row so the page shows an error instead of spinning forever.
 */
class ChatAsync {

    /**
     * True when the turn can be run after the response is sent. Only php-fpm
     * exposes fastcgi_finish_request(); under any other SAPI (CLI, CGI) the
     * caller must run the turn synchronously before responding.
     */
    public static function canDetach(): bool {
        return function_exists('fastcgi_finish_request');
    }

    /**
     * Send the already-echoed response, release the browser, and keep running.
     * After this returns the connection is closed, so no web-request or proxy
     * timeout applies to the work that follows.
     */
    public static function detach(): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        // Release the PHP session lock before the long turn. fastcgi_finish_request
        // closes the connection but NOT the session, and PHP serializes requests
        // on the same session file — so without this every chat_poll for this
        // conversation would block on session_start() until the turn ends, and no
        // partial text would ever be observed. The turn only reads identity from
        // the session ($_SESSION stays readable in memory), so closing it is safe.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // The client is gone; don't let its disconnect abort the turn, and lift
        // PHP's own execution limit (on Linux fpm this counts CPU time, not the
        // model's I/O wait, but lifting it removes all doubt).
        ignore_user_abort(true);
        set_time_limit(0);
    }

    /** Flush the partial answer onto the row at most this often / this many new
     *  chars — bounds DB writes while keeping the poll feeling live. */
    const STREAM_FLUSH_SECONDS = 0.4;
    const STREAM_FLUSH_CHARS = 80;

    /**
     * A throttled text sink for streamed answer deltas. Accumulates fragments and
     * writes the growing answer onto $msg->aim_content so the poll endpoint can
     * return it; the row stays RUNNING and finalize later overwrites the content
     * with the resolved text. $seed pre-loads the buffer (e.g. a resume's lead
     * text) so partials read continuously. Returns a callable(string $delta).
     */
    public static function streamSink(AiConversationMessage $msg, string $seed = ''): callable {
        $buffer  = $seed;
        $pending = 0;
        $last    = microtime(true);
        $stamped_writing = false;
        return function (string $delta) use ($msg, &$buffer, &$pending, &$last, &$stamped_writing): void {
            $buffer  .= $delta;
            $pending += strlen($delta);
            $now = microtime(true);
            if ($pending >= self::STREAM_FLUSH_CHARS || ($now - $last) >= self::STREAM_FLUSH_SECONDS) {
                $msg->set('aim_content', $buffer);
                if (!$stamped_writing) {
                    // Answer text has started arriving — the stage flips to
                    // Writing on the same save, costing no extra write.
                    $msg->set('aim_activity', 'Writing…');
                    $stamped_writing = true;
                }
                $msg->save();
                $pending = 0;
                $last = $now;
            }
        };
    }

    /**
     * A stage-label sink for the running row: writes the label onto
     * aim_activity so the poll endpoints can show a live "what's happening"
     * line (specs/ai_chat_turn_activity.md). Stage transitions are a handful
     * of tiny writes per turn — no throttling needed. Skips the write when
     * the label hasn't changed (e.g. repeated identical stamps).
     */
    public static function activityStamper(AiConversationMessage $msg): callable {
        $current = null;
        return function (string $label) use ($msg, &$current): void {
            if ($label === $current) return;
            $current = $label;
            $msg->set('aim_activity', $label !== '' ? $label : null);
            $msg->save();
        };
    }

    /**
     * Worst-case wall-clock for a legitimate turn, in seconds. AgentLoop bounds
     * a turn by max_iterations and the token budget, NOT by elapsed time, so the
     * longest real turn is roughly (chat max_iterations × the per-call provider
     * HTTP timeout) plus bounded tool time. We derive it from the live settings
     * so the ceiling tracks configuration, and add a generous margin so the
     * sweep never reaps a turn that is still working.
     */
    public static function staleCeilingSeconds(): int {
        $settings = Globalvars::get_instance();
        $iterations = max(1, (int)$settings->get_setting('joinery_ai_chat_max_iterations'));
        // The local provider's per-call timeout is the larger of the two
        // (Anthropic's is 120s); use it as the per-iteration worst case.
        $per_call = (int)$settings->get_setting('joinery_ai_local_timeout_seconds') ?: 300;
        $worst_case = $iterations * $per_call;     // e.g. 8 × 300 = 2400s
        return $worst_case + 600;                  // + 10 min margin
    }

    /**
     * If $msg is still RUNNING but older than the stale ceiling, the process
     * that owned it almost certainly died (fpm restart, OOM). Mark it FAILED so
     * the poller surfaces an error rather than spinning forever. Returns true if
     * it reaped the row. Safe to call on any row — it no-ops unless the row is
     * a stale running one.
     */
    public static function sweepMessage(AiConversationMessage $msg): bool {
        if ($msg->get('aim_status') !== AiConversationMessage::STATUS_RUNNING) {
            return false;
        }
        $started = (string)$msg->get('aim_create_time');
        if ($started === '') return false;

        $cutoff = LibraryFunctions::time_shift(
            gmdate('Y-m-d H:i:s'), '-' . self::staleCeilingSeconds() . ' seconds', 'Y-m-d H:i:s'
        );
        if ($started > $cutoff) return false;   // still within its legitimate window

        $msg->set('aim_status', AiConversationMessage::STATUS_FAILED);
        $msg->set('aim_error', 'The turn did not finish (the worker process appears to have stopped).');
        $msg->set('aim_activity', null);
        $msg->save();
        return true;
    }

}
