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
 * Automatic reports (Part 2): the same-fault key, which errors count, what an
 * automatic report carries, and one report per fault with its count sent as
 * updates.
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
$applied = (string)DbConnector::get_instance()->get_db_link()->query('SELECT MAX(mig_version) FROM mig_migrations')->fetchColumn();
check($op_bundle['site']['version'] !== '' && $op_bundle['site']['schema_version'] === $applied,
	'the site names its version and the highest migration applied', $op_bundle['site']['schema_version']);
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

// ------------------------------------------------------- automatic reports

section('The same-fault key');

$base_error = array('kind' => 'Exception', 'file' => 'includes/Thing.php', 'line' => '40', 'message' => 'Row 17 not found',
	'trace' => array('#0 includes/Thing.php(12): Thing->load()', '#1 views/page.php(88): Thing->show()'));
$fp = ProblemReportBundle::fingerprint($base_error);
check(is_string($fp) && strlen($fp) === 32, 'an error with frames has a key');
$moved = $base_error;
$moved['line'] = '52';
$moved['message'] = 'Row 99 not found';
$moved['trace'] = array('#0 includes/Thing.php(19): Thing->load()', '#1 views/page.php(101): Thing->show()');
check(ProblemReportBundle::fingerprint($moved) === $fp, 'line numbers and the message do not split a fault that has frames');
$other_path = $base_error;
$other_path['trace'][1] = '#1 views/other.php(88): Thing->show()';
check(ProblemReportBundle::fingerprint($other_path) !== $fp, 'a different caller is a different fault');
$other_kind = $base_error;
$other_kind['kind'] = 'Database Error';
check(ProblemReportBundle::fingerprint($other_kind) !== $fp, 'a different kind is a different fault');

$bare = array('kind' => 'Exception', 'file' => 'includes/Thing.php', 'message' => "User 'bob' has 3 items (ref 9f8e7d6c5b4a)");
$bare2 = array('kind' => 'Exception', 'file' => 'includes/Thing.php', 'message' => "User 'alice' has 12 items (ref 0a1b2c3d4e5f)");
check(ProblemReportBundle::fingerprint($bare) === ProblemReportBundle::fingerprint($bare2),
	'with no frames, numbers, quoted text and hex runs do not split a fault');
check(ProblemReportBundle::fingerprint($bare) !== ProblemReportBundle::fingerprint(array('kind' => 'Exception', 'file' => 'includes/Thing.php', 'message' => 'Disk full')),
	'with no frames, a different message is a different fault');
check(ProblemReportBundle::fingerprint(array('message' => '')) === null, 'an error naming neither a place nor a message has no key');

section('Which errors are sent automatically');

check(ProblemReport::isUnexpected(new RuntimeException('boom')), 'an ordinary exception is unexpected');
check(!ProblemReport::isUnexpected(new SystemDisplayableError('Enter a date.')), 'a message marked safe to show is not');
check(!ProblemReport::isUnexpected(new SystemAuthenticationError('No permission.')), 'a permission refusal is not');

section('What an automatic report carries');

check(ProblemReportBundle::pathShape('/profile/jane-doe/posts/42?id=7&q=Jane') === '/profile/…/posts/42?id=7&q=Jane',
	'a path is masked to its shape (request() masks the query)', ProblemReportBundle::pathShape('/profile/jane-doe/posts/42?id=7&q=Jane'));
$auto = ProblemReportBundle::automatic($own_error, 'RuntimeException', '/profile/Jane.Doe/edit?id=5&q=secret');
check($auto['scope'] === 'automatic', 'marked automatic');
check(!isset($auto['who']) && !isset($auto['recent_errors']), 'no reporter and no log lines');
check(isset($auto['runtime'], $auto['plugins'], $auto['settings'], $auto['health']), 'the site-wide sections are there');
check($auto['request']['path'] === '/profile/…/edit?id=5&q=…', 'the path is its shape', $auto['request']['path']);
check(!isset($auto['request']['timezone']), 'no time zone');
check($auto['error']['class'] === 'RuntimeException', 'the exception class is carried');
check(ProblemReportBundle::maskQuoted("User 'bob' said \"hi\"") === "User '…' said \"…\"", 'quoted text in a message is masked');

section('An unexpected error starts one automatic report, and recurrences count');

$db = DbConnector::get_instance()->get_db_link();
harness_set_setting_mem('problem_reports_send', '1');
harness_set_setting_mem('problem_reports_auto_send', '0');
$off_id = ErrorReference::log(new RuntimeException('prb automatic off'));
harness_register_row('err_general_errors', 'err_general_error_id', $off_id);
$auto_count = function () use ($db) {
	return (int)$db->query("SELECT COUNT(*) FROM prr_problem_reports WHERE prr_automatic")->fetchColumn();
};
$before = $auto_count();
ErrorReference::log(new RuntimeException('prb automatic off, again'));
check($auto_count() === $before, 'with the switch off, nothing is noted');

harness_set_setting_mem('problem_reports_auto_send', '1');
/** Thrown from one place, so every call is the same fault. */
function prb_auto_fault($n) {
	return new RuntimeException('prb automatic fault number ' . $n);
}
$first_id = ErrorReference::log(prb_auto_fault(1));
harness_register_row('err_general_errors', 'err_general_error_id', $first_id);
check($auto_count() === $before + 1, 'an unexpected error starts an automatic report');
$auto_id = (int)$db->query("SELECT MAX(prr_problem_report_id) FROM prr_problem_reports WHERE prr_automatic")->fetchColumn();
harness_register_row('prr_problem_reports', 'prr_problem_report_id', $auto_id);
$auto_row = new ProblemReport($auto_id, TRUE);
check($auto_row->get('prr_status') === ProblemReport::STATUS_QUEUED && $auto_row->get('prr_usr_user_id') === null,
	'it waits for the task, with no reporter', $auto_row->get('prr_status'));
check((int)$auto_row->get('prr_occurrences') === 1 && $auto_row->bundle()['scope'] === 'automatic', 'counted once, with an automatic bundle');

$second_id = ErrorReference::log(prb_auto_fault(2));
harness_register_row('err_general_errors', 'err_general_error_id', $second_id);
$auto_row = new ProblemReport($auto_id, TRUE);
check($auto_count() === $before + 1 && (int)$auto_row->get('prr_occurrences') === 2,
	'the same fault again adds to the count and starts nothing new', 'occurrences ' . $auto_row->get('prr_occurrences'));

$wall_id = ErrorReference::log(new SystemDisplayableError('prb a wall, not a bug'));
harness_register_row('err_general_errors', 'err_general_error_id', $wall_id);
check($auto_count() === $before + 1, 'an error with a message safe to show is not noted');

section('Sending an automatic report, then its count');

$client->status = 200;
$client->body = '{"api_version":"1.0","data":{"report_id":888}}';
$calls_before = count($client->calls);
check($auto_row->is_sendable() && $auto_row->send(), 'the task sends it');
$sent_body = $client->calls[$calls_before]['body'] ?? '';
check(strpos($sent_body, '"occurrences":2') !== false && strpos($sent_body, '"scope":"automatic"') !== false,
	'it carries both occurrences and the automatic scope');
$auto_row = new ProblemReport($auto_id, TRUE);
check((int)$auto_row->get('prr_occurrences_sent') === 2 && !$auto_row->is_sendable(), 'what was sent is recorded; nothing is due');

$third_id = ErrorReference::log(prb_auto_fault(3));
harness_register_row('err_general_errors', 'err_general_error_id', $third_id);
$auto_row = new ProblemReport($auto_id, TRUE);
check($auto_row->is_sendable() && $auto_row->unsentOccurrences() === 1, 'a recurrence after sending makes it due again');
$due = new MultiProblemReport(array('sendable' => true));
$ids = array();
foreach ($due as $d) { $ids[] = (int)$d->key; }
check(in_array($auto_id, $ids, true), 'the task\'s list includes it');
$auto_row->send();
$update_body = $client->calls[count($client->calls) - 1]['body'];
check(strpos($update_body, '"occurrences":1') !== false, 'the update carries only the new count');
$auto_row = new ProblemReport($auto_id, TRUE);
check((int)$auto_row->get('prr_occurrences') === 3 && (int)$auto_row->get('prr_occurrences_sent') === 3, 'all three are now heard');
harness_set_setting_mem('problem_reports_auto_send', '0');

section('Retention');

$db->prepare("UPDATE prr_problem_reports SET prr_create_time = now() - INTERVAL '400 days' WHERE prr_problem_report_id = ?")
	->execute(array((int)$kept->key));
$result = ProblemReport::purgeExpired(365);
check(!ProblemReport::check_if_exists((int)$kept->key), 'a report past the window is removed', $result['message']);
check(ProblemReport::check_if_exists((int)$report->key), 'a report inside the window stays');

ProblemReport::useClient(null);
harness_finish();
