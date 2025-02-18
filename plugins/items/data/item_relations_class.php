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

require_once(LibraryFunctions::get_plugin_file_path('items_class.php', 'items', '/data', 'system'));

class ItemRelationException extends SystemClassException {}

class ItemRelation extends SystemBase {
	public static $prefix = 'itr';
	public static $tablename = 'itr_item_relations';
	public static $pkey_column = 'itr_item_relation_id';
	public static $url_namespace = 'item_relation';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(
		'itr_item_relation_id' => 'delete',	
		//'pac_itr_item_relation_id' => 'delete',
		//'com_itr_item_relation_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'itr_item_relation_id' => 'ID of the url',
		'itr_itm_item_id_left' => 'Name of item_relation',
		'itr_itm_item_id_right' => 'Name of item_relation',
		'itr_external_link' => 'External link if no right relation',
		'itr_itt_item_relation_type_id' => 'Type to the item_relation',
		'itr_usr_user_id' => 'User this item_relation is associated with',
		'itr_published_time' => 'Time published',
		'itr_create_time' => 'Time Created',
		'itr_delete_time' => 'Time of deletion',
	);

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
		
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate('itr_link')){
			throw new SystemAuthenticationError(
					'This item_relation link is a duplicate.');
		}
		
		parent::save($debug);
	}
	
}

class MultiItemRelation extends SystemMultiBase {


	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'itr_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}

		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'itr_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM itr_item_relations ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM itr_item_relations
				' . $where_clause . '
				ORDER BY ';
			
			if (empty($this->order_by)) {
				$sql .= " itr_item_relation_id ASC ";
			}
			else {
				if (array_key_exists('item_relation_id', $this->order_by)) {
					$sql .= ' itr_item_relation_id ' . $this->order_by['item_relation_id'];
				}			
			}
				
			$sql .= ' '.$this->generate_limit_and_offset();	

		}			
		

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Item($row->itr_item_relation_id);
			$child->load_from_data($row, array_keys(Item::$fields));
			$this->add($child);
		}
	}
}


?>
