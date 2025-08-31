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
	
	public static $fields = array(
		'itr_item_relation_id' => 'Primary key - ItemRelation ID',
		'itr_itm_item_id_left' => 'Name of item_relation',
		'itr_itm_item_id_right' => 'Name of item_relation',
		'itr_external_link' => 'External link if no right relation',
		'itr_itt_item_relation_type_id' => 'Type to the item_relation',
		'itr_usr_user_id' => 'User this item_relation is associated with',
		'itr_published_time' => 'Time published',
		'itr_create_time' => 'Time Created',
		'itr_delete_time' => 'Time of deletion',
	);
	
/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)'  < /dev/null |  |  'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'itr_item_relation_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'itr_itm_item_id_left' => array('type'=>'int4'),
		'itr_itm_item_id_right' => array('type'=>'int4'),
		'itr_external_link' => array('type'=>'text'),
		'itr_itt_item_relation_type_id' => array('type'=>'int4'),
		'itr_usr_user_id' => array('type'=>'int4'),
		'itr_published_time' => array('type'=>'timestamp(6)'),
		'itr_create_time' => array('type'=>'timestamp(6)'),
		'itr_delete_time' => array('type'=>'timestamp(6)'),
	);

public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('itr_create_time' => 'now()'
		);				

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
