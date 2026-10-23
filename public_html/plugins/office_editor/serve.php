<?php
/**
 * Office editor routes: the WOPI endpoints the editor service calls. The
 * editor page itself (/profile/office_editor/edit) comes from view
 * auto-discovery.
 *
 * @version 0.1.0
 */
$routes = array(
	'dynamic' => array(
		'/office_editor/wopi/files/{id}/contents' => array(
			'handler' => 'includes/wopi_route',
			'plugin'  => 'office_editor',
			'static_cache' => false,
			'valid_page' => false,
		),
		'/office_editor/wopi/files/{id}' => array(
			'handler' => 'includes/wopi_route',
			'plugin'  => 'office_editor',
			'static_cache' => false,
			'valid_page' => false,
		),
	),
);
