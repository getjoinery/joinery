<?php
/**
 * DirectCapabilityCache - what one domain publishes about Joinery Direct, and
 * for how long that answer is good.
 *
 * This exists for a specific reason on the RECEIVE side. Verifying a preflight
 * needs the SENDER domain's signing key from DNS, and the sender domain is
 * attacker-chosen — so a fresh lookup per preflight would turn the receiver into
 * an outbound-DNS engine driven by attacker input, before the request is
 * authenticated and therefore before any per-instance limit can apply. Caching
 * makes a busy legitimate sender one lookup per TTL, and caching FAILURES
 * (`jdc_has_capability = false`) makes repeatedly naming non-resolving domains
 * cost one lookup rather than one per request.
 *
 * The send side reads the same cache for the recipient domain, where the win is
 * only speed.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DirectCapabilityCacheException extends SystemBaseException {}

class DirectCapabilityCache extends SystemBase {
	public static $prefix = 'jdc';

	function authenticate_read($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
		}
	}

	function authenticate_write($data) {
		throw new SystemAuthenticationError('The Direct capability cache is filled from DNS, never through the API.');
	}

	public static $tablename = 'jdc_direct_capability_cache';
	public static $pkey_column = 'jdc_direct_capability_cache_id';

	public static $field_specifications = array(
		'jdc_direct_capability_cache_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'jdc_domain'   => array('type'=>'varchar(255)', 'is_nullable'=>false, 'unique'=>true),
		// False is a real, cached answer: "this domain does not speak Direct".
		'jdc_has_capability' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'jdc_host'     => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'jdc_port'     => array('type'=>'int4', 'is_nullable'=>true),
		// Every published key id for the domain, id => base64 public key, so a
		// rotation that publishes two keys is honored without a second lookup.
		'jdc_keys'     => array('type'=>'text', 'is_nullable'=>true),
		'jdc_expires_time' => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'index'=>true),
		'jdc_create_time'  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'jdc_update_time'  => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	public static $timestamp_fields = array('jdc_expires_time', 'jdc_create_time', 'jdc_update_time');

	/** The key id => base64 public key map this domain publishes. */
	public function keys(): array {
		$decoded = json_decode((string)$this->get('jdc_keys'), true);
		return is_array($decoded) ? $decoded : array();
	}

	public function isFresh(): bool {
		return (string)$this->get('jdc_expires_time') > gmdate('Y-m-d H:i:s');
	}

	/** The cached row for a domain, fresh or stale; null when never looked up. */
	public static function forDomain(string $domain): ?DirectCapabilityCache {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return null;
		}
		$multi = new MultiDirectCapabilityCache(array('domain' => $domain));
		$multi->load();
		foreach ($multi as $row) {
			return $row;
		}
		return null;
	}

	public static function purgeExpired(): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('DELETE FROM jdc_direct_capability_cache WHERE jdc_expires_time < now() - interval \'1 day\'');
		$stmt->execute();
		return $stmt->rowCount();
	}
}

class MultiDirectCapabilityCache extends SystemMultiBase {
	protected static $model_class = 'DirectCapabilityCache';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['domain'])) {
			$filters['jdc_domain'] = array(strtolower(trim((string)$this->options['domain'])), PDO::PARAM_STR);
		}
		if (isset($this->options['has_capability'])) {
			$filters['jdc_has_capability'] = $this->options['has_capability'] ? '= TRUE' : '= FALSE';
		}
		if (!empty($this->options['fresh'])) {
			$filters['jdc_expires_time'] = '>= now()';
		}

		return $this->_get_resultsv2('jdc_direct_capability_cache', $filters, $this->order_by, $only_count, $debug);
	}
}
