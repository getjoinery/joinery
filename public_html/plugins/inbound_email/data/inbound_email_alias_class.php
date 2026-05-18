<?php
/**
 * EmailForwardingAlias - Virtual mailbox aliases that forward to real addresses.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class EmailForwardingAliasException extends SystemBaseException {}

class EmailForwardingAlias extends SystemBase {
	public static $prefix = 'efa';
	public static $tablename = 'efa_email_forwarding_aliases';
	public static $pkey_column = 'efa_email_forwarding_alias_id';

	protected static $foreign_key_actions = [
		'efa_efd_email_forwarding_domain_id' => ['action' => 'cascade'],
	];

	public static $field_specifications = array(
		'efa_email_forwarding_alias_id'        => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'efa_efd_email_forwarding_domain_id'   => array('type'=>'int4', 'is_nullable'=>false),
		'efa_alias'              => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'efa_destinations'       => array('type'=>'text', 'required'=>true, 'is_nullable'=>false),
		'efa_description'        => array('type'=>'varchar(500)'),
		'efa_is_enabled'         => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'efa_forward_count'      => array('type'=>'int4', 'default'=>'0'),
		'efa_last_forward_time'  => array('type'=>'timestamp(6)'),
		'efa_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'efa_update_time'        => array('type'=>'timestamp(6)'),
		'efa_delete_time'        => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		// Normalize alias to lowercase
		$alias = strtolower(trim($this->get('efa_alias')));
		$this->set('efa_alias', $alias);

		// Validate alias format (alphanumeric, dots, hyphens, underscores)
		if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $alias)) {
			throw new EmailForwardingAliasException('Alias must be alphanumeric (dots, hyphens, underscores allowed).');
		}

		// Validate destinations
		$destinations = $this->get('efa_destinations');
		$dest_list = $this->parse_destinations($destinations);

		if (empty($dest_list)) {
			throw new EmailForwardingAliasException('At least one destination email address is required.');
		}

		$settings = Globalvars::get_instance();
		$max_destinations = intval($settings->get_setting('email_forwarding_max_destinations')) ?: 10;
		if (count($dest_list) > $max_destinations) {
			throw new EmailForwardingAliasException('Maximum ' . $max_destinations . ' destinations allowed.');
		}

		foreach ($dest_list as $dest) {
			if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
				throw new EmailForwardingAliasException('Invalid destination email address: ' . htmlspecialchars($dest));
			}
		}

		// Store normalized comma-separated
		$this->set('efa_destinations', implode(',', $dest_list));

		// Check for duplicate alias within domain
		$domain_id = $this->get('efa_efd_email_forwarding_domain_id');
		$existing = new MultiEmailForwardingAlias(array(
			'domain_id' => $domain_id,
			'alias' => $alias,
			'deleted' => false
		));
		$existing->load();
		foreach ($existing as $ex) {
			if ($ex->key != $this->key) {
				throw new EmailForwardingAliasException('This alias already exists for this domain.');
			}
		}

		$this->set('efa_update_time', gmdate('Y-m-d H:i:s'));
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
		return $this->parse_destinations($this->get('efa_destinations'));
	}

	/**
	 * Get the full email address for this alias.
	 */
	function get_full_address() {
		require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));
		$domain = new EmailForwardingDomain($this->get('efa_efd_email_forwarding_domain_id'), TRUE);
		return $this->get('efa_alias') . '@' . $domain->get('efd_domain');
	}

	/**
	 * Increment the forward counter and update last forward time.
	 */
	function record_forward() {
		$this->set('efa_forward_count', intval($this->get('efa_forward_count')) + 1);
		$this->set('efa_last_forward_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}

	/**
	 * Look up an alias by full email address.
	 * Returns EmailForwardingAlias or false.
	 */
	static function GetByAddress($email_address) {
		$email_address = strtolower(trim($email_address));
		$parts = explode('@', $email_address, 2);
		if (count($parts) !== 2) {
			return false;
		}

		$local_part = $parts[0];
		$domain_name = $parts[1];

		require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));
		$domain = EmailForwardingDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->get('efd_is_enabled')) {
			return false;
		}

		$results = new MultiEmailForwardingAlias(array(
			'domain_id' => $domain->key,
			'alias' => $local_part,
			'deleted' => false
		));
		$results->load();
		if (count($results)) {
			$alias = $results->get(0);
			if ($alias->get('efa_is_enabled')) {
				return $alias;
			}
		}

		return false;
	}
}

class MultiEmailForwardingAlias extends SystemMultiBase {
	protected static $model_class = 'EmailForwardingAlias';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain_id'])) {
			$filters['efa_efd_email_forwarding_domain_id'] = [$this->options['domain_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['alias'])) {
			$filters['efa_alias'] = [$this->options['alias'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['efa_is_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['efa_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('efa_email_forwarding_aliases', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
