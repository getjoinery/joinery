<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));

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
     * A throttled text sink for streamed answer deltas. $seed pre-loads the buffer
     * (a resume's lead text) so partials read continuously. Returns a
     * callable(string $delta).
     *
     * Standard conversation: writes the growing answer onto $msg->aim_content so
     * the poll endpoint can return it; finalize later overwrites it. Protected
     * conversation ($sealed = true): the partial must NOT land in the DB column as
     * plaintext, so the buffer streams into a RAM/tmpfs scratch (see
     * writeScratch()) that chat_poll reads instead; aim_content stays empty until
     * finalize seals the complete buffer into it. Either way the stage flips to
     * "Writing…" once text starts arriving.
     *
     * $owner_id (the conversation owner) gates every sealed flush on the vault
     * window still being open: locking wipes the scratch (the bootstrap onWipe),
     * and a flush after that must not recreate it — the buffer keeps accumulating
     * in process RAM only, and finalize still seals the complete answer (sealing
     * needs only the public key).
     */
    public static function streamSink(AiConversationMessage $msg, string $seed = '',
            bool $sealed = false, int $owner_id = 0): callable {
        $buffer  = $seed;
        $pending = 0;
        $last    = microtime(true);
        $stamped_writing = false;
        return function (string $delta) use ($msg, &$buffer, &$pending, &$last, &$stamped_writing, $sealed, $owner_id): void {
            $buffer  .= $delta;
            $pending += strlen($delta);
            $now = microtime(true);
            if ($pending >= self::STREAM_FLUSH_CHARS || ($now - $last) >= self::STREAM_FLUSH_SECONDS) {
                // Targeted UPDATE (never save()): during a RESUME the row is already
                // sealed, and a full save() would decrypt-and-rewrite its content.
                if ($sealed) {
                    if ($owner_id > 0 && !VaultUnlock::isOpen($owner_id)) {
                        // Window closed mid-turn: honor the wipe — no plaintext
                        // partial past the lock. Mop up any flush that raced the wipe.
                        self::clearScratch((int)$msg->key);
                    } else {
                        self::writeScratch((int)$msg->key, $buffer);
                    }
                    if (!$stamped_writing) {
                        AiConversationMessage::updateColumns((int)$msg->key, ['aim_activity' => 'Writing…']);
                        $stamped_writing = true;
                    }
                } else {
                    $cols = ['aim_content' => $buffer];
                    if (!$stamped_writing) { $cols['aim_activity'] = 'Writing…'; $stamped_writing = true; }
                    AiConversationMessage::updateColumns((int)$msg->key, $cols);
                }
                $pending = 0;
                $last = $now;
            }
        };
    }

    /**
     * The streaming-partial scratch for a protected turn (Phase 2). A RAM-backed
     * tmpfs file keyed to the assistant message id — every process on the host
     * sees /dev/shm (the sealed turn runs in-process on the web SAPI and chat_poll
     * reads it from another web request; the vault's window marker uses the same
     * tmpfs discipline for the same cross-process reason). The partial is
     * plaintext in RAM only, never at rest in the DB, and is cleared at finalize.
     *
     * FAIL CLOSED: with no writable tmpfs there is nowhere the plaintext partial
     * may go — a disk-backed temp dir is exactly the at-rest plaintext this
     * feature exists to prevent. Returns null; the turn still runs and finalizes
     * sealed, the poll just shows no partial text until then.
     */
    private static function scratchDir(): ?string {
        return (is_dir('/dev/shm') && is_writable('/dev/shm')) ? '/dev/shm' : null;
    }

    public static function scratchPath(int $message_id): ?string {
        $dir = self::scratchDir();
        return $dir === null ? null : $dir . '/aichat_partial_' . $message_id;
    }

    public static function writeScratch(int $message_id, string $buffer): void {
        if ($message_id <= 0) return;
        $path = self::scratchPath($message_id);
        if ($path === null) {
            static $warned = false;
            if (!$warned) {
                error_log('[joinery_ai chat] no writable /dev/shm — protected-turn partials stay in process RAM only (no live partial text)');
                $warned = true;
            }
            return;
        }
        @file_put_contents($path, $buffer, LOCK_EX);
        @chmod($path, 0600);
    }

    public static function readScratch(int $message_id): ?string {
        if ($message_id <= 0) return null;
        $path = self::scratchPath($message_id);
        if ($path === null || !is_file($path)) return null;
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    public static function clearScratch(int $message_id): void {
        if ($message_id <= 0) return;
        $path = self::scratchPath($message_id);
        if ($path !== null) @unlink($path);
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
            // Targeted UPDATE of the cleartext stage label only — safe on a sealed
            // row (a resume stamps activity on an already-sealed message).
            AiConversationMessage::updateColumns((int)$msg->key,
                ['aim_activity' => $label !== '' ? $label : null]);
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

        // Seal the error on a protected conversation (errorColumns resolves it and
        // no-ops for Standard); persist via a targeted UPDATE (the row may be a
        // sealed, finalized message being reaped). Clear any leftover scratch.
        $cols = ChatSeal::errorColumns($msg, 'The turn did not finish (the worker process appears to have stopped).');
        $cols['aim_status']   = AiConversationMessage::STATUS_FAILED;
        $cols['aim_activity'] = null;
        AiConversationMessage::updateColumns((int)$msg->key, $cols);
        self::clearScratch((int)$msg->key);
        return true;
    }

}
