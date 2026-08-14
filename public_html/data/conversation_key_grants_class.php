<?php
/**
 * ConversationKeyGrant and MultiConversationKeyGrant classes
 *
 * Who can open a sealed conversation.
 *
 * A Private or Guarded conversation has one key of its own, and that key is
 * wrapped separately to every participant's vault public key — one row here per
 * (conversation, member). The server reads a message only while at least one
 * participant is present with an open unlock window, because only a present
 * member's secret can unwrap their grant back into the conversation key.
 *
 * The shape is Drive's per-file key grants generalized from an owner to a room:
 * adding a member wraps the key to them, removing a member deletes their row,
 * and a vault key rotation re-wraps their rows to the new public key.
 *
 * @version 1.1
 */


class ConversationKeyGrantException extends SystemBaseException {}

class ConversationKeyGrant extends SystemBase {
	public static $prefix = 'ckg';
	public static $tablename = 'ckg_conversation_key_grants';
	public static $pkey_column = 'ckg_conversation_key_grant_id';

	// Never REST-exposed and never AI-readable: a wrapped key is the one thing
	// on the platform whose disclosure would matter most and whose reading has
	// no legitimate caller outside the decrypt path below.
	public static $api_readable = false;
	public static $api_writable = false;
	public static $ai_readable  = false;

	protected static $foreign_key_actions = array(
		'ckg_cnv_conversation_id' => array('action' => 'permanent_delete', 'source_class' => 'Conversation'),
		'ckg_usr_user_id'         => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'ckg_conversation_key_grant_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'ckg_cnv_conversation_id' => array('type' => 'int8', 'is_nullable' => false),
		'ckg_usr_user_id'         => array('type' => 'int4', 'is_nullable' => false),
		// The conversation key, sealed to this member's vault public key.
		'ckg_wrapped_key'         => array('type' => 'text', 'is_nullable' => false),
		// The member's VAULT key generation at wrap time. A key rotation drains
		// exactly the rows sitting on the generation it is retiring.
		'ckg_key_generation'      => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'ckg_create_time'         => array('type' => 'timestamp(6)'),
	);

	/**
	 * Wrap a conversation key to one member.
	 *
	 * Sealing needs only the member's public key, so this works whether or not
	 * they are anywhere near the site — which is what lets one present member
	 * add another to a sealed conversation.
	 *
	 * @param string $dek raw conversation key bytes
	 * @return ConversationKeyGrant|null null when the member holds no vault
	 */
	public static function grant($conversation_id, $user_id, string $dek): ?ConversationKeyGrant {

		$vault = UserEncryptionVault::loadForUser((int)$user_id);
		if ($vault === null) {
			return null;
		}

		$existing = self::forMember($conversation_id, $user_id);
		if ($existing) {
			return $existing;
		}

		$crypto = new VaultCrypto();
		$row = new ConversationKeyGrant(NULL);
		$row->set('ckg_cnv_conversation_id', (int)$conversation_id);
		$row->set('ckg_usr_user_id', (int)$user_id);
		$row->set('ckg_wrapped_key', $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key')));
		$row->set('ckg_key_generation', (int)$vault->get('uev_key_generation'));
		$row->set('ckg_create_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return $row;
	}

	/** One member's grant on one conversation, or null. */
	public static function forMember($conversation_id, $user_id): ?ConversationKeyGrant {
		$rows = new MultiConversationKeyGrant(array(
			'conversation_id' => (int)$conversation_id,
			'user_id'         => (int)$user_id,
		));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** Take away a member's ability to open this conversation server-side. */
	public static function revoke($conversation_id, $user_id): bool {
		// A key opened earlier in this request may have come through the grant
		// being revoked; from here it must be re-derived or refused.
		unset(self::$open_key_cache[(int)$conversation_id]);
		$row = self::forMember($conversation_id, $user_id);
		if (!$row) {
			return false;
		}
		$row->permanent_delete();
		return true;
	}

	/** @var array<int,array{user_id:int,dek:string}> keys opened this request, and by whose window */
	private static $open_key_cache = array();

	/**
	 * The conversation key, if anyone present can supply it.
	 *
	 * Tries every grant, which reads as "any participant with an open window
	 * suffices". In a web request that resolves to the caller's own window —
	 * an unlock window belongs to a session, so nobody else's key is reachable
	 * from here — and returning null means locked, never an error.
	 *
	 * Deliberately not named openDek(): the sealed-read guard
	 * (tests/vault/sealed_read_paths_test.php) watches for calls shaped like
	 * SealedBox's own primitives, and a method sharing that name would make
	 * every caller look like a violation. The unwrapping below goes through
	 * VaultCrypto, which is the sanctioned door.
	 */
	public static function openConversationKey($conversation_id): ?string {
		$conversation_id = (int)$conversation_id;

		// A sealed thread render asks for the same key once per message, so an
		// opened key is kept for the request. Only SUCCESS is cached — "locked"
		// can become "open" within one request (the unlock request itself drains
		// deferred work), and a cached null would wrongly hold that door shut —
		// and a hit is only as open as the window that opened it, so the window
		// is re-checked and the entry dropped when it has closed since.
		if (isset(self::$open_key_cache[$conversation_id])) {
			$hit = self::$open_key_cache[$conversation_id];
			if (VaultUnlock::secretKey($hit['user_id']) !== null) {
				return $hit['dek'];
			}
			unset(self::$open_key_cache[$conversation_id]);
		}

		$crypto = new VaultCrypto();
		$rows = new MultiConversationKeyGrant(array('conversation_id' => $conversation_id));
		foreach ($rows as $row) {
			$secret = VaultUnlock::secretKey((int)$row->get('ckg_usr_user_id'));
			if ($secret === null) {
				continue;
			}
			try {
				$dek = $crypto->openItemDek((string)$row->get('ckg_wrapped_key'), $secret);
				self::$open_key_cache[$conversation_id] = array(
					'user_id' => (int)$row->get('ckg_usr_user_id'),
					'dek'     => $dek,
				);
				return $dek;
			} catch (Exception $e) {
				// One member's wrapping being unopenable must not hide another's
				// that works. Worth a line in the log; not worth a dead thread.
				error_log('[ConversationKeyGrant] grant ' . $row->key . ' would not open: ' . $e->getMessage());
			}
		}
		return null;
	}

	/**
	 * Re-wrap this member's grants onto a new vault key generation.
	 *
	 * The conversation keys themselves never change — only the envelopes around
	 * them — so every message stays readable and no ciphertext is rewritten.
	 * Called from the messenger's reseal hook during a key rotation.
	 *
	 * @return array{attempted:int,failed:int}
	 */
	public static function resealForUser(int $user_id, string $old_secret_key, int $old_key_generation,
			string $new_public_key, int $new_key_generation): array {

		$crypto = new VaultCrypto();
		$attempted = 0;
		$failed = 0;

		$rows = new MultiConversationKeyGrant(array(
			'user_id'        => $user_id,
			'key_generation' => $old_key_generation,
		));
		foreach ($rows as $row) {
			$attempted++;
			try {
				$dek = $crypto->openItemDek((string)$row->get('ckg_wrapped_key'), $old_secret_key);
				$row->set('ckg_wrapped_key', $crypto->sealItemDek($dek, $new_public_key));
				$row->set('ckg_key_generation', $new_key_generation);
				$row->save();
			} catch (Exception $e) {
				$failed++;
				error_log('[ConversationKeyGrant] reseal failed for grant ' . $row->key . ': ' . $e->getMessage());
			}
		}

		return array('attempted' => $attempted, 'failed' => $failed);
	}

	function display_title() {
		return 'Key grant #' . $this->key;
	}
}

class MultiConversationKeyGrant extends SystemMultiBase {
	protected static $model_class = 'ConversationKeyGrant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['conversation_id'])) {
			$filters['ckg_cnv_conversation_id'] = array($this->options['conversation_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['user_id'])) {
			$filters['ckg_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['key_generation'])) {
			$filters['ckg_key_generation'] = array($this->options['key_generation'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('ckg_conversation_key_grants', $filters, $this->order_by, $only_count, $debug);
	}
}
