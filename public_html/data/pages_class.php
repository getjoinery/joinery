<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

class PageException extends SystemBaseException {}

class Page extends SystemBase {	public static $prefix = 'pag';
	public static $tablename = 'pag_pages';
	public static $pkey_column = 'pag_page_id';
	public static $url_namespace = 'page';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM

	// A/B testing opt-in — title, body, and layout are all testable.
	public static $ab_testable = true;
	public static $ab_testable_fields = array('pag_title', 'pag_body', 'pag_component_layout');

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
	    'pag_page_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'pag_title' => array('type'=>'varchar(255)'),
	    'pag_link' => array('type'=>'varchar(255)'),
	    'pag_body' => array('type'=>'text'),
	    'pag_usr_user_id' => array('type'=>'int4'),
	    'pag_published_time' => array('type'=>'timestamp(6)'),
	    'pag_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'pag_fil_file_id' => array('type'=>'int4'),
	    'pag_delete_time' => array('type'=>'timestamp(6)'),
	    'pag_tier_min_level' => array('type'=>'int4', 'is_nullable'=>true),
	    'pag_tier_public_after_hours' => array('type'=>'int4', 'is_nullable'=>true),
	    'pag_component_layout' => array('type'=>'json', 'is_nullable'=>true),
	    'pag_template' => array('type'=>'varchar(128)', 'is_nullable'=>true),
	);

	protected static $foreign_key_actions = [
		'pag_fil_file_id' => ['action' => 'null']
	];

/**
	 * A/B testing: URLs to invalidate from the static cache when this page's
	 * test lifecycle changes state. Scoped per-page so activation/pause/crown
	 * don't wipe unrelated cached pages.
	 */
	function get_tested_cache_urls() {
		$url = $this->get_url();
		return $url ? [$url] : [];
	}

	/**
	 * Get typed component-layout array. Tolerates both raw JSON strings
	 * (fresh-loaded rows) and already-decoded arrays (after a set()).
	 *
	 * @return array Ordered list of pac_page_content_id values, empty if unset
	 */
	function get_component_layout() {
		$layout = $this->get_json_decoded('pag_component_layout');
		return is_array($layout) ? $layout : [];
	}

	/**
	 * Render page content from pag_component_layout, or pag_body if the
	 * layout is empty.
	 *
	 * @return string Rendered content
	 */
	function get_filled_content() {
		$layout = $this->get_component_layout();

		if (empty($layout)) {
			return $this->get('pag_body');
		}

		require_once(PathHelper::getIncludePath('data/page_contents_class.php'));
		require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

		$dblink = DbConnector::get_instance()->get_db_link();
		$placeholders = implode(',', array_fill(0, count($layout), '?'));
		$sql = 'SELECT * FROM pac_page_contents
				WHERE pac_page_content_id IN (' . $placeholders . ')
				  AND pac_delete_time IS NULL';
		$q = $dblink->prepare($sql);
		$q->execute(array_map('intval', $layout));
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$fields = array_keys(PageContent::$field_specifications);
		$by_id = [];
		foreach ($rows as $row) {
			$component = new PageContent($row['pac_page_content_id']);
			$component->load_from_data($row, $fields);
			$by_id[(int)$row['pac_page_content_id']] = $component;
		}

		$output = '';
		foreach ($layout as $pac_id) {
			$pac_id = (int)$pac_id;
			if (isset($by_id[$pac_id])) {
				$output .= ComponentRenderer::render_component($by_id[$pac_id]);
			}
		}
		return $output;
	}
	
	/**
	 * Get picture URL for display
	 *
	 * @param string $size_key Image size key (default 'original')
	 * @return string|false URL or false if no picture
	 */
	function get_picture_link($size_key='original'){
		if($this->get('pag_fil_file_id')){
			require_once(PathHelper::getIncludePath('data/files_class.php'));
			$file = new File($this->get('pag_fil_file_id'), TRUE);
			return $file->get_url($size_key, 'full');
		}
		return false;
	}

	/**
	 * Set a photo as the primary photo for this page
	 *
	 * @param int $photo_id EntityPhoto ID to set as primary
	 */
	function set_primary_photo($photo_id) {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photo = new EntityPhoto($photo_id, TRUE);
		$this->set('pag_fil_file_id', $photo->get('eph_fil_file_id'));
		$this->save();
	}

	/**
	 * Clear the primary photo for this page
	 */
	function clear_primary_photo() {
		$this->set('pag_fil_file_id', NULL);
		$this->save();
	}

	/**
	 * Get all photos for this page
	 *
	 * @return MultiEntityPhoto
	 */
	function get_photos() {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'page', 'entity_id' => $this->key, 'deleted' => false],
			['eph_sort_order' => 'ASC']
		);
		$photos->load();
		return $photos;
	}

	function get_primary_photo() {
		$file_id = $this->get('pag_fil_file_id');
		if (!$file_id) return null;
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'page', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
			[], 1
		);
		$photos->load();
		return $photos->count() > 0 ? $photos->get(0) : null;
	}

	function save($debug=false) {
		
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate('pag_link')){
			throw new SystemAuthenticationError(
					'This page link is a duplicate.');
		}

		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_PAGE, $this->key, $this->get('pag_body'), $this->get('pag_title'), $this->get('pag_title'));			
		}
		
		parent::save($debug);
	}
	
}

class MultiPage extends SystemMultiBase {
	protected static $model_class = 'Page';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('pag_title');
			$items[$entry->key] = $option_display;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function get_dropdown_array_link($include_new=FALSE) {
		$items = array();
		foreach($this as $page) {
			$items[$page->get_url()] = $page->get('pag_title');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['link'])) {
			$filters['pag_link'] = [$this->options['link'], PDO::PARAM_STR];
		}

		if (isset($this->options['has_link'])) {
			$filters['pag_link'] = "IS NOT NULL AND pag_link != ''";
		}

		if (isset($this->options['deleted'])) {
			$filters['pag_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['published'])) {
			$filters['pag_published_time'] = $this->options['published'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('pag_pages', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method

	// NEW: Added count_all method
}

?>
