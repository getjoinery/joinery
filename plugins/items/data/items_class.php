<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('plugins/items/data/item_relations_class.php'));

class ItemException extends SystemBaseException {}

class Item extends SystemBase {
	public static $prefix = 'itm';
	public static $tablename = 'itm_items';
	public static $pkey_column = 'itm_item_id';
	public static $url_namespace = 'item';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM

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
	    'itm_item_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'itm_name' => array('type'=>'varchar(255)'),
	    'itm_description' => array('type'=>'varchar(255)'),
	    'itm_link' => array('type'=>'varchar(255)'),
	    'itm_body' => array('type'=>'text'),
	    'itm_usr_user_id' => array('type'=>'int4'),
	    'itm_published_time' => array('type'=>'timestamp(6)'),
	    'itm_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'itm_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	function save($debug=false) {
		
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate('itm_link')){
			throw new SystemAuthenticationError(
					'This item link is a duplicate.');
		}

		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			//TODO MOVE OUT OF CONTENTVERSION
			ContentVersion::NewVersion(ContentVersion::TYPE_ITEM, $this->key, $this->get('itm_body'), $this->get('itm_name'), $this->get('itm_name'));			
		}
		
		parent::save($debug);
	}
	
}

class MultiItem extends SystemMultiBase {
	protected static $model_class = 'Item';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('itm_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function get_dropdown_array_link($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->get('itm_name')] = $item->get_url();
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['link'])) {
            $filters['itm_link'] = [$this->options['link'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['has_link'])) {
            $filters['LENGTH(itm_link)'] = '> 0';
        }
        
        if (isset($this->options['deleted'])) {
            $filters['itm_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('itm_items', $filters, $this->order_by, $only_count, $debug);
    }
}

?>
