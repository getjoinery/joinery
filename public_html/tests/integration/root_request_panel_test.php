<?php
/** @joinery-test
 * name: root_request_panel
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The root request panel (AdminPage::root_request_panel) polls a URL that
 * reaches the root_request_status action. Every page that queues a root
 * request (an upgrade, a plugin or theme install, a docs save) renders this
 * panel; when its URL missed the action, every panel answered 400 and sat at
 * Queued whatever root did.
 *
 * The URL is read from the rendered panel and asked over HTTP as a signed-in
 * superadmin with the page's CSRF token, for a request id that does not exist:
 * the action answers that it has no such request, which a wrong URL never does.
 *
 * Run: php tests/integration/root_request_panel_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/http.php');
harness_http_boot($argv);
harness_boot();

$run_id = substr(md5(uniqid('rrp', true)), 0, 6);
$super = make_user('Rrp' . $run_id, 10);
$super->set('usr_is_activated', true);
$super->save();
$jar = harness_jar_new('rrp');

section('The panel polls the status action');

$panel = AdminPage::root_request_panel('harness-' . $run_id);
check(preg_match("#fetch\\('(/api/v1/[^']+)'#", $panel, $m) === 1, 'the panel names the URL it polls');
$url = $m[1] ?? '';

$csrf = harness_web_login($jar, $super->get('usr_email'), 'TestPassword_Rrp' . $run_id);
check($csrf !== null, 'the superadmin is signed in');

$r = harness_request('POST', $url, array(
	'jar'     => $jar,
	'headers' => harness_csrf_header((string)$csrf),
	'body'    => array('id' => 'harness-' . $run_id),
));
$error = (string)($r['json']['error'] ?? '');
check($r['status'] === 422 && $error === 'No such request.',
	'the URL reaches root_request_status, which answers for the request id',
	$url . ' answered ' . $r['status'] . ' ' . $error);

harness_finish();
