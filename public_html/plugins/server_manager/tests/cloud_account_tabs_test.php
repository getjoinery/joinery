<?php
/** @joinery-test
 * name: cloud_account_tabs
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Which cloud account a box belongs to, behind the Server Manager dashboard's tabs
 * (the server_manager_account_tabs spec).
 *
 *  - a box made by hand is marked with the default cloud account's kind (test when
 *    that account is flagged test, else main); one that already names an account
 *    keeps it;
 *  - a server in one of our accounts is on that account's tab: a test account's on
 *    Test, a main account's, and a second main account's, on Main (the
 *    cloud_accounts_list spec, WP5);
 *  - a node placed on a host takes its host's account, a node whose host is gone
 *    stands on its own, an unmarked box reads as main;
 *  - the backfill marks only unmarked boxes made since the token swap, and only on a
 *    plane whose own account is the test one;
 *  - the selected tab is the request's choice, else the cookie, else main.
 *
 * Run: php plugins/server_manager/tests/cloud_account_tabs_test.php
 *
 * @version 1.1 - main or test follows the account's own flag, and the default account's for a box made by hand
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

class CloudAccountTabsTest {

	private $accounts = array();

	/** An account of ours, flagged test or not, removed after the run. */
	private function account(bool $test): OperatorCloudAccount {
		$oca = new OperatorCloudAccount(NULL);
		$oca->set('oca_method', OperatorCloudAccount::METHOD_KEY);
		$oca->set('oca_account_name', 'HarnessTest cat ' . ($test ? 'test' : 'main') . ' ' . bin2hex(random_bytes(3)));
		$oca->set('oca_is_test', $test);
		$oca->store_credential('harness-cat-token');
		$oca->save();
		$oca->load();
		harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $oca->key);
		return $oca;
	}

	private function provision(OperatorCloudAccount $oca): CustomerCloudProvision {
		$p = new CustomerCloudProvision(NULL);
		$p->set('cvp_hosting_mode', 'operator');
		$p->set('cvp_oca_operator_cloud_account_id', (int)$oca->key);
		return $p;
	}

	function run() {
		$was = (string)OperatorCloudAccount::default_id();
		try {
			$this->test_names();
			$this->test_stamping();
			$this->test_account_flag();
			$this->test_inheritance();
			$this->test_backfill();
			$this->test_selected();
		} catch (Throwable $e) {
			check(false, 'uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			Setting::put(OperatorCloudAccount::DEFAULT_SETTING, $was === '0' ? '' : $was);
		}
	}

	private function node(string $name_part, array $set = []): ManagedNode {
		$sfx = substr(bin2hex(random_bytes(4)), 0, 8);
		$n = new ManagedNode(NULL);
		$n->set('mgn_name', 'HarnessTest cat ' . $name_part . ' ' . $sfx);
		$n->set('mgn_slug', 'cat-' . $sfx);
		$n->set('mgn_host', '198.51.100.' . random_int(2, 250));
		$n->set('mgn_uptime_enabled', false);
		foreach ($set as $k => $v) { $n->set($k, $v); }
		$n->save();
		$n->load();
		harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
		return $n;
	}

	private function host(array $set = []): ManagedHost {
		$sfx = substr(bin2hex(random_bytes(4)), 0, 8);
		$h = new ManagedHost(NULL);
		$h->set('mgh_name', 'cat host ' . $sfx);
		$h->set('mgh_slug', 'cat-host-' . $sfx);
		$h->set('mgh_host', '198.51.100.' . random_int(2, 250));
		foreach ($set as $k => $v) { $h->set($k, $v); }
		$h->save();
		$h->load();
		harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $h->key);
		return $h;
	}

	private function raw_set(string $table, string $col, string $id_col, $id, $value, ?string $create_time = null) {
		$db = DbConnector::get_instance()->get_db_link();
		$prefix = substr($col, 0, 3);
		$db->prepare("UPDATE $table SET $col = ?" . ($create_time ? ", {$prefix}_create_time = ?" : '') . " WHERE $id_col = ?")
			->execute($create_time ? [$value, $create_time, $id] : [$value, $id]);
	}

	private function test_names() {
		section('names');
		check(CloudAccounts::label('main') === 'Main' && CloudAccounts::label('test') === 'Test', 'both tabs are named');
		check(CloudAccounts::normalize('') === 'main' && CloudAccounts::normalize('nonsense') === 'main' && CloudAccounts::normalize(' test ') === 'test',
			'an empty or unknown value reads as main');
		check(CloudAccounts::for_company(TestCloudCleanup::COMPANY) === 'test', 'the disposable company is the test account');
		check(CloudAccounts::for_company('') === 'main' && CloudAccounts::for_company('Acme') === 'main', 'any other company, or none, is main');
	}

	private function test_stamping() {
		section('a new box is marked with the default account\'s kind');
		$test = $this->account(true);
		$main = $this->account(false);
		Setting::put(OperatorCloudAccount::DEFAULT_SETTING, (string)$test->key);
		check(CloudAccounts::plane_account() === 'test', 'with the test account the default, this plane reads as test');
		$n = $this->node('on-test-plane');
		check($n->get('mgn_cloud_account') === 'test', 'a node made on a test plane is test');
		$h = $this->host();
		check($h->get('mgh_cloud_account') === 'test', 'and so is a host');

		$kept = $this->node('chosen', ['mgn_cloud_account' => 'main']);
		check($kept->get('mgn_cloud_account') === 'main', 'a node that names its account keeps it');

		Setting::put(OperatorCloudAccount::DEFAULT_SETTING, (string)$main->key);
		$m = $this->node('on-main-plane');
		check($m->get('mgn_cloud_account') === 'main', 'with a main account the default, a node is main');
		Setting::put(OperatorCloudAccount::DEFAULT_SETTING, '');
		$none = $this->node('no-default');
		check($none->get('mgn_cloud_account') === 'main', 'no default account means main');
	}

	private function test_account_flag() {
		section('a server is on its own account\'s tab');
		$test = $this->account(true);
		$main = $this->account(false);
		$second = $this->account(false);
		Setting::put(OperatorCloudAccount::DEFAULT_SETTING, (string)$main->key);
		check(CloudAccounts::for_provision($this->provision($test)) === 'test',
			'a server in the test account is on Test, whatever the default is');
		check(CloudAccounts::for_provision($this->provision($main)) === 'main', 'one in a main account is on Main');
		check(CloudAccounts::for_provision($this->provision($second)) === 'main', 'and so is one in a second main account');
		$cust = new CustomerCloudProvision(NULL);
		$cust->set('cvp_hosting_mode', 'customer');
		check(CloudAccounts::for_provision($cust) === 'customer'
			&& CloudAccounts::normalize(CloudAccounts::for_provision($cust)) === 'main', 'a customer\'s server is the customer\'s, shown on Main');
		Setting::put(OperatorCloudAccount::DEFAULT_SETTING, (string)$test->key);
		check(CloudAccounts::for_provision($this->provision($second)) === 'main',
			'a main account\'s server stays on Main when the test account is the default');
	}

	private function test_inheritance() {
		section('a node follows its host');
		$host = $this->host(['mgh_cloud_account' => 'test']);
		$on_host = $this->node('placed', ['mgn_cloud_account' => 'main', 'mgn_mgh_managed_host_id' => (int)$host->key]);
		check(CloudAccounts::of_node($on_host) === 'test', 'a node on a test host is test whatever it holds itself');
		check(CloudAccounts::of_node($on_host, [(int)$host->key => 'test']) === 'test', 'the page path gives the same answer from its host map');

		$orphan = $this->node('orphan', ['mgn_cloud_account' => 'test', 'mgn_mgh_managed_host_id' => 999999999]);
		check(CloudAccounts::of_node($orphan) === 'test', 'a node whose host row is gone stands on its own account');
		check(CloudAccounts::of_node($orphan, []) === 'test', 'and so does one whose host is not in the page map');

		$unmarked = $this->node('unmarked');
		$this->raw_set('mgn_managed_nodes', 'mgn_cloud_account', 'mgn_managed_node_id', $unmarked->key, null);
		$unmarked->load();
		check(CloudAccounts::of_node($unmarked) === 'main', 'an unmarked node reads as main');
	}

	private function test_backfill() {
		section('backfill');
		$db = DbConnector::get_instance()->get_db_link();
		$recent = $this->node('recent');
		$old = $this->node('old');
		foreach (array($recent, $old) as $n) {
			$this->raw_set('mgn_managed_nodes', 'mgn_cloud_account', 'mgn_managed_node_id', $n->key, null,
				$n === $recent ? '2026-10-08 12:00:00' : '2026-09-01 12:00:00');
		}
		$marked = $this->host();
		$this->raw_set('mgh_managed_hosts', 'mgh_cloud_account', 'mgh_managed_host_id', $marked->key, null, '2026-10-08 12:00:00');

		$count = function ($table, $col, $id_col, $id) use ($db) {
			$q = $db->prepare("SELECT $col FROM $table WHERE $id_col = ?");
			$q->execute([$id]);
			return $q->fetchColumn();
		};
		$acct = function ($n) use ($count) { return $count('mgn_managed_nodes', 'mgn_cloud_account', 'mgn_managed_node_id', $n->key); };

		CloudAccounts::backfill($db, 'Joinery');
		check($acct($recent) === null && $acct($old) === null, 'on a plane whose account is not the test one nothing is marked');

		CloudAccounts::backfill($db, TestCloudCleanup::COMPANY);
		check($acct($recent) === 'test', 'on a test plane an unmarked box made since the swap is marked test');
		check($acct($old) === null, 'an older one is left unmarked, so it shows under main');
		check($count('mgh_managed_hosts', 'mgh_cloud_account', 'mgh_managed_host_id', $marked->key) === 'test', 'hosts are marked the same way');

		$this->raw_set('mgn_managed_nodes', 'mgn_cloud_account', 'mgn_managed_node_id', $recent->key, 'main');
		CloudAccounts::backfill($db, TestCloudCleanup::COMPANY);
		check($acct($recent) === 'main', 'a second run leaves a box that already names its account alone');
	}

	private function test_selected() {
		section('selected tab');
		check(CloudAccounts::selected([], []) === 'main', 'main by default');
		check(CloudAccounts::selected([], ['svm_account' => 'test']) === 'test', 'the cookie remembers the last tab');
		check(CloudAccounts::selected(['account' => 'main'], ['svm_account' => 'test']) === 'main', 'the request wins over the cookie');
		check(CloudAccounts::selected(['account' => 'bogus'], ['svm_account' => 'test']) === 'test', 'an unknown tab in the request is ignored');
		check(CloudAccounts::selected([], ['svm_account' => 'bogus']) === 'main', 'an unknown tab in the cookie reads as main');
	}
}

(new CloudAccountTabsTest())->run();
harness_finish();
?>
