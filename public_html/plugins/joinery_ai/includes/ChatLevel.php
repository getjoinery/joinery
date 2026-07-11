<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/FireworksProvider.php'));

/**
 * The per-conversation security level's prerequisites and resolution
 * (specs/joinery_ai_chat_encryption.md § levels). Standard is always available;
 * Private needs the owner to hold a Sealed Vault (nothing to seal to without one);
 * Fortress needs a vault AND a configured local model (its whole point is pinning
 * inference to a local model). This is the one place those rules live, shared by
 * the create path, the level selector, and the Fortress provider gate.
 */
class ChatLevel {

    /** A local model is served (joinery_ai_local_model set). */
    public static function localModelConfigured(): bool {
        return (string)Globalvars::get_instance()->get_setting('joinery_ai_local_model') !== '';
    }

    /** Whether a model id routes to the local host (not Anthropic, not Fireworks). */
    public static function isLocalModel(string $model): bool {
        $m = trim($model);
        if ($m === '') return false;
        if (preg_match('/^claude/i', $m)) return false;
        if (FireworksProvider::owns($m)) return false;
        return true;
    }

    /** The default local model id (first of the configured list), or '' if none. */
    public static function localDefaultModel(): string {
        try {
            return (string)LlmProviderFactory::forModel(self::anyLocalId())->defaultModel();
        } catch (Throwable $e) {
            return self::anyLocalId();
        }
    }

    /** The first configured local model id, from the (possibly comma-separated) setting. */
    private static function anyLocalId(): string {
        $raw = (string)Globalvars::get_instance()->get_setting('joinery_ai_local_model');
        $first = trim(explode(',', $raw)[0] ?? '');
        return $first;
    }

    public static function privateAvailable(int $owner_id): bool {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        return ChatSeal::ownerHasVault($owner_id);
    }

    public static function fortressAvailable(int $owner_id): bool {
        return self::privateAvailable($owner_id) && self::localModelConfigured();
    }

    /** The configured plugin-wide default level (falls back to standard). */
    public static function defaultLevel(): string {
        $lvl = (string)Globalvars::get_instance()->get_setting('joinery_ai_default_chat_level');
        return in_array($lvl, ChatSeal::levels(), true) ? $lvl : ChatSeal::LEVEL_STANDARD;
    }

    /**
     * The effective level for a NEW conversation: the composer's explicit choice
     * when valid, else the plugin default — then downgraded when its prerequisites
     * are missing (Fortress → Private without a local model, Private → Standard
     * without a vault) so a new chat never claims a protection it can't deliver.
     */
    public static function resolveForNew($requested, int $owner_id): string {
        $level = in_array($requested, ChatSeal::levels(), true) ? (string)$requested : self::defaultLevel();

        if ($level === ChatSeal::LEVEL_FORTRESS && !self::fortressAvailable($owner_id)) {
            $level = self::privateAvailable($owner_id) ? ChatSeal::LEVEL_PRIVATE : ChatSeal::LEVEL_STANDARD;
        }
        if ($level === ChatSeal::LEVEL_PRIVATE && !self::privateAvailable($owner_id)) {
            $level = ChatSeal::LEVEL_STANDARD;
        }
        return $level;
    }

    /**
     * Change an existing conversation's level, converging its stored content
     * (specs/joinery_ai_chat_encryption.md § Phase 6 backfill). Raising to a
     * protected level seals title/instructions + every message + attachment under
     * fresh DEKs (idempotent — already-sealed rows are skipped); sealing needs
     * only the public key. Lowering to Standard decrypts everything back to
     * plaintext and so requires an open window. Fortress additionally requires a
     * local model and pins the chat's model to one. Returns
     * ['ok'=>bool, 'error'=>?string, 'level'=>string].
     */
    public static function changeLevel(AiConversation $c, string $target, int $uid): array {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

        if (!in_array($target, ChatSeal::levels(), true)) {
            return ['ok' => false, 'error' => 'Invalid privacy level.'];
        }
        $owner = (int)$c->get('aic_owner_user_id');
        if ($owner !== $uid) return ['ok' => false, 'error' => 'Not your chat.'];

        $current = (string)$c->get('aic_security_level') ?: ChatSeal::LEVEL_STANDARD;
        $target_protected  = ChatSeal::isProtectedLevel($target);
        $current_protected = ChatSeal::isProtectedLevel($current);

        // Prerequisites.
        if ($target_protected && !ChatSeal::ownerHasVault($owner)) {
            return ['ok' => false, 'error' => 'Set up your encryption vault first (in your security settings) to make a chat private.'];
        }
        if ($target === ChatSeal::LEVEL_FORTRESS && !self::localModelConfigured()) {
            return ['ok' => false, 'error' => 'Configure a local model in Joinery AI settings to use Fortress.'];
        }
        // Reading sealed content to unseal (or to reseal a locked protected chat)
        // needs the window.
        if ($current_protected && !ChatSeal::windowOpenFor($owner)) {
            return ['ok' => false, 'error' => 'Unlock your vault to change this protected chat’s privacy.'];
        }
        // A level change must not race an in-flight turn: the worker finalizes
        // with the level it captured at turn start, so converging the rows under
        // it would leave a plaintext turn on a sealed chat (or a sealed row on a
        // Standard one). Reap a stale runner first so a dead worker can't block
        // the change forever.
        if ($current !== $target && self::hasRunningTurn($c)) {
            return ['ok' => false, 'error' => 'Wait for the current reply to finish before changing this chat’s privacy.'];
        }

        if ($current === $target) {
            // No content transition; a to-Fortress no-op still ensures the model pin.
            if ($target === ChatSeal::LEVEL_FORTRESS && !self::isLocalModel((string)$c->get('aic_model'))) {
                AiConversation::updateColumns((int)$c->key, ['aic_model' => self::localDefaultModel()]);
            }
            return ['ok' => true, 'level' => $target];
        }

        try {
            if (!$current_protected && $target_protected) {
                self::sealConversationBackfill($c);
            } elseif ($current_protected && !$target_protected) {
                self::unsealConversationBackfill($c);
            }
            // protected↔protected (Private↔Fortress): content stays sealed under the
            // same DEKs; only the level and (for Fortress) the model pin change.

            $final = ['aic_security_level' => $target];
            if ($target === ChatSeal::LEVEL_FORTRESS && !self::isLocalModel((string)$c->get('aic_model'))) {
                $final['aic_model'] = self::localDefaultModel();
            }
            AiConversation::updateColumns((int)$c->key, $final);
        } catch (Throwable $e) {
            error_log('[joinery_ai chat] level change failed for conversation ' . $c->key . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not change this chat’s privacy — please try again.'];
        }
        return ['ok' => true, 'level' => $target];
    }

    /** Whether the conversation has a RUNNING assistant turn (a live worker that
     *  will finalize under the level it started with). Sweeps a stale runner
     *  (dead worker) first so it can't hold the level hostage. */
    private static function hasRunningTurn(AiConversation $c): bool {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$c->key,
             'status' => AiConversationMessage::STATUS_RUNNING, 'deleted' => false], []);
        $rows->load();
        foreach ($rows as $m) {
            if (!ChatAsync::sweepMessage($m)) return true;
        }
        return false;
    }

    /** Seal a Standard conversation's stored content (title/instructions + every
     *  message + attachment). Idempotent per row. */
    private static function sealConversationBackfill(AiConversation $c): void {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        if (!$c->get('aic_content_sealed')) {
            $title = (string)$c->get('aic_title');
            $instr = (string)$c->get('aic_instructions');
            AiConversation::updateColumns((int)$c->key,
                ChatSeal::sealConversationColumns((int)$c->key, $c, ['aic_title' => $title, 'aic_instructions' => $instr]));
            $c->load();
        }
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$c->key, 'deleted' => false], ['aim_message_id' => 'ASC']);
        $rows->load();
        foreach ($rows as $m) ChatSeal::sealExistingMessage($m, $c);
    }

    /** Reverse: decrypt a protected conversation's content back to plaintext. */
    private static function unsealConversationBackfill(AiConversation $c): void {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$c->key, 'deleted' => false], ['aim_message_id' => 'ASC']);
        $rows->load();
        foreach ($rows as $m) ChatSeal::unsealExistingMessage($m);

        if ($c->get('aic_content_sealed')) {
            $title = (string)$c->get('aic_title');           // get() decrypts in-window
            $instr = (string)$c->get('aic_instructions');
            AiConversation::updateColumns((int)$c->key, [
                'aic_title'          => $title,
                'aic_instructions'   => $instr,
                'aic_content_sealed' => false,
                'aic_sealed_key'     => null,
                'aic_key_generation' => 0,
            ]);
            $c->load();
        }
    }
}
