<?php
/**
 * MailboxContact — a per-user email contact (specs/mailbox_compose_maturity.md § Phase 4).
 *
 * A disposable autocomplete cache, NOT an identity/relationship record (that is a separate
 * future core system — specs/FUTURE_verified_connections.md; this table must stay a cache).
 * One row per (user, mailbox, normalized address); the store warms up through use (harvested
 * on every send and on opening a thread) and can be imported from a vCard / Google CSV.
 *
 * MAILBOX SCOPE. A contact belongs to the mailbox it was seen on (imc_iea_inbound_email_alias_id),
 * so composing from a work mailbox never suggests addresses harvested in a personal one. The
 * same person seen on two mailboxes is two rows, which this store treats as normal: it is a
 * cache, not a person record. Scope is a property of the ROW, not of the sealing — a row still
 * seals to the harvesting USER's vault, so grantees sharing one mailbox each keep their own
 * contacts, readable only by them.
 *
 * ENCRYPTION AT REST. A row is sealed iff the owning user holds a Sealed Vault
 * (docs/sealed_vault.md) — the same identity that seals their mail. imc_address and
 * imc_display_name seal under a per-row DEK (imc_sealed_key, sealed to the owner's vault
 * public key); imc_sealed_owner_user_id records whose vault, at seal time. $sealed_fields +
 * the decrypt hooks are the generic Sealed Vault read path (SystemBase::get() and the
 * raw-row readers). AD convention: "contact:{id}:{field}".
 *
 * BLIND-INDEX DEDUP. imc_address_hash is a deterministic digest of the mailbox scope AND the
 * normalized (lowercased, trimmed) address, unique per user, so upsert/dedup works even when
 * imc_address is ciphertext. Hashing the alias id alongside the address is what makes the
 * (hash, user) unique constraint mean one row per (user, MAILBOX, address) without a composite
 * key over a column that is itself ciphertext. For a vault holder it is a KEYED hash (HMAC
 * under a subkey derived from the in-window vault secret) so it never leaks the sealed address
 * to an attacker with only DB access; for a user with no vault it is a plain SHA-256 (the
 * address column is plaintext anyway). See MailboxContacts::addressHash(). A vault rotation
 * changes the derived key, so a re-harvested address may land a second row post-rotation —
 * harmless, because the contacts payload also de-duplicates by decrypted address on read (this
 * store is a cache).
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxContactException extends SystemBaseException {}

class MailboxContact extends SystemBase {
	public static $prefix = 'imc';
	public static $tablename = 'imc_mailbox_contacts';
	public static $pkey_column = 'imc_mailbox_contact_id';

	const SOURCE_SENT     = 'sent';
	const SOURCE_RECEIVED = 'received';
	const SOURCE_IMPORT   = 'import';
	const SOURCE_MANUAL   = 'manual';

	public static $sealed_fields = array('imc_address', 'imc_display_name');

	protected static $foreign_key_actions = array(
		'imc_usr_user_id' => array('action' => 'cascade'),
		// A mailbox going away takes its harvested contacts with it — they are that
		// mailbox's cache and mean nothing without it.
		'imc_iea_inbound_email_alias_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'imc_mailbox_contact_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'imc_usr_user_id'        => array('type'=>'int8', 'is_nullable'=>false),
		// The mailbox this contact was seen on. Nullable only because rows harvested
		// before contacts were mailbox-scoped carry no alias; every write sets it, and
		// every read filters on it, so a legacy NULL row is simply invisible and
		// re-warms on the next send or thread open. Nothing is lost — this is a cache.
		'imc_iea_inbound_email_alias_id' => array('type'=>'int4', 'is_nullable'=>true,
			'foreign_key'=>array('table'=>'iea_inbound_email_aliases',
				'column'=>'iea_inbound_email_alias_id', 'on_delete'=>'CASCADE')),
		// Ciphertext once sealed, so 'text' (base64 + AEAD outgrows a varchar cap).
		'imc_address'            => array('type'=>'text'),
		'imc_display_name'       => array('type'=>'text'),
		// Deterministic dedup digest of the mailbox AND the normalized address; keyed
		// (blind index) for vault holders, plain SHA-256 otherwise. Hashing the alias
		// alongside the address is what makes this per-user constraint mean one row per
		// (user, MAILBOX, address) — see MailboxContacts::addressHash().
		'imc_address_hash'       => array('type'=>'varchar(64)', 'unique_with'=>array('imc_usr_user_id')),
		'imc_last_used_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'imc_use_count'          => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		'imc_source'             => array('type'=>'varchar(10)', 'default'=>'sent'), // sent|received|import|manual
		// Sealed Vault columns (mirror InboundEmailMessage).
		'imc_content_sealed'     => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'imc_sealed_key'         => array('type'=>'text', 'is_nullable'=>true),
		'imc_key_generation'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'imc_sealed_owner_user_id' => array('type'=>'int8', 'is_nullable'=>true),
		'imc_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function authenticate_write($data) {
		// A member manages their OWN contacts; scope is enforced in MailboxContacts
		// (every mutation is bound to the acting user's id), so no admin gate here.
	}

	/**
	 * The AD row-binding string for a sealed contact field (docs/sealed_vault.md § AD).
	 * Overrides the SystemBase default ("imc:{id}:{field}") — every contact already
	 * sealed uses this literal, and changing it would strand them.
	 *
	 * Everything else about sealing here is the SystemBase Layer 0 default: the four
	 * convention columns are declared above, so reads decrypt through
	 * decryptSealedField()/decryptSealedFieldStatic() and writes go through
	 * sealColumns() with no crypto code in this class.
	 */
	public static function sealAd(int $contact_id, string $field): string {
		return 'contact:' . $contact_id . ':' . $field;
	}
}

class MultiMailboxContact extends SystemMultiBase {
	protected static $model_class = 'MailboxContact';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['imc_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['address_hash'])) {
			$filters['imc_address_hash'] = array($this->options['address_hash'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('imc_mailbox_contacts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
