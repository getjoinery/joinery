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

// ---- Product fulfillment: customer-cloud server (the store↔server_manager
// seam). Registered only when the store plugin is present; picking it on a
// product is the entire product-side setup for cloud hosting. The provider
// contributes the one requirement its line carries (ManagedSiteRequirement:
// the id of the site the buyer configured on /profile/server_manager/configure),
// so nothing is attached by hand on the product edit page.
$smf_registry = PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php');
if (file_exists($smf_registry)) {
	require_once($smf_registry);
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php'));
	FulfillmentRegistry::register(new CustomerCloudFulfillment());
}
