<?php
/**
 * MailboxContactIndexKey — one row per user: the random key that keys the
 * contact store's blind address index (MailboxContacts::addressHash()),
 * sealed to the user's vault like any per-item DEK.
 *
 * The index key exists so an address digest can be computed without the
 * vault secret in hand. It is minted once, at the user's first sealed contact
 * write, and opened in-window through VaultCrypto::openItemDek() exactly as a
 * row DEK is; it never derives from the vault secret, so a key rotation moves
 * only its wrapping (VaultUnlock::modelReseal() in the mailbox bootstrap)
 * and every address hash survives the rotation.
 *
 * This is the blob-only sealing shape (docs/sealed_vault.md § Blob-only
 * sealing): no $sealed_fields, the four sealing columns, and the key recorded
 * with SystemBase::recordSealedKey(). The row IS its key — there is no
 * content column to seal under it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class MailboxContactIndexKeyException extends SystemBaseException {}

class MailboxContactIndexKey extends SystemBase {
	public static $prefix = 'mck';
	public static $tablename = 'mck_mailbox_contact_index_keys';
	public static $pkey_column = 'mck_mailbox_contact_index_key_id';

	public static $api_readable = false;
	public static $api_writable = false;

	protected static $foreign_key_actions = array(
		'mck_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'mck_mailbox_contact_index_key_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'mck_usr_user_id'           => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true),
		// The four Sealed Vault columns (docs/sealed_vault.md § Sealed-field model
		// hook). mck_sealed_key is the sealed index key itself.
		'mck_content_sealed'        => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'mck_sealed_key'            => array('type'=>'text', 'is_nullable'=>true),
		'mck_sealed_owner_user_id'  => array('type'=>'int8', 'is_nullable'=>true),
		'mck_key_generation'        => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'mck_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function authenticate_write($data) {
		// Written only by MailboxContacts on the owner's own behalf; nothing
		// user-facing edits this row.
	}

	/**
	 * The user's index key, raw 32 bytes, opened under their in-window vault
	 * key — minted and sealed to $vault on first use.
	 *
	 * @throws VaultLockedException never here (the caller already holds the
	 *   open key); a RuntimeException from the open means the row is damaged
	 */
	public static function openForUser(int $user_id, UserEncryptionVault $vault, VaultKey $key): string {
		$crypto = new VaultCrypto();
		$row = self::loadForUser($user_id);
		if ($row === null) {
			$row = new MailboxContactIndexKey(NULL);
			$row->set('mck_usr_user_id', $user_id);
			$row->save();
			// recordSealedKey() mints the key, seals it to the vault and stamps
			// the generation and owner the rotation sweep reads.
			return self::recordSealedKey((int)$row->key, $vault);
		}
		$sealed = (string)$row->get('mck_sealed_key');
		if ($sealed === '') {
			return self::recordSealedKey((int)$row->key, $vault);
		}
		return $crypto->openItemDek($sealed, $key);
	}

	public static function loadForUser(int $user_id): ?MailboxContactIndexKey {
		$multi = new MultiMailboxContactIndexKey(array('user_id' => $user_id));
		$multi->load();
		return $multi->count() > 0 ? $multi->get(0) : null;
	}
}

class MultiMailboxContactIndexKey extends SystemMultiBase {
	protected static $model_class = 'MailboxContactIndexKey';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['user_id'])) {
			$filters['mck_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('mck_mailbox_contact_index_keys', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
