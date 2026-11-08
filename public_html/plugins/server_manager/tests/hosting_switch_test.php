<?php
/** @joinery-test
 * name: hosting_switch
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Whether this management node sells hosting (SmHosting):
 *
 *   - with the setting off and nothing sold on record, it does not: the
 *     hosting pages have nothing to show, and the Provisioning page keeps only
 *     the cloud accounts its own servers are made and copied in
 *   - a provision an admin made (a site copy, an adoption) is not selling
 *   - a hosting order on record makes it a seller, setting or not
 *   - the setting on makes any site one
 *
 * Rows go to the test database and are deleted.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/hosting_switch_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$sold = function () use ($db) {
	$n = 0;
	foreach (array("SELECT count(*) FROM cvp_customer_cloud_provisions WHERE cvp_origin IN ('order', 'buyer')",
			'SELECT count(*) FROM rdm_registered_domains', 'SELECT count(*) FROM svt_service_tenants',
			'SELECT count(*) FROM itx_instance_transfers', 'SELECT count(*) FROM htr_hosted_trials') as $sql) {
		$n += (int)$db->query($sql)->fetchColumn();
	}
	return $n;
};
/** A fresh in-use answer: it is kept for the request. */
$fresh = function () {
	$ref = new ReflectionProperty('SmHosting', 'in_use');
	$ref->setValue(null, null);
	return SmHosting::enabled();
};

harness_set_setting_mem(SmHosting::SETTING, '0');

// ---------------------------------------------------------------------------
section('Setting off, nothing sold: not a seller');

if ($sold() === 0) {
	check($fresh() === false, 'it does not sell hosting');
	check(strpos(SmHosting::off_text(), 'sells hosting') !== false, 'the page says which setting turns selling on');

	$_SERVER['REQUEST_METHOD'] = 'GET';
	require_once(PathHelper::getIncludePath('plugins/server_manager/logic/admin_provisioning_setup_logic.php'));
	$result = admin_provisioning_setup_logic(array('tab' => 'shop'));
	$data = $result->data ?? array();
	check(array_keys($data['tabs'] ?? array()) === array('hosting') || array_keys($data['tabs'] ?? array()) === array('hosting', 'cleanup'),
		'the Provisioning page keeps only the cloud accounts (and the test cleanup where a test account is held)',
		implode(',', array_keys($data['tabs'] ?? array())));
	check(($data['tab'] ?? '') === 'hosting', 'a selling tab asked for opens the cloud accounts instead', (string)($data['tab'] ?? ''));
	check(($data['page_title'] ?? '') === 'Cloud Accounts', 'the page is called Cloud Accounts', (string)($data['page_title'] ?? ''));

	// ---------------------------------------------------------------------------
	section('A provision an admin made is not selling');

	$owner = make_user('HostingSwitch');
	$admin = new CustomerCloudProvision(NULL);
	$admin->set('cvp_usr_user_id', $owner->key);
	$admin->set('cvp_origin', 'admin');
	$admin->set('cvp_domain', 'hosting-switch-admin.example.test');
	$admin->set('cvp_slug', 'hstswadmin');
	$admin->set('cvp_status', 'pending');
	$admin->prepare();
	$admin->save();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $admin->key);
	check($fresh() === false, 'still not a seller');

	// ---------------------------------------------------------------------------
	section('A buyer\'s hosting order on record: a seller');

	$order = new CustomerCloudProvision(NULL);
	$order->set('cvp_usr_user_id', $owner->key);
	$order->set('cvp_origin', 'buyer');
	$order->set('cvp_domain', 'hosting-switch-order.example.test');
	$order->set('cvp_slug', 'hstsworder');
	$order->set('cvp_status', 'draft');
	$order->prepare();
	$order->save();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $order->key);
	check($fresh() === true, 'it sells hosting');
	$order->permanent_delete();
	$fresh();
} else {
	harness_skip('setting off, nothing sold', 'the test database holds sold rows another suite left');
}

// ---------------------------------------------------------------------------
section('Setting on: a seller');

harness_set_setting_mem(SmHosting::SETTING, '1');
check($fresh() === true, 'it sells hosting');

harness_finish();
