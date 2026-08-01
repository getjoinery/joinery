<?php
/**
 * joinery_ai plugin bootstrap — loaded once per request by
 * VaultUnlock::loadConsumerBootstraps() whenever the plugin is active. Wires AI
 * chat into the Sealed Vault's generic consumer hooks (docs/sealed_vault.md
 * § The consumer contract), mirroring the mailbox bootstrap: the File decrypt
 * hook for sealed chat-upload bytes, and the rotation re-seal / window-wipe
 * callbacks.
 *
 * The sealed-field model hooks ($sealed_fields on AiConversation /
 * AiConversationMessage / AiMessageAttachment) need no registration here — they
 * are declared on the classes, which are required wherever a row is read.
 *
 * It also registers the AI pipeline as a deferred-work consumer
 * (specs/in_window_deferred_work.md): recipes whose job reads sealed mail run
 * in the owner's unlock window rather than on a schedule.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));

// --- Deferred work consumer (specs/in_window_deferred_work.md) ---
// A pipeline job that reads sealed content cannot run from cron, because a
// command-line worker never holds an unlock window. Those recipes run here
// instead: in slices, inside the owner's own request, while their vault is open.
// Registered AFTER mailbox (VaultUnlock::CONSUMER_PLUGINS order) because the
// email jobs skip unparsed mail — parsing has to lead.
require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
VaultDeferredWork::register(
    'ai_pipeline',
    function (int $user_id): bool {
        return RecipeVaultScope::hasWork($user_id);
    },
    function (int $user_id, string $secret_key, float $deadline): int {
        return RecipeVaultScope::drain($user_id, $secret_key, $deadline);
    }
);

// --- Sealed-File decrypt hook (docs/sealed_vault.md § The two generic consumer hooks) ---
// A chat upload on a protected conversation stores ciphertext bytes on disk; the
// bytes are sealed under the OWNING message's DEK. Resolve the attachment link
// (and its message) from the file id, then open in-window. A plaintext file (a
// Standard-chat upload, aia_sealed = false) streams as-is.
File::registerDecryptHook(File::SOURCE_AI_CHAT_UPLOAD, function (string $ciphertext, File $file): string {
    $links = new MultiAiMessageAttachment(array('file_id' => intval($file->key), 'deleted' => false), array());
    $links->load();
    if ($links->count() === 0) {
        throw new VaultLockedException(); // no link row — nothing this hook can resolve
    }
    $link = $links->get(0);
    if (!$link->get('aia_sealed')) {
        return $ciphertext; // stored plaintext
    }
    $msg = new AiConversationMessage(intval($link->get('aia_aim_message_id')), TRUE);
    if (!$msg->key) {
        throw new VaultLockedException();
    }
    return ChatSeal::openAttachmentBytes($msg, intval($link->key), $ciphertext);
});

// --- Rotation re-seal callback (docs/sealed_vault.md § Key rotation) ---
// Re-seal each protected conversation's DEK (aic_sealed_key — title/instructions)
// and each protected message's DEK (aim_sealed_key — content/trace/error) that is
// sealed to the generation being drained, to the new keypair. Attachments seal
// under the message DEK (the DEK bytes are unchanged — only their sealing to the
// vault key moves), so re-sealing the message DEK covers them; no attachment row
// is touched. Fail-loud per the onReseal() contract: attempt every item, then
// throw if any failed so the ceremony refuses to retire the old wrappings while
// content is still sealed to them.
VaultUnlock::onReseal(function (int $user_id, string $old_secret_key, int $old_key_generation, string $new_public_key, int $new_key_generation) {
    $db = DbConnector::get_instance()->get_db_link();
    $crypto = new VaultCrypto();
    $failed = 0;
    $attempted = 0;

    // Conversation DEKs.
    $cs = $db->prepare(
        'SELECT aic_conversation_id, aic_sealed_key FROM aic_conversations
         WHERE aic_owner_user_id = ? AND aic_content_sealed = true
         AND aic_key_generation = ? AND aic_delete_time IS NULL');
    $cs->execute(array($user_id, $old_key_generation));
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string)$row['aic_sealed_key'] === '') { continue; }
        $attempted++;
        try {
            $dek = $crypto->openItemDek((string)$row['aic_sealed_key'], $old_secret_key);
            $resealed = $crypto->sealItemDek($dek, $new_public_key);
            $u = $db->prepare(
                'UPDATE aic_conversations SET aic_sealed_key = ?, aic_key_generation = ?
                 WHERE aic_conversation_id = ?');
            $u->execute(array($resealed, $new_key_generation, intval($row['aic_conversation_id'])));
        } catch (Throwable $e) {
            $failed++;
            error_log('Chat vault reseal: failed for conversation ' . $row['aic_conversation_id'] . ': ' . $e->getMessage());
        }
    }

    // Message DEKs — self-contained via aim_sealed_owner_user_id (the conversation owner).
    $ms = $db->prepare(
        'SELECT aim_message_id, aim_sealed_key FROM aim_conversation_messages
         WHERE aim_sealed_owner_user_id = ? AND aim_content_sealed = true
         AND aim_key_generation = ? AND aim_delete_time IS NULL');
    $ms->execute(array($user_id, $old_key_generation));
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string)$row['aim_sealed_key'] === '') { continue; }
        $attempted++;
        try {
            $dek = $crypto->openItemDek((string)$row['aim_sealed_key'], $old_secret_key);
            $resealed = $crypto->sealItemDek($dek, $new_public_key);
            $u = $db->prepare(
                'UPDATE aim_conversation_messages SET aim_sealed_key = ?, aim_key_generation = ?
                 WHERE aim_message_id = ?');
            $u->execute(array($resealed, $new_key_generation, intval($row['aim_message_id'])));
        } catch (Throwable $e) {
            $failed++;
            error_log('Chat vault reseal: failed for message ' . $row['aim_message_id'] . ': ' . $e->getMessage());
        }
    }

    if ($failed > 0) {
        throw new RuntimeException(
            'Chat reseal: ' . $failed . ' of ' . $attempted . ' sealed chat DEKs could not be '
            . 're-sealed; the old key generation must not be retired.');
    }
});

// --- Window-wipe callback (docs/sealed_vault.md § consumer contract) ---
// The only disposable in-window plaintext chat keeps is the streaming scratch for
// a turn in flight (RAM/tmpfs, keyed by message id). On a window close, purge the
// scratch of any of this user's still-running protected turns so no partial
// plaintext lingers past the lock. Finalized turns already cleared their own.
VaultUnlock::onWipe(function (int $user_id, ?string $scope) {
    if ($scope !== null && $scope !== UserEncryptionVault::SCOPE_USER) {
        return;
    }
    $db = DbConnector::get_instance()->get_db_link();
    $q = $db->prepare(
        "SELECT m.aim_message_id FROM aim_conversation_messages m
         JOIN aic_conversations c ON c.aic_conversation_id = m.aim_aic_conversation_id
         WHERE c.aic_owner_user_id = ? AND m.aim_status = 'running'
         AND c.aic_security_level IN ('private','fortress') AND m.aim_delete_time IS NULL");
    $q->execute(array($user_id));
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        ChatAsync::clearScratch(intval($mid));
    }
});
?>
