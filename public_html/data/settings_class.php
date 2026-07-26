<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class SettingException extends SystemBaseException {}

class Setting extends SystemBase {	public static $prefix = 'stg';

	// REST API: settings may hold sensitive config/secrets — admin-only (permission >= 5) read via the API.
	function authenticate_read($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
		}
	}
	public static $tablename = 'stg_settings';
	public static $pkey_column = 'stg_setting_id';

	protected static $foreign_key_actions = [
		'stg_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
	];
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'stg_setting_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'stg_name' => array('type'=>'varchar(100)', 'required'=>true, 'unique'=>true),
	    'stg_value' => array('type'=>'text'),
	    'stg_group_name' => array('type'=>'varchar(255)'),
	    'stg_usr_user_id' => array('type'=>'int4'),
	    'stg_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'stg_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);	

private function _check_for_duplicate_setting() {
		
		$settings = Globalvars::get_instance();
		if($settings->get_setting($this->get('stg_name'))){
			return true;
		}
		
		$count = new MultiSetting(array(
			'setting_name' => $this->get('stg_name'),
		));
		
		if ($count->count_all() > 0) {
						echo 'duplicate';
			exit();
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}		

	function prepare() {
		
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->_check_for_duplicate_setting()){
				throw new SettingException(
				'This setting already exists');
			}
		}

	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	/**
	 * Names that are never settings, however they arrive.
	 *
	 * The settings pages save by walking the POST, so every field a browser
	 * submits is a candidate setting — including the ones the form machinery
	 * puts there itself. Without this boundary those become rows: a CSRF token,
	 * a submit button and a captcha response all ended up stored as settings and
	 * re-written on every save.
	 *
	 * Four families:
	 *   - request/form infrastructure that rides along in any POST
	 *   - `submit_*` buttons. A page with several independent forms names each
	 *     button after its section (`submit_vault`, `submit_store`), so the
	 *     fixed list cannot cover them — the prefix can.
	 *   - `clear__*` checkboxes, which tell the save to wipe a credential. An
	 *     instruction about a setting, never a setting.
	 *   - `*_readonly` display mirrors of Globalvars_site.php values, which are
	 *     rendered readonly and post their value straight back. They are output,
	 *     not input — the real setting is the name without the suffix.
	 *
	 * @param string $name candidate stg_name
	 * @return bool true when the name must never be written as a setting
	 */
	public static function isReservedName(string $name): bool {
		static $reserved = array(
			'_csrf_token',            // FormWriterV2Base 'csrf_field' default
			'__route',                // serve.php rewrite parameter
			'edit_primary_key_value', // FormWriter record-id hidden field
			'plugin_settings_target', // Plugin Settings tab section marker
			'g-recaptcha-response',   // reCAPTCHA widget
			'h-captcha-response',     // hCaptcha widget
			'submit_button',
			'btn_submit',
		);
		if (in_array($name, $reserved, true)) {
			return true;
		}
		// No real setting is named submit_*, and every per-section submit
		// button is. Narrow enough to be safe, broad enough to cover a button
		// naming scheme that is generated rather than written down.
		if (strncmp($name, 'submit_', 7) === 0) {
			return true;
		}
		// clear__<setting> is the "wipe this credential" checkbox that rides
		// alongside a secret field. It is an instruction about a setting, not a
		// setting — SettingsWriter reads it and it is never stored.
		if (strncmp($name, 'clear__', 7) === 0) {
			return true;
		}
		return substr($name, -9) === '_readonly';
	}

	/**
	 * Bulk-insert declared default settings, skipping any stg_name that
	 * already exists. Used by PluginManager (plugin.json settings array) and
	 * update_database (core settings.json). Seed-only — never overwrites.
	 *
	 * @param array $declarations list of [['name' => ..., 'default' => ...], ...]
	 */
	public static function seed_declared(array $declarations): void {
		if (empty($declarations)) return;

		$dblink = DbConnector::get_instance()->get_db_link();
		$sql = "INSERT INTO stg_settings
					(stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
				VALUES (?, ?, 1, NOW(), NOW(), 'general')
				ON CONFLICT (stg_name) DO NOTHING";
		$stmt = $dblink->prepare($sql);

		foreach ($declarations as $i => $d) {
			if (!is_array($d) || !array_key_exists('name', $d) || !is_string($d['name']) || $d['name'] === '') {
				throw new InvalidArgumentException("Setting::seed_declared: declaration[$i] missing or invalid 'name' field");
			}
			$stmt->execute([$d['name'], $d['default'] ?? '']);
		}
	}

	/**
	 * Delete settings rows whose names appear in $declarations.
	 *
	 * Used during plugin uninstall. Deletes only the currently-declared names —
	 * orphan rows from previously-declared-but-now-dropped settings are left
	 * in place by design.
	 *
	 * @param array $declarations list of [['name' => ..., ...], ...]
	 */
	public static function unseed_declared(array $declarations): void {
		if (empty($declarations)) return;

		$names = [];
		foreach ($declarations as $i => $d) {
			if (!is_array($d) || !array_key_exists('name', $d) || !is_string($d['name']) || $d['name'] === '') {
				throw new InvalidArgumentException("Setting::unseed_declared: declaration[$i] missing or invalid 'name' field");
			}
			$names[] = $d['name'];
		}
		if (empty($names)) return;

		$dblink = DbConnector::get_instance()->get_db_link();
		$placeholders = implode(',', array_fill(0, count($names), '?'));
		$sql = "DELETE FROM stg_settings WHERE stg_name IN ({$placeholders})";
		$stmt = $dblink->prepare($sql);
		$stmt->execute($names);
	}

}

class MultiSetting extends SystemMultiBase {
	protected static $model_class = 'Setting';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('stg_name');
			$items[$entry->key] = $option_display;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['setting_id'])) {
            $filters['stg_setting_id'] = [$this->options['setting_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['setting_name'])) {
            $filters['stg_name'] = [$this->options['setting_name'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('stg_settings', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
