<?php
/**
 * Messenger plugin bootstrap — the plugin's declared load point (the top-level
 * `bootstrap` key in plugin.json), loaded once per request by PluginBootstraps
 * whenever the plugin is active.
 *
 * It wires protected conversations into the Sealed Vault's two generic consumer
 * hooks (docs/sealed_vault.md § The consumer contract): the File decrypt hook
 * for sealed attachment bytes, and the rotation re-seal callback.
 *
 * WHAT THIS CONSUMER COVERS. `Message` is a core model — conversations are core
 * because several consumers share their rows — but its sealed body is this
 * plugin's to protect: nothing else on the platform ever seals a message. So
 * the reseal obligation is declared here, and a deployment that never activated
 * the messenger has no sealed message to lose. The sealed-field model hook
 * itself needs no registration: Message declares $sealed_fields and its own
 * decrypt path on the class, which loads wherever a message is read.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/ConversationSealing.php'));
require_once(PathHelper::getIncludePath('data/conversation_key_grants_class.php'));

// --- Streaming decrypt hook for sealed attachments -------------------------
// A photo sent in a Private or Guarded conversation is a SealedFileContainer on
// disk. File::serve_from_path() asks this opener for the plaintext size and
// then for the span the client wanted; a closed unlock window becomes a 423,
// never ciphertext with a 200 on it. A Standard conversation's attachment is
// plaintext and streams unchanged.
File::registerStreamingDecryptHook(File::SOURCE_MESSENGER_ATTACHMENT, function (File $file, $size_key = null) {
	return $file->is_sealed() ? new ConversationAttachmentStream($file) : null;
});

// --- Rotation re-seal callback (docs/sealed_vault.md § Key rotation) --------
// A conversation key never changes when a member rotates their vault — only the
// envelope around it does. So this re-wraps exactly the grants sitting on the
// generation being drained and rewrites no message and no attachment. Every
// grant is attempted and any failure throws, so the ceremony cannot retire the
// old wrappings while a conversation still depends on them.
VaultUnlock::onReseal(function (int $user_id, string $old_secret_key, int $old_key_generation,
		string $new_public_key, int $new_key_generation) {
	$result = ConversationKeyGrant::resealForUser(
		$user_id, $old_secret_key, $old_key_generation, $new_public_key, $new_key_generation);

	if ($result['failed'] > 0) {
		throw new RuntimeException(
			'Messenger vault reseal: ' . $result['failed'] . ' of ' . $result['attempted']
			. ' conversation key grants could not be re-wrapped; the old key generation '
			. 'must not be retired.');
	}
});
