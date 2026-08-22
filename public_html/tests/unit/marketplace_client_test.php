<?php
/** @joinery-test
 * name: marketplace_client
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Marketplace client mechanics that fail silently when they regress.
 *
 * Pins the catalog/local-status merge MarketplaceClient does for the
 * /admin/admin_marketplace page and the marketplace_catalog API action, the
 * input refusals on install(), and the FormWriter CSRF contract the install
 * buttons depend on: a POST handler constructs the form it is validating,
 * which replaces the session token — validateCSRF() must still honor the
 * token the submitted page carries, and must refuse its replay.
 *
 * Run: php tests/unit/marketplace_client_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (session_id() === '') { @session_start(); }

function mkt_threw(callable $fn, $class = 'Throwable') {
	try { $fn(); return false; } catch (Throwable $e) { return $e instanceof $class; }
}

// ------------------------------------------------- catalog/local-status merge

section('enrich_with_local_status');

$remote = array(
	array('name' => 'Alpha', 'directory_name' => 'alpha'),
	array('name' => 'beta'),                                  // no directory_name — falls back to name
	array('name' => 'Gamma', 'directory_name' => 'gamma'),
);
$enriched = MarketplaceClient::enrich_with_local_status($remote, array('alpha', 'beta'), 'plugin');

check(count($enriched) === 3, 'Every catalog item survives the merge');
check($enriched[0]['install_status'] === 'installed', 'Present directory_name marks installed');
check($enriched[1]['install_status'] === 'installed', 'Fallback to name marks installed', 'beta has no directory_name');
check($enriched[2]['install_status'] === 'not_installed', 'Absent directory marks not_installed');
check($enriched[0]['type'] === 'plugin', 'Type is stamped on each item');

// ------------------------------------------------------------ install refusals

section('install() refusals');

check(mkt_threw(function () { MarketplaceClient::install('module', 'x'); }, 'InvalidArgumentException'),
	'A type that is not theme/plugin is refused');
check(mkt_threw(function () { MarketplaceClient::install('plugin', ''); }, 'InvalidArgumentException'),
	'An empty name is refused');
check(mkt_threw(function () { MarketplaceClient::install('plugin', '..'); }, 'InvalidArgumentException'),
	'A traversal name is refused');
check(mkt_threw(function () { MarketplaceClient::fetch_catalog('theme'); }, 'InvalidArgumentException'),
	"fetch_catalog takes the list nouns 'themes'/'plugins', not the singular");

// ------------------------------------------------------- audience visibility

section('audience visibility');

// An extension that names no audience is everybody's — the case almost every
// extension is in, and the reason the manifest key is optional.
check(MarketplaceClient::audience_allows(null, 'zoukphilly.com'), 'No audience is public');
check(MarketplaceClient::audience_allows(array(), 'zoukphilly.com'), 'An empty audience is public');
check(MarketplaceClient::audience_allows(null, ''), 'No audience is public even to an unidentified caller');

// An audience names the sites the extension was built for. Reserved .example
// domains throughout: a real domain here could collide with whatever this
// site names as its root node, and the root sees everything — which would
// make these checks pass for a reason they are not testing.
$audience = array('zoukphilly.example', 'second.example');
check(MarketplaceClient::audience_allows($audience, 'zoukphilly.example'), 'A named site sees it');
check(MarketplaceClient::audience_allows($audience, 'second.example'), 'A second named site sees it');
check(!MarketplaceClient::audience_allows($audience, 'zoukroom.example'), 'An unnamed site does not');
check(!MarketplaceClient::audience_allows($audience, ''), 'A caller claiming nothing does not');

// Hosts are compared in one normalized form, so an operator can write the
// domain the way it appears in a browser and still have it match.
check(MarketplaceClient::audience_allows($audience, 'https://www.ZoukPhilly.example/'),
	'Scheme, www, case and trailing path do not break the match');
check(MarketplaceClient::audience_allows(array('https://ZoukPhilly.example'), 'zoukphilly.example'),
	'An audience entry written as a URL still matches');
check(MarketplaceClient::normalize_host('http://Example.com:8080/path') === 'example.com',
	'normalize_host strips scheme, port and path');

// A malformed audience hides the extension rather than publishing it — a
// manifest typo must not be the thing that leaks a private theme.
check(!MarketplaceClient::audience_allows('zoukphilly.example', 'zoukphilly.example'),
	'A bare string audience hides the extension instead of matching');
check(!MarketplaceClient::audience_allows(array(array('site' => 'zoukphilly.example')), 'zoukphilly.example'),
	'A structured audience entry does not match');

// A subdomain is a different site, not a member of the parent's audience.
check(!MarketplaceClient::audience_allows(array('parent.example'), 'sub.parent.example'),
	'A subdomain is not covered by the parent domain');


// ------------------------------------------------------------- the root node

section('the root node');

// One deployment is the origin of the estate: the code is written there and
// the extensions are published from there. It holds every extension already,
// so it sees the whole catalog — and no manifest has to spend a line naming
// the box the work is done on.
$root = MarketplaceClient::root_node();

check($root === MarketplaceClient::normalize_host($root),
	'root_node is stored in the normalized form audience entries compare in');

if ($root !== '') {
	check(MarketplaceClient::audience_allows(array('nobody.example'), $root),
		'The root node sees an extension whose audience does not name it');
	check(MarketplaceClient::audience_allows(array('nobody.example'), 'HTTPS://WWW.' . strtoupper($root) . '/'),
		'and however the root writes its own domain');
	check(MarketplaceClient::is_root() === (MarketplaceClient::site_identity() === $root),
		'is_root() is this site measured against that name, nothing else');
}

if (MarketplaceClient::is_root()) {
	// The catalog here is this site's own disk, so everything in it is already
	// installed; an install could only overwrite the working tree with a
	// cached archive of itself.
	check(mkt_threw(function () { MarketplaceClient::install('theme', 'default'); }, 'Exception'),
		'The origin refuses an install that would overwrite its own working copy');
	check(MarketplaceClient::source() === 'https://' . $root || MarketplaceClient::source() === 'http://' . $root,
		'and it sources the catalog from itself rather than from upgrade_source',
		'source() is ' . var_export(MarketplaceClient::source(), true));
} else {
	// No origin named — the ordinary case for a site that consumes releases.
	// The rule must then be inert rather than opening the catalog to anyone.
	check(!MarketplaceClient::audience_allows(array('nobody.example'), 'anyone.example'),
		'With no root node named, an audience still hides the extension');
	check(!MarketplaceClient::is_root(), 'and no site reads as the origin');
}

// Naming the origin by domain rather than by a flag is what makes a clone or
// a restored backup safe: it carries the same value, still naming the origin,
// and correctly concludes it is not the origin itself.
check(!MarketplaceClient::audience_allows(array('nobody.example'), 'a-clone-of-the-root.example'),
	'A site that is not the named origin gets no such pass');

// ------------------------------------------- FormWriter handler-side validateCSRF

section('validateCSRF from a POST handler');

// Render request: the page's form mints the token the browser will submit.
$render_form = new FormWriterV2HTML5('mkt_csrf_test');
$submitted_token = $render_form->getCSRFToken();
check(is_string($submitted_token) && strlen($submitted_token) === 64, 'Construction mints a token');

// POST request: the handler constructs the same form to validate. That
// replaces the session entry with a fresh token — the submitted one must
// still validate.
$handler_form = new FormWriterV2HTML5('mkt_csrf_test');
check($handler_form->validateCSRF(array('_csrf_token' => $submitted_token)) === true,
	'The submitted token validates in the handler that constructed the form');
check($handler_form->validateCSRF(array('_csrf_token' => $submitted_token)) === false,
	'Replaying the same token to the same handler is refused');

// A later request replaying the token: its handler instance holds the
// current session entry (a fresh token), not the replayed one.
$later_handler = new FormWriterV2HTML5('mkt_csrf_test');
check($later_handler->validateCSRF(array('_csrf_token' => $submitted_token)) === false,
	'Replaying the token in a later request is refused');

// The current-session-entry path: token minted and validated by the same
// instance in one request (a form whose render and submit share a request).
$same_request = new FormWriterV2HTML5('mkt_csrf_test_2');
check($same_request->validateCSRF(array('_csrf_token' => $same_request->getCSRFToken())) === true,
	'A token validates against the current session entry');

check($same_request->validateCSRF(array('_csrf_token' => '')) === false, 'An empty token is refused');
check($same_request->validateCSRF(array()) === false, 'A missing token is refused');

harness_finish();
?>
