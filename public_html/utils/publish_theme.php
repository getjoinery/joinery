<?php
/**
 * Legacy alias for /utils/publish_theme — keeps older install.sh/upgrade.php clients working.
 *
 * The canonical location is /admin/server_manager/publish_theme. This file exists so that
 * release tarballs with older install.sh (which calls /utils/publish_theme?list=themes and
 * ?download=<name>) continue to resolve.
 *
 * Core files (PathHelper, Globalvars, SessionControl) are pre-loaded by serve.php.
 *
 * This alias ships in core to every site, but only a publishing site (one with
 * the server_manager plugin) can answer — anywhere else it is a 404, not a fatal.
 */

$publish_theme = PathHelper::getIncludePath('plugins/server_manager/includes/publish_theme.php');
if (!is_file($publish_theme)) {
	http_response_code(404);
	header('Content-Type: application/json');
	echo json_encode(array('success' => false, 'error' => 'This site does not publish extensions.'));
	exit;
}

require_once($publish_theme);
