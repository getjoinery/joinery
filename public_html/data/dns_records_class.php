<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ManagedDnsRecordException extends SystemBaseException {}

/**
 * Managed DNS records — the platform's memory of which records in a zone are
 * its responsibility.
 *
 * The platform manages only records it created or adopted, and this table is
 * what makes that sentence enforceable: it never modifies or deletes a record
 * absent from here, and removing a domain withdraws only what is listed.
 *
 * **Ownership is acquired by agreement, not only by authorship.** A record whose
 * live value already matches what the platform wants is adopted on first publish
 * without touching DNS — platform and zone already agree, so recording who is
 * responsible is bookkeeping, not a change. Only a record that exists and
 * *differs* is a conflict, and that takes an explicit adopt-and-overwrite
 * choice. Ownership strictly from authorship was rejected because it punishes
 * the deployments that did the work by hand: every correct record they published
 * would sit permanently unowned.
 *
 * No credential is ever recorded here. dnr_provider is a driver NAME, kept so a
 * later publish can say where a record was written.
 *
 * See docs/dns_management.md.
 *
 * @version 1.0.0
 */
class ManagedDnsRecord extends SystemBase {	public static $prefix = 'dnr';
	public static $tablename = 'dnr_dns_records';
	public static $pkey_column = 'dnr_dns_record_id';

	/** Nothing references an ownership row; it is a leaf. */
	public static $permanent_delete_actions = array();

	public static $field_specifications = array(
	    'dnr_dns_record_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'dnr_domain' => array('type'=>'varchar(253)', 'is_nullable'=>false),
	    'dnr_type' => array('type'=>'varchar(10)', 'is_nullable'=>false),
	    'dnr_name' => array('type'=>'varchar(253)', 'is_nullable'=>false),
	    'dnr_value' => array('type'=>'text'),
	    'dnr_owner' => array('type'=>'varchar(64)', 'is_nullable'=>false),
	    'dnr_provider' => array('type'=>'varchar(64)'),
	    'dnr_zone' => array('type'=>'varchar(253)'),
	    'dnr_adopted' => array('type'=>'bool', 'default'=>'false'),
	    'dnr_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'dnr_update_time' => array('type'=>'timestamp(6)'),
	    'dnr_delete_time' => array('type'=>'timestamp(6)'),
	);

	// Admin-only: the table says what the platform may overwrite in someone's
	// DNS, so it is never part of a member-facing surface.
	function authenticate_read($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
		}
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	/**
	 * The live ownership row for one record slot, or null.
	 *
	 * A slot is (domain, type, name) — not the value. Ownership survives a value
	 * change, which is what lets the platform update a record it already manages
	 * without asking again.
	 */
	public static function Find($domain, $type, $name) {
		$rows = new MultiManagedDnsRecord(array(
			'domain'  => strtolower(trim((string)$domain)),
			'type'    => strtoupper(trim((string)$type)),
			'name'    => strtolower(rtrim(trim((string)$name), '.')),
			'deleted' => false,
		));
		$rows->load();
		return count($rows) ? $rows->get(0) : null;
	}

	/**
	 * Record the platform as responsible for a record slot, creating the row or
	 * refreshing the value on an existing one. Idempotent: re-publishing an
	 * already-owned record changes nothing but the recorded value.
	 *
	 * @param bool $adopted True when ownership came from the live value already
	 *                      matching the plan rather than from writing it.
	 */
	public static function Remember($domain, $type, $name, $value, $owner,
			$provider = '', $zone = '', $adopted = false) {
		$row = self::Find($domain, $type, $name);
		if ($row === null) {
			$row = new ManagedDnsRecord(NULL);
			$row->set('dnr_domain', strtolower(trim((string)$domain)));
			$row->set('dnr_type', strtoupper(trim((string)$type)));
			$row->set('dnr_name', strtolower(rtrim(trim((string)$name), '.')));
			$row->set('dnr_owner', (string)$owner);
			$row->set('dnr_adopted', (bool)$adopted);
		}
		$row->set('dnr_value', (string)$value);
		if ((string)$provider !== '') { $row->set('dnr_provider', (string)$provider); }
		if ((string)$zone !== '')     { $row->set('dnr_zone', (string)$zone); }
		$row->set('dnr_update_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return $row;
	}

	/** Whether the platform is responsible for a record slot. */
	public static function IsOwned($domain, $type, $name) {
		return self::Find($domain, $type, $name) !== null;
	}

	/** Stop claiming responsibility for a slot. The DNS record itself is untouched. */
	public static function Forget($domain, $type, $name) {
		$row = self::Find($domain, $type, $name);
		if ($row !== null) {
			$row->soft_delete();
		}
	}

	/**
	 * Every record slot the platform owns in one domain — what a withdrawal
	 * offers to remove, and the only thing it may remove.
	 *
	 * @return ManagedDnsRecord[]
	 */
	public static function OwnedFor($domain) {
		$rows = new MultiManagedDnsRecord(
			array('domain' => strtolower(trim((string)$domain)), 'deleted' => false),
			array('dnr_type' => 'ASC', 'dnr_name' => 'ASC')
		);
		$rows->load();
		$out = array();
		foreach ($rows as $row) {
			$out[] = $row;
		}
		return $out;
	}
}

class MultiManagedDnsRecord extends SystemMultiBase {
	protected static $model_class = 'ManagedDnsRecord';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$items[$entry->key] = $entry->get('dnr_type') . ' ' . $entry->get('dnr_name');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['domain'])) {
            $filters['dnr_domain'] = [$this->options['domain'], PDO::PARAM_STR];
        }

        if (isset($this->options['type'])) {
            $filters['dnr_type'] = [$this->options['type'], PDO::PARAM_STR];
        }

        if (isset($this->options['name'])) {
            $filters['dnr_name'] = [$this->options['name'], PDO::PARAM_STR];
        }

        if (isset($this->options['owner'])) {
            $filters['dnr_owner'] = [$this->options['owner'], PDO::PARAM_STR];
        }

        if (isset($this->options['provider'])) {
            $filters['dnr_provider'] = [$this->options['provider'], PDO::PARAM_STR];
        }

        if (isset($this->options['deleted'])) {
            $filters['dnr_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('dnr_dns_records', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
