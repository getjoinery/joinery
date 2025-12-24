<?php
/**
 * ComponentRenderer - Renders page components by slug
 *
 * Provides static methods to render component instances either by slug
 * or by PageContent object. Handles template resolution, logic function
 * execution, and debug output for troubleshooting.
 *
 * Usage:
 *   // Render by slug (explicit call in template)
 *   echo ComponentRenderer::render('homepage-hero');
 *
 *   // Render PageContent object directly (used by Page::get_filled_content())
 *   echo ComponentRenderer::render_component($page_content);
 *
 * @see /specs/page_component_system.md
 * @version 1.0.0
 */

class ComponentRenderer {

	/**
	 * Output debug message as HTML comment
	 * Uses Globalvars 'debug' setting to determine visibility
	 *
	 * @param string $message Debug message
	 * @param string $slug Component slug for context
	 * @return string HTML comment or empty string
	 */
	protected static function debug_output($message, $slug = '') {
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('debug')) {
			return '';
		}
		$slug_info = $slug ? " (slug: {$slug})" : '';
		return "<!-- ComponentRenderer{$slug_info}: {$message} -->\n";
	}

	/**
	 * Render a component by its slug
	 *
	 * @param string $slug The component's slug (pac_location_name)
	 * @return string Rendered HTML (includes debug comments for admins on errors)
	 */
	public static function render($slug) {
		require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

		$component_instance = PageContent::get_by_slug($slug);

		if (!$component_instance) {
			return self::debug_output("Component not found", $slug);
		}

		if (!$component_instance->is_component()) {
			return self::debug_output("Record exists but is not a component (no pac_com_component_id)", $slug);
		}

		if (!$component_instance->is_visible()) {
			return self::debug_output("Component exists but is not published", $slug);
		}

		return self::render_component($component_instance, $slug);
	}

	/**
	 * Render a single component instance
	 *
	 * @param PageContent $component_instance The component instance
	 * @param string $slug For debug output (optional, extracted from instance if not provided)
	 * @return string Rendered HTML
	 */
	public static function render_component($component_instance, $slug = '') {
		if (empty($slug)) {
			$slug = $component_instance->get('pac_location_name');
		}

		$component_type = $component_instance->get_component_type();

		if (!$component_type) {
			return self::debug_output("Component type not found (pac_com_component_id may reference deleted type)", $slug);
		}

		if (!$component_type->is_available()) {
			$type_key = $component_type->get('com_type_key');
			return self::debug_output("Component type '{$type_key}' is inactive", $slug);
		}

		// Check if component requires a plugin
		$required_plugin = $component_type->get('com_requires_plugin');
		if ($required_plugin) {
			if (!class_exists('PluginHelper') || !PluginHelper::isPluginActive($required_plugin)) {
				return self::debug_output("Required plugin '{$required_plugin}' is not active", $slug);
			}
		}

		$config = $component_instance->get_config();
		$data = array();

		// Load dynamic data if needed
		$logic_function = $component_type->get('com_logic_function');
		if ($logic_function) {
			try {
				$data = self::load_component_data($logic_function, $config, $slug);
			} catch (Exception $e) {
				return self::debug_output("Logic function '{$logic_function}' threw exception: " . $e->getMessage(), $slug);
			}
		}

		// Render template
		$template_file = $component_type->get('com_template_file');
		if (!$template_file) {
			return self::debug_output("Component type has no template file configured", $slug);
		}

		// Use theme-aware path resolution
		// Template files are stored as filename only (e.g., 'hero_static.php')
		// and always located in views/components/ subdirectory
		$template_path = PathHelper::getThemeFilePath($template_file, 'views/components');
		if (!file_exists($template_path)) {
			return self::debug_output("Template file not found: {$template_file} (resolved to: {$template_path})", $slug);
		}

		ob_start();

		// Make variables available to template
		$component_config = $config;
		$component_data = $data;
		$component = $component_instance;
		$component_type_record = $component_type;
		$component_slug = $slug;

		try {
			require($template_path);
		} catch (Exception $e) {
			ob_end_clean();
			return self::debug_output("Template threw exception: " . $e->getMessage(), $slug);
		}

		return ob_get_clean();
	}

	/**
	 * Load dynamic data for a component
	 *
	 * Logic functions are located in logic/components/ directory and follow
	 * the naming convention: function_name.php containing function function_name($config)
	 *
	 * @param string $logic_function Function name to call
	 * @param array $config Component configuration
	 * @param string $slug For debug output
	 * @return array Data for the template
	 */
	protected static function load_component_data($logic_function, $config, $slug = '') {
		$logic_file = 'logic/components/' . $logic_function . '.php';
		$full_path = PathHelper::getIncludePath($logic_file);

		if (!file_exists($full_path)) {
			// Return empty but log debug - not a fatal error
			error_log("ComponentRenderer: Logic file not found: {$logic_file}");
			return array();
		}

		require_once($full_path);

		if (!function_exists($logic_function)) {
			error_log("ComponentRenderer: Logic function '{$logic_function}' not defined in {$logic_file}");
			return array();
		}

		return call_user_func($logic_function, $config);
	}

	/**
	 * Check if a component slug exists and is renderable
	 *
	 * Useful for conditional rendering in templates
	 *
	 * @param string $slug The component slug
	 * @return bool True if component exists, is a component type, and is published
	 */
	public static function exists($slug) {
		require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

		$component_instance = PageContent::get_by_slug($slug);
		if (!$component_instance) {
			return false;
		}
		if (!$component_instance->is_component()) {
			return false;
		}
		if (!$component_instance->is_visible()) {
			return false;
		}
		return true;
	}

	/**
	 * Get all components for a page, ordered by pac_order
	 *
	 * @param int $page_id Page ID
	 * @param bool $published_only Only return published components
	 * @return array Array of PageContent objects
	 */
	public static function get_page_components($page_id, $published_only = true) {
		require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

		$options = [
			'page_id' => $page_id,
			'components_only' => true,
			'deleted' => false
		];

		if ($published_only) {
			$options['published'] = true;
		}

		$components = new MultiPageContent($options, ['pac_order' => 'ASC']);
		$components->load();

		$result = [];
		foreach ($components as $component) {
			$result[] = $component;
		}

		return $result;
	}

	/**
	 * Render multiple components in sequence
	 *
	 * @param array $slugs Array of component slugs
	 * @return string Combined rendered HTML
	 */
	public static function render_multiple($slugs) {
		$output = '';
		foreach ($slugs as $slug) {
			$output .= self::render($slug);
		}
		return $output;
	}
}
