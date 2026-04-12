<?php
/**
 * Legacy alias for /utils/publish_theme — keeps older install.sh/upgrade.php clients working.
 *
 * The canonical location is /admin/server_manager/publish_theme. This file exists so that
 * release tarballs with older install.sh (which calls /utils/publish_theme?list=themes and
 * ?download=<name>) continue to resolve.
 *
 * Core files (PathHelper, Globalvars, SessionControl) are pre-loaded by serve.php.
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/publish_theme.php'));
