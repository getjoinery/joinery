<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

/**
 * The per-conversation security level's prerequisites and resolution
 * (specs/joinery_ai_chat_encryption.md § levels), and of the one add-on Private
 * carries. Standard is always available; Private needs the owner to hold a
 * Sealed Vault (nothing to seal to without one). The Local models only add-on
 * (aic_local_models_only) needs a Private chat AND a configured local model
 * (its whole point is pinning inference to one); it is one-way — once on, it
 * stays on. This is the one place those rules live, shared by the create path,
 * the level selector, the add-on switch, and the local-only provider gate.
 */
class ChatLevel {

    /**
     * Is there a model this install can reach that stays on the operator's own
     * hardware? The Local models only add-on's whole point, so it is asked of the catalog rather
     * than of one setting — an endpoint declared `local` qualifies whether it
     * is Ollama on this box or the operator's own LAN host.
     */
    public static function localModelConfigured(): bool {
        try {
            foreach (AiEndpointRegistry::catalog() as $entry) {
                if ((string)$entry['trust'] === AiModelRequirement::TRUST_LOCAL) return true;
            }
        } catch (Throwable $e) {
            // No usable catalog — nothing is reachable, local included.
        }
        return false;
    }

    /**
     * Does this model id run on hardware the operator controls?
     *
     * One lookup in the shipped catalog, where a model id belongs to exactly
     * one endpoint and that endpoint declares its trust class. This used to
     * re-implement the provider factory's string sniffing independently — two
     * copies of one decision, already drifting in shape — and an id neither
     * copy recognised was classified as local, which is to say safe.
     *
     * An unknown id is now NOT local, which is the correct direction to be
     * wrong in: it is what makes a local-only chat refuse a model nothing
     * classifies rather than assume the best of it.
     */
    public static function isLocalModel(string $model): bool {
        $m = trim($model);
        if ($m === '') return false;
        try {
            return AiEndpointRegistry::trustForModel($m) === AiModelRequirement::TRUST_LOCAL;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** A local model a local-only chat can start on, or '' when the operator
     *  serves none. Resolved rather than guessed, so it honours the same
     *  selection policy every other choice does. */
    public static function localDefaultModel(): string {
        return ChatRunner::defaultModelFor(true);
    }

    public static function privateAvailable(int $owner_id): bool {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        return ChatSeal::ownerHasVault($owner_id);
    }

    /** Whether this owner can have the Local models only add-on: a Private chat
     *  (so a vault) and a model on the operator's own hardware to pin to. */
    public static function localOnlyAvailable(int $owner_id): bool {
        return self::privateAvailable($owner_id) && self::localModelConfigured();
    }

    /** The configured plugin-wide default level (falls back to standard). A
     *  default still stored under the legacy name (before migration aic_001
     *  runs) reads as Private, the way a legacy conversation row does. */
    public static function defaultLevel(): string {
        $lvl = AiConversation::normalizeLevel(
            Globalvars::get_instance()->get_setting('joinery_ai_default_chat_level'));
        return in_array($lvl, ChatSeal::levels(), true) ? $lvl : ChatSeal::LEVEL_STANDARD;
    }

    /** The configured plugin-wide default for the Local models only add-on. A
     *  default level still stored under the legacy name meant Private pinned to
     *  a local model, so it is on whatever the (not yet seeded) flag says. */
    public static function defaultLocalOnly(): bool {
        $settings = Globalvars::get_instance();
        if ((string)$settings->get_setting('joinery_ai_default_chat_level') === AiConversation::LEGACY_LEVEL_LOCAL_ONLY) {
            return true;
        }
        return (bool)$settings->get_setting('joinery_ai_default_chat_local_only');
    }

    /**
     * The effective level for a NEW conversation: the composer's explicit choice,
     * else the plugin default — then downgraded to Standard when the owner has
     * no vault, so a new chat never claims a protection it can't deliver.
     *
     * A level the caller asked for that is not one of chat's rungs is refused
     * (null), never quietly swapped for the default: someone who asked for a
     * protected level and mistyped it must hear so.
     */
    public static function resolveForNew($requested, int $owner_id): ?string {
        // A page loaded before the fold posts the retired name (and no add-on
        // field) for "sealed + pinned to a local model": read it as Private here;
        // resolveLocalOnlyForNew() turns the pin on for it.
        if (self::isLegacyLocalOnlyRequest($requested)) $requested = ChatSeal::LEVEL_PRIVATE;

        $level = ProtectionLevel::fromInput($requested, self::defaultLevel());
        if ($level === null || !in_array($level, ChatSeal::levels(), true)) return null;

        if ($level === ChatSeal::LEVEL_PRIVATE && !self::privateAvailable($owner_id)) {
            $level = ChatSeal::LEVEL_STANDARD;
        }
        return $level;
    }

    /** Whether a requested level is the retired name an older client sends. */
    public static function isLegacyLocalOnlyRequest($requested): bool {
        return is_string($requested)
            && strtolower(trim($requested)) === AiConversation::LEGACY_LEVEL_LOCAL_ONLY;
    }

    /**
     * Whether a NEW conversation at $level (already resolved) starts with the
     * Local models only add-on: the composer's explicit choice when given, else
     * the plugin default — and only on a Private chat with a local model to pin
     * to, so a new chat never claims a protection it can't deliver. An older
     * client's retired level name ($requested_level) carries the add-on itself.
     */
    public static function resolveLocalOnlyForNew($requested, string $level, $requested_level = null): bool {
        $on = ($requested === null || $requested === '')
            ? (self::isLegacyLocalOnlyRequest($requested_level) || self::defaultLocalOnly())
            : in_array(strtolower(trim((string)$requested)), ['1', 'true', 'on', 'yes'], true);
        return $on && $level === ChatSeal::LEVEL_PRIVATE && self::localModelConfigured();
    }

    /**
     * Switch the Local models only add-on on an existing conversation. One-way:
     * turning it on pins the chat's model to a local one; asking to turn it off
     * once on is refused. It lives under Private, so a Standard chat is refused
     * (make it Private first), and it needs a configured local model. Returns
     * ['ok'=>bool, 'error'=>?string, 'local_models_only'=>bool].
     */
    public static function setLocalModelsOnly(AiConversation $c, bool $on, int $uid): array {
        if ((int)$c->get('aic_owner_user_id') !== $uid) return ['ok' => false, 'error' => 'Not your chat.'];
        $stored = $c->localModelsOnlyFlag();

        if (!$on) {
            if ($stored) {
                return ['ok' => false, 'error' => 'Local models only can’t be turned off once it’s on — start a new chat to use other models.'];
            }
            return ['ok' => true, 'local_models_only' => false];
        }
        if (!$c->isProtected()) {
            return ['ok' => false, 'error' => 'Make this chat Private first — Local models only is an extra protection for Private chats.'];
        }
        if (!self::localModelConfigured()) {
            return ['ok' => false, 'error' => 'Configure a local model in Joinery AI settings to use Local models only.'];
        }
        $cols = [];
        if (!(bool)$c->get('aic_local_models_only')) $cols['aic_local_models_only'] = true;
        if ((string)$c->get('aic_security_level') !== $c->level()) $cols['aic_security_level'] = $c->level();
        if (!self::isLocalModel((string)$c->get('aic_model'))) $cols['aic_model'] = self::localDefaultModel();
        if ($cols) AiConversation::updateColumns((int)$c->key, $cols);
        return ['ok' => true, 'local_models_only' => true];
    }

    /**
     * Why a turn of this chat on $model may not run, or null: the chat holds
     * Private content (it is Private, or its transcript became sealed-derived)
     * and $model runs on an endpoint the owner's PrivateContentConsent does not
     * allow. The words name the model's class, where the owner's setting keeps
     * private content, and both ways out — never a silent switch of model.
     */
    public static function privateContentRefusal(AiConversation $c, string $model, int $owner_id): ?string {
        if (!$c->isProtected() && !$c->get('aic_egress_restricted')) return null;
        require_once(PathHelper::getIncludePath('includes/PrivateContentConsent.php'));
        $consent = PrivateContentConsent::forUser($owner_id);
        $model = trim($model);
        try {
            $trust = AiEndpointRegistry::trustForModel($model);
        } catch (Throwable $e) {
            $trust = null;
        }
        if (PrivateContentConsent::allows($consent, $trust)) return null;
        $labels = LlmProviderFactory::allModels();
        $label = (string)($labels[$model] ?? ($model !== '' ? $model : 'the chosen model'));
        $class = $trust === null ? 'an endpoint this site has not classified' : 'a ' . $trust . ' endpoint';
        return 'This chat holds private content and ' . $label . ' runs on ' . $class
            . ', which would send that content off your hardware. Your setting keeps private content '
            . PrivateContentConsent::where($consent) . '. Pick a local model for this chat, or allow it under '
            . 'Security › Encrypted Vault › Private content and AI.';
    }

    /**
     * Change an existing conversation's level (specs/joinery_ai_chat_encryption.md
     * § Phase 6). The sequence and its security rules are ProtectionLevelChange's:
     * a recent second factor for an owner who has one, the prerequisites
     * (ChatConversationLevel::blockers()), lowering only with the owner's window
     * open, and the level flipped FIRST. One bounded converge pass runs here so a
     * short chat is done at once; a longer one reports `remaining`, and the page
     * drives chat_level_batch until nothing is left.
     *
     * Returns ['ok'=>bool, 'error'=>?string, 'requires_stepup'=>?bool,
     *          'level'=>string, 'remaining'=>int].
     */
    public static function changeLevel(AiConversation $c, string $target, int $uid): array {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/conversation_messages_class.php'));

        if (!in_array($target, ChatSeal::levels(), true)) {
            return ['ok' => false, 'error' => 'Invalid privacy level.'];
        }
        if ((int)$c->get('aic_owner_user_id') !== $uid) return ['ok' => false, 'error' => 'Not your chat.'];

        if ($c->level() === $target) {
            // No content transition. An unconverted legacy row is rewritten in the
            // current shape, and a local-only chat still gets its model pin.
            $pin_local = ChatSeal::isProtectedLevel($target) && $c->localModelsOnlyFlag();
            if ($pin_local && !self::localModelConfigured()) {
                return ['ok' => false, 'error' => 'This chat is set to Local models only — configure a local model in Joinery AI settings to make it Private again.'];
            }
            $cols = ((string)$c->get('aic_security_level') === AiConversation::LEGACY_LEVEL_LOCAL_ONLY)
                ? ['aic_security_level' => $target, 'aic_local_models_only' => true] : [];
            if ($pin_local && !self::isLocalModel((string)$c->get('aic_model'))) {
                $cols['aic_model'] = self::localDefaultModel();
            }
            if ($cols) AiConversation::updateColumns((int)$c->key, $cols);
            // A change that stopped part-way resumes here too.
            $scope = new ChatConversationLevel($c);
            $remaining = $scope->remaining();
            if ($remaining > 0) {
                $remaining = ProtectionLevelChange::convergeBatch($scope)['remaining'];
            }
            return ['ok' => true, 'level' => $target, 'remaining' => $remaining];
        }

        // A level change must not race an in-flight turn: the worker finalizes
        // with the level it captured at turn start, so a flip under it would leave
        // a plaintext turn on a sealed chat (or a sealed row on a Standard one).
        // Reap a stale runner first so a dead worker can't block the change forever.
        if (self::hasRunningTurn($c)) {
            return ['ok' => false, 'error' => 'Wait for the current reply to finish before changing this chat’s privacy.'];
        }

        $scope = new ChatConversationLevel($c);
        $change = ProtectionLevelChange::change($scope, $target, $uid);
        if ($change['status'] === ProtectionLevelChange::STEPUP) {
            return ['ok' => false, 'error' => $change['error'], 'requires_stepup' => true];
        }
        if ($change['status'] !== ProtectionLevelChange::OK) {
            return ['ok' => false, 'error' => $change['error']];
        }
        $pass = ProtectionLevelChange::convergeBatch($scope);
        return ['ok' => true, 'level' => $target, 'remaining' => $pass['remaining']];
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
}

