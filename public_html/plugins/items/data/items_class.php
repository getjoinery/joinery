<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

require_once(LibraryFunctions::get_plugin_file_path('item_relations_class.php', 'items', '/data', 'system'));

class ItemException extends SystemClassException {}

class Item extends SystemBase {
	public static $prefix = 'itm';
	public static $tablename = 'itm_items';
	public static $pkey_column = 'itm_item_id';
	public static $url_namespace = 'item';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(
		'itm_item_id' => 'delete',	
		'itr_item_id_left' => 'delete',
		'itr_item_id_right' => 'delete',
		//'com_itm_item_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'itm_item_id' => 'ID of the url',
		'itm_name' => 'Name of item',
		'itm_description' => 'Name of item',
		'itm_link' => 'Link to the item',
		'itm_body' => 'Body of this item',
		'itm_usr_user_id' => 'User this item is associated with',
		'itm_published_time' => 'Time published',
		'itm_create_time' => 'Time Created',
		'itm_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'itm_item_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'itm_name' => array('type'=>'varchar(255)'),
		'itm_description' => array('type'=>'varchar(255)'),
		'itm_link' => array('type'=>'varchar(255)'),
		'itm_body' => array('type'=>'text'),
		'itm_usr_user_id' => array('type'=>'int4'),
		'itm_published_time' => array('type'=>'timestamp(6)'),
		'itm_create_time' => array('type'=>'timestamp(6)'),
		'itm_delete_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('itm_create_time' => 'now()'
		);				

	
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
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Item($row->itm_item_id);
            $child->load_from_data($row, array_keys(Item::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}


?>
