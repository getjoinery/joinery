<?php
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));   // VaultUnlock + VaultLockedException
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

/**
 * The Sealed Vault consumer crypto for AI chat (docs/sealed_vault.md,
 * specs/joinery_ai_chat_encryption.md). One place owns what chat seals, the AD
 * (additional-data) row-binding convention every sealer/opener must agree on
 * byte-for-byte, and the mint-vs-reuse DEK dance.
 *
 * The unit of protection is the CONVERSATION: its aic_security_level is 'private'
 * or 'fortress' (protected) or 'standard' (plaintext, unchanged). A protected
 * conversation seals its title/instructions under a per-conversation DEK
 * (aic_sealed_key) and each message seals content/tool-trace/error under a
 * per-message DEK (aim_sealed_key); attachments seal under the OWNING message's
 * DEK (no per-attachment key). Every DEK is sealed to the owner's vault public
 * key — so sealing needs only the public key (works while locked); only reading
 * (openField) needs the in-window secret.
 *
 * IMPORTANT persistence rule: a sealed row must NEVER be written through model
 * save() — SystemBase::save() rebuilds every column via get(), which decrypts the
 * sealed fields and would write plaintext back (unsealing them) or throw when
 * locked. The seal methods here therefore RETURN a column => value map that the
 * caller persists with the model's updateColumns() (a targeted raw UPDATE). All
 * sealed fields on one row share that row's single DEK, so a partial update
 * reuses the existing DEK (reseal*), while an initial full seal mints (seal*).
 */
class ChatSeal {

    const LEVEL_STANDARD = 'standard';
    const LEVEL_PRIVATE  = 'private';
    const LEVEL_FORTRESS = 'fortress';

    /** A sealed AEAD blob always carries this prefix (SealedBox::aeadEncrypt); the
     *  decrypt paths key on it so an empty/not-yet-sealed field is returned as-is. */
    const BLOB_PREFIX = 'v1.aead.';

    /** The placeholder title shown for a locked protected conversation. */
    const LOCKED_TITLE = 'Protected chat (locked)';

    public static function isProtectedLevel($level): bool {
        return $level === self::LEVEL_PRIVATE || $level === self::LEVEL_FORTRESS;
    }

    public static function levels(): array {
        return [self::LEVEL_STANDARD, self::LEVEL_PRIVATE, self::LEVEL_FORTRESS];
    }

    // ---------------------------------------------------------- AD conventions

    /** Message column AD: chat:{aim_message_id}:{column}. */
    public static function messageAd(int $message_id, string $column): string {
        return 'chat:' . $message_id . ':' . $column;
    }

    /** Conversation column AD: chat:conv:{aic_conversation_id}:title|instructions. */
    public static function conversationAd(int $conversation_id, string $token): string {
        return 'chat:conv:' . $conversation_id . ':' . $token;
    }

    /** Attachment bytes AD: chat:{aim_message_id}:att:{aia_attachment_id}. */
    public static function attachmentBytesAd(int $message_id, int $attachment_id): string {
        return 'chat:' . $message_id . ':att:' . $attachment_id;
    }

    /** Attachment extracted-text AD: chat:{aim_message_id}:att_text:{aia_attachment_id}. */
    public static function attachmentTextAd(int $message_id, int $attachment_id): string {
        return 'chat:' . $message_id . ':att_text:' . $attachment_id;
    }

    private static function conversationToken(string $column): string {
        return $column === 'aic_title' ? 'title' : 'instructions';
    }

    /** Whether a message column stores JSON (tool trace / pending action). */
    public static function isJsonColumn(string $column): bool {
        return $column === 'aim_tool_calls' || $column === 'aim_pending_action';
    }

    // ---------------------------------------------------------- vault resolution

    public static function vaultForOwner(int $owner_id): ?UserEncryptionVault {
        if ($owner_id <= 0) return null;
        return UserEncryptionVault::loadForUser($owner_id, UserEncryptionVault::SCOPE_USER);
    }

    public static function ownerHasVault(int $owner_id): bool {
        return self::vaultForOwner($owner_id) !== null;
    }

    /** Whether $owner_id currently holds an open vault window (secret in RAM).
     *  A pure probe — isOpen() has no side effects. secretKey() would extend the
     *  idle window and stamp a content-decrypt on every call, and this runs per
     *  row on every list render/poll, which would keep the vault open forever. */
    public static function windowOpenFor(int $owner_id): bool {
        return VaultUnlock::isOpen($owner_id);
    }

    /** Locked-state test: protected AND the owner's window is closed. */
    public static function isLocked(AiConversation $c): bool {
        return self::isProtectedLevel($c->get('aic_security_level'))
            && !self::windowOpenFor((int)$c->get('aic_owner_user_id'));
    }

    /**
     * Whether editing this conversation's sealed content (a rename of the title,
     * or an instructions edit) must prompt unlock first: it reseals under the
     * vault, which needs an open window. Same predicate for both the rename and
     * instructions surfaces so their locked-state gate can't diverge.
     */
    public static function lockedForContentEdit(AiConversation $c): bool {
        return $c->isProtected() && !self::windowOpenFor((int)$c->get('aic_owner_user_id'));
    }

    // ---------------------------------------------------------- message sealing

    /**
     * Column map sealing message content fields under a FRESH per-message DEK
     * (public key only — no window). $fields maps column => plaintext (null/''
     * pass through unsealed). The returned map also carries aim_sealed_key /
     * generation / owner / content_sealed and is persisted via
     * AiConversationMessage::updateColumns($message_id, $map).
     */
    public static function sealMessageColumns(int $message_id, AiConversation $conv, array $fields): array {
        $owner = (int)$conv->get('aic_owner_user_id');
        $vault = self::vaultForOwner($owner);
        if ($vault === null) {
            throw new RuntimeException('ChatSeal: owner ' . $owner . ' has no vault; a protected chat cannot be sealed.');
        }
        if ($message_id <= 0) {
            throw new RuntimeException('ChatSeal: message must be persisted (id assigned) before sealing.');
        }
        $crypto = new VaultCrypto();
        $dek = $crypto->newItemDek();
        $sealed_key = $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key'));
        $cols = array();
        foreach ($fields as $col => $plain) {
            $cols[$col] = ($plain === null || $plain === '') ? $plain
                : $crypto->sealField((string)$plain, $dek, self::messageAd($message_id, $col));
        }
        $cols['aim_sealed_key'] = $sealed_key;
        $cols['aim_key_generation'] = (int)$vault->get('uev_key_generation');
        $cols['aim_sealed_owner_user_id'] = $owner;
        $cols['aim_content_sealed'] = true;
        return $cols;
    }

    /**
     * The content columns for a finalized turn: json-encode the trace columns and
     * seal every content column when protected (else plaintext). Returned map is
     * merged with the caller's operational columns (status/tokens/activity) and
     * persisted via updateColumns.
     */
    public static function turnColumns(AiConversation $conv, int $message_id, string $content, $tool_calls, $pending): array {
        $tc = self::encodeJsonColumn($tool_calls);
        $pa = self::encodeJsonColumn($pending);
        if (self::isProtectedLevel($conv->get('aic_security_level'))) {
            return self::sealMessageColumns($message_id, $conv, [
                'aim_content' => $content, 'aim_tool_calls' => $tc, 'aim_pending_action' => $pa,
            ]);
        }
        return ['aim_content' => $content, 'aim_tool_calls' => $tc, 'aim_pending_action' => $pa];
    }

    /** The content column(s) for a user message. */
    public static function userColumns(AiConversation $conv, int $message_id, string $content): array {
        if (self::isProtectedLevel($conv->get('aic_security_level'))) {
            return self::sealMessageColumns($message_id, $conv, ['aim_content' => $content]);
        }
        return ['aim_content' => $content];
    }

    /**
     * The aim_error column for markFailed/sweep. Reuses the row's existing DEK when
     * it is already sealed (in-window); mints a fresh DEK for an unsealed
     * placeholder (public key only). markFailed strings are generic operational
     * text, so if sealing is impossible the error is stored plaintext rather than
     * lost — never content ciphertext.
     */
    public static function errorColumns(AiConversationMessage $msg, string $error): array {
        $conv = new AiConversation((int)$msg->get('aim_aic_conversation_id'), true);
        if (!$conv->key || !self::isProtectedLevel($conv->get('aic_security_level'))) {
            return ['aim_error' => $error];
        }
        try {
            if ($msg->get('aim_content_sealed') && (string)$msg->get('aim_sealed_key') !== '') {
                $owner  = (int)$conv->get('aic_owner_user_id');
                $secret = VaultUnlock::secretKey($owner);
                if ($secret === null) throw new VaultLockedException();
                $crypto = new VaultCrypto();
                $dek = $crypto->openItemDek((string)$msg->get('aim_sealed_key'), $secret);
                return ['aim_error' => $crypto->sealField($error, $dek, self::messageAd((int)$msg->key, 'aim_error'))];
            }
            return self::sealMessageColumns((int)$msg->key, $conv, ['aim_error' => $error]);
        } catch (Throwable $e) {
            return ['aim_error' => $error];
        }
    }

    /**
     * Re-seal one message content column under the row's EXISTING DEK (in window) —
     * for a single content rewrite in place (e.g. clearing a dangling pending
     * action). Standard rows return the plain value. Returns a column => value map.
     */
    public static function resealMessageColumn(AiConversationMessage $msg, AiConversation $conv, string $column, $plain): array {
        if (!self::isProtectedLevel($conv->get('aic_security_level'))) {
            return [$column => self::isJsonColumn($column) ? self::encodeJsonColumn($plain) : $plain];
        }
        $stored = self::isJsonColumn($column) ? self::encodeJsonColumn($plain) : $plain;
        if ($stored === null || $stored === '') return [$column => $stored];
        if (!$msg->get('aim_content_sealed') || (string)$msg->get('aim_sealed_key') === '') {
            return self::sealMessageColumns((int)$msg->key, $conv, [$column => $stored]);
        }
        $owner  = (int)$conv->get('aic_owner_user_id');
        $secret = VaultUnlock::secretKey($owner);
        if ($secret === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek((string)$msg->get('aim_sealed_key'), $secret);
        return [$column => $crypto->sealField((string)$stored, $dek, self::messageAd((int)$msg->key, $column))];
    }

    /** Open one sealed message column — behind AiConversationMessage::decryptSealedField(). */
    public static function openMessageField(int $message_id, int $owner_id, string $sealed_key,
            string $column, string $ciphertext): string {
        $secret = VaultUnlock::secretKey($owner_id);
        if ($secret === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek($sealed_key, $secret);
        return $crypto->openField($ciphertext, $dek, self::messageAd($message_id, $column));
    }

    // ------------------------------------------------------ conversation sealing

    /** Column map sealing a conversation's title/instructions under a FRESH DEK. */
    public static function sealConversationColumns(int $conversation_id, AiConversation $c, array $fields): array {
        $owner = (int)$c->get('aic_owner_user_id');
        $vault = self::vaultForOwner($owner);
        if ($vault === null) {
            throw new RuntimeException('ChatSeal: owner ' . $owner . ' has no vault; a protected chat cannot be sealed.');
        }
        if ($conversation_id <= 0) {
            throw new RuntimeException('ChatSeal: conversation must be persisted before sealing.');
        }
        $crypto = new VaultCrypto();
        $dek = $crypto->newItemDek();
        $sealed_key = $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key'));
        $cols = array();
        foreach ($fields as $col => $plain) {
            $cols[$col] = ($plain === null || $plain === '') ? $plain
                : $crypto->sealField((string)$plain, $dek, self::conversationAd($conversation_id, self::conversationToken($col)));
        }
        $cols['aic_sealed_key'] = $sealed_key;
        $cols['aic_key_generation'] = (int)$vault->get('uev_key_generation');
        $cols['aic_content_sealed'] = true;
        return $cols;
    }

    /** Re-seal ONE conversation column (title/instructions) under the existing DEK. */
    public static function resealConversationColumn(AiConversation $c, string $column, $plain): array {
        if (!self::isProtectedLevel($c->get('aic_security_level'))) {
            return [$column => $plain];
        }
        if ($plain === null || $plain === '') return [$column => $plain];
        if (!$c->get('aic_content_sealed') || (string)$c->get('aic_sealed_key') === '') {
            return self::sealConversationColumns((int)$c->key, $c, [$column => $plain]);
        }
        $owner  = (int)$c->get('aic_owner_user_id');
        $secret = VaultUnlock::secretKey($owner);
        if ($secret === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek((string)$c->get('aic_sealed_key'), $secret);
        return [$column => $crypto->sealField((string)$plain, $dek, self::conversationAd((int)$c->key, self::conversationToken($column)))];
    }

    /** Open a sealed conversation column — behind AiConversation::decryptSealedField(). */
    public static function openConversationField(int $conversation_id, int $owner_id, string $sealed_key,
            string $column, string $ciphertext): string {
        $secret = VaultUnlock::secretKey($owner_id);
        if ($secret === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek($sealed_key, $secret);
        return $crypto->openField($ciphertext, $dek, self::conversationAd($conversation_id, self::conversationToken($column)));
    }

    // -------------------------------------------------------- attachment sealing

    /** Open one attachment's per-message-DEK-sealed bytes. Behind the File hook. */
    public static function openAttachmentBytes(AiConversationMessage $msg, int $attachment_id, string $ciphertext): string {
        $owner = (int)$msg->get('aim_sealed_owner_user_id');
        $sealed_key = (string)$msg->get('aim_sealed_key');
        if ($owner <= 0 || $sealed_key === '') throw new VaultLockedException();
        $secret = VaultUnlock::secretKey($owner);
        if ($secret === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek($sealed_key, $secret);
        return $crypto->openField($ciphertext, $dek, self::attachmentBytesAd((int)$msg->key, $attachment_id));
    }

    /** Open a sealed attachment's extracted text — behind AiMessageAttachment::decryptSealedField(). */
    public static function openAttachmentText(AiMessageAttachment $att, string $ciphertext): string {
        $msg = new AiConversationMessage((int)$att->get('aia_aim_message_id'), true);
        if (!$msg->key) throw new VaultLockedException();
        $owner = (int)$msg->get('aim_sealed_owner_user_id');
        $sealed_key = (string)$msg->get('aim_sealed_key');
        if ($owner <= 0 || $sealed_key === '') throw new VaultLockedException();
        $secret = VaultUnlock::secretKey($owner);
        if ($secret === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek($sealed_key, $secret);
        return $crypto->openField($ciphertext, $dek, self::attachmentTextAd((int)$msg->key, (int)$att->key));
    }

    /**
     * Seal an attachment's extracted text and/or raw bytes under the OWNING
     * message's DEK (in window). Returns ['text'=>?string, 'bytes'=>?string] — a
     * null input stays null.
     */
    public static function sealAttachmentUnderMessage(AiConversationMessage $msg, int $attachment_id,
            ?string $text, ?string $bytes): array {
        $owner = (int)$msg->get('aim_sealed_owner_user_id');
        $sealed_key = (string)$msg->get('aim_sealed_key');
        if ($owner <= 0 || $sealed_key === '') {
            throw new RuntimeException('ChatSeal: owning message is not sealed; cannot seal its attachment.');
        }
        $secret = VaultUnlock::secretKey($owner);
        if ($secret === null) throw new VaultLockedException();
        $crypto = new VaultCrypto();
        $dek = $crypto->openItemDek($sealed_key, $secret);
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
     * them under a fresh DEK via one raw UPDATE, then seals each attachment's text
     * + bytes under that DEK. Idempotent — an already-sealed row is skipped.
     */
    public static function sealExistingMessage(AiConversationMessage $msg, AiConversation $conv): void {
        if ($msg->get('aim_content_sealed')) return;
        $content = (string)$msg->get('aim_content');
        $tool    = (string)$msg->get('aim_tool_calls');       // plain JSON text
        $pending = (string)$msg->get('aim_pending_action');
        $error   = (string)$msg->get('aim_error');
        $cols = self::sealMessageColumns((int)$msg->key, $conv, [
            'aim_content'        => $content,
            'aim_tool_calls'     => $tool !== '' ? $tool : null,
            'aim_pending_action' => $pending !== '' ? $pending : null,
            'aim_error'          => $error !== '' ? $error : null,
        ]);
        AiConversationMessage::updateColumns((int)$msg->key, $cols);
        // Reload so the sealed DEK is in hand for the attachments.
        $msg->load();
        self::sealExistingAttachments($msg);
    }

    /** Reverse: converge a sealed message back to plaintext (→Standard). */
    public static function unsealExistingMessage(AiConversationMessage $msg): void {
        if (!$msg->get('aim_content_sealed')) return;
        self::unsealExistingAttachments($msg);   // uses the still-sealed message DEK
        $content = (string)$msg->get('aim_content');   // get() decrypts in-window
        $tool    = (string)$msg->get('aim_tool_calls');
        $pending = (string)$msg->get('aim_pending_action');
        $error   = (string)$msg->get('aim_error');
        AiConversationMessage::updateColumns((int)$msg->key, [
            'aim_content'              => $content,
            'aim_tool_calls'           => $tool !== '' ? $tool : null,
            'aim_pending_action'       => $pending !== '' ? $pending : null,
            'aim_error'                => $error !== '' ? $error : null,
            'aim_content_sealed'       => false,
            'aim_sealed_key'           => null,
            'aim_sealed_owner_user_id' => null,
            'aim_key_generation'       => 0,
        ]);
    }

    public static function sealExistingAttachments(AiConversationMessage $msg): void {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $links = new MultiAiMessageAttachment(['message_id' => (int)$msg->key, 'deleted' => false], []);
        $links->load();
        foreach ($links as $link) {
            if ($link->get('aia_sealed')) continue;
            $text = (string)$link->get('aia_extracted_text');
            $file = new File((int)$link->get('aia_fil_file_id'), true);
            $bytes = $file->key ? $file->read_bytes('original') : null;
            $sealed = self::sealAttachmentUnderMessage($msg, (int)$link->key,
                $text !== '' ? $text : null, ($bytes === false ? null : $bytes));
            if ($file->key && $sealed['bytes'] !== null) {
                $path = $file->get_filesystem_path('original');
                if ($path && @file_put_contents($path, $sealed['bytes']) !== false) {
                    $file->set('fil_type', substr((string)$file->get('fil_type'), 0, 128));
                    $file->save();
                }
            }
            AiMessageAttachment::updateColumns((int)$link->key, [
                'aia_extracted_text' => $sealed['text'],
                'aia_sealed'         => true,
            ]);
        }
    }

    public static function unsealExistingAttachments(AiConversationMessage $msg): void {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $links = new MultiAiMessageAttachment(['message_id' => (int)$msg->key, 'deleted' => false], []);
        $links->load();
        foreach ($links as $link) {
            if (!$link->get('aia_sealed')) continue;
            $plain_text = (string)$link->get('aia_extracted_text');   // get() decrypts in-window
            $file = new File((int)$link->get('aia_fil_file_id'), true);
            if ($file->key) {
                $path = $file->get_filesystem_path('original');
                $cipher = ($path && is_file($path)) ? @file_get_contents($path) : false;
                if ($cipher !== false && $cipher !== '') {
                    try {
                        $plain_bytes = self::openAttachmentBytes($msg, (int)$link->key, $cipher);
                        @file_put_contents($path, $plain_bytes);
                    } catch (Throwable $e) { /* leave as-is on failure */ }
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
