<?php
/**
 * API App Platform Endpoints
 *
 * Serves /api/v1/app/*: the server-driven pieces native apps consume.
 *
 *   GET /api/v1/app/navigation — session-key-authenticated; the user's menu as
 *   a routing table for the app's tab bar and More list (docs/mobile_apps.md)
 *
 * Dispatched from api/apiv1.php after $api_user resolves — every /app/*
 * endpoint requires an authenticated app session key. Uses the api_error()/
 * api_success() helpers defined in apiv1.php.
 *
 * The navigation source is the seeded profile menu store (amu_admin_menus,
 * user_dropdown location) — the same accessor every web theme renders — so a
 * new plugin profileMenu entry appears in shipped apps with no release.
 *
 * @version 1.0.0
 */

class ApiAppEndpoint {

	// Entries the native shell owns (auth flows); never sent to apps. The
	// signed-out entries could never match a key-authenticated request, but
	// the shell contract is explicit rather than inferred from visibility.
	const SHELL_SLUGS = array('core-signin', 'core-signout', 'core-signup', 'core-forgot-password');

	/**
	 * Post-authentication dispatch for /app/*. Always exits.
	 */
	public static function dispatchAuthenticated($url_segments, $api_entry, $api_user, $headers) {
		$endpoint = strtolower($url_segments[3] ?? '');

		if ($endpoint === 'navigation') {
			self::handle_navigation($api_entry, $api_user, $headers);
		}

		api_error('Unknown app endpoint: ' . $endpoint, 'ActionError', 404);
	}

	/**
	 * GET /api/v1/app/navigation — the filtered menu as a routing table.
	 *
	 * Each entry carries a destination the client resolves version-safely:
	 * {type: "web", url} renders in the authenticated webview; {type:
	 * "native", screen, fallback_url} renders the named native screen when
	 * the shipped client recognizes it, else loads fallback_url. `tabs` lists
	 * the tab-bar-pinned slugs for this client_app (from the app_navigation
	 * setting); everything else belongs in the More list. Always exits.
	 */
	protected static function handle_navigation($api_entry, $api_user, $headers) {
		if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
			api_error('Navigation endpoint must use GET method', 'ActionError', 405);
		}

		if ($api_entry === null || !$api_entry->is_session()) {
			RequestLogger::log('api', 'app/navigation', false, [
				'user_id' => $api_user->key,
				'status_code' => 403,
				'error_type' => 'AuthenticationError',
				'note' => $api_entry === null ? 'Browser session on navigation' : 'Machine key on navigation'
			]);
			api_error('The navigation endpoint serves app session keys only', 'AuthenticationError', 403);
		}

		require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

		// The store's own filters apply: permission vs the user's, visibility
		// (app users are always signed in), setting_activate, and disable.
		$items = MultiAdminMenu::get_user_dropdown_items(true, (int)$api_user->get('usr_permission'));

		$entries = array();
		foreach ($items as $item) {
			$slug = $item->get('amu_slug');
			if (in_array($slug, self::SHELL_SLUGS)) {
				continue;
			}

			// Same URL rule the web menus apply: rooted paths pass through,
			// bare page names are admin pages.
			$defaultpage = (string)$item->get('amu_defaultpage');
			$url = ($defaultpage !== '' && $defaultpage[0] === '/')
				? $defaultpage : '/admin/' . $defaultpage;

			$entries[] = array(
				'slug' => $slug,
				'title' => $item->get('amu_menudisplay'),
				'icon' => $item->get('amu_icon'),
				'order' => (int)$item->get('amu_order'),
				'destination' => array('type' => 'web', 'url' => $url),
			);
		}

		$client_app = isset($headers['client_app']) ? trim($headers['client_app']) : '';
		$tabs = self::pinned_tabs($client_app, array_column($entries, 'slug'));

		RequestLogger::log('api', 'app/navigation', true, [
			'user_id' => $api_user->key,
			'status_code' => 200
		]);

		api_success(array(
			'tabs' => $tabs,
			'entries' => $entries,
		));
	}

	/**
	 * The tab-bar-pinned slugs for a client app, from the app_navigation
	 * setting: a JSON map of client_app → ordered slug list, with a "default"
	 * key for apps without their own entry. Filtered to slugs this user
	 * actually received, preserving the configured order.
	 */
	protected static function pinned_tabs($client_app, $present_slugs) {
		$settings = Globalvars::get_instance();
		$map = json_decode((string)$settings->get_setting('app_navigation', false, true), true);
		if (!is_array($map)) {
			return array();
		}

		$configured = null;
		if ($client_app !== '' && isset($map[$client_app]) && is_array($map[$client_app])) {
			$configured = $map[$client_app];
		} elseif (isset($map['default']) && is_array($map['default'])) {
			$configured = $map['default'];
		}

		if (!$configured) {
			return array();
		}

		return array_values(array_filter($configured,
			fn($slug) => is_string($slug) && in_array($slug, $present_slugs)));
	}

}

?>
