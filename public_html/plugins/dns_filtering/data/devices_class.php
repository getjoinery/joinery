<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/device_backups_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));

class SdDeviceException extends SystemBaseException {}

class SdDevice extends SystemBase {

	public static $prefix = 'sdd';
	public static $tablename = 'sdd_devices';
	public static $pkey_column = 'sdd_device_id';

	protected static $foreign_key_actions = [
		'sdd_usr_user_id' => ['action' => 'permanent_delete'],
	];

	public static $field_specifications = array(
	    'sdd_device_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sdd_device_name' => array('type'=>'varchar(64)'),
	    'sdd_device_type' => array('type'=>'varchar(32)'),
	    'sdd_usr_user_id' => array('type'=>'int4'),
	    'sdd_is_active' => array('type'=>'bool'),
	    'sdd_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'sdd_delete_time' => array('type'=>'timestamp(6)'),
	    'sdd_deactivation_pin' => array('type'=>'varchar(10)'),
	    'sdd_timezone' => array('type'=>'varchar(64)'),
	    'sdd_allow_device_edits' => array('type'=>'int4'),
	    'sdd_activate_time' => array('type'=>'timestamp(6)'),
	    'sdd_resolver_uid' => array('type'=>'varchar(32)'),
	    'sdd_log_queries' => array('type'=>'bool', 'default'=>'false'),
	);

	function prepare() {}

	function authenticate_read($data) {
		if ($this->get('sdd_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to see this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
	}

	function authenticate_write($data) {
		if ($this->get('sdd_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
	}

	static function createDevice($device, $user, $post_vars){
		$deactivation_pin = rand(100000, 999999);

		$device_name = 'user'.$user->key . '-' . trim(preg_replace("/[^a-zA-Z0-9\s'-]/", "", $post_vars['device_name']));
		$device_type = trim(preg_replace("/[^a-zA-Z0-9\s'-]/", "", $post_vars['device_type']));

		// Generate a unique 32-char hex resolver UID for DoH/DoT routing
		$resolver_uid = bin2hex(random_bytes(16));

		$device->set('sdd_timezone', strip_tags($post_vars['sdd_timezone']));
		$device->set('sdd_usr_user_id', $user->key);
		$device->set('sdd_is_active', true);
		$device->set('sdd_allow_device_edits', $post_vars['sdd_allow_device_edits']);
		$device->set('sdd_deactivation_pin', $deactivation_pin);
		$device->set('sdd_device_type', $device_type);
		$device->set('sdd_device_name', $device_name);
		$device->set('sdd_resolver_uid', $resolver_uid);
		$device->set('sdd_activate_time', 'now()');

		$device->prepare();
		$device->save();

		// Every device gets an always-on block for its baseline policy
		SdScheduledBlock::getOrCreateAlwaysOnBlock($device->key);

		return $device;
	}

	// No external activation needed - devices are always active once created
	function check_activate(){
		return true;
	}

	function get_readable_name(){
		return preg_replace('/^user\d+-/', '', $this->get('sdd_device_name'));
	}

	// Sunday-only impulse-lock for the recovery audience: prevents a user from
	// removing their own filters at 2am. Opt out via sdd_allow_device_edits.
	// Edits are permitted today if the device was just created or just activated.
	function are_filters_editable(){
		if($this->get('sdd_allow_device_edits')){
			return true;
		}

		if($this->get('sdd_activate_time')){
			$current_timestamp = time();
			$future_24_hours = $current_timestamp + (24 * 60 * 60);
			if($this->get('sdd_activate_time') > $current_timestamp && $this->get('sdd_activate_time') <= $future_24_hours){
				return true;
			}
		}

		if(date('Y-m-d', strtotime($this->get('sdd_create_time'))) === date("Y-m-d")){
			return true;
		}

		$weekday = 'Sunday';
		if($this->get('sdd_timezone')){
			$timezone = $this->get('sdd_timezone');
		}
		else{
			$timezone = 'America/New_York';
		}

		date_default_timezone_set($timezone);

		$weekday = strtolower($weekday);
		$currentDay = strtolower(date('l'));
		return $weekday === $currentDay;
	}

	function permanent_delete($debug = false){
		// Delete all scheduled blocks for this device
		$blocks = new MultiSdScheduledBlock(['device_id' => $this->key]);
		$blocks->load();
		foreach ($blocks as $block) {
			$block->permanent_delete();
		}

		// Save deactivation pin history
		$device_backup = new SdDeviceBackup(NULL);
		$device_backup->set('sddb_device_backup_name', $this->get('sdd_device_name'));
		$device_backup->set('sddb_usr_user_id', $this->get('sdd_usr_user_id'));
		$device_backup->set('sddb_deactivation_pin', $this->get('sdd_deactivation_pin'));
		$device_backup->save();

		parent::permanent_delete();
		return true;
	}

}

class MultiSdDevice extends SystemMultiBase {
	protected static $model_class = 'SdDevice';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $sddevice) {
			$items[$sddevice->key] = '('.$sddevice->key.') '.$sddevice->get('sdd_device_name');
		}
		if ($include_new) {
			$items['Enter New Below'] = 'new';
		}
		return $items;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['sdd_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['active'])) {
            $filters['sdd_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['deleted'])) {
            $filters['sdd_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('sdd_devices', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
