<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class AddressException extends SystemClassException {}
class DisplayableAddressException extends AddressException implements DisplayableErrorMessage {}
class AddressTravelMismatchException extends DisplayableAddressException {}

class Address extends SystemBase {	public static $prefix = 'usa';
	public static $tablename = 'usa_users_addrs';
	public static $pkey_column = 'usa_users_addr_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	const PRIVACY_SHOW_ALL = 1;
	const PRIVACY_SHOW_CLIENTS = 2;
	const PRIVACY_SHOW_NEVER = 3;

	public static $google_address_precision = array(
		0=>"Unknown location",
		1=>"Country level accuracy",
		2=>"Region (state, province, prefecture, etc.) level accuracy",
		3=>"Sub-region (county, municipality, etc.) level accuracy",
		4=>"Town (city, village) level accuracy",
		5=>"Post code (zip code) level accuracy",
		6=>"Street level accuracy",
		7=>"Intersection level accuracy",
		8=>"Address level accuracy",
		9=>"Premise (building name, property name, shopping center, etc.) level accuracy"
	);

	public static $states = array(
		'AL'=>"Alabama",
		'AK'=>"Alaska",
		'AS'=>"American Samoa",
		'AZ'=>"Arizona",
		'AR'=>"Arkansas",
		'CA'=>"California",
		'CO'=>"Colorado",
		'CT'=>"Connecticut",
		'DE'=>"Delaware",
		'DC'=>"District Of Columbia",
		'FM'=>"Federated States of Micronesia",
		'FL'=>"Florida",
		'GA'=>"Georgia",
		'GU'=>"Guam",
		'HI'=>"Hawaii",
		'ID'=>"Idaho",
		'IL'=>"Illinois",
		'IN'=>"Indiana",
		'IA'=>"Iowa",
		'KS'=>"Kansas",
		'KY'=>"Kentucky",
		'LA'=>"Louisiana",
		'ME'=>"Maine",
		'MH'=>"Marhsall Islands",
		'MD'=>"Maryland",
		'MA'=>"Massachusetts",
		'MI'=>"Michigan",
		'MN'=>"Minnesota",
		'MS'=>"Mississippi",
		'MO'=>"Missouri",
		'MT'=>"Montana",
		'NE'=>"Nebraska",
		'NV'=>"Nevada",
		'NH'=>"New Hampshire",
		'NJ'=>"New Jersey",
		'NM'=>"New Mexico",
		'NY'=>"New York",
		'NC'=>"North Carolina",
		'ND'=>"North Dakota",
		'MP'=>"Northern Mariana Islands",
		'OH'=>"Ohio",
		'OK'=>"Oklahoma",
		'OR'=>"Oregon",
		'PW'=>"Palau",
		'PA'=>"Pennsylvania",
		'PR'=>"Puerto Rico",
		'RI'=>"Rhode Island",
		'SC'=>"South Carolina",
		'SD'=>"South Dakota",
		'TN'=>"Tennessee",
		'TX'=>"Texas",
		'UT'=>"Utah",
		'VT'=>"Vermont",
		'VI'=>"Virgin Islands",
		'VA'=>"Virginia",
		'WA'=>"Washington",
		'WV'=>"West Virginia",
		'WI'=>"Wisconsin",
		'WY'=>"Wyoming"
	);

	public static $fields = array(
		'usa_users_addr_id' => 'Primary key - Address ID',
		'usa_type' => 'Address type',
		'usa_address1' => 'Line 1 of address',
		'usa_address2' => 'Line 2 of address',
		'usa_city' => 'City',
		'usa_state' => 'State',
		'usa_zip_code_id' => 'Zip code',
		'usa_usr_user_id' => 'User Id this address belongs to',
		'usa_is_default' => 'Is this the default address?',
		'usa_timezone' => 'Timezone this address is in',
		'usa_create_time' => 'time created', 
		'usa_cco_country_code_id' => 'Country code id',
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
		'usa_users_addr_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false), 
		'usa_type' => array('type'=>'int2'),
		'usa_address1' => array('type'=>'varchar(128)'),
		'usa_address2' => array('type'=>'varchar(64)'),
		'usa_city' => array('type'=>'varchar(64)'),
		'usa_state' => array('type'=>'varchar(32)'),
		'usa_zip_code_id' => array('type'=>'varchar(10)'),
		'usa_usr_user_id' => array('type'=>'int8', 'is_nullable'=>false),
		'usa_is_default' => array('type'=>'bool'),
		'usa_timezone' => array('type'=>'varchar(64)'),
		'usa_create_time' => array('type'=>'timestamp(6)'),
		'usa_cco_country_code_id' => array('type'=>'int2'),
	);

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();

	public static $initial_default_values = array(
		'usa_create_time' => 'now()');

	public static $json_vars = array(
		'usa_address1', 'usa_address2', 'usa_city', 'usa_state', 'usa_zip_code_id',
	);

	public static $json_prefix = 'usa_';

	private static function UcAddress($string) {
		$test_string = preg_replace('/[^A-Za-z]/', '', $string);
		if(ctype_lower($test_string) || ctype_upper($test_string) ){
		    $string = ucwords(strtolower($string));
		}
	    return $string;
	}

	public static function PlainForm($formwriter, $address=NULL, $options=NULL) {

		if($address){
			echo $formwriter->hiddeninput('usa_address_id', $address->key);
		}
		
		$optionvals = Address::get_country_drop_array2();
		echo $formwriter->dropinput("Country", "usa_cco_country_code_id", "sm:col-span-6", $optionvals, ($address ? $address->get('usa_cco_country_code_id') : ''), '', FALSE);
		echo $formwriter->textinput("Street Address", "usa_address1", "sm:col-span-6", 20, ($address ? $address->get('usa_address1') : ''), "", 255,"");
		echo $formwriter->textinput("Apt, Suite, etc. (optional)", "usa_address2", "sm:col-span-6", 20, ($address ? $address->get('usa_address2') : ''), "", 255,"");
		echo $formwriter->textinput("City", "usa_city", "sm:col-span-6", 20, ($address ? $address->get('usa_city') : ''), "", 255,"");
		echo $formwriter->textinput("State/Province", "usa_state", "sm:col-span-6", 20, ($address ? $address->get('usa_state') : ''), "", 255,"");
		//echo $formwriter->generatestatedrop("State/Province", "usa_state", "sm:col-span-6", ($address ? $address->get('usa_state') : ''));
		echo $formwriter->textinput("Zip/Postcode", "usa_zip_code_id", "sm:col-span-6", 20, ($address ? $address->get('usa_zip_code_id') : ''), "", 255,"");
	}

	public static function IsInMetroCode($address, $metro_code) {
		// Check to see if the given address is in one of the given area codes
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT 1
			FROM geoip.locations WHERE
			metro_code = ? AND postal_code = ? LIMIT 1';

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $metro_code, PDO::PARAM_INT);
			$q->bindValue(2, $address->get('usa_zip_code_id'), PDO::PARAM_STR);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q->fetch();
	}
	
	public static function GetCountryCodeFromCountryAbbr($abbr){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT cco_code FROM cco_country_codes WHERE cco_iso_code_2=?";
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $abbr, PDO::PARAM_STR);
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$country_code = $q->fetch();
		return $country_code->cco_code;			
	}

	public static function GetCountryAbbrFromCountryCode($code){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT cco_iso_code_2 FROM cco_country_codes WHERE cco_code=?";
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $code, PDO::PARAM_INT);
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$country_code = $q->fetch();
		return $country_code->cco_iso_code_2;			
	}

	public static function CreateAddressFromForm($form, $user_id, $address = NULL, $and_save=TRUE, $use_transaction=TRUE, $strict_checks=TRUE) {
		
		$new_address = FALSE;
		if(!$address){
			$new_address = TRUE;
			$address = new Address(NULL);
			$address->set('usa_is_default', FALSE);
			$address->set('usa_usr_user_id', $user_id);
			$address->set('usa_is_bad', FALSE);
		}

		foreach(array(
			'usa_cco_country_code_id', 'usa_type', 'usa_address1', 'usa_address2', 'usa_city', 'usa_state',
			'usa_zip_code_id', 'usa_privacy') as $form_field) {
			if (isset($form[$form_field])) {
				$address->set($form_field, $form[$form_field]);
			}
		}

		/*
		if ($strict_checks && !$address->get('usa_address1')) {
			throw new DisplayableAddressException(
				'Your address must have a street address');
		}
		*/

		/*
		if (!$strict_checks && $address->get('usa_privacy') === NULL) {
			$address->set('usa_privacy', 2); // default to a private address
		}
		*/

		//TODO MORE ADDRESS CHECKING
		// If there is no city/state, grab it based of the zip code
		/*
		if (!$address->get('usa_city') || !$address->get('usa_state')) {
			$address->update_city_state_from_zip();
		}
		*/

		// If there is a duplicate address, just return that one seamlessly!
		if($new_address){
			$duplicate = $address->check_for_duplicate();
			if ($duplicate) {
				return $duplicate;
			}
		}

		$address->prepare();

		if ($and_save) {
			$address->save();
			$address->load();
			//$address->update_coordinates();

			// So now the user has entered a new address.  If this address is valid and their
			// default is not, lets swap it and delete the default one
			if ($address->get('usa_usr_user_id') && !$address->get('usa_is_bad')) {
				$user = new User($user_id, TRUE);
				if($default_address_id = $user->get_default_address()){
					$default_address = new Address($default_address_id, TRUE);
				}
				else{
					$default_address = NULL;
				}
				
				if ($default_address === NULL || $default_address->get('usa_is_bad')) {
					// The default address is bad, and the given one is good.
					// Kill the default one!
					$user->set_default_address($address->key, $use_transaction);

				}
			}
		}

		return $address;
	}

	public static function GetStateSelectOptions($default='') {
		$str = '';
		foreach (self::$states as $code => $name) {
			$str .= '<option value="' . $code . '" ' .
				($default == $code ? 'selected="selected"' : '') . '>' . $code . '</option>';
		}
		return $str;
	}
	public function check_for_duplicate($fields=NULL, $search_deleted=false) {
		//FIELDS WILL BE UNUSED IN THIS FUNCTION, INCLUDED TO MATCH SYSTEMCLASS DECLARATION
		
		// See if there is a duplicate address to this one for this user already!
		$address_count = new MultiAddress(array(
			'address1_lower' => $this->get('usa_address1'),
			'address2_lower' => $this->get('usa_address2'),
			'zip_code_lower' => $this->get('usa_zip_code_id'),
			'user_id' => $this->get('usa_usr_user_id'),
			'state_lower' => $this->get('usa_state'),
			'city_lower' => $this->get('usa_city'),
			'deleted' => FALSE,
		));
		if ($address_count->count_all()) {
			$address_count->load();
			return $address_count->get(0);
		}
		return NULL;
	}

	public static function GetDefaultAddressForUser($user_id) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		//SET ALL DEFAULT FOR THIS USER TO ZERO
		$sql = "SELECT usa_users_addr_id FROM usa_users_addrs
			WHERE usa_usr_user_id = :usr_user_id AND usa_is_default = TRUE";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':usr_user_id', $user_id, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		if (!$q->rowCount()) {
			//throw new AddressException('This user doesn\'t have a default address.');
			return FALSE;
		}

		$r = $q->fetch();

		return $r->usa_users_addr_id;
	}

	public static function SetDefaultAddressForUser($user_id, $address_id, $use_transaction=TRUE) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// Run this operation in a transaction, since we rely on the SELECT from the first
		// query for the UPDATE in the 2nd one
		if ($use_transaction) {
			$dblink->beginTransaction();
		}

		$address = new Address($address_id, TRUE);
		if ($address->get('usa_usr_user_id') != $user_id) {
			if ($use_transaction) {
				$dblink->rollBack();
			}
			throw new AddressException('Invalid Address or User.');
		}

		//SET ALL DEFAULT FOR THIS USER TO ZERO
		$sql = "UPDATE usa_users_addrs
			SET usa_is_default = (usa_users_addr_id = :address_id)
			WHERE usa_usr_user_id = :usr_user_id";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':address_id', $address_id, PDO::PARAM_INT);
			$q->bindValue(':usr_user_id', $user_id, PDO::PARAM_INT);
			$q->execute();
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

	function export_as_array() {
		$address_data = array();
		foreach(array_keys(self::$fields) as $field) {
			$address_data[$field] = $this->get($field);
		}

		$address_data['city_state_string'] = $this->get_city_state_string();

		unset($address_data['usa_coordinates_ll']);
		unset($address_data['usa_coordinates_proj_m']);
		unset($address_data['usa_coordinates_ll_private']);

		$address_data['privacy_checked_display_string'] = $this->get_privacy_checked_address_string(', ');
		$address_data['display_string'] = $this->get_address_string(', ');

		return $address_data;
	}

	function get_json() {
		// build the json-ready PHP object (to be passed into json_encode)
		$json = array();
		foreach(self::$json_vars as $field) {
			// strip out the prefix when shipping as JSON, also make sanitary for display
			$json[str_replace(self::$json_prefix, '', $field)] = htmlspecialchars($this->get($field));
		}
		return $json;
	}

	function get_number_and_street() {
		$exploded_street = explode(' ', $this->get('usa_address1'), 1);
		if (count($exploded_street) == 2) {
			if (is_numeric($exploded_street[0])) {
				return $exploded_street;
			}
		}
		return NULL;
	}

	function prepare() {

		//TODO MORE CHECKING FOR US
		// Only pull the first 5 digits of the zip code
		//$this->set('usa_zip_code_id', substr($this->get('usa_zip_code_id'), 0, 5));

		//if (!is_numeric($this->get('usa_zip_code_id'))) {
		//	throw new DisplayableAddressException('The zip code you entered must be either 5 or 9 digits.  Please double check your zip code is in the proper format.');
		//}

		//if ($this->get('usa_state') && !array_key_exists($this->get('usa_state'), self::$states)) {
		//	throw new DisplayableAddressException('The state you have entered is invalid.  Please go back and double check you have entered a valid 2 letter state code.');
		//}

		// Get information about the zip code
		/*
		$zip_data = SingleRowFetch(
			'zips.zip_codes', 'zip_code_id', $this->get('usa_zip_code_id'), PDO::PARAM_INT,
			SINGLE_ROW_ALL_COLUMNS);
		if ($zip_data === NULL) {
			// Zip code is invalid
			throw new DisplayableAddressException('Invalid zip code.');
		}

		$this->set('usa_timezone', $zip_data->zip_timezone);

		if (!$this->get('usa_city')) {
			$this->set('usa_city', $zip_data->zip_city);
		}

		if (!$this->get('usa_state')) {
			$this->set('usa_state', $zip_data->zip_state);
		}
		*/

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// Check to ensure they aren't entering a duplicate address
		if ($this->key === NULL && $this->check_for_duplicate()) {
			throw new DisplayableAddressException(
				'This address (' . $this->get_address_string(', ') . ') has already been entered in your account.  Please select it from the dropdown instead of re-entering it.');
		}

		//CAPITALIZATION
		$this->set('usa_city', ucwords($this->get('usa_city')));
		$this->set('usa_address1', Address::UcAddress($this->get('usa_address1')));
		$this->set('usa_address2', Address::UcAddress($this->get('usa_address2')));

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

	function is_owner($session) {
		return $this->get('usa_usr_user_id') === $session->get_user_id();
	}

	function get_distance_between($other_address, $force_private=FALSE) {
		return Address::GetDistanceBetweenLocations(
			$this->get_location($force_private),
		 	$other_address->get_location($force_private));
	}

	function get_location($force_private=FALSE) {
		if (!$this->get('x')) {
			return NULL;
		}

		if ($this->get('usa_privacy') > 1 || $force_private) {
			return array($this->get('x_priv'), $this->get('y_priv'));
		}
		return array($this->get('x'), $this->get('y'));
	}

	function get_privacy_checked_address_string($seperator=', ') {
		if ($this->get('usa_privacy') > 1) {
			return $this->get_city_state_string() . ' ' . $this->get('usa_zip_code_id');
		}
		else {
			return $this->get_address_string($seperator);
		}
	}

	function get_address_string($seperator='\n') {
		$address_out = array();
		if ($this->get('usa_address1')) {
			$address_out[] = ucwords(strtolower($this->get('usa_address1')));
		}
		if ($this->get('usa_address2')) {
			$address_out[] = ucwords(strtolower($this->get('usa_address2')));
		}
		$address_out[] = $this->get_city_state_string() . ' ' . ucwords(strtolower($this->get('usa_zip_code_id')));
		return implode($seperator, $address_out);
	}

	function get_city_state_string() {
		if ($this->get('usa_city') && $this->get('usa_state')) {
			return ucwords(strtolower($this->get('usa_city'))) . ', ' . strtoupper($this->get('usa_state'));
		}
		return NULL;
	}

	function get_privacy_checked_microformat($force_public=FALSE) {
		$state_full = (array_key_exists($this->get('usa_state'), self::$states)) ? self::$states[$this->get('usa_state')] : '';

		$microformat = array();
		if ($this->get('usa_privacy') < 2 || $force_public) {
			$microformat[] = '<span class="street-address">' . htmlspecialchars($this->get('usa_address1')) . '</span><br/>';
		}
		$microformat[] = '<span class="locality">' . ucwords(strtolower($this->get('usa_city'))) . '</span>,&nbsp;';
		$microformat[] = '<abbr class="region" title="' . $state_full . '">' . strtoupper($this->get('usa_state')) . '</abbr>&nbsp;';
		$microformat[] = '<span class="postal-code">' . $this->get('usa_zip_code_id') . '</span>';
		return implode($microformat);
	}

	function get_type() {
		switch($this->get('usa_type')) {
			case 'HM':
				return 'Home';
			default:
				return 'Business';
		}
	}

	function get_privacy_status() {
		if ($this->get('usa_privacy') == 1) {
			return 'Public';
		}
		else if ($this->get('usa_privacy') == 2) {
			return 'Private';
		}
	}
	
	static function get_country_drop_array() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT * FROM country WHERE TRUE";
		try {
			$q = $dblink->prepare($sql);
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$optionvals = array();
		while ($country = $q->fetch()) {
			$optionvals[$country->country_name] = $country->country_code;
		}
		return $optionvals;
	}
	
	static function get_country_drop_array2(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT cco_country_code_id, cco_country FROM cco_country_codes WHERE TRUE";
		try {
			$q = $dblink->prepare($sql);
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$optionvals = array();
		while ($country = $q->fetch()) {
			$optionvals[$country->cco_country] = $country->cco_country_code_id;
		}
		return $optionvals;		
	}

	static function get_timezone_drop_array($country_code = NULL) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$whereclause = 'TRUE';
		if($country_code){	
			$whereclause = 'country_code = :country_code';
		}

		$sql = "SELECT * FROM zone WHERE ". $whereclause . " ORDER BY zone_name ASC"; 
		try {
			$q = $dblink->prepare($sql);
			if($country_code){
				$q->bindParam(':country_code', $country_code, PDO::PARAM_STR);
			}
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$optionvals = array();
		while ($zone = $q->fetch()) {
			$optionvals[$zone->zone_name] = $zone->zone_name;
		}
		return $optionvals;
	}

	function update_city_state_from_zip() {
		$zip_data = SingleRowFetch(
			'zips.zip_codes', 'zip_code_id', $this->get('usa_zip_code_id'), PDO::PARAM_INT,
			SINGLE_ROW_ALL_COLUMNS);
		if ($zip_data !== NULL) {
			$this->set('usa_city', $zip_data->zip_city);
			$this->set('usa_state', $zip_data->zip_state);
		}
	}

	static function getPointFromAddress($street, $city, $state){ 

		//YAHOO GEOCODE.  SWITCHED TO GOOGLE FOR HIGHER LIMIT AND SIMPLER RETURN STRUCTURE
		/*
		$ch =curl_init();
		$url =  "http://local.yahooapis.com/MapsService/V1/geocode";
		$args = "?appid=EQiiV7PV34GJIRDmdyqroT8OkF0c_SU5.0ANq3HoDkj8gTz.oEbI9VQaM5XToTeO9Zw2SbpM";
		$args .= "&street=" . urlencode($street);
		$args .= "&city=" . urlencode($city);
		$args .= "&state=" . $state;
		$args .= "&output=php";
		$url = $url . $args;
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$address_data = unserialize(curl_exec($ch));
		curl_close($ch);

		if(strlen($address_data['ResultSet']['Result']['precision'])>0){
			$numreturned=1;
		}
		else{
			$numreturned = count($address_data['ResultSet']['Result']);
		}

		if($numreturned >= 1){
			$point = array();
			$point['numreturned'] = $numreturned;
			$point['results'] = $address_data['ResultSet']['Result'];

			return $point;
		}
		else{
			return FALSE;
		}
		*/

		if((is_null($city) || strlen($city) == 0) || (is_null($state) || strlen($state) == 0)){
			return FALSE;
		}

		//GOOGLE API
		$longitude = "";
		$latitude = "";
		$precision = "";

		//Three parts to the querystring: q is address, output is the format (
		$settings = Globalvars::get_instance();
        $key = $settings->get_setting('GoogleMapAPIKey');
		$address = urlencode("$street $city, $state");
		$url = "http://maps.google.com/maps/geo?q=".$address."&output=csv&key=".$key;

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER,0);
		curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER["HTTP_USER_AGENT"]);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

		$data = curl_exec($ch);
		curl_close($ch);

		if (substr($data,0,3) == "200"){
			$data = explode(",",$data);

			$point = array();
			$point['Precision'] = $data[1];
			$point['Latitude'] = $data[2];
			$point['Longitude'] = $data[3];

			return $point;
		}
		else {
			return FALSE;
		}
	}

}

class MultiAddress extends SystemMultiBase {
	protected static $model_class = 'Address';

	function get_address_dropdown_array($include_new=TRUE, $message='', $short_address=FALSE) {
		$items = array();
		foreach($this as $address) {
			if ($address->get('usa_address1')) {
				$items[LibraryFunctions::encode($address->key)] =
					($short_address ? htmlspecialchars($address->get('usa_address1')) : $address->get_address_string(' ')) .
					($message ? ' ' . $message : '');
			}
		}
		if ($include_new) {
			$items['new'] = 'Enter New Address Below';
		}
		return $items;

	}

	function get_address_dropdown_options($selected_key=NULL, $include_new=TRUE, $disabled=FALSE, $disabled_message='', $short_address=FALSE, $distance_from_addr=NULL) {
		$address_dropdown_builder = array();
		$items = $this->get_address_dropdown_array($include_new, $disabled_message, $short_address, $distance_from_addr);

		foreach($items as $key => $label) {
			$address_dropdown_builder[] =
				'<option value="' . $key . '" ' .
				($key == $selected_key ? 'selected' : '') .
				($disabled ? ' disabled="disabled"' : '' ) . '>' . $label .
				'</option>';
		}
		return $address_dropdown_builder;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['usa_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['address1'])) {
            $filters['usa_address1'] = [$this->options['address1'], PDO::PARAM_STR];
        }

        if (isset($this->options['address2'])) {
            $filters['usa_address2'] = [$this->options['address2'], PDO::PARAM_STR];
        }

        if (isset($this->options['address1_lower'])) {
            $filters['LOWER(usa_address1)'] = '= \''.strtolower($this->options['address1_lower']).'\'';
        }

        if (isset($this->options['address2_lower'])) {
            $filters['LOWER(usa_address2)'] = '= \''.strtolower($this->options['address2_lower']).'\'';
        }

        if (isset($this->options['city_lower'])) {
            $filters['LOWER(usa_city)'] = '= \''.strtolower($this->options['city_lower']).'\'';
        }

        if (isset($this->options['state_lower'])) {
            $filters['LOWER(usa_state)'] = '= \''.strtolower($this->options['state_lower']).'\'';
        }

        if (isset($this->options['zip_code_lower'])) {
            $filters['LOWER(usa_zip_code_id)'] = '= \''.strtolower($this->options['zip_code_lower']).'\'';
        }

        if (isset($this->options['zip_code'])) {
            $filters['usa_zip_code_id'] = [$this->options['zip_code'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('usa_users_addrs', $filters, $this->order_by, $only_count, $debug);
    }

	public static function AddressDropdown($user_id, $get_raw_options=FALSE) {
		$address_book = new MultiAddress(array('user_id' => $user_id, 'deleted' => FALSE, 'bad' => FALSE));
		$address_book->load();

		if ($get_raw_options) {
			$dropdown_options = $address_book->get_address_dropdown_options(NULL, FALSE, FALSE, '', FALSE);
		} else {
			$dropdown_options = $address_book->get_address_dropdown_array(NULL, NULL, FALSE);
		}

		return $dropdown_options;
	}
}

?>
