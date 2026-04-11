<?php
// PathHelper is pre-loaded when accessed via the route system

function admin_marketplace_logic($get, $post) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/themes_class.php'));
	require_once(PathHelper::getIncludePath('data/plugins_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$upgrade_source = $settings->get_setting('upgrade_source');

	if (empty($upgrade_source)) {
		return LogicResult::render(array(
			'error' => 'No upgrade source configured. Set the <strong>upgrade_source</strong> setting in Admin &gt; Settings to use the marketplace.',
			'themes' => array(),
			'plugins' => array(),
		));
	}

	// Handle install action (POST only)
	if (isset($post['action']) && $post['action'] === 'install') {
		return handle_marketplace_install($post, $upgrade_source, $session);
	}

	// Fetch remote catalogs
	$remote_themes = fetch_marketplace_catalog($upgrade_source, 'themes');
	$remote_plugins = fetch_marketplace_catalog($upgrade_source, 'plugins');

	// Get local install names
	$local_themes = array_column(Theme::get_all_themes_with_status(), 'name');
	$local_plugins = array_column(MultiPlugin::get_all_plugins_with_status(), 'name');

	// Merge remote + local status
	$themes = enrich_with_local_status($remote_themes, $local_themes, 'theme');
	$plugins = enrich_with_local_status($remote_plugins, $local_plugins, 'plugin');

	return LogicResult::render(array(
		'message' => $get['message'] ?? '',
		'error' => $get['error'] ?? '',
		'themes' => $themes,
		'plugins' => $plugins,
		'upgrade_source' => $upgrade_source,
		'catalog_error' => (empty($remote_themes) && empty($remote_plugins)),
	));
}

/**
 * Fetch catalog from upgrade server
 */
function fetch_marketplace_catalog($upgrade_source, $type) {
	$url = rtrim($upgrade_source, '/') . '/admin/server_manager/publish_theme?list=' . urlencode($type);

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
 * Enrich remote items with local install status
 */
function enrich_with_local_status($remote_items, $local_names, $type) {
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
 * Handle install action (POST)
 */
function handle_marketplace_install($post, $upgrade_source, $session) {
	// CSRF check
	$token = $post['_csrf_token'] ?? '';
	if (empty($token) || !isset($_SESSION['csrf_tokens']['marketplace_install'])) {
		$session->save_message(new DisplayMessage(
			'Invalid request token. Please try again.',
			'Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
		return LogicResult::redirect('/admin/server_manager/marketplace');
	}

	$stored = $_SESSION['csrf_tokens']['marketplace_install'];
	if ($stored['expires'] < time() || !hash_equals($stored['token'], $token)) {
		unset($_SESSION['csrf_tokens']['marketplace_install']);
		$session->save_message(new DisplayMessage(
			'Invalid or expired request token. Please try again.',
			'Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
		return LogicResult::redirect('/admin/server_manager/marketplace');
	}
	unset($_SESSION['csrf_tokens']['marketplace_install']);

	$name = basename($post['name'] ?? '');
	$type = ($post['type'] ?? '') === 'plugin' ? 'plugin' : 'theme';

	if (empty($name)) {
		$session->save_message(new DisplayMessage(
			'No item specified.',
			'Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
		return LogicResult::redirect('/admin/server_manager/marketplace');
	}

	// Build download URL
	$download_url = rtrim($upgrade_source, '/') . '/admin/server_manager/publish_theme?download=' . urlencode($name);
	if ($type === 'plugin') {
		$download_url .= '&type=plugin';
	}

	// Download to temp file
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
		$session->save_message(new DisplayMessage(
			"Failed to download $type '$name': HTTP $http_code" . ($curl_error ? " ($curl_error)" : ''),
			'Download Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
		return LogicResult::redirect('/admin/server_manager/marketplace');
	}

	// Install via manager
	try {
		if ($type === 'plugin') {
			require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
			$manager = new PluginManager();
		} else {
			require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
			$manager = new ThemeManager();
		}

		$installed_name = $manager->installFromTarGz($temp_file);
		$manager->sync();

		@unlink($temp_file);

		$admin_page = $type === 'plugin' ? '/admin/admin_plugins' : '/admin/admin_themes';
		$session->save_message(new DisplayMessage(
			ucfirst($type) . " '$installed_name' installed successfully. <a href=\"$admin_page\">Go to " . ucfirst($type) . "s</a> to activate it.",
			'Installed',
			NULL,
			DisplayMessage::MESSAGE_ANNOUNCEMENT
		));
		return LogicResult::redirect('/admin/server_manager/marketplace');

	} catch (Exception $e) {
		@unlink($temp_file);
		$session->save_message(new DisplayMessage(
			'Install failed: ' . $e->getMessage(),
			'Install Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
		return LogicResult::redirect('/admin/server_manager/marketplace');
	}
}
?>
