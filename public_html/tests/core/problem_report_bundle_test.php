<?php
/** @joinery-test
 * name: problem_report_bundle
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A problem report carries what it promises and nothing private.
 *
 * The bundle is what leaves the site, so this pins its privacy rules against
 * real rows: every section is present for an operator, a member's report
 * carries none of the site-wide sections, a member cannot attach another
 * member's error, and no email address, IP address, stack argument or
 * setting value appears anywhere in the JSON. Then the local record and the
 * sender: a report saves, a 2xx marks it sent with the remote id, a refusal
 * marks it failed with the reason, retries stop at the limit, the operator's
 * switch keeps reports local, and the retention rule removes old ones.
 *
 * The sender talks to a stand-in HTTP client; nothing leaves this box.
 *
 * Writes users, err_general_errors rows and prr_problem_reports rows, and
 * removes them.
 * Run: php tests/core/problem_report_bundle_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (session_id() === '') { @session_start(); }

/** A stand-in for the upgrade source: answers with a fixed status and body. */
class PrbStubClient extends SafeHttpClient {
	public $status = 200;
	public $body = '{"api_version":"1.0","data":{"report_id":777}}';
	public $calls = array();
	public function post(string $url, string $body, array $headers = array()): SafeHttpResponse {
		$this->calls[] = array('url' => $url, 'body' => $body, 'headers' => $headers);
		return new SafeHttpResponse($this->status, array(), $this->body, $url);
	}
}

function prb_error_row(int $user_id, string $message): GeneralError {
	$session = SessionControl::get_instance();
	$session->set_api_user($user_id);
	$id = ErrorReference::log(prb_throw($message, 'jane.doe@example.com', '203.0.113.9'));
	$session->clear_api_user();
	harness_register_row('err_general_errors', 'err_general_error_id', $id);
	return new GeneralError($id, TRUE);
}

/** Throw from a frame whose arguments carry personal data, so the trace has some to drop. */
function prb_throw($message, $email_arg, $ip_arg) {
	return prb_inner($message, $email_arg, $ip_arg);
}
function prb_inner($message, $email_arg, $ip_arg) {
	return new RuntimeException($message);
}

/** Render a page in its own scope, so its variables do not overwrite this test's. */
function prb_render($path) {
	ob_start();
	try {
		include PathHelper::getIncludePath($path);
	} catch (\Throwable $e) {
		echo 'RENDER FAILED: ' . $e->getMessage();
	}
	return ob_get_clean();
}

$member = make_user('prb_member');
$other = make_user('prb_other');
$operator = make_user('prb_operator', 10);

$own_error = prb_error_row((int)$member->key, 'Could not save row 17 for jane.doe@example.com from 203.0.113.9');
$others_error = prb_error_row((int)$other->key, 'Another member\'s failure for bob@example.com');

// ---------------------------------------------------------------- the bundle

section('An operator\'s report has every section');

$op_bundle = ProblemReportBundle::build((int)$operator->key, 10, false, (int)$own_error->key,
	'/profile/thing?id=42&q=jane.doe@example.com', '');
foreach (array_keys(ProblemReportBundle::SECTIONS) as $key) {
	check(array_key_exists($key, $op_bundle), 'section present: ' . $key);
}
check($op_bundle['scope'] === 'operator', 'marked as an operator report');
check($op_bundle['error']['id'] === (int)$own_error->key, 'the error row is attached');
check($op_bundle['error']['hash'] === ErrorReference::hashForRow($own_error), 'the error carries its grouping hash');
check($op_bundle['request']['path'] === '/profile/thing?id=42&q=…',
	'the path is whole, a numeric query value is kept and any other is masked', $op_bundle['request']['path']);

section('A member\'s report carries only its own part');

$member_bundle = ProblemReportBundle::build((int)$member->key, 0, false, (int)$own_error->key, '/profile', '');
foreach (array('recent_errors', 'runtime', 'plugins', 'settings', 'health') as $key) {
	check(!array_key_exists($key, $member_bundle), 'no ' . $key . ' in a member\'s report');
}
check($member_bundle['error']['id'] === (int)$own_error->key, 'a member attaches an error recorded on their own account');

$stolen = ProblemReportBundle::build((int)$member->key, 0, false, (int)$others_error->key, '/profile', 'what I saw');
check($stolen['error']['id'] === null && $stolen['error']['message'] === 'what I saw',
	'a member cannot attach another member\'s error: the report falls back to what they saw');
check(strpos(json_encode($stolen), 'Another member') === false, 'the other member\'s error text appears nowhere');

section('Nothing private is in the JSON');

$json = json_encode($op_bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
check(!preg_match('/[A-Za-z0-9._%+\-]+@(?:[A-Za-z0-9\-]+\.)+[A-Za-z]{2,}/', str_replace('<email>@', '', $json)),
	'no email address (only the <email>@domain mask)');
check(!preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $json), 'no IPv4 address');
check(strpos($json, 'jane.doe') === false && strpos($json, '203.0.113.9') === false,
	'the stack frames\' arguments are dropped, not just masked');
check(!empty($op_bundle['error']['trace']) && strpos(implode("\n", $op_bundle['error']['trace']), 'prb_inner()') !== false,
	'the frames still name the functions', implode(' | ', $op_bundle['error']['trace']));

$leaked = array();
$db = DbConnector::get_instance()->get_db_link();
$site_values = array_map('strval', array_values($op_bundle['site']));
foreach ($op_bundle['plugins'] as $plugin) {
	$site_values[] = (string)$plugin['name'];
}
foreach ($op_bundle['settings']['changed_from_default'] as $name) {
	$value = (string)$db->query('SELECT stg_value FROM stg_settings WHERE stg_name = ' . $db->quote($name))->fetchColumn();
	// Short or generic values ("1", "default") and values the bundle states in
	// their own right (a plugin's name) appear by coincidence; a distinctive
	// one appearing anywhere else is a value that leaked.
	if (strlen($value) < 12 || in_array($value, $site_values, true) || ctype_digit($value)) {
		continue;
	}
	if (strpos($json, $value) !== false) {
		$leaked[] = $name;
	}
}
check($leaked === array(), 'no setting value appears, only names', implode(', ', $leaked));

// ------------------------------------------------------- the record and sender

section('A report saves and sends');

$client = new PrbStubClient();
ProblemReport::useClient($client);
harness_set_setting_mem('problem_reports_send', '1');

$reporter = array('user_id' => (int)$member->key, 'permission' => 0, 'logged_in_as' => false);
$inputs = ProblemReport::inputs(array('ref' => (string)$own_error->key, 'from' => '/profile', 'msg' => ''));
$report = ProblemReport::submit($reporter, $inputs, 'I pressed save and it broke.', null);
harness_register_row('prr_problem_reports', 'prr_problem_report_id', $report->key);

check((int)$report->key > 0, 'the report is saved');
check($report->get('prr_status') === ProblemReport::STATUS_SENT, 'a 2xx answer marks it sent', $report->get('prr_status'));
check($report->get('prr_remote_report_id') === '777', 'the upgrade source\'s report id is kept');
check(count($client->calls) === 1 && substr($client->calls[0]['url'], -strlen(ProblemReport::ACTION_PATH)) === ProblemReport::ACTION_PATH,
	'it went to the receiving action on the upgrade source', $client->calls[0]['url'] ?? '');
check(strpos($client->calls[0]['body'], 'I pressed save and it broke.') !== false
		&& strpos($client->calls[0]['body'], 'name="bundle"') !== false,
	'the body carries the bundle and the comment');
check($report->get('prr_bundle') === json_encode($report->bundle(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
	'the stored bundle is the one that was sent');

section('The admin page shows a report, escaped');

$escaped = ProblemReport::submit($reporter, $inputs, '<script>alert(9)</script> it broke', null);
harness_register_row('prr_problem_reports', 'prr_problem_report_id', $escaped->key);
$session_for_page = SessionControl::get_instance();
$session_for_page->set_api_user((int)$operator->key);
$saved_get = $_GET;
$_GET = array('prr_problem_report_id' => (string)$escaped->key);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/admin/admin_problem_reports';
$page_html = prb_render('adm/admin_problem_reports.php');
$_GET = $saved_get;
$session_for_page->clear_api_user();
check(strpos($page_html, 'RENDER FAILED') === false && strpos($page_html, 'What was sent') !== false,
	'the report page renders', substr($page_html, 0, 200));
check(strpos($page_html, '<script>alert(9)') === false && strpos($page_html, '&lt;script&gt;alert(9)') !== false,
	'the member\'s words are shown escaped');

section('Refusals, retries and the switch');

try {
	ProblemReport::submit($reporter, $inputs, '   ', null);
	check(false, 'an empty description is refused');
} catch (ProblemReportException $e) {
	check(true, 'an empty description is refused');
}

$client->status = 503;
$client->body = '{"api_version":"1.0","errortype":"ServerError","error":"Down for maintenance"}';
$failing = ProblemReport::submit($reporter, $inputs, 'Second report.', null);
harness_register_row('prr_problem_reports', 'prr_problem_report_id', $failing->key);
check($failing->get('prr_status') === ProblemReport::STATUS_FAILED, 'a refusal marks it failed');
check(strpos((string)$failing->get('prr_last_reason'), 'HTTP 503') !== false
		&& strpos((string)$failing->get('prr_last_reason'), 'Down for maintenance') !== false,
	'the reason says what the upgrade source answered', $failing->get('prr_last_reason'));

for ($i = 0; $i < ProblemReport::MAX_ATTEMPTS + 2; $i++) {
	$row = new ProblemReport($failing->key, TRUE);
	if ($row->is_sendable()) {
		$row->send();
	}
}
$row = new ProblemReport($failing->key, TRUE);
check((int)$row->get('prr_attempts') === ProblemReport::MAX_ATTEMPTS && !$row->is_sendable(),
	'retries stop at the limit', 'attempts ' . $row->get('prr_attempts'));

harness_set_setting_mem('problem_reports_send', '0');
$calls_before = count($client->calls);
$kept = ProblemReport::submit($reporter, $inputs, 'Third report.', null);
harness_register_row('prr_problem_reports', 'prr_problem_report_id', $kept->key);
check($kept->get('prr_status') === ProblemReport::STATUS_KEPT && count($client->calls) === $calls_before,
	'with sending switched off, a report is kept here and nothing is sent');
harness_set_setting_mem('problem_reports_send', '1');

section('Retention');

$db->prepare("UPDATE prr_problem_reports SET prr_create_time = now() - INTERVAL '400 days' WHERE prr_problem_report_id = ?")
	->execute(array((int)$kept->key));
$result = ProblemReport::purgeExpired(365);
check(!ProblemReport::check_if_exists((int)$kept->key), 'a report past the window is removed', $result['message']);
check(ProblemReport::check_if_exists((int)$report->key), 'a report inside the window stays');

ProblemReport::useClient(null);
harness_finish();
