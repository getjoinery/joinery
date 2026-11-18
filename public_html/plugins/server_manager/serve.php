<?php
/**
 * Server Manager plugin routes.
 *
 * The publish endpoint needs its own route because its script lives in
 * includes/, not views/admin/: the route names the file, and the router checks
 * that file's @joinery-admin header (level 8) before it runs.
 */
$routes = [
	'dynamic' => [
		'/admin/server_manager/publish' => [
			'view' => 'plugins/server_manager/includes/publish_upgrade',
		],
		// Public: other sites' marketplaces fetch it with no session, so the
		// admin page check (declared_permissions 3.4) is turned off here.
		'/admin/server_manager/publish_theme' => [
			'view' => 'plugins/server_manager/includes/publish_theme',
			'admin_page_check' => false,
		],
	],
];

// ---- Member Dashboard: a buyer's sites, with the way to Your Sites ----
// Read by the Dashboard and the apps' dashboard summary on every request, so
// it is registered here, with the routes, as the store's sections are.
require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
ProfileDashboardRegistry::register('your_sites', array('SmHosting', 'dashboard_section'));
