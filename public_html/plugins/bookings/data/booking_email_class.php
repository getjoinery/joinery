<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class BookingEmailException extends SystemBaseException {}

class BookingEmail extends SystemBase {
	public static $prefix = 'bke';
	public static $tablename = 'bke_booking_emails';
	public static $pkey_column = 'bke_booking_email_id';

	public static $field_specifications = array(
		'bke_booking_email_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'bke_bkn_booking_id' => array('type'=>'int8', 'is_nullable'=>false, 'required'=>true),
		'bke_kind' => array('type'=>'varchar(16)', 'is_nullable'=>false, 'required'=>true),
		'bke_offset_minutes' => array('type'=>'int4'),
		'bke_booking_start_time' => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'required'=>true),
		'bke_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'bke_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// What happens to these rows when a referenced parent is deleted — docs/deletion_system.md
	protected static $foreign_key_actions = array(
		'bke_bkn_booking_id' => array(
			'action' => 'cascade'
		)
	);

	// Cleanup when permanent_delete() runs on a row of this model — docs/deletion_system.md

	// Business-rule extension point. TODO: add cross-field validation, computed
	// export_as_array() keys, or relationship loading here. Override prepare()
	// for validation (docs/validation.md); note prepare() is not guaranteed to
	// run before save() — mandatory transforms belong in save().
	// function prepare() {
	//     $result = parent::prepare();
	//     // ... your checks; set $result['success'] = false and append messages on failure ...
	//     return $result;
	// }
}

class MultiBookingEmail extends SystemMultiBase {
	protected static $model_class = 'BookingEmail';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['booking_id'])) {
			$filters['bke_bkn_booking_id'] = [$this->options['booking_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['kind'])) {
			$filters['bke_kind'] = [$this->options['kind'], PDO::PARAM_STR];
		}
		if (isset($this->options['deleted'])) {
			$filters['bke_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('bke_booking_emails', $filters, $this->order_by, $only_count, $debug);
	}
}
?>