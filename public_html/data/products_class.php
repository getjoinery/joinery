<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

class ProductException extends SystemClassException {}

class ProductRequirementException extends SystemClassException {}

abstract class ProductRequirement {

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

class FullNameRequirement extends ProductRequirement {
	const ID = 1;
	const LABEL = 'Name';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }

	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("First Name", "full_name_first", "ctrlHolder", 20, $user ? $user->get('usr_first_name') : '', '', 255, '');
		echo $formwriter->textinput("Last Name", "full_name_last", "ctrlHolder", 20, $user ? $user->get('usr_last_name') : '', '', 255, '');
	}

	public function validate_form($data, $session=NULL) {
		if (empty($data['full_name_first'])) {
			throw new ProductRequirementException('First Name is Required');
		}
		if (empty($data['full_name_last'])) {
			throw new ProductRequirementException('Last Name is Required');
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

class PhoneNumberRequirement extends ProductRequirement {
	const ID = 2;
	const LABEL = 'Phone Number';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("Phone Number", "phone", "ctrlHolder", 11, '', "Example: (+1) 123-456-6789", 17, "");
	}

	public function get_validation_info() {
		return array(
			'phone' => array(
				'required' => array('true', 'Social security number is required'),
				'regex' => array('\'^[0-9]{3}[- \.]?[0-9]{2}[- \.]?[0-9]{4}$\'', 'Phone Number should be in this form: +X XXX-XX-XXXX')
			));
	}

	public function validate_form($data, $session=NULL) {
		//if (empty($data['phone']) || !preg_match('/^[0-9]{3}[- \.]?[0-9]{2}[- \.]?[0-9]{4}$/', $data['ssn'])) {
		//	throw new ProductRequirementException('Phone Number is not valid, must be XXX-XXX-XXXX');
		//}

		return array(
			array('phone' => $data['phone']),
			array('Phone Number' => $data['phone']));
	}
}

class DOBRequirement extends ProductRequirement {
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
			<div id="dob_container" class="ctrlHolder errorplacement">
				<label for="dob_date">Date of Birth</label>

				<select style="width: 125px" name="dob_month" id="dob_month">
				<option value="" selected></option><option value="01">01 - January</option><option value="02">02 - February</option><option value="03">03 - March</option><option value="04">04 - April</option><option value="05">05 - May</option><option value="06">06 - June</option><option value="07">07 - July</option><option value="08">08 - August</option><option value="09">09 - September</option><option value="10">10 - October</option><option value="11">11 - November</option><option value="12">12 - December</option></select>

				<select style="width: 50px; margin-left: 15px;" name="dob_day" id="dob_day">
				<option value="" selected></option>
				<?php
				foreach(range(1, 31) as $day) {
					echo "<option value=\"$day\">$day</option>";
				}
				?>
				</select>

				<select style="width: 75px; margin-left: 15px;" name="dob_year" id="dob_year">
				<option value="" selected></option>
				<?php
				foreach(range(intval(date('Y') - 17), 1900, -1) as $year) {
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
			throw new ProductRequirementException('Date of Birth must be fully filled out.');
		}

		if (!is_numeric($data['dob_month']) || !is_numeric($data['dob_day']) || !is_numeric($data['dob_year'])) {
			throw new ProductRequirementException('Date of Birth is invalid.');
		}

		$day = intval($data['dob_day']);
		$month = intval($data['dob_month']);
		$year = intval($data['dob_year']);

		if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 1900 || $year > 2020) {
			throw new ProductRequirementException('Date of Birth is invalid.');
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

class AddressRequirement extends ProductRequirement {
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
				echo '<div id="address_container" class="ctrlHolder">
					<label for="address">Address</label>
					<select name="address" id="address">'
					. implode('', $address_dropdown_builder) .
					'</select></div>';
				$new_address_display = false;
				echo '<div id="new_address_block" style="display:none;">';
				Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));
				echo '</div>';
			} else {
				echo $formwriter->hiddeninput('address', 'new');
				Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));
			}
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
			/*'usa_state' => array(
				'required' => array('is_new_address', 'State must be set.')),*/
		);
	}

	function validate_form($data, $session) {
		if (empty($data['address'])) {
			throw new ProductRequirementException('The address section must be filled out.');
		}

		if ($data['address'] === 'new') {
			try {
				$address = Address::CreateAddressFromForm($data, $session->get_user_id());
				return array(
					array('address' => $address),
					array('Address' => $address->get_address_string(', '))
				);
			}	catch (AddressException $e) {
				throw new ProductRequirementException('Your address was invalid: ' . $e->getMessage());
			}
		} else {
			$address_key = LibraryFunctions::decode($data['address']);
			if ($address_key === FALSE) {
				throw new ProductRequirementException('You have selected an invalid address, please try again.');
			}
			$address = new Address($address_key, TRUE);
			$address->authenticate_write($session);
			return array(
				array('address' => $address),
				array('Address' => $address->get_address_string(', '))
			);
		}
	}
}


class GDPRNoticeRequirement extends ProductRequirement {
	const ID = 16;
	const LABEL = 'GDPR Notice';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo '<div id="gdpr_terms_container" class="ctrlHolder">';
		echo '<label for="gdpr_terms">Privacy Notice</label>';
		echo "<div><div onclick=\"$('#gdpr_terms').attr('checked', !$('#gdpr_terms').attr('checked')); return false;\" style=\"overflow:auto; height: 100px; border: 1px solid #DDDAD3; width: 45%; padding: 6px; margin-bottom: 5px; background-color: #f5f5f5;\">Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our privacy policy.</div>";
		echo '<label></label><input name="gdpr_terms" id="gdpr_terms" value="1" type="checkbox"  /><span onclick="$(\'#gdpr_terms\').attr(\'checked\', !$(\'#gdpr_terms\').attr(\'checked\')); return false;"> I have read and agree to the privacy policy.</span></div>';
	}

	function validate_form($data, $session) {
		if (empty($data['gdpr_terms'])) {
			throw new ProductRequirementException('You must have read and agreed to the privacy policy in order to continue.');
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

class RecordConsentRequirement extends ProductRequirement {
	const ID = 32;
	const LABEL = 'Consent to Record';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo '<div id="record_terms_container" class="ctrlHolder">';
		echo '<label for="record_terms">Recording Notice</label>';
		echo '<input name="record_terms" id="record_terms" value="1" type="checkbox"  /><span onclick="$(\'#record_terms\').attr(\'checked\', !$(\'#record_terms\').attr(\'checked\')); return false;"> I am aware that the course/event may be recorded and consent to being recorded. </span></div>';
	}

	function validate_form($data, $session) {
		if (empty($data['record_terms'])) {
			throw new ProductRequirementException('You must have read and agreed to the recording notice in order to continue.');
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

class EmailRequirement extends ProductRequirement {
	const ID = 64;
	const LABEL = 'Email';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("Email", "email", "ctrlHolder", 20, $user ? $user->get('usr_email') : '', '', 255, '');
		
	}

	public function validate_form($data, $session=NULL) {
		if (empty($data['email'])) {
			throw new ProductRequirementException('Email is Required');
		}
		
		if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
			$error = "Email address '".$data['email']."' is not valid.\n";
			throw new ProductRequirementException($error);
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

class UserPriceRequirement extends ProductRequirement {
	const ID = 128;
	const LABEL = 'User chooses price';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("Amount of donation $", "user_price", "ctrlHolder", 20, '', '', 255, '');
		
	}

	public function validate_form($data, $session=NULL) {
		if (empty($data['user_price'])) {
			throw new ProductRequirementException('Donation amount is required');
		}

		
		//CLEAN IT UP
		//REMOVE ANYTHING BUT NUMBERS AND A DOT AND CAST TO INTEGER, DROPPING THE CENTS
		//TODO NEED TO FIGURE OUT HOW TO HANDLE CENTS
		$data['user_price'] = (int)preg_replace("/[^0-9\.]/", "", $data['user_price']);
		
		if ($data['user_price'] == 0 || $data['user_price'] == '0.00') {
			throw new ProductRequirementException('Donation amount must be greater than zero.');
		}

		$return_array = array(
			'user_price' => $data['user_price'],
		);

		$display_array = array(
			'Donation amount $' => $data['user_price']. '.00',
		);

		return array(
			$return_array, $display_array);
	}

	public function get_validation_info() {
		return array(
			'user_price' => array('required' => array('true', 'Donation amount is required')),
		);
	}
}

class NewsletterSignupRequirement extends ProductRequirement {
	const ID = 256;
	const LABEL = 'Newsletter Signup';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo '<div id="newsletter_container" class="ctrlHolder">';
		echo '<label for="newsletter">Newsletter Signup</label>';
		echo '<input name="newsletter" id="newsletter" value="1" type="checkbox"  /><span> Please add me to the newsletter.</span></div>';
	}

	function validate_form($data, $session) {
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

class CommentRequirement extends ProductRequirement {
	const ID = 512;
	const LABEL = 'Comment';
	
	function get_id() {
        return  self::ID;
    }
	function get_label() {
        return  self::LABEL;
    }
	
	public function get_form($formwriter, $user=NULL) {
		echo $formwriter->textinput("Optional comment", "comment", "ctrlHolder", 20, '', '', 255, '');
		
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


class ProductVersion {
	// Constants for prv_status
	const ACTIVE = 1;
	const INACTIVE = 2;

	public static function StoreProductVersion($product_id, $version_name, $version_price, $state, $is_deposit=FALSE) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'INSERT INTO prv_product_versions ' .
			'(prv_pro_product_id, prv_version_name, prv_version_price, prv_status, prv_is_deposit)
				VALUES (?, ?, ?, ?)';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->bindValue(2, $version_name, PDO::PARAM_STR);
			$q->bindValue(3, $version_price, PDO::PARAM_STR);	
			$q->bindValue(4, $state, PDO::PARAM_INT);	
			$q->bindValue(5, $is_deposit, PDO::PARAM_BOOL);			
			
			$q->execute();
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function GetActiveProductVersion($product_id, $product_version_id) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT * FROM prv_product_versions WHERE 
			prv_pro_product_id = ? AND prv_product_version_id = ? AND prv_status = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->bindValue(2, $product_version_id, PDO::PARAM_INT);
			$q->bindValue(3, self::ACTIVE, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);

			if ($q->rowCount()) {
				return $q->fetch();
			} else {
				return NULL;
			}
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function GetAnyProductVersion($product_id, $product_version_id) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT * FROM prv_product_versions WHERE 
			prv_pro_product_id = ? AND prv_product_version_id = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->bindValue(2, $product_version_id, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);

			if ($q->rowCount()) {
				return $q->fetch();
			} else {
				return NULL;
			}
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function ChangeProductVersionState($product_id, $product_version_id, $new_state) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'UPDATE prv_product_versions SET prv_status = ? WHERE 
			prv_pro_product_id = ? AND prv_product_version_id = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $new_state, PDO::PARAM_INT);
			$q->bindValue(2, $product_id, PDO::PARAM_INT);
			$q->bindValue(3, $product_version_id, PDO::PARAM_INT);
			$q->execute();
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function GetProductVersionsForProduct($product_id, $valid_states=NULL) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT * FROM prv_product_versions
			WHERE prv_pro_product_id = ? ORDER BY prv_version_price DESC';

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$versions = array();
		foreach ($q->fetchall() as $product_version) {
			if ($valid_states === NULL || in_array($product_version->prv_status, $valid_states)) {
				$versions[] = $product_version;
			}
		}
		return $versions;
	}
}

class Product extends SystemBase {
	public static $required_fields = array();

	public static $fields = array(
		'pro_product_id' => 'Product ID',
		'pro_name' => 'Product name',
		'pro_description' => 'Product Description',
		'pro_price' => 'Price',
		'pro_requirements' => 'Requirements of this product',
		'pro_max_purchase_count' => 'Maximum number of this item that can be bought at one time',
		'pro_prg_product_group_id' => 'Product group this product is part of',
		'pro_after_purchase_message' => 'Message shown after purchase of the item',
		'pro_initial_odi_status' => 'After this product is purchased, what should the initial order status be',
		'pro_evt_event_id' => 'Event id if the order is for an event',
		'pro_user_choose_price' => 'When TRUE, the user can choose what price to pay.',
		'pro_recurring' => 'This charge is a recurring charge, valid values are "day", "week", "month", or "year"',
		'pro_expires' => 'How much time until the purchase expires.',
		'pro_is_active' => 'Active or disabled'
	);
	
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
	

	public function add_product_version($version_name, $version_price) {
		ProductVersion::StoreProductVersion(
			$this->key, $version_name, $version_price, ProductVersion::ACTIVE);
	}

	public function change_product_version_status($version_id, $status) {
		ProductVersion::ChangeProductVersionState(
			$this->key, $version_id, $status);
	}

	public function get_product_versions($valid_states=NULL) {
		return ProductVersion::GetProductVersionsForProduct($this->key, $valid_states);
	}

	public function get_product_version_details($product_version_id) {
		return ProductVersion::GetActiveProductVersion($this->key, $product_version_id);	
	}

	public function get_product_version($form_data) {
		$versions = $this->get_product_versions(array(ProductVersion::ACTIVE));

		if ($versions) {
			if (!array_key_exists('product_version', $form_data)) {
				throw new ProductRequirementException(
					'Sorry, one of the products in your cart is invalid.  Please clear your cart and try again.');
			}
			$version = $this->get_product_version_details(intval($form_data['product_version']));
			if (!$version) {
				throw new ProductRequirementException(
					'Sorry, one of the products in your cart is invalid.  Please clear your cart and try again.');
			}
			return $version;
		}
		return NULL;
	}
	

	function GetProductById($product_id) {
		$data = SingleRowFetch('pro_products', 'pro_product_id',
			$product_id, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

			$product = new Product($data->pro_product_id);
			$product->load_from_data($data, array_keys(Product::$fields));
			return $product;

	}

	function load() {
		parent::load();

		$this->data = SingleRowFetch('pro_products', 'pro_product_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

		if ($this->data === NULL) {
			throw new ProductException('Invalid product ID');
		}
	}

	function save() {
		// Saving requires some session control for authentication checking and whatnot
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('pro_product_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['pro_product_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "pro_products", $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['pro_product_id'];
	}
	
	function permanent_delete(){
		//CANNOT DELETE A PRODUCT WITH ORDERS
		$orders = new MultiOrderItem(array('product_id' => $this->key));
		if($orders->count_all()){
			throw new SystemDisplayableError("You cannot delete a product with orders.");
			exit();	
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM pro_products WHERE pro_product_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();	
		
		$q = $dblink->prepare('DELETE FROM prv_product_versions WHERE prv_pro_product_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();				
		
		$this->key = NULL;
		return true;			
	}	
	

	function get_url() {
		return '/product?product_id=' . $this->key;
	}

	
	function get_product_requirements() {
		return ProductRequirement::GetRequirements($this->get('pro_requirements'));
	}

	function clean_variables($data) {
		foreach($data as $key => $value) {
			$data[$key] = htmlspecialchars($value);
		}
		return $data;
	}

	function validate_form($data, $session) {
		$form_data = array();
		$form_display_data = array();

		// If the product has active product verisons, one of them must be selected!
		$versions = $this->get_product_versions(array(ProductVersion::ACTIVE));
		if ($versions) {
			if (!isset($data['product_version']) || !is_numeric($data['product_version'])) {
				throw new ProductRequirementException(
					'You must select which version of the product you would like to purchase.');
			}

			$version = $this->get_product_version_details(intval($data['product_version']));
			if ($version === NULL) {
				throw new ProductRequirementException(
					'Sorry, the product you have selected is not valid.  Please go back and try again.');
			}

			$form_display_data['Product'] = $version->prv_version_name;
			$form_data['product_version'] = $version->prv_product_version_id;
		}
		

		foreach ($this->get_product_requirements() as $product_requirement) {

			list($validation_data, $display_data) = $product_requirement->validate_form($data, $session);
			
			if ($validation_data !== NULL) {
				$form_data = array_merge($form_data, $validation_data);
			}
			if ($display_data !== NULL) {
				$form_display_data = array_merge($form_display_data, $display_data);
			}
		}

		$errors = array();
		foreach (static::$required_fields as $field => $error_message) {
			if (empty($data[$field])) {
				$errors[] = $error_message;
			}
		}
		if ($errors) {
			throw new ProductRequirementException(
				implode('<br>', $errors));
		}

		return array($form_data, $form_display_data);
	}

	function output_javascript($extra_data=array()) {
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

					$('#product_form').validate({
						errorElement: 'p',
							rules: " . str_replace('"', '', json_encode($rules)) . ",
							messages: " . str_replace('"', '', json_encode($messages)) . ",
					errorClass: 'errorField',
					highlight: function(element, errorClass) {
						$('#' + error_message_array[element.name]).addClass('error');
					},
					unhighlight: function(element, errorClass) {
						$('#' + error_message_array[element.name]).removeClass('error');
					},
					errorPlacement: function(error, element) {
						error.prependTo(element.parents('.errorplacement').eq(0));
					}
					});
			});
			";
		}
		echo '</script>';
	}
	

	function output_product_form($formwriter, $user, $extra_data=array()) {
		$versions = $this->get_product_versions(array(ProductVersion::ACTIVE));
		if ($versions) {
			$version_dropdown = array();
			foreach ($versions as $version) {
				$output_string = $version->prv_version_name . ' - $' . $version->prv_version_price;
				$version_dropdown[$output_string] = $version->prv_product_version_id;
			}
			echo $formwriter->dropinput(
				'Product',
				'product_version',
				'ctrlHolder',
				$version_dropdown,
				'',
				'',
				FALSE);
		}

		$form_javascript = array();
		foreach ($this->get_product_requirements() as $product_requirement) {
			$product_requirement->get_form($formwriter, $user);	
		}
		
		return TRUE;
	}
	

	function num_versions($type = 'active') {
		$versions = $this->get_product_versions(array(ProductVersion::ACTIVE));
		$count = 0;
		foreach($versions as $version){
			$count++;
		}
		return $count;
	}	
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS pro_products_pro_product_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."pro_products" (
			  "pro_product_id" int4 NOT NULL DEFAULT nextval(\'pro_products_pro_product_id_seq\'::regclass),
			  "pro_requirements" int4,
			  "pro_price" numeric(10,2) NOT NULL,
			  "pro_name" varchar(100) COLLATE "pg_catalog"."default" NOT NULL,
			  "pro_description" text COLLATE "pg_catalog"."default",
			  "pro_max_purchase_count" int4 DEFAULT 0,
			  "pro_prg_product_group_id" int4,
			  "pro_after_purchase_message" text COLLATE "pg_catalog"."default",
			  "pro_initial_odi_status" int4,
			  "pro_evt_event_id" int4,
			  "pro_user_choose_price" bool NOT NULL DEFAULT false,
			  "pro_recurring" varchar(10) COLLATE "pg_catalog"."default" NOT NULL,
			  "pro_is_active" bool DEFAULT true, 
			  "pro_expires" int4,
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."pro_products" ADD CONSTRAINT "pro_products_pkey" PRIMARY KEY ("pro_product_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		
		
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS prv_product_versions_prv_product_version_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."prv_product_versions" (
			  "prv_product_version_id" int4 NOT NULL DEFAULT nextval(\'prv_product_versions_prv_product_version_id_seq\'::regclass),
			  "prv_pro_product_id" int4 NOT NULL,
			  "prv_version_name" varchar(100) COLLATE "pg_catalog"."default" NOT NULL,
			  "prv_version_price" numeric(10,2) NOT NULL,
			  "prv_status" int2 NOT NULL,
			  "prv_order" int4,
			  "prv_percent_tax_deductible" int4
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."prv_product_versions" ADD CONSTRAINT "prv_product_versions_pkey" PRIMARY KEY ("prv_product_version_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}		

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
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


	private function _get_results($only_count=FALSE) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('product_group', $this->options)) {
			$where_clauses[] = 'pro_prg_product_group_id = ?';
			$bind_params[] = array($this->options['product_group'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'pro_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('product_id_is_not', $this->options)) {
			$where_clauses[] = 'pro_product_id != ?';
			$bind_params[] = array($this->options['product_id_is_not'], PDO::PARAM_INT);
		}	

		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();


		if ($this->order_by) {
			if (array_key_exists('product_id', $this->order_by)) {
				$order_by_string = ' pro_product_id '. $this->order_by['product_id'];
			}	
			
			if (array_key_exists('price_low', $this->order_by)) {
				$order_by_string = ' pro_price ASC';
			}		
			
			if (array_key_exists('price_high', $this->order_by)) {
				$order_by_string = ' pro_price DESC';
			}					
		}
		else {
			$order_by_string = ' pro_product_id '. $this->order_by['product_id'];
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM pro_products
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM pro_products
				' . $where_clause . '
				ORDER BY ' . $order_by_string . ' ' .$this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);

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

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new Product($row->pro_product_id);
			$child->load_from_data($row, array_keys(Product::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}

// Also require all the sub-products
foreach (glob($_SERVER['DOCUMENT_ROOT'] . '/data/products/*.php') as $sub) {
	require_once($sub);
}


?>
