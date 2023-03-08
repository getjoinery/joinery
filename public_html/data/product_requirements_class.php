<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');

require_once($siteDir . '/data/products_class.php');


class ProductRequirementsException extends SystemClassException {}
class DisplayableProductRequirementsException extends ProductRequirementsException implements DisplayableErrorMessage {}
class DisplayablePermanentProductRequirementsException extends ProductRequirementsException implements DisplayablePermanentErrorMessage {}


class ProductRequirement extends SystemBase {
	public static $prefix = 'prq';
	public static $tablename = 'prq_product_requirements';
	public static $pkey_column = 'prq_product_requirement_id';
	public static $permanent_delete_actions = array(
		'prq_product_requirement_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'prq_product_requirement_id' => 'event session ID',
		'prq_pro_product_id' => 'Product it is attached to',
		'prq_title' => 'Session title',
		'prq_text' => 'Page content',
		'prq_link' => 'link to something',
		'prq_is_required' => 'Is this required or optional?',
		'prq_order' => 'sort order',
		'prq_vid_video_id' => 'Video attached to this requirement',
		'prq_pic_picture_id' => 'Picture attached to this requirement',
		'prq_srv_survey_id' => 'Survey attached to this requirement',
		'prq_delete_time' => 'Time of deletion',
		); 

	public static $field_specifications = array(
		'prq_product_requirement_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prq_pro_product_id' => array('type'=>'int4'),
		'prq_title' => array('type'=>'varchar(255)'),
		'prq_text' => array('type'=>'text'),
		'prq_link' => array('type'=>'varchar(255)'),
		'prq_is_required' => array('type'=>'bool'),
		'prq_order' => array('type'=>'int2'),
		'prq_vid_video_id' => array('type'=>'int4'),
		'prq_pic_picture_id' => array('type'=>'int4'),
		'prq_srv_survey_id' => array('type'=>'int4'),
		'prq_delete_time' => array('type'=>'timestamp(6)'),
		); 
			 	
	public static $required_fields = array(
		'prq_pro_product_id', 'prq_title'
	);
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'prq_is_required' => FALSE
	);	

	public static $field_constraints = array(
	/*
		'prq_name' => array(
			array('WordLength', 0, 255),
			'NoCaps',
			),
		'prq_description' => array(
			array('WordLength', 50, 100000),
			'NoCaps',
			),
					*/
		);
		
	


	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this item.');
		}
	}	


}

class MultiProductRequirements extends SystemMultiBase {

	function get_sessions_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $session) {
			$event = new Event($session->get('prq_evt_event_id'), TRUE);
			$option_display = $event->get('evt_name').' - '.$session->get('prq_title'); 
			$items[$option_display] = $session->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}




	function _get_results($only_count=FALSE, $debug = false) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('product_id', $this->options)) {
			$where_clauses[] = 'prq_pro_product_id = ?';
			$bind_params[] = array($this->options['product_id'], PDO::PARAM_INT);
		}
		

		if (array_key_exists('required', $this->options)) {
			$where_clauses[] = 'prq_is_required = ' . ($this->options['required'] ? 'TRUE' : 'FALSE');
		}
		
			
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM prq_product_requirements
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM prq_product_requirements
				' . $where_clause . ' ORDER BY ';

			if ($this->order_by === NULL) {
				$sql .= 'prq_product_requirement_id DESC';
			} else {
				$sort_clauses = array();
				
				if (array_key_exists('title', $this->order_by)) {
					$sort_clauses[] = 'prq_title ' . $this->order_by['title'];
				}							
				
				$sql .= implode(',', $sort_clauses);
			}
			$sql .= $this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);
			
			if($debug){
				echo $sql. "<br>\n";
				print_r($this->options);
			}

			$total_params = count($bind_params);
			for($i=0;$i<$total_params;$i++) {
				list($param, $type) = $bind_params[$i];
				$q->bindValue($i+1, $param, $type);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ProductRequirement($row->prq_product_requirement_id);
			$child->load_from_data($row, array_keys(ProductRequirement::$fields));
			$this->add($child);
		}
	}

}

?>
