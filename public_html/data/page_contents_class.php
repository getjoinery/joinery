<?php
/**
 * PageContent - Component Instances and Page Content Placeholders
 *
 * Stores component instances (when pac_com_component_id is set) or
 * legacy page content placeholders (when pac_link is set).
 *
 * Components are identified by pac_location_name (slug) and rendered via ComponentRenderer.
 *
 * @see /specs/page_component_system.md
 * @version 1.2.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

// Note: DbConnector, Globalvars, SessionControl are always available - no require needed
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PageContentException extends SystemBaseException {}

class PageContent extends SystemBase {
	public static $prefix = 'pac';
	public static $tablename = 'pac_page_contents';
	public static $pkey_column = 'pac_page_content_id';

	protected static $foreign_key_actions = [
		'pac_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
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
		'pac_page_content_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'pac_pag_page_id' => array('type'=>'int4'),
		'pac_com_component_id' => array('type'=>'int4'),
		'pac_location_name' => array('type'=>'varchar(255)', 'unique'=>true),
		'pac_title' => array('type'=>'varchar(255)'),
		'pac_link' => array('type'=>'varchar(255)'),
		'pac_usr_user_id' => array('type'=>'int4'),
		'pac_body' => array('type'=>'text'),
		'pac_config' => array('type'=>'json'),
		'pac_order' => array('type'=>'int2', 'default'=>0),
		'pac_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'pac_script_filename' => array('type'=>'varchar(255)'),
		'pac_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $json_vars = array('pac_config');

	/**
	 * Get component by slug (location_name)
	 *
	 * @param string $slug The component slug
	 * @return PageContent|null The component or null if not found
	 */
	public static function get_by_slug($slug) {
		return static::GetByColumn('pac_location_name', $slug);
	}

	/**
	 * Check if this is a component (has component type) vs legacy content
	 *
	 * @return bool True if this is a component instance
	 */
	public function is_component() {
		return !empty($this->get('pac_com_component_id'));
	}

	/**
	 * Get the component type definition
	 *
	 * @return Component|null The component type or null if not a component
	 */
	public function get_component_type() {
		if (!$this->is_component()) {
			return null;
		}
		require_once(PathHelper::getIncludePath('data/components_class.php'));
		$component = new Component($this->get('pac_com_component_id'), true);
		if ($component->key) {
			return $component;
		}
		return null;
	}

	/**
	 * Get config as array
	 *
	 * @return array Configuration array
	 */
	public function get_config() {
		$config = $this->get('pac_config');
		if (is_string($config)) {
			return json_decode($config, true) ?: array();
		}
		return $config ?: array();
	}

	/**
	 * Set config from array
	 *
	 * @param array $config Configuration array
	 */
	public function set_config($config) {
		$this->set('pac_config', $config);
	}

	/**
	 * Check if component is visible (not deleted)
	 *
	 * @return bool True if visible
	 */
	public function is_visible() {
		return !$this->get('pac_delete_time');
	}

	/**
	 * Legacy: Get content for placeholder system
	 *
	 * @return string Content body or empty string
	 */
	function get_content() {
		if (!$this->get('pac_delete_time')) {
			return $this->get('pac_body');
		}
		return '';
	}

	/**
	 * Get filled content with script file variable replacement
	 *
	 * @return string Processed content
	 */
	function get_filled_content() {
		// LOOK FOR THE SCRIPT FILE AND REPLACE CONTENT PLACEHOLDERS {{}}
		if ($this->get('pac_script_filename')) {
			// Include the logic file using ThemeHelper
			require_once(PathHelper::getThemeFilePath($this->get('pac_script_filename'), 'logic'));

			$content_out = $this->get_content();

			if (isset($replace_values) && is_array($replace_values)) {
				foreach ($replace_values as $var => $val) {
					$content_out = str_replace('{{' . $var . '}}', $val, $content_out);
				}
			}

			return $content_out;
		} else {
			return $this->get('pac_body');
		}
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix . '_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in ' . static::$tablename
				);
			}
		}
	}

	function save($debug = false) {
		// Only check for duplicate pac_link if it's not empty (legacy placeholder system)
		$pac_link = $this->get('pac_link');
		if (!empty($pac_link) && $this->check_for_duplicate('pac_link')) {
			throw new SystemAuthenticationError('This page link is a duplicate.');
		}

		if ($this->key) {
			// SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			// Version pac_config (JSON) for components, fall back to pac_body for legacy content
			$config_json = $this->get('pac_config');
			$version_content = $config_json ?: $this->get('pac_body');

			ContentVersion::NewVersion(
				ContentVersion::TYPE_PAGE_CONTENT,
				$this->key,
				$version_content,
				$this->get('pac_title'),
				$this->get('pac_title')
			);
		}

		parent::save($debug);
	}

	/**
	 * Generate a URL-safe slug from a string
	 *
	 * @param string $input Input string
	 * @return string URL-safe slug
	 */
	public function generate_slug($input) {
		$slug = strtolower($input);
		$slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
		$slug = preg_replace('/-+/', '-', $slug);
		$slug = trim($slug, '-');
		return $slug;
	}
}

class MultiPageContent extends SystemMultiBase {
	protected static $model_class = 'PageContent';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['pac_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['page_id'])) {
			$filters['pac_pag_page_id'] = array($this->options['page_id'], PDO::PARAM_INT);
		}

		// Filter by component type
		if (isset($this->options['component_id'])) {
			$filters['pac_com_component_id'] = array($this->options['component_id'], PDO::PARAM_INT);
		}

		// Filter for components only (has component type)
		if (isset($this->options['components_only']) && $this->options['components_only']) {
			$filters['pac_com_component_id'] = "IS NOT NULL";
		}

		// Filter for legacy content only (no component type)
		if (isset($this->options['legacy_only']) && $this->options['legacy_only']) {
			$filters['pac_com_component_id'] = "IS NULL";
		}

		// Filter by slug
		if (isset($this->options['slug'])) {
			$filters['pac_location_name'] = array($this->options['slug'], PDO::PARAM_STR);
		}

		if (isset($this->options['link'])) {
			$filters['pac_link'] = array($this->options['link'], PDO::PARAM_STR);
		}

		if (isset($this->options['has_link'])) {
			$filters['pac_link'] = "LENGTH(pac_link) > 0";
		}

		if (isset($this->options['deleted'])) {
			$filters['pac_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('pac_page_contents', $filters, $this->order_by, $only_count, $debug);
	}

	/**
	 * Get dropdown array for select fields
	 *
	 * @param bool $include_empty Include empty option at start
	 * @return array Associative array of id => title
	 */
	public function get_dropdown_array($include_empty = false) {
		$items = array();
		if ($include_empty) {
			$items[''] = '-- Select Component --';
		}
		foreach ($this as $content) {
			$title = $content->get('pac_title') ?: $content->get('pac_location_name');
			$items[$content->key] = $title;
		}
		return $items;
	}
}

?>
