<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/order_items_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/coupon_codes_class.php'));
require_once(PathHelper::getIncludePath('data/coupon_code_products_class.php'));
require_once(PathHelper::getIncludePath('data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('data/product_requirements_class.php'));
require_once(PathHelper::getIncludePath('data/product_requirement_instances_class.php'));

class ProductException extends SystemBaseException {}

class BasicProductRequirementException extends SystemBaseException {}

class Product extends SystemBase {
	public static $prefix = 'pro';
	public static $tablename = 'pro_products';
	public static $pkey_column = 'pro_product_id';
	public static $url_namespace = 'product';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM

	protected static $foreign_key_actions = [
		'pro_prg_product_group_id' => ['action' => 'prevent', 'message' => 'Cannot delete product group - products exist'],
		'pro_fil_file_id' => ['action' => 'null'],
	];

	public static $currency_symbols = array(
	 'usd' => '$',
	 'eur' => '&euro;'
	 ); 
	
	const PRICE_TYPE_ONE = 1;
	const PRICE_TYPE_MULTIPLE = 2;
	const PRICE_TYPE_USER_CHOOSE = 3;

	const PRODUCT_TYPE_SYSTEM = 0;
	const PRODUCT_TYPE_EVENT = 1;
	const PRODUCT_TYPE_ITEM = 2;
	
	const PRODUCT_ID_OPTIONAL_DONATION=4;

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
	    'pro_product_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'pro_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'pro_short_description' => array('type'=>'text'),
	    'pro_description' => array('type'=>'text'),
	    'pro_max_cart_count' => array('type'=>'int4'),
	    'pro_max_purchase_count' => array('type'=>'int4'),
	    'pro_prg_product_group_id' => array('type'=>'int4'),
	    'pro_after_purchase_message' => array('type'=>'text'),
	    'pro_evt_event_id' => array('type'=>'int4'),
	    'pro_expires' => array('type'=>'int4'),
	    'pro_is_active' => array('type'=>'bool'),
	    'pro_grp_group_id' => array('type'=>'int4'),
	    'pro_type' => array('type'=>'int4'),
	    'pro_digital_link' => array('type'=>'varchar(255)'),
	    'pro_num_remaining_calc' => array('type'=>'int4'),
	    'pro_link' => array('type'=>'varchar(255)', 'required'=>true),
	    'pro_delete_time' => array('type'=>'timestamp(6)'),
	    'pro_product_scripts' => array('type'=>'text'),
	    'pro_stripe_product_id' => array('type'=>'varchar(64)'),
	    'pro_stripe_product_id_test' => array('type'=>'varchar(64)'),
	    'pro_sbt_subscription_tier_id' => array('type'=>'int4'),
	    'pro_tier_min_level' => array('type'=>'int4', 'is_nullable'=>true),
	    'pro_fil_file_id' => array('type'=>'int4'),
	);

public function get_requirement_info($output='text') {
		$requirements_out = array();
		foreach ($this->get_product_requirements() as $requirement){
			$requirements_out[] = $requirement->get_label();
		}
		return $requirements_out;
	}	
	
	//GET ALL OF THE ADDITIONAL PRODUCT REQUIREMENTS FOR THIS PRODUCT
	function get_requirement_instances($deleted=false){
		if(!$deleted){
			$pri_lists = new MultiProductRequirementInstance(
			array('product_id'=>$this->key, 'deleted' => false),
			NULL,		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET			
		}
		else{
			$pri_lists = new MultiProductRequirementInstance(
			array('product_id'=>$this->key),
			NULL,		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET			
		}

		$pri_lists->load();	
		return $pri_lists;
	}
	
	function run_product_scripts($user, $order_item){
		//REQUIRE ALL OF THE PRODUCT SCRIPTS, THE MAIN ONE AND ALL OF THE PLUGINS
		require_once(PathHelper::getIncludePath('logic/product_scripts_logic.php'));

		$plugins = LibraryFunctions::list_plugins();
		foreach($plugins as $plugin){
			$product_script_file = PathHelper::getRootDir().'/plugins/'.$plugin.'/hooks/product_purchase.php';
			if(file_exists($product_script_file)){
				require_once($product_script_file);
			}
		}
		
		//RUN THE PRODUCT SCRIPTS
		if($product_scripts_list = $this->get('pro_product_scripts')){
			$product_scripts = explode(',', $product_scripts_list);
			foreach($product_scripts as $product_script){
				$product_script($user, $order_item);
			}
		}
		
		return true;
	}

	/**
	 * Save the set of requirement instances for this product.
	 *
	 * @param array $requirements Array of requirement specs, each containing:
	 *   - 'class_name' => string (e.g., 'FullNameRequirement' or 'QuestionRequirement')
	 *   - 'config' => array (e.g., ['question_id' => 42] for QuestionRequirement)
	 *   A unique key is generated from class_name + config for diff matching.
	 */
	function save_requirement_instances($requirements){
		if(empty($requirements)){
			$requirements = array();
		}

		// Build map of current instances by unique key (class_name + config)
		$current_instances = $this->get_requirement_instances(true);
		$current_map = [];
		foreach($current_instances as $instance){
			$key = $instance->get('pri_class_name') . '|' . ($instance->get('pri_config') ?: '');
			$current_map[$key] = $instance;
		}

		$order = 0;
		$processed_keys = [];

		foreach ($requirements as $req_spec) {
			$class_name = $req_spec['class_name'];
			$config = isset($req_spec['config']) ? $req_spec['config'] : [];
			$config_json = !empty($config) ? json_encode($config) : '';
			$unique_key = $class_name . '|' . $config_json;

			if (isset($current_map[$unique_key])) {
				// Already exists — undelete if needed and update order
				$instance = $current_map[$unique_key];
				$instance->set('pri_delete_time', null);
				$instance->set('pri_order', $order);
				$instance->save();
			} else {
				// New — create it
				$pri = new ProductRequirementInstance(NULL);
				$pri->set('pri_pro_product_id', $this->key);
				$pri->set('pri_class_name', $class_name);
				$pri->set('pri_config', $config_json ?: null);
				$pri->set('pri_order', $order);
				$pri->save();
			}

			$processed_keys[] = $unique_key;
			$order++;
		}

		// Soft-delete any current instances not in the new set
		foreach($current_map as $key => $instance){
			if(!in_array($key, $processed_keys)){
				$instance->set('pri_delete_time', 'now()');
				$instance->save();
			}
		}
	}	
	
	// get_requirement_validation() removed — validation is now unified through AbstractProductRequirement
	
	//THIS FUNCTION GIVES AN ESTIMATE OF PRICE FOR DISPLAY PURPOSES
	public function get_readable_price($product_version_id=NULL){
		$settings = Globalvars::get_instance(); 
		$currency_symbol = Product::$currency_symbols[strtolower($settings->get_setting('site_currency'))] ?? '$';

		if($this->key == Product::PRODUCT_ID_OPTIONAL_DONATION){
			//IT IS AN OPTIONAL DONATION
			//REMOVE EVERYTHING BUT DECIMALS AND INTEGERS (ALLOW FOR EUROPEAN COMMAS)
			return false;
		}		
		else{
			$versions = $this->get_product_versions();
			if(!$this->count_product_versions()){
				return false;
			}
			else if($product_version_id){
				//WE WANT ONLY THE PRICE OF A SPECIFIC PRODUCT VERSION
				foreach ($versions as $version) {
					if ($version->key == $product_version_id) {	
						return $currency_symbol.$version->get('prv_version_price');
					} 
				}				
			}
			else if($this->count_product_versions() == 1){
				$version = $versions->get(0);
				return $currency_symbol.$version->get('prv_version_price');
			}
			 
			else{
				$low_price = NULL;
				$high_price = NULL;
				foreach ($versions as $version) {
					if ($version->get('prv_status')) {
						
						if(!$low_price || $version->get('prv_version_price') < $low_price){
							$low_price = $version->get('prv_version_price');
						}
						
						if(!$high_price || $version->get('prv_version_price') > $high_price){
							$high_price = $version->get('prv_version_price');
						}
					} 
				}
				
				if($low_price && $high_price){
					return $currency_symbol.$low_price . ' - ' . $currency_symbol.$high_price;
				}
				else{
					return false;
				}
			}

		}	
	
	}
	
	public function get_price($product_version, $data){
		//HANDLE PRICES
		$settings = Globalvars::get_instance(); 
		
		if($this->key == Product::PRODUCT_ID_OPTIONAL_DONATION){
			//IT IS AN OPTIONAL DONATION
			//REMOVE EVERYTHING BUT DECIMALS AND INTEGERS (ALLOW FOR EUROPEAN COMMAS)
			return str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $data['user_price']));
		}		
		else if($product_version->get('prv_price_type') == 'user'){
	
			if($data['user_price_override']){
				//REMOVE EVERYTHING BUT DECIMALS AND INTEGERS (ALLOW FOR EUROPEAN COMMAS)
				return str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $data['user_price_override']));
			}
			else{
				$error = 'This product is missing the price override.';
				throw new SystemDisplayableError($error. "  Contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.");
				exit;
			}
		}
		else if($product_version){
			//THIS PRODUCT HAS A VERSION THAT WE SHOULD PULL TO GET THE PRICE
			return $product_version->get('prv_version_price');		
		}
		else{
			$error = 'This product has no price.';
			throw new SystemDisplayableError($error. "  Contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.");
			exit;
		}
		
	}
	
	public function total_coupon_discount($full_price, $product_version, $coupon_codes){
		$discount = 0;
		$valid_coupons = $this->get_valid_coupons($product_version);

		foreach($coupon_codes as $coupon_code){
			foreach($valid_coupons as $coupon){
				if($coupon_code == $coupon->get('ccd_code')){
					//STACKABLE OR NOT 
					if($coupon->get('ccd_is_stackable')){
						$discount += $coupon->get_discount($full_price);
					}
					else{
						$this_discount = $coupon->get_discount($full_price);
						if($this_discount > $discount){
							$discount = $this_discount;
						}
					}
				}
			}
		}
		
		if($discount > $full_price){
			$discount = $full_price;
		}

		return $discount;
		
	}
	
	public function get_valid_coupons($product_version){
		$valid_coupon_codes = array();

		//FIRST GET ANY COUPONS THAT ARE GLOBAL AND VALID 
		$searches = array('deleted' => false, 'active' => true, 'applies_to' => 0);	
		$coupon_codes = new MultiCouponCode($searches);
		$coupon_codes->load();
		foreach($coupon_codes as $coupon_code){	
			if($coupon_code->is_valid()){
				$found=0;
				foreach($valid_coupon_codes as $valid_coupon_code){
					if($valid_coupon_code->get('ccd_code') == $coupon_code->get('ccd_code')){
						$found=1;
					}
				}
				
				if(!$found){	
					$valid_coupon_codes[] = $coupon_code;
				}
			}
		}

		//THEN GET ANY COUPONS THAT MATCH SUBSCRIPTION STATUS AND VALID 
		if($product_version->is_subscription()){
			$searches = array('deleted' => false, 'active' => true, 'applies_to' => 1);	
		}
		else{
			$searches = array('deleted' => false, 'active' => true, 'applies_to' => 2);	
		}
		$coupon_codes = new MultiCouponCode($searches);
		$coupon_codes->load();
		foreach($coupon_codes as $coupon_code){	
			if($coupon_code->is_valid()){
				$found=0;
				foreach($valid_coupon_codes as $valid_coupon_code){
					if($valid_coupon_code->get('ccd_code') == $coupon_code->get('ccd_code')){
						$found=1;
					}
				}
				
				if(!$found){
					$valid_coupon_codes[] = $coupon_code;
				}
			}
		}		
		
		//THEN STORE ANY COUPONS THAT MATCH THE PRODUCT EXACTLY
		$searches = array('product_id' => $this->key);	
		$coupon_code_products = new MultiCouponCodeProduct($searches);
		$coupon_code_products->load();
		
		foreach($coupon_code_products as $coupon_code_product){	
			$coupon_code = new CouponCode($coupon_code_product->get('ccp_ccd_coupon_code_id'), TRUE);
			if($coupon_code->get('ccd_applies_to') == 3){
				if($coupon_code->is_valid()){
					$found=0;
					foreach($valid_coupon_codes as $valid_coupon_code){
						if($valid_coupon_code->get('ccd_code') == $coupon_code->get('ccd_code')){
							$found=1;
						}
					}
					
					if(!$found){
						$valid_coupon_codes[] = $coupon_code;
					}
				}
			}
		}

		return $valid_coupon_codes;
	}

	public function get_product_versions($active=TRUE, $product_version_id=NULL) {
		$product_versions = new MultiProductVersion(
			array('product_id' => $this->key, 'is_active' => $active), 
			NULL
		);
		$product_versions->load();
		
		if($product_version_id){
			foreach($product_versions as $product_version){
				if($product_version->key == $product_version_id){
					return $product_version;
				}
			}
			throw new BasicProductRequirementException(
					'Sorry, one of the products in your cart does not have a correct version.  Please clear your cart and try again.');
		}
		return $product_versions;
	}
	
	public function count_product_versions($active=TRUE) {
		$product_versions = new MultiProductVersion(
			array('product_id' => $this->key, 'is_active' => $active), 
			NULL
		);
		return $product_versions->count_all();
	}

	function get_number_purchased($status = OrderItem::STATUS_PAID){
		//COUNT THE NUMBER OF PRODUCTS PURCHASED SO FAR
		$orders = new MultiOrderItem(array('product_id' => $this->key, 'status' => $status));
		return $orders->count_all();		
	}
	
	function is_sold_out(){
		
		if($this->get('pro_max_purchase_count') == 0){
			return false;
		}
		
		//CHECK AGAINST MAX NUMBER ALLOWED
		$sold_out = false;
		if($this->get('pro_max_purchase_count')){
			if($this->get_number_purchased() >= $this->get('pro_max_purchase_count')){
				$sold_out = true;
			}
		}	
		return $sold_out;
	}

	function get_product_requirements() {
		require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));
		$requirements = AbstractProductRequirement::getProductRequirements($this->key);

		// Auto-add SurveyRequirement if this product's event has a required pre-purchase survey
		if ($this->get('pro_evt_event_id')) {
			require_once(PathHelper::getIncludePath('data/events_class.php'));
			$event = new Event($this->get('pro_evt_event_id'), TRUE);
			if ($event->get('evt_svy_survey_id') && $event->get('evt_survey_display') === 'required_before_purchase') {
				require_once(PathHelper::getIncludePath('includes/requirements/SurveyRequirement.php'));
				$requirements[] = AbstractProductRequirement::createInstance('SurveyRequirement', [
					'survey_id' => $event->get('evt_svy_survey_id'),
					'event_id' => $event->key,
				]);
			}
		}

		return $requirements;
	}

	function validate_form($form_data, $session) {
		$form_display_data = array();

		// If the product has active product verisons, one of them must be selected!
		$versions = $this->get_product_versions();

		if (!isset($form_data['product_version']) || !is_numeric($form_data['product_version'])) {
			throw new BasicProductRequirementException(
				'You must select which version of the product you would like to purchase.');
		}

		$product_version = new ProductVersion(intval($form_data['product_version']), TRUE);
		if (!$product_version) {
			throw new BasicProductRequirementException(
				'Sorry, the product you have selected is not valid.  Please try again.');
		}

		$form_display_data['Product'] = $product_version->get('prv_version_name');
		$form_data['product_version'] = $product_version->get('prv_product_version_id');

		//VALIDATE THE USER PRICE OVERRIDE IF THAT EXISTS
		if($product_version->get('prv_price_type') == 'user' && isset($form_data['user_price_override'])){
			if(!$form_data['user_price_override']){
				throw new SystemDisplayableErrorNoLog(
					'You must enter an amount in the "Price to pay" field.');
			}
		}

		//IF NO ITEMS REMAINING, SHOW ERROR
		if($this->get('pro_max_purchase_count') > 0){
			$remaining = $this->get('pro_max_purchase_count') - $this->get_number_purchased();
			if(!$remaining){
				throw new SystemDisplayableErrorNoLog(
							'This item is sold out.');
			}
		}

		// Validate all requirements via the AbstractProductRequirement
		foreach ($this->get_product_requirements() as $requirement) {
			// Validate
			$errors = $requirement->validate($form_data, $this);
			if (!empty($errors)) {
				throw new BasicProductRequirementException(implode('<br>', $errors));
			}

			// Process — get data and display arrays
			list($validation_data, $display_data) = $requirement->process($form_data, $this, null, null);

			if ($validation_data !== null) {
				$form_data = array_merge($form_data, $validation_data);
			}
			if ($display_data !== null) {
				$form_display_data = array_merge($form_display_data, $display_data);
			}
		}

		return array($form_data, $form_display_data);
	}

	function output_javascript($formwriter, $extra_data=array(), $form_id='product_form') {

		$validation_info = array();

		echo '<script type="text/javascript">';
		foreach ($this->get_product_requirements() as $requirement) {
			echo $requirement->get_javascript();
			$info = $requirement->get_validation_info();
			if ($info) {
				$validation_info[] = $info;
			}
		}

		if ($validation_info) {
			$rules = array();
			$messages = array();
			$error_message_objects = array();

			foreach($validation_info as $info) {
				foreach($info as $field_name => $field_constraints) {
					foreach($field_constraints as $constraint => $value_message) {
						if (is_array($value_message)) {
							if (count($value_message) == 2) {
								list($value, $message) = $value_message;
								$field_container = $field_name . '_container';
							} else {
								list($value, $message, $field_container) = $value_message;
							}
							$rules[$field_name][$constraint] = $value;
							$messages[$field_name][$constraint] = $message;
							$error_message_objects[$field_name] = $field_container;
						} else {
							// Simple value (e.g., from Question validation)
							$rules[$field_name][$constraint] = $value_message;
						}
					}
				}
			}

			//ADD IN EXTRA DATA
			if(count($extra_data)){
				foreach($extra_data as $field=>$valuearray){
						$value = $valuearray['required'];
						$rules[$field] = array(key($valuearray)=>$value['value']);
				}
			}

			// Generate JoineryValidation JavaScript (migrated from jQuery Validate)
			echo "
				document.addEventListener('DOMContentLoaded', function() {
					const validationOptions = {
						debug: false,
						rules: {";

			// Output rules
			$first = true;
			foreach ($rules as $fieldName => $fieldRules) {
				if (!$first) echo ',';
				$first = false;
				echo "\n\t\t\t\t\t\t" . json_encode($fieldName) . ': {';
				$firstRule = true;
				foreach ($fieldRules as $ruleName => $ruleValue) {
					if (!$firstRule) echo ', ';
					$firstRule = false;
					echo $ruleName . ': ';
					// Handle boolean values
					if ($ruleValue === 'true' || $ruleValue === 'false') {
						echo $ruleValue;
					} else {
						echo json_encode($ruleValue);
					}
				}
				echo '}';
			}

			echo "
						},
						messages: {";

			// Output custom messages
			$firstMsg = true;
			foreach ($messages as $fieldName => $fieldMessages) {
				foreach ($fieldMessages as $ruleName => $message) {
					if (!empty($message)) {
						if (!$firstMsg) echo ',';
						$firstMsg = false;
						echo "\n\t\t\t\t\t\t" . json_encode($fieldName) . ': {';
						echo $ruleName . ': ' . json_encode($message);
						echo '}';
					}
				}
			}

			echo "
						}
					};

					JoineryValidation.init('" . $form_id . "', validationOptions);
				});
			";
		}
		echo '</script>';
	}

	function output_product_form($formwriter, $user, $exclude_requirements=false, $product_version_id=NULL, $prefill_data=NULL) {
		$settings = Globalvars::get_instance();
		$currency_symbol = Product::$currency_symbols[strtolower($settings->get_setting('site_currency'))] ?? '$';

		$versions = $this->get_product_versions();

		if ($this->count_product_versions() == 1) {
			$version = $versions->get(0);

			if($version->get('prv_price_type') == 'user'){
				$prefill_price = ($prefill_data && isset($prefill_data['user_price_override'])) ? $prefill_data['user_price_override'] : '';
				$formwriter->textinput('user_price_override', 'Amount to pay ('.$currency_symbol.')', ['size' => 100, 'maxlength' => 5, 'value' => $prefill_price]);
			}
			else{
				$formwriter->hiddeninput('product_version', '', ['value' => $version->get('prv_product_version_id')]);
			}
		}
		else if ($this->count_product_versions() > 1) {
			if($product_version_id){
				$selected = $product_version_id;
			}
			if ($prefill_data && isset($prefill_data['product_version'])) {
				$selected = $prefill_data['product_version'];
			}

			$version_dropdown = array();
			foreach ($versions as $version) {
				$output_string = $version->get('prv_version_name') . ' - '.$currency_symbol . $version->get('prv_version_price');
				$version_dropdown[$version->key] = $output_string;
			}
			$formwriter->dropinput('product_version', 'Product', [
				'options' => $version_dropdown,
				'value' => $selected,
				'showdefault' => false,
			]);
		}

		if(!$exclude_requirements){
			// Build existing data for pre-filling forms
			$existing_data = [];
			if ($user) {
				$existing_data['user'] = $user;
				$existing_data['usr_first_name'] = $user->get('usr_first_name');
				$existing_data['usr_last_name'] = $user->get('usr_last_name');
				$existing_data['usr_email'] = $user->get('usr_email');
			}
			// Merge in prefill data from cart item when editing
			if ($prefill_data) {
				$existing_data = array_merge($existing_data, $prefill_data);
			}

			// Group requirements by form group for card-based layout
			$groups = array();
			foreach ($this->get_product_requirements() as $requirement) {
				$group = $requirement->getFormGroup();
				if (!isset($groups[$group])) {
					$groups[$group] = array();
				}
				$groups[$group][] = $requirement;
			}

			$group_labels = array(
				'info' => 'Your Information',
				'address' => 'Address',
				'questions' => 'Additional Questions',
			);

			// If only one group, skip card wrappers
			$use_cards = (count($groups) > 1);

			foreach ($groups as $group_key => $requirements) {
				if ($use_cards) {
					$label = isset($group_labels[$group_key]) ? $group_labels[$group_key] : ucfirst($group_key);
					echo '<div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); padding: 1.25rem; margin-bottom: 1rem;">';
					echo '<h5 style="margin: 0 0 1rem; font-size: 1rem; font-weight: 600;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</h5>';
				}
				foreach ($requirements as $requirement) {
					$requirement->render_fields($formwriter, $this, $existing_data);
				}
				if ($use_cards) {
					echo '</div>';
				}
			}
		}

		return TRUE;
	}

	function prepare(){
		parent::prepare();
		//DO NOT ALLOW DUPLICATE PRODUCT LINKS
		if($product = Product::get_by_link($this->get('pro_link'))){
			if($product->key != $this->key){
				throw new SystemDisplayableError('This product link already exists.');
				exit;
			}
		}

	}

	// ===== Entity Photo Methods =====

	function get_picture_link($size_key='original'){
		if($this->get('pro_fil_file_id')){
			require_once(PathHelper::getIncludePath('data/files_class.php'));
			$file = new File($this->get('pro_fil_file_id'), TRUE);
			return $file->get_url($size_key, 'full');
		}
		return false;
	}

	function set_primary_photo($photo_id) {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photo = new EntityPhoto($photo_id, TRUE);
		$this->set('pro_fil_file_id', $photo->get('eph_fil_file_id'));
		$this->save();
	}

	function clear_primary_photo() {
		$this->set('pro_fil_file_id', NULL);
		$this->save();
	}

	function get_photos() {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'product', 'entity_id' => $this->key, 'deleted' => false],
			['eph_sort_order' => 'ASC']
		);
		$photos->load();
		return $photos;
	}

	function get_primary_photo() {
		$file_id = $this->get('pro_fil_file_id');
		if (!$file_id) return null;
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'product', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
			[], 1
		);
		$photos->load();
		return $photos->count() > 0 ? $photos->get(0) : null;
	}

	function permanent_delete($debug=false){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}

		//DELETE ENTITY PHOTOS
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'product', 'entity_id' => $this->key]
		);
		$photos->load();
		foreach($photos as $photo){
			$photo->permanent_delete();
		}

		parent::permanent_delete($debug);

		if($this_transaction){
			$dblink->commit();
		}

		return true;
	}

}

class MultiProduct extends SystemMultiBase {
	protected static $model_class = 'Product';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->key] = $item->get('pro_name');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['product_group'])) {
			$filters['pro_prg_product_group_id'] = [$this->options['product_group'], PDO::PARAM_INT];
		}

		if (isset($this->options['event_id'])) {
			$filters['pro_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['name_like'])) {
			$filters['pro_name'] = 'ILIKE \'%'.$this->options['name_like'].'%\'';
		}

		if (isset($this->options['is_active'])) {
			$filters['pro_is_active'] = "= TRUE";
		}

		if (isset($this->options['link'])) {
			$filters['pro_link'] = [$this->options['link'], PDO::PARAM_STR];
		}

		if (isset($this->options['product_type'])) {
			$filters['pro_type'] = [$this->options['product_type'], PDO::PARAM_INT];
		}

		if (isset($this->options['in_stock'])) {
			$filters['pro_max_purchase_count'] = "IS NULL OR pro_max_purchase_count = 0 OR (pro_max_purchase_count > 0 AND (pro_num_remaining_calc IS NULL OR pro_num_remaining_calc > 0))";
		}

		if (isset($this->options['product_id_is_not'])) {
			$filters['pro_product_id'] = '!= '.$this->options['product_id_is_not'];
		}

		if (isset($this->options['pro_sbt_subscription_tier_id'])) {
			$filters['pro_sbt_subscription_tier_id'] = [$this->options['pro_sbt_subscription_tier_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['pro_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['max_visible_tier_level'])) {
			$level = intval($this->options['max_visible_tier_level']);
			$filters['(pro_tier_min_level'] = "<= {$level} OR pro_tier_min_level IS NULL)";
		}

		return $this->_get_resultsv2('pro_products', $filters, $this->order_by, $only_count, $debug);
	}
}

// Also require all the sub-products
foreach (glob(PathHelper::getBasePath() . '/data/products/*.php') as $sub) {
	require_once($sub);
}

?>
