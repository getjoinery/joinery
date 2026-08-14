<?php
/**
 * MessengerAttachmentGate — who may open a photo or file sent in a conversation.
 *
 * An attachment is exactly as private as the conversation it was sent in: the
 * people in that conversation can open it, nobody else can. Rather than a
 * download endpoint of its own, an attachment File carries the platform's
 * content access gate (fil_access_provider / fil_access_ref pointing at the
 * conversation), and this provider answers the question core asks on every
 * serve. That buys the ordinary /uploads/ URL, thumbnails and range requests
 * without a second serving path to keep honest.
 *
 * Registered from the plugin's serve.php, so it is live on every request while
 * the plugin is active. Deactivating the plugin unregisters it, and the gate
 * fails closed — an attachment stops being readable rather than becoming public.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));

class MessengerAttachmentGate implements AccessGateProvider {

	/** The value stored in fil_access_provider. */
	const KEY = 'messenger_conversation';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return 'Messenger conversation';
	}

	/**
	 * No admin picker: conversations are not content an administrator gates a
	 * file behind by hand, and listing every member's private threads in a
	 * dropdown would be its own disclosure.
	 */
	public function options(): array {
		return array();
	}

	public function userMayAccess(int $user_id, int $ref): bool {
		if ($ref <= 0 || $user_id <= 0) {
			return false;
		}
		require_once(PathHelper::getIncludePath('data/conversations_class.php'));
		try {
			$conversation = new Conversation($ref, TRUE);
		} catch (Exception $e) {
			return false;
		}
		if (!$conversation->key || $conversation->get('cnv_delete_time')) {
			return false;
		}
		return $conversation->has_participant($user_id);
	}
}
