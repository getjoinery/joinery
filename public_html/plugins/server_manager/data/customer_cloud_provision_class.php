<?php
/**
 * CustomerCloudProvision - One customer-cloud fulfillment, order to running site.
 *
 * Created by PollHostingOrders when a paid order's product declares
 * pro_fulfillment_provider = 'customer_cloud'. Advanced by the
 * ProvisionCustomerCloud scheduled task.
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
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class CustomerCloudProvisionException extends SystemBaseException {}

class CustomerCloudProvision extends SystemBase {
	public static $prefix = 'cvp';
	public static $tablename = 'cvp_customer_cloud_provisions';
	public static $pkey_column = 'cvp_id';

	public static $permanent_delete_actions = array();

	public static $field_specifications = array(
		'cvp_id'                     => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'cvp_external_order_item_id' => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'cvp_usr_user_id'            => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		'cvp_domain'                 => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'cvp_slug'                   => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false),
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
		'cvp_error'                  => array('type'=>'text'),
		'cvp_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'cvp_update_time'            => array('type'=>'timestamp(6)'),
		'cvp_delete_time'            => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		if (empty($this->get('cvp_external_order_item_id'))) {
			throw new CustomerCloudProvisionException('Order item id is required.');
		}
		if (empty($this->get('cvp_usr_user_id'))) {
			throw new CustomerCloudProvisionException('User is required.');
		}
		if (empty($this->get('cvp_domain'))) {
			throw new CustomerCloudProvisionException('Domain is required.');
		}
		$this->set('cvp_update_time', gmdate('Y-m-d H:i:s'));
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

		if (isset($this->options['deleted'])) {
			$filters['cvp_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('cvp_customer_cloud_provisions', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
