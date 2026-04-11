<?php
/**
 * ManagedNode - A remote Joinery server or container managed by the control plane.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ManagedNodeException extends SystemBaseException {}

class ManagedNode extends SystemBase {
	public static $prefix = 'mgn';
	public static $tablename = 'mgn_managed_nodes';
	public static $pkey_column = 'mgn_id';

	public static $json_vars = array('mgn_last_status_data');

	public static $field_specifications = array(
		'mgn_id'                  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mgn_name'                => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'mgn_slug'                => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'mgn_host'                => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'mgn_ssh_user'            => array('type'=>'varchar(50)', 'is_nullable'=>false, 'default'=>"'root'"),
		'mgn_ssh_key_path'        => array('type'=>'varchar(500)'),
		'mgn_ssh_port'            => array('type'=>'int4', 'default'=>'22'),
		'mgn_container_name'      => array('type'=>'varchar(100)'),
		'mgn_container_user'      => array('type'=>'varchar(50)'),
		'mgn_web_root'            => array('type'=>'varchar(500)'),
		'mgn_site_url'            => array('type'=>'varchar(500)'),
		'mgn_joinery_version'     => array('type'=>'varchar(20)'),
		'mgn_last_status_check'   => array('type'=>'timestamp(6)'),
		'mgn_last_status_data'    => array('type'=>'jsonb'),
		'mgn_enabled'             => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'mgn_notes'               => array('type'=>'text'),
		'mgn_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mgn_update_time'         => array('type'=>'timestamp(6)'),
		'mgn_delete_time'         => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		// Normalize slug to lowercase alphanumeric + hyphens
		$slug = strtolower(trim($this->get('mgn_slug')));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$this->set('mgn_slug', $slug);

		if (empty($slug)) {
			throw new ManagedNodeException('Node slug is required.');
		}

		if (empty($this->get('mgn_name'))) {
			throw new ManagedNodeException('Node name is required.');
		}

		if (empty($this->get('mgn_host'))) {
			throw new ManagedNodeException('SSH host is required.');
		}

		// Check for duplicate slug
		$existing = new MultiManagedNode(array('slug' => $slug, 'deleted' => false));
		$existing->load();
		foreach ($existing as $ex) {
			if ($ex->key != $this->key) {
				throw new ManagedNodeException('A node with this slug already exists.');
			}
		}

		$this->set('mgn_update_time', gmdate('Y-m-d H:i:s'));
	}
}

class MultiManagedNode extends SystemMultiBase {
	protected static $model_class = 'ManagedNode';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['slug'])) {
			$filters['mgn_slug'] = [$this->options['slug'], PDO::PARAM_STR];
		}

		if (isset($this->options['host'])) {
			$filters['mgn_host'] = [$this->options['host'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['mgn_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['mgn_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('mgn_managed_nodes', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
