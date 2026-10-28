<?php

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ProductVersionException extends SystemBaseException {}

class ProductVersion extends SystemBase {	public static $prefix = 'prv';
	public static $tablename = 'prv_product_versions';
	public static $access_class = 'money';
	public static $pkey_column = 'prv_product_version_id';

	protected static $foreign_key_actions = [
		'prv_pro_product_id' => ['action' => 'permanent_delete'],
	];

	// Content packs (specs/content_pack_feature.md): a version is found by its
	// product and name, and a pack may write these fields.
	public static $content_pack_keys = [['prv_pro_product_id', 'prv_version_name']];
	public static $content_pack_fields = ['prv_version_price', 'prv_price_type', 'prv_trial_period_days',
		'prv_status', 'prv_order'];

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
	    'prv_product_version_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'prv_pro_product_id' => array('type'=>'int4', 'required'=>true),
	    'prv_version_name' => array('type'=>'varchar(100)', 'required'=>true),
	    'prv_version_price' => array('type'=>'numeric(10,2)', 'required'=>true),
	    'prv_status' => array('type'=>'int2', 'required'=>true),
	    'prv_order' => array('type'=>'int4'),
	    'prv_price_type' => array('type'=>'varchar(10)'),
	    'prv_trial_period_days' => array('type'=>'int4'),
	    'prv_display_priority' => array('type'=>'int4', 'default'=>0),
	    'prv_stripe_price_id' => array('type'=>'varchar(64)'),
	    'prv_stripe_price_id_test' => array('type'=>'varchar(64)'),
	);

	/** The price types the version edit form offers. Empty is a legacy one-time price. */
	const PRICE_TYPES = array('single', 'user', 'day', 'week', 'month', 'year');

	/**
	 * The version form's rules, so a write from anywhere else keeps them. Each
	 * checks a value only when this save changes it, so a stored row that
	 * predates a rule still saves untouched.
	 */
	function prepare(){
		parent::prepare();

		$price_type = (string)$this->get('prv_price_type');
		if($this->value_changes('prv_price_type')){
			if($price_type !== '' && !in_array($price_type, self::PRICE_TYPES, true)){
				throw new SystemDisplayableError('"' . $price_type . '" is not a price type. Use one of: '
					. implode(', ', self::PRICE_TYPES) . '.');
			}
			if($this->is_subscription() && $this->get('prv_pro_product_id')){
				$product = new Product((int)$this->get('prv_pro_product_id'), TRUE);
				if(trim((string)$product->get('pro_ownership_tag')) !== ''){
					throw new SystemDisplayableError('This product can only be owned once, and ownership applies to '
						. 'one-time purchases only, so a subscription version cannot be added. '
						. 'Set Ownership to "No limit" first.');
				}
			}
		}
	}


public function is_subscription(){
		if($this->get('prv_price_type') == 'day' || $this->get('prv_price_type') == 'week' || $this->get('prv_price_type') == 'month' || $this->get('prv_price_type') == 'year'){
			return $this->get('prv_price_type');
		}
		return false;
	}

	/**
	 * What follows a price shown to a buyer: '/month' for a monthly
	 * subscription, '' for a one-time price.
	 */
	public function price_period_suffix(): string {
		$period = $this->is_subscription();
		return $period ? '/' . $period : '';
	}
	
}

class MultiProductVersion extends SystemMultiBase {
	protected static $model_class = 'ProductVersion';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['product_id'])) {
			$filters['prv_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['version_name'])) {
			$filters['prv_version_name'] = [$this->options['version_name'], PDO::PARAM_STR];
		}

		if (isset($this->options['is_active'])) {
			if ($this->options['is_active']) {
				$filters['prv_status'] = "> 0";
			}
		}

		if (isset($this->options['prv_display_priority'])) {
			$filters['prv_display_priority'] = $this->options['prv_display_priority'];
		}

		return $this->_get_resultsv2('prv_product_versions', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
