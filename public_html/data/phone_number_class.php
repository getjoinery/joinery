<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class PhoneNumberException extends SystemDisplayableError {}
class DisplayablePhoneNumberException extends PhoneNumberException implements DisplayableErrorMessage {}

class PhoneNumber extends SystemBase {	public static $prefix = 'phn';
	public static $tablename = 'phn_phone_numbers';
	public static $pkey_column = 'phn_phone_number_id';
	public static $permanent_delete_actions = array(		'act_activation_codes' => 'delete',
		'usr_phn_phone_number_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

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
	    'phn_phone_number_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'phn_phone_number' => array('type'=>'varchar(30)', 'required'=>true),
	    'phn_is_private' => array('type'=>'bool'),
	    'phn_is_verified' => array('type'=>'bool'),
	    'phn_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'phn_phone_carrier' => array('type'=>'varchar(64)'),
	    'phn_is_default' => array('type'=>'bool'),
	    'phn_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'phn_cco_country_code_id' => array('type'=>'int4', 'default'=>1),
	);

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
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

}

class MultiPhoneNumber extends SystemMultiBase {
	protected static $model_class = 'PhoneNumber';

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

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['phn_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['country_code'])) {
			$filters['phn_cco_country_code_id'] = [$this->options['country_code'], PDO::PARAM_INT];
		}

		if (isset($this->options['verified'])) {
			$filters['phn_is_verified'] = $this->options['verified'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['private'])) {
			$filters['phn_is_private'] = $this->options['private'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['phone_number'])) {
			$filters['phn_phone_number'] = [$this->options['phone_number'], PDO::PARAM_STR];
		}

		if (isset($this->options['phone_number_like'])) {
			$filters['phn_phone_number'] = 'LIKE \'%'.$this->options['phone_number_like'].'%\'';
		}

		return $this->_get_resultsv2('phn_phone_numbers', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
