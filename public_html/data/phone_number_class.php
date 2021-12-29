<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

class PhoneNumberException extends SystemDisplayableError {}
class DisplayablePhoneNumberException extends PhoneNumberException implements DisplayableErrorMessage {}

class PhoneNumber extends SystemBase {

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
		'phn_create_time' => 'now()');	
	
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
		$phone_number->set('phn_is_private', $form_data['phn_is_private']);

		$phone_number->set('phn_cco_country_code_id', $form_data['phn_cco_country_code_id']);


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

	function load() {
		parent::load();
		$this->data = SingleRowFetch('phn_phone_numbers', 'phn_phone_number_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new PhoneNumberException(
				'This phone number does not exist');
		}
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
		echo $formwriter->dropinput("Country code", "phn_cco_country_code_id", "ctrlHolder", $optionvals, ($phone_number ? $phone_number->get('phn_cco_country_code_id') : ''), '', FALSE);
		echo $formwriter->textinput("Phone Number*", "phn_phone_number", "ctrlHolder", 20, ($phone_number ? $phone_number->get('phn_phone_number') : ''), NULL , 20, "");
	}
	
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('phn_usr_user_id') != $current_user) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this number.');
			}
		}
	}
	
	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('phn_phone_number_id' => $this->key);
			// Editing an existing record
			unset($rowdata['phn_usr_user_id']);
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['phn_phone_number_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'phn_phone_numbers', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['phn_phone_number_id'];
	}	

	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}
		
		//DELETE AUTH CODES FOR PHONE
		$sql = 'UPDATE act_activation_codes SET act_deleted=TRUE WHERE act_phn_phone_number_id = :act_phn_phone_number_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':act_phn_phone_number_id', $this->key);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		//REMOVE LINKS WITH USERS
		$sql = 'UPDATE usr_users SET usr_phn_phone_number_id=NULL WHERE usr_phn_phone_number_id = :usr_phn_phone_number_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':usr_phn_phone_number_id', $phone_number->key);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$sql = 'DELETE FROM phn_phone_numbers WHERE phn_phone_number_id=:phn_phone_number_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':phn_phone_number_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		// Check for another phone number to set as default
		if ($phone_number->get('phn_is_default')) { 
			$phones = new MultiPhoneNumber(array('user_id' => $this->get('phn_usr_user_id'), 'deleted' => FALSE));
			$phones->load();
			if (count($phones)) { 
				$phones->get(0)->set_default(FALSE);
			}
		}		

		if($this_transaction){
			$dblink->commit();
		}
		
		$this->key = NULL;
		
		return true;		
	}
	
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS phn_phone_numbers_phn_phone_number_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."phn_phone_numbers" (
			  "phn_phone_number_id" int4 NOT NULL DEFAULT nextval(\'phn_phone_numbers_phn_phone_number_id_seq\'::regclass),
			  "phn_phone_number" varchar(30) COLLATE "pg_catalog"."default" NOT NULL,
			  "phn_is_private" bool NOT NULL DEFAULT true,
			  "phn_is_verified" bool NOT NULL DEFAULT false,
			  "phn_usr_user_id" int4 NOT NULL,
			  "phn_phone_carrier" varchar(64) COLLATE "pg_catalog"."default",
			  "phn_is_default" bool NOT NULL DEFAULT false,
			  "phn_create_time" timestamp(6) DEFAULT now(),
			  "phn_cco_country_code_id" int4
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."phn_phone_numbers" ADD CONSTRAINT "phn_phone_numbers_pkey" PRIMARY KEY ("phn_phone_number_id");';
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

	function load() {
		parent::load();

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

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
			$where_clause = 'WHERE ' . implode(' AND ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		//GET THE DATA
		$sql = "
			SELECT * FROM phn_phone_numbers
			" . $where_clause . "
			ORDER BY phn_phone_number_id DESC" . $this->generate_limit_and_offset();

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

		// So now we have everything from this thread.
		foreach($q->fetchAll() as $row) {
			$child = new PhoneNumber($row->phn_phone_number_id);
			$child->load_from_data($row, array_keys(PhoneNumber::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

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
			$where_clause = 'WHERE ' . implode(' AND ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		//GET THE DATA
		$sql = "
			SELECT COUNT(1) as total_count FROM phn_phone_numbers
			" . $where_clause;

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
	
		$row = $q->fetch();	
		return $row->total_count;
	}
}

?>
