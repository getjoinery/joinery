<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class BookingTypeException extends SystemBaseException {}

class BookingType extends SystemBase {

	public static $prefix = 'bkt';
	public static $tablename = 'bkt_booking_types';
	public static $pkey_column = 'bkt_booking_type_id';

	const BOOKING_STATUS_INACTIVE = 0;
	const BOOKING_STATUS_ACTIVE = 1;

	public static $field_specifications = array(
	    'bkt_booking_type_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    // Provider: native is the only shipped implementation; externals slot in later.
	    'bkt_provider' => array('type'=>'varchar(32)', 'default'=>'native'),
	    'bkt_external_type_uri' => array('type'=>'varchar(255)'),
	    // Host: availability is this user's one schedule.
	    'bkt_usr_user_id' => array('type'=>'int8'),
	    'bkt_pro_product_id' => array('type'=>'int4'),
	    'bkt_svy_survey_id' => array('type'=>'int8'),
	    'bkt_name' => array('type'=>'varchar(255)'),
	    'bkt_slug' => array('type'=>'varchar(255)', 'unique'=>true),
	    'bkt_description_html' => array('type'=>'text'),
	    'bkt_description_plain' => array('type'=>'text'),
	    'bkt_status' => array('type'=>'int4', 'zero_on_create'=>true),
	    // Slot shape.
	    'bkt_duration_minutes' => array('type'=>'int4'),
	    'bkt_slot_increment_minutes' => array('type'=>'int4', 'default'=>30),
	    'bkt_buffer_before_minutes' => array('type'=>'int4', 'default'=>0),
	    'bkt_buffer_after_minutes' => array('type'=>'int4', 'default'=>0),
	    'bkt_min_notice_minutes' => array('type'=>'int4', 'default'=>240),
	    'bkt_rolling_days' => array('type'=>'int4', 'default'=>60),
	    'bkt_window_start' => array('type'=>'date'),
	    'bkt_window_end' => array('type'=>'date'),
	    'bkt_max_per_day' => array('type'=>'int4'),
	    'bkt_max_per_week' => array('type'=>'int4'),
	    // Location.
	    'bkt_location_mode' => array('type'=>'varchar(32)'),
	    'bkt_location_details' => array('type'=>'text'),
	    // Cancellation policy.
	    'bkt_cancel_notice_minutes' => array('type'=>'int4'),
	    'bkt_cancellation_policy_text' => array('type'=>'text'),
	    // Reminders / follow-ups.
	    'bkt_send_native_emails' => array('type'=>'bool', 'default'=>true),
	    'bkt_reminder_minutes_csv' => array('type'=>'varchar(64)', 'default'=>'1440,60'),
	    'bkt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'bkt_delete_time' => array('type'=>'timestamp(6)'),
	    'bkt_update_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();

	// A survey attached to a booking type for response collection is
	// optional - deleting the survey should just detach it, not delete the
	// booking type.
	protected static $foreign_key_actions = [
		'bkt_svy_survey_id' => ['action' => 'null'],
		'bkt_usr_user_id' => ['action' => 'permanent_delete'],
		'bkt_pro_product_id' => ['action' => 'null'],
	];

	/** Resolve a booking type by its globally-unique public slug. */
	static function GetBySlug($slug) {
		$results = new MultiBookingType(array('slug' => $slug, 'deleted' => false));
		$results->load();
		return count($results) ? $results->get(0) : false;
	}

	function is_active() {
		return (int)$this->get('bkt_status') === self::BOOKING_STATUS_ACTIVE;
	}

	/** Reminder offsets (minutes before start) parsed from the CSV config. */
	function reminder_offsets() {
		$csv = $this->get('bkt_reminder_minutes_csv');
		if (!$csv) { return array(); }
		$out = array();
		foreach (explode(',', $csv) as $part) {
			$n = (int)trim($part);
			if ($n > 0) { $out[] = $n; }
		}
		return $out;
	}

	function authenticate_write($data) {
		if ($this->get('bkt_usr_user_id') != $data['current_user_id']
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiBookingType extends SystemMultiBase {
	protected static $model_class = 'BookingType';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['bkt_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['slug'])) {
            $filters['bkt_slug'] = [$this->options['slug'], PDO::PARAM_STR];
        }

        if (isset($this->options['status'])) {
            $filters['bkt_status'] = [$this->options['status'], PDO::PARAM_INT];
        }

        if (isset($this->options['active'])) {
            $filters['bkt_status'] = "= " . ($this->options['active'] ? '1' : '0');
        }

        if (isset($this->options['provider'])) {
            $filters['bkt_provider'] = [$this->options['provider'], PDO::PARAM_STR];
        }


        return $this->_get_resultsv2('bkt_booking_types', $filters, $this->order_by, $only_count, $debug);
    }

}
