<?php

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

class ProductRequirementException extends SystemBaseException {}
class DisplayableProductRequirementException extends ProductRequirementException implements DisplayableErrorMessage {}
class DisplayablePermanentProductRequirementException extends ProductRequirementException implements DisplayablePermanentErrorMessage {}

class ProductRequirement extends SystemBase {	public static $prefix = 'prq';
	public static $tablename = 'prq_product_requirements';
	public static $pkey_column = 'prq_product_requirement_id';

	protected static $foreign_key_actions = [
		'prq_fil_file_id' => ['action' => 'null'],
		'prq_qst_question_id' => ['action' => 'prevent', 'message' => 'product requirements are built on this question - repoint them first'],
	];

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_owner_field     = false; // ownerless catalog — members read all rows
	public static $ai_description     = 'Required data fields a buyer must complete at checkout for a product.';
	public static $ai_excluded_fields = [];

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
	    'prq_product_requirement_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'prq_title' => array('type'=>'varchar(255)', 'required'=>true),
	    'prq_link' => array('type'=>'varchar(255)'),
	    'prq_is_required' => array('type'=>'bool', 'default'=>false),
	    'prq_order' => array('type'=>'int2'),
	    'prq_fil_file_id' => array('type'=>'int4'),
	    'prq_qst_question_id' => array('type'=>'int4'),
	    'prq_delete_time' => array('type'=>'timestamp(6)'),
	); 

function get_link_to_append(){
		$settings = Globalvars::get_instance();
		if($this->get('prq_link')){
			return $this->get('prq_link');
		}
		else if($this->get('prq_fil_file_id')){
			$file = new File($this->get('prq_fil_file_id'), TRUE);
			return $file->get_url('original', 'full');
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
	protected static $model_class = 'ProductRequirement';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$option_display = $item->get('prq_title');
			$items[$item->key] = $option_display;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		// Note: 'product_id' filter removed - prq_pro_product_id field does not exist in model

		if (isset($this->options['required'])) {
			$filters['prq_is_required'] = $this->options['required'] ? "= TRUE" : "= FALSE";
		}

		return $this->_get_resultsv2('prq_product_requirements', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
