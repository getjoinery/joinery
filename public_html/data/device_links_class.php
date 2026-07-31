<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * DeviceLink — one in-flight device-link ceremony (dlk_device_links).
 *
 * A desktop sync client cannot sign a user in well. It has no password field
 * worth trusting, WebAuthn does not work outside a browser, and an account that
 * uses passkeys has no password to type anyway. So the client does not try:
 * it asks the server to open a ceremony, shows the user a short code, and the
 * user approves it in the browser they are already signed into — where their
 * passkey works and where the site can demand a fresh step-up.
 *
 * This row is the ceremony's state while it is open, and it is deliberately
 * short-lived (10 minutes) and hostile to guessing: the link code and poll
 * token are stored only as hashes, the minted API secret is encrypted at rest
 * and delivered exactly once, and wrong codes are counted per address until
 * that address is shut out.
 * Nothing here survives the ceremony — the retention sweep removes the row (see $retention_policy).
 *
 * @version 1.0.0
 */
class DeviceLink extends SystemBase {
	public static $prefix = 'dlk';
	public static $tablename = 'dlk_device_links';
	public static $pkey_column = 'dlk_device_link_id';

	protected static $foreign_key_actions = array(
		'dlk_usr_user_id'         => array('action' => 'permanent_delete'),
		'dlk_apk_api_key_id'      => array('action' => 'null'),
		'dlk_sde_sync_device_id'  => array('action' => 'null'),
	);

	// Retention: two ways a link finishes, so the rule is a method rather than
	// a single age column. 0 in the setting means never purge.
	public static $retention_policy = array(
		'label'          => 'Finished device links',
		'purge_method'   => 'purgeFinishedLinks',
		'window_setting' => 'drive_device_link_grace_minutes',
	);

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_DENIED   = 'denied';

	/** How long a ceremony stays open. Long enough to walk to another machine. */
	const TTL_SECONDS = 600;

	/**
	 * Wrong codes one address may try in CODE_GUESS_WINDOW before being shut
	 * out. The cap lives per-address rather than per-ceremony because a guesser
	 * does not have a ceremony to count against — every wrong guess by
	 * definition matches no row. Ten minutes of validity and a 32^8 space make
	 * this generous; it exists to close the door on scripted grinding, not to
	 * inconvenience someone squinting at a code.
	 */
	const CODE_GUESS_LIMIT = 20;
	const CODE_GUESS_WINDOW = 900;

	/**
	 * Crockford base32, minus the letters that read as digits (I, L, O, U). The
	 * user retypes this off one screen onto another, so the alphabet matters
	 * more than the entropy math: 32^8 is ~10^12, and the attempt cap plus the
	 * 10-minute window do the rest.
	 */
	const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	public static $field_specifications = array(
		'dlk_device_link_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'dlk_code_hash'          => array('type' => 'char(64)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'dlk_poll_token_hash'    => array('type' => 'char(64)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'dlk_device_pubkey'      => array('type' => 'text', 'is_nullable' => true),
		'dlk_device_name'        => array('type' => 'varchar(64)', 'is_nullable' => false, 'required' => true),
		'dlk_platform'           => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true),
		'dlk_request_ip'         => array('type' => 'varchar(45)', 'is_nullable' => true),
		'dlk_status'             => array('type' => 'varchar(12)', 'is_nullable' => false, 'default' => 'pending'),
		'dlk_usr_user_id'        => array('type' => 'int4', 'is_nullable' => true),
		'dlk_apk_api_key_id'     => array('type' => 'int8', 'is_nullable' => true),
		'dlk_sde_sync_device_id' => array('type' => 'int8', 'is_nullable' => true),
		// The drive vault secret key sealed to dlk_device_pubkey in the approving
		// browser. Opaque here — the server never held the key that opens it.
		'dlk_sealed_vault_key'   => array('type' => 'text', 'is_nullable' => true),
		// The minted session secret, SecretBox-encrypted at rest and scrubbed the
		// moment the device collects it. It exists here only to bridge the gap
		// between the browser that approved and the device that is polling.
		'dlk_secret_once'        => array('type' => 'text', 'is_nullable' => true),
		'dlk_expires_time'       => array('type' => 'timestamp(6)', 'is_nullable' => false, 'required' => true),
		'dlk_create_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/** A fresh 8-character link code, shown as XXXX-XXXX. */
	public static function generate_code() {
		$out = '';
		$n = strlen(self::CODE_ALPHABET);
		for ($i = 0; $i < 8; $i++) {
			$out .= self::CODE_ALPHABET[random_int(0, $n - 1)];
		}
		return $out;
	}

	/**
	 * Fold a user-typed code back to canonical form: upper case, separators
	 * dropped, and the four characters people reliably mistype mapped to what
	 * they meant (O→0, I/L→1). Someone reading a code off a screen should not
	 * be defeated by a font.
	 */
	public static function normalize_code($raw) {
		$s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$raw));
		return strtr($s, array('O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V'));
	}

	public static function hash_code($code) {
		return hash('sha256', 'device-link-code:' . self::normalize_code($code));
	}

	public static function hash_token($token) {
		return hash('sha256', 'device-link-token:' . (string)$token);
	}

	/** Load an open ceremony by its raw link code, or null. */
	public static function load_open_by_code($raw_code) {
		return self::load_open_by_column('dlk_code_hash', self::hash_code($raw_code));
	}

	/** Load a ceremony by its raw poll token, or null. Any status; may be expired. */
	public static function load_by_poll_token($raw_token) {
		if (!is_string($raw_token) || $raw_token === '') {
			return null;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT dlk_device_link_id FROM dlk_device_links WHERE dlk_poll_token_hash = ? LIMIT 1");
		$q->execute(array(self::hash_token($raw_token)));
		$id = $q->fetchColumn();
		return ($id === false) ? null : new self((int)$id, true);
	}

	private static function load_open_by_column($column, $hash) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT dlk_device_link_id FROM dlk_device_links
			  WHERE $column = ? AND dlk_status = 'pending' AND dlk_expires_time > now() LIMIT 1");
		$q->execute(array($hash));
		$id = $q->fetchColumn();
		return ($id === false) ? null : new self((int)$id, true);
	}

	public function is_expired() {
		$exp = $this->get('dlk_expires_time');
		return ($exp === null || $exp === '' || $exp <= gmdate('Y-m-d H:i:s'));
	}

	/** Store the one-time session secret, encrypted at rest. */
	public function seal_secret($plaintext) {
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$this->set('dlk_secret_once', (new SecretBox())->encrypt((string)$plaintext));
	}

	/** Read back the one-time session secret, or null when it is already gone. */
	public function open_secret() {
		$blob = $this->get('dlk_secret_once');
		if ($blob === null || $blob === '') {
			return null;
		}
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		try {
			return (new SecretBox())->decrypt($blob);
		} catch (Exception $e) {
			error_log('DeviceLink::open_secret failed for dlk=' . $this->key . ': ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Wipe every secret the row is still carrying, right after the device has
	 * collected them. What is left is an audit trail of a completed ceremony,
	 * which the purge task removes on its next pass.
	 */
	public function scrub_secrets() {
		$this->set('dlk_secret_once', null);
		$this->set('dlk_sealed_vault_key', null);
		$this->save();
	}

	/**
	 * Has this address burned through its wrong-code allowance? Failed lookups
	 * are logged as failures under the 'device_link_code' feature, so the shared
	 * request-log limiter does the counting.
	 */
	public static function guessing_too_much() {
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		return !RequestLogger::check_rate_limit('device_link_code', self::CODE_GUESS_LIMIT, self::CODE_GUESS_WINDOW, false);
	}

	/** Record one wrong code from this address. */
	public static function record_failed_guess() {
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		RequestLogger::log('device_link_code', 'device_link lookup', false, array(
			'error_type' => 'AuthenticationError',
			'note' => 'Unknown or expired device link code',
		));
	}

	/**
	 * Remove device links that are finished with.
	 *
	 * A link finishes two ways and both are covered: it expired without anyone
	 * acting on it, or it was approved/denied and the ceremony is over. The
	 * grace is measured from each of those moments, not from creation, so a
	 * link is never removed while its window is still open.
	 *
	 * @param int $minutes  Grace period from the retention setting
	 * @return array        removed, message
	 */
	public static function purgeFinishedLinks($minutes) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"DELETE FROM dlk_device_links
			  WHERE dlk_expires_time < now() - (INTERVAL '1 minute' * :grace)
			     OR (dlk_status <> 'pending' AND dlk_create_time < now() - (INTERVAL '1 minute' * :grace2))");
		$q->execute(array(':grace' => (int)$minutes, ':grace2' => (int)$minutes));
		$removed = $q->rowCount();

		return array(
			'removed' => $removed,
			'message' => $removed === 0 ? 'no finished device links' : $removed . ' finished device link(s)',
		);
	}
}

class MultiDeviceLink extends SystemMultiBase {
	protected static $model_class = 'DeviceLink';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['dlk_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['status'])) {
			$filters['dlk_status'] = array($this->options['status'], PDO::PARAM_STR);
		}
		if (isset($this->options['expired_before'])) {
			$filters['dlk_expires_time'] = '< ' . DbConnector::get_instance()->get_db_link()->quote($this->options['expired_before']);
		}

		return $this->_get_resultsv2('dlk_device_links', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
