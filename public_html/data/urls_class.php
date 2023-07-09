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

class UrlException extends SystemClassException {}

class Url extends SystemBase {
	public static $prefix = 'url';
	public static $tablename = 'url_urls';
	public static $pkey_column = 'url_url_id';
	public static $permanent_delete_actions = array(
		'url_url_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'url_url_id' => 'ID of the url',
		'url_incoming' => 'Incoming url',
		'url_redirect_url' => 'Url to redirect to',
		'url_redirect_file' => 'File to load',
		'url_type' => 'Type of redirect - 301, 302, etc',
		'url_create_time' => 'Time added',
		'url_delete_time' => 'Time deleted',
	);

	public static $field_specifications = array(
		'url_url_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'url_incoming' => array('type'=>'varchar(255)'),
		'url_redirect_url' => array('type'=>'varchar(255)'),
		'url_redirect_file' => array('type'=>'varchar(255)'),
		'url_type' => array('type'=>'int2'),
		'url_create_time' => array('type'=>'timestamp(6)'),
		'url_delete_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array('url_incoming');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('url_create_time' => 'now()'
		);		
	

	
	function get_type_text() {
		if($this->get('url_type') == 301){
			return 'HTTP/1.1 301 Moved Permanently';
		}
		else if($this->get('url_type') == 302){
			return 'HTTP/1.1 302 Found';
		}		
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('url_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this url.');
			}
		}
	}

}

class MultiUrl extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('incoming', $this->options)) {
			$where_clauses[] = 'url_incoming = ?';
			$bind_params[] = array($this->options['incoming'], PDO::PARAM_INT);
		}
	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM url_urls ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM url_urls
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " url_url_id ASC ";
			}
			else {
				if (array_key_exists('url_id', $this->order_by)) {
					$sql .= ' url_url_id ' . $this->order_by['url_id'];
				}			
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
			$child = new Url($row->url_url_id);
			$child->load_from_data($row, array_keys(Url::$fields));
			$this->add($child);
		}
	}
}


?>
