<?php
/**
 * FleetHandMachine - a machine we pay for that this management node cannot
 * read for itself, written down by hand (specs/fleet_security_patching.md WP8,
 * Q6).
 *
 * On 2026-10-21 three old sites surfaced only because the owner remembered
 * them, and one was not even live. A machine in a cloud account the platform
 * holds no key for (the owner's own Linode account) is listed here by hand, so
 * the list of every machine we pay for has no gap. A row may also speak for a
 * server the platform does list but does not manage (fhm_instance_ref, its
 * account key and instance id): what it is, and why it is not managed.
 *
 * A row is true as of fhm_confirmed_time. The machine list asks for it to be
 * confirmed again after CONFIRM_DAYS, since nothing checks it.
 *
 * @version 1.0
 */

class FleetHandMachineException extends SystemBaseException {}

class FleetHandMachine extends SystemBase {
	public static $prefix = 'fhm';
	public static $tablename = 'fhm_fleet_hand_machines';
	public static $pkey_column = 'fhm_fleet_hand_machine_id';

	/** A row unconfirmed this long is asked about again. */
	const CONFIRM_DAYS = 90;

	public static $field_specifications = array(
		'fhm_fleet_hand_machine_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'fhm_name'                  => array('type'=>'varchar(120)', 'required'=>true, 'is_nullable'=>false),
		// Its public address, as the machine list shows it.
		'fhm_address'               => array('type'=>'varchar(64)'),
		// Where it runs: the provider and account, in words ("Linode, the owner's own account").
		'fhm_where'                 => array('type'=>'varchar(160)'),
		// A listed server this row speaks for: its account key and instance id ("oca:3:12345678").
		'fhm_instance_ref'          => array('type'=>'varchar(120)'),
		'fhm_purpose'               => array('type'=>'varchar(255)'),
		'fhm_owner'                 => array('type'=>'varchar(120)'),
		// Its operating system as written ("Ubuntu 20.04"), read for its support end.
		'fhm_os'                    => array('type'=>'varchar(64)'),
		// The domains it serves; none means nothing is known to depend on it.
		'fhm_serves'                => array('type'=>'varchar(255)'),
		'fhm_retire_by'             => array('type'=>'date'),
		'fhm_notes'                 => array('type'=>'text'),
		'fhm_confirmed_time'        => array('type'=>'timestamp(6)'),
		'fhm_confirmed_by'          => array('type'=>'int8'),
		'fhm_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'fhm_update_time'           => array('type'=>'timestamp(6)'),
		'fhm_delete_time'           => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		'fhm_confirmed_by' => ['action' => 'null', 'source_table' => 'usr_users'],
	];

	/** Write it down as true now. */
	public function confirm($user_id): void {
		$this->set('fhm_confirmed_time', gmdate('Y-m-d H:i:s'));
		$this->set('fhm_confirmed_by', $user_id ?: null);
	}

	public function save($debug = false) {
		$this->set('fhm_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}
}

class MultiFleetHandMachine extends SystemMultiBase {
	protected static $model_class = 'FleetHandMachine';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('fhm_fleet_hand_machines', array(), $this->order_by, $only_count, $debug);
	}
}
