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
