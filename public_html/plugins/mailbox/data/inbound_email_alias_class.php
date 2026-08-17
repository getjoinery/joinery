<?php
/**
 * InboundEmailAlias - Virtual mailbox aliases that forward to real addresses
 * or store the message locally.
 *
 * iea_security_level is this ONE mailbox's protection posture, and NULL — the
 * value every hosted mailbox carries — means "inherit the domain's"
 * (specs/mailbox_connect_flow.md § D). Protection attaches to the identity that
 * owns the mail: for hosted mail that identity is the domain (MX, SPF, DMARC and
 * DKIM are domain-level facts, and every mailbox under it inherits); for mail
 * pulled in over IMAP the identity is the mailbox, because gmail.com is not an
 * identity this deployment holds. Two people pulling their own Gmail into one
 * site would otherwise share a single setting, and sealed mail encrypts to one
 * person.
 *
 * security_level()/seals_content() are THE resolver — nothing else reads the
 * column, and nothing else reimplements the inherit rule. Every content-sealing
 * decision asks the alias; domain identity (DKIM, protected identity, DNS shape,
 * relay export) keeps asking the domain.
 *
 * @version 1.5 - the SQL level helpers normalise case exactly as the PHP
 *   resolver does, so the two can never disagree on a stored value
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailAliasException extends SystemBaseException {}

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/NotifiesRelayMapOnChange.php'));

class InboundEmailAlias extends SystemBase {
	use NotifiesRelayMapOnChange;
	public static $prefix = 'iea';
	public static $tablename = 'iea_inbound_email_aliases';
	public static $pkey_column = 'iea_inbound_email_alias_id';

	// Delivery mode values
	const MODE_FORWARD = 'forward';
	const MODE_STORE = 'store';
	const MODE_FORWARD_AND_STORE = 'forward_and_store';

	protected static $foreign_key_actions = [
		// permanent_delete, not cascade: an alias owns filters, grants and IMAP
		// feeds, and a flat SQL delete here would strand all of them.
		'iea_ied_inbound_email_domain_id' => ['action' => 'permanent_delete'],
	];

	public static $field_specifications = array(
		'iea_inbound_email_alias_id'      => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iea_ied_inbound_email_domain_id' => array('type'=>'int4', 'is_nullable'=>false),
		'iea_alias'              => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'iea_destinations'       => array('type'=>'text'),
		'iea_delivery_mode'      => array('type'=>'varchar(20)', 'default'=>'forward', 'is_nullable'=>false),
		'iea_description'        => array('type'=>'varchar(500)'),
		'iea_is_enabled'         => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		// This mailbox's own protection posture, or NULL to inherit the domain's
		// (specs/mailbox_connect_flow.md § D). NULL is what every existing row
		// means, so there is nothing to migrate. Read through security_level().
		'iea_security_level'     => array('type'=>'varchar(16)', 'is_nullable'=>true),
		'iea_forward_count'      => array('type'=>'int4', 'default'=>'0'),
		'iea_last_forward_time'  => array('type'=>'timestamp(6)'),
		'iea_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iea_update_time'        => array('type'=>'timestamp(6)'),
		'iea_delete_time'        => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		// Normalize alias to lowercase
		$alias = strtolower(trim($this->get('iea_alias')));
		$this->set('iea_alias', $alias);

		// Validate alias format (alphanumeric, dots, hyphens, underscores)
		if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $alias)) {
			throw new InboundEmailAliasException('Alias must be alphanumeric (dots, hyphens, underscores allowed).');
		}

		// Validate delivery mode
		$mode = $this->get('iea_delivery_mode');
		if (!$mode) {
			$mode = self::MODE_FORWARD;
			$this->set('iea_delivery_mode', $mode);
		}
		if (!in_array($mode, [self::MODE_FORWARD, self::MODE_STORE, self::MODE_FORWARD_AND_STORE], true)) {
			throw new InboundEmailAliasException('Invalid delivery mode: ' . htmlspecialchars($mode));
		}

		$forwards = ($mode === self::MODE_FORWARD || $mode === self::MODE_FORWARD_AND_STORE);

		// Destinations rules depend on delivery mode.
		$destinations = $this->get('iea_destinations');
		$dest_list = $this->parse_destinations($destinations);

		if ($forwards) {
			if (empty($dest_list)) {
				throw new InboundEmailAliasException('At least one destination email address is required when forwarding.');
			}

			$settings = Globalvars::get_instance();
			$max_destinations = intval($settings->get_setting('mailbox_forwarding_max_destinations')) ?: 10;
			if (count($dest_list) > $max_destinations) {
				throw new InboundEmailAliasException('Maximum ' . $max_destinations . ' destinations allowed.');
			}

			foreach ($dest_list as $dest) {
				if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
					throw new InboundEmailAliasException('Invalid destination email address: ' . htmlspecialchars($dest));
				}
			}

			$this->set('iea_destinations', implode(',', $dest_list));
		} else {
			// Pure store: destinations are ignored / cleared.
			$this->set('iea_destinations', '');
		}

		// Check for duplicate alias within domain
		$domain_id = $this->get('iea_ied_inbound_email_domain_id');
		$existing = new MultiInboundEmailAlias(array(
			'domain_id' => $domain_id,
			'alias' => $alias,
			'deleted' => false
		));
		$existing->load();
		foreach ($existing as $ex) {
			if ($ex->key != $this->key) {
				throw new InboundEmailAliasException('This alias already exists for this domain.');
			}
		}

		$this->set('iea_update_time', gmdate('Y-m-d H:i:s'));
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Parse destinations string into array (handles comma or newline separated).
	 */
	function parse_destinations($destinations) {
		// An alias with no destinations stores NULL, and every caller passes the
		// column straight in, so the empty case reaches here as NULL rather than
		// ''. trim(NULL) is deprecated and becomes a TypeError in PHP 9; the
		// empty-array result is unchanged.
		$raw = preg_split('/[\s,]+/', trim((string)$destinations));
		$clean = array();
		foreach ($raw as $d) {
			$d = trim($d);
			if (!empty($d)) {
				$clean[] = strtolower($d);
			}
		}
		return $clean;
	}

	/**
	 * Get destinations as an array.
	 */
	function get_destinations_array() {
		return $this->parse_destinations($this->get('iea_destinations'));
	}

	/**
	 * Get the full email address for this alias.
	 */
	function get_full_address() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		$domain = new InboundEmailDomain($this->get('iea_ied_inbound_email_domain_id'), TRUE);
		return $this->get('iea_alias') . '@' . $domain->get('ied_domain');
	}

	/**
	 * Increment the forward counter and update last forward time.
	 */
	function record_forward() {
		$this->set('iea_forward_count', intval($this->get('iea_forward_count')) + 1);
		$this->set('iea_last_forward_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}

	/**
	 * This mailbox's protection level (specs/mailbox_connect_flow.md § D) — the
	 * one answer every content-sealing decision asks for. Its own value when it
	 * has one, otherwise the domain's, and Standard for a stored value that is
	 * not a level at all (the same rule the domain applies; the column is only
	 * ever written through validated pickers).
	 */
	function security_level() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		$own = strtolower(trim((string)$this->get('iea_security_level')));
		if ($own !== '') {
			return in_array($own, array(InboundEmailDomain::LEVEL_STANDARD,
				InboundEmailDomain::LEVEL_PRIVATE, InboundEmailDomain::LEVEL_FORTRESS), true)
				? $own : InboundEmailDomain::LEVEL_STANDARD;
		}
		return self::domainLevel(intval($this->get('iea_ied_inbound_email_domain_id')));
	}

	/** True when this mailbox seals stored content at rest (Private or Fortress). */
	function seals_content() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		return in_array($this->security_level(),
			array(InboundEmailDomain::LEVEL_PRIVATE, InboundEmailDomain::LEVEL_FORTRESS), true);
	}

	/** True when this mailbox carries a level of its own rather than inheriting. */
	function has_own_security_level(): bool {
		return trim((string)$this->get('iea_security_level')) !== '';
	}

	/**
	 * The domain's level, read fresh every time.
	 *
	 * NOT memoized, deliberately. A level is mutable — a raise or a lowering
	 * changes it mid-request, and the code that acts on the change is running in
	 * that same request. A cache here answered "private" to everything after a
	 * lowering, so a mailbox that had just converged still claimed to be sealed.
	 * A stale posture is the one kind of wrong answer this resolver must never
	 * give, and one extra row read is not a price worth paying to risk it.
	 */
	private static function domainLevel(int $domain_id): string {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		if ($domain_id <= 0) {
			return InboundEmailDomain::LEVEL_STANDARD;
		}
		$domain = new InboundEmailDomain($domain_id, TRUE);
		return $domain->key ? $domain->security_level() : InboundEmailDomain::LEVEL_STANDARD;
	}

	/**
	 * True when any live mailbox on this domain carries a sealing level of its
	 * own — the question a domain-wide surface has to ask before deciding that
	 * "the domain is Standard" settles anything.
	 */
	static function domainHasSealingMailbox(int $domain_id): bool {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		if ($domain_id <= 0) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT 1 FROM iea_inbound_email_aliases
			 WHERE iea_ied_inbound_email_domain_id = ? AND iea_delete_time IS NULL
			   AND LOWER(TRIM(iea_security_level)) IN (?, ?) LIMIT 1');
		$stmt->execute(array($domain_id, InboundEmailDomain::LEVEL_PRIVATE, InboundEmailDomain::LEVEL_FORTRESS));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * The effective-level SQL expression for a query that has joined a message
	 * to its alias and its domain: the alias's own value when it has one, the
	 * domain's otherwise. The one place the inherit rule is expressed in SQL, so
	 * a set-based query and the PHP resolver can never disagree.
	 *
	 * $alias_tbl / $domain_tbl are the query's table aliases. A message with no
	 * mailbox (the catch-all) has no alias row, so the LEFT JOIN's NULL falls
	 * through to the domain — which is exactly whose mail it is.
	 */
	static function effectiveLevelSql(string $alias_tbl, string $domain_tbl): string {
		// LOWER/TRIM mirror the PHP resolver's normalisation, so a value that
		// only a hand edit could have miscased still reads as the same level
		// here as it does there.
		return "COALESCE(NULLIF(LOWER(TRIM($alias_tbl.iea_security_level)), ''),"
			. " LOWER(TRIM($domain_tbl.ied_security_level)))";
	}

	/**
	 * Look up an alias by full email address.
	 * Returns InboundEmailAlias or false.
	 */
	static function GetByAddress($email_address) {
		$email_address = strtolower(trim($email_address));
		$parts = explode('@', $email_address, 2);
		if (count($parts) !== 2) {
			return false;
		}

		$local_part = $parts[0];
		$domain_name = $parts[1];

		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->get('ied_is_enabled')) {
			return false;
		}

		$results = new MultiInboundEmailAlias(array(
			'domain_id' => $domain->key,
			'alias' => $local_part,
			'deleted' => false
		));
		$results->load();
		if (count($results)) {
			$alias = $results->get(0);
			if ($alias->get('iea_is_enabled')) {
				return $alias;
			}
		}

		return false;
	}
}

class MultiInboundEmailAlias extends SystemMultiBase {
	protected static $model_class = 'InboundEmailAlias';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain_id'])) {
			$filters['iea_ied_inbound_email_domain_id'] = [$this->options['domain_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['alias'])) {
			$filters['iea_alias'] = [$this->options['alias'], PDO::PARAM_STR];
		}

		if (isset($this->options['delivery_mode'])) {
			$filters['iea_delivery_mode'] = [$this->options['delivery_mode'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['iea_is_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}


		return $this->_get_resultsv2('iea_inbound_email_aliases', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
