<?php
/**
 * The filter/service catalog: every category filter and service the editor
 * offers, with restricted (advanced) filters flagged so clients never
 * hardcode the catalog or the gating.
 *
 * The response is static per deployment — clients should cache it (fetch on
 * launch, not per screen).
 *
 * Exposed as POST /api/v1/action/dns_filtering/catalog.
 *
 * @version 1.0
 */

function catalog_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScrollDaddyHelper.php'));

	$session = SessionControl::get_instance();

	if (!$session->get_user_id()) {
		return LogicResult::error('Not logged in.');
	}

	$restricted = ScrollDaddyHelper::getRestrictedFilters();

	$filters = array();
	foreach (ScrollDaddyHelper::$filters as $key => $label) {
		$filters[] = array(
			'key' => $key,
			'label' => $label,
			'advanced' => in_array($key, $restricted, true),
		);
	}

	$service_categories = array();
	foreach (ScrollDaddyHelper::$service_categories as $key => $label) {
		$service_categories[] = array(
			'key' => $key,
			'label' => $label,
		);
	}

	$services = array();
	foreach (ScrollDaddyHelper::$services as $category_key => $items) {
		$services[$category_key] = array();
		foreach ($items as $key => $label) {
			$services[$category_key][] = array(
				'key' => $key,
				'label' => $label,
			);
		}
	}

	return LogicResult::render(array(
		'filters' => $filters,
		'service_categories' => $service_categories,
		'services' => $services,
	));
}

function catalog_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'Filter and service catalog with advanced-filter flags. Static per deployment — cache client-side.',
	];
}

?>
