<?php
/**
 * Drives an event_manager ICS route handler in a subprocess and prints whatever
 * it emits.
 *
 * The handlers finish through IcsHelper::outputIcs() or display_404_page(), both
 * of which call exit(), so they cannot be invoked inside a test process that
 * still has assertions to run. A subprocess makes the real handler — route
 * params, resolution, emitted calendar and all — observable as plain output.
 *
 * Usage: php ics_route_runner.php <event|calendar> [slug] [date]
 */

if (php_sapi_name() !== 'cli') { exit(1); }

require_once(dirname(__DIR__, 4) . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

// The 404 view renders a full themed page, which reads request context that a
// CLI process does not have. Supply enough to keep it quiet.
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$which = $argv[1] ?? '';
$params = array();
if (isset($argv[2]) && $argv[2] !== '') { $params['slug'] = $argv[2]; }
if (isset($argv[3]) && $argv[3] !== '') { $params['date'] = $argv[3]; }

$file = ($which === 'calendar')
	? 'plugins/event_manager/includes/ics_calendar_route.php'
	: 'plugins/event_manager/includes/ics_event_route.php';

require(PathHelper::getIncludePath($file));
