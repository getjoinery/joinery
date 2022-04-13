<?php
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


	function load() {
		$this->loaded = TRUE;
		if ($this->key === NULL) {
			throw new SystemClassNoKeyError('Cannot load a '.static::$tablename.' object with no key.');
		}
		
		$this->data = SingleRowFetch(static::$tablename, static::$pkey_column,
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new Exception(
				'This '.static::$tablename.' row ('.static::$pkey_column.'='.$this->key.') does not exist');
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
	
	
	//DEFAULT ACTION ON PERMANENT DELETE IS TO DELETE ALL ROWS WITH FOREIGN KEY 
	//FOR OTHER BEHAVIOR SET THE permanent_delete_actions ARRAY
	//OPTIONS FOR permanent_delete_actions ARRAY:
	//'delete' = DELETE THE ROW WITH THE FOREIGN KEY
	//'null' = SET THE FOREIGN KEY TO NULL
	//value = SET THE FOREIGN KEY TO A VALUE
	//'skip' = SKIP THE FOREIGN KEY
	//'prevent' = IF FOREIGN KEY ROWS ARE PRESENT, DO NOT ALLOW PERMANENT DELETE...THROWS AN ERROR
	function permanent_delete($debug=false){
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
		
		

		//CHECK FOR 'PREVENT' CONSTRAINT FIRST AND IF FOUND, THEN ABORT THE PERMANENT DELETE WITH AN ERROR

		foreach($found_foreign_keys as $column=>$table_name){
			$action = 'delete';  //DELETE IS DEFAULT
			foreach(static::$permanent_delete_actions as $pcolumn=>$paction){
				if($pcolumn == $column){
					$action = $paction;
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
					$count = $q->execute();
				}
				catch(PDOException $e){
					$dbhelper->handle_query_error($e);
				}
				if($count->count){
					if($debug){
						echo "FOUND<br>\n";
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
				else{
					if($debug){
						echo "NOT FOUND<br>\n";
					}
				}
			}								
		}
			



		//IF NO PREVENT CONSTRAINT EXISTS, THEN DO THE DELETES
		foreach($found_foreign_keys as $column=>$table_name){
			
			$action = 'delete';  //DELETE IS DEFAULT
			foreach(static::$permanent_delete_actions as $pcolumn=>$paction){
				if($pcolumn == $column){
					$action = $paction;
				}
			}
			
			if($action == 'prevent'){	
				//DO NOTHING
			}					
			else if($action == 'delete'){
			
				$sql = 'DELETE FROM '.$table_name.' WHERE '.$column.'=:param1';
				
				if($debug){
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
				//DO NOTHING
			}
			else{
				$sql = 'UPDATE '.$table_name.' SET '.$column.'='.$action.' WHERE '.$column.'=:param1';
				if($debug){
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

	function check_field_constraints() {
		/*
		//MOVED TO THE SAVE FUNCTION
		CheckRequiredFields($this, static::$required, static::$fields);

		foreach (static::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = static::$fields[$field];
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				} else {
					call_user_func($constraint, static::$fields[$field], $this->get($field));
				}
			}
		}
		*/
	}

	// To prepare it is without error
	function prepare() {}
	
	
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
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, static::$tablename, $p_keys, $rowdata, FALSE, $debug);

		$this->key = $p_keys_return[static::$pkey_column];
			
		
	}

	function authenticate_read($session, $other_data=NULL) {}

	function authenticate_write($session, $other_data=NULL) {}

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

}

abstract class SystemMultiBase implements IteratorAggregate, Countable {

	private $multi_data;
	protected $cached_references;
	protected static $default_options = array();

	function __construct($options=array(), $order_by=NULL, $limit=NULL, $offset=NULL, $operation='AND', $write_lock=FALSE) {
		$this->multi_data = array();
		$this->loaded = FALSE;

		if ($options !== NULL) {
			$this->options = array_merge(static::$default_options, $options);
		} else {
			$this->options = NULL;
		}

		$this->order_by = $order_by;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->operation = $operation;
		$this->write_lock = $write_lock;
		$this->cached_references = array();

		if ($options === NULL) {
			$this->loadable = FALSE;
		} else {
			$this->loadable = TRUE;
		}
	}

	function get_sql_builder() {
		return new SQLBuilder(static::$table_name, static::$table_primary_key, $this->limit, $this->offset, $this->operation, $this->write_lock);
	}

	function set_write_lock($write_lock) {
		$this->write_lock = $write_lock;
	}

	function generate_limit_and_offset($include_write_lock=TRUE) {
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

	function authenticate_read($session, $other_data=NULL) {
		foreach ($this as $child) {
			$child->authenticate_read($session, $other_data);
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

	function count_all($debug = false) {
		return FALSE;
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
require_once('ErrorHandler.php');
require_once('Globalvars.php');
require_once('SessionControl.php');
require_once('Globalvars.php');
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir  . '/data/general_errors_class.php');

if (!defined('SKIP_DEFAULT_EXCEPTION_HANDLER')) { 
	function default_exception_handler($e) {
		
		// First deal with errors that have their own custom error pages
		if ($e instanceof CustomErrorPage) {
			error_log('EXCEPTION: (CUSTOM ERROR) ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
			$e->display_error_page();
			exit;
		}
		

		$params = explode("/", $_SERVER['REQUEST_URI']);
		
		if($params[1] == 'admin'){
			$errorpage = 'admin';
		}
		else{
			$errorpage = 'public';
		}

		$debug = Globalvars::get_instance()->get_setting('debug');

		$errorhandler = new ErrorHandler($_SERVER["SERVER_PORT"] == 443);
		if ($debug) {
			$debug_message = 'Debug Message: ' . $e->getMessage() . '<br>' . $e->getCode() . '<br>' .$e->getTraceAsString(). '<br>' . $e->getFile() . '('.$e->getLine().')';
			error_log($debug_message);
			if($errorpage == 'admin'){
				$errorhandler->handle_admin_error($debug_message, ErrorHandler::INPUT_ERROR);
			}
			else{
				$errorhandler->handle_general_error($debug_message, ErrorHandler::INPUT_ERROR);
			}				
		} 
		else {
			if ($e instanceof PDOException) { 
				error_log('DATABASE ERROR: ' . $e->getTraceAsString() . ' | ' . $e->getCode() . ' | ' . $e->getMessage());
				GeneralError::LogGeneralError($e, $_SESSION, $_REQUEST);
				if($errorpage == 'admin'){
					$errorhandler->handle_admin_error('');
				}
				else{
					$errorhandler->handle_general_error('');
				}
			} 
			else if ($e instanceof DisplayableErrorMessage) {
				error_log('EXCEPTION: (DISPLAYABLE ERROR) ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
				if($errorpage == 'admin'){
					$errorhandler->handle_admin_error($e->getMessage(), ErrorHandler::INPUT_ERROR);
				}
				else{
					$errorhandler->handle_general_error($e->getMessage(), ErrorHandler::INPUT_ERROR);
				}				
			} 
			else if ($e instanceof DisplayablePermanentErrorMessageNoLog) {
				//error_log('EXCEPTION: (DISPLAYABLE PERMANENT ERROR) ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
				//GeneralError::LogGeneralError($e, $_SESSION, $_REQUEST);
				if($errorpage == 'admin'){
					$errorhandler->handle_admin_error($e->getMessage(), ErrorHandler::PERMANENT_ERROR);
				}
				else{
					$errorhandler->handle_general_error($e->getMessage(), ErrorHandler::PERMANENT_ERROR);
				}	
			} 
			else if ($e instanceof DisplayableErrorMessageNoLog) {
				//error_log('EXCEPTION: (DISPLAYABLE ERROR) ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
				if($errorpage == 'admin'){
					$errorhandler->handle_admin_error($e->getMessage(), ErrorHandler::INPUT_ERROR);
				}
				else{
					$errorhandler->handle_general_error($e->getMessage(), ErrorHandler::INPUT_ERROR);
				}				
			} 
			else if ($e instanceof DisplayablePermanentErrorMessage) {
				error_log('EXCEPTION: (DISPLAYABLE PERMANENT ERROR) ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
				GeneralError::LogGeneralError($e, $_SESSION, $_REQUEST);
				if($errorpage == 'admin'){
					$errorhandler->handle_admin_error($e->getMessage(), ErrorHandler::PERMANENT_ERROR);
				}
				else{
					$errorhandler->handle_general_error($e->getMessage(), ErrorHandler::PERMANENT_ERROR);
				}	
			} 
			else {
				error_log('EXCEPTION: ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
				GeneralError::LogGeneralError($e, $_SESSION, $_REQUEST);
				if($errorpage == 'admin'){
					$errorhandler->handle_admin_error('');
				}
				else{
					$errorhandler->handle_general_error('');
				}
			}
		}
	}
	set_exception_handler('default_exception_handler');
	//THESE SETTINGS WERE TURNED OFF BECAUSE 
	/*
	https://www.php.net/manual/en/function.set-error-handler.php
	The following error types cannot be handled with a user defined function: E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING independent of where they were raised, and most of E_STRICT raised in the file where set_error_handler() is called.
	*/
	//set_error_handler("phpErrorHandler");
	//register_shutdown_function("check_for_fatal");
}

?>
