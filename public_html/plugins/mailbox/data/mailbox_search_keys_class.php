<?php
/**
 * MailboxSearchKey — one row per user: the random key that seals the search
 * index each of their browsers keeps over their end-to-end (Fortress) mail
 * (specs/client_custody_mail.md § R5), sealed to their `mail` vault.
 *
 * The browser mints it and seals it (`v1.edgeseal.mail.`); the server stores
 * the blob and cannot open it. Every browser the person searches from opens the
 * same key, so an index record one browser wrote is readable only there, and a
 * rotation of the mail vault moves only this row's wrapping: the key itself
 * never changes, so no browser rebuilds its index. That leaks nothing — the key
 * opens only a browser's own saved index, whose words that browser already held.
 *
 * Create-only (acceptBrowserKey()): the sealed blob is the only copy of the key,
 * so an overwrite would orphan every saved index; a second browser racing the
 * first is refused and fetches the winner's.
 *
 * The blob-only sealing shape (docs/sealed_vault.md § Blob-only sealing): no
 * $sealed_fields, the four sealing columns. Registered with the mail rotation
 * (VaultUnlock::clientReseal in the mailbox bootstrap), which walks
 * msk_sealed_key / msk_key_generation / msk_sealed_owner_user_id like a
 * message row's.
 *
 * @version 1.2 - a lowering never moves it: browserCustodyPage() lists nothing and
 *   browserCustodyBacklog() counts 0
 * @version 1.1 - authenticate_write refuses: acceptBrowserKey() is the only way a key is written
 * @version 1.0
 */

class MailboxSearchKeyException extends SystemBaseException {}

class MailboxSearchKey extends SystemBase {
	public static $prefix = 'msk';
	public static $tablename = 'msk_mailbox_search_keys';
	public static $pkey_column = 'msk_mailbox_search_key_id';

	public static $api_readable = false;
	public static $api_writable = false;

	/** The scope the key is sealed to: the person's mail vault. */
	const SCOPE = 'mail';

	protected static $foreign_key_actions = array(
		'msk_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'msk_mailbox_search_key_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'msk_usr_user_id'           => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true),
		// The four Sealed Vault columns. msk_sealed_key is the sealed search key itself.
		'msk_content_sealed'        => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'msk_sealed_key'            => array('type'=>'text', 'is_nullable'=>true),
		'msk_sealed_owner_user_id'  => array('type'=>'int8', 'is_nullable'=>true),
		'msk_key_generation'        => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'msk_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/** No write on anyone's behalf through the model (assert_can_write, the API): the row is
	 *  written only by acceptBrowserKey() (create-only) and moved only by the rotation's
	 *  re-seal. Nothing calls save() on it. */
	function authenticate_write($data) {
		throw new MailboxSearchKeyException('A search key is written only by its owner\'s browser (acceptBrowserKey).');
	}

	protected static function sealScopeForWrite(array $row): string {
		return self::SCOPE;
	}

	/** The key stays in the mail vault whatever the mailboxes' levels
	 *  (sealScopeForWrite()), so a lowering's walk has nothing of it to move. */
	public static function browserCustodyPage(int $user_id, string $scope, int $after_id, int $limit): array {
		return array('rows' => array(), 'last_id' => $after_id, 'done' => true);
	}

	public static function browserCustodyBacklog(int $user_id, string $scope): ?int {
		return 0;
	}

	/** The user's sealed search key (`v1.edgeseal.mail.…`), or null before their first browser made one. */
	public static function sealedKeyFor(int $user_id): ?string {
		$row = self::loadForUser($user_id);
		$sealed = $row ? (string)$row->get('msk_sealed_key') : '';
		return $sealed !== '' ? $sealed : null;
	}

	/**
	 * Store the search key the user's browser minted and sealed to their mail
	 * vault. $public_key names the key it sealed to: the vault's current key,
	 * or the pending one during a rotation, which sets the generation.
	 *
	 * @throws MailboxSearchKeyException already set up, not a mail-vault blob,
	 *   no mail vault, or sealed to a key the vault does not have
	 */
	public static function acceptBrowserKey(int $user_id, string $sealed_key, string $public_key): void {
		$prefix = 'v1.edgeseal.' . self::SCOPE . '.';
		if (strncmp($sealed_key, $prefix, strlen($prefix)) !== 0 || strlen($sealed_key) <= strlen($prefix)) {
			throw new MailboxSearchKeyException('That is not a key sealed to your mail vault.');
		}
		if (self::sealedKeyFor($user_id) !== null) {
			throw new MailboxSearchKeyException('Your search key is already set up.');
		}
		$vault = VaultClientCustody::loadVault($user_id, self::SCOPE);
		if (!$vault) {
			throw new MailboxSearchKeyException('Set up your vault before searching end-to-end encrypted mail.');
		}
		if ($public_key !== '' && $public_key === (string)$vault->get('uev_public_key')) {
			$generation = (int)$vault->get('uev_key_generation');
		} elseif ($public_key !== '' && $vault->get('uev_pending_key_generation') !== null
				&& $public_key === (string)$vault->get('uev_pending_public_key')) {
			$generation = (int)$vault->get('uev_pending_key_generation');
		} else {
			throw new MailboxSearchKeyException('That key is sealed to a key your vault does not have.');
		}

		// One statement, and the unique user column decides a race: the loser's
		// insert does nothing and it is told to fetch the winner's key.
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('INSERT INTO ' . self::$tablename . '
			(msk_usr_user_id, msk_content_sealed, msk_sealed_key, msk_key_generation, msk_sealed_owner_user_id)
			VALUES (?, true, ?, ?, ?)
			ON CONFLICT (msk_usr_user_id) DO NOTHING');
		$stmt->execute(array($user_id, $sealed_key, $generation, $user_id));
		if ($stmt->rowCount() === 0) {
			throw new MailboxSearchKeyException('Your search key is already set up.');
		}
	}

	public static function loadForUser(int $user_id): ?MailboxSearchKey {
		$multi = new MultiMailboxSearchKey(array('user_id' => $user_id));
		$multi->load();
		return $multi->count() > 0 ? $multi->get(0) : null;
	}
}

class MultiMailboxSearchKey extends SystemMultiBase {
	protected static $model_class = 'MailboxSearchKey';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['user_id'])) {
			$filters['msk_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('msk_mailbox_search_keys', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
