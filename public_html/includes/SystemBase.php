<?php
require_once('PathHelper.php');
require_once('SqlBuilder.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));


interface CustomErrorPage {
	function display_error_page();
}

// An error message that is displayable and can be fixed by the user
interface DisplayableErrorMessage {}
interface DisplayableErrorMessageNoLog {}

// A displayable error message that cannot be fixed by the user
interface DisplayablePermanentErrorMessage {}
interface DisplayablePermanentErrorMessageNoLog {}

class SystemBaseException extends Exception {}

class SystemInvalidFormError extends SystemBaseException {}

class SystemBaseNoKeyError extends SystemBaseException {}

class SystemAuthenticationError extends SystemBaseException {}

class SystemDisplayableError extends SystemBaseException implements DisplayableErrorMessage {}

class SystemDisplayablePermanentError extends SystemBaseException implements DisplayablePermanentErrorMessage {}

class SystemDisplayableErrorNoLog extends SystemBaseException implements DisplayableErrorMessageNoLog {}

class SystemDisplayablePermanentErrorNoLog extends SystemBaseException implements DisplayablePermanentErrorMessageNoLog {}

abstract class SystemBase {
	
	public $key;
	protected $data;
	protected $loaded;
	protected $cached_references;

	static $constants = array();
	static $required = array();
	static $required_user = array();
	static $permanent_delete_actions = array();

	function __construct($key, $and_load=FALSE) {
		$this->key = $key;
		$this->data = new StdClass;
		$this->loaded = FALSE;
		$this->cached_references = array();
		
		if(!static::$prefix){
			throw new SystemBaseException('This object has no prefix.');
		}
		
		if(!static::$tablename){
			throw new SystemBaseException('This object has no table name.');
		}
		
		if(!static::$pkey_column){
			throw new SystemBaseException('This object has no primary key.');
		}

		if ($and_load) {
			$this->load();
		}
	}

	//THIS FUNCTION RETURNS ONLY ONE ROW AS AN OBJECT WHICH MATCHES THE COLUMN AND VALUE PROVIDED
	public static function GetByColumn($column, $value) {
		if(empty($column) || (empty($value) && $value !== 0)){
			throw new SystemBaseException('To load a row using GetByColumn, you must pass a column and value.');
		}

		if(!isset(static::$field_specifications[$column])){
			throw new SystemBaseException('That column '.$column.' does not exist in '.static::$tablename);
		}

		$field_type = static::$field_specifications[$column]['type'];
		if(str_contains($field_type, 'int')){
			$data = SingleRowFetch(static::$tablename, $column,
			$value, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		}
		else{
			$data = SingleRowFetch(static::$tablename, $column,
			$value, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);
		}

		if ($data === NULL) {
			return NULL;
		}
		$classname = get_called_class();
		$pkey_column_name = $classname::$pkey_column;
		$pkey_column_value = $data->$pkey_column_name;

		$object = new $classname($pkey_column_value);
		$object->load_from_data($data, array_keys($classname::$field_specifications));
		return $object;
	}
	
	//STUB FUNCTION THAT MODELS CAN OPTIONALLY EXTEND
	static function CreateNew($data){
		return false; //WE RETURN FALSE IF WE DID NOT, IN FACT, CREATE A NEW SOMETHING
	}
	
	//BEGIN LINK FUNCTIONS
	
	//FETCH AN ENTRY BASED ON ITS LINK, OR SLUG
	//ARGUMENTS ARE THE LINK TO SEARCH AND WHETHER DELETED ITEMS SHOULD BE SEARCHED
	static function get_by_link($link, $search_deleted=false){
		$classname = get_called_class();
		$mclassname = 'Multi'.$classname;
		if($search_deleted){
			$results = new $mclassname(array('link' => $link));
		}
		else{
			$results = new $mclassname(array('link' => $link, 'deleted'=>false));
		}
		$results->load();
	
		if($results->count()){	
			return $results->get(0);	
		}
		else{
			return false;
		}
	}
	
	//CREATES A URL OR SLUG BASED ON AN INPUT STRING
	function create_url($input_url) {
		//REQUIRE THAT THE OBJECT IS LOADED
		if(!$input_url){
			throw new SystemBaseException('You must pass a string to the create_url function.');
		}

		$classname = get_called_class();

		$tmp = strtolower(str_replace(' ', '-', $input_url));
		$tmp = preg_replace("/[^a-zA-Z0-9-]/", "", $tmp);
		$tmp = preg_replace('/-{2,}/', '-', $tmp);
		
		//NO DUPLICATES
		$safety = 0;
		$increment=1;
		$tmp_orig = $tmp;
		while($result = $classname::get_by_link($tmp, true)){
			$safety++;
			if($safety > 50){
				throw new SystemBaseException('Create_url is stuck in a loop. Check the presence of link Multi search.');
				exit;
			}
			if($result->key == $this->key){
				//IF WE FOUND THIS ONE, IT'S OKAY
				return $tmp;
			}
			else{
				$tmp = $tmp_orig . $increment;
				$increment++;
			}
		}
		return $tmp;
	}

	function get_url($format='short') {
		if(!isset(static::$url_namespace)){
			error_log('URL namespace is not set in object '.get_called_class().'. get_url() returning false.');
			return false;
		}
		
		if($format == 'full'){
			return LibraryFunctions::get_absolute_url('/'. static::$url_namespace .'/' . $this->get(static::$prefix .'_link'));
		}
		else{
			return '/'. static::$url_namespace .'/' . $this->get(static::$prefix .'_link');
		}
	}
	
	//END LINK FUNCTIONS
	
	function set($key, $value, $check_existance=TRUE) {
		if ($check_existance && !array_key_exists($key, static::$field_specifications)) {
			$display_value = is_array($value) || is_object($value) ? json_encode($value) : $value;
			error_log('EXCEPTION: Attempting to set the non-defined field ' . $key . ' of ' . get_class($this) . ' to ' . $display_value . '. Trace:' . print_r(debug_backtrace(FALSE), TRUE));
		}
		$this->data->$key = $value;
	}
	
	
	function set_all_to_null(){
		foreach(array_keys(static::$field_specifications) as $field) {
			$this->set($field, NULL);
		}
	}
	

	function set_array($key, $value, $check_existance=TRUE) {
		$formatted_values = array();
		foreach ($value as $array_item) {
			if (is_string($array_item)) {
				$formatted_values[] = '"' . pg_escape_string($array_item) . '"';
			} else {
				$formatted_values[] = pg_escape_string($array_item);
			}
		}
		$this->set($key, '{' . implode(', ', $formatted_values) . '}', $check_existance);
	}

	function smart_get($key) {
		// Auto-detect timestamp fields from type
		if ($this->is_timestamp_field($key)) {
			$value = $this->get($key);
			if ($value) {
				return new DateTime($value);
			}
		}
		
		return $this->get($key);
	}

	/**
	 * Auto-detect if a field is a timestamp based on its type specification
	 * Optimized for performance with quick rejection of non-timestamp types
	 */
	protected function is_timestamp_field($field_name) {
		if (!isset(static::$field_specifications[$field_name])) {
			return false;
		}
		
		$type = strtolower(static::$field_specifications[$field_name]['type'] ?? '');
		
		// Quick rejection: if type starts with clearly non-timestamp types, return false immediately
		$first_char = $type[0] ?? '';
		if ($first_char === 'v' || $first_char === 'i' || $first_char === 'b' || 
			$first_char === 'n' || $first_char === 'f' || $first_char === 'c') {
			return false; // varchar, int*, bool*, numeric, float, char
		}
		
		// Additional optimization: check first two characters for "te" (text fields)
		if ($first_char === 't' && isset($type[1]) && $type[1] === 'e') {
			return false; // text, textarea - definitely not timestamps
		}
		
		// Final switch statement for complete type matching (no strpos() calls needed)
		switch ($type) {
			// Standard timestamp variants
			case 'timestamp':
			case 'timestamptz':
			case 'timestamp with time zone':
			case 'timestamp without time zone':
			
			// Timestamp with precision (0-6 fractional seconds)
			case 'timestamp(0)':
			case 'timestamp(1)':
			case 'timestamp(2)':
			case 'timestamp(3)':
			case 'timestamp(4)':
			case 'timestamp(5)':
			case 'timestamp(6)':
			
			// Timestamp with time zone and precision
			case 'timestamptz(0)':
			case 'timestamptz(1)':
			case 'timestamptz(2)':
			case 'timestamptz(3)':
			case 'timestamptz(4)':
			case 'timestamptz(5)':
			case 'timestamptz(6)':
			
			// Other date/time types
			case 'datetime':
			case 'date':
			case 'time':
			case 'time(0)':
			case 'time(1)':
			case 'time(2)':
			case 'time(3)':
			case 'time(4)':
			case 'time(5)':
			case 'time(6)':
				return true;
				
			default:
				// Fallback: check if type contains timestamp-related keywords (handles edge cases)
				if (strpos(strtolower($type), 'timestamp') !== false || 
					strpos(strtolower($type), 'datetime') !== false || 
					strpos(strtolower($type), 'date') === 0 || 
					strpos(strtolower($type), 'time') === 0) {
					return true;
				}
				return false;
		}
	}
	
	/**
	 * Auto-detect if a field is a JSON field based on its type specification
	 * Optimized for performance with quick rejection of non-JSON types
	 */
	protected function is_json_field($field_name) {
		if (!isset(static::$field_specifications[$field_name])) {
			return false;
		}
		
		$type = static::$field_specifications[$field_name]['type'] ?? '';
		
		// Optimized: Quick rejection based on first character
		$first_char = $type[0] ?? '';
		if ($first_char !== 'j') {
			return false; // Not json/jsonb - immediate rejection
		}
		
		// Only perform exact comparison if starts with 'j'
		return $type === 'json' || $type === 'jsonb';
	}
	
	function get($key) {
		return $this->data->$key ?? NULL;
	}


	//TAKES AN OBJECT TO SEARCH FOR AND A STRING OR AN ARRAY REPRESENTING NAMES OF FIELDS TO CHECK WITH CURRENT OBJECT
	//IT WILL RETURN A LIST OF DUPLICATES, SEPARATING FIELDS WITH 'AND' IN THE SQL
	//IF SEARCH_DELETED IS TRUE, IT WILL ALSO SEARCH ALL DELETED ITEMS
	public static function CheckForDuplicate($obj_to_check, $fields=NULL, $search_deleted=false) {
		if(!isset($fields) || $fields == '' || $fields == NULL){
			throw new SystemBaseException('You must pass some fields to check for duplicates.');
		}
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();  

		$sql = 'SELECT * from '.static::$tablename . ' WHERE ';

		$whereclauses = array();
		if(is_array($fields)){
			foreach ($fields as $field){
				$field_type = static::$field_specifications[$field]['type'];
				if(str_contains($field_type, 'int')){
					$whereclauses[] = $field . '='.$obj_to_check->get($field). ' ';
				}
				else if(str_contains($field_type, 'bool')){
					if($obj_to_check->get($field) === true){
						$whereclauses[] = $field . '= true ';
					}
					else if($obj_to_check->get($field) === false){
						$whereclauses[] = $field . '= false ';
					}
					else{
						$whereclauses[] = $field . ' IS NULL';
					}
					
				} 
				else{
					$whereclauses[] = $field . '=\''.$obj_to_check->get($field). '\' ';
				}				
				
			}
		}
		else{
			$field_type = static::$field_specifications[$fields]['type'];
			if(str_contains($field_type, 'int')){
				$whereclauses[] = $field . '='.$obj_to_check->get($field). ' ';
			}
			else if(str_contains($field_type, 'bool')){
				if($obj_to_check->get($field) === true){
					$whereclauses[] = $field . '= true ';
				}
				else if($obj_to_check->get($field) === false){
					$whereclauses[] = $field . '= false ';
				}
				else{
					$whereclauses[] = $field . ' IS NULL';
				}
				
			} 
			else{
				$whereclauses[] = $field . '=\''.$obj_to_check->get($field). '\' ';
			}
		}
		
		if(!$search_deleted){
			//SEE IF THERE IS A DELETED FIELD
			if(isset(static::$field_specifications[static::$prefix . '_delete_time'])){
				$whereclauses[] = static::$prefix . '_delete_time IS NULL ';
			}
			else if(isset(static::$field_specifications[static::$prefix . '_is_deleted'])){
				$whereclauses[] = '('.static::$prefix . '_is_deleted IS NULL OR '.static::$prefix . '_is_deleted = FALSE)';
			}			
		}

		$sql .= implode(' AND ', $whereclauses);

		try{
			$q = $dblink->prepare($sql);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		

		$this_class = static::class;
		$multi_class_name = 'Multi'.$this_class;
		$pkey_column_name = $this_class::$pkey_column;

		$this_multi_array = new $multi_class_name();
		$numresults = 0;
		foreach($q->fetchAll() as $row) {
			$numresults++;
			$child = new $this_class($row->$pkey_column_name);
			$child->load_from_data($row, array_keys($this_class::$field_specifications));
			$this_multi_array->add($child);
		}
		
		if($numresults){
			return $this_multi_array;
		}
		else{
			return NULL;
		}		

	}		
	
	//TAKES A STRING OR AN ARRAY REPRESENTING NAMES OF FIELDS TO CHECK WITH CURRENT OBJECT
	//WILL RETURN THE NUMBER OF DUPLICATES FOUND, SEPARATING FIELDS WITH 'AND' IN THE SQL
	//IF SEARCH_DELETED IS TRUE, IT WILL ALSO SEARCH ALL DELETED ITEMS
	public function check_for_duplicate($fields=NULL, $search_deleted=false) {
		if(!isset($fields) || $fields == '' || $fields == NULL){
			throw new SystemBaseException('You must pass some fields to check for duplicates.');
		}
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();  
		


		$sql = 'SELECT count(*) as total from '.static::$tablename . ' WHERE ';
	
		$whereclauses = array();
		$param_string = ':param';
		$counter = 0;
		if(is_array($fields)){
			foreach ($fields as $field){
				$counter++;
				$whereclauses[] = $field . '='.$param_string .$counter. ' ';
			}
		}
		else{
			$whereclauses[] = $fields . '= :param1 ';
		}
		
		if(!$search_deleted){
			//SEE IF THERE IS A DELETED FIELD
			if(isset(static::$field_specifications[static::$prefix . '_delete_time'])){
				$whereclauses[] = static::$prefix . '_delete_time IS NULL ';
			}
			else if(isset(static::$field_specifications[static::$prefix . '_is_deleted'])){
				$whereclauses[] = '('.static::$prefix . '_is_deleted IS NULL OR '.static::$prefix . '_is_deleted = FALSE)';
			}			
		}

		$sql .= implode(' AND ', $whereclauses);

		if($this->key){
			$sql .= ' AND '.static::$pkey_column.' != '.$this->key;
		}	

		try{
			$q = $dblink->prepare($sql);
			$counter = 0;
			if(is_array($fields)){
				foreach ($fields as $field){
					$field_type = static::$field_specifications[$field]['type'];
					$counter++;
					$param_name = $param_string . $counter;
					$value = $this->get($field);
					if(str_contains($field_type, 'int')){
						$q->bindValue($param_name, $value, PDO::PARAM_INT);
					}
					else if(str_contains($field_type, 'bool')){
						$q->bindValue($param_name, $value, PDO::PARAM_BOOL);
					}
					else{
						$q->bindValue($param_name, $value, PDO::PARAM_STR);
					}
				}
			}
			else{
				$field_type = static::$field_specifications[$fields]['type'];
				$value = $this->get($fields);
				if(str_contains($field_type, 'int')){
					$q->bindValue(':param1', $value, PDO::PARAM_INT);
				}
				else if(str_contains($field_type, 'bool')){
					$q->bindValue(':param1', $value, PDO::PARAM_BOOL);
				}
				else{
					$q->bindValue(':param1', $value, PDO::PARAM_STR);
				}
			}
			
			
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		$count = $q->fetch();
		return $count->total;
	}	

	/**
	 * Check unique constraints defined in field_specifications
	 * Returns array with constraint violation details or null if no violations
	 */
	protected function check_unique_constraints() {
		if ($this->key) {
			return null; // Only check on insert
		}
		
		if (!isset(static::$field_specifications)) {
			return null; // No field specifications defined
		}
		
		foreach (static::$field_specifications as $field => $spec) {
			// Check single field unique constraints
			if (isset($spec['unique']) && $spec['unique']) {
				if ($this->check_for_duplicate($field)) {
					return array(
						'field' => $field,
						'message' => "Duplicate value for {$field}"
					);
				}
			}
			
			// Check composite unique constraints
			if (isset($spec['unique_with'])) {
				$fields = array_merge(array($field), $spec['unique_with']);
				if ($this->check_for_duplicate($fields)) {
					$field_list = implode(', ', $fields);
					return array(
						'fields' => $fields,
						'message' => "Duplicate combination for {$field_list}"
					);
				}
			}
		}
		
		return null;
	}

	function hash() {
		if ($this->key) {
			return md5(get_class($this) . " " . $this->key);
		}
		throw new SystemBaseException('Cannot hash an element with no key.');
	}

	function export_as_array() {
		$out_array = array();
		foreach(array_keys(static::$field_specifications) as $field) {
			$out_array[$field] = $this->get($field);
		}
		foreach(static::$field_specifications as $field_name => $spec) {
			if ($this->is_timestamp_field($field_name) && $this->get($field_name)) {
				$value = $this->get($field_name);
				// Skip SQL function defaults like 'now()' - these aren't parseable dates
				if (is_string($value) && preg_match('/^\w+\(\)$/', $value)) {
					$out_array[$field_name] = null;
					continue;
				}
				// Create DateTime object with UTC timezone (database values are in UTC)
				$out_array[$field_name] = new DateTime($value, new DateTimeZone('UTC'));
			}
		}
		$out_array['key'] = $this->key;
		return $out_array;
	}

	function get_without_prefix($key) {
		return $this->get(static::$prefix . '_' . $key);	
	}

	function get_array($key) {
		// Return a postgres array as a php array
		$value = $this->get($key);

		if ($value) {
			$formatted_values = explode(',', trim($value, '{}'));
			$output = array();
			foreach ($formatted_values as $formatted_value) {
				if (strpos($formatted_value, '"') === 0 &&
						strrpos($formatted_value, '"') === (strlen($formatted_value) - 1)) {
					$output[] = stripslashes(substr($formatted_value, 1, strlen($formatted_value) - 2));
				} else {
					$output[] = stripslashes($formatted_value);
				}
			}
			return $output;
		}
		return NULL;
	}

	function getString($key, $max_len=NULL, $ellipsis=TRUE) {
		if ($max_len !== NULL) {
			$length = strlen($this->get($key));
			$return_string = substr($this->get($key), 0, $max_len);
			if ($length > $max_len && $ellipsis) {
				$return_string .= '...';
			}
			return $return_string;
		}

		return $this->get($key);
	}

	function get_words_from_string($key, $max_chars=150, $max_words=75) {
		$words = preg_split('/\s+/', $this->get($key), $max_words + 1);
		if (count($words) == ($max_words + 1)) {
			unset($words[$max_words]);
		}

		$word_count = count($words);
		$cur_len = 0;
		for($i=0;$i<$word_count;$i++) {
			$cur_len += strlen($words[$i]) + 1;
			if ($cur_len > $max_chars) {
				return implode(' ', array_slice($words, 0, $i-1)) . '...';
			}
		}
		return implode(' ', $words);
	}

	function get_string_at_word_boundary($key, $max_chars=150, $ellipsis=TRUE) { 
		$rtn = $this->get($key);
		if (strlen($rtn) > $max_chars) { 
			$rtn = trim(substr($rtn, 0, $max_chars));
			$last_space = strrpos($rtn, " ");
			$last_nl = strrpos($rtn, "\n");
			$last_cr = strrpos($rtn, "\r");
			$rtn = trim(substr($rtn, 0, max($last_space, $last_nl, $last_cr)+1));
			$last_char = substr($rtn, strlen($rtn) - 1, strlen($rtn));
			if($ellipsis && ($last_char !== "." && $last_char !== "!" && $last_char !== "?")) {
				$rtn .= "...";
			}
		}
		return $rtn;
	}

	function add_flag($field, $flag) {
		$this->set($field, $this->get($field) | $flag);
	}

	function check_any_flag($field, $flag) {
		return $this->get($field) & $flag;
	}

	function check_flag($field, $flag) {
		return ($this->get($field) & $flag) === $flag;
	}

	function remove_flag($field, $flag) {
		$this->set($field, $this->get($field) & ~$flag);
	}

	function call_and_cache($method_name, $args=array()) { 
		if (!isset($this->cached_references[$method_name])) { 
			$this->cached_references[$method_name] = call_user_func_array(array('self', $method_name), $args);
		}
		return $this->cached_references[$method_name];
	}
	
	static function check_if_exists($key) {
		$data = SingleRowFetch(static::$tablename, static::$pkey_column,
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}


	function load() {
		$this->loaded = TRUE;
		if ($this->key === NULL) {
			throw new SystemBaseNoKeyError('Cannot load a '.static::$tablename.' object with no key.');
		}
		
		$this->data = SingleRowFetch(static::$tablename, static::$pkey_column,
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			error_log('This '.static::$tablename.' row ('.static::$pkey_column.'='.$this->key.') does not exist');
			return false;
			//throw new Exception('This '.static::$tablename.' row ('.static::$pkey_column.'='.$this->key.') does not exist');
		}		
		
	}

	function soft_delete(){
		foreach(array_keys(get_class($this)::$field_specifications) as $field) {
			if($field == static::$prefix.'_delete_time'){
				$this->set(static::$prefix.'_delete_time', 'now()');
				$this->save();
				return true;				
			}
		}
		throw new Exception(
			'This '.static::$tablename.' column ('.static::$prefix.'_delete_time) does not exist');
	}
	
	function undelete(){
		foreach(array_keys(get_class($this)::$field_specifications) as $field) {
			if($field == static::$prefix.'_delete_time'){
				$this->set(static::$prefix.'_delete_time', NULL);
				$this->save();	
				return true;			
			}
		}
		throw new Exception(
			'This '.static::$tablename.' column ('.static::$prefix.'_delete_time) does not exist');
	}
	
	
	/**
	 * Perform a dry run of deletion to see what would be affected
	 * Returns structured array of all actions that would be taken
	 */
	public function permanent_delete_dry_run() {
		$db = DbConnector::get_instance()->get_db_link();
		$results = [
			'primary' => [
				'table' => static::$tablename,
				'key_column' => static::$pkey_column,
				'key' => $this->key,
				'action' => 'delete'
			],
			'dependencies' => [],
			'total_affected' => 1,  // Start with the primary record
			'can_delete' => true,
			'blocking_reasons' => []
		];

		// Get all deletion rules for this table from the database
		$sql = "SELECT * FROM del_deletion_rules
				WHERE del_source_table = ?
				ORDER BY del_id";
		$stmt = $db->prepare($sql);
		$stmt->execute([static::$tablename]);

		// Process each dependent relationship
		while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$dep_table = $rule['del_target_table'];
			$dep_column = $rule['del_target_column'];

			// Check if records exist
			$count_sql = "SELECT COUNT(*) FROM {$dep_table} WHERE {$dep_column} = ?";
			$count_stmt = $db->prepare($count_sql);
			$count_stmt->execute([$this->key]);
			$count = $count_stmt->fetchColumn();

			if ($count > 0) {
				$dependency = [
					'table' => $dep_table,
					'column' => $dep_column,
					'count' => $count,
					'action' => $rule['del_action'],
					'action_value' => $rule['del_action_value'],
					'message' => $rule['del_message']
				];

				// Check if this would prevent deletion
				if ($rule['del_action'] === 'prevent') {
					$results['can_delete'] = false;
					$results['blocking_reasons'][] = $rule['del_message'] ??
						"Cannot delete: {$count} record(s) in {$dep_table} depend on this record";
					$dependency['blocks_deletion'] = true;
				} else {
					// Add to total affected count for all non-prevent actions
					$results['total_affected'] += $count;
				}

				$results['dependencies'][] = $dependency;
			}
		}

		return $results;
	}

	/**
	 * Perform the actual permanent deletion
	 */
	public function permanent_delete($debug=false) {
		$db = DbConnector::get_instance()->get_db_link();

		$this_transaction = false;
		if(!$debug && !$db->inTransaction()){
			$db->beginTransaction();
			$this_transaction = true;
		}

		try {
			// Get all deletion rules for this table from the database
			// This is much more efficient than scanning information_schema
			$sql = "SELECT * FROM del_deletion_rules
					WHERE del_source_table = ?
					ORDER BY del_id";
			$stmt = $db->prepare($sql);
			$stmt->execute([static::$tablename]);

			// Process each dependent relationship
			while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$dep_table = $rule['del_target_table'];
				$dep_column = $rule['del_target_column'];

				// Check if records exist
				$count_sql = "SELECT COUNT(*) FROM {$dep_table} WHERE {$dep_column} = ?";
				$count_stmt = $db->prepare($count_sql);
				$count_stmt->execute([$this->key]);
				$count = $count_stmt->fetchColumn();

				if ($count > 0) {
					switch ($rule['del_action']) {
						case 'prevent':
							throw new SystemDisplayableError(
								"Cannot delete: $count records in {$dep_table} column {$dep_column} " .
								($rule['del_message'] ?? 'depend on this record')
							);

						case 'cascade':
							// Default action - delete dependent records
							if($debug){
								echo "DELETE FROM {$dep_table} WHERE {$dep_column} = {$this->key}<br>";
							} else {
								$del_sql = "DELETE FROM {$dep_table} WHERE {$dep_column} = ?";
								$del_stmt = $db->prepare($del_sql);
								$del_stmt->execute([$this->key]);
							}
							break;

						case 'null':
							if($debug){
								echo "UPDATE {$dep_table} SET {$dep_column} = NULL WHERE {$dep_column} = {$this->key}<br>";
							} else {
								$null_sql = "UPDATE {$dep_table} SET {$dep_column} = NULL WHERE {$dep_column} = ?";
								$null_stmt = $db->prepare($null_sql);
								$null_stmt->execute([$this->key]);
							}
							break;

						case 'set_value':
							$value = $rule['del_action_value'];
							if($debug){
								echo "UPDATE {$dep_table} SET {$dep_column} = {$value} WHERE {$dep_column} = {$this->key}<br>";
							} else {
								$set_sql = "UPDATE {$dep_table} SET {$dep_column} = ? WHERE {$dep_column} = ?";
								$set_stmt = $db->prepare($set_sql);
								$set_stmt->execute([$value, $this->key]);
							}
							break;
					}
				}
			}

			// Delete the main record
			if($debug){
				echo "DELETE FROM " . static::$tablename . " WHERE " . static::$pkey_column . " = {$this->key}<br>";
			} else {
				$sql = "DELETE FROM " . static::$tablename . " WHERE " . static::$pkey_column . " = ?";
				$stmt = $db->prepare($sql);
				$stmt->execute([$this->key]);
			}

			if($this_transaction){
				$db->commit();
			}

			if(!$debug){
				$this->key = NULL;
			}

		} catch (Exception $e) {
			if($this_transaction){
				$db->rollback();
			}
			throw $e;
		}

		return true;
	}

	function safe_load_and_set($key, $value, $and_prepare=FALSE) { 
		DbConnector::BeginTransaction();
		try { 
			$this->load(TRUE);
			$this->set($key, $value);
			if ($and_prepare) { 
				$this->prepare();
			}
			$this->save();
		} catch(Exception $e) { 
			DbConnector::Rollback();
			throw $e;
		}
		DbConnector::Commit();
	}

	function load_from_data($data, $fields) {
		$this->loaded = TRUE;
		// Theoretically, we would love to check all of these "set" calls for field definition, however
		// the potential for massive slowdown is just too much, so we let it slide here and allow for the
		// database to return fields that we haven't defined (because they are obsolete or whatever) without
		// error.
		if (is_array($data)) {
			foreach($fields as $field) {
				$this->set($field, $data[$field], FALSE);
			}
		} else {
			foreach($fields as $field) {
				$this->set($field, $data->$field, FALSE);
			}
		}
	}

	function load_from_object($other, $fields) {
		$this->loaded = TRUE;
		foreach($fields as $field) {
			$this->set($field, $other->get($field));
		}
	}

	// To prepare it is without error
	function prepare() {
		// Check unique constraints defined in field_specifications
		$duplicate = $this->check_unique_constraints();
		if ($duplicate) {
			// Use DisplayableUserException for user-friendly errors
			throw new DisplayableUserException($duplicate['message']);
		}
	}
	
	
	// And to save it to the database
	function save($debug=false) {
		if ($this->data === NULL) {
			throw new SystemBaseException('This '.static::$tablename.' object has no data.');
		}		
		
		// EXACT SAME BEHAVIOR AS CURRENT - just reading from field_specifications instead of separate arrays
		
		if ($this->key === NULL) {
			// SET INITIAL DEFAULT VALUES (exact current logic)
			foreach (static::$field_specifications as $field_name => $spec) {
				if (isset($spec['default'])) {
					if ($this->get($field_name) === NULL) {
						$this->set($field_name, $spec['default']);
					}
				}
			}
			
			// SET ZERO VARIABLES (exact current logic)
			foreach (static::$field_specifications as $field_name => $spec) {
				if (isset($spec['zero_on_create']) && $spec['zero_on_create'] === true) {
					if ($this->key === NULL && $this->get($field_name) === NULL) {
						$this->set($field_name, 0);
					}
				}
			}
		}

		// CHECK REQUIRED FIELDS (exact current logic, minus array support for Phase 1)
		foreach (static::$field_specifications as $field_name => $spec) {
			if (isset($spec['required']) && $spec['required'] === true) {
				if (is_null($this->get($field_name)) || $this->get($field_name) === '') {
					throw new SystemBaseException('Required field "' . $field_name . '" must be set.');
				}
			}
		}

		// CHECK VALIDATION RULES FROM field_specifications['validation']
		foreach (static::$field_specifications as $field_name => $spec) {
			if (isset($spec['validation']) && is_array($spec['validation'])) {
				$field_value = $this->get($field_name);
				$validation_rules = $spec['validation'];
				$custom_messages = $validation_rules['messages'] ?? array();

				foreach ($validation_rules as $rule_name => $rule_param) {
					// Skip 'messages' key
					if ($rule_name === 'messages') {
						continue;
					}

					$is_valid = true;
					$error_message = null;

					switch ($rule_name) {
						case 'required':
							if ($rule_param === true) {
								if (is_null($field_value) || $field_value === '') {
									$is_valid = false;
									$error_message = $custom_messages['required'] ?? "Field '$field_name' is required.";
								}
							}
							break;

						case 'email':
							if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
								// Step 1: Format validation
								if (!filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
									$is_valid = false;
									$error_message = $custom_messages['email'] ?? "Field '$field_name' must be a valid email address.";
								}
								// Step 2: DNS MX record check (fail-open)
								else {
									$domain = substr($field_value, strrpos($field_value, '@') + 1);
									$mx_records = @dns_get_record($domain, DNS_MX);
									if ($mx_records === false) {
										// DNS lookup failed — pass the email (fail-open)
									} elseif (empty($mx_records)) {
										// No MX records — check for A record fallback (RFC 5321)
										$a_records = @dns_get_record($domain, DNS_A);
										if ($a_records === false) {
											// DNS lookup failed — pass the email (fail-open)
										} elseif (empty($a_records)) {
											// Lookup succeeded, definitively no MX or A records
											$is_valid = false;
											$error_message = $custom_messages['email_mx'] ?? "The email domain '$domain' does not appear to accept email.";
										}
									}
								}
							}
							break;

						case 'url':
							if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
								if (!filter_var($field_value, FILTER_VALIDATE_URL)) {
									$is_valid = false;
									$error_message = $custom_messages['url'] ?? "Field '$field_name' must be a valid URL.";
								}
							}
							break;

						case 'minlength':
							if (is_numeric($rule_param) && !is_null($field_value) && $field_value !== '') {
								if (strlen($field_value) < $rule_param) {
									$is_valid = false;
									$error_message = $custom_messages['minlength'] ?? "Field '$field_name' must be at least $rule_param characters.";
								}
							}
							break;

						case 'maxlength':
							if (is_numeric($rule_param) && !is_null($field_value) && $field_value !== '') {
								if (strlen($field_value) > $rule_param) {
									$is_valid = false;
									$error_message = $custom_messages['maxlength'] ?? "Field '$field_name' must be no more than $rule_param characters.";
								}
							}
							break;

						case 'pattern':
							if (is_string($rule_param) && !is_null($field_value) && $field_value !== '') {
								if (!preg_match($rule_param, $field_value)) {
									$is_valid = false;
									$error_message = $custom_messages['pattern'] ?? "Field '$field_name' does not match the required format.";
								}
							}
							break;

						case 'numeric':
							if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
								if (!is_numeric($field_value)) {
									$is_valid = false;
									$error_message = $custom_messages['numeric'] ?? "Field '$field_name' must be numeric.";
								}
							}
							break;
					}

					if (!$is_valid && $error_message) {
						throw new DisplayableUserException($error_message);
					}
				}
			}
		}

		//CHECK UNIQUE CONSTRAINTS (safety net)
		$duplicate = $this->check_unique_constraints();
		if ($duplicate) {
			throw new DisplayableUserException($duplicate['message']);
		}

		$rowdata = array();
		foreach(array_keys(get_class($this)::$field_specifications) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array(static::$pkey_column => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata[static::$pkey_column]);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// Build column metadata from field_specifications
		// Maps spec types to the same data_type/is_nullable strings that
		// information_schema.columns returns, so the binding code below
		// (copied verbatim from edit_table) works identically.
		$column_meta = array();
		foreach (static::$field_specifications as $col_name => $spec) {
			$spec_type = strtolower(preg_replace('/\(.*\)/', '', $spec['type'] ?? 'varchar'));
			switch ($spec_type) {
				case 'int4':
				case 'integer':
				case 'serial':
					$data_type = 'integer';
					break;
				case 'int2':
				case 'smallint':
					$data_type = 'smallint';
					break;
				case 'int8':
				case 'bigint':
				case 'bigserial':
					$data_type = 'bigint';
					break;
				case 'bool':
				case 'boolean':
					$data_type = 'boolean';
					break;
				case 'json':
					$data_type = 'json';
					break;
				case 'jsonb':
					$data_type = 'jsonb';
					break;
				default:
					$data_type = 'character varying';
					break;
			}
			$column_meta[$col_name]['data_type'] = $data_type;
			// Default to 'YES' (nullable) — PostgreSQL columns are nullable unless
			// explicitly declared NOT NULL. Only field_specifications with
			// 'is_nullable' => false map to 'NO'. This matches information_schema behavior.
			$column_meta[$col_name]['is_nullable'] = (isset($spec['is_nullable']) && $spec['is_nullable'] === false) ? 'NO' : 'YES';
		}

		// --- BEGIN: SQL generation and execution (from edit_table) ---

		if(count($rowdata) == 0){
			return FALSE;
		}

		if(is_null($p_keys)){
			$op = 'add';
			$sql = 'INSERT INTO ' . static::$tablename . ' ';

			$colphrase="";
			$valphrase="";
			foreach($rowdata as $column_name=>$column_val){
				if((string)$column_val != "-NOUPDATE-"){
					$colphrase .= $column_name . ',';
						$valphrase .= ':' . $column_name . ',';

				}
			}

			$colphrase[strlen($colphrase)-1] = ' ';
			$valphrase[strlen($valphrase)-1] = ' ';

			$sql .= '(' . $colphrase . ') VALUES (' . $valphrase . ') ';
		}
		else{
			$op = 'edit';
			$sql = 'UPDATE ' . static::$tablename . ' SET ';

			foreach($rowdata as $column_name=>$column_val){
				if((string)$column_val != "-NOUPDATE-"){
						$sql .= $column_name . '=:' . $column_name . ',';
				}
			}

			$sql[strlen($sql)-1] = ' ';

			//ADD WHERE CLAUSE
			$sql .= 'WHERE ';
			foreach($p_keys as $pname=>$pvalue){
				$sql .= $pname . '=:' . $pname . ' ';
				$sql .= ' AND ';
			}
			//REMOVE THE LAST ' AND '
			$sql = substr($sql, 0, strlen($sql)-5);
		}

		//BIND VALUES AND PREPARE STATEMENT
		$dbhelper->prepare_query($sql);

		foreach($rowdata as $column_name=>$column_val){
			if((string)$column_val != "-NOUPDATE-"){
				if($column_meta[$column_name]['data_type'] == 'integer' || $column_meta[$column_name]['data_type'] == 'smallint'){
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_INT);
				}
				else if($column_meta[$column_name]['data_type'] == 'boolean'){
					if($column_val===NULL){
						if($column_meta[$column_name]['is_nullable'] == 'YES') {
							$dbhelper->bind_value(":$column_name", NULL, PDO::PARAM_BOOL);
						} else {
							$dbhelper->bind_value(":$column_name", FALSE, PDO::PARAM_BOOL);
						}
					}
					else if($column_val==TRUE){
						$dbhelper->bind_value(":$column_name", TRUE, PDO::PARAM_BOOL);
					}
					else if($column_val==FALSE){
						$dbhelper->bind_value(":$column_name", FALSE, PDO::PARAM_BOOL);
					}
				}
				else if($column_meta[$column_name]['data_type'] == 'json' || $column_meta[$column_name]['data_type'] == 'jsonb'){
					// JSON columns - auto-encode arrays/objects
					if (is_array($column_val) || is_object($column_val)) {
						$column_val = json_encode($column_val);
					}
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_STR);
				}
				else{
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_STR);
				}
			}
		}

		if($op == 'edit'){
			foreach($p_keys as $pname=>$pvalue){
				if($column_meta[$pname]['data_type'] == 'integer' || $column_meta[$pname]['data_type'] == 'smallint'){
					$dbhelper->bind_value(":$pname", $pvalue, PDO::PARAM_INT);
				}
				else{
					$dbhelper->bind_value(":$pname", $pvalue, PDO::PARAM_STR);
				}
			}
		}

		if($debug){
			$error_var_statement = '<pre>';
			$error_var_statement .= "Table: " . static::$tablename . "\n";
			foreach ($rowdata as $col=>$val){
				$error_var_statement .= "[$col]=>";
				if(is_null($val)) {
					$error_var_statement .= 'NULL';
				}
				else if($val === '') {
					$error_var_statement .= "''";
				}
				else if($val === FALSE) {
					$error_var_statement .= "FALSE";
				}
				else if($val === TRUE) {
					$error_var_statement .= "TRUE";
				}
				else  {
					$error_var_statement .= "$val";
				}
				$error_var_statement .= "\n";
			}
			if(is_null($p_keys)){
				$error_var_statement .= 'pkeys is null ' . "\n";
			}
			$error_var_statement .= 'Number of Keys: '. count($p_keys) . "\n";
			echo $error_var_statement;
			echo '</pre>';
		}

		try {
			$dbhelper->execute_query();
		} catch (PDOException $e) {
			// Add context about the operation
			$operation = $op == 'add' ? 'INSERT' : 'UPDATE';
			$context = "Database $operation failed on table '" . static::$tablename . "'";

			if ($op == 'edit' && $p_keys) {
				$context .= " for record: " . json_encode($p_keys);
			}

			$dbhelper->handle_query_error(
				new PDOException($context . " - " . $e->getMessage(), (int)$e->getCode(), $e)
			);
		}

		if($op == 'edit'){
			if($debug){
				exit;
			}
			$this->key = $p_keys[static::$pkey_column];
		}
		else{
			$seq = static::$tablename . '_' . static::$pkey_column . '_seq';

			if($debug){
				echo "Sequence: $seq\n";
				exit;
			}

			$this->key = $dblink->lastInsertId($seq);
		}

		// --- END: SQL generation and execution ---

		// AUTO CACHE INVALIDATION - Simple approach
		// Only invalidate the model's own URL if it has one
		if (class_exists('StaticPageCache')) {
			if (method_exists($this, 'get_url')) {
				$url = $this->get_url();
				if ($url) {
					require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
					StaticPageCache::invalidateUrl($url);
				}
			}
		}


	}

	function authenticate_read($data) {}

	function authenticate_write($data) {}

	function is_owner($session) { return FALSE; }

	function get_json() { 
		// build the json-ready PHP object (to be passed into json_encode) 
		$json = array();
		foreach (array_keys(static::$field_specifications) as $field) {
			if ($this->is_json_field($field)) { 
				// make sanitary for display
				$json[$field] = htmlspecialchars($this->get($field));
			}
		}
		return $json;
	}
	
	//TESTS FOR THIS CLASS
	static function test($debug=false, $verbose=false, $read_only=false){
		$current_class = get_called_class();
		$result = true;
		
		// Check if we should run only Multi tests
		if (defined('MULTI_TESTS_ONLY') && MULTI_TESTS_ONLY) {
			// Only run Multi tests
			if (!class_exists('MultiModelTester')) {
				require_once(PathHelper::getBasePath() . '/tests/models/MultiModelTester.php');
			}
			$multi_tester = new MultiModelTester($current_class);
			return $multi_tester->test($debug);
		}
		
		// Check if we should skip Multi tests
		$skip_multi = false;
		if (defined('SINGLE_TESTS_ONLY') && SINGLE_TESTS_ONLY) {
			$skip_multi = true;
		}
		
		// Run single model tests (unless we're in Multi-only mode)
		if (!defined('MULTI_TESTS_ONLY') || !MULTI_TESTS_ONLY) {
			// Load testing infrastructure on demand
			if (!class_exists('ModelTester')) {
				require_once(PathHelper::getBasePath() . '/tests/models/ModelTester.php');
			}
			
			$tester = new ModelTester($current_class);
			$result = $tester->test(null, $debug, $read_only);
		}
		
		// Multi tests only when explicitly requested AND not disabled
		if (!$skip_multi) {
			$run_multi = false;
			
			// Check multiple ways to enable Multi testing
			if (isset($_GET['test_multi']) && $_GET['test_multi']) {
				$run_multi = true;
			} else if (getenv('TEST_MULTI')) {
				$run_multi = true;
			} else if (defined('TEST_MULTI') && TEST_MULTI) {
				$run_multi = true;
			}
			
			if ($run_multi) {
				if (!class_exists('MultiModelTester')) {
					require_once(PathHelper::getBasePath() . '/tests/models/MultiModelTester.php');
				}
				$multi_tester = new MultiModelTester($current_class);
				$multi_result = $multi_tester->test($debug);
				$result = $result && $multi_result;
			}
		}
		
		return $result;
	}	

}

abstract class SystemMultiBase implements IteratorAggregate, Countable {

	private $multi_data;
	protected $cached_references;
	protected static $default_options = array();

	function __construct($options=array(), $order_by=array(), $limit=NULL, $offset=NULL, $operation='AND', $write_lock=FALSE) {
		$this->multi_data = array();
		$this->loaded = FALSE;

		if (is_array($options)) {
			$this->options = array_merge(static::$default_options, $options);
		} 
		else{
			$this->options = static::$default_options;
		}

		if (is_array($order_by)) {
			$this->order_by = $order_by;
		} 
		else{
			$this->order_by = array();
		}

		
		
	/*
		if ($options !== NULL) {
			$this->options = array_merge(static::$default_options, $options);
		} else {
			$this->options = NULL;
		}
		*/

		//$this->order_by = $order_by;
		$this->limit = (int)$limit;
		$this->offset = (int)$offset;
		$this->operation = $operation;
		$this->write_lock = $write_lock;
		$this->cached_references = array();

		if ($options === NULL) {
			$this->loadable = FALSE;
		} else {
			$this->loadable = TRUE;
		}
	}

	protected function _get_resultsv2($table, $filters = [], $sorts = [], $only_count = false, $debug = false) {
		$where_clauses = [];
		$bind_params = [];
		$operation = $this->operation;

		// Extract prefix from table name (everything before first underscore)
		$prefix = substr($table, 0, strpos($table, '_'));

		foreach ($filters as $column => $condition) {
			if (is_array($condition)) {
				// If an array is passed, assume it contains [value, PDO type]
				$where_clauses[] = "$column = ?";
				$bind_params[] = [$condition[0], $condition[1]];
			} else {
				// Assume the caller is passing a raw SQL condition
				$where_clauses[] = "$column $condition";
			}
		}

		$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(" $operation ", $where_clauses) : '';
		
		// Handle order by with prefix inference
		$order_sql = '';
		if (!empty($sorts)) {
			$order_clauses = [];
			foreach ($sorts as $column => $direction) {
				// If prefix exists and column doesn't already have it, add it
				if ($prefix && strpos($column, $prefix . '_') !== 0) {
					$column = $prefix . '_' . $column;
				}
				$direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'; // Ensure only ASC or DESC
				$order_clauses[] = "$column $direction";
			}
			$order_sql = 'ORDER BY ' . implode(', ', $order_clauses);
		}

		if ($only_count) {
			$sql = "SELECT COUNT(*) FROM $table $where_sql";
		} else {
			$limit_offset_sql = $this->generate_limit_and_offset(false);
			$sql = "SELECT * FROM $table $where_sql $order_sql $limit_offset_sql";
		}

		if ($debug) {
			echo "SQL Query: $sql<br>\n";
			echo "Inferred prefix: $prefix<br>\n";
		}

		// Prepare and execute query
		$q = DbConnector::GetPreparedStatement($sql);
		foreach ($bind_params as $index => $param) {
			$q->bindValue($index + 1, $param[0], $param[1]);
		}

		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
		if($only_count){
			return $q->fetchColumn();
		}
		else{
			return $q;
		}
	}

	function get_sql_builder() {
		return new SQLBuilder(static::$table_name, static::$table_primary_key, $this->limit, $this->offset, $this->operation, $this->write_lock);
	}

	function set_write_lock($write_lock) {
		$this->write_lock = $write_lock;
	}

	function generate_limit_and_offset($include_write_lock=TRUE) {
		
		if(!is_numeric($this->limit) || !is_numeric($this->offset)){
			//IF THEY AREN'T INTEGERS FAIL BUT DON'T LOG THE FAILURE (SPAM)
			throw new SystemDisplayableError('Bad limit or offset');
		}
		
		$sql = '';

		if ($this->limit) {
			$sql .= " LIMIT {$this->limit}";
		}

		if ($this->offset) {
			$sql .= " OFFSET {$this->offset}";
		}

		if ($include_write_lock) {
			$sql .= $this->generate_write_lock_string();
		}

		return $sql;
	}

	function generate_write_lock_string() {
		if ($this->write_lock) {
			return ' FOR UPDATE';
		}
		return '';
	}

	function authenticate_read($data) {
		foreach ($this as $child) {
			$child->authenticate_read($data);
		}
	}

	function usort($callback) {
		usort($this->multi_data, $callback);
	}

	function load($debug = false) {
		// Make sure to clear out the existing array when we load in data
		$this->clear();
		if ($this->loadable) {
			$this->loaded = TRUE;
		} else {
			throw new SystemBaseException('This MultiBase was explicitly set unloaded with $options === NULL');
		}
		
		// Generic implementation for all Multi classes
		if (!isset(static::$model_class)) {
			throw new SystemBaseException("Multi class " . get_class($this) . " must define \$model_class property");
		}
		
		$childClassName = static::$model_class;
		
		// Verify the child class exists
		if (!class_exists($childClassName)) {
			throw new SystemBaseException("Model class {$childClassName} not found for " . get_class($this));
		}
		
		// Get the primary key column from the child class
		$pkey_column = $childClassName::$pkey_column;
		
		// Get results from the concrete implementation
		$q = $this->getMultiResults(false, $debug);
		
		// Load each row into a child object
		foreach($q->fetchAll() as $row) {
			// Create child object using the primary key value
			$child = new $childClassName($row->$pkey_column);
			
			// Load data into the child object
			$child->load_from_data($row, array_keys($childClassName::$field_specifications));
			
			// Add to collection
			$this->add($child);
		}
	}
	
	/**
	 * Generic count_all implementation for Multi classes
	 */
	function count_all($debug = false) {
		return $this->getMultiResults(TRUE, $debug);
	}
	
	/**
	 * Abstract method that must be implemented by concrete Multi classes
	 * This method handles the specific query building for each Multi class
	 */
	abstract protected function getMultiResults($only_count = false, $debug = false);

	// This is a very special function that takes the key of a specific timestamp column of a
	// table, and after loading all of the elements selected in this MultiBase, will set that
	// lock to $duration time in the future.  If you use the $write_lock feature of your load
	// and make sure to only load elements where the lock column is in the past, you are guaranteed
	// never to load the same element within the $duration time period.
	// Sample usage:  If you are setting up a system that is going to be used concurrently by
	// many users, you might want to set a lock time of 1 hour (the default) as your are loading elements.
	// This means that when a user loads a page, another user cannot load that same element for at
	// least one hour, which means users wont be accidentally doing the same work over and over.
	function load_and_lock($lock_key, $duration='1 hour') {
		DbConnector::BeginTransaction();
		$this->load();
		$future_time = new DateTime('now + ' . $duration);
		foreach ($this as $row) {
			$row->set($lock_key, $future_time->format(DATE_ATOM));
			$row->save();
		}
		DbConnector::Commit();
		return $this;
	}

	function clear() {
		$this->multi_data = array();
	}

	function contains($item) {
		return $this->contains_key($item->key);
	}

	function contains_key($key) {
		foreach($this as $existing_item) {
			if ($existing_item->key == $key) {
				return TRUE;
			}
		}
		return FALSE;
	}

	function add($value) {
		$this->multi_data[] = $value;
	}

	function get_by_key($key) {
		foreach($this as $existing_item) {
			if ($existing_item->key == $key) {
				return $existing_item;
			}
		}
		return NULL;
	}

	function get($location) {
		if (isset($this->multi_data[$location])) {
			return $this->multi_data[$location];
		} else {
			throw new SystemBaseException('Given location doesn\'t exist.');
		}
	}

	function is_valid($location) {
		return isset($this->multi_data[$location]);
	}

	function remove_by_key($key) {
		$array_iterator = $this->getIterator();
		foreach($array_iterator as $existing_item) {
			if ($existing_item->key == $key) {
				unset($this->multi_data[$array_iterator->key()]);
			}
		}
	}

	function remove($location) {
		unset($this->multi_data[$location]);
	}

	function re_index() {
		$this->multi_data = array_values($this->multi_data);
	}

	function count() {
		return count($this->multi_data);
	}

	function getIterator() {
		return new ArrayIterator($this->multi_data);
	}

	function incremental_iterator($incremental_limit=200) {
		return new SystemMultiBaseIncremental(clone $this, $incremental_limit);
	}
}

class SystemMultiBaseIncremental implements Iterator {

	function __construct($multi_base, $incremental_limit=200) {
		$this->overall_position = 0;
		$this->incremental_position = 0;

		$this->multi_base = $multi_base;
		$this->original_limit = $multi_base->limit;
		$this->original_offset = $multi_base->offset;

		if ($this->original_limit !== NULL) {
			$this->multi_base->limit = min($incremental_limit, $this->multi_base->limit);
		} else {
			$this->multi_base->limit = $incremental_limit;
		}
		$this->multi_base->load();

		$this->current_segment = NULL;
	}

	function rewind() {
	}

	function key() {
		return $this->overall_position;
	}

	function next() {
		$this->incremental_position++;
		$this->overall_position++;

		if ($this->incremental_position >= count($this->multi_base)) {
			$this->multi_base->offset = $this->original_offset + $this->overall_position;
			$this->incremental_position = 0;
			$this->multi_base->load();
		}
	}

	function current() {
		return $this->multi_base->get($this->incremental_position);
	}

	function valid() {
		return ($this->original_limit === NULL || $this->overall_position < $this->original_limit) && 
			$this->multi_base->is_valid($this->incremental_position);
	}

	function has_any() {
		return $this->multi_base->is_valid(0);
	}
}

// Since SystemBase is the base of everything, it needs to be defined before
// we setup the default exception handler.  Thus in this case it is OK for us to
// require_once things not at the top of the file!
require_once('SessionControl.php');
require_once(PathHelper::getIncludePath('data/general_errors_class.php'));

if (!defined('SKIP_DEFAULT_EXCEPTION_HANDLER')) { 
    // Initialize new error handling system
    require_once('ErrorHandler.php');
    ErrorManager::getInstance()->register();
}

?>
