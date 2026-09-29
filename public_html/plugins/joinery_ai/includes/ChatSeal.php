<?php
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));   // VaultUnlock + VaultLockedException
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/conversation_messages_class.php'));

/**
 * The Sealed Vault consumer policy for AI chat (docs/sealed_vault.md,
 * specs/joinery_ai_chat_encryption.md).
 *
 * The unit of protection is the CONVERSATION: its aic_security_level is 'private'
 * (protected) or 'standard' (plaintext, unchanged). A protected conversation
 * seals its title/instructions under a per-conversation DEK (aic_sealed_key) and
 * each message seals content/tool-trace/error under a per-message DEK
 * (aim_sealed_key). Both models ride SystemBase's generic sealing: save() seals
 * what it writes (shouldSeal() reads the level), get() opens it in-window, and
 * the models' sealAd() overrides keep the AD literals every stored row carries.
 * Every DEK is sealed to the owner's vault public key, so a new row seals with
 * the window closed; rewriting a sealed column under an existing DEK needs the
 * window.
 *
 * What stays here is what the generic path cannot express: the level
 * predicates, the finalize and failure writes a worker makes after its owner
 * may have locked, attachments sealed under the OWNING message's DEK (no key
 * of their own), and the Standard<->Private backfill.
 */
class ChatSeal {

    const LEVEL_STANDARD = 'standard';
    const LEVEL_PRIVATE  = 'private';

    /** The placeholder title shown for a locked protected conversation. */
    const LOCKED_TITLE = 'Protected chat (locked)';

    public static function isProtectedLevel($level): bool {
        // Normalized, so an unconverted legacy row still reads as protected.
        return AiConversation::normalizeLevel($level) === self::LEVEL_PRIVATE;
    }

    public static function levels(): array {
        return [self::LEVEL_STANDARD, self::LEVEL_PRIVATE];
    }

    /**
     * Should content written at $level for $owner_id seal? The models'
     * shouldSeal() hooks ask this. A Private chat whose owner has no vault is
     * refused outright rather than stored in the clear.
     */
    public static function sealsForOwner(string $level, int $owner_id): bool {
        if (!self::isProtectedLevel($level)) return false;
        if (!self::ownerHasVault($owner_id)) {
            throw new RuntimeException('ChatSeal: owner ' . $owner_id . ' has no vault; a protected chat cannot be sealed.');
        }
        return true;
    }

    // ---------------------------------------------------------- AD conventions

    /** Message column AD: chat:{aim_conversation_message_id}:{column}. */
    public static function messageAd(int $message_id, string $column): string {
        return AiConversationMessage::sealAd($message_id, $column);
    }

    /** Conversation column AD: chat:conv:{aic_conversation_id}:title|instructions. */
    public static function conversationAd(int $conversation_id, string $token): string {
        return AiConversation::sealAd($conversation_id, 'aic_' . $token);
    }

    /** Attachment bytes AD: chat:{aim_conversation_message_id}:att:{aia_message_attachment_id}. */
    public static function attachmentBytesAd(int $message_id, int $attachment_id): string {
        return 'chat:' . $message_id . ':att:' . $attachment_id;
    }

    /** Attachment extracted-text AD: chat:{aim_conversation_message_id}:att_text:{aia_message_attachment_id}. */
    public static function attachmentTextAd(int $message_id, int $attachment_id): string {
        return 'chat:' . $message_id . ':att_text:' . $attachment_id;
    }

    // ---------------------------------------------------------- vault resolution

    public static function vaultForOwner(int $owner_id): ?UserEncryptionVault {
        if ($owner_id <= 0) return null;
        return UserEncryptionVault::loadForUser($owner_id, UserEncryptionVault::SCOPE_USER);
    }

    public static function ownerHasVault(int $owner_id): bool {
        return self::vaultForOwner($owner_id) !== null;
    }

    /** The conversation owner's vault, for a direct seal; refuses when there is none. */
    public static function requireVault(AiConversation $c): UserEncryptionVault {
        $owner = (int)$c->get('aic_owner_user_id');
        $vault = self::vaultForOwner($owner);
        if ($vault === null) {
            throw new RuntimeException('ChatSeal: owner ' . $owner . ' has no vault; a protected chat cannot be sealed.');
        }
        return $vault;
    }

    /** Whether $owner_id currently holds an open vault window (secret in RAM).
     *  A pure probe — isOpen() has no side effects. secretKey() would extend the
     *  idle window and stamp a content-decrypt on every call, and this runs per
     *  row on every list render/poll, which would keep the vault open forever. */
    public static function windowOpenFor(int $owner_id): bool {
        return VaultUnlock::isOpen($owner_id);
    }

    /**
     * Locked-state test: the owner's window is closed AND the chat is protected
     * or still holds sealed rows (lowered, not yet converged).
     */
    public static function isLocked(AiConversation $c): bool {
        if (self::windowOpenFor((int)$c->get('aic_owner_user_id'))) return false;
        return self::isProtectedLevel($c->get('aic_security_level')) || self::holdsSealedContent($c);
    }

    /**
     * Does this chat still hold sealed rows? True on a protected chat, and on
     * one lowered to Standard whose rows have not all converged back yet.
     */
    public static function holdsSealedContent(AiConversation $c): bool {
        if ($c->rowIsSealed()) return true;
        $owner = (int)$c->get('aic_owner_user_id');
        // One probe per owner per request: an owner none of whose chats holds a
        // sealed row (everyone without a vault) never pays the per-chat query,
        // which the chat list would otherwise run for every row it renders.
        static $owner_holds = array();
        if (!array_key_exists($owner, $owner_holds)) {
            $stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT EXISTS (SELECT 1 FROM aic_conversations
                WHERE aic_owner_user_id = ? AND aic_content_sealed = true) OR EXISTS (SELECT 1 FROM aim_conversation_messages m
                JOIN aic_conversations c ON c.aic_conversation_id = m.aim_aic_conversation_id
                WHERE c.aic_owner_user_id = ? AND m.aim_delete_time IS NULL AND m.aim_content_sealed = true)');
            $stmt->execute(array($owner, $owner));
            $owner_holds[$owner] = in_array($stmt->fetchColumn(), array(true, 't', 1, '1'), true);
        }
        if (!$owner_holds[$owner]) return false;
        $stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT EXISTS (SELECT 1 FROM aim_conversation_messages
            WHERE aim_aic_conversation_id = ? AND aim_delete_time IS NULL AND aim_content_sealed = true)');
        $stmt->execute(array((int)$c->key));
        return in_array($stmt->fetchColumn(), array(true, 't', 1, '1'), true);
    }

    /**
     * Whether editing this conversation's sealed content (a rename of the title,
     * or an instructions edit) must prompt unlock first: it reseals under the
     * vault, which needs an open window. Same predicate for both the rename and
     * instructions surfaces so their locked-state gate can't diverge.
     */
    public static function lockedForContentEdit(AiConversation $c): bool {
        if (self::windowOpenFor((int)$c->get('aic_owner_user_id'))) return false;
        return $c->isProtected() || self::holdsSealedContent($c);
    }

    // ------------------------------------------------------- worker-side writes

    /**
     * Write a finalized turn: its content and trace plus the operational columns
     * in $plain (status, tokens, activity).
     *
     * On a Private chat the content is a direct full-row seal under a FRESH DEK,
     * never save(). The turn finalizes in a worker whose owner may have locked
     * since it started; minting needs only the public key, where save() on a
     * row sealed before (a resumed turn) would have to unwrap its existing DEK.
     * Every sealed column is written, so nothing older is left under a key the
     * row no longer records.
     */
    public static function finalizeTurn(AiConversation $conv, int $message_id, string $content,
            $tool_calls, array $plain): void {
        $tc = self::encodeJsonColumn($tool_calls);
        if (!self::isProtectedLevel($conv->get('aic_security_level'))) {
            AiConversationMessage::updateColumns($message_id,
                ['aim_content' => $content, 'aim_tool_calls' => $tc] + $plain);
            return;
        }
        $vault = self::requireVault($conv);
        $db = DbConnector::get_instance()->get_db_link();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            AiConversationMessage::sealColumns($message_id, $vault,
                ['aim_content' => $content, 'aim_tool_calls' => $tc, 'aim_error' => null]);
            AiConversationMessage::updateColumns($message_id, $plain);
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a turn failed with $error, plus the operational columns in $plain.
     *
     * On a Private chat the error is content (it can echo provider detail) and
     * seals like any other column, through save() on a fresh instance. An
     * unsealed placeholder takes its first DEK from the public key, so that works
     * with the window closed. A row that is already sealed can only take a new
     * sealed value under its existing DEK, which needs the window: with the
     * window closed the error text is dropped and aim_status alone records the
     * failure. Plaintext never goes into a sealed column.
     */
    public static function writeFailure(AiConversationMessage $msg, string $error, array $plain): void {
        $conv = new AiConversation((int)$msg->get('aim_aic_conversation_id'), true);
        if (!$conv->key || !self::isProtectedLevel($conv->get('aic_security_level'))) {
            AiConversationMessage::updateColumns((int)$msg->key, ['aim_error' => $error] + $plain);
            return;
        }
        try {
            $fresh = new AiConversationMessage((int)$msg->key, true);
            foreach ($plain as $col => $value) $fresh->set($col, $value);
            if (!$fresh->rowIsSealed() || self::windowOpenFor((int)$conv->get('aic_owner_user_id'))) {
                $fresh->set('aim_error', $error);
            }
            $fresh->save();
        } catch (Throwable $e) {
            // The failure itself must still land (a turn left RUNNING would spin
            // until the sweeper reaps it); only the error text is lost.
            error_log('ChatSeal: could not seal the failure on message ' . (int)$msg->key . ': ' . $e->getMessage());
            AiConversationMessage::updateColumns((int)$msg->key, $plain);
        }
    }

    /**
     * Write a conversation's title or instructions through save() on a fresh
     * instance: a Private chat reseals under its existing DEK (callers gate on
     * lockedForContentEdit() first, since that needs the window) and a Standard
     * one stores plaintext. Fresh, so the save carries nothing stale beside the
     * one column.
     */
    public static function setConversationContent(AiConversation $c, string $column, $value): void {
        // A chat lowered to Standard whose own row is still sealed opens it back
        // first; save() at Standard leaves a sealed column alone, and the edit
        // would vanish.
        $scope = new ChatConversationLevel($c);
        if (!$c->isProtected() && in_array(ChatConversationLevel::CONVERSATION_ITEM, $scope->pending(1), true)) {
            $scope->convertOne(ChatConversationLevel::CONVERSATION_ITEM);
        }
        $fresh = new AiConversation((int)$c->key, true);
        $fresh->set($column, $value);
        $fresh->save();
    }

    // -------------------------------------------------------- attachment sealing

    /** Open one attachment's per-message-DEK-sealed bytes. Behind the File hook. */
    public static function openAttachmentBytes(AiConversationMessage $msg, int $attachment_id, string $ciphertext): string {
        $owner = (int)$msg->get('aim_sealed_owner_user_id');
        $sealed_key = (string)$msg->get('aim_sealed_key');
        if ($owner <= 0 || $sealed_key === '') throw new VaultLockedException();
        $key = VaultUnlock::secretKey($owner);
        if ($key === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek($sealed_key, $key);
        return $crypto->openField($ciphertext, $dek, self::attachmentBytesAd((int)$msg->key, $attachment_id));
    }

    /** Open a sealed attachment's extracted text — behind AiMessageAttachment::decryptSealedField(). */
    public static function openAttachmentText(AiMessageAttachment $att, string $ciphertext): string {
        $msg = new AiConversationMessage((int)$att->get('aia_aim_conversation_message_id'), true);
        if (!$msg->key) throw new VaultLockedException();
        $owner = (int)$msg->get('aim_sealed_owner_user_id');
        $sealed_key = (string)$msg->get('aim_sealed_key');
        if ($owner <= 0 || $sealed_key === '') throw new VaultLockedException();
        $key = VaultUnlock::secretKey($owner);
        if ($key === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek($sealed_key, $key);
        return $crypto->openField($ciphertext, $dek, self::attachmentTextAd((int)$msg->key, (int)$att->key));
    }

    /**
     * Seal an attachment's extracted text and/or raw bytes under the OWNING
     * message's DEK: $dek when the caller just minted it, else unwrapped in
     * window. Returns ['text'=>?string, 'bytes'=>?string] — a
     * null input stays null.
     */
    public static function sealAttachmentUnderMessage(AiConversationMessage $msg, int $attachment_id,
            ?string $text, ?string $bytes, ?string $dek = null): array {
        $crypto = new VaultCrypto();
        if ($dek === null) {
            $owner = (int)$msg->get('aim_sealed_owner_user_id');
            $sealed_key = (string)$msg->get('aim_sealed_key');
            if ($owner <= 0 || $sealed_key === '') {
                throw new RuntimeException('ChatSeal: owning message is not sealed; cannot seal its attachment.');
            }
            $key = VaultUnlock::secretKey($owner);
            if ($key === null) throw new VaultLockedException();
            $dek = $crypto->openItemDek($sealed_key, $key);
        }
        return [
            'text'  => ($text  === null || $text  === '') ? $text
                       : $crypto->sealField($text, $dek, self::attachmentTextAd((int)$msg->key, $attachment_id)),
            'bytes' => ($bytes === null || $bytes === '') ? $bytes
                       : $crypto->sealField($bytes, $dek, self::attachmentBytesAd((int)$msg->key, $attachment_id)),
        ];
    }

    // ------------------------------------------------------ level backfill (Phase 6)

    /**
     * Converge one message to sealed form (Standard→protected backfill). Reads its
     * plaintext columns (Standard = plaintext at rest, no window needed), seals
     * the whole row under a fresh DEK, then seals each attachment's text + bytes
     * under that same DEK — all from the public key, so no window is needed.
     * Idempotent: a row sealed on an earlier pass whose attachments did not all
     * follow has those finished under its DEK (unwrapped in window).
     */
    public static function sealExistingMessage(AiConversationMessage $msg, AiConversation $conv): void {
        // Several drivers can reach one message at once (the page's loop, the
        // vault's deferred work, a second tab). The first seal holds the row and
        // re-reads it, so a second driver waits and then finds it sealed: two
        // first seals would each mint a DEK, and whatever was sealed under the
        // first would be left under a key the row no longer records. Only the row
        // write is inside the lock; attachment bytes (not transactional) follow
        // under the DEK the row now records, so a failure there leaves nothing
        // under a key the row does not know.
        $dek = null;
        $db = DbConnector::get_instance()->get_db_link();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT aim_content_sealed FROM aim_conversation_messages
                WHERE aim_conversation_message_id = ? FOR UPDATE');
            $lock->execute(array((int)$msg->key));
            $msg->load();
            if (!$msg->get('aim_content_sealed')) {
                $content = (string)$msg->get('aim_content');
                $tool    = (string)$msg->get('aim_tool_calls');       // plain JSON text
                $error   = (string)$msg->get('aim_error');
                $dek = AiConversationMessage::sealColumns((int)$msg->key, self::requireVault($conv), [
                    'aim_content'        => $content,
                    'aim_tool_calls'     => $tool !== '' ? $tool : null,
                    'aim_error'          => $error !== '' ? $error : null,
                ]);
            }
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $msg->load();
        // A row sealed by this call hands its DEK on; one sealed earlier (or by
        // another driver just now) has it unwrapped in window.
        self::sealExistingAttachments($msg, $dek);
    }

    /** Reverse: converge a sealed message back to plaintext (→Standard). */
    public static function unsealExistingMessage(AiConversationMessage $msg): void {
        if (!$msg->get('aim_content_sealed')) return;
        self::unsealExistingAttachments($msg);   // uses the still-sealed message DEK
        $content = (string)$msg->get('aim_content');   // get() decrypts in-window
        $tool    = (string)$msg->get('aim_tool_calls');
        $error   = (string)$msg->get('aim_error');
        AiConversationMessage::updateColumns((int)$msg->key, [
            'aim_content'              => $content,
            'aim_tool_calls'           => $tool !== '' ? $tool : null,
            'aim_error'                => $error !== '' ? $error : null,
            'aim_content_sealed'       => false,
            'aim_sealed_key'           => null,
            'aim_sealed_owner_user_id' => null,
            'aim_key_generation'       => 0,
        ]);
    }

    public static function sealExistingAttachments(AiConversationMessage $msg, ?string $dek = null): void {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/message_attachments_class.php'));
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $links = new MultiAiMessageAttachment(['message_id' => (int)$msg->key, 'deleted' => false], []);
        $links->load();
        foreach ($links as $link) {
            if ($link->get('aia_sealed')) continue;
            $text = (string)$link->get('aia_extracted_text');
            $file = new File((int)$link->get('aia_fil_file_id'), true);
            $bytes = null;
            if ($file->key) {
                $bytes = $file->read_bytes('original');
                // A read that fails (a cloud error, a missing blob) is not "nothing
                // to seal": the link stays unsealed and the message in the backlog.
                if (!is_string($bytes)) {
                    throw new RuntimeException('ChatSeal: attachment ' . (int)$link->key . ' could not be read to seal.');
                }
                // Bytes a pass already sealed (it stopped before the link) are not
                // sealed a second time.
                if (strpos($bytes, 'v1.aead.') === 0) {
                    $bytes = null;
                }
            }
            $sealed = self::sealAttachmentUnderMessage($msg, (int)$link->key,
                $text !== '' ? $text : null, $bytes, $dek);
            if ($file->key && $sealed['bytes'] !== null) {
                // replace_bytes() splits a dedup-shared blob before rewriting, so a
                // sibling file that deduped onto the same original is never sealed over.
                if (!$file->replace_bytes($sealed['bytes'])) {
                    throw new RuntimeException('ChatSeal: attachment ' . (int)$link->key . ' could not be sealed.');
                }
                $file->set('fil_type', substr((string)$file->get('fil_type'), 0, 128));
                $file->save();
            }
            AiMessageAttachment::updateColumns((int)$link->key, [
                'aia_extracted_text' => $sealed['text'],
                'aia_sealed'         => true,
            ]);
        }
    }

    public static function unsealExistingAttachments(AiConversationMessage $msg): void {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/message_attachments_class.php'));
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $links = new MultiAiMessageAttachment(['message_id' => (int)$msg->key, 'deleted' => false], []);
        $links->load();
        foreach ($links as $link) {
            if (!$link->get('aia_sealed')) continue;
            $plain_text = (string)$link->get('aia_extracted_text');   // get() decrypts in-window
            $file = new File((int)$link->get('aia_fil_file_id'), true);
            if ($file->key) {
                $cipher = $file->read_bytes('original');
                // Bytes still sealed are opened and written back plain; bytes already
                // plain (a pass that wrote them and stopped before the link) are done.
                // A read that fails, or any other failure, throws with the link still
                // marked sealed: the message keeps its DEK and stays in the backlog,
                // rather than claiming plaintext it does not hold.
                if (!is_string($cipher)) {
                    throw new RuntimeException('ChatSeal: attachment ' . (int)$link->key . ' could not be read to open.');
                }
                if (strpos($cipher, 'v1.aead.') === 0) {
                    $plain_bytes = self::openAttachmentBytes($msg, (int)$link->key, $cipher);
                    // replace_bytes() writes through the blob (cloud-aware) and
                    // splits a shared blob first, mirroring the seal path.
                    if (!$file->replace_bytes($plain_bytes)) {
                        throw new RuntimeException('ChatSeal: attachment ' . (int)$link->key . ' could not be written back.');
                    }
                }
            }
            AiMessageAttachment::updateColumns((int)$link->key, [
                'aia_extracted_text' => $plain_text,
                'aia_sealed'         => false,
            ]);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** array → JSON string; '' → null; a string is kept as-is (already JSON). */
    private static function encodeJsonColumn($value): ?string {
        if ($value === null) return null;
        if (is_string($value)) return $value === '' ? null : $value;
        if (is_array($value)) return empty($value) ? null : json_encode($value);
        return null;
    }
}
