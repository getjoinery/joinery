<?php
/**
 * ComponentRenderer - Renders page components by slug
 *
 * Provides static methods to render component instances either by slug
 * or by PageContent object. Handles template resolution, logic function
 * execution, layout wrapping, and debug output for troubleshooting.
 *
 * Usage:
 *   // Render by slug (explicit call in template)
 *   echo ComponentRenderer::render('homepage-hero');
 *
 *   // Render PageContent object directly (used by Page::get_filled_content())
 *   echo ComponentRenderer::render_component($page_content);
 *
 * @see /specs/page_component_system.md
 * @version 1.3.0
 */

class ComponentRenderer {

	/** @var bool Whether the layout CSS has been output on this page */
	protected static $layout_css_output = false;

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
			return self::debug_output("Component exists but is deleted", $slug);
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
		// Template files can be stored as:
		// - Full relative path: 'theme/linka-reference/views/components/component.php' or 'views/components/component.php'
		// - Filename only (legacy): 'component.php'
		if (strpos($template_file, '/') !== false) {
			// Full relative path - use directly
			$template_path = PathHelper::getIncludePath($template_file);
		} else {
			// Filename only - look in views/components with theme override support
			$template_path = PathHelper::getThemeFilePath($template_file, 'views/components');
		}
		if (!file_exists($template_path)) {
			return self::debug_output("Template file not found: {$template_file} (resolved to: {$template_path})", $slug);
		}

		// Check if component type opts out of layout wrapping
		$layout_defaults = $component_type->get('com_layout_defaults');
		if (is_string($layout_defaults)) {
			$layout_defaults = json_decode($layout_defaults, true) ?: [];
		}
		$skip_wrapper = !empty($layout_defaults['skip_wrapper']);

		// Compute layout variables for this component instance
		// NULL = no restriction; any CSS value = used as max-width/max-height
		$container_width = $component_instance->get('pac_max_width');
		$max_height = $component_instance->get('pac_max_height');

		$layout_vars = self::get_layout_vars($container_width, $max_height);

		// Template variables for layout (available to all templates)
		$container_class = $layout_vars['container_class'];
		$container_style = $layout_vars['container_style'];
		$max_height_style = $layout_vars['max_height_style'];

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

		$html = ob_get_clean();

		// Conditionally wrap output with layout div (skip if component type opts out)
		if (!$skip_wrapper && trim($html) !== '') {
			$html = self::wrap_with_layout($html, $layout_vars);
		}

		return $html;
	}

	/**
	 * Compute layout variables from instance settings
	 *
	 * Returns CSS custom property values (for the wrapper) and
	 * template variables (for skip_wrapper templates).
	 *
	 * NULL/empty = no restriction (no wrapper needed).
	 * Any CSS value = used as max-width or max-height.
	 *
	 * @param string|null $width CSS max-width value or null
	 * @param string|null $max_height CSS max-height value or null
	 * @return array Layout variables
	 */
	protected static function get_layout_vars($width, $max_height) {
		$vars = [
			'container_class' => 'container',
			'container_style' => '',
			'max_height_style' => '',
			'cl_max_width' => null,
			'cl_max_height' => null,
		];

		if (!empty($width)) {
			$vars['cl_max_width'] = $width;
			$vars['container_style'] = 'max-width:' . $width;
		}

		if (!empty($max_height)) {
			$vars['cl_max_height'] = $max_height;
			$vars['max_height_style'] = 'max-height:' . $max_height . ';overflow:hidden';
		}

		return $vars;
	}

	/**
	 * Wrap template output with layout div
	 *
	 * Only adds a wrapper when at least one layout setting is non-default.
	 * Uses data-maxw/data-maxh attributes to scope CSS rules so they only
	 * fire when their respective property is defined.
	 *
	 * @param string $html Template output HTML
	 * @param array $layout_vars Layout variables from get_layout_vars()
	 * @return string Wrapped HTML (or original if both are default)
	 */
	protected static function wrap_with_layout($html, $layout_vars) {
		$has_width = ($layout_vars['cl_max_width'] !== null);
		$has_height = ($layout_vars['cl_max_height'] !== null);

		// No wrapper needed when both are default
		if (!$has_width && !$has_height) {
			return $html;
		}

		// Build wrapper attributes
		$styles = [];
		$attrs = 'class="component-layout"';

		if ($has_width) {
			$attrs .= ' data-maxw';
			$styles[] = '--cl-max-width: ' . $layout_vars['cl_max_width'];
		}
		if ($has_height) {
			$attrs .= ' data-maxh';
			$styles[] = '--cl-max-height: ' . $layout_vars['cl_max_height'];
		}

		if (!empty($styles)) {
			$attrs .= ' style="' . implode('; ', $styles) . '"';
		}

		// Include layout CSS on first use
		$css = self::get_layout_css();

		return $css . '<div ' . $attrs . '>' . "\n" . $html . '</div>' . "\n";
	}

	/**
	 * Get the layout CSS rules (output once per page)
	 *
	 * Injects a <style> block on the first render that uses layout wrapping.
	 * This ensures the CSS is always available regardless of theme.
	 *
	 * @return string Style tag HTML or empty string if already output
	 */
	protected static function get_layout_css() {
		if (self::$layout_css_output) {
			return '';
		}
		self::$layout_css_output = true;

		return '<style>
/* Component Layout Controls */
.component-layout[data-maxw] .container,
.component-layout[data-maxw] .container-fluid,
.component-layout[data-maxw] .container-lg,
.component-layout[data-maxw] .container-xl {
	max-width: var(--cl-max-width);
}
.component-layout[data-maxw]:not(:has(.container, .container-fluid, .container-lg, .container-xl)) > :first-child {
	max-width: var(--cl-max-width);
	margin-left: auto;
	margin-right: auto;
}
.component-layout[data-maxh] > :first-child {
	max-height: var(--cl-max-height);
	overflow: hidden;
}
</style>
';
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
	 * @return bool True if component exists, is a component type, and is not deleted
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
	 * @return array Array of PageContent objects
	 */
	public static function get_page_components($page_id) {
		require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

		$options = [
			'page_id' => $page_id,
			'components_only' => true,
			'deleted' => false
		];

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
