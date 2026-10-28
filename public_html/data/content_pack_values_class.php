<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * The value a content pack last established for one field of one record, or
 * for one setting. A pack's check reads it to tell a field the pack still
 * holds (a change it may make) from one somebody edited on the site since (left
 * alone unless the owner ticks it).
 *
 * cpv_target is "{Model}:{key}" for a record field, "setting" for a setting;
 * cpv_field is the column or the setting's name.
 *
 * See specs/content_pack_feature.md §4.2.
 */
class ContentPackValue extends SystemBase {
	public static $prefix = 'cpv';
	public static $tablename = 'cpv_content_pack_values';
	public static $access_class = 'keys';
	public static $owner_column = false;
	public static $pkey_column = 'cpv_content_pack_value_id';

	// The value outlives the record of the apply that set it: it is still what
	// a pack last established.
	protected static $foreign_key_actions = array(
		'cpv_cpa_content_pack_apply_id' => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'cpv_content_pack_value_id'     => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'cpv_target'                    => array('type'=>'varchar(150)', 'required'=>true, 'unique_with'=>array('cpv_field')),
		'cpv_field'                     => array('type'=>'varchar(100)', 'required'=>true),
		'cpv_value'                     => array('type'=>'text'),
		'cpv_cpa_content_pack_apply_id' => array('type'=>'int8'),
		'cpv_update_time'               => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);
}

class MultiContentPackValue extends SystemMultiBase {
	protected static $model_class = 'ContentPackValue';

	protected function getMultiResults($only_count = false, $debug = false) {
		$sorts = !empty($this->order_by) ? $this->order_by : array();
		return $this->_get_resultsv2('cpv_content_pack_values', array(), $sorts, $only_count, $debug);
	}
}
