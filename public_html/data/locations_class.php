<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class LocationException extends SystemBaseException {}

class Location extends SystemBase {	public static $prefix = 'loc';
	public static $tablename = 'loc_locations';
	public static $pkey_column = 'loc_location_id';
	public static $url_namespace = 'location';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM

	protected static $foreign_key_actions = [
		'loc_fil_file_id' => ['action' => 'null'],
	];

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
	    'loc_location_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'loc_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'loc_link' => array('type'=>'varchar(255)', 'required'=>true),
	    'loc_address' => array('type'=>'varchar(255)'),
	    'loc_website' => array('type'=>'varchar(255)'),
	    'loc_description' => array('type'=>'text'),
	    'loc_short_description' => array('type'=>'varchar(255)'),
	    'loc_fil_file_id' => array('type'=>'int4'),
	    'loc_is_published' => array('type'=>'bool'),
	    'loc_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'loc_delete_time' => array('type'=>'timestamp(6)'),
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

	// ===== Entity Photo Methods =====

	/**
	 * Set a photo as the primary photo for this location
	 *
	 * @param int $photo_id EntityPhoto ID to set as primary
	 */
	function set_primary_photo($photo_id) {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

		$photo = new EntityPhoto($photo_id, TRUE);
		$this->set('loc_fil_file_id', $photo->get('eph_fil_file_id'));
		$this->save();
	}

	/**
	 * Clear the primary photo for this location
	 */
	function clear_primary_photo() {
		$this->set('loc_fil_file_id', NULL);
		$this->save();
	}

	/**
	 * Get all photos for this location
	 *
	 * @return MultiEntityPhoto
	 */
	function get_photos() {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'location', 'entity_id' => $this->key, 'deleted' => false],
			['eph_sort_order' => 'ASC']
		);
		$photos->load();
		return $photos;
	}

	/**
	 * Get the primary photo EntityPhoto object
	 *
	 * @return EntityPhoto|null
	 */
	function get_primary_photo() {
		$file_id = $this->get('loc_fil_file_id');
		if (!$file_id) return null;
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'location', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
			[], 1
		);
		$photos->load();
		return $photos->count() > 0 ? $photos->get(0) : null;
	}

	/**
	 * Get picture URL for display
	 *
	 * @param string $size_key Image size key (default 'content')
	 * @return string|false URL or false if no picture
	 */
	function get_picture_link($size_key = 'content') {
		$file_id = $this->get('loc_fil_file_id');
		if (!$file_id) {
			return false;
		}
		$file = new File($file_id, TRUE);
		return $file->get_url($size_key);
	}

}

class MultiLocation extends SystemMultiBase {
	protected static $model_class = 'Location';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $location) {
			$items[$location->key] = $location->get('loc_name');
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

}

?>
