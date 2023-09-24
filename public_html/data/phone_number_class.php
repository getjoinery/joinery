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

class PhoneNumberException extends SystemDisplayableError {}
class DisplayablePhoneNumberException extends PhoneNumberException implements DisplayableErrorMessage {}

class PhoneNumber extends SystemBase {
	public static $prefix = 'phn';
	public static $tablename = 'phn_phone_numbers';
	public static $pkey_column = 'phn_phone_number_id';
	public static $permanent_delete_actions = array(
		'phn_phone_number_id' => 'delete',	
		'act_activation_codes' => 'delete',
		'usr_phn_phone_number_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	
	
	public static $fields = array(
		'phn_phone_number_id' => 'Phone number id',
		'phn_phone_number' => 'Phone number',
		'phn_is_private' => 'Is this phone number private?',
		'phn_is_verified' => 'Is this phone number verified?',
		'phn_usr_user_id' => 'User who owns this phone #',
		'phn_phone_carrier' => 'Carrier of this phone number',
		'phn_is_default' => 'Is this the users default phone #?',
		'phn_create_time' => 'Creation time', 
		'phn_cco_country_code_id' => 'Country code for the phone number',
	);

	public static $field_specifications = array(
		'phn_phone_number_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'phn_phone_number' => array('type'=>'varchar(30)'),
		'phn_is_private' => array('type'=>'bool'),
		'phn_is_verified' => array('type'=>'bool'),
		'phn_usr_user_id' => array('type'=>'int4'),
		'phn_phone_carrier' => array('type'=>'varchar(64)'),
		'phn_is_default' => array('type'=>'bool'),
		'phn_create_time' => array('type'=>'timestamp(6)'), 
		'phn_cco_country_code_id' => array('type'=>'int4'),
	);
				 
	public static $required_fields = array(
		'phn_phone_number', 'phn_usr_user_id');

	public static $field_constraints = array(
	/*
		'phn_field' => array(
			array('WordLength', 0, 255),
			'NoSymbols',
			'NoCaps',
			),
		'phn_field' => array(
			array('WordLength', 50, 100000),
			'NoEmailAddress',
			'NoCaps',
			),*/
		);
		
	public static $zero_variables = array();

	public static $initial_default_values = array(
		'phn_create_time' => 'now()', 'phn_cco_country_code_id' => 1);	
	
	public static $phone_carriers = array(
		'Alltel'=>'message.alltel.com',
		'AT&T'=>'txt.att.net',
		'Boost'=>'myboostmobile.com',
		'Cricket'=>'mms.mycricket.com',
		'MetroPCS'=>'mymetropcs.com',
		'nTelos'=>'pcs.ntelos.com',
		'Sprint'=>'messaging.sprintpcs.com',
		'T-Mobile USA'=>'tmomail.net',
		'Verizon Wireless'=>'vtext.com',
		'Virgin Mobile USA'=>'vmobl.com'
	);	
	
	public static function CreateFromForm($form_data, $owner, $phone_number = NULL, $use_transaction=TRUE) {
			
		$new_phone_number = FALSE;
		if(!$phone_number){
			$new_phone_number = TRUE;
			$phone_number = new PhoneNumber(NULL);		
			$phone_number->set('phn_is_verified', FALSE);
			$phone_number->set('phn_usr_user_id', $owner);
		}
		
		$phone_number->set('phn_phone_number', preg_replace('/[^0-9]/', '', $form_data['phn_phone_number']));
		
		if($form_data['phn_is_private']){
			$phone_number->set('phn_is_private', $form_data['phn_is_private']);
		}
		
		if($form_data['phn_cco_country_code_id']){
			$phone_number->set('phn_cco_country_code_id', $form_data['phn_cco_country_code_id']);
		}

		// If they have already entered this phone number, just return that
		$duplicate_number = $phone_number->check_for_duplicates();
		if ($duplicate_number) {
			return $duplicate_number->key;
		}

		if ($use_transaction) {
			$dbhelper = DbConnector::get_instance();
			$dblink = $dbhelper->get_db_link();
			$dblink->beginTransaction();
		}

		try {
			$phone_number->prepare();
			$phone_number->save();

			// Here we also want to check and see if this is the user's first phone #.
			// if so, make it the default
			$user = new User($owner, TRUE);
			if ($user->get('usr_phn_phone_number_id') === NULL) {
				// User has no default phone #
				$user->set('usr_phn_phone_number_id', $phone_number->key);
				$user->save();
				$phone_number->set('phn_is_default', TRUE);
				$phone_number->save();
			}

			if ($use_transaction) {
				$dblink->commit();
			}
		} catch (PDOException $e) {
			if ($use_transaction) {
				$dblink->rollBack();
			}
			$dbhelper->handle_query_error($e);
		}
		
		return $phone_number->key;
	}

	function check_for_duplicates() {
		$number_count = new MultiPhoneNumber(array(
			'deleted' => FALSE,
			'phone_number' => $this->get('phn_phone_number'),
			'user_id' => $this->get('phn_usr_user_id'),
			'country_code' => $this->get('phn_cco_country_code_id'),
		));
		if ($number_count->count_all()) {
			$number_count->load();
			return $number_count->get(0);
		}
		return NULL;
	}

	function export_as_array() {
		$phone_data = parent::export_as_array();
		$phone_data['phone_string'] = $this->get_phone_string();
		return $phone_data;
	}


	function prepare() {

		if (strlen($this->get('phn_phone_number')) == 11) {
			// If they gave us 11 digits, and the first one is a one, kill it
			$phone_string = $this->get('phn_phone_number');
			if ($phone_string[0] == '1') {
				$this->set('phn_phone_number', substr($phone_string, 1));
			}
		}
		/*
		if (strlen($this->get('phn_phone_number')) > 10) {
			throw new DisplayablePhoneNumberException(
				'This phone number has too many digits.  Please enter only your area code and phone number, like this: 800-555-1234.  We do not support extensions right now.');
		}
		*/
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		// Check for duplicates
		if(!$this->key && $this->check_for_duplicates()) {
			throw new DisplayablePhoneNumberException(
				'This phone number (' .$this->get_phone_string() .') has already been entered in your account.  Please go back and select that existing phone number.');				
		}
	}	
	
	function get_phone_string() {
		//TODO FORMAT PHONE NUMBERS
		return $this->get_formatted_country_code(). ' ' .$this->get('phn_phone_number');
		/*
		switch(strlen($this->get('phn_phone_number'))) {
			case 7:
				return preg_replace(
					"/([0-9a-zA-Z]{3})([0-9a-zA-Z]{4})/",
					"$1-$2",
					$this->get('phn_phone_number'));
			case 10:
				return preg_replace(
					"/([0-9a-zA-Z]{3})([0-9a-zA-Z]{3})([0-9a-zA-Z]{4})/",
					"($1) $2-$3",
					$this->get('phn_phone_number'));
			case 11:
				return preg_replace(
					"/([0-9a-zA-Z]{1})([0-9a-zA-Z]{3})([0-9a-zA-Z]{3})([0-9a-zA-Z]{4})/",
					"$1($2) $3-$4",
					$this->get('phn_phone_number'));
			default:
				return $this->get('phn_phone_number');
		}
		*/
	}
	
	function get_formatted_country_code(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();


		$sql = "SELECT cco_code FROM cco_country_codes WHERE cco_country_code_id=?";
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $this->get('phn_cco_country_code_id'), PDO::PARAM_INT);
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$country_code = $q->fetch();
		return '+'.$country_code->cco_code;			
	}

	function get_phone_string_anonymized() {
		return preg_replace(
			"/([0-9a-zA-Z\-]+)([0-9a-zA-Z]{4})$/",
			"$1XXXX",
			$this->get_phone_string());
	}
	
	static function get_country_code_drop_array(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();


		$sql = "SELECT cco_country_code_id, cco_code, cco_country FROM cco_country_codes WHERE TRUE";
		try {
			$q = $dblink->prepare($sql);
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$optionvals = array();
		while ($country = $q->fetch()) {
			$countryval = '+'.$country->cco_code . ' ' . $country->cco_country;
			$optionvals[$countryval] = $country->cco_country_code_id;
		}
		return $optionvals;		
	}	
	
	
	function set_default($use_transaction=TRUE) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		if ($use_transaction) {
			$dblink->beginTransaction();
		}		
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		//SET ALL DEFAULT FOR THIS USER TO ZERO
		$sql = "UPDATE phn_phone_numbers SET phn_is_default = FALSE WHERE phn_usr_user_id = :usr_user_id";
	
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':usr_user_id', $this->get('phn_usr_user_id'), PDO::PARAM_INT);
			$success = $q->execute();
		}
		catch(PDOException $e){
			if ($use_transaction) {
				$dblink->rollBack();
			}
			$dbhelper->handle_query_error($e);
		}
	
	
		$sql = "UPDATE phn_phone_numbers SET phn_is_default = TRUE WHERE phn_phone_number_id = :phn_phone_number_id";
	
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':phn_phone_number_id', $this->key, PDO::PARAM_INT);
			$success = $q->execute();
		}
		catch(PDOException $e){
			if ($use_transaction) {
				$dblink->rollBack();
			}
			$dbhelper->handle_query_error($e);
		}
	
		$sql = "UPDATE usr_users SET usr_phn_phone_number_id = :phn_phone_number_id WHERE usr_user_id = :usr_user_id";
	
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':phn_phone_number_id', $this->key, PDO::PARAM_INT);
			$q->bindParam(':usr_user_id', $this->get('phn_usr_user_id'), PDO::PARAM_INT);
			$success = $q->execute();
		}
		catch(PDOException $e){
			if ($use_transaction) {
				$dblink->rollBack();
			}
			$dbhelper->handle_query_error($e);
		}
		
		if ($use_transaction) {
			$dblink->commit();
		}
	}	
	

	public static function PlainForm($formwriter, $phone_number=NULL, $options=NULL) {
		if($phone_number){
			echo $formwriter->hiddeninput('phn_phone_number_id', $phone_number->key);
		}
		$optionvals = PhoneNumber::get_country_code_drop_array();
		echo $formwriter->dropinput("Country code", "phn_cco_country_code_id", "", $optionvals, ($phone_number ? $phone_number->get('phn_cco_country_code_id') : ''), '', FALSE);
		echo $formwriter->textinput("Phone Number", "phn_phone_number", "", 20, ($phone_number ? $phone_number->get('phn_phone_number') : ''), NULL , 20, "");
	}
	
	
	
	function authenticate_write($data) {
		if ($this->get($this->prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. $this->tablename);
			}
		}
	}
	
	
}


class MultiPhoneNumber extends SystemMultiBase {

	function get_dropdown_options($selected_key=NULL, $include_new=TRUE, $include_none=FALSE) { 
		$dropdown_builder = array();
		if ($include_none) {
			$dropdown_builder[] = '<option value="-">No Phone Number</option>';
		}
		foreach($this as $phone) {
			if ($phone->get_phone_string()) {
				$dropdown_builder[] =
					'<option value="' . $phone->key . '" ' .
					(($phone->key == $selected_key) ? 'selected' : '') .  '>' . 
					$phone->get_phone_string() . 
					($phone->get('phn_is_private') ?  ' (private)' : ' (public)') . 
					'</option>';
			}
		}
		if ($include_new) {
			$dropdown_builder[] = '<option value="new">Add New Phone Number</option>';
		}
		return $dropdown_builder;
	}



	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'phn_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('country_code', $this->options)) {
			$where_clauses[] = 'phn_cco_country_code_id = ?';
			$bind_params[] = array($this->options['country_code'], PDO::PARAM_INT);
		}		

		if (array_key_exists('verified', $this->options)) {
			$where_clauses[] = 'phn_is_verified = ' . ($this->options['verified'] ? 'TRUE' : 'FALSE');
		}
		
		if (array_key_exists('private', $this->options)) {
			$where_clauses[] = 'phn_is_private = ' . ($this->options['private'] ? 'TRUE' : 'FALSE');
		}		

		if (array_key_exists('phone_number', $this->options)) {
			$where_clauses[] = 'phn_phone_number = ?';
			$bind_params[] = array($this->options['phone_number'], PDO::PARAM_STR);
		}

		if (array_key_exists('phone_number_like', $this->options)) {
			$where_clauses[] = 'phn_phone_number LIKE ?';
			$bind_params[] = array('%'.$this->options['phone_number_like'].'%', PDO::PARAM_STR);
		}	
	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM phn_phone_numbers ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM phn_phone_numbers
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " phn_phone_number_id ASC ";
			}
			else {
				/*
				if (array_key_exists('phone_number_id', $this->order_by)) {
					$sql .= ' phn_phone_number_id ' . $this->order_by['phone_number_id'];
				}	
				*/				
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
			$child = new PhoneNumber($row->phn_phone_number_id);
			$child->load_from_data($row, array_keys(PhoneNumber::$fields));
			$this->add($child);
		}
	}


}

?>
