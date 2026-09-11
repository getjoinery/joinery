<?php
/**
 * Server Manager plugin routes.
 *
 * The publish endpoint needs its own route because it enforces its own
 * permission check (level 8+) inside the script. Without this route,
 * /admin/server_manager/publish would fall through to the /admin/* wildcard
 * which requires min_permission 5 — letting unprivileged users in.
 */
$routes = [
	'dynamic' => [
		'/admin/server_manager/publish' => [
			'view' => 'plugins/server_manager/includes/publish_upgrade',
		],
		'/admin/server_manager/publish_theme' => [
			'view' => 'plugins/server_manager/includes/publish_theme',
		],
	],
];

// ---- Product fulfillment: customer-cloud server (the store↔server_manager
// seam). Registered only when the store plugin is present; picking it on a
// product is the entire product-side setup for BYO-cloud hosting.
$smf_registry = PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php');
if (file_exists($smf_registry)) {
	require_once($smf_registry);
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php'));
	FulfillmentRegistry::register(new CustomerCloudFulfillment());

	// ---- Product requirement: managed domain registration. Attached per
	// product (a pri_ row, picked from "Info to collect before purchase"),
	// never injected by a fulfillment provider — an injected requirement never
	// receives post_purchase(), and this type's whole intake IS post_purchase.
	// A pri_ attachment also keeps the domain leg orthogonal to compute mode:
	// shared-host products have no fulfillment provider at all.
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedDomainRequirement.php'));
}
