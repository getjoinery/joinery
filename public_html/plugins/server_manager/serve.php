<?php
/**
 * Server Manager plugin routes.
 *
 * The publish endpoint needs its own route because it handles its own auth:
 * - Web UI: session-based permission check (level 8+)
 * - refresh-archives API: IP whitelist (no session required)
 *
 * Without this route, /admin/server_manager/publish would fall through to
 * the /admin/* wildcard which requires min_permission 5 — blocking the
 * refresh-archives API calls from remote nodes.
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
