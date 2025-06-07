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
require_once($siteDir . '/data/order_items_class.php');


class OrderItemRequirementException extends SystemClassException {}
class DisplayableOrderItemRequirementException extends OrderItemRequirementException implements DisplayableErrorMessage {}
class DisplayablePermanentOrderItemRequirementException extends OrderItemRequirementException implements DisplayablePermanentErrorMessage {}


class OrderItemRequirement extends SystemBase {
	public static $prefix = 'oir';
	public static $tablename = 'oir_order_item_requirements';
	public static $pkey_column = 'oir_order_item_requirement_id';
	public static $permanent_delete_actions = array(
		'oir_order_item_requirement_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'oir_order_item_requirement_id' => 'The key',
		'oir_odi_order_item_id' => 'Order item the requirement info is attached to',
		'oir_prq_product_requirement_id' => 'Requirement ID',
		'oir_qst_question_id' => 'Question ID',
		'oir_label' => 'Label for the item in the database',
		'oir_answer' => 'The answer',
		'oir_submit_time' => 'Time of submission',
		); 

	public static $field_specifications = array(
		'oir_order_item_requirement_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'oir_odi_order_item_id' => array('type'=>'int4'),
		'oir_prq_product_requirement_id' => array('type'=>'int4'),
		'oir_qst_question_id' => array('type'=>'int4'),
		'oir_label' => array('type'=>'varchar(255)'),
		'oir_answer' => array('type'=>'text'),
		'oir_submit_time' => array('type'=>'timestamp(6)'),
		); 
			 	
	public static $required_fields = array(
		'oir_odi_order_item_id'
	);
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'oir_submit_time' => 'now()'
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
		
	


	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}	


}

class MultiOrderItemRequirement extends SystemMultiBase {

	/*
	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$option_display = $item->get('prq_title'); 
			$items[$option_display] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	*/


	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['order_item_id'])) {
			$filters['oir_odi_order_item_id'] = [$this->options['order_item_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('oir_order_item_requirements', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new OrderItemRequirement($row->oir_order_item_requirement_id);
			$child->load_from_data($row, array_keys(OrderItemRequirement::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}

?>
