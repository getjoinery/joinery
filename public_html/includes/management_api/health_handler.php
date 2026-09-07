<?php
/**
 * GET /api/v1/management/health
 *
 * Lightweight liveness probe. A management node asks this to establish that a
 * site is answering — the node health probe and the dashboard's status refresh
 * both call it. It decides no routing: jobs reach a node as primitives over the
 * signed channel, never over this API. The probe should stay cheap and
 * deterministic — do NOT add database checks, filesystem scans, etc.
 */

function health_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'Liveness probe. Used by the server_manager management node to pick API vs SSH.',
	];
}

function health_handler($request) {
	return [
		'ok'      => true,
		'version' => LibraryFunctions::get_joinery_version(),
	];
}
?>
