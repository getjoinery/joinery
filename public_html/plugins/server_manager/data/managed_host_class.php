<?php
/**
 * ManagedHost - A server that hosts one or more auto-provisioned Joinery sites.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ManagedHostException extends SystemBaseException {}

class ManagedHost extends SystemBase {
	public static $prefix = 'mgh';
	public static $tablename = 'mgh_managed_hosts';
	public static $pkey_column = 'mgh_id';

	public static $field_specifications = array(
		'mgh_id'                   => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mgh_slug'                 => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'mgh_name'                 => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'mgh_host'                 => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'mgh_ssh_user'             => array('type'=>'varchar(50)', 'is_nullable'=>false, 'default'=>"'root'"),
		'mgh_ssh_key_path'         => array('type'=>'varchar(500)'),
		'mgh_ssh_port'             => array('type'=>'int4', 'default'=>'22'),
		'mgh_max_sites'            => array('type'=>'int4', 'default'=>'50'),
		'mgh_provisioning_enabled' => array('type'=>'bool', 'default'=>'false', 'is_nullable'=>false),
		'mgh_notes'                => array('type'=>'text'),
		'mgh_create_time'          => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mgh_update_time'          => array('type'=>'timestamp(6)'),
		'mgh_delete_time'          => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$slug = strtolower(trim($this->get('mgh_slug')));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$slug = preg_replace('/-+/', '-', $slug);
		$slug = trim($slug, '-');
		$this->set('mgh_slug', $slug);

		if (empty($slug)) {
			throw new ManagedHostException('Host slug is required.');
		}
		if (empty($this->get('mgh_name'))) {
			throw new ManagedHostException('Host name is required.');
		}
		if (empty($this->get('mgh_host'))) {
			throw new ManagedHostException('Host IP/hostname is required.');
		}

		$existing = new MultiManagedHost(array('slug' => $slug, 'deleted' => false));
		$existing->load();
		foreach ($existing as $ex) {
			if ($ex->key != $this->key) {
				throw new ManagedHostException('A host with this slug already exists.');
			}
		}

		$this->set('mgh_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Count active (non-deleted) nodes assigned to this host.
	 */
	public function count_sites() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT COUNT(*) FROM mgn_managed_nodes WHERE mgn_mgh_host_id = ? AND mgn_delete_time IS NULL");
		$q->execute([$this->key]);
		return (int) $q->fetchColumn();
	}

	/**
	 * Select the least-loaded provisioning-enabled host with available capacity.
	 * Returns a ManagedHost or null if no capacity is available.
	 */
	public static function pick_for_provisioning() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query(
			"SELECT mgh.mgh_id " .
			"FROM mgh_managed_hosts mgh " .
			"LEFT JOIN mgn_managed_nodes mgn ON mgn.mgn_mgh_host_id = mgh.mgh_id AND mgn.mgn_delete_time IS NULL " .
			"WHERE mgh.mgh_provisioning_enabled = true AND mgh.mgh_delete_time IS NULL " .
			"GROUP BY mgh.mgh_id " .
			"HAVING COUNT(mgn.mgn_id) < mgh.mgh_max_sites " .
			"ORDER BY COUNT(mgn.mgn_id) ASC, mgh.mgh_id ASC " .
			"LIMIT 1"
		);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row) return null;
		return new ManagedHost($row['mgh_id'], true);
	}
}

class MultiManagedHost extends SystemMultiBase {
	protected static $model_class = 'ManagedHost';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['slug'])) {
			$filters['mgh_slug'] = [$this->options['slug'], PDO::PARAM_STR];
		}

		if (isset($this->options['provisioning_enabled'])) {
			$filters['mgh_provisioning_enabled'] = $this->options['provisioning_enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['mgh_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('mgh_managed_hosts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
