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

// An audience names the sites the extension was built for.
$audience = array('zoukphilly.com', 'dev.getjoinery.com');
check(MarketplaceClient::audience_allows($audience, 'zoukphilly.com'), 'A named site sees it');
check(MarketplaceClient::audience_allows($audience, 'dev.getjoinery.com'), 'A second named site sees it');
check(!MarketplaceClient::audience_allows($audience, 'zoukroom.com'), 'An unnamed site does not');
check(!MarketplaceClient::audience_allows($audience, ''), 'A caller claiming nothing does not');

// Hosts are compared in one normalized form, so an operator can write the
// domain the way it appears in a browser and still have it match.
check(MarketplaceClient::audience_allows($audience, 'https://www.ZoukPhilly.com/'),
	'Scheme, www, case and trailing path do not break the match');
check(MarketplaceClient::audience_allows(array('https://ZoukPhilly.com'), 'zoukphilly.com'),
	'An audience entry written as a URL still matches');
check(MarketplaceClient::normalize_host('http://Example.com:8080/path') === 'example.com',
	'normalize_host strips scheme, port and path');

// A malformed audience hides the extension rather than publishing it — a
// manifest typo must not be the thing that leaks a private theme.
check(!MarketplaceClient::audience_allows('zoukphilly.com', 'zoukphilly.com'),
	'A bare string audience hides the extension instead of matching');
check(!MarketplaceClient::audience_allows(array(array('site' => 'zoukphilly.com')), 'zoukphilly.com'),
	'A structured audience entry does not match');

// A subdomain is a different site, not a member of the parent's audience.
check(!MarketplaceClient::audience_allows(array('getjoinery.com'), 'dev.getjoinery.com'),
	'A subdomain is not covered by the parent domain');

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
