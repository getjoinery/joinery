<?php
/** @joinery-test
 * name: admin_page_levels
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 900
 */
/**
 * Every admin page opens at its level and is refused below it (spec
 * declared_permissions WP5; docs/permissions.md § Pages), for core and each
 * active plugin.
 *
 * A page's level is AdminPageAccess::levelForFile(): its header. For each page a user at that level must not be
 * refused (any answer but a 401 or the sign-in page: a page asked with no
 * row id may redirect or say so), and a user at the next level down must be
 * refused with a 401. A 500 is a failure either way. Pages are asked with a
 * GET, which writes nothing.
 *
 * Run: php tests/run.php --filter=admin_page_levels
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/http.php');
harness_boot();
harness_http_boot($argv);

$suffix = strtoupper(LibraryFunctions::random_string(6));
$levels = array_keys(Access::levels());

/** The named level just below $level (0 for the lowest). */
function apl_below(array $levels, int $level): int {
	$below = 0;
	foreach ($levels as $l) {
		if ($l < $level && $l > $below) $below = $l;
	}
	return $below;
}

$jars = array();
foreach ($levels as $level) {
	$user = make_user($suffix . 'L' . $level, $level);
	$jar = harness_jar_new('apl' . $level);
	if (harness_web_login($jar, $user->get('usr_email'), 'TestPassword_' . $suffix . 'L' . $level) === null) {
		check(false, "a level $level user signs in");
		continue;
	}
	$jars[$level] = $jar;
}

$by_area = array();
foreach (AccessDeclarations::pages() as $url => $page) {
	if ($page['plugin'] !== 'core' && !PluginHelper::isPluginActive($page['plugin'])) continue;
	$by_area[$page['plugin']][$url] = $page;
}
ksort($by_area);

foreach ($by_area as $area => $pages) {
	section($area);
	$opens = 0; $refused = 0; $failures = array();
	foreach ($pages as $url => $page) {
		$level = AdminPageAccess::levelForFile($page['file']);
		$below = apl_below($levels, $level);
		if (isset($jars[$level])) {
			$r = harness_request('GET', $url, array('jar' => $jars[$level], 'follow' => false, 'timeout' => 60));
			$to_login = $r['status'] === 302 && harness_header_matches($r['headers'], '#^Location:.*/login#i');
			if ($r['status'] === 401 || $to_login) {
				$failures[] = "$url: a level $level user is refused (status {$r['status']})";
			} elseif ($r['status'] >= 500) {
				$failures[] = "$url: answers {$r['status']} at level $level";
			} else {
				$opens++;
			}
		}
		if ($level > 0 && isset($jars[$below])) {
			$r = harness_request('GET', $url, array('jar' => $jars[$below], 'follow' => false, 'timeout' => 60));
			if ($r['status'] === 401) {
				$refused++;
			} else {
				$failures[] = "$url: a level $below user is not refused (status {$r['status']}; the page needs $level)";
			}
		}
	}
	check($failures === array(), count($pages) . " pages: $opens open at their level, $refused refuse the level below",
		implode("\n", $failures));
}

harness_finish();
