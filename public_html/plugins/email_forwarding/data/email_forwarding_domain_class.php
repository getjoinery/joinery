<?php
/**
 * EmailForwardingDomain - Tracks domains that accept forwarded mail.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class EmailForwardingDomainException extends SystemBaseException {}

class EmailForwardingDomain extends SystemBase {
	public static $prefix = 'efd';
	public static $tablename = 'efd_email_forwarding_domains';
	public static $pkey_column = 'efd_email_forwarding_domain_id';

	public static $field_specifications = array(
		'efd_email_forwarding_domain_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'efd_domain'            => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'efd_is_enabled'        => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'efd_catch_all_address' => array('type'=>'varchar(500)'),
		'efd_reject_unmatched'  => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'efd_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'efd_update_time'       => array('type'=>'timestamp(6)'),
		'efd_delete_time'       => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		// Normalize domain to lowercase
		$domain = strtolower(trim($this->get('efd_domain')));
		$this->set('efd_domain', $domain);

		// Validate domain format
		if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
			throw new EmailForwardingDomainException('Invalid domain format.');
		}

		// Validate catch-all address if provided
		$catch_all = $this->get('efd_catch_all_address');
		if ($catch_all && !filter_var($catch_all, FILTER_VALIDATE_EMAIL)) {
			throw new EmailForwardingDomainException('Invalid catch-all email address.');
		}

		// Check for duplicate domain
		if (!$this->key) {
			$existing = new MultiEmailForwardingDomain(array('domain' => $domain, 'deleted' => false));
			if ($existing->count_all() > 0) {
				throw new EmailForwardingDomainException('Domain already exists.');
			}
		}

		$this->set('efd_update_time', gmdate('Y-m-d H:i:s'));
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Get the alias count for this domain.
	 */
	function get_alias_count() {
		require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_alias_class.php'));
		$aliases = new MultiEmailForwardingAlias(array('domain_id' => $this->key, 'deleted' => false));
		return $aliases->count_all();
	}

	/**
	 * Look up a domain by name.
	 */
	static function GetByDomain($domain) {
		$results = new MultiEmailForwardingDomain(array('domain' => strtolower($domain), 'deleted' => false));
		$results->load();
		if (count($results)) {
			return $results->get(0);
		}
		return false;
	}
}

class MultiEmailForwardingDomain extends SystemMultiBase {
	protected static $model_class = 'EmailForwardingDomain';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain'])) {
			$filters['efd_domain'] = [$this->options['domain'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['efd_is_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['efd_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('efd_email_forwarding_domains', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
