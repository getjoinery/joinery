<?php
/**
 * InboundEmailAlias - Virtual mailbox aliases that forward to real addresses
 * or store the message locally.
 *
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailAliasException extends SystemBaseException {}

class InboundEmailAlias extends SystemBase {
	public static $prefix = 'iea';
	public static $tablename = 'iea_inbound_email_aliases';
	public static $pkey_column = 'iea_inbound_email_alias_id';

	// Delivery mode values
	const MODE_FORWARD = 'forward';
	const MODE_STORE = 'store';
	const MODE_FORWARD_AND_STORE = 'forward_and_store';

	protected static $foreign_key_actions = [
		'iea_ied_inbound_email_domain_id' => ['action' => 'cascade'],
	];

	public static $field_specifications = array(
		'iea_inbound_email_alias_id'      => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iea_ied_inbound_email_domain_id' => array('type'=>'int4', 'is_nullable'=>false),
		'iea_alias'              => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'iea_destinations'       => array('type'=>'text'),
		'iea_delivery_mode'      => array('type'=>'varchar(20)', 'default'=>'forward', 'is_nullable'=>false),
		'iea_description'        => array('type'=>'varchar(500)'),
		'iea_is_enabled'         => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
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
			$max_destinations = intval($settings->get_setting('inbound_email_forwarding_max_destinations')) ?: 10;
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
		$raw = preg_split('/[\s,]+/', trim($destinations));
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
		require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
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

		require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
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

		if (isset($this->options['deleted'])) {
			$filters['iea_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('iea_inbound_email_aliases', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
