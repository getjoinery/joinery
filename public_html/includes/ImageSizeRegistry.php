<?php
/**
 * ImageSizeRegistry - Theme-driven image size configuration
 *
 * Merges image sizes from Falcon (admin theme, always included),
 * the active public theme, and any plugin-declared sizes.
 * Themes declare sizes in their theme.json under "image_sizes".
 *
 * @version 1.1.0 - max_dimensions(): the largest width and height any size asks for, for ImageDecoder
 * @version 1.0.0
 * @see /specs/pictures_refactor_spec.md
 */

class ImageSizeRegistry {

	private static $cached_sizes = null;

	/**
	 * Get all registered image sizes, merged from all sources
	 *
	 * @return array Associative array of size_key => config
	 */
	public static function get_sizes() {
		if (self::$cached_sizes !== null) {
			return self::$cached_sizes;
		}

		$sizes = [];

		// 1. Start with joinery-system sizes (admin theme, always loaded)
		$admin_sizes = ThemeHelper::config('image_sizes', [], 'joinery-system');
		if (is_array($admin_sizes)) {
			$sizes = $admin_sizes;
		}

		// 2. Merge active public theme sizes (wins on key conflicts)
		$active_theme = ThemeHelper::getActive();
		if ($active_theme && $active_theme !== 'falcon') {
			$theme_sizes = ThemeHelper::config('image_sizes', [], $active_theme);
			if (is_array($theme_sizes)) {
				$sizes = array_merge($sizes, $theme_sizes);
			}
		}

		// 3. Merge plugin-declared sizes
		if (class_exists('PluginHelper')) {
			$plugins = PluginHelper::getActivePlugins();
			if (is_array($plugins)) {
				foreach ($plugins as $plugin_name => $plugin) {
					$plugin_sizes = $plugin->get('image_sizes', []);
					if (is_array($plugin_sizes)) {
						$sizes = array_merge($sizes, $plugin_sizes);
					}
				}
			}
		}

		// Normalize all size configs
		foreach ($sizes as $key => &$config) {
			$config = self::normalize_config($config);
		}
		unset($config);

		self::$cached_sizes = $sizes;
		return $sizes;
	}

	/**
	 * Get a specific size configuration
	 *
	 * @param string $key Size key
	 * @return array|null Size config or null if not found
	 */
	/**
	 * The largest width and the largest height any registered size asks for:
	 * what a decode must keep so every size, cropped or scaled, comes out as it
	 * would from the full image (ImageDecoder::open takes this pair). A size
	 * that constrains neither dimension wants the full image, so the answer
	 * is then unbounded.
	 *
	 * @return array{width:int,height:int}
	 */
	public static function max_dimensions() {
		$w = 0;
		$h = 0;
		foreach (self::get_sizes() as $config) {
			if ($config['width'] <= 0 && $config['height'] <= 0) {
				return ['width' => PHP_INT_MAX, 'height' => PHP_INT_MAX];
			}
			$w = max($w, (int)$config['width']);
			$h = max($h, (int)$config['height']);
		}
		return ['width' => max(1, $w), 'height' => max(1, $h)];
	}

	public static function get_size($key) {
		$sizes = self::get_sizes();
		return isset($sizes[$key]) ? $sizes[$key] : null;
	}

	/**
	 * Check if a size key is registered
	 *
	 * @param string $key Size key
	 * @return bool
	 */
	public static function has_size($key) {
		$sizes = self::get_sizes();
		return isset($sizes[$key]);
	}

	/**
	 * Clear the cached sizes (used after theme switch)
	 */
	public static function clear_cache() {
		self::$cached_sizes = null;
	}

	/**
	 * Normalize a size config array to ensure all keys exist
	 *
	 * @param array $config Raw config from theme.json
	 * @return array Normalized config
	 */
	private static function normalize_config($config) {
		return [
			'width' => isset($config['width']) ? (int) $config['width'] : 0,
			'height' => isset($config['height']) ? (int) $config['height'] : 0,
			'crop' => isset($config['crop']) ? (bool) $config['crop'] : false,
			'quality' => isset($config['quality']) ? (int) $config['quality'] : 85,
		];
	}
}
