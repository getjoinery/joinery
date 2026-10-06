<?php
/**
 * MachineTransfer — one cloud machine's outbound transfer this billing month,
 * as its provider counts it (specs/node_outbound_and_transfer.md WP1).
 *
 * A provider adds every machine's allowance to one account pool and bills
 * overage against the pool, so the pool alone hides one machine running far
 * past its own share. MachineTransferWatch reads each machine the plane's
 * nodes run on, once a day, and keeps one row per machine: the figure and the
 * read before it (what a day's rate is worked out from). Nodes point at their
 * machine's row (mgn_mtr_machine_transfer_id); several sites on one server
 * share one row. The conditions on it (a day far above its share, a month on
 * pace past its allowance, the allowance passed) are incidents, raised by
 * IncidentSourceMachineTransfer on the one node that speaks for the machine
 * (mtr_mgn_managed_node_id), so a server of twelve sites raises one, not twelve.
 *
 * A machine that leaves the account (deleted at the provider) keeps its row:
 * its nodes unlink at the next good listing, and a reading over three days old
 * raises nothing, so the row is history, not a source of alerts.
 *
 * The figure is the provider's, not the machine's interface counter: Linode
 * counts about the payload, and leaves out traffic to another machine in the
 * same data center over IPv6.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MachineTransferException extends SystemBaseException {}

class MachineTransfer extends SystemBase {
	public static $prefix = 'mtr';
	public static $tablename = 'mtr_machine_transfers';
	public static $pkey_column = 'mtr_machine_transfer_id';

	protected static $foreign_key_actions = array(
		'mtr_mgn_managed_node_id' => array('action' => 'null'),
	);

	public static $test_fixture = array(
		'values'       => array('mtr_provider' => 'linode', 'mtr_instance_id' => '1', 'mtr_account' => 'operator'),
		'update_field' => 'mtr_label',
	);

	public static $field_specifications = array(
		'mtr_machine_transfer_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mtr_provider'      => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'linode',
			'unique_with'=>array('mtr_instance_id')),
		'mtr_instance_id'   => array('type'=>'varchar(50)', 'is_nullable'=>false),
		// Which credential reads it: 'operator', or 'cca:<id>' for a connected account.
		'mtr_account'       => array('type'=>'varchar(30)', 'is_nullable'=>false),
		'mtr_label'         => array('type'=>'varchar(255)'),
		'mtr_region'        => array('type'=>'varchar(40)'),
		// When the provider created the machine: a machine created this month
		// has an allowance for the days it exists, not the whole month.
		'mtr_instance_created_time' => array('type'=>'timestamp(6)'),
		// The billing month the figures belong to, 'YYYY-MM' (UTC).
		'mtr_period'        => array('type'=>'varchar(7)'),
		'mtr_used_bytes'    => array('type'=>'int8'),
		'mtr_quota_gb'      => array('type'=>'numeric(12,2)'),
		'mtr_billable_gb'   => array('type'=>'numeric(12,2)'),
		'mtr_read_time'     => array('type'=>'timestamp(6)'),
		// The read before, in the same period: a day's rate is the difference.
		'mtr_prev_used_bytes' => array('type'=>'int8'),
		'mtr_prev_read_time'  => array('type'=>'timestamp(6)'),
		// The node the machine's incidents are raised on: the server's own
		// node where it has one, else its lowest-numbered site.
		'mtr_mgn_managed_node_id' => array('type'=>'int8'),
		// The last read's failure, cleared by a good read.
		'mtr_error'         => array('type'=>'text'),
		'mtr_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mtr_update_time'   => array('type'=>'timestamp(6)'),
	);

	function save($debug = false) {
		if (trim((string)$this->get('mtr_instance_id')) === '') {
			throw new MachineTransferException('A machine transfer row names its instance.');
		}
		$this->set('mtr_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** The row for a provider's instance, or null. */
	public static function for_instance(string $provider, string $instance_id): ?MachineTransfer {
		foreach (new MultiMachineTransfer(array('provider' => $provider, 'instance_id' => $instance_id)) as $row) {
			return $row;
		}
		return null;
	}

	/** Gigabytes used this period (decimal GB, as the provider bills). */
	public function used_gb(): float {
		return (int)$this->get('mtr_used_bytes') / 1e9;
	}
}

class MultiMachineTransfer extends SystemMultiBase {
	protected static $model_class = 'MachineTransfer';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['provider'])) {
			$filters['mtr_provider'] = array((string)$this->options['provider'], PDO::PARAM_STR);
		}
		if (isset($this->options['instance_id'])) {
			$filters['mtr_instance_id'] = array((string)$this->options['instance_id'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('mtr_machine_transfers', $filters, $this->order_by, $only_count, $debug);
	}
}
