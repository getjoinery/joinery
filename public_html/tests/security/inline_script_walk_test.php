<?php
/** @joinery-test
 * name: inline_script_walk
 * tier: db
 * env: dev-only
 * needs: [dev-web]
 * timeout: 1200
 */
/**
 * The page walk (specs/strict_script_policy.md WP9): what a strict script
 * policy would refuse, page by page, and a ceiling per page that only goes down.
 *
 * Signs in as a member and as a superadmin and GETs every page that needs no
 * record id: each admin page (AccessDeclarations::pages(), as the superadmin),
 * each core and active-plugin profile view (as the member), and each core and
 * active-plugin public view (signed out). A page that does not answer 200 with
 * HTML is listed as skipped. Each page's HTML is parsed for what the browser
 * would refuse once script-src drops 'unsafe-inline':
 *   - an inline event handler (any attribute starting with "on");
 *   - a <script> with no src, nonce or not: the strict policy names no
 *     nonce (Q5), so no inline script runs (JSON and other data blocks
 *     excepted: the browser never runs them);
 *   - a javascript: URL in href, src, action or formaction.
 *
 * No browser is needed: no JS file outside vendor adds a handler or builds one
 * into HTML (the spec's "What is true today", held by csp_header's sweep), so
 * the HTML holds everything a browser would refuse.
 *
 * Counts are of distinct items, not occurrences: a table with a handler on
 * every row counts that handler once (digits and quoted strings are
 * collapsed), so a page's count does not move with how much data it shows.
 *
 * Each page's count is held to its ceiling in inline_script_walk_ceilings.json.
 * Over the ceiling fails, and a page with no ceiling has a ceiling of zero, so a
 * new page starts clean. A page under its ceiling is reported, and its ceiling
 * is lowered by running this file directly with --lower; a ceiling is never
 * raised by hand. Only --baseline takes every page's count as its ceiling; a
 * missing file fails. Pages that answer no 200 HTML page are held the same
 * way: the skipped list may shrink, never grow, so a page that starts to
 * redirect or fail cannot leave the walk unseen.
 *
 * Every response is also checked to name no nonce in any CSP header.
 *
 * Counts depend on what the site's data shows, so a new kind of row on a page
 * can raise a count with no code change. That reads as a failure to look at,
 * which is the point.
 *
 * Read-only: GETs only, and /logout is never asked.
 *
 * Run: php tests/run.php --only=tests/security/inline_script_walk_test.php
 * Lower the ceilings: php tests/security/inline_script_walk_test.php --lower
 * First ceilings only: php tests/security/inline_script_walk_test.php --baseline
 *
 * @version 1.2 - a nonced script counts (the strict policy names no nonce); every response is checked to name none; --baseline only records
 * @version 1.1 - a missing ceilings file fails (--baseline makes one); the skipped list is held; says when no nonce could be checked
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/http.php');
harness_boot();
harness_http_boot($argv);

$lower = in_array('--lower', (array)$argv, true);
$baseline = in_array('--baseline', (array)$argv, true);
$ceilings_file = __DIR__ . '/inline_script_walk_ceilings.json';
// Only --baseline takes every page's count as its ceiling. A missing file is
// a failure, never a fresh start: deleting it must not reset the ratchet.
$first_ceilings = $baseline;

/** A line of information in a direct run; the runner's summary has the checks. */
function isw_note(string $text): void {
	if (!harness_wants_json() && php_sapi_name() === 'cli') {
		echo "  NOTE: $text\n";
	}
}

/** Script types the browser runs. Anything else (JSON, templates) is data. */
function isw_runs_as_script(string $type): bool {
	$type = strtolower(trim(explode(';', $type)[0]));
	return in_array($type, array('', 'text/javascript', 'application/javascript', 'module',
		'text/ecmascript', 'application/ecmascript', 'application/x-javascript'), true);
}

/** One item's identity: digits and quoted strings collapsed, whitespace squeezed. */
function isw_shape(string $s): string {
	$s = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', 'S', $s);
	$s = preg_replace('/\d+/', '#', (string)$s);
	$s = preg_replace('/\s+/', ' ', (string)$s);
	return substr(trim((string)$s), 0, 240);
}

/** The CSP header lines of this response that name a nonce. */
function isw_nonce_headers(array $headers): array {
	$out = array();
	foreach ($headers as $line) {
		if (preg_match('/^Content-Security-Policy(-Report-Only)?:/i', $line) && stripos($line, "'nonce-") !== false) {
			$out[] = $line;
		}
	}
	return $out;
}

/**
 * What a strict policy would refuse on this page: distinct items, by kind.
 *
 * @return array{handlers: string[], scripts: string[], urls: string[]}
 */
function isw_refused(string $html): array {
	$out = array('handlers' => array(), 'scripts' => array(), 'urls' => array());
	$doc = new DOMDocument();
	$prev = libxml_use_internal_errors(true);
	$doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_COMPACT);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	foreach ($doc->getElementsByTagName('*') as $el) {
		$tag = strtolower($el->nodeName);
		foreach ($el->attributes ?? array() as $attr) {
			$name = strtolower($attr->nodeName);
			if (strlen($name) > 2 && strpos($name, 'on') === 0) {
				$out['handlers'][$tag . ' ' . $name . '=' . isw_shape($attr->nodeValue)] = true;
			}
			if (in_array($name, array('href', 'src', 'action', 'formaction'), true)
					&& preg_match('/^\s*javascript:/i', (string)$attr->nodeValue)) {
				$out['urls'][$tag . ' ' . $name . '=' . isw_shape($attr->nodeValue)] = true;
			}
		}
		if ($tag === 'script' && !$el->hasAttribute('src') && isw_runs_as_script($el->getAttribute('type'))) {
			$out['scripts'][isw_shape($el->textContent)] = true;
		}
	}
	return array_map('array_keys', $out);
}

// ── Who walks ───────────────────────────────────────────────────────────────
$suffix = strtoupper(LibraryFunctions::random_string(6));
$jars = array('signed out' => harness_jar_new('iswout'));
foreach (array('member' => 0, 'superadmin' => 10) as $who => $level) {
	$user = make_user($suffix . 'W' . $level, $level);
	$jar = harness_jar_new('isw' . $level);
	if (harness_web_login($jar, $user->get('usr_email'), 'TestPassword_' . $suffix . 'W' . $level) === null) {
		check(false, "the $who signs in");
		harness_finish();
		exit;
	}
	$jars[$who] = $jar;
}

// ── Which pages ─────────────────────────────────────────────────────────────
$root = rtrim(PathHelper::getIncludePath(''), '/');
$pages = array(); // url => who
foreach (AccessDeclarations::pages() as $url => $page) {
	if ($page['plugin'] === 'core' || PluginHelper::isPluginActive($page['plugin'])) {
		$pages[$url] = 'superadmin';
	}
}
foreach (glob("$root/views/profile/*.php") ?: array() as $f) {
	$pages['/profile/' . basename($f, '.php')] = 'member';
}
foreach (glob("$root/views/*.php") ?: array() as $f) {
	$name = basename($f, '.php');
	$pages[$name === 'index' ? '/' : '/' . $name] = 'signed out';
}
foreach (glob("$root/plugins/*/views", GLOB_ONLYDIR) ?: array() as $dir) {
	$plugin = basename(dirname($dir));
	if (!PluginHelper::isPluginActive($plugin)) {
		continue;
	}
	foreach (glob("$dir/profile/*.php") ?: array() as $f) {
		$pages["/profile/$plugin/" . basename($f, '.php')] = 'member';
	}
	foreach (glob("$dir/*.php") ?: array() as $f) {
		$name = basename($f, '.php');
		$pages[$name === 'index' ? "/$plugin" : "/$plugin/$name"] = 'signed out';
	}
}
foreach (array_keys($pages) as $url) {
	if (stripos($url, 'logout') !== false) {
		unset($pages[$url]);
	}
}
ksort($pages);

// ── The walk ────────────────────────────────────────────────────────────────
$ceilings_doc = is_file($ceilings_file) ? json_decode((string)file_get_contents($ceilings_file), true) : null;
if (!is_array($ceilings_doc) && !$baseline) {
	check(false, 'the ceilings file is there to hold the pages to', "$ceilings_file is missing or unreadable; only --baseline makes a new one");
	harness_finish();
	exit;
}
$ceilings = (array)($ceilings_doc['pages'] ?? array());
$known_skipped = array_flip((array)($ceilings_doc['skipped'] ?? array()));
$nonce_pages = array();
$counts = array();
$skipped = array();
$by_who = array();
foreach ($pages as $url => $who) {
	$by_who[$who][] = $url;
}
foreach ($by_who as $who => $urls) {
	section("Pages as the $who");
	$over = array();
	$under = array();
	$clean = 0;
	foreach ($urls as $url) {
		$r = harness_request('GET', $url, array('jar' => $jars[$who], 'accept' => 'text/html', 'follow' => false, 'timeout' => 60));
		if ($r['status'] !== 200 || stripos((string)$r['content_type'], 'text/html') === false) {
			$skipped[$url] = $r['status'];
			continue;
		}
		if (isw_nonce_headers((array)$r['headers']) !== array()) {
			$nonce_pages[] = $url;
		}
		$refused = isw_refused((string)$r['body']);
		$n = count($refused['handlers']) + count($refused['scripts']) + count($refused['urls']);
		$counts[$url] = $n;
		$ceiling = (int)($ceilings[$url] ?? 0);
		if ($n === 0) {
			$clean++;
		}
		if ($n > $ceiling && !$baseline) {
			$over[] = "$url: $n, ceiling $ceiling\n      " . implode("\n      ", array_merge(
				array_map(function ($h) { return 'handler: ' . $h; }, $refused['handlers']),
				array_map(function ($s) { return 'script: ' . substr($s, 0, 100); }, $refused['scripts']),
				array_map(function ($u) { return 'url: ' . $u; }, $refused['urls'])));
		} elseif ($n < $ceiling) {
			$under[] = "$url: $n, ceiling $ceiling";
		}
	}
	$seen = count(array_diff($urls, array_keys($skipped)));
	check($over === array(), "$seen pages walked, $clean already clean, none above its ceiling", implode("\n", $over));
	if ($under !== array() && !$lower) {
		isw_note(count($under) . " pages are under their ceiling; lower them with --lower:\n  " . implode("\n  ", $under));
	}
}

section('Pages the walk could not read');
// A page that starts answering a redirect or an error would otherwise leave
// the walk unseen and keep its ceiling. The skipped list is held like the
// ceilings: it may shrink, never grow.
$newly_skipped = array();
foreach ($skipped as $url => $status) {
	if (!isset($known_skipped[$url]) && !$baseline) {
		$newly_skipped[] = "$url ($status)";
	}
}
check($newly_skipped === array(), count($skipped) . ' pages skipped (they need a record id or redirect), none newly',
	"these pages answered no 200 HTML page and were walked before:\n" . implode("\n", $newly_skipped));
if ($skipped) {
	isw_note(implode(', ', array_map(function ($u) use ($skipped) { return "$u ({$skipped[$u]})"; }, array_keys($skipped))));
}

section('No response names a nonce');
// The strict policy runs no inline script at all (specs/strict_script_policy.md
// Q5). A nonce in a header would let a block back in, so none may appear.
check($nonce_pages === array(), 'no walked page sends a CSP header that names a nonce', implode(', ', $nonce_pages));
isw_note('total refused items across walked pages: ' . array_sum($counts) . ' on ' . count(array_filter($counts)) . ' pages');

if ($lower || $baseline) {
	$next = $baseline ? array() : $ceilings;
	foreach ($counts as $url => $n) {
		if (isset($next[$url]) ? $n < (int)$next[$url] : $first_ceilings) {
			$next[$url] = $n;
		}
	}
	// A page held at zero needs no line: no line means zero.
	$next = array_filter($next, function ($n) { return (int)$n > 0; });
	ksort($next);
	// The skipped list only shrinks: a page now read leaves it.
	$next_skipped = $baseline ? array_keys($skipped) : array_values(array_intersect(array_keys($known_skipped), array_keys($skipped)));
	sort($next_skipped);
	file_put_contents($ceilings_file, json_encode(array(
		'about' => 'Ceilings for tests/security/inline_script_walk_test.php: per page, how many distinct inline handlers, inline scripts and javascript: URLs it may still send, and the pages it cannot read. Both only ever shrink (run the test with --lower). A page not listed must send none.',
		'pages' => $next,
		'skipped' => $next_skipped,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	echo "Wrote " . count($next) . " ceilings to $ceilings_file\n";
}

harness_finish();
