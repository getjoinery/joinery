<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

require_once($siteDir . '/data/order_items_class.php');
require_once($siteDir . '/data/questions_class.php');
require_once($siteDir . '/data/coupon_codes_class.php');
require_once($siteDir . '/data/coupon_code_products_class.php');
require_once($siteDir . '/data/product_versions_class.php');
require_once($siteDir . '/data/product_requirements_class.php');
require_once($siteDir . '/data/product_requirement_instances_class.php');

class ProductException extends SystemClassException {}

class BasicProductRequirementException extends SystemClassException {}

abstract class BasicProductRequirement {

	public static $REQUIREMENT_IDS = array(
		1 => 'FullNameRequirement',
		2 => 'PhoneNumberRequirement',
		4 => 'DOBRequirement',
		8 => 'AddressRequirement',
		16 => 'GDPRNoticeRequirement',
		32 => 'RecordConsentRequirement',
		64 => 'EmailRequirement',
		128 => 'UserPriceRequirement',
		256 => 'NewsletterSignupRequirement',
		512 => 'CommentRequirement'
	);
	

	public static function GetRequirements($requirements) {
		$req_classes = array();
		foreach(self::$REQUIREMENT_IDS as $requirement => $req_class) {
			if ($requirements & $requirement) {
				$req_classes[] = new $req_class;
			}
		}
		return $req_classes;
	}

	public function get_form($formwriter, $user=NULL) {}
	public function validate_form($data, $session=NULL) {}

	public function get_javascript() { return ''; }
	public function get_validation_info() { return NULL; }
}

class FullNameRequirement extends BasicProductRequirement {
	const ID = 1;
	const LABEL = 'Name';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }

	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("First Name", "full_name_first", NULL, 20, $user ? $user->get('usr_first_name') : '', '', 255, '');
		echo $formwriter->textinput("Last Name", "full_name_last", NULL, 20, $user ? $user->get('usr_last_name') : '', '', 255, '');
	}

	public function validate_form($data, $session=NULL) {

		if (empty($data['full_name_first'])) {
			throw new BasicProductRequirementException('First Name is Required');
		}
		if (empty($data['full_name_last'])) {
			throw new BasicProductRequirementException('Last Name is Required');
		}
		
		$return_array = array(
			'full_name_first' => $data['full_name_first'],
			'full_name_last' => $data['full_name_last']
		);

		$display_array = array(
			'First Name' => $data['full_name_first'],
		);

		$display_array['Last Name'] = $data['full_name_last'];
		return array(
			$return_array, $display_array);
	}

	public function get_validation_info() {
		return array(
			'full_name_first' => array('required' => array('true', 'First Name is required')),
			'full_name_last' => array('required' => array('true', 'Last name is required')),
		);
	}
}

class PhoneNumberRequirement extends BasicProductRequirement {
	const ID = 2;
	const LABEL = 'Phone Number';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		//echo $formwriter->textinput("Phone Number", "phone", NULL, 11, '', "Example: 123-456-6789", 17, "");
		PhoneNumber::PlainForm($formwriter, NULL);
	}

	public function get_validation_info() {
		return array(
			'phn_phone_number' => array(
				'required' => array('true', 'Phone number is required'),
				//'regex' => array('\'^[0-9]{3}[- \.]?[0-9]{3}[- \.]?[0-9]{4}$\'', 'Phone Number should be in this form: XXX-XXX-XXXX')
			));
	}

	public function validate_form($data, $session=NULL) {
		/*
		if (empty($data['phone']) || !preg_match('/^[0-9]{3}[- \.]?[0-9]{3}[- \.]?[0-9]{4}$/', $data['phone'])) {
			throw new BasicProductRequirementException('Phone Number is not valid, must be XXX-XXX-XXXX');
		}
		*/
		if (empty($data['phn_phone_number'])) {
			throw new BasicProductRequirementException('Phone Number is not valid');
		}


		return array(
			array('phn_phone_number' => $data['phn_phone_number']),
			array('Phone Number' => $data['phn_phone_number']));
	}
}

class DOBRequirement extends BasicProductRequirement {
	const ID = 4;
	const LABEL = 'Date of Birth';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
?>
			<div id="dob_container" class="errorplacement sm:col-span-6">
				<label for="dob_date" class="block text-sm font-medium text-gray-700">Date of Birth</label>

				<select style="width: 175px" name="dob_month" id="dob_month" class="mt-1 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
				<option value="" selected></option><option value="01">01 - January</option><option value="02">02 - February</option><option value="03">03 - March</option><option value="04">04 - April</option><option value="05">05 - May</option><option value="06">06 - June</option><option value="07">07 - July</option><option value="08">08 - August</option><option value="09">09 - September</option><option value="10">10 - October</option><option value="11">11 - November</option><option value="12">12 - December</option></select>

				<select style="width: 75px;" class="mt-1  text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md" name="dob_day" id="dob_day">
				<option value="" selected></option>
				<?php
				foreach(range(1, 31) as $day) {
					echo "<option value=\"$day\">$day</option>";
				}
				?>
				</select>

				<select style="width: 100px;" name="dob_year" id="dob_year" class="mt-1 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
				<option value="" selected></option>
				<?php
				foreach(range(intval(date('Y') - 0), 1900, -1) as $year) {
					echo "<option value=\"$year\">$year</option>";
				}
				?>
				</select>
				</div>
	<?php
	}

	public function get_validation_info() {
		return array(
			'dob_month' => array(
				'required' => array('true', 'Please enter the month you were born', 'dob_container'),
			),
			'dob_day' => array(
				'required' => array('true', 'Please enter the day of the month you were born', 'dob_container'),
			),
			'dob_year' => array(
				'required' => array('true', 'Please enter the year you were born', 'dob_container'),
			),
		);
	}

	public function validate_form($data, $session=NULL) {
		if (empty($data['dob_month']) || empty($data['dob_day']) || empty($data['dob_year'])) {
			throw new BasicProductRequirementException('Date of Birth must be fully filled out.');
		}

		if (!is_numeric($data['dob_month']) || !is_numeric($data['dob_day']) || !is_numeric($data['dob_year'])) {
			throw new BasicProductRequirementException('Date of Birth is invalid.');
		}

		$day = intval($data['dob_day']);
		$month = intval($data['dob_month']);
		$year = intval($data['dob_year']);

		if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 1900 || $year > 2030) {
			throw new BasicProductRequirementException('Date of Birth is invalid.');
		}

		return array(
			array(
				'dob_day' => $day,
				'dob_month' => $month,
				'dob_year' => $year,
			),
			array(
				'Date Of Birth' => $month . '/' . $day . '/' . $year
			)
		);
	}
}

class AddressRequirement extends BasicProductRequirement {
	const ID = 8;
	const LABEL = 'Address';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }

	public function get_form($formwriter, $user=NULL) {
		$new_address_display = true;
		if ($user) {
			$default_address = $user->get_default_address();
			$address_book = new MultiAddress(array('user_id' => $user->key, 'deleted' => FALSE));
			$address_book->load();
			$address_dropdown_builder = $address_book->get_address_dropdown_options($user->get_default_address());
			$new_address_display = true;

			if (count($address_dropdown_builder) > 1) {
				echo '<div id="address_container" class="sm:col-span-6 errorplacement">
					<label for="address" class="block text-sm font-medium text-gray-700">Address</label>
					<select name="address" id="address" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">'
					. implode('', $address_dropdown_builder) .
					'</select></div>';
				$new_address_display = false;
				echo '<div id="new_address_block" class="sm:col-span-6" style="display:none;">';
				Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));
				echo '</div>';
			} 
			else {
				echo $formwriter->hiddeninput('address', 'new');
				Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));
			}
		}
		else{
			echo $formwriter->hiddeninput('address', 'new');
			Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));			
		}
	}

	function get_javascript() {
		return '
		$("#address").change(function() {
			if ($("#address").val() == "new") {
				$("#new_address_block").slideDown(500);
			} else {
				$("#new_address_block").hide();
			}
			return true;
		});

		function is_new_address(element) {
			return $("#address").val() == "new";
		}';
	}

	public function get_validation_info() {
		return array(
			'usa_address1' => array(
				'required' => array('is_new_address', 'Street Address must be set.')),
			'usa_city' => array(
				'required' => array('is_new_address', 'City must be set.')),
			'usa_zip_code_id' => array(
				'required' => array('is_new_address', 'Zip/Postcode must be set.')),
			'usa_state' => array(
				'required' => array('is_new_address', 'State must be set.')),
		);
	}

	function validate_form($data, $session=NULL) {
		if (empty($data['address'])) {
			throw new BasicProductRequirementException('The address section must be filled out.');
		}

		if ($data['address'] === 'new') {
			try {
				$user_id = NULL;
				if($session->get_user_id()){
					$user_id = $session->get_user_id();
				}
				$address = Address::CreateAddressFromForm($data, $user_id);
				return array(
					array('address' => $address),
					array('Address' => $address->get_address_string(', '))
				);
			}	catch (AddressException $e) {
				throw new BasicProductRequirementException('Your address was invalid: ' . $e->getMessage());
			}
		} else {
			$address_key = LibraryFunctions::decode($data['address']);
			if ($address_key === FALSE) {
				throw new BasicProductRequirementException('You have selected an invalid address, please try again.');
			}
			$address = new Address($address_key, TRUE);
			$address->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			return array(
				array('address' => $address),
				array('Address' => $address->get_address_string(', '))
			);
		}
	}
}


class GDPRNoticeRequirement extends BasicProductRequirement {
	const ID = 16;
	const LABEL = 'GDPR Notice';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo '<div id="gdpr_terms_container" class=NULL>';
		echo '<label for="gdpr_terms">Privacy Notice</label>';
		echo "<div><div onclick=\"$('#gdpr_terms').attr('checked', !$('#gdpr_terms').attr('checked')); return false;\" style=\"overflow:auto; height: 100px; border: 1px solid #DDDAD3; width: 45%; padding: 6px; margin-bottom: 5px; background-color: #f5f5f5;\">Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our privacy policy.</div>";
		echo '<label></label><input name="gdpr_terms" id="gdpr_terms" value="1" type="checkbox"  /><span onclick="$(\'#gdpr_terms\').attr(\'checked\', !$(\'#gdpr_terms\').attr(\'checked\')); return false;"> I have read and agree to the privacy policy.</span></div>';
	}

	function validate_form($data, $session=NULL) {
		if (empty($data['gdpr_terms'])) {
			throw new BasicProductRequirementException('You must have read and agreed to the privacy policy in order to continue.');
		}
		
		if($data['gdpr_terms']){
			$display = 'Yes';
		} 
		else{
			$display = 'No';
		}

		return array(array('gdpr_terms' => $data['gdpr_terms']), array('GDPR Terms' => $display));
	}

	public function get_validation_info() {
		return array(
				'gdpr_terms' => array('required' => array('true', 'You must have read and agreed to the privacy policy in order to continue.')));
	}
}

class RecordConsentRequirement extends BasicProductRequirement {
	const ID = 32;
	const LABEL = 'Consent to Record';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->checkboxinput("I am aware that the course/event may be recorded and consent to being recorded.", "record_terms", "sm:col-span-6", "normal", '1', "yes", '');
		//echo '<div id="record_terms_container" class=NULL>';
		//echo '<label for="record_terms">Recording Notice</label>';
		//echo '<input name="record_terms" id="record_terms" value="1" type="checkbox"  /><span onclick="$(\'#record_terms\').attr(\'checked\', !$(\'#record_terms\').attr(\'checked\')); return false;"> I am aware that the course/event may be recorded and consent to being recorded. </span></div>';
	}

	function validate_form($data, $session=NULL) {

		if (empty($data['record_terms'])) {
			throw new BasicProductRequirementException('You must have read and agreed to the recording notice in order to continue.');
		}
		
		if($data['record_terms']){
			$display = 'Yes';
		} 
		else{
			$display = 'No';
		}

		return array(array('record_terms' => $data['record_terms']), array('Record Consent' => $display));
	}
	
	

	public function get_validation_info() {
		return array(
				'record_terms' => array('required' => array('true', 'You must have read and agreed to the recording notice in order to continue.')));
	}
}

class EmailRequirement extends BasicProductRequirement {
	const ID = 64;
	const LABEL = 'Email';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("Email", "email", NULL, 20, $user ? $user->get('usr_email') : '', '', 255, '');
		
	}

	public function validate_form($data, $session=NULL) {
		if (empty($data['email'])) {
			throw new BasicProductRequirementException('Email is Required');
		}
		
		if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
			$error = "Email address '".$data['email']."' is not valid.\n";
			throw new BasicProductRequirementException($error);
		}		

		$return_array = array(
			'email' => $data['email'],
		);

		$display_array = array(
			'Email' => $data['email'],
		);

		return array(
			$return_array, $display_array);
	}

	public function get_validation_info() {
		return array(
			'email' => array('required' => array('true', 'Email is required')),
		);
	}
}

class UserPriceRequirement extends BasicProductRequirement {
	const ID = 128;
	const LABEL = 'User chooses price';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("Optional donation amount ($)", "user_price", NULL, 20, '', '', 255, '');
		
	}

	public function validate_form($data, $session=NULL) {
		/*if (empty($data['user_price'])) {
			throw new BasicProductRequirementException('Donation amount is required');
		}*/

		
		//CLEAN IT UP
		//REMOVE ANYTHING BUT NUMBERS AND A DOT
		$data['user_price'] = str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $data['user_price'])); 
		
		/*
		if ($data['user_price'] == 0 || $data['user_price'] == '0.00') {
			throw new BasicProductRequirementException('Donation amount must be greater than zero.');
		}
		*/
		if ($data['user_price'] < 0) {
			throw new BasicProductRequirementException('Donation amount must be zero or more.');
		}


		$return_array = array(
			'user_price' => $data['user_price'],
		);

		$display_array = array(
			'Donation amount ($)' => $data['user_price']. '.00',
		);

		return array(
			$return_array, $display_array);
	}

	public function get_validation_info() {
		return array(
			//'user_price' => array('required' => array('true', 'Donation amount is required')),
		);
	}
}

class NewsletterSignupRequirement extends BasicProductRequirement {
	const ID = 256;
	const LABEL = 'Newsletter Signup';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->checkboxinput("Keep me updated by email", "newsletter", "checkbox", "", NULL, 1, "");

	}

	function validate_form($data, $session=NULL) {
		/*
		if (empty($data['newsletter'])) {
			throw new ProductRequirementException('You must have read and agreed to the recording notice in order to continue.');
		}
		*/
		
		if($data['newsletter']){
			$display = 'Yes';
		} 
		else{
			$display = 'No';
		}

		return array(array('newsletter' => $data['newsletter']), array('Newsletter Signup' => $display));

	}

	public function get_validation_info() {
		/*return array(
				'newsletter' => array('required' => array('true', 'You must have read and agreed to the recording notice in order to continue.')));*/
	}
}

class CommentRequirement extends BasicProductRequirement {
	const ID = 512;
	const LABEL = 'Comment';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("Optional comment", "comment", NULL, 20, '', '', 255, '');
		
	}

	public function validate_form($data, $session=NULL) {
			

		$return_array = array(
			'comment' => $data['comment'],
		);

		$display_array = array(
			'Comment' => $data['comment'],
		);

		return array(
			$return_array, $display_array);
	}

	public function get_validation_info() {
		return array();
	}
}



class Product extends SystemBase {
	public static $prefix = 'pro';
	public static $tablename = 'pro_products';
	public static $pkey_column = 'pro_product_id';
	public static $url_namespace = 'product';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(
		'pro_product_id' => 'delete',	
		'prd_pro_product_id' => 'delete',
		'prv_pro_product_id' => 'delete',
		'ccp_pro_product_id' => 'delete',
		'odi_pro_product_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
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

	public static $fields = array(
		'pro_product_id' => 'Product ID',
		'pro_name' => 'Product name',
		'pro_short_description' => 'Product Description',
		'pro_description' => 'Product Description',
		'pro_requirements' => 'Requirements of this product',
		'pro_max_cart_count' => 'Maximum number of this item that can be bought at one time',
		'pro_max_purchase_count' => 'Maximum number of this item that can be bought total',
		'pro_prg_product_group_id' => 'Product group this product is part of',
		'pro_after_purchase_message' => 'Message shown after purchase of the item',
		'pro_evt_event_id' => 'Event id if the order is for an event',
		'pro_expires' => 'How much time until the purchase expires.',
		'pro_is_active' => 'Active or disabled',
		'pro_grp_group_id' => 'The group id of the bundle if the product is for a bundle',
		'pro_type' => 'Type of product e.g. event ticket or digital item',
		'pro_digital_link' => 'Link for a digital download',
		'pro_num_remaining_calc' => 'Calculated field of number remaining in stock',
		'pro_link' => 'Link to use for accessing',
		'pro_delete_time' => 'time deleted',
		'pro_product_scripts' => 'Comma separated list of scripts to run upon purchase',
		'pro_stripe_product_id' => 'Product ID at Stripe',
		'pro_stripe_product_id_test' => 'Product ID at Stripe in test mode',
	);

	public static $field_specifications = array(
		'pro_product_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pro_name' => array('type'=>'varchar(255)'),
		'pro_short_description' => array('type'=>'text'),
		'pro_description' => array('type'=>'text'),
		'pro_requirements' => array('type'=>'int4'),
		'pro_max_cart_count' => array('type'=>'int4'),
		'pro_max_purchase_count' => array('type'=>'int4'),
		'pro_prg_product_group_id' => array('type'=>'int4'),
		'pro_after_purchase_message' =>  array('type'=>'text'),
		'pro_evt_event_id' => array('type'=>'int4'),
		'pro_expires' =>  array('type'=>'int4'),
		'pro_is_active' => array('type'=>'bool'),
		'pro_grp_group_id' => array('type'=>'int4'),
		'pro_type' => array('type'=>'int4'),
		'pro_digital_link' =>  array('type'=>'varchar(255)'),
		'pro_num_remaining_calc' => array('type'=>'int4'),
		'pro_link' => array('type'=>'varchar(255)'),
		'pro_delete_time' => array('type'=>'timestamp(6)'),
		'pro_product_scripts' => array('type'=>'text'),
		'pro_stripe_product_id' =>  array('type'=>'varchar(64)'),
		'pro_stripe_product_id_test' =>  array('type'=>'varchar(64)'),
	);
			 
	public static $required_fields = array('pro_link', 'pro_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();	
	

	
	public function get_requirement_info($output='text') {
		$requirements_out = array();
		foreach ($this->get_product_requirements() as $productr){
			if($output == 'text'){
				$requirements_out[] = $productr->get_label();
			}
			else{
				$requirements_out[] = $productr->get_id();
			}
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
		require_once($_SERVER['DOCUMENT_ROOT'] . '/logic/product_scripts_logic.php');

		$plugins = LibraryFunctions::list_plugins();
		foreach($plugins as $plugin){
			$product_script_file = $_SERVER['DOCUMENT_ROOT'].'/plugins/'.$plugin.'/logic/product_scripts_logic.php';
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

	//SAVE THE SET OF NEW REQUIREMENT INSTANCES
	function save_requirement_instances($requirements){
		if(empty($requirements)){
			$requirements = array();
		}
		$requirements = array_filter($requirements);

		
		//FIRST GET A LIST OF THE CURRENT REQUIREMENT INSTANCES
		
		$pri_lists = $this->get_requirement_instances(true);
		$to_process = array();
		foreach($pri_lists as $pri_list){
			$to_process[] = $pri_list->get('pri_prq_product_requirement_id');
		}

		
		foreach ($requirements as $choice => $value){
			//THEN CYCLE THROUGH THE NEW ONES, ADD IF IT'S NOT THERE
			if(in_array($value, $to_process)){
				//ITS ALREADY THERE, UNDELETE(IF NEEDED) AND REMOVE IT FROM THE LIST
				$product_requirement_instances = new MultiProductRequirementInstance(array('product_id' => $this->key, 'product_requirement_id' => $value));  	
				$product_requirement_instances->load();	
				foreach($product_requirement_instances as $product_requirement_instance){
					$product_requirement_instance->set('pri_delete_time', NULL);
					$product_requirement_instance->save();
				}
				
				unset($to_process[$choice]);
			}
			else{
				//ADD THE NEW ONE
				$pri = new ProductRequirementInstance(NULL);
				$pri->set('pri_pro_product_id', $this->key);
				$pri->set('pri_prq_product_requirement_id', $value);
				$pri->prepare();
				$pri->save();
				//NOW REMOVE IT FROM THE LIST
				unset($to_process[$choice]);
			}
		}
		

		//IF ANY ARE LEFT, SET THEM TO DELETED
		//WE ARE NOT ALLOWING FULL DELETION IN CASE THERE ARE REFERENCES IN THE DATABASE
		foreach($pri_lists as $pri_list){
			if(in_array($pri_list->get('pri_prq_product_requirement_id'), $to_process)){
				$pri = new ProductRequirementInstance($pri_list->key, TRUE);
				$pri->set('pri_delete_time', 'now()');
				$pri->save();
			}
		}

	}	
	
	function get_requirement_validation(){
		//GET EXTRA PRODUCT REQUIREMENTS, HERE WE OUTPUT THE FORM.  THE VALIDATION HAPPENS ELSEWHERE
		$instances = $this->get_requirement_instances();

		foreach($instances as $instance){
			$requirement = new ProductRequirement($instance->get('pri_prq_product_requirement_id'), TRUE);
			if($requirement->get('prq_qst_question_id')){
				$question = new Question($requirement->get('prq_qst_question_id'), TRUE);
				$validation_rules = array();
				$validation_rules[] = $question->output_js_validation($validation_rules);
				//echo $formwriter->set_validate($validation_rules);	
			}
		}
		return $validation_rules;
	}
	
	//THIS FUNCTION GIVES AN ESTIMATE OF PRICE FOR DISPLAY PURPOSES
	public function get_readable_price($product_version_id=NULL){
		$settings = Globalvars::get_instance(); 
		$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
		
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
		$requirements = $this->get_requirement_info('id');

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
		return BasicProductRequirement::GetRequirements($this->get('pro_requirements'));
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

		foreach ($this->get_product_requirements() as $product_requirement) {

			list($validation_data, $display_data) = $product_requirement->validate_form($form_data, $session);
			
			if ($validation_data !== NULL) {
				$form_data = array_merge($form_data, $validation_data);
			}
			if ($display_data !== NULL) {
				$form_display_data = array_merge($form_display_data, $display_data);
			}
		}
		
		
		//NOW VALIDATE THE ADDITIONAL PRODUCT REQUIREMENTS
		$instances = $this->get_requirement_instances();

		foreach($instances as $instance){
			$requirement = new ProductRequirement($instance->get('pri_prq_product_requirement_id'), TRUE);
			$question = new Question($requirement->get('prq_qst_question_id'), TRUE);
			$valid = $question->validate_answers($form_data['question_'.$question->key]);
			if($valid == 'valid'){
				$question_info = array('name' => 'question_'.$question->key, 'requirement_id' => $instance->get('pri_prq_product_requirement_id'), 'question_id' => $question->key, 'question' => $question->get('qst_question'), 'answer' => $question->get_answer_readable($form_data['question_'.$question->key], false));
				$form_data['question_'.$question->key] = $question_info;
			}
			else{
				throw new SystemDisplayableErrorNoLog($valid);
				/*$errorhandler = new ErrorHandler(TRUE);
				$errorhandler->handle_general_error();
				exit();*/
			}
		}

			
/*
		$errors = array();
		foreach (static::$required_fields as $field => $error_message) {
			if (empty($data[$field])) {
				$errors[] = $error_message;
			}
		}
		if ($errors) {
			throw new BasicProductRequirementException(
				implode('<br>', $errors));
		}
		*/

		return array($form_data, $form_display_data);
	}

	function output_javascript($extra_data=array(), $formwriter, $form_id='product_form') {
		
		$validation_info = array();

		echo '<script type="text/javascript">';
		foreach ($this->get_product_requirements() as $product_requirement) {
			echo $product_requirement->get_javascript();
			if ($product_requirement->get_validation_info()) {
				$validation_info[] = $product_requirement->get_validation_info();
			}
		}

		if ($validation_info) {
			$rules = array();
			$messages = array();
			$error_message_objects = array();

			foreach($validation_info as $info) {
				foreach($info as $field_name => $field_constraints) {
					foreach($field_constraints as $constraint => $value_message) {
						if (count($value_message) == 2) {
							list($value, $message) = $value_message;
							$field_container = $field_name . '_container';
						} else {
							list($value, $message, $field_container) = $value_message;
						}
						list($value, $message) = $value_message;
						$rules[$field_name][$constraint] = $value;
						$messages[$field_name][$constraint] = "'$message'";
						$error_message_objects[$field_name] = $field_container;
					}
				}
			}
			
			//ADD IN THE PRODUCT REQUIREMENT INSTANCES
			$instances_validations = $this->get_requirement_validation();
			foreach($instances_validations as $instance_validation){
				foreach($instance_validation as $field=>$valuearray){
						$value = $valuearray['required'];
						$rules[$field] = array(key($valuearray)=>$value['value']);
					
				}
			}
			
			
			//ADD IN REQUIRED PRICE OVERRIDE
			/*
			if($this->get('pro_price_type') == Product::PRICE_TYPE_USER_CHOOSE){
				$rules['user_price_override'] = array('required' => 'true');
			}
			*/


			//ADD IN EXTRA DATA 
			if(count($extra_data)){
				foreach($extra_data as $field=>$valuearray){
						$value = $valuearray['required'];
						$rules[$field] = array(key($valuearray)=>$value['value']);
					
				}
			}

			echo "
				$(document).ready(function() {

					error_message_array = eval(" . json_encode($error_message_objects) . ");

					$.validator.addMethod(
									'regex',
									function(value, element, regexp) {
											var check = false;
											var re = new RegExp(regexp);
											return this.optional(element) || re.test(value);
									}
					);

					$('#".$form_id."').validate({
							rules: " . str_replace('"', '', json_encode($rules)) . ",
							messages: " . str_replace('"', '', json_encode($messages)) . ",";

					echo $formwriter->validate_style_info;
					echo "
					});
			});
			";
		}
		echo '</script>';
	}
	

	function output_product_form($formwriter, $user, $exclude_requirements=false, $product_version_id=NULL) {
		$settings = Globalvars::get_instance(); 
		$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	
		$versions = $this->get_product_versions();

		if ($this->count_product_versions() == 1) {
			$version = $versions->get(0);
			
			if($version->get('prv_price_type') == 'user'){
				$validation_rules = array();
				$validation_rules['user_price_override']['required']['value'] = 'true';
				echo $formwriter->textinput('Amount to pay ('.$currency_symbol.')', 'user_price_override', NULL, 100, NULL, '', 5, ''); 
			}
			else{
				echo $formwriter->hiddeninput('product_version', $version->get('prv_product_version_id'));
			}
		}
		else if ($this->count_product_versions() > 1) {
			if($product_version_id){
				$selected = $product_version_id;
			}

			
			$version_dropdown = array();
			foreach ($versions as $version) {
				$output_string = $version->get('prv_version_name') . ' - '.$currency_symbol . $version->get('prv_version_price');
				$version_dropdown[$output_string] = $version->key;
			}
			echo $formwriter->dropinput(
				'Product',
				'product_version',
				NULL,
				$version_dropdown,
				$selected,
				'',
				FALSE);
			
		}

		if(!$exclude_requirements){
			
			$form_javascript = array();
			foreach ($this->get_product_requirements() as $product_requirement) {
				$product_requirement->get_form($formwriter, $user);	
			}

			
			//GET EXTRA PRODUCT REQUIREMENTS, HERE WE OUTPUT THE FORM.  THE VALIDATION HAPPENS ELSEWHERE
			$instances = $this->get_requirement_instances();

			foreach($instances as $instance){
				$requirement = new ProductRequirement($instance->get('pri_prq_product_requirement_id'), TRUE);
				if($requirement->get('prq_qst_question_id')){
					$question = new Question($requirement->get('prq_qst_question_id'), TRUE);
					//$validation_rules = array();
					//$validation_rules = $question->output_js_validation($validation_rules);
					//echo $formwriter->set_validate($validation_rules);
					if($link_append = $requirement->get_link_to_append()){
						$link_append = ' (<a target="_blank" href="'.$link_append.'">'.$link_append.'</a>)';
					}
					echo $question->output_question($formwriter, NULL, $link_append);		
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
	
}

class MultiProduct extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->get('pro_name')] = $item->key;
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

		if (isset($this->options['deleted'])) {
			$filters['pro_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('pro_products', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Product($row->pro_product_id);
			$child->load_from_data($row, array_keys(Product::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}
}

// Also require all the sub-products
foreach (glob($siteDir . '/data/products/*.php') as $sub) {
	require_once($sub);
}


?>
