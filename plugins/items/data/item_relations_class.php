<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

require_once(LibraryFunctions::get_plugin_file_path('items_class.php', 'items', '/data', 'system'));

class ItemRelationException extends SystemClassException {}

class ItemRelation extends SystemBase {
	public static $prefix = 'itr';
	public static $tablename = 'itr_item_relations';
	public static $pkey_column = 'itr_item_relation_id';
	public static $url_namespace = 'item_relation';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(
		//'pac_itr_item_relation_id' => 'delete',
		//'com_itr_item_relation_id' => 'null'
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
	    'itr_item_relation_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'itr_itm_item_id_left' => array('type'=>'int4'),
	    'itr_itm_item_id_right' => array('type'=>'int4'),
	    'itr_external_link' => array('type'=>'text'),
	    'itr_itt_item_relation_type_id' => array('type'=>'int4'),
	    'itr_usr_user_id' => array('type'=>'int4'),
	    'itr_published_time' => array('type'=>'timestamp(6)'),
	    'itr_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'itr_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	function save($debug=false) {
		
		parent::save($debug);
	}
	
}

class MultiItemRelation extends SystemMultiBase {
	protected static $model_class = 'ItemRelation';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['deleted'])) {
            $filters['itr_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('itr_item_relations', $filters, $this->order_by, $only_count, $debug);
    }
}

?>
