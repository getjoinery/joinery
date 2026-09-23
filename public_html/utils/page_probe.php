<?php
/**
 * page_probe.php - render one of this node's own pages as a throwaway viewer
 * and print facts about the render, never the render, as ONE JSON object.
 *
 * The page_probe observe word of specs/agent_recipes_and_vocabulary.md
 * ("page_probe {page, viewer}"). The agent runs this file, verified against
 * the signed release manifest, as `php page_probe.php PAGE VIEWER`, with two
 * argv elements it has already validated against its own patterns.
 *
 * THE CONTRACT, which tests/unit/page_probe_test.php pins:
 *
 *   - PAGE must be on this node's own list (PageProbe::node_pages(): its admin
 *     menu entries and public views, no query string) and not on
 *     PageProbe::REFUSED_PAGES. VIEWER is anonymous, member or admin.
 *   - Inside this one run: make the throwaway user (member: permission 0;
 *     admin: permission 10), mint a one-minute grant, send ONE request over
 *     this machine's own connection, read the report the request left, then
 *     delete the grant and PERMANENTLY delete the user and every row that
 *     hangs off it. A probe that cannot finish its cleanup says so.
 *   - RETURNS: HTTP status, bytes, render time, statements run, peak memory,
 *     PHP warnings and errors as type and file:line, theme and plugin static
 *     assets the page references that fail to load, a landmark check
 *     (header, main, footer; count of forms), and a structure hash (the tag
 *     sequence with all text and attribute values removed). The HTML is read
 *     with patterns, never a C parser: it carries what members wrote.
 *   - NEVER RETURNS: page text, HTML, form values, a warning's message, the
 *     address of an uploaded file, or a failing asset path the release does
 *     not ship (those are counted, never quoted). The body is held to 4 MiB
 *     and counted past it (`truncated`).
 *   - Exit 0 whenever the object was printed; exit 2 for a refused argument.
 *
 * @version 1.1 - review 2026-09-23: failed assets named only when the file is in the release tree
 *               (B4); the body is held to 4 MiB and counted past it (B21)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');

/** The most of a page this probe holds in memory; the rest is counted. */
const PROBE_MAX_BODY = 4194304;

$page   = (string)($argv[1] ?? '');
$viewer = (string)($argv[2] ?? '');

function probe_refuse($why) {
	fwrite(STDERR, 'page_probe: ' . $why . "\n");
	exit(2);
}

if (!preg_match(PageProbe::PAGE_PATTERN, $page)) {
	probe_refuse('the page is not a path this probe accepts');
}
if (!in_array($viewer, PageProbe::VIEWERS, true)) {
	probe_refuse('the viewer is not one of ' . implode(', ', PageProbe::VIEWERS));
}
if (PageProbe::refused($page)) {
	probe_refuse($page . ' acts on arrival; a probe never renders it');
}
if (!in_array($page, PageProbe::node_pages(), true)) {
	probe_refuse($page . ' is not one of this node\'s own pages');
}

$site_root = dirname(PathHelper::getRootDir());
$site = basename($site_root);

// Where to send the request: the site vhost's own name, at the address its
// vhost is bound to (install.sh binds the server's IP; a proxy vhost binds *).
function probe_target($site) {
	$vhost = '/etc/apache2/sites-available/' . $site . '.conf';
	$conf = is_readable($vhost) ? (string)file_get_contents($vhost, false, null, 0, 65536) : '';
	$name = preg_match('/^\s*ServerName\s+([A-Za-z0-9.-]{1,253})\s*$/mi', $conf, $m) ? $m[1] : '';
	$addr = preg_match('/<VirtualHost\s+([0-9.]{7,15}):/i', $conf, $m) ? $m[1] : '127.0.0.1';
	return array($name, $addr);
}
list($server_name, $server_addr) = probe_target($site);
if ($server_name === '') {
	probe_refuse('this node has no site vhost to address the request to');
}

// A leftover from a probe that died mid-way goes first.
$swept = PageProbe::sweep();

$result = array(
	'page' => $page, 'viewer' => $viewer, 'status' => 0, 'bytes' => 0, 'render_ms' => null,
	'statements' => null, 'peak_memory' => null, 'warnings' => array(), 'failed_assets' => array(),
	'landmarks' => array('header' => false, 'main' => false, 'footer' => false, 'forms' => 0),
	'structure_hash' => '', 'reported' => false, 'cleanup' => 'done', 'swept' => $swept,
	'failed_assets_unnamed' => 0, 'truncated' => false,
);

$user = null;
$grant = null;
try {
	if ($viewer !== 'anonymous') {
		$suffix = bin2hex(random_bytes(6));
		$user = new User(NULL);
		$user->set('usr_first_name', 'Page');
		$user->set('usr_last_name', 'Probe');
		$user->set('usr_email', 'probe-' . $suffix . '@probe.invalid');
		$user->set('usr_password', User::GeneratePassword(bin2hex(random_bytes(24))));
		$user->set('usr_permission', $viewer === 'admin' ? 10 : 0);
		$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		$user->set('usr_is_activated', true);
		$user->set('usr_setup_dismissed_time', gmdate('Y-m-d H:i:s'));
		$user->save();
		$user->load();
	}
	$token = bin2hex(random_bytes(32));
	$grant = new PageProbeGrant(NULL);
	$grant->set('ppg_token_hash', hash('sha256', $token));
	$grant->set('ppg_usr_user_id', $user ? $user->key : null);
	$grant->set('ppg_viewer', $viewer);
	$grant->set('ppg_page', $page);
	$grant->set('ppg_expires_time', gmdate('Y-m-d H:i:s', time() + PageProbe::GRANT_SECONDS));
	$grant->save();
	$grant->load();

	// The body is read up to PROBE_MAX_BODY and counted past it: a large admin
	// page must not exhaust this process before the JSON is printed (review B21).
	$fetch = function ($path, $head, $with_token) use ($server_name, $server_addr, $token) {
		foreach (array('https' => 443, 'http' => 80) as $scheme => $port) {
			$kept = '';
			$total = 0;
			$ch = curl_init($scheme . '://' . $server_name . $path);
			$opts = array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_RESOLVE        => array($server_name . ':' . $port . ':' . $server_addr),
				CURLOPT_USERAGENT      => PageProbe::USER_AGENT,
				CURLOPT_NOBODY         => $head,
				CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$kept, &$total) {
					$total += strlen($chunk);
					if (strlen($kept) < PROBE_MAX_BODY) {
						$kept .= substr($chunk, 0, PROBE_MAX_BODY - strlen($kept));
					}
					return strlen($chunk);
				},
			);
			if ($with_token) {
				$opts[CURLOPT_HTTPHEADER] = array('X-Joinery-Probe: ' . $token);
			}
			curl_setopt_array($ch, $opts);
			curl_exec($ch);
			$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			if ($status > 0) {
				return array($status, $kept, $total);
			}
		}
		return array(0, '', 0);
	};

	list($status, $body, $total_bytes) = $fetch($page, false, true);
	$result['status'] = $status;
	$result['bytes'] = $total_bytes;
	$result['truncated'] = $total_bytes > strlen($body);

	// Facts about the HTML, read here and discarded: no text leaves. Read
	// with patterns, never a C parser: a rendered page carries what members
	// wrote, and this runs as root (specs/parser_jail.md).
	if ($body !== '') {
		// Comments, and the bodies of scripts and styles, are not structure.
		$markup = preg_replace(array('#<!--.*?-->#s', '#(<script\b[^>]*>).*?(</script>)#is', '#(<style\b[^>]*>).*?(</style>)#is'),
			array('', '$1$2', '$1$2'), $body);
		preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]{0,30})\b([^>]*)>#', (string)$markup, $tags, PREG_SET_ORDER);
		$shape = '';
		$opened = array();
		foreach ($tags as $t) {
			$name = strtolower($t[2]);
			$shape .= ($t[1] === '/' ? '/' : '') . $name . ';';
			if ($t[1] === '') { $opened[$name] = ($opened[$name] ?? 0) + 1; }
		}
		foreach (array('header', 'main', 'footer') as $tag) {
			$result['landmarks'][$tag] = !empty($opened[$tag]);
		}
		$result['landmarks']['forms'] = (int)($opened['form'] ?? 0);
		// The tag sequence, names only: the same page renders to the same hash
		// while its structure is unchanged.
		$result['structure_hash'] = hash('sha256', $shape);
		// Theme and plugin static assets: code the release ships, never an upload.
		$assets = array();
		foreach ($tags as $t) {
			$name = strtolower($t[2]);
			if ($t[1] !== '' || !in_array($name, array('script', 'link', 'img'), true)) { continue; }
			if (!preg_match('#\b(?:src|href)\s*=\s*["\']([^"\']{1,300})["\']#i', $t[3], $am)) { continue; }
			$p = parse_url($am[1], PHP_URL_PATH);
			$h = parse_url($am[1], PHP_URL_HOST);
			if (!is_string($p) || ($h !== null && $h !== $server_name)) { continue; }
			if (preg_match('#^/(theme|plugins)/[A-Za-z0-9_./\-]{1,200}$#', $p) && strpos($p, '..') === false) {
				$assets[$p] = true;
			}
		}
		// A failing asset is NAMED only when the release ships that file: a path
		// that is not in this tree may have come from what a member wrote into
		// the page, so it is counted and never quoted (review B4).
		$web_root = PathHelper::getRootDir();
		foreach (array_slice(array_keys($assets), 0, 40) as $p) {
			list($st) = $fetch($p, true, false);
			if ($st >= 200 && $st < 400) { continue; }
			$real = realpath($web_root . $p);
			if ($real !== false && strpos($real, $web_root . '/') === 0 && is_file($real)) {
				$result['failed_assets'][] = array('path' => $p, 'status' => $st);
			} else {
				$result['failed_assets_unnamed']++;
			}
		}
	}
	unset($body);

	$grant->load();
	$report = $grant->report();
	if (is_array($report)) {
		$result['reported'] = true;
		$result['render_ms'] = isset($report['render_ms']) ? (int)$report['render_ms'] : null;
		$result['statements'] = isset($report['statements']) ? (int)$report['statements'] : null;
		$result['peak_memory'] = isset($report['peak_memory']) ? (int)$report['peak_memory'] : null;
		foreach (array_slice((array)($report['warnings'] ?? array()), 0, PageProbe::MAX_WARNINGS) as $w) {
			if (is_array($w)) {
				$result['warnings'][] = array('type' => (string)($w['type'] ?? 'other'), 'at' => (string)($w['at'] ?? ''));
			}
		}
	}
} catch (Throwable $e) {
	$result['error'] = 'the probe could not run: ' . get_class($e);
}

// Cleanup, always: the grant, and the throwaway user with every row off it.
if ($grant && $grant->key) {
	if (!PageProbe::discard($grant)) {
		$result['cleanup'] = 'incomplete: the grant or the probe user could not be deleted; the next probe sweeps it';
	}
} elseif ($user && $user->key) {
	try { $user->permanent_delete(); } catch (Throwable $e) { $result['cleanup'] = 'incomplete: the probe user could not be deleted'; }
}

echo json_encode($result) . "\n";
exit(0);
