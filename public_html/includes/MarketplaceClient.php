<?php
/**
 * Marketplace client — the consumer side of extension distribution.
 *
 * Fetches the theme/plugin catalogs a source site publishes and installs an
 * archive from them. The publishing side (catalog + archive serving) lives in
 * the server_manager plugin on the source site; this class needs only the
 * `upgrade_source` setting and ships in core so every site can acquire
 * extensions. Used by the /admin/admin_marketplace page and the
 * marketplace_catalog / marketplace_install API actions.
 *
 * @version 1.0.0
 */

class MarketplaceClient {

	/**
	 * The configured source site base URL, or null when unset.
	 */
	public static function source() {
		$source = Globalvars::get_instance()->get_setting('upgrade_source');
		return empty($source) ? null : rtrim($source, '/');
	}

	/**
	 * Fetch a catalog from the source site.
	 *
	 * @param string $type 'themes' or 'plugins'
	 * @return array Catalog items; empty on any failure (logged)
	 */
	public static function fetch_catalog($type) {
		if ($type !== 'themes' && $type !== 'plugins') {
			throw new InvalidArgumentException("Catalog type must be 'themes' or 'plugins', got '$type'");
		}
		$source = self::source();
		if ($source === null) {
			return array();
		}

		$url = $source . '/admin/server_manager/publish_theme?list=' . urlencode($type);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => true,
		]);
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($http_code !== 200 || !$response) {
			error_log("Marketplace: failed to fetch $type catalog from $url — HTTP $http_code, $curl_error");
			return array();
		}

		$data = json_decode($response, true);
		if (!$data || empty($data['success'])) {
			return array();
		}

		return $data[$type] ?? array();
	}

	/**
	 * Directory names of locally present extensions of a type, whatever their
	 * install/active state — presence on disk is what "installed" means to
	 * the catalog display.
	 *
	 * @param string $type 'theme' or 'plugin'
	 * @return array Directory names
	 */
	public static function local_names($type) {
		if ($type === 'plugin') {
			return array_column(MultiPlugin::get_all_plugins_with_status(), 'name');
		}
		return array_column(Theme::get_all_themes_with_status(), 'name');
	}

	/**
	 * Mark each catalog item installed / not_installed against the local list.
	 *
	 * @param array  $remote_items Catalog items from fetch_catalog()
	 * @param array  $local_names  Directory names from local_names()
	 * @param string $type         'theme' or 'plugin'
	 * @return array Items with 'type' and 'install_status' added
	 */
	public static function enrich_with_local_status($remote_items, $local_names, $type) {
		$result = array();
		foreach ($remote_items as $item) {
			$dir_name = $item['directory_name'] ?? $item['name'];
			$item['type'] = $type;
			$item['install_status'] = in_array($dir_name, $local_names) ? 'installed' : 'not_installed';
			$result[] = $item;
		}
		return $result;
	}

	/**
	 * Download an extension archive from the source and install it.
	 *
	 * Delegates the archive handling to PluginManager/ThemeManager
	 * installFromTarGz(), which enforces the receives_upgrades: false
	 * overwrite refusal, then syncs so the extension is registered and
	 * ready to activate.
	 *
	 * @param string $type 'theme' or 'plugin'
	 * @param string $name Directory name as listed in the catalog
	 * @return string Installed extension name
	 * @throws Exception on download or install failure
	 */
	public static function install($type, $name) {
		if ($type !== 'theme' && $type !== 'plugin') {
			throw new InvalidArgumentException("Install type must be 'theme' or 'plugin', got '$type'");
		}
		$name = basename((string)$name);
		if ($name === '' || $name === '.' || $name === '..') {
			throw new InvalidArgumentException('No item specified.');
		}
		$source = self::source();
		if ($source === null) {
			throw new Exception('No upgrade source configured. Set the upgrade_source setting to use the marketplace.');
		}

		$download_url = $source . '/admin/server_manager/publish_theme?download=' . urlencode($name);
		if ($type === 'plugin') {
			$download_url .= '&type=plugin';
		}

		$temp_file = tempnam(sys_get_temp_dir(), 'mkt_') . '.tar.gz';

		$ch = curl_init($download_url);
		$fp = fopen($temp_file, 'w');
		curl_setopt_array($ch, [
			CURLOPT_FILE => $fp,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => true,
		]);
		curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);
		fclose($fp);

		if ($http_code !== 200) {
			@unlink($temp_file);
			throw new Exception("Failed to download $type '$name': HTTP $http_code" . ($curl_error ? " ($curl_error)" : ''));
		}

		try {
			$manager = ($type === 'plugin') ? new PluginManager() : new ThemeManager();

			$installed_name = $manager->installFromTarGz($temp_file);
			$manager->sync();

			return $installed_name;
		} finally {
			@unlink($temp_file);
		}
	}
}
?>
