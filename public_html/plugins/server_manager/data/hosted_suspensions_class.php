<?php
/**
 * HostedSuspension — one time a person suspended a hosted site, and when it
 * was lifted.
 *
 * A row is opened by Suspend and closed by Unsuspend (HostedSuspension
 * actions live in HostedSuspensions). An open row (no hsu_unsuspend_time) is
 * the site's current suspension; at most one is open per site. Closed rows
 * stay as the history of what was done to whom, and why.
 *
 * hsu_saved holds what Suspend switched off on the site's node (its backup
 * policy and uptime check) so Unsuspend puts back exactly what was there.
 * hsu_steps is what each action did and what it could not do, one line each,
 * for the person reading the node page.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class HostedSuspensionException extends SystemBaseException {}

class HostedSuspension extends SystemBase {
	public static $prefix = 'hsu';
	public static $tablename = 'hsu_hosted_suspensions';
	public static $access_class = 'config';
	public static $pkey_column = 'hsu_hosted_suspension_id';

	protected static $foreign_key_actions = array(
		'hsu_cvp_customer_cloud_provision_id' => array('action' => 'cascade'),
	);

	public static $test_fixture = array(
		'update_field' => 'hsu_reason',
	);

	public static $field_specifications = array(
		'hsu_hosted_suspension_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'hsu_cvp_customer_cloud_provision_id' => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		'hsu_reason'                          => array('type'=>'varchar(500)', 'required'=>true, 'is_nullable'=>false),
		'hsu_suspend_usr_user_id'             => array('type'=>'int8'),
		'hsu_suspend_time'                    => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'hsu_unsuspend_usr_user_id'           => array('type'=>'int8'),
		'hsu_unsuspend_time'                  => array('type'=>'timestamp(6)'),
		'hsu_hold_job_id'                     => array('type'=>'int8'),
		'hsu_saved'                           => array('type'=>'jsonb'),
		'hsu_steps'                           => array('type'=>'jsonb'),
		'hsu_create_time'                     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'hsu_update_time'                     => array('type'=>'timestamp(6)', 'stamp_on_save'=>true),
		'hsu_delete_time'                     => array('type'=>'timestamp(6)'),
	);

	function save($debug = false) {
		if (!$this->get('hsu_cvp_customer_cloud_provision_id')) {
			throw new HostedSuspensionException('A suspension belongs to a hosted site.');
		}
		if (trim((string)$this->get('hsu_reason')) === '') {
			throw new HostedSuspensionException('A suspension says why.');
		}
		return parent::save($debug);
	}

	/** The site's open suspension, or null when it is not suspended. */
	public static function open_for($provision_id): ?HostedSuspension {
		$provision_id = (int)$provision_id;
		if (!$provision_id) { return null; }
		$rows = new MultiHostedSuspension(array('provision_id' => $provision_id, 'open' => true, 'deleted' => false),
			array('hsu_hosted_suspension_id' => 'DESC'));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** The lines hsu_steps holds, oldest first. */
	public function steps(): array {
		$steps = $this->get('hsu_steps');
		$steps = is_string($steps) ? json_decode($steps, true) : $steps;
		return is_array($steps) ? $steps : array();
	}

	public function add_steps(array $lines): void {
		$this->set('hsu_steps', json_encode(array_values(array_merge($this->steps(), $lines))));
	}

	/** What Suspend switched off, as it saved it. */
	public function saved(): array {
		$saved = $this->get('hsu_saved');
		$saved = is_string($saved) ? json_decode($saved, true) : $saved;
		return is_array($saved) ? $saved : array();
	}
}

class MultiHostedSuspension extends SystemMultiBase {
	protected static $model_class = 'HostedSuspension';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['provision_id'])) {
			$filters['hsu_cvp_customer_cloud_provision_id'] = array((int)$this->options['provision_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['open'])) {
			$filters['hsu_unsuspend_time'] = $this->options['open'] ? 'IS NULL' : 'IS NOT NULL';
		}
		return $this->_get_resultsv2('hsu_hosted_suspensions', $filters, $this->order_by, $only_count, $debug);
	}
}
