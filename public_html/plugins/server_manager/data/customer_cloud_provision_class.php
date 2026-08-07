<?php
/**
 * CustomerCloudProvision - One cloud-instance provision, request to running site.
 *
 * Two origins (cvp_origin):
 *   order - created by PollHostingOrders when a paid order's product declares
 *           pro_fulfillment_provider = 'customer_cloud'; carries the order
 *           item linkage that drives the buyer welcome email.
 *   admin - created by the Install New Node form's cloud-instance target;
 *           no order item, no welcome email.
 *
 * Install parameters travel on the row (cvp_docker_mode, cvp_install_mode,
 * cvp_source_node_id, cvp_backup_source, cvp_port) and are honored by the
 * ProvisionCustomerCloud scheduled task, which advances every provision.
 *
 * Status flow:
 *   pending_connect - waiting for the buyer's OAuth grant (Connect page)
 *   ready           - grant available; instance not yet created
 *   booting         - instance created on the customer's account; waiting for
 *                     it to reach running + have an IP
 *   installing      - managed node created, install_node job dispatched
 *   done            - install succeeded (SSL + welcome email flow from there
 *                     is the standard pipeline)
 *   failed          - terminal; cvp_error says why. Admin alert sent.
 *
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class CustomerCloudProvisionException extends SystemBaseException {}

class CustomerCloudProvision extends SystemBase {
	public static $prefix = 'cvp';
	public static $tablename = 'cvp_customer_cloud_provisions';
	public static $pkey_column = 'cvp_id';

	protected static $foreign_key_actions = array(
		'cvp_usr_user_id'    => array('action' => 'prevent', 'message' => 'this user has cloud provisions - deprovision them first'),
		'cvp_cca_account_id' => array('action' => 'null'),
		'cvp_mgn_node_id'    => array('action' => 'null'),
	);

	// Admin origin needs no order item, so spec-generated test rows validate;
	// the update test must mutate a free-form field, not a code-enforced enum.
	public static $test_fixture = array(
		'values'       => array('cvp_origin' => 'admin'),
		'update_field' => 'cvp_domain',
	);

	public static $field_specifications = array(
		'cvp_id'                     => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'cvp_origin'                 => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'order', 'allowed_values'=>array('order', 'admin')),
		'cvp_external_order_item_id' => array('type'=>'int8', 'unique'=>true),
		'cvp_usr_user_id'            => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		'cvp_domain'                 => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'cvp_slug'                   => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false),
		'cvp_sitename'               => array('type'=>'varchar(50)'),
		'cvp_buyer_email'            => array('type'=>'varchar(255)'),
		'cvp_buyer_name'             => array('type'=>'varchar(255)'),
		'cvp_status'                 => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending_connect'),
		'cvp_cca_account_id'         => array('type'=>'int8'),
		'cvp_provider'               => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'linode'),
		'cvp_instance_id'            => array('type'=>'varchar(50)'),
		'cvp_instance_ip'            => array('type'=>'varchar(64)'),
		'cvp_region'                 => array('type'=>'varchar(50)'),
		'cvp_instance_type'          => array('type'=>'varchar(50)'),
		'cvp_mgn_node_id'            => array('type'=>'int8'),
		'cvp_docker_mode'            => array('type'=>'varchar(12)', 'is_nullable'=>false, 'default'=>'docker', 'allowed_values'=>array('docker', 'bare-metal')),
		'cvp_install_mode'           => array('type'=>'varchar(12)', 'is_nullable'=>false, 'default'=>'fresh', 'allowed_values'=>array('fresh', 'from_backup', 'bare')),
		'cvp_source_node_id'         => array('type'=>'int8'),
		'cvp_backup_source'          => array('type'=>'varchar(10)'),
		'cvp_port'                   => array('type'=>'int4', 'is_nullable'=>false, 'default'=>8080),
		'cvp_error'                  => array('type'=>'text'),
		'cvp_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'cvp_update_time'            => array('type'=>'timestamp(6)'),
		'cvp_delete_time'            => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->validate_row();
		$this->set('cvp_update_time', gmdate('Y-m-d H:i:s'));
	}

	// Validation lives in save(): prepare() is not guaranteed to run first.
	function save($debug = false) {
		$this->validate_row();
		$this->set('cvp_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	private function validate_row() {
		$origin = $this->get('cvp_origin') ?: 'order';
		if (!in_array($origin, array('order', 'admin'), true)) {
			throw new CustomerCloudProvisionException("Unknown origin '{$origin}'.");
		}
		if ($origin === 'order' && empty($this->get('cvp_external_order_item_id'))) {
			throw new CustomerCloudProvisionException('Order item id is required for order-origin provisions.');
		}
		$docker_mode = $this->get('cvp_docker_mode') ?: 'docker';
		if (!in_array($docker_mode, array('docker', 'bare-metal'), true)) {
			throw new CustomerCloudProvisionException("Unknown docker mode '{$docker_mode}'.");
		}
		$install_mode = $this->get('cvp_install_mode') ?: 'fresh';
		if (!in_array($install_mode, array('fresh', 'from_backup', 'bare'), true)) {
			throw new CustomerCloudProvisionException("Unknown install mode '{$install_mode}'.");
		}
		if ($install_mode === 'from_backup' && empty($this->get('cvp_source_node_id'))) {
			throw new CustomerCloudProvisionException('Source node is required for from-backup provisions.');
		}
		// A bare instance (no site install) has no order to fulfill — it exists
		// for infrastructure roles like relay shards, which only admins create.
		if ($install_mode === 'bare' && $origin !== 'admin') {
			throw new CustomerCloudProvisionException('Bare provisions must be admin-origin.');
		}
		if (empty($this->get('cvp_usr_user_id'))) {
			throw new CustomerCloudProvisionException('User is required.');
		}
		if (empty($this->get('cvp_domain'))) {
			throw new CustomerCloudProvisionException('Domain is required.');
		}
	}

	/**
	 * Record a terminal failure. Saves.
	 */
	public function fail($message) {
		$this->set('cvp_status', 'failed');
		$this->set('cvp_error', mb_substr((string)$message, 0, 4000));
		$this->save();
	}
}

class MultiCustomerCloudProvision extends SystemMultiBase {
	protected static $model_class = 'CustomerCloudProvision';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['cvp_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['status'])) {
			$filters['cvp_status'] = [$this->options['status'], PDO::PARAM_STR];
		}

		if (isset($this->options['statuses']) && is_array($this->options['statuses']) && count($this->options['statuses'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['statuses']);
			$filters['cvp_status'] = "IN (" . implode(',', $quoted) . ")";
		}

		if (isset($this->options['external_order_item_id'])) {
			$filters['cvp_external_order_item_id'] = [$this->options['external_order_item_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['account_id'])) {
			$filters['cvp_cca_account_id'] = [$this->options['account_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['node_id'])) {
			$filters['cvp_mgn_node_id'] = [$this->options['node_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['cvp_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('cvp_customer_cloud_provisions', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
