<?php
/**
 * InboundPersonalAddress - a secret, unguessable address that stands for "this
 * person, about this thing".
 *
 * `boards+r7k2…@example.org` means "Alice, about board 7". It is a sub-address of a
 * plugin's handler alias (InboundHandlerRegistry), so a message to it reaches the
 * plugin's InboundAddressHandler with the token as its tag. The handler resolve()s
 * the tag and learns who is writing and what about, without trusting the From header
 * alone: senderIs() is the standard second check (the From must be the person, and
 * the message must be attested by the sending domain).
 *
 * The standard check a handler makes:
 *   1. resolve() the tag.
 *   2. senderIs() against the address's user.
 *   3. The person's account is active (userIsActive()), and the handler's own
 *      permission: may this person still act on this thing? That check runs at the
 *      moment of use, so an address held by someone who has since lost access is
 *      harmless and addresses are not revoked on access loss.
 *
 * Mail that fails any step is left stored and not acted on.
 *
 * @version 1.0
 */

class InboundPersonalAddress extends SystemBase {

	public static $prefix = 'ipa';
	public static $tablename = 'ipa_inbound_personal_addresses';
	public static $access_class = 'personal';
	public static $owner_column = 'ipa_usr_user_id';   // a member's own address
	public static $pkey_column = 'ipa_inbound_personal_address_id';

	const TOKEN_LENGTH = 26;

	// Revoked rows are kept 90 days (a message already in flight to a just-revoked
	// address can still be told why), then purged by the core RetentionSweep. 0 keeps
	// them indefinitely.
	public static $retention_policy = array(
		'label'          => 'Revoked personal addresses',
		'age_column'     => 'ipa_delete_time',
		'age_unit'       => 'days',
		'only_where'     => 'ipa_delete_time IS NOT NULL',
		'window_setting' => 'mailbox_personal_address_retention_days',
	);

	protected static $foreign_key_actions = array(
		// A deleted person's addresses go with them.
		'ipa_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'ipa_inbound_personal_address_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'ipa_token'       => array('access_class' => 'restricted', 'type' => 'varchar(32)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'ipa_handler'     => array('type' => 'varchar(64)', 'is_nullable' => false, 'required' => true,
			'unique_with' => array('ipa_usr_user_id', 'ipa_ref_type', 'ipa_ref_id')),
		// The database constraint is what removes a deleted person's addresses where no
		// deletion rules are loaded (the test database carries none).
		'ipa_usr_user_id' => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'usr_users', 'column' => 'usr_user_id', 'on_delete' => 'CASCADE')),
		'ipa_ref_type'    => array('type' => 'varchar(32)', 'is_nullable' => false, 'required' => true),
		'ipa_ref_id'      => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'ipa_create_time' => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'ipa_delete_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	/** Base32 (RFC 4648 alphabet, lowercased): letters and 2-7, safe in a local part. */
	private static function newToken(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$bytes = random_bytes(self::TOKEN_LENGTH);
		$token = '';
		for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
			$token .= $alphabet[ord($bytes[$i]) & 31];
		}
		return $token;
	}

	private static function liveRow(string $handler, int $user_id, string $ref_type, int $ref_id): ?InboundPersonalAddress {
		$rows = new MultiInboundPersonalAddress(array(
			'handler' => $handler, 'user_id' => $user_id, 'ref_type' => $ref_type, 'ref_id' => $ref_id, 'deleted' => false));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/**
	 * The address for this person and thing: `{handler}+{token}@{domain}`, creating
	 * the row on first use. The domain is the one given, or the registry's default.
	 * Null when the handler has no alias on it (no eligible domain, or the plugin has
	 * not asked for one there yet).
	 *
	 * The token is not tied to a domain: resolve() finds it from whichever domain's
	 * alias the mail arrived at.
	 */
	public static function addressFor(string $handler, int $user_id, string $ref_type, int $ref_id, ?int $domain_id = null): ?string {
		$domain = ($domain_id !== null) ? new InboundEmailDomain($domain_id, TRUE) : InboundHandlerRegistry::defaultDomain();
		if ($domain === null || !$domain->key) {
			return null;
		}
		$alias = InboundHandlerRegistry::aliasFor($handler, $domain);
		if ($alias === null || !$alias->get('iea_is_enabled') || $alias->seals_content()) {
			return null;
		}

		$row = self::liveRow($handler, $user_id, $ref_type, $ref_id);
		if ($row === null) {
			$row = new InboundPersonalAddress(NULL);
			$row->set('ipa_token', self::newToken());
			$row->set('ipa_handler', $handler);
			$row->set('ipa_usr_user_id', $user_id);
			$row->set('ipa_ref_type', $ref_type);
			$row->set('ipa_ref_id', $ref_id);
			$row->save();
		}
		return $handler . '+' . $row->get('ipa_token') . '@' . $domain->get('ied_domain');
	}

	/** The live row for a tag and handler, or null (unknown, revoked, or another handler's). */
	public static function resolve(string $handler, ?string $tag): ?InboundPersonalAddress {
		$tag = strtolower(trim((string)$tag));
		if (strlen($tag) !== self::TOKEN_LENGTH || !preg_match('/^[a-z2-7]+$/', $tag)) {
			return null;
		}
		$rows = new MultiInboundPersonalAddress(array('token' => $tag, 'handler' => $handler, 'deleted' => false));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** Revoke one address. Resetting an address is revoke followed by addressFor(). */
	public static function revoke(string $handler, int $user_id, string $ref_type, int $ref_id): void {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE ipa_inbound_personal_addresses SET ipa_delete_time = now()
			  WHERE ipa_handler = ? AND ipa_usr_user_id = ? AND ipa_ref_type = ? AND ipa_ref_id = ? AND ipa_delete_time IS NULL');
		$stmt->execute(array($handler, $user_id, $ref_type, $ref_id));
	}

	/** Revoke every address for a thing, for example when a card is permanently deleted. */
	public static function revokeAllFor(string $handler, string $ref_type, int $ref_id): void {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE ipa_inbound_personal_addresses SET ipa_delete_time = now()
			  WHERE ipa_handler = ? AND ipa_ref_type = ? AND ipa_ref_id = ? AND ipa_delete_time IS NULL');
		$stmt->execute(array($handler, $ref_type, $ref_id));
	}

	/**
	 * Was this message really sent by this member? The From header alone is
	 * forgeable, so both hold: the sender is the member, and the message is attested.
	 *
	 *   1. The address in iem_sender matches the account email, or the verified
	 *      recovery email, case-insensitively.
	 *   2. InboundEmailMessage::senderAttested() — the sending domain vouched for it.
	 */
	public static function senderIs(InboundEmailMessage $msg, User $user): bool {
		$from = $msg->senderAddress();
		if ($from === '') {
			return false;
		}
		$mine = array(strtolower(trim((string)$user->get('usr_email'))));
		$recovery = strtolower(trim((string)$user->recovery_email()));
		if ($recovery !== '') {
			$mine[] = $recovery;
		}
		return in_array($from, $mine, true) && $msg->senderAttested();
	}

	/**
	 * Is this person's account one that can act: it exists, is not deleted and not
	 * disabled? A soft delete leaves their address rows in place, so a handler asks.
	 */
	public static function userIsActive(User $user): bool {
		return $user->key && !$user->get('usr_delete_time') && !$user->get('usr_is_disabled');
	}
}

class MultiInboundPersonalAddress extends SystemMultiBase {
	protected static $model_class = 'InboundPersonalAddress';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['token'])) {
			$filters['ipa_token'] = [$this->options['token'], PDO::PARAM_STR];
		}
		if (isset($this->options['handler'])) {
			$filters['ipa_handler'] = [$this->options['handler'], PDO::PARAM_STR];
		}
		if (isset($this->options['user_id'])) {
			$filters['ipa_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['ref_type'])) {
			$filters['ipa_ref_type'] = [$this->options['ref_type'], PDO::PARAM_STR];
		}
		if (isset($this->options['ref_id'])) {
			$filters['ipa_ref_id'] = [$this->options['ref_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('ipa_inbound_personal_addresses', $filters, $this->order_by, $only_count, $debug);
	}
}
