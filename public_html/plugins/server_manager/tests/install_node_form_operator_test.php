<?php
/** @joinery-test
 * name: install_node_form_operator
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Remote Install offers every cloud account of this management node, the
 * default chosen, beside customers' connected ones, as the Copy tab does
 * (the cloud_accounts_list spec): a management node with no connected account
 * can still install a new node in its own.
 *
 * Proven over HTTP as a signed-in superadmin. Nothing here creates a
 * provision: every POST leaves the domain empty, so the form refuses it, and
 * the check is which fields it refuses. A ready provision would have the
 * Provision Customer Cloud task create a real server.
 *
 * Run: php plugins/server_manager/tests/install_node_form_operator_test.php [base_url] [origin_ip]
 *
 * @version 1.1 - every account of ours is offered as oca:<id>, the default chosen; an unknown choice is refused
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_http_boot($argv);
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$run_id = substr(md5(uniqid('inf', true)), 0, 6);
$display = 'HarnessTest install ' . $run_id;

$super = make_user('Inf' . $run_id, 10);
$super->set('usr_is_activated', true);
$super->save();
$jar = harness_jar_new('inf');

$provisions = function () use ($db, $display) {
	$q = $db->prepare('SELECT COUNT(*) FROM cvp_customer_cloud_provisions WHERE cvp_slug = ?');
	$q->execute(array(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($display)), '-')));
	return (int)$q->fetchColumn();
};
$post = function (string $account) use ($jar, $display, $run_id) {
	return harness_request('POST', '/admin/server_manager/install_node_form', array(
		'jar' => $jar, 'accept' => null, 'encode' => 'form',
		'body' => array(
			'mgn_name' => 'HarnessTest install ' . $run_id, 'cloud_account' => $account,
			'cloud_region' => 'us-east', 'cloud_instance_type' => 'g6-nanode-1',
			'sitename' => 'harness' . $run_id, 'docker_mode' => 'docker', 'install_mode' => 'fresh',
			'domain' => '',
		),
	));
};

check(harness_web_login($jar, $super->get('usr_email'), 'TestPassword_Inf' . $run_id) !== null, 'the superadmin is signed in');

// ---------------------------------------------------------------------------
section('Our accounts are offered, the default chosen');

$default = OperatorCloudAccount::default_account();
if ($default === null || !$default->usable()) {
	harness_skip('this site holds no usable default cloud account, so there is nothing to offer');
} else {
	$key = $default->choice_key();
	$page = harness_request('GET', '/admin/server_manager/install_node_form', array('jar' => $jar, 'accept' => null));
	check($page['status'] === 200, 'Remote Install renders', 'status ' . $page['status']);
	check(preg_match('#<option[^>]*value="' . preg_quote($key, '#') . '"[^>]*selected[^>]*>[^<]*ours, default#i', $page['body']) === 1
		|| preg_match('#<option[^>]*selected[^>]*value="' . preg_quote($key, '#') . '"[^>]*>[^<]*ours, default#i', $page['body']) === 1,
		'the cloud account list offers the default account of ours, chosen');

	// -----------------------------------------------------------------------
	section('Choosing it is accepted');

	$before = $provisions();
	$r = $post($key);
	check(strpos($r['body'], 'Domain is required.') !== false, 'the form is refused for the empty domain (the control)');
	check(strpos($r['body'], 'Choose a cloud account.') === false,
		'our account is not refused as an unknown choice');
	check($provisions() === $before, 'a refused form records no provision');
}

// ---------------------------------------------------------------------------
section('No account chosen, or an unknown one, is refused');

$r = $post('');
check(strpos($r['body'], 'Choose a cloud account.') !== false, 'an empty choice is refused, naming the field');
$r = $post('operator');
check(strpos($r['body'], 'Choose a cloud account.') !== false, 'a key naming no account is refused');

harness_finish();
