<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));

/**
 * The security-critical send sequence shared by BOTH chat_send surfaces — the
 * /api/v1 action (logic/chat_send_logic.php) and the web AJAX endpoint
 * (views/admin/chat_send.php) — and the unlock-first gate that surface
 * shares too.
 *
 * These steps decide the security level, hold protected content out of the first
 * insert, gate on the vault window, and seal-after-insert. They are identical
 * across surfaces and must stay that way: a fix applied to one copy but not the
 * other would silently write plaintext or skip the unlock gate on the other
 * surface. Each method does the work and returns plain data / mutates the model;
 * the caller owns its own response shape (LogicResult vs echo) and execution
 * dispatch (CLI worker vs fastcgi detach), which legitimately differ.
 */
class ChatSend {

    /**
     * Build a NEW (unsaved) conversation from composer input: owner, model,
     * seeded controls, the resolved security level (Fortress additionally pins
     * the model to a local one), and the held-out title/instructions — kept out
     * of the first insert so no plaintext content lands at rest on a protected
     * chat. Returns ['conversation','level','title','instructions']; the caller
     * persists it with persistNewConversation() after uploads validate.
     */
    public static function buildNewConversation(int $uid, array $input, string $message): array {
        $c = new AiConversation(NULL);
        $c->set('aic_owner_user_id', $uid);
        $c->set('aic_model', ChatRunner::defaultModel());
        ChatControls::seedNewConversation($c, $input);
        // Security level: the composer's choice or the plugin default, downgraded
        // when its prerequisites (vault / local model) are missing (Phase 1).
        $level = ChatLevel::resolveForNew($input['security_level'] ?? null, $uid);
        $c->set('aic_security_level', $level);
        // Fortress pins inference to a local model — start it on one.
        if ($level === AiConversation::LEVEL_FORTRESS
                && !ChatLevel::isLocalModel((string)$c->get('aic_model'))) {
            $c->set('aic_model', ChatLevel::localDefaultModel());
        }
        // Title (and any seeded instructions) are content: on a protected chat
        // hold them out of the first insert and seal them once the id exists, so
        // no plaintext title/instructions ever land at rest.
        $title = ChatTurn::deriveTitle($message !== '' ? $message : 'New chat');
        $instructions = (string)$c->get('aic_instructions');
        if (ChatSeal::isProtectedLevel($level)) {
            $c->set('aic_instructions', '');
        } else {
            $c->set('aic_title', $title);
        }
        return ['conversation' => $c, 'level' => $level, 'title' => $title, 'instructions' => $instructions];
    }

    /**
     * The unlock-first gate. A protected conversation seals with the public key
     * alone, but the turn must decrypt its history to build the model payload —
     * so starting/continuing one needs an open vault window. True => the caller
     * returns its own 'locked' response before persisting anything; the client
     * unlocks then resubmits.
     */
    public static function lockedForWrite(int $uid, bool $protected): bool {
        return $protected && VaultUnlock::secretKey($uid) === null;
    }

    /**
     * Persist a freshly built conversation, then seal its title/instructions
     * under a fresh DEK once the id (which the AD binds to) exists. A Standard
     * chat's title was already set in memory by buildNewConversation().
     */
    public static function persistNewConversation(AiConversation $c, bool $protected,
            string $title, string $instructions): void {
        $c->prepare();
        $c->save();
        $c->load();
        if ($protected) {
            AiConversation::updateColumns((int)$c->key,
                ChatSeal::sealConversationColumns((int)$c->key, $c, [
                    'aic_title'        => $title,
                    'aic_instructions' => $instructions,
                ]));
            $c->load();   // refresh so get('aic_title') decrypts in-window
        }
    }

    /**
     * Persist the user's message (complete on insert; content may be empty when
     * the turn is attachments-only). On a protected chat the row is inserted
     * empty and its content sealed under a per-message DEK afterward — the AD
     * needs the row id, and no plaintext prompt ever lands at rest. Returns the
     * loaded row (sealed columns decrypt in-window on read).
     */
    public static function persistUserMessage(AiConversation $c, bool $protected, string $message): AiConversationMessage {
        $m = new AiConversationMessage(NULL);
        $m->set('aim_aic_conversation_id', (int)$c->key);
        $m->set('aim_role', AiConversationMessage::ROLE_USER);
        $m->set('aim_content', $protected ? '' : $message);
        $m->prepare();
        $m->save();
        $m->load();
        if ($protected) {
            AiConversationMessage::updateColumns((int)$m->key,
                ChatSeal::userColumns($c, (int)$m->key, $message));
            $m->load();   // refresh so the serializer/renderer reads sealed→decrypt
        }
        return $m;
    }
}
