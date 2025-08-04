<?php
require_once('PathHelper.php');
require_once('SqlBuilder.php');
require_once('FieldConstraints.php');


interface CustomErrorPage {
	function display_error_page();
}

// An error message that is displayable and can be fixed by the user
interface DisplayableErrorMessage {}
interface DisplayableErrorMessageNoLog {}

// A displayable error message that cannot be fixed by the user
interface DisplayablePermanentErrorMessage {}
interface DisplayablePermanentErrorMessageNoLog {}

class SystemClassException extends Exception {}

class SystemInvalidFormError extends SystemClassException {}

class SystemClassNoKeyError extends SystemClassException {}

class SystemAuthenticationError extends SystemClassException {}

class SystemDisplayableError extends SystemClassException implements DisplayableErrorMessage {}

class SystemDisplayablePermanentError extends SystemClassException implements DisplayablePermanentErrorMessage {}

class SystemDisplayableErrorNoLog extends SystemClassException implements DisplayableErrorMessageNoLog {}

class SystemDisplayablePermanentErrorNoLog extends SystemClassException implements DisplayablePermanentErrorMessageNoLog {}

abstract class SystemBase {
	
	public $key;
	protected $data;
	protected $loaded;
	protected $cached_references;

	static $fields = array();
	static $timestamp_fields = array();	// Used for the mailer
	static $constants = array();
	static $required = array();
	static $required_user = array();	
	static $field_constraints = array();
	static $field_constraints_user = array();	
	static $initial_default_values = array();
	static $json_vars = array('key');
	static $permanent_delete_actions = array();

	function __construct($key, $and_load=FALSE) {
		$this->key = $key;
		$this->data = new StdClass;
		$this->loaded = FALSE;
		$this->cached_references = array();
		
		if(!static::$prefix){
			throw new SystemClassException('This object has no prefix.');
		}
		
		if(!static::$tablename){
			throw new SystemClassException('This object has no table name.');
		}
		
		if(!static::$pkey_column){
			throw new SystemClassException('This object has no primary key.');
		}

		if ($and_load) {
			$this->load();
		}
	}

	//THIS FUNCTION RETURNS ONLY ONE ROW AS AN OBJECT WHICH MATCHES THE COLUMN AND VALUE PROVIDED
	public static function GetByColumn($column, $value) {
		if(empty($column) || (empty($value) && $value !== 0)){
			throw new SystemClassException('To load a row using GetByColumn, you must pass a column and value.');
		}

		if(!isset(static::$field_specifications[$column])){
			throw new SystemClassException('That column '.$column.' does not exist in '.static::$tablename);
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
		$object->load_from_data($data, array_keys($classname::$fields));
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
			throw new SystemClassException('You must pass a string to the create_url function.');
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
				throw new SystemClassException('Create_url is stuck in a loop. Check the presence of link Multi search.');
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
			throw new SystemClassException('URL namespace is not set in object '.get_called_class().'.');
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
		if ($check_existance && !array_key_exists($key, static::$fields)) {
			//TODO BETTER LOGGING HERE
			error_log('EXCEPTION: Attempting to set the non-defined field ' . $key . ' of ' . get_class($this) . ' to ' . $value . '. Trace:' . print_r(debug_backtrace(FALSE), TRUE));
			//throw new SystemClassException('EXCEPTION: Attempting to set the non-defined field ' . $key . ' of ' . get_class($this) . ' to ' . $value . '. Trace:' . print_r(debug_backtrace(FALSE), TRUE));
		}
		$this->data->$key = $value;
	}
	
	
	function set_all_to_null(){
		foreach(array_keys(static::$fields) as $field) {
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
		if (in_array($key, static::$timestamp_fields)) {
			return new DateTime($this->get($key));
		}
		return $this->get($key);
	}
	
	function get($key) {
		if (isset($this->data->$key)) {
			return $this->data->$key;
		}
		return NULL;
	}


	//TAKES AN OBJECT TO SEARCH FOR AND A STRING OR AN ARRAY REPRESENTING NAMES OF FIELDS TO CHECK WITH CURRENT OBJECT
	//IT WILL RETURN A LIST OF DUPLICATES, SEPARATING FIELDS WITH 'AND' IN THE SQL
	//IF SEARCH_DELETED IS TRUE, IT WILL ALSO SEARCH ALL DELETED ITEMS
	public static function CheckForDuplicate($obj_to_check, $fields=NULL, $search_deleted=false) {
		if(!isset($fields) || $fields == '' || $fields == NULL){
			throw new SystemClassException('You must pass some fields to check for duplicates.');
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
			$child->load_from_data($row, array_keys($this_class::$fields));
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
			throw new SystemClassException('You must pass some fields to check for duplicates.');
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
					if(str_contains($field_type, 'int')){
						$q->bindParam($param_name, $this->get($field), PDO::PARAM_INT);
					}
					else if(str_contains($field_type, 'bool')){
						$q->bindParam($param_name, $this->get($field), PDO::PARAM_BOOL);
					} 
					else{
						$q->bindParam($param_name, $this->get($field), PDO::PARAM_STR);
					}
				}
			}
			else{
				$field_type = static::$field_specifications[$fields]['type'];
				if(str_contains($field_type, 'int')){
					$q->bindParam(':param1', $this->get($fields), PDO::PARAM_INT);
				}
				else if(str_contains($field_type, 'bool')){
					$q->bindParam(':param1', $this->get($fields), PDO::PARAM_BOOL);
				} 
				else{
					$q->bindParam(':param1', $this->get($fields), PDO::PARAM_STR);
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
		throw new SystemClassException('Cannot hash an element with no key.');
	}

	function export_as_array() {
		$out_array = array();
		foreach(array_keys(static::$fields) as $field) {
			$out_array[$field] = $this->get($field);
		}
		foreach(static::$timestamp_fields as $field) {
			if ($this->get($field)) {
				$out_array[$field] = new DateTime($this->get($field));
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

	function get_timezone_corrected_time($key, $session, $format='Y-m-d h:i A T') {
		$date = new DateTime($this->get($key), new DateTimeZone('UTC'));
		$date->setTimeZone(new DateTimeZone($session->get_timezone()));
		return $date->format($format);
	}

	function get_timezone_corrected_datetime($key, $session, $format='Y-m-d h:i A T') {
		$date = new DateTime($this->get($key), new DateTimeZone('UTC'));
		$date->setTimeZone(new DateTimeZone($session->get_timezone()));
		return $date;
	}

	function get_timezone_agnostic_date($key, $session, $format='Y-m-d h:i A T') { 
		$date = new DateTime($this->get($key, $session), new DateTimeZone($session->get_timezone()));
		return $date->format($format);
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
			throw new SystemClassNoKeyError('Cannot load a '.static::$tablename.' object with no key.');
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
		foreach(array_keys(get_class($this)::$fields) as $field) {
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
		foreach(array_keys(get_class($this)::$fields) as $field) {
			if($field == static::$prefix.'_delete_time'){
				$this->set(static::$prefix.'_delete_time', NULL);
				$this->save();	
				return true;			
			}
		}
		throw new Exception(
			'This '.static::$tablename.' column ('.static::$prefix.'_delete_time) does not exist');
	}
	
	
	//DEFAULT ACTION ON PERMANENT DELETE IS TO SKIP ALL ROWS WITH FOREIGN KEY 
	//FOR OTHER BEHAVIOR SET THE permanent_delete_actions ARRAY
	//OPTIONS FOR permanent_delete_actions ARRAY:
	//'delete' = DELETE THE ROW WITH THE FOREIGN KEY
	//'null' = SET THE FOREIGN KEY TO NULL
	//value = SET THE FOREIGN KEY TO A VALUE
	//'skip' = SKIP THE FOREIGN KEY
	//'prevent' = IF FOREIGN KEY ROWS ARE PRESENT, DO NOT ALLOW PERMANENT DELETE...THROWS AN ERROR
	//DOES NOT CASCADE.  IF YOU NEED CASCADE DELETE, THEN CALL THE permanent_delete() FUNCTION DIRECTLY ON THE OTHER CLASS
	function permanent_delete($debug=false){
		// Check if the primary key is incorrectly included in permanent_delete_actions
		if(isset(static::$permanent_delete_actions) && array_key_exists(static::$pkey_column, static::$permanent_delete_actions)){
			throw new SystemClassException('Primary key ' . static::$pkey_column . ' should not be included in permanent_delete_actions for ' . static::$tablename . '. The main record deletion is handled automatically.');
		}
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();  
		

		if(!$debug){
			$this_transaction = false;
			if(!$dblink->inTransaction()){
				$dblink->beginTransaction();
				$this_transaction = true;
			}	
		}

		$sql = '		select
			t.table_name,
			array_agg(c.column_name::text) as columns
		from
			information_schema.tables t
		inner join information_schema.columns c on
			t.table_name = c.table_name
		where
			t.table_schema = \'public\'
			--and t.table_type= \'BASE TABLE\'
			and c.table_schema = \'public\'
		group by t.table_name;	';
		try{
			$q = $dblink->prepare($sql);
			//$q->bindParam(':param1', $this->key, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		//MAKE A LIST OF FOUND FOREIGN KEYS
		$found_foreign_keys = array();
		while ($row = $q->fetch()) {
			$table_name = $row->table_name;
			$columns = $row->columns;
			$columns_array = explode(',', trim($columns, '{}'));
			
			foreach($columns_array as $column){
				if(str_contains($column, static::$pkey_column)){
					if($debug){
						echo static::$pkey_column . ' is in ' .$column. "\n<br>";
					}
					$found_foreign_keys[$column] = $table_name;
				}
			}
		}
		
		//If no permanent_delete_actions specified, we'll use default behavior (delete) for all foreign keys
		$has_permanent_delete_actions = isset(static::$permanent_delete_actions) && !empty(static::$permanent_delete_actions);

		//CHECK FOR 'PREVENT' CONSTRAINT FIRST AND IF FOUND, THEN ABORT THE PERMANENT DELETE WITH AN ERROR
		foreach($found_foreign_keys as $column=>$table_name){
			$action = 'delete';  //DELETE IS DEFAULT
			if($has_permanent_delete_actions){
				foreach(static::$permanent_delete_actions as $pcolumn=>$paction){
					if($pcolumn == $column){
						$action = $paction;
					}
				}
			}
			
			if($action == 'prevent'){
				if($debug){
					echo 'Checking "prevent" constraint on foreign key '.$column.' in '.$table_name. ': ';
				}
				$sql = 'SELECT COUNT(1) FROM '.$table_name.' WHERE '.$column.'=:param1';
				
				try{
					$q = $dblink->prepare($sql);
					$q->bindParam(':param1', $this->key, PDO::PARAM_INT);
					$q->execute();
					$count = $q->fetchColumn();
				}
				catch(PDOException $e){
					$dbhelper->handle_query_error($e);
				}
				if($count > 0){
					if($debug){
						echo "Prevent: ".$column." <br>\n";
					}
					else{
						//IF FOUND, ERROR AND ABORT PERMANENT DELETE
						if($this_transaction){
							$dblink->rollBack();
						}
						
						throw new SystemClassException('Cannot permanent delete '.static::$pkey_column.'='.$this->key.' from '.static::$tablename.'. Columns exist in table '. $table_name);
						return false;
					}
					
				}
			}								
		}
			



		//IF NO PREVENT CONSTRAINT EXISTS, THEN DO THE DELETES
		foreach($found_foreign_keys as $column=>$table_name){
			
			$action = 'delete';  //DELETE IS DEFAULT
			if($has_permanent_delete_actions){
				foreach(static::$permanent_delete_actions as $pcolumn=>$paction){
					if($pcolumn == $column){
						$action = $paction;
					}
				}
			}
			
			if($action == 'prevent'){	
				//DO NOTHING
			}					
			else if($action == 'delete'){

			
				$sql = 'DELETE FROM '.$table_name.' WHERE '.$column.'=:param1';
				
				if($debug){
					$sql = str_replace(':param1', $this->key, $sql);
					echo $sql . "<br>";
				}
				else{

					try{
						$q = $dblink->prepare($sql);
						$q->bindParam(':param1', $this->key, PDO::PARAM_INT);
						$q->execute();
					}
					catch(PDOException $e){
						$dbhelper->handle_query_error($e);
					}
				}
			}
			else if($action == 'null'){
			
				$sql = 'UPDATE '.$table_name.' SET '.$column.'=NULL WHERE '.$column.'=:param1';
				if($debug){
					$sql = str_replace(':param1', $this->key, $sql);
					echo $sql . "<br>";
				}
				else{	
					try{
						$q = $dblink->prepare($sql);
						$q->bindParam(':param1', $this->key, PDO::PARAM_INT);
						$q->execute();
					}
					catch(PDOException $e){
						$dbhelper->handle_query_error($e);
					}
				}					
			}
			else if($action == 'skip'){
				if($debug){
					echo 'Skipping '.$column . "<br>";
				}
			}
			else{
				$sql = 'UPDATE '.$table_name.' SET '.$column.'='.$action.' WHERE '.$column.'=:param1';
				if($debug){
					$sql = str_replace(':param1', $this->key, $sql);
					echo $sql . "<br>";
				}
				else{	
					
					try{
						$q = $dblink->prepare($sql);
						$q->bindParam(':param1', $this->key, PDO::PARAM_INT);
						$q->execute();
					}
					catch(PDOException $e){
						$dbhelper->handle_query_error($e);
					}
							
				}					
			}		
		}			

		
		
		// Finally, delete the main record itself
		if(!$debug){
			$sql = 'DELETE FROM ' . static::$tablename . ' WHERE ' . static::$pkey_column . ' = :param1';
			try{
				$q = $dblink->prepare($sql);
				$q->bindParam(':param1', $this->key, PDO::PARAM_INT);
				$q->execute();
			}
			catch(PDOException $e){
				if($this_transaction){
					$dblink->rollBack();
				}
				$dbhelper->handle_query_error($e);
			}
		} else {
			$sql = 'DELETE FROM ' . static::$tablename . ' WHERE ' . static::$pkey_column . ' = ' . $this->key;
			echo $sql . "<br>";
		}
		
		if(!$debug){
			if($this_transaction){
				$dblink->commit();
			}
		
			$this->key = NULL;
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
			throw new SystemClassException('This '.static::$tablename.' object has no data.');
		}		
		
		if ($this->key === NULL) {
			//SET INITIAL DEFAULT VALUES
			foreach (static::$initial_default_values as $key => $value) {
				if ($this->get($key) === NULL) {
					$this->set($key, $value);
				}
			}
			
			//SET ZERO VARIABLES
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
					$this->set($variable, 0);
				}
			}
		}

		//CHECK REQUIRED FIELDS
		foreach (static::$required_fields as $required_field) {
			if (gettype($required_field) == 'array') {
				$one_true = FALSE;
				foreach($required_field as $element) {

					if ($this->get($element)) {
						// If they pass an array, we check to see if one of them is true
						// If so, we are good.
						$one_true = TRUE;
						break;
					}
				}

				if (!$one_true) {
					$display_names = array();
					foreach($required_field as $field) {
						$display_names[] = "'" . $field . "'";
					}
					throw new SystemClassException('One of ' . implode(', ', $display_names) . ' must be set.');
				}
			} 
			else if (is_null($this->get($required_field)) || $this->get($required_field) === '') {
				throw new SystemClassException('Required field "' . $required_field . '" must be set.');
			}
		}
		
		//CHECK FIELD CONSTRAINTS
		foreach (static::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = $field;
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				} 
				else {
					call_user_func($constraint, $field, $this->get($field));
				}
			}
		}	

		//CHECK UNIQUE CONSTRAINTS (safety net)
		$duplicate = $this->check_unique_constraints();
		if ($duplicate) {
			throw new DisplayableUserException($duplicate['message']);
		}

		$rowdata = array();
		foreach(array_keys(get_class($this)::$fields) as $field) {
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
		
		try {
			$p_keys_return = LibraryFunctions::edit_table(
				$dbhelper, $dblink, static::$tablename, $p_keys, $rowdata, FALSE, $debug);
		} catch (PDOException $e) {
			// Use the existing handle_query_error method
			$dbhelper->handle_query_error($e);
		}

		$this->key = $p_keys_return[static::$pkey_column];
			
		
	}

	function authenticate_read($data) {}

	function authenticate_write($data) {}

	function is_owner($session) { return FALSE; }

	function get_json() { 
		// build the json-ready PHP object (to be passed into json_encode) 
		$json = array();
		foreach (array_keys(static::$fields) as $field) {
			if (in_array($field, static::$json_vars)) { 
				// make sanitary for display
				$json[$field] = htmlspecialchars($this->get($field));
			}
		}
		return $json;
	}
	
	//TESTS FOR THIS CLASS
	static function test($debug=false){
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
			$result = $tester->test(null, $debug);
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
			throw new SystemClassException('This MultiBase was explicitly set unloaded with $options === NULL');
		}
	}

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
			throw new SystemClassException('Given location doesn\'t exist.');
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

// Since SystemClass is the base of everything, it needs to be defined before
// we setup the default exception handler.  Thus in this case it is OK for us to
// require_once things not at the top of the file!
require_once('SessionControl.php');
PathHelper::requireOnce('data/general_errors_class.php');

if (!defined('SKIP_DEFAULT_EXCEPTION_HANDLER')) { 
    // Initialize new error handling system
    require_once('ErrorHandler.php');
    ErrorManager::getInstance()->register();
}

?>
