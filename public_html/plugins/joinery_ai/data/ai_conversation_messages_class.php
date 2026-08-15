<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * One turn in an AiConversation — a user message, an assistant reply, or a
 * platform-written EVENT (a queued action's resolution). Assistant rows
 * additionally carry the per-turn tool trace and token counts.
 *
 * The FK column follows the {prefix}_{source_prefix}_{entity}_id convention
 * (aim_aic_conversation_id → aic_conversations) so the deletion system
 * auto-detects the parent and cascades when a conversation is permanently
 * deleted. Deleting a conversation loads each message via permanent_delete (not a
 * flat cascade) so the recursion reaches each message's own children —
 * attachment-link rows, which in turn clean up their uploaded File bytes. Not
 * $ai_readable.
 */
class AiConversationMessageException extends SystemBaseException {}

class AiConversationMessage extends SystemBase {

    public static $prefix = 'aim';
    public static $tablename = 'aim_conversation_messages';
    public static $pkey_column = 'aim_message_id';

    const ROLE_USER      = 'user';
    const ROLE_ASSISTANT = 'assistant';
    // A platform-written transcript fact, not a person speaking: how a queued
    // action resolved (specs/implemented/ai_action_queue.md). Rendered as a neutral chip;
    // the history builder feeds it to the model as user-side context.
    const ROLE_EVENT     = 'event';

    // Boundary inside an EVENT row's content between the trusted platform
    // narration ("[Queued action #N approved and ran.]") and an approved web
    // action's fetched result carried after it. The history builder splits here
    // to frame the result as untrusted and to window it (only the most recent
    // carried result is sent in full). Only egress-result events use it; write
    // events keep their short summary inline. The narration never contains it,
    // so a first-occurrence split is exact regardless of the result's bytes.
    const EVENT_RESULT_SEP = "\n\u{2500}\u{2500} fetched result \u{2500}\u{2500}\n";

    // Turn lifecycle for asynchronous chat. A user row is COMPLETE on insert;
    // an assistant placeholder is RUNNING until the in-process turn finishes it
    // (COMPLETE) or it errors / is reaped (FAILED). CANCELLED is a terminal state
    // for a turn the user stopped mid-flight (aim_cancel_requested); any partial
    // answer already streamed is kept. See ChatAsync / ChatTurn.
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETE  = 'complete';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected static $foreign_key_actions = [
        'aim_aic_conversation_id' => ['action' => 'permanent_delete'],
    ];

    public static $field_specifications = array(
        'aim_message_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'aim_aic_conversation_id' => array('type'=>'int8', 'required'=>true),
        'aim_role'                => array('type'=>'varchar(20)', 'required'=>true),
        'aim_content'             => array('type'=>'text'),
        // 'text' (not jsonb) so a sealed turn can hold ciphertext, which is not
        // valid JSON — removed from $json_vars accordingly. A Standard turn stores
        // plain JSON text; a Private/Fortress turn stores an AEAD blob. The seal/
        // unseal path does the json_encode/json_decode around the ciphertext, and
        // every reader already tolerates a string (json_decodes it).
        'aim_tool_calls'          => array('type'=>'text'),
        // Sealed Vault consumer columns (docs/sealed_vault.md). aim_sealed_key is
        // the per-message DEK sealed to the owner's vault public key; the content
        // columns (aim_content/aim_tool_calls/aim_error) seal
        // under it. aim_key_generation matches the vault generation (rotation);
        // aim_content_sealed marks a sealed row; aim_sealed_owner_user_id records
        // WHOSE vault the row seals to (the conversation owner) so decryption is
        // self-contained without re-reading the conversation.
        'aim_sealed_key'          => array('type'=>'text', 'is_nullable'=>true),
        'aim_key_generation'      => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
        'aim_content_sealed'      => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        'aim_sealed_owner_user_id'=> array('type'=>'int8', 'is_nullable'=>true),
        'aim_input_tokens'        => array('type'=>'int4', 'default'=>0),
        'aim_output_tokens'       => array('type'=>'int4', 'default'=>0),
        // The model's real context window (tokens) when this turn ran, read from
        // the local host's /api/ps. Colors the per-reply context number by how close
        // it is to the limit. NULL for remote models or an unreachable host.
        'aim_context_window'      => array('type'=>'int4', 'is_nullable'=>true),
        // The conversation's size as of this turn: the first tool-loop call's prompt,
        // which is the system prompt plus the whole conversation plus this turn's
        // message, before any tool result joins it. Only ever grows, so the badge
        // built on it climbs toward the window and never travels back — once it
        // reads red, the oldest exchanges are being dropped on every turn and only a
        // new conversation resets it. Deliberately NOT aim_input_tokens, which sums
        // every call in the loop (counting the system prompt and conversation once
        // per step) and so moves with tool volume rather than conversation length.
        // NULL for rows written before this column existed.
        'aim_context_used'        => array('type'=>'int4', 'is_nullable'=>true),
        'aim_status'              => array('type'=>'varchar(20)', 'default'=>'complete'),
        // Cross-process cancel signal. The chat_cancel endpoint sets this TRUE on a
        // RUNNING assistant row; the running turn re-reads it (fresh SELECT) at the
        // AgentLoop boundary and mid-stream, then exits STATUS_CANCELLED. Mirrors
        // recipe runs' rcr_kill_requested. Cleared on every finalize path.
        'aim_cancel_requested'    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        // Live one-line stage label while the row is RUNNING ("Waiting for
        // {model}…", "Running tool: web_search…"); NULL once the turn
        // finalizes. The turn runner is the only writer. See
        // specs/ai_chat_turn_activity.md.
        'aim_activity'            => array('type'=>'varchar(160)'),
        'aim_error'               => array('type'=>'text'),
        'aim_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'aim_delete_time'         => array('type'=>'timestamp(6)'),
    );

    // aim_tool_calls is plain 'text' (not $json_vars) so a sealed turn can hold
    // ciphertext, which is not valid JSON. The seal/unseal path json_encodes on
    // write and readers json_decode.
    public static $json_vars = array();

    // Sealed Vault generic read hook (docs/sealed_vault.md): SystemBase::get()
    // decrypts these automatically on a sealed row. aim_content is the user prompt
    // / assistant reply; aim_tool_calls the per-turn tool trace (names+args+
    // results); aim_error may echo provider/content detail. All cleartext on a
    // Standard conversation.
    public static $sealed_fields = array('aim_content', 'aim_tool_calls', 'aim_error');

    // Sealing runs through ChatSeal: a message's DEK also seals its
    // attachments' bytes, so the key has to be minted where both can reach it.
    public static $seal_on_save = false;

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 5) {
            throw new SystemAuthenticationError(
                'Joinery AI chat messages require permission level 5 to write.');
        }
    }

    /**
     * Sealed Vault read hook (docs/sealed_vault.md), the SystemBase::get() path.
     * Decrypts only when the row is marked sealed AND the value carries the
     * sealed-blob prefix — an empty/unsealed field on a protected row (e.g. a
     * failed placeholder that only sealed aim_error) or any Standard row is
     * returned untouched. Throws VaultLockedException when the window is closed.
     */
    protected function decryptSealedField($field, $ciphertext) {
        if (!$this->get('aim_content_sealed') || !is_string($ciphertext)
                || strpos($ciphertext, 'v1.aead.') !== 0) {
            return $ciphertext;
        }
        $owner = (int)$this->get('aim_sealed_owner_user_id');
        $sealed_key = (string)$this->get('aim_sealed_key');
        if ($owner <= 0 || $sealed_key === '') {
            require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
            throw new VaultLockedException();
        }
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        return ChatSeal::openMessageField((int)$this->key, $owner, $sealed_key, $field, $ciphertext);
    }

    /** Static half of the sealed-field contract (raw associative row). Chat
     *  messages are not $ai_readable, so ModelQueryExecutor never calls this, but
     *  the contract requires it rather than defaulting to the throwing base. */
    public static function decryptSealedFieldStatic($field, $ciphertext, array $row) {
        if (empty($row['aim_content_sealed']) || !is_string($ciphertext)
                || strpos($ciphertext, 'v1.aead.') !== 0) {
            return $ciphertext;
        }
        $owner = (int)($row['aim_sealed_owner_user_id'] ?? 0);
        $sealed_key = (string)($row['aim_sealed_key'] ?? '');
        if ($owner <= 0 || $sealed_key === '') {
            require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
            throw new VaultLockedException();
        }
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        return ChatSeal::openMessageField((int)($row['aim_message_id'] ?? 0), $owner, $sealed_key, $field, $ciphertext);
    }

    /**
     * Permanently delete this message and clean up its attachment links (which in
     * turn delete the uploaded File bytes), explicitly and in order, before the
     * message row itself is deleted - each link's own File cleanup (cloud/disk
     * bytes, not just the row) needs a real permanent_delete() call, not a flat
     * cascade. Reached both on a direct message delete and when a conversation
     * is deleted (its aic→aim rule is `permanent_delete`, which loads each
     * message and calls this).
     *
     * A link's permanent_delete() is let to propagate rather than caught here: if
     * it fails, this message must NOT be deleted out from under a still-live
     * attachment link (that would leave the link referencing a deleted message
     * with no way to ever be cleaned up). The whole cascade runs inside one
     * transaction (SystemBase::permanent_delete()), so an uncaught failure rolls
     * everything back instead of committing a partial delete.
     */
    // A sealed row must NEVER be save()d: SystemBase::save() rebuilds every
    // column through get(), which decrypts the sealed fields and would write
    // plaintext back (unsealing them) or throw when the window is closed. Every
    // write to a sealed message — seal-on-write, status/token/activity flips,
    // re-seal — goes through SystemBase::updateColumns() instead.

    /**
     * Soft-delete via a targeted UPDATE, not the base save() path: on a sealed
     * message SystemBase::soft_delete()'s save() would decrypt the content columns
     * and write them back as plaintext (unsealing a still-recoverable row) or throw
     * when the vault is locked.
     */
    public function soft_delete() {
        self::updateColumns((int)$this->key, ['aim_delete_time' => gmdate('Y-m-d H:i:s')]);
        $this->set('aim_delete_time', gmdate('Y-m-d H:i:s'));
        return true;
    }

    public function permanent_delete($debug = false) {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));
        $links = new MultiAiMessageAttachment(['message_id' => (int)$this->key], []);
        $links->load();
        foreach ($links as $link) {
            $link->permanent_delete($debug);
        }
        return parent::permanent_delete($debug);
    }

}

class MultiAiConversationMessage extends SystemMultiBase {
    protected static $model_class = 'AiConversationMessage';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['conversation_id'])) {
            $filters['aim_aic_conversation_id'] = [$this->options['conversation_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['role'])) {
            $filters['aim_role'] = [$this->options['role'], PDO::PARAM_STR];
        }

        if (isset($this->options['status'])) {
            $filters['aim_status'] = [$this->options['status'], PDO::PARAM_STR];
        }

        if (isset($this->options['deleted'])) {
            $filters['aim_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['aim_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('aim_conversation_messages', $filters, $this->order_by, $only_count, $debug);
    }

}
