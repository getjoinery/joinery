<?php
/** @joinery-test
 * name: management_api_surface
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The management API stays status-only (specs/implemented/agent_on_node_architecture.md
 * §3.5 rule 4).
 *
 * A management node can call every endpoint under includes/management_api/.
 * It is ordinary PHP, so it could quietly grow an endpoint that returns
 * member content, and nothing else would notice. This test pins the list of
 * endpoints: a new handler file fails it until it is added below, which is
 * the moment someone has to say what the new endpoint returns and why that is
 * status, not content.
 *
 * Each entry says what the endpoint returns. Adding one means writing that
 * sentence honestly; if it cannot be written without "content", the endpoint
 * does not belong on this API.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/management_api_surface_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$reviewed = array(
	'backups/list_handler.php'  => 'names, sizes and times of the node\'s backup archives',
	'databases_handler.php'     => 'database names and sizes',
	'errors/recent_handler.php' => 'recent error-log lines, masked with LogRedactor::text()',
	'health_handler.php'        => 'service and resource health readings',
	'stats_handler.php'         => 'disk, memory, load and counts',
	'version_handler.php'       => 'platform, schema and component versions',
);

$root = PathHelper::getIncludePath('includes/management_api');
$found = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	if ($file->isFile() && substr($file->getFilename(), -12) === '_handler.php') {
		$found[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
	}
}
sort($found);

section('Every management API endpoint has been reviewed');

$new = array_values(array_diff($found, array_keys($reviewed)));
check($new === array(), 'No endpoint is on the management API without a stated, status-only purpose',
	$new ? 'unreviewed: ' . implode(', ', $new) . ' — add it to $reviewed with what it returns' : '');

$gone = array_values(array_diff(array_keys($reviewed), $found));
check($gone === array(), 'Every reviewed endpoint still exists (remove an entry when its handler goes)',
	$gone ? 'missing: ' . implode(', ', $gone) : '');

section('Error lines leave masked');

$src = file_get_contents($root . '/errors/recent_handler.php');
check(strpos($src, 'LogRedactor::text(') !== false,
	'errors/recent_handler.php masks every line with LogRedactor::text()');

harness_finish();
