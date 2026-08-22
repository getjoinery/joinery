<?php
/**
 * Marketplace client — the consumer side of extension distribution.
 *
 * Fetches the theme/plugin catalogs a source site publishes and installs an
 * archive from them. The publishing side (catalog + archive serving) lives in
 * the server_manager plugin on the source site; this class ships in core so
 * every site can acquire extensions. It reads two settings: `upgrade_source`,
 * the site to fetch from, and `root_node`, which names the one deployment the
 * estate originates from — that site serves its own catalog, sees every
 * extension whatever audience it declares, and refuses an install that would
 * overwrite its working copy with an archive of itself. Used by the
 * /admin/admin_marketplace page and the marketplace_catalog /
 * marketplace_install API actions.
 *
 * @version 1.2.0
 */

class MarketplaceClient {

	/** Memoized root_node: the catalog asks per extension, each miss a query. */
	private static $root_node = null;

	/**
	 * The site to fetch catalogs and archives from, or null when unset.
	 *
	 * The root node is the origin of what it serves, so it answers with
	 * itself. `upgrade_source` records where a site was installed from,
	 * which on the root names a site running an older copy of the root's
	 * own code — reading a catalog from it would show the origin a stale
	 * mirror of itself, and offer to install yesterday's theme over today's.
	 */
	public static function source() {
		if (self::is_root()) {
			// Not get_absolute_url(): under 'auto' that sniffs $_SERVER, and
			// a plugin refresh runs from CLI too, where sniffing yields
			// http:// for an https-only site. Only an explicit setting of
			// 'http' means http.
			$scheme = Globalvars::get_instance()->get_setting('protocol_mode') === 'http'
				? 'http' : 'https';
			return $scheme . '://' . self::site_identity();
		}
		$source = Globalvars::get_instance()->get_setting('upgrade_source');
		return empty($source) ? null : rtrim($source, '/');
	}

	/**
	 * Domain of the deployment the whole estate originates from — where the
	 * code is written and the extensions are published. Empty when no site
	 * has been named, which is the ordinary case.
	 */
	public static function root_node() {
		if (self::$root_node === null) {
			self::$root_node = self::normalize_host(
				Globalvars::get_instance()->get_setting('root_node'));
		}
		return self::$root_node;
	}

	/**
	 * Whether this site is that origin.
	 *
	 * The setting names a domain rather than raising a flag so a clone or a
	 * restored backup carries it unchanged: it still names the origin, and
	 * still correctly concludes that it is not the origin itself.
	 */
	public static function is_root() {
		$root = self::root_node();
		return $root !== '' && $root === self::site_identity();
	}

	/**
	 * This site's own domain, as the catalog knows it — what an extension's
	 * `audience` list names. Empty when the site has no webDir configured,
	 * which reads as "some anonymous caller" and sees only public items.
	 */
	public static function site_identity() {
		return self::normalize_host(Globalvars::get_instance()->get_setting('webDir'));
	}

	/**
	 * Reduce a host to the form audience entries are compared in: no scheme,
	 * no path, no port, no leading www, lowercase. So an operator can write
	 * "https://www.ZoukPhilly.com/" and mean zoukphilly.com.
	 */
	public static function normalize_host($host) {
		if (!is_string($host) && !is_numeric($host)) {
			return '';
		}
		$host = strtolower(trim((string)$host));
		$host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
		$host = explode('/', $host)[0];
		$host = preg_replace('/:\d+$/', '', $host);
		$host = preg_replace('/^www\./', '', $host);
		return $host;
	}

	/**
	 * Whether a caller may see an extension whose manifest declares `audience`.
	 *
	 * No audience means public — every site sees it, which is what the great
	 * majority of extensions want and why the key is optional. An audience
	 * names the sites the extension was built for: everyone else is not shown
	 * it. A malformed audience hides the extension rather than publishing it,
	 * so a manifest typo cannot leak a site's private theme.
	 *
	 * This is listing visibility, not access control — the archive download is
	 * anonymous by design, so an audience keeps an extension out of catalogs
	 * and fresh installs, it does not keep a determined caller out.
	 *
	 * @param mixed  $audience        Manifest `audience` value (array, or absent)
	 * @param string $requesting_site Domain the caller claims
	 */
	public static function audience_allows($audience, $requesting_site) {
		if ($audience === null || $audience === '' || $audience === array()) {
			return true;
		}
		if (!is_array($audience)) {
			return false;
		}
		$want = self::normalize_host($requesting_site);
		if ($want === '') {
			return false;
		}
		// The origin holds every extension already and is where each one is
		// published from. It sees the whole catalog without every audience
		// having to name it, so no manifest carries a line about the box the
		// work is done on.
		if ($want === self::root_node()) {
			return true;
		}
		foreach ($audience as $entry) {
			if (self::normalize_host($entry) === $want) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch a catalog from the source site.
	 *
	 * Sends this site's own domain so the source can include extensions whose
	 * audience names it.
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

		$url = $source . '/admin/server_manager/publish_theme?list=' . urlencode($type)
			. '&site=' . urlencode(self::site_identity());

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
		// On the origin the catalog is this site's own disk, so every entry in
		// it is already installed and an install can only mean overwriting the
		// working tree with a cached archive of itself — which the publisher
		// caches per version, so an edit made without a version bump would be
		// replaced by the code it replaced.
		if (self::is_root()) {
			throw new Exception('This site publishes the marketplace catalog, so its extensions are already here. Installing one would overwrite the working copy with an archive of itself.');
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
