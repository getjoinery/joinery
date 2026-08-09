<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_queued_actions_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/QueueableToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolRegistry.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class ActionQueueException extends Exception {}

/**
 * The AI proposed-action queue (specs/implemented/ai_action_queue.md): the one deferred
 * write door. A chat write tool call lands here as a pending row and the
 * conversation continues; the call executes only at the moment the owner
 * approves it — in that request, as the owner, through the same audited tool
 * path — or resolves declined/expired/failed and never runs.
 *
 * Every card fact is rendered from the stored literal arguments
 * (QueueableToolInterface::renderProposedAction()), never from model prose.
 *
 * Queued web-egress calls (the hot-turn egress rule) differ from writes in
 * one way only: a write's value is its side effect, a fetch's value is its
 * content — so an approved egress action's full result is carried back into
 * the conversation through the resolution event row, where the next turn can
 * reason over it.
 *
 * @version 1.2
 */
class ActionQueue {

    /** Longest rendered fact line — literal values, but bounded for the card. */
    const FACT_LINE_MAX = 200;

    /** Ceiling on a verbatim result carried in an event row. Above the web
     *  tools' own 50k output caps, so in practice nothing is cut; a hard
     *  bound so a misbehaving tool cannot balloon the transcript. */
    const EVENT_RESULT_MAX = 60000;

    /**
     * Queue one proposed tool call for $owner_id. Returns the created row.
     *
     * Sealing (the queue is a declared sealed sink from day one): when the
     * enqueueing process is hot, the arguments must land sealed to the owner —
     * and when they cannot be (no single attributable owner, a different
     * owner's scope, or no vault), the enqueue is REFUSED rather than stored
     * in the clear.
     *
     * @throws ActionQueueException when the tool cannot be queued
     */
    public static function enqueue(int $owner_id, string $tool_name, array $input,
            ?int $conversation_id = null, string $area = '',
            string $source_type = AiQueuedAction::SOURCE_CHAT, ?int $recipe_id = null): AiQueuedAction {
        if ($owner_id <= 0) {
            throw new ActionQueueException('A queued action needs an owner.');
        }
        $tool = RecipeToolRegistry::get($tool_name);
        if ($tool === null || !($tool instanceof QueueableToolInterface)) {
            // The one-card rule: no renderer, no queue entry — refuse the call.
            throw new ActionQueueException(
                "'$tool_name' cannot be queued for approval (it declares no card renderer), "
                . 'so it is not available here.');
        }

        $hot = SealedEgressGuard::isHot();
        $vault = null;
        if ($hot) {
            $hot_owner = SealedEgressGuard::ownerUserId();
            if ($hot_owner === null || (int)$hot_owner !== $owner_id) {
                throw new ActionQueueException(
                    'This proposal may quote protected content that cannot be attributed to '
                    . 'you alone, so it cannot be queued.');
            }
            $vault = UserEncryptionVault::loadForUser($owner_id);
            if ($vault === null || !$vault->key) {
                throw new ActionQueueException(
                    'This proposal may quote protected content and you have no vault to seal '
                    . 'it to, so it cannot be queued.');
            }
        }

        $row = new AiQueuedAction(NULL);
        $row->set('aqa_owner_user_id', $owner_id);
        $row->set('aqa_area', $area);
        $row->set('aqa_source_type', $source_type);
        $row->set('aqa_aic_conversation_id', $conversation_id ?: null);
        $row->set('aqa_rcp_recipe_id', $recipe_id ?: null);
        $row->set('aqa_tool', $tool_name);
        $row->set('aqa_status', AiQueuedAction::STATUS_PENDING);
        $row->set('aqa_created_time', gmdate('Y-m-d H:i:s'));
        $row->set('aqa_expires_time', gmdate('Y-m-d H:i:s',
            time() + AiQueuedAction::DEFAULT_EXPIRY_DAYS * 86400));
        // Cold: the arguments store in the clear, directly. Hot: the row is
        // INSERTed with the sealed column empty, then sealColumns() writes the
        // ciphertext (the AD binds to the row id, so the id must exist first).
        $encoded = json_encode($input, JSON_UNESCAPED_SLASHES);
        if (!$hot) {
            $row->set('aqa_arguments', $encoded);
        }
        $row->save();
        $row->load();

        if ($hot) {
            AiQueuedAction::sealColumns((int)$row->key, $vault, ['aqa_arguments' => $encoded]);
            $row->load();
        }
        return $row;
    }

    /**
     * The platform-rendered facts for a card: the queued tool's own renderer
     * over the literal stored arguments, each line bounded. Null when the row
     * is sealed and the owner's window is not open (the card then renders as
     * locked). The renderer's output is the card's substance — model prose
     * never reaches it.
     */
    public static function factsFor(AiQueuedAction $row): ?array {
        $arguments = self::openArguments($row);
        if ($arguments === null) return null;

        $tool = RecipeToolRegistry::get((string)$row->get('aqa_tool'));
        if ($tool === null || !($tool instanceof QueueableToolInterface)) {
            // The tool vanished since the proposal (plugin removed) — the card
            // still has to say what it would have done.
            return ['Run ' . (string)$row->get('aqa_tool') . ' (this tool is no longer installed)'];
        }
        $lines = [];
        foreach ($tool->renderProposedAction($arguments) as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $lines[] = mb_strlen($line) > self::FACT_LINE_MAX
                ? mb_substr($line, 0, self::FACT_LINE_MAX - 1) . '…' : $line;
        }
        return $lines !== [] ? $lines : ['Run ' . (string)$row->get('aqa_tool')];
    }

    /**
     * Resolve one pending action as its owner. 'approve' executes the call in
     * THIS request, re-validating against live state exactly as the tool
     * always does (allowlists, opt-ins, authenticate_write, the logic file's
     * own gauntlet); a validation miss or execution error resolves the row
     * `failed` with the reason — an approved action never silently
     * half-happens. 'decline' resolves the row and runs nothing. Either way
     * the resolution is appended to the source conversation as an event, so
     * the model knows on its next turn.
     *
     * @return AiQueuedAction the resolved row (fresh)
     * @throws ActionQueueException on every refusal (not the owner's, not
     *         pending, expired) — idempotent-safe: the card refreshes to its
     *         true state.
     */
    public static function resolve(int $action_id, int $user_id, string $resolution): AiQueuedAction {
        if (!in_array($resolution, ['approve', 'decline'], true)) {
            throw new ActionQueueException('Invalid resolution.');
        }
        $row = new AiQueuedAction($action_id, TRUE);
        if (!$row->key || $row->get('aqa_delete_time')
                || (int)$row->get('aqa_owner_user_id') !== $user_id) {
            throw new ActionQueueException('No such pending action of yours.');
        }
        if ((string)$row->get('aqa_status') !== AiQueuedAction::STATUS_PENDING) {
            throw new ActionQueueException(
                'This action is already ' . (string)$row->get('aqa_status') . '.');
        }
        if (self::isOverdue($row)) {
            self::markResolved($row, AiQueuedAction::STATUS_EXPIRED, null);
            throw new ActionQueueException('This proposal expired before it was resolved, so it can no longer run.');
        }

        if ($resolution === 'decline') {
            self::markResolved($row, AiQueuedAction::STATUS_DECLINED, null);
            $row->load();
            self::appendResolutionEvent($row, 'declined', null);
            return $row;
        }

        // Approve — execute now, in-request, as the owner.
        $arguments = self::openArguments($row);
        if ($arguments === null) {
            throw new ActionQueueException(
                'This proposal is sealed to your vault — unlock it to approve.');
        }

        $conversation = null;
        $conv_id = (int)$row->get('aqa_aic_conversation_id');
        if ($conv_id > 0) {
            $conversation = new AiConversation($conv_id, TRUE);
            if (!$conversation->key || $conversation->get('aic_delete_time')) $conversation = null;
        }
        if ($conversation === null) {
            // Execution scope (model/action allowlists) lives on the source
            // conversation; without it there is nothing safe to execute under.
            self::markResolved($row, AiQueuedAction::STATUS_FAILED,
                ['error' => 'The conversation this action came from no longer exists.']);
            $row->load();
            return $row;
        }

        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));
        $ctx = new ChatTurnContext($conversation, $user_id);
        $tool_use = [
            'type'  => 'tool_use',
            'id'    => 'toolu_queued_' . (int)$row->key,
            'name'  => (string)$row->get('aqa_tool'),
            'input' => $arguments,
        ];
        $block = AgentLoop::executeApproved($tool_use, $ctx);

        $is_error = !empty($block['is_error']);
        $content = $block['content'] ?? '';
        $summary = is_array($content) ? '[content blocks]' : (string)$content;
        $result = $is_error ? ['error' => $summary] : ['status' => 'success', 'summary' => $summary];

        self::markResolved($row,
            $is_error ? AiQueuedAction::STATUS_FAILED : AiQueuedAction::STATUS_APPROVED,
            $result);
        $row->load();
        // A queued egress call's result IS the content the model asked for —
        // carry it back verbatim, not as a one-line summary.
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RiskHeuristic.php'));
        $verbatim = !$is_error && in_array((string)$row->get('aqa_tool'),
            RiskHeuristic::WEB_EGRESS_TOOLS, true);
        self::appendResolutionEvent($row, $is_error ? 'failed' : 'approved',
            self::resultLine($result), $verbatim);
        return $row;
    }

    /** A pending row past its expiry can never execute; sweep it on sight. */
    public static function expireOverdueFor(int $user_id): void {
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare(
            "UPDATE aqa_ai_queued_actions
                SET aqa_status = ?, aqa_resolved_time = ?
              WHERE aqa_owner_user_id = ? AND aqa_status = ?
                AND aqa_expires_time IS NOT NULL AND aqa_expires_time < ?
                AND aqa_delete_time IS NULL");
        $now = gmdate('Y-m-d H:i:s');
        $q->execute([AiQueuedAction::STATUS_EXPIRED, $now, $user_id,
            AiQueuedAction::STATUS_PENDING, $now]);
    }

    /** Pending count for the AI button's badge — one indexed count. */
    public static function pendingCount(int $user_id): int {
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare(
            "SELECT count(*) FROM aqa_ai_queued_actions
              WHERE aqa_owner_user_id = ? AND aqa_status = ?
                AND (aqa_expires_time IS NULL OR aqa_expires_time >= ?)
                AND aqa_delete_time IS NULL");
        $q->execute([$user_id, AiQueuedAction::STATUS_PENDING, gmdate('Y-m-d H:i:s')]);
        return (int)$q->fetchColumn();
    }

    /** One action as the card shape every surface renders. */
    public static function card(AiQueuedAction $row): array {
        $facts = self::factsFor($row);
        $result = null;
        if ($facts !== null) {
            $raw = (string)$row->get('aqa_result');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $result = self::resultLine($decoded);
                if (mb_strlen($result) > 500) $result = mb_substr($result, 0, 499) . '…';
            }
        }
        return [
            'action_id'       => (int)$row->key,
            'tool'            => (string)$row->get('aqa_tool'),
            'area'            => (string)$row->get('aqa_area'),
            'status'          => (string)$row->get('aqa_status'),
            'locked'          => $facts === null,
            'facts'           => $facts,
            'model_note'      => $facts === null ? null : (string)$row->get('aqa_model_note'),
            'result'          => $result,
            'conversation_id' => (int)$row->get('aqa_aic_conversation_id') ?: null,
            'created_time'    => (string)$row->get('aqa_created_time'),
            'expires_time'    => (string)$row->get('aqa_expires_time'),
            'resolved_time'   => (string)$row->get('aqa_resolved_time'),
        ];
    }

    /**
     * One plain sentence from a stored execution result. The stored value is
     * {error} or {status, summary}, where summary is the raw tool result —
     * often itself a JSON envelope. The card gets the envelope's own summary
     * line when it has one, never the raw JSON.
     */
    private static function resultLine(array $decoded): string {
        if (isset($decoded['error'])) return (string)$decoded['error'];
        $summary = (string)($decoded['summary'] ?? '');
        $inner = json_decode($summary, true);
        if (is_array($inner)) {
            if (!empty($inner['summary'])) return (string)$inner['summary'];
            if (!empty($inner['message'])) return (string)$inner['message'];
            if (($inner['status'] ?? '') === 'success') {
                $what = (string)($inner['model'] ?? $inner['action'] ?? '');
                $key = isset($inner['key']) ? ' #' . $inner['key'] : '';
                return trim('Completed' . ($what !== '' ? " — $what$key" : '') . '.');
            }
        }
        return $summary;
    }

    // ---- internals ----

    /** The literal arguments, opened if sealed — or null while locked. */
    private static function openArguments(AiQueuedAction $row): ?array {
        if (!empty($row->get('aqa_content_sealed'))) {
            $owner_id = (int)$row->get('aqa_owner_user_id');
            if (!VaultUnlock::isOpen($owner_id, UserEncryptionVault::SCOPE_USER)) {
                return null;
            }
        }
        $raw = (string)$row->get('aqa_arguments');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Terminal-state write. The result seals whenever the row itself is
     * sealed (re-using the row DEK, openable only in-window — which approval
     * has by construction) or the resolving process is hot.
     */
    private static function markResolved(AiQueuedAction $row, string $status, ?array $result): void {
        $cols = [
            'aqa_status'        => $status,
            'aqa_resolved_time' => gmdate('Y-m-d H:i:s'),
        ];
        $encoded = $result !== null ? json_encode($result, JSON_UNESCAPED_SLASHES) : null;

        $row_sealed = !empty($row->get('aqa_content_sealed'));
        $hot = SealedEgressGuard::isHot();
        if ($encoded !== null && ($row_sealed || $hot)) {
            $owner_id = (int)$row->get('aqa_owner_user_id');
            AiQueuedAction::updateColumns((int)$row->key, $cols);
            $vault = UserEncryptionVault::loadForUser($owner_id);
            if ($vault === null || !$vault->key) {
                error_log('ActionQueue: result for action ' . (int)$row->key
                    . ' not retained — no vault to seal it to.');
                return;
            }
            $reuse_dek = null;
            if ($row_sealed) {
                // Same-DEK reseal so the already-sealed arguments still open.
                $secret = VaultUnlock::secretKey($owner_id);
                if ($secret === null) {
                    error_log('ActionQueue: result for action ' . (int)$row->key
                        . ' not retained — row is sealed and the window closed mid-resolve.');
                    return;
                }
                require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
                $reuse_dek = (new VaultCrypto())->openItemDek(
                    (string)$row->get('aqa_sealed_key'), $secret);
            }
            AiQueuedAction::sealColumns((int)$row->key, $vault,
                ['aqa_result' => $encoded], $reuse_dek);
            return;
        }

        if ($encoded !== null) $cols['aqa_result'] = $encoded;
        AiQueuedAction::updateColumns((int)$row->key, $cols);
    }

    private static function isOverdue(AiQueuedAction $row): bool {
        $expires = (string)$row->get('aqa_expires_time');
        return $expires !== '' && substr($expires, 0, 19) < gmdate('Y-m-d H:i:s');
    }

    /**
     * Tell the source conversation how its proposal resolved — an EVENT row
     * the transcript shows as a neutral chip and the model reads on its next
     * turn. Sealed like any other content on a protected conversation; a
     * failure to append never un-resolves the action.
     *
     * $verbatim carries the result at full length (bounded by
     * EVENT_RESULT_MAX) instead of the one-line 400-char summary — the
     * approved-egress path, where the result is the content itself.
     */
    private static function appendResolutionEvent(AiQueuedAction $row, string $outcome,
            ?string $summary, bool $verbatim = false): void {
        $conv_id = (int)$row->get('aqa_aic_conversation_id');
        if ($conv_id <= 0) return;
        try {
            $conversation = new AiConversation($conv_id, TRUE);
            if (!$conversation->key || $conversation->get('aic_delete_time')) return;

            $facts = self::factsFor($row);
            $headline = $facts !== null && $facts !== [] ? $facts[0] : ('Action ' . (string)$row->get('aqa_tool'));
            $outcome_phrase = $outcome === 'approved' ? 'the owner approved it and it ran'
                : ($outcome === 'failed' ? 'the owner approved it but it failed'
                : 'the owner declined it; do not retry it');
            $open = '[Queued action #' . (int)$row->key . ' — ' . $headline . ' — ' . $outcome_phrase;
            $has_result = $summary !== null && trim($summary) !== '';
            if (!$has_result) {
                $text = $open . '.]';
            } elseif ($verbatim) {
                // Egress: the fetched content is the value. Carry it AFTER the
                // narration, past a separator, so the history builder frames it as
                // untrusted and windows it while the narration stays trusted. On a
                // protected conversation the whole row seals; the confinement rule
                // means a standard conversation never reaches this branch hot.
                $text = $open . '.]' . AiConversationMessage::EVENT_RESULT_SEP
                      . self::boundResult($summary, true);
            } else {
                $text = $open . '. Result: ' . self::boundResult($summary, false) . ']';
            }

            $msg = new AiConversationMessage(NULL);
            $msg->set('aim_aic_conversation_id', $conv_id);
            $msg->set('aim_role', AiConversationMessage::ROLE_EVENT);
            $msg->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
            $msg->set('aim_create_time', gmdate('Y-m-d H:i:s'));
            $msg->save();
            $msg->load();

            require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
            $cols = ChatSeal::userColumns($conversation, (int)$msg->key, $text);
            AiConversationMessage::updateColumns((int)$msg->key, $cols);
            AiConversation::updateColumns($conv_id, [
                'aic_update_time' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('ActionQueue: could not append resolution event for action '
                . (int)$row->key . ': ' . $e->getMessage());
        }
    }

    /** The event row's result text: a one-line summary for writes, the full
     *  content (up to EVENT_RESULT_MAX) for approved egress. */
    private static function boundResult(string $summary, bool $verbatim): string {
        $max = $verbatim ? self::EVENT_RESULT_MAX : 400;
        return mb_strlen($summary) > $max
            ? mb_substr($summary, 0, $max - 1) . '…' : $summary;
    }

}
