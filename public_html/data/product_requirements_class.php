<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');

PathHelper::requireOnce('data/products_class.php');
PathHelper::requireOnce('data/files_class.php');

class ProductRequirementException extends SystemClassException {}
class DisplayableProductRequirementException extends ProductRequirementException implements DisplayableErrorMessage {}
class DisplayablePermanentProductRequirementException extends ProductRequirementException implements DisplayablePermanentErrorMessage {}


class ProductRequirement extends SystemBase {
	public static $prefix = 'prq';
	public static $tablename = 'prq_product_requirements';
	public static $pkey_column = 'prq_product_requirement_id';
	public static $permanent_delete_actions = array(
		'prq_product_requirement_id' => 'delete',	
		'pri_prq_product_requirement_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'prq_product_requirement_id' => 'event session ID',
		'prq_title' => 'Session title',
		'prq_link' => 'link to something',
		'prq_is_required' => 'Is this required upon registration/purchase?',
		'prq_order' => 'sort order',
		'prq_fil_file_id' => 'File attached to this requirement',
		//'prq_srv_survey_id' => 'Survey attached to this requirement, for an entire survey',
		'prq_qst_question_id' => 'Question that is attached to this requirement',
		'prq_delete_time' => 'Time of deletion',
		); 

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'prq_product_requirement_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prq_title' => array('type'=>'varchar(255)'),
		'prq_link' => array('type'=>'varchar(255)'),
		'prq_is_required' => array('type'=>'bool'),
		'prq_order' => array('type'=>'int2'),
		'prq_fil_file_id' => array('type'=>'int4'),
		//'prq_srv_survey_id' => array('type'=>'int4'),
		'prq_qst_question_id' => array('type'=>'int4'),
		'prq_delete_time' => array('type'=>'timestamp(6)'),
		); 
			 	
	public static $required_fields = array(
		'prq_title'
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
		
	function get_link_to_append(){
		$settings = Globalvars::get_instance();
		if($this->get('prq_link')){
			return $this->get('prq_link');
		}
		else if($this->get('prq_fil_file_id')){
			$file = new File($this->get('prq_fil_file_id'), TRUE);
			return $file->get_url('standard', 'full');
		}
	}


	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}


}

class MultiProductRequirement extends SystemMultiBase {

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


	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['product_id'])) {
			$filters['prq_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['required'])) {
			$filters['prq_is_required'] = $this->options['required'] ? "= TRUE" : "= FALSE";
		}

		return $this->_get_resultsv2('prq_product_requirements', $filters, $this->order_by, $only_count, $debug);
	}


	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ProductRequirement($row->prq_product_requirement_id);
			$child->load_from_data($row, array_keys(ProductRequirement::$fields));
			$this->add($child);
		}
	}


	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}

?>
