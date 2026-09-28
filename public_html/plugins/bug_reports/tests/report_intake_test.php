<?php
/** @joinery-test
 * name: bug_reports_intake
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The receiving end of problem reports files what a Joinery site sends and
 * refuses what is not a report.
 *
 * Pins the intake order from plugins/bug_reports/docs/overview.md: a
 * well-formed report is stored with the callback's verdict (verified, version
 * mismatch, unverified), the fourth unverified report from one address in an
 * hour is refused instead of stored, a bad image is noted without refusing
 * the report, a bundle that is not a JSON object naming a site is refused,
 * and nothing in a hostile bundle reaches the admin page unescaped.
 *
 * The callback talks to a stand-in client; nothing leaves this box. Skips,
 * saying so, where the plugin is not active: an inactive plugin's classes do
 * not resolve.
 *
 * Writes rbr_received_bug_reports rows and removes them.
 * Run: php plugins/bug_reports/tests/report_intake_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('BugReportIntake')) {
	section('Bug Reports intake');
	check(true, 'skipped: the bug_reports plugin is not active on this site (nothing to test)');
	harness_finish();
	return;
}

if (session_id() === '') { @session_start(); }

/** A stand-in for the claimed site: answers HEAD with a version header, or fails. */
class BriStubClient extends SafeHttpClient {
	public $version = '0.8.435';
	public $fail = false;
	public $asked = array();
	public function head(string $url, array $headers = array()): SafeHttpResponse {
		$this->asked[] = $url;
		if ($this->fail) {
			throw new SafeHttpException('HTTP transport error (28): timed out');
		}
		return new SafeHttpResponse(200, $this->version === '' ? array() : array('x-joinery-version' => $this->version), '', $url);
	}
}

$client = new BriStubClient();
BugReportIntake::useClient($client);
harness_set_setting_mem('bug_reports_notify_email', '');

function bri_bundle(array $overrides = array()) {
	$bundle = array(
		'format' => 1,
		'site'   => array('host' => 'site.example.org', 'version' => '0.8.435'),
		'error'  => array('id' => 12, 'kind' => 'Exception', 'file' => 'logic/x_logic.php', 'line' => '40',
			'message' => 'Something broke', 'hash' => str_repeat('a', 32)),
	);
	return json_encode(array_replace_recursive($bundle, $overrides));
}

function bri_receive($bundle, $ip, $image = null) {
	$report = BugReportIntake::receive($bundle, 'It broke when I saved.', $image, $ip);
	harness_register_row('rbr_received_bug_reports', 'rbr_received_bug_report_id', $report->key);
	return $report;
}

/** Render a page in its own scope, so its variables do not overwrite this test's. */
function bri_render($path) {
	ob_start();
	try {
		include PathHelper::getIncludePath($path);
	} catch (\Throwable $e) {
		echo 'RENDER FAILED: ' . $e->getMessage();
	}
	return ob_get_clean();
}

function bri_refused(callable $fn, $status = null) {
	try {
		$fn();
		return false;
	} catch (BugReportRefusal $e) {
		return $status === null || $e->status === $status;
	}
}

$ip = '198.51.100.' . random_int(1, 250);

// ------------------------------------------------------------------ verdicts

section('The callback decides the verdict');

$r = bri_receive(bri_bundle(), $ip);
check($r->get('rbr_verdict') === ReceivedBugReport::VERDICT_VERIFIED, 'the claimed version answered: verified');
check(end($client->asked) === 'https://site.example.org/', 'the callback asked the claimed host, over HTTPS, at its front page');
check($r->get('rbr_status') === ReceivedBugReport::STATUS_NEW && $r->get('rbr_error_hash') === str_repeat('a', 32),
	'stored as new, with the error hash that groups it');
check($r->get('rbr_error_location') === 'logic/x_logic.php:40', 'the place is kept for the list');

$client->version = '0.8.400';
$r = bri_receive(bri_bundle(), $ip);
check($r->get('rbr_verdict') === ReceivedBugReport::VERDICT_MISMATCH
		&& strpos((string)$r->get('rbr_verdict_reason'), '0.8.400') !== false,
	'another version answered: version_mismatch, naming it');

$client->version = '';
$r = bri_receive(bri_bundle(), $ip);
check($r->get('rbr_verdict') === ReceivedBugReport::VERDICT_UNVERIFIED, 'no Joinery header: unverified');

$client->version = '0.8.435';
$client->fail = true;
$r = bri_receive(bri_bundle(), $ip);
check($r->get('rbr_verdict') === ReceivedBugReport::VERDICT_UNVERIFIED
		&& strpos((string)$r->get('rbr_verdict_reason'), 'timed out') !== false,
	'a timeout: unverified, saying why');

section('Unverified reports from one address are capped');

$r = bri_receive(bri_bundle(), $ip);
check($r->key > 0, 'the third unverified report from an address is stored');
check(bri_refused(function () use ($ip) { bri_receive(bri_bundle(), $ip); }, 429),
	'the fourth in the hour is refused with 429, not stored');
$client->fail = false;
$r = bri_receive(bri_bundle(), $ip);
check($r->get('rbr_verdict') === ReceivedBugReport::VERDICT_VERIFIED, 'a verified report from the same address is still taken');

// -------------------------------------------------------------------- shapes

section('What is not a report is refused');

check(bri_refused(function () use ($ip) { bri_receive('not json', $ip); }, 422), 'text that is not JSON: 422');
check(bri_refused(function () use ($ip) { bri_receive('[1,2,3]', $ip); }, 422), 'a JSON list: 422');
check(bri_refused(function () use ($ip) { bri_receive('{"site":{"version":"1.0"}}', $ip); }, 422), 'no host: 422');
check(bri_refused(function () use ($ip) { bri_receive(bri_bundle(array('site' => array('host' => '127.0.0.1'))), $ip); }, 422),
	'an address instead of a host name: 422');
check(bri_refused(function () use ($ip) { bri_receive(bri_bundle(array('site' => array('host' => 'evil.example.org/../x'))), $ip); }, 422),
	'a host carrying a path: 422');
check(bri_refused(function () use ($ip) { bri_receive(bri_bundle(array('site' => array('version' => '<b>1</b>'))), $ip); }, 422),
	'a version that is not a version: 422');
check(bri_refused(function () use ($ip) { BugReportIntake::receive(bri_bundle(), '   ', null, $ip); }, 422),
	'no description: 422');

section('A bad image is noted, not fatal');

$r = bri_receive(bri_bundle(), $ip, 'this is not an image');
check($r->key > 0 && !$r->get('rbr_fil_file_id') && strpos((string)$r->get('rbr_image_note'), 'not a PNG') !== false,
	'a non-image is dropped and the row says why');
$r = bri_receive(bri_bundle(), $ip, str_repeat('x', BugReportIntake::IMAGE_MAX_BYTES + 1));
check($r->key > 0 && strpos((string)$r->get('rbr_image_note'), 'larger than 5 MB') !== false,
	'an oversize image is dropped and the row says why');

// ----------------------------------------------------------- hostile content

section('A hostile bundle reaches the admin page escaped');

$hostile = bri_bundle(array('error' => array(
	'message' => '<script>alert(1)</script> for someone@example.com',
	'file'    => '../../../../etc/passwd',
	'kind'    => '<img src=x onerror=alert(2)>',
)));
$r = BugReportIntake::receive($hostile, '<script>alert(3)</script>', null, $ip);
harness_register_row('rbr_received_bug_reports', 'rbr_received_bug_report_id', $r->key);

$admin = make_user('bri_admin', 10);
$session = SessionControl::get_instance();
$session->set_api_user($admin->key);
$_GET = array('rbr_received_bug_report_id' => (string)$r->key);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/plugins/bug_reports/admin/admin_bug_report';
$html = bri_render('plugins/bug_reports/admin/admin_bug_report.php');
$_GET = array('view' => 'grouped');
$list_html = bri_render('plugins/bug_reports/admin/admin_bug_reports.php');
$session->clear_api_user();

check(strpos($html, 'RENDER FAILED') === false && strpos($html, 'What the site sent') !== false,
	'the report page renders', substr($html, 0, 200));
foreach (array('<script>alert(1)', '<script>alert(3)', '<img src=x onerror') as $raw) {
	check(strpos($html, $raw) === false && strpos($list_html, $raw) === false, 'not raw on either page: ' . $raw);
}
check(strpos($html, '&lt;script&gt;alert(3)') !== false, 'the comment is shown, escaped');
check(strpos($list_html, 'RENDER FAILED') === false, 'the grouped list renders');

section('Notice');

$session->set_api_user($admin->key);
check(strpos(ReceivedBugReport::admin_notice(), 'new problem report') !== false, 'an admin is told new reports are waiting');
$session->clear_api_user();
check(ReceivedBugReport::admin_notice() === '', 'no one below permission 9 is told');

BugReportIntake::useClient(null);
harness_finish();
