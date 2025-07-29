<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');


class LocationException extends SystemClassException {}

class Location extends SystemBase {
	public static $prefix = 'loc';
	public static $tablename = 'loc_locations';
	public static $pkey_column = 'loc_location_id';
	public static $url_namespace = 'location';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(		'evt_loc_location_id' => 'null',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'loc_location_id' => 'Primary key - Location ID',
		'loc_name' => 'Location Name',
		'loc_link' => 'Link of the location',
		'loc_address' => 'Address of the location',
		'loc_website' => 'Address of the location',
		'loc_description' => 'Description of the location',
		'loc_short_description' => 'Short description, no html, max 255 chars',
		'loc_fil_file_id' => 'Image attached',
		'loc_is_published' => 'Time published',
		'loc_create_time' => 'Time Created',
		'loc_delete_time' => 'Time of deletion',
		
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
		'loc_location_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'loc_name' => array('type'=>'varchar(255)'),
		'loc_link' => array('type'=>'varchar(255)'),
		'loc_address' => array('type'=>'varchar(255)'),
		'loc_website' => array('type'=>'varchar(255)'),
		'loc_description' => array('type'=>'text'),
		'loc_short_description' => array('type'=>'varchar(255)'),
		'loc_fil_file_id' => array('type'=>'int4'),
		'loc_is_published' => array('type'=>'bool'),
		'loc_create_time' => array('type'=>'timestamp(6)'),
		'loc_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array('loc_name', 'loc_link');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'loc_create_time' => 'now()'
	);	
	
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	function save($debug=false) {
		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_LOCATION, $this->key, $this->get('loc_description'), $this->get('loc_name'), $this->get('loc_name'));			
		}
		parent::save($debug);
	}
	
}

class MultiLocation extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $location) {
			$items[$location->get('loc_name')] = $location->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['link'])) {
			$filters['loc_link'] = [$this->options['link'], PDO::PARAM_STR];
		}

		if (isset($this->options['published'])) {
			$filters['loc_is_published'] = $this->options['published'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['deleted'])) {
			$filters['loc_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('loc_locations', $filters, $this->order_by, $only_count, $debug);
	}


	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Location($row->loc_location_id);
			$child->load_from_data($row, array_keys(Location::$fields));
			$this->add($child);
		}
	}


	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
