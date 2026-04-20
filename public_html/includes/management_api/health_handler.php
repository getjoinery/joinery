<?php
/**
 * GET /api/v1/management/health
 *
 * Lightweight liveness probe. Used by JobCommandBuilder::has_api() to decide
 * whether to route a job via API or SSH. The probe should stay cheap and
 * deterministic — do NOT add database checks, filesystem scans, etc.
 */

function health_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'Liveness probe. Used by the server_manager control plane to pick API vs SSH.',
	];
}

function health_handler($request) {
	return [
		'ok'      => true,
		'version' => LibraryFunctions::get_joinery_version(),
	];
}
?>
