<?php
/**
 * InboundEmailDomain - Tracks domains that accept inbound mail.
 *
 * ied_is_imap_source marks a domain whose mail arrives by IMAP poll (an
 * InboundImapAccount populates one of its aliases) rather than by MX delivery.
 * The Setup tab skips MX/DNS checks for such a domain — the mail is already in
 * the remote mailbox, so no MX is required.
 *
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailDomainException extends SystemBaseException {}

class InboundEmailDomain extends SystemBase {
	public static $prefix = 'ied';
	public static $tablename = 'ied_inbound_email_domains';
	public static $pkey_column = 'ied_inbound_email_domain_id';

	// Catch-all mode values
	const CATCHALL_FORWARD = 'forward';
	const CATCHALL_STORE = 'store';

	public static $field_specifications = array(
		'ied_inbound_email_domain_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ied_domain'            => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'ied_is_enabled'        => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'ied_catch_all_mode'    => array('type'=>'varchar(20)', 'default'=>'forward', 'is_nullable'=>false),
		'ied_catch_all_address' => array('type'=>'varchar(500)'),
		'ied_reject_unmatched'  => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'ied_is_imap_source'    => array('type'=>'bool', 'default'=>'false', 'is_nullable'=>false),
		'ied_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ied_update_time'       => array('type'=>'timestamp(6)'),
		'ied_delete_time'       => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		// Normalize domain to lowercase
		$domain = strtolower(trim($this->get('ied_domain')));
		$this->set('ied_domain', $domain);

		// Validate domain format
		if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
			throw new InboundEmailDomainException('Invalid domain format.');
		}

		// Validate catch-all mode
		$mode = $this->get('ied_catch_all_mode');
		if (!$mode) {
			$mode = self::CATCHALL_FORWARD;
			$this->set('ied_catch_all_mode', $mode);
		}
		if (!in_array($mode, [self::CATCHALL_FORWARD, self::CATCHALL_STORE], true)) {
			throw new InboundEmailDomainException('Invalid catch-all mode: ' . htmlspecialchars($mode));
		}

		if ($mode === self::CATCHALL_STORE) {
			// In store mode the address is ignored — clear it for consistency.
			$this->set('ied_catch_all_address', '');
		} else {
			// Validate catch-all address if provided (forward mode)
			$catch_all = $this->get('ied_catch_all_address');
			if ($catch_all && !filter_var($catch_all, FILTER_VALIDATE_EMAIL)) {
				throw new InboundEmailDomainException('Invalid catch-all email address.');
			}
		}

		// Check for duplicate domain
		if (!$this->key) {
			$existing = new MultiInboundEmailDomain(array('domain' => $domain, 'deleted' => false));
			if ($existing->count_all() > 0) {
				throw new InboundEmailDomainException('Domain already exists.');
			}
		}

		$this->set('ied_update_time', gmdate('Y-m-d H:i:s'));
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
		require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
		$aliases = new MultiInboundEmailAlias(array('domain_id' => $this->key, 'deleted' => false));
		return $aliases->count_all();
	}

	/**
	 * Look up a domain by name.
	 */
	static function GetByDomain($domain) {
		$results = new MultiInboundEmailDomain(array('domain' => strtolower($domain), 'deleted' => false));
		$results->load();
		if (count($results)) {
			return $results->get(0);
		}
		return false;
	}
}

class MultiInboundEmailDomain extends SystemMultiBase {
	protected static $model_class = 'InboundEmailDomain';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain'])) {
			$filters['ied_domain'] = [$this->options['domain'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['ied_is_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['ied_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('ied_inbound_email_domains', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
