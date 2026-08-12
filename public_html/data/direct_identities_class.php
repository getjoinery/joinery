<?php
/**
 * DirectIdentity - one domain's Joinery Direct instance signing identity.
 *
 * The instance signature is what a receiver checks a delivery against, so this
 * row is the private half of what the domain publishes in its `_joinery-key`
 * TXT record. Key custody deliberately mirrors DKIM's (docs/joinery_direct.md §
 * The relay at Fortress): a Standard/Private domain keeps the secret key at rest
 * under SecretBox and the box unwraps it per send; a domain whose owner holds a
 * Sealed Vault keeps it sealed to that vault instead and unwraps it in-window,
 * so a locked Fortress box cannot sign in anyone's name.
 *
 * `jdi_key_id` is what makes rotation a non-event: a new row supersedes the old
 * one, both TXT values are published for as long as the old key id may still be
 * quoted, and a receiver matches the id on the envelope rather than guessing.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DirectIdentityException extends SystemBaseException {}

class DirectIdentity extends SystemBase {
	public static $prefix = 'jdi';

	// REST API: signing-key custody — never readable or writable over the API at
	// any permission level. The public half is published in DNS; the secret half
	// has no legitimate remote reader.
	function authenticate_read($data) {
		throw new SystemAuthenticationError('Direct signing identities are not readable through the API.');
	}

	function authenticate_write($data) {
		throw new SystemAuthenticationError('Direct signing identities are not writable through the API.');
	}

	public static $tablename = 'jdi_direct_identities';
	public static $pkey_column = 'jdi_direct_identity_id';

	// The column carries an 'owner_' infix, so the naming convention cannot find
	// its source table on its own — name it. Losing the owner does not lose the
	// key: box custody is what a domain with no vault owner already uses.
	protected static $foreign_key_actions = array(
		'jdi_owner_usr_user_id' => array('action' => 'null', 'source_table' => 'usr_users'),
	);

	public static $field_specifications = array(
		'jdi_direct_identity_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'jdi_domain'        => array('type'=>'varchar(255)', 'is_nullable'=>false, 'index'=>true),
		// The published key id. Unique per domain so a rotation can stage a second
		// row without colliding, and so a receiver's key-id match is unambiguous.
		'jdi_key_id'        => array('type'=>'varchar(32)', 'is_nullable'=>false, 'unique_with'=>array('jdi_domain')),
		'jdi_public_key'    => array('type'=>'text', 'is_nullable'=>false),   // base64 Ed25519 public key
		// Exactly one of these two holds the secret half. SecretBox at rest for a
		// box-custody domain; crypto_box_seal to the owner's vault public key when
		// the domain's owner holds one (Fortress custody).
		'jdi_secret_key'        => array('type'=>'text', 'is_nullable'=>true),
		'jdi_sealed_secret_key' => array('type'=>'text', 'is_nullable'=>true),
		'jdi_owner_usr_user_id' => array('type'=>'int8', 'is_nullable'=>true),
		// False while a freshly rotated key is published but not yet signing.
		'jdi_is_active'     => array('type'=>'bool', 'is_nullable'=>false, 'default'=>true),
		'jdi_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'jdi_retire_time'   => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	public static $timestamp_fields = array('jdi_create_time', 'jdi_retire_time');

	/** The active identity for a domain, or null when the domain has none yet. */
	public static function activeFor(string $domain): ?DirectIdentity {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return null;
		}
		$multi = new MultiDirectIdentity(array('domain' => $domain, 'is_active' => true));
		$multi->load();
		foreach ($multi as $row) {
			return $row;
		}
		return null;
	}

	/** Every identity for a domain that is still publishable (active or retiring). */
	public static function publishableFor(string $domain): array {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return array();
		}
		$multi = new MultiDirectIdentity(array('domain' => $domain));
		$multi->load();
		$out = array();
		foreach ($multi as $row) {
			$out[] = $row;
		}
		return $out;
	}
}

class MultiDirectIdentity extends SystemMultiBase {
	protected static $model_class = 'DirectIdentity';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['domain'])) {
			$filters['jdi_domain'] = array(strtolower(trim((string)$this->options['domain'])), PDO::PARAM_STR);
		}
		if (isset($this->options['key_id'])) {
			$filters['jdi_key_id'] = array((string)$this->options['key_id'], PDO::PARAM_STR);
		}
		if (isset($this->options['is_active'])) {
			$filters['jdi_is_active'] = $this->options['is_active'] ? '= TRUE' : '= FALSE';
		}
		if (isset($this->options['owner_user_id'])) {
			$filters['jdi_owner_usr_user_id'] = array($this->options['owner_user_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('jdi_direct_identities', $filters, $this->order_by, $only_count, $debug);
	}
}
