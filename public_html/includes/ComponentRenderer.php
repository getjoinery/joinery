<?php
/**
 * ComponentRenderer - Renders page components
 *
 * Provides static methods to render component instances by slug or
 * component type key. Handles template resolution, logic function
 * execution, layout wrapping, and debug output for troubleshooting.
 *
 * Usage:
 *   // Render by slug
 *   echo ComponentRenderer::render('homepage-hero');
 *
 *   // Render by slug with config overrides
 *   echo ComponentRenderer::render('homepage-hero', null, ['heading' => 'Custom']);
 *
 *   // Render by type key (programmatic, no database instance)
 *   echo ComponentRenderer::render(null, 'image_gallery', ['photos' => $photos]);
 *
 * @see /specs/page_component_system.md
 * @version 1.5.0
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
	 * Render a component by slug, type key, or both
	 *
	 * Three modes:
	 *   render('slug')                         - Load from database by slug
	 *   render('slug', null, $overrides)        - Load from database, merge overrides into config
	 *   render(null, 'type_key', $config)       - Render by type key with provided config (no database instance)
	 *
	 * @param string|null $slug The component's slug (pac_location_name), or null for type_key mode
	 * @param string|null $type_key The component type key (com_type_key), used when $slug is null
	 * @param array $overrides Config values merged into database config (slug mode) or used as full config (type_key mode)
	 * @return string Rendered HTML (includes debug comments for admins on errors)
	 */
	public static function render($slug, $type_key = null, $overrides = []) {
		$component_instance = null;
		$component_type = null;
		$debug_label = $slug ?: $type_key ?: '';

		// --- Resolve component type ---

		if (!empty($slug)) {
			// Slug mode: load instance from database
			require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

			$component_instance = PageContent::get_by_slug($slug);

			if (!$component_instance) {
				return self::debug_output("Component not found", $debug_label);
			}
			if (!$component_instance->is_component()) {
				return self::debug_output("Record exists but is not a component (no pac_com_component_id)", $debug_label);
			}
			if (!$component_instance->is_visible()) {
				return self::debug_output("Component exists but is deleted", $debug_label);
			}

			$component_type = $component_instance->get_component_type();
			if (!$component_type) {
				return self::debug_output("Component type not found (pac_com_component_id may reference deleted type)", $debug_label);
			}

		} elseif (!empty($type_key)) {
			// Type key mode: look up component type directly
			require_once(PathHelper::getIncludePath('data/components_class.php'));

			$component_type = Component::get_by_type_key($type_key);
			if (!$component_type) {
				return self::debug_output("Component type '{$type_key}' not found", $debug_label);
			}

		} else {
			return '';
		}

		if (!$component_type->is_available()) {
			$tk = $component_type->get('com_type_key');
			return self::debug_output("Component type '{$tk}' is inactive", $debug_label);
		}

		// Check if component requires a plugin
		$required_plugin = $component_type->get('com_requires_plugin');
		if ($required_plugin) {
			if (!class_exists('PluginHelper') || !PluginHelper::isPluginActive($required_plugin)) {
				return self::debug_output("Required plugin '{$required_plugin}' is not active", $debug_label);
			}
		}

		// --- Build config ---

		if ($component_instance) {
			$config = $component_instance->get_config();
			if (!empty($overrides)) {
				$config = array_merge($config, $overrides);
			}
		} else {
			$config = $overrides;
		}

		// --- Load dynamic data ---

		$data = array();
		$logic_function = $component_type->get('com_logic_function');
		if ($logic_function) {
			try {
				$data = self::load_component_data($logic_function, $config, $debug_label);
			} catch (Exception $e) {
				return self::debug_output("Logic function '{$logic_function}' threw exception: " . $e->getMessage(), $debug_label);
			}
		}

		// --- Resolve template ---

		$template_file = $component_type->get('com_template_file');
		if (!$template_file) {
			return self::debug_output("Component type has no template file configured", $debug_label);
		}

		if (strpos($template_file, '/') !== false) {
			$template_path = PathHelper::getIncludePath($template_file);
		} else {
			$template_path = PathHelper::getThemeFilePath($template_file, 'views/components');
		}
		if (!file_exists($template_path)) {
			return self::debug_output("Template file not found: {$template_file} (resolved to: {$template_path})", $debug_label);
		}

		// --- Layout ---

		$skip_wrapper = true; // Default for type_key mode (no instance)
		$container_width = null;
		$max_height = null;
		$vertical_margin = null;

		if ($component_instance) {
			$layout_defaults = $component_type->get('com_layout_defaults');
			if (is_string($layout_defaults)) {
				$layout_defaults = json_decode($layout_defaults, true) ?: [];
			}
			$skip_wrapper = !empty($layout_defaults['skip_wrapper']);
			$container_width = $component_instance->get('pac_max_width');
			$max_height = $component_instance->get('pac_max_height');
			$vertical_margin = $component_instance->get('pac_vertical_margin');
			if (!$vertical_margin) {
				$vertical_margin = $layout_defaults['vertical_margin'] ?? null;
			}
		}

		$layout_vars = self::get_layout_vars($container_width, $max_height, $vertical_margin);
		$container_class = $layout_vars['container_class'];
		$container_style = $layout_vars['container_style'];
		$max_height_style = $layout_vars['max_height_style'];

		// --- Render template ---

		ob_start();

		$component_config = $config;
		$component_data = $data;
		$component = $component_instance; // null in type_key mode
		$component_type_record = $component_type;
		$component_slug = $slug ?: '';

		try {
			require($template_path);
		} catch (Exception $e) {
			ob_end_clean();
			return self::debug_output("Template threw exception: " . $e->getMessage(), $debug_label);
		}

		$html = ob_get_clean();

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
	 * @param string|null $vertical_margin Margin keyword (none, sm, md, lg, xl) or null
	 * @return array Layout variables
	 */
	protected static function get_layout_vars($width, $max_height, $vertical_margin = null) {
		$vars = [
			'container_class' => 'container',
			'container_style' => '',
			'max_height_style' => '',
			'cl_max_width' => null,
			'cl_max_height' => null,
			'cl_vertical_margin' => null,
		];

		if (!empty($width)) {
			$vars['cl_max_width'] = $width;
			$vars['container_style'] = 'max-width:' . $width;
		}

		if (!empty($max_height)) {
			$vars['cl_max_height'] = $max_height;
			$vars['max_height_style'] = 'max-height:' . $max_height . ';overflow:hidden';
		}

		if (!empty($vertical_margin)) {
			$valid_margins = ['none', 'sm', 'md', 'lg', 'xl'];
			if (in_array($vertical_margin, $valid_margins)) {
				$vars['cl_vertical_margin'] = $vertical_margin;
			}
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
		$has_margin = ($layout_vars['cl_vertical_margin'] !== null);

		// No wrapper needed when all are default
		if (!$has_width && !$has_height && !$has_margin) {
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
		if ($has_margin) {
			$attrs .= ' data-vmargin="' . htmlspecialchars($layout_vars['cl_vertical_margin']) . '"';
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
/* Vertical Margin Controls */
.component-layout[data-vmargin="none"] { margin-top: 0; margin-bottom: 0; }
.component-layout[data-vmargin="sm"] { margin-top: 1rem; margin-bottom: 1rem; }
.component-layout[data-vmargin="md"] { margin-top: 2rem; margin-bottom: 2rem; }
.component-layout[data-vmargin="lg"] { margin-top: 3rem; margin-bottom: 3rem; }
.component-layout[data-vmargin="xl"] { margin-top: 5rem; margin-bottom: 5rem; }
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
