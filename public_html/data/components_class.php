<?php
/**
 * Component - Component Type Library for Page Component System
 *
 * Defines available component types that can be used to build pages.
 * Each component type has a template file, config schema, and optional logic function.
 *
 * @see /specs/page_component_system.md
 * @version 1.1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

// Note: DbConnector, Globalvars, SessionControl are always available - no require needed
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ComponentException extends SystemBaseException {}

class Component extends SystemBase {
	public static $prefix = 'com';
	public static $tablename = 'com_components';
	public static $pkey_column = 'com_component_id';
	public static $permanent_delete_actions = array();

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
		'com_component_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'com_type_key' => array('type'=>'varchar(64)', 'unique'=>true),
		'com_title' => array('type'=>'varchar(255)', 'required'=>true),
		'com_description' => array('type'=>'text'),
		'com_category' => array('type'=>'varchar(64)'),
		'com_icon' => array('type'=>'varchar(64)'),
		'com_template_file' => array('type'=>'varchar(255)'),
		'com_config_schema' => array('type'=>'json'),
		'com_logic_function' => array('type'=>'varchar(255)'),
		'com_is_active' => array('type'=>'bool', 'default'=>true),
		'com_requires_plugin' => array('type'=>'varchar(64)'),
		'com_css_framework' => array('type'=>'varchar(32)'),
		'com_order' => array('type'=>'int2'),
		'com_published_time' => array('type'=>'timestamp(6)'),
		'com_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'com_script_filename' => array('type'=>'varchar(255)'), // Legacy, keep for compatibility
		'com_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $json_vars = array('com_config_schema');

	/**
	 * Get config schema as parsed array
	 * Returns the fields array from the schema, or empty array if invalid
	 *
	 * @return array Array of field definitions
	 */
	public function get_config_schema() {
		$schema = $this->get('com_config_schema');
		if (is_string($schema)) {
			$schema = json_decode($schema, true);
		}
		if (!is_array($schema) || !isset($schema['fields'])) {
			return array();
		}
		return $schema['fields'];
	}

	/**
	 * Get simple field names (non-repeater) for quick access
	 *
	 * @return array Array of field names
	 */
	public function get_simple_field_names() {
		$names = array();
		foreach ($this->get_config_schema() as $field) {
			if (($field['type'] ?? 'textinput') !== 'repeater') {
				$names[] = $field['name'];
			}
		}
		return $names;
	}

	/**
	 * Get repeater field definitions
	 *
	 * @return array Associative array of repeater field name => field definition
	 */
	public function get_repeater_fields() {
		$repeaters = array();
		foreach ($this->get_config_schema() as $field) {
			if (($field['type'] ?? 'textinput') === 'repeater') {
				$repeaters[$field['name']] = $field;
			}
		}
		return $repeaters;
	}

	/**
	 * Check if component type is available (active and not deleted)
	 *
	 * @return bool True if component is available for use
	 */
	public function is_available() {
		if ($this->get('com_delete_time')) {
			return false;
		}
		return (bool)$this->get('com_is_active');
	}

	/**
	 * Get component by type key
	 *
	 * @param string $type_key The component type key (e.g., 'hero_static')
	 * @return Component|null The component or null if not found
	 */
	public static function get_by_type_key($type_key) {
		return static::GetByColumn('com_type_key', $type_key);
	}

	/**
	 * Get available component categories
	 *
	 * @return array Associative array of category key => display name
	 */
	public static function get_categories() {
		return array(
			'hero' => 'Hero Sections',
			'content' => 'Content Blocks',
			'features' => 'Features & Benefits',
			'media' => 'Media & Images',
			'testimonials' => 'Testimonials & Social Proof',
			'dynamic' => 'Dynamic Content',
			'conversion' => 'CTAs & Conversion',
			'layout' => 'Layout & Spacing',
			'custom' => 'Custom & Freeform',
		);
	}

	/**
	 * Get available field types for config schema
	 *
	 * @return array Associative array of type key => display label
	 */
	public static function get_field_types() {
		return array(
			'textinput' => 'Text Input (single line)',
			'passwordinput' => 'Password Input',
			'textarea' => 'Text Area (multi-line)',
			'textbox' => 'Text Box (multi-line)',
			'checkboxinput' => 'Checkbox (yes/no)',
			'radioinput' => 'Radio Buttons',
			'checkboxList' => 'Checkbox List (multiple)',
			'dropinput' => 'Dropdown Select',
			'dateinput' => 'Date Picker',
			'timeinput' => 'Time Picker',
			'datetimeinput' => 'Date & Time Picker',
			'fileinput' => 'File Upload',
			'imageinput' => 'Image Upload',
			'hiddeninput' => 'Hidden Field',
			'repeater' => 'Repeater (grouped fields)',
		);
	}
}

class MultiComponent extends SystemMultiBase {
	protected static $model_class = 'Component';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['category'])) {
			$filters['com_category'] = array($this->options['category'], PDO::PARAM_STR);
		}

		if (isset($this->options['active'])) {
			$filters['com_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
		}

		if (isset($this->options['deleted'])) {
			$filters['com_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['has_type_key'])) {
			$filters['com_type_key'] = "IS NOT NULL";
		}

		if (isset($this->options['type_key'])) {
			$filters['com_type_key'] = array($this->options['type_key'], PDO::PARAM_STR);
		}

		if (isset($this->options['requires_plugin'])) {
			$filters['com_requires_plugin'] = array($this->options['requires_plugin'], PDO::PARAM_STR);
		}

		if (isset($this->options['css_framework'])) {
			$filters['com_css_framework'] = array($this->options['css_framework'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('com_components', $filters, $this->order_by, $only_count, $debug);
	}

	/**
	 * Get dropdown array for select fields
	 *
	 * @param bool $include_empty Include empty option at start
	 * @return array Associative array of component_id => title
	 */
	public function get_dropdown_array($include_empty = false) {
		$items = array();
		if ($include_empty) {
			$items[''] = '-- Select Component Type --';
		}
		foreach ($this as $component) {
			$title = $component->get('com_title');
			$type_key = $component->get('com_type_key');
			$items[$component->key] = $title . ($type_key ? " ({$type_key})" : '');
		}
		return $items;
	}
}

?>
