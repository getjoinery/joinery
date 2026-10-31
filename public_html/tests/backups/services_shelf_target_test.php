<?php
/** @joinery-test
 * name: services_shelf_target
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A self-hosted site's backups in getjoinery's backup storage
 * (specs/easy_signup_completion.md WP4), end to end on this machine: the
 * site's side (ServicesClient's enrol answer, the `managed` target, the
 * engine, the readers) against getjoinery's own broker (ShelfBroker) over the
 * loopback S3 fixture. What a ServicesClient would send over HTTP goes
 * straight to the broker's decision instead, for the site's own tenant.
 *
 *   - enrolling writes a `managed` target with no credential and makes it
 *     where backups go; no form offers that provider
 *   - the engine plans a site run through the target's broker, prunes
 *     nothing there, and a backup lands in the site's storage space with
 *     every object's hash in getjoinery's ledger
 *   - the site reads it back through getjoinery: a listing, a download
 *     link, a verify request; a passed verify is reported there
 *   - getjoinery signs no delete; a refusal (over the allowance) and a
 *     moved storage place are each a sentence, not a silent failure
 *   - the Test button asks getjoinery for the site's standing
 *
 * Run: php tests/run.php db --only=tests/backups/services_shelf_target_test.php
 *
 * @version 1.4 - the fixture recovery key is an X-Wing key (post-quantum sealing WP1)
 * @version 1.3 - the reconcile asks per point and never lists the offloaded-file store (round-3 V1)
 * @version 1.2 - every call passes the action's real input descriptor; the object store lists the active space only,
 *                a moved key's read finds its space, a partial listing and a moved place prune no record (re-review)
 * @version 1.1 - the WP4 review's fixes: the site's own slug untouched, the run's chain recorded at getjoinery, a
 *                repeated finish, a verify-length link, no write outside a run, refused at the allowance, a moved
 *                place adopted, records reconciled with getjoinery's listing
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('tests/lib/s3_fixtures.php'));

if (!class_exists('ShelfBroker') || !class_exists('JoineryServices')) {
	harness_skip('getjoinery backup storage', 'the server_manager plugin is not active here');
	harness_finish();
}
$db = DbConnector::get_instance()->get_db_link();
foreach (array('svt_service_tenants', 'svr_shelf_runs', 'svo_shelf_objects', 'sps_storage_spaces') as $table) {
	if (!$db->query("SELECT to_regclass('public." . $table . "') IS NOT NULL")->fetchColumn()) {
		harness_skip('getjoinery backup storage', $table . ' is not in the test database yet');
		harness_finish();
	}
}

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('getjoinery backup storage', 'no loopback S3 fixture could start');
	harness_finish();
}
harness_defer(function () use ($fx) { s3fx_stop($fx); });

/**
 * What a ServicesClient would get from getjoinery, decided by getjoinery's own
 * broker for one tenant. Every call first passes the action's own input
 * descriptor exactly as the HTTP endpoint does (DescriptorValidator), with the
 * payload ServicesClient sends: a descriptor that refuses what the site sends
 * fails here, not only over HTTP.
 */
class SstFakeClient {
	public $row;
	public $calls = array();
	public function __construct(ServiceTenant $row) { $this->row = $row; }
	private function ask($action, array $payload, callable $f) {
		$this->calls[] = $action;
		require_once(PathHelper::getIncludePath('plugins/server_manager/logic/' . $action . '_logic.php'));
		try {
			$in = DescriptorValidator::coerce(call_user_func($action . '_logic_descriptor'), $payload);
		} catch (InvalidArgumentException $e) {
			throw new ServiceClientException('getjoinery: ' . $e->getMessage());
		}
		try {
			return $f($in);
		} catch (ShelfBrokerException $e) {
			throw new ServiceClientException('getjoinery: ' . $e->getMessage());
		}
	}
	public function shelfBeginRun($profile, $chain, $artifacts) {
		return $this->ask('shelf_begin_run', array('profile' => $profile, 'chain' => $chain, 'artifacts' => $artifacts), function ($in) {
			return ShelfBroker::beginRun($this->row, trim((string)($in['profile'] ?? 'site')), trim((string)($in['chain'] ?? '')),
				(array)($in['artifacts'] ?? array()));
		});
	}
	public function shelfSign($run_id, $name, $operation, $args = array()) {
		return $this->ask('shelf_sign', array('run_id' => $run_id, 'name' => $name, 'operation' => $operation) + $args, function ($in) {
			return ShelfBroker::sign($this->row, (int)($in['run_id'] ?? 0), (string)($in['name'] ?? ''), (string)($in['operation'] ?? ''), array(
				'bytes' => (int)($in['bytes'] ?? 0), 'upload_id' => (string)($in['upload_id'] ?? ''), 'first' => (int)($in['first'] ?? 1),
				'count' => (int)($in['count'] ?? ShelfBroker::PARTS_PER_BATCH), 'space_id' => (int)($in['space_id'] ?? 0),
				'expires' => (int)($in['expires'] ?? 0)));
		});
	}
	public function shelfList($prefix = '') {
		return $this->ask('shelf_list', array('prefix' => $prefix), function ($in) {
			return ShelfBroker::listPrefix($this->row, (string)($in['prefix'] ?? ''));
		});
	}
	public function shelfFinishRun($run_id, $completed, $chain = '') {
		return $this->ask('shelf_finish_run', array('run_id' => $run_id, 'completed' => $completed, 'chain' => $chain), function ($in) {
			return ShelfBroker::finishRun($this->row, (int)($in['run_id'] ?? 0), (array)($in['completed'] ?? array()),
				trim((string)($in['chain'] ?? '')));
		});
	}
	public function shelfVerifiedRun($run_id) {
		return $this->ask('shelf_verified_run', array('run_id' => $run_id), function ($in) {
			return ShelfBroker::verifiedRun($this->row, (int)($in['run_id'] ?? 0));
		});
	}
	public function shelfStatus() {
		return $this->ask('shelf_status', array(), function () {
			return JoineryServices::statusOf($this->row) + array('writable' => ShelfBroker::refusal($this->row) === '',
				'refusal' => ShelfBroker::refusal($this->row));
		});
	}
}

$cleanup = array();

// The settings this suite writes on the test database come back as they were.
$kept = array();
foreach (array('backup_target_id', 'backup_path_slug', 'backup_service_folder', 'backup_recovery_public_key', 'backup_recovery_public_key_proven_fpr') as $name) {
	$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	$kept[$name] = $q->fetchColumn();
}
harness_defer(function () use ($kept, $db) {
	foreach ($kept as $name => $value) {
		if ($value === false) {
			$db->prepare('DELETE FROM stg_settings WHERE stg_name = ?')->execute(array($name));
		} else {
			$db->prepare('UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?')->execute(array($value, $name));
		}
		Globalvars::get_instance()->forget_setting($name);
	}
});

// getjoinery's own storage, on the fixture.
$plane_target = new BackupTarget(NULL);
$plane_target->set('bkt_name', 'harnesstest getjoinery storage ' . bin2hex(random_bytes(3)));
$plane_target->set('bkt_provider', 's3');
$plane_target->set('bkt_bucket', 'shelf');
$plane_target->set('bkt_path_prefix', 'harness-shelf');
$plane_target->set('bkt_credentials', json_encode(s3fx_creds($fx)));
$plane_target->save();
$cleanup[] = array('bkt_backup_targets', 'bkt_backup_target_id', (int)$plane_target->key);
harness_set_setting_mem('server_manager_backup_target_id', (string)$plane_target->key);
harness_set_setting_mem('server_manager_hosted_shelf_allowance_gb', '1');
harness_set_setting_mem('server_manager_services_grace_days', '14');

// The customer, entitled, enrolled: what the site's enrol call answers.
$owner = make_user('SstOwner');
$key = make_machine_key($owner->key, 'Joinery services', 3);
$key_id = (int)$key['api_key']->key;
// Registered after the fixture user: teardown runs last-first, and an account
// is never removed while its tenant holds anything in backup storage.
harness_defer(function () use (&$cleanup, $db) {
	foreach (array_reverse($cleanup) as $c) {
		$db->exec("DELETE FROM {$c[0]} WHERE {$c[1]} = " . (int)$c[2]);
	}
});
$host = 'sst-' . bin2hex(random_bytes(3)) . '.example.com';
JoineryServices::enrol($owner->key, $key_id, 'shelf', $host);
$row = ServiceTenant::forKey($key_id, 'shelf');
$cleanup[] = array('svt_service_tenants', 'svt_service_tenant_id', (int)$row->key);
JoineryServices::grant($row, '2030-01-01');
$answer = JoineryServices::enrol($owner->key, $key_id, 'shelf', $host);
$row = ServiceTenant::forKey($key_id, 'shelf');
$space = StorageSpace::active_for(StorageSpace::OWNER_TENANT, (int)$row->key);
$cleanup[] = array('sps_storage_spaces', 'sps_storage_space_id', $space ? (int)$space->key : 0);
harness_defer(function () use ($db, $row) {
	$db->exec('DELETE FROM svo_shelf_objects WHERE svo_svt_service_tenant_id = ' . (int)$row->key);
	$db->exec('DELETE FROM svr_shelf_runs WHERE svr_svt_service_tenant_id = ' . (int)$row->key);
});
$client = new SstFakeClient($row);
ServicesShelfBroker::$client_for_tests = $client;
harness_defer(function () { ServicesShelfBroker::$client_for_tests = null; });

// A proven recovery key of the site's own: nothing is sealed without one.
BackupRecoveryKey::set_public_key(base64_encode((new XWing())->publicKey(random_bytes(32))), true);
BackupRecoveryKey::record_possession_proof(BackupRecoveryKey::expected_proof_string());

// ---------------------------------------------------------------------------
section('Enrolling writes a target with no credential, where backups go');

$own_slug = BackupRunner::slug();
$target = ServicesClient::applyShelfEnrolment($answer);
if ($target !== null) {
	$cleanup[] = array('bkt_backup_targets', 'bkt_backup_target_id', (int)$target->key);
}
check($target !== null && $target->is_service() && $target->get('bkt_enabled'),
	'an entitled answer writes a managed target, switched on', json_encode(array_keys($answer)));
$raw = $db->query('SELECT bkt_credentials FROM bkt_backup_targets WHERE bkt_backup_target_id = ' . (int)$target->key)->fetchColumn();
check($raw === null || $raw === '' || $raw === false, 'it stores no credential');
check($target->get_credentials() instanceof ServicesShelfBroker && $target->write_credentials() === $target->get_credentials(),
	'what it hands the signer is its broker, one per target');
check((int)Globalvars::get_instance()->get_setting('backup_target_id') === (int)$target->key
	&& BackupRunner::slug_for($target) === $space->folder(),
	'backups go there, under the folder getjoinery keeps for this site', BackupRunner::slug_for($target) . ' / ' . $space->folder());
check(BackupRunner::slug() === $own_slug,
	'the site\'s own slug is untouched: runs in a bucket of its own keep their names', BackupRunner::slug() . ' / ' . $own_slug);
check($target->get_credentials()->base() === $space->base(), 'the site names its keys exactly where getjoinery signs them',
	$target->get_credentials()->base() . ' / ' . $space->base());
check(!isset(StorageProvider::options()[StorageProvider::SERVICE]) && StorageProvider::known(StorageProvider::SERVICE),
	'no form offers it, and the model knows it');
$again = ServicesClient::applyShelfEnrolment($answer);
check($again !== null && (int)$again->key === (int)$target->key, 'enrolling again updates the same target');
check(ServicesClient::applyShelfEnrolment(array('entitled' => false)) === null, 'an answer that grants nothing writes nothing');

// ---------------------------------------------------------------------------
section('The engine plans a site run through getjoinery, and prunes nothing there');

$plan = BackupRunner::plan(array('profile' => 'site', 'backup_type' => 'project'));
check(($plan['broker'] ?? null) instanceof ServicesShelfBroker && $plan['target']->is_service(),
	'a site plan on the managed target signs every request through its broker');
check(empty($plan['prunes_cloud']), 'and deletes nothing in getjoinery\'s storage: retention there is getjoinery\'s');
check(!$plan['broker']->begun() && strpos((string)BackupRunner::destination_of_plan($plan), 'target:') === 0,
	'reading the plan opens no run at getjoinery', (string)BackupRunner::destination_of_plan($plan));

// ---------------------------------------------------------------------------
section('A backup lands in the site\'s storage space, every object hashed');

$work = harness_scratch_dir('shelf_target');
$tree = $work . '/site';
@mkdir($tree . '/public_html', 0755, true);
@mkdir($tree . '/config', 0755, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo 1;');
file_put_contents($tree . '/config/site.txt', 'config travels');
$dbname = 'jy_sst_' . getmypid();
$pdo = DbConnector::get_instance()->get_db_link();
$pdo->exec('CREATE DATABASE "' . $dbname . '" TEMPLATE template0');
harness_defer(function () use ($pdo, $dbname) { $pdo->exec('DROP DATABASE IF EXISTS "' . $dbname . '" WITH (FORCE)'); });
putenv('PGPASSWORD=' . (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));
harness_defer(function () { putenv('PGPASSWORD'); });

$plan['base_dir'] = $work . '/backups';
$plan['output_dir'] = $work . '/backups/site';
$plan['project'] = 'site';
$plan['project_dir'] = $tree;
$plan['database'] = $dbname;
$plan['objects'] = false;
$history = new BackupHistory(NULL);
$history->set('bkh_type', 'project');
$history->set('bkh_outcome', 'running');
$history->set('bkh_slug', $plan['slug']);
$history->set('bkh_profile', 'site');
$history->set('bkh_recovery_fpr', $plan['recovery_fpr']);
$history->set('bkh_encrypted', true);
$history->set('bkh_destination', 'target');
$history->set('bkh_bkt_backup_target_id', (int)$target->key);
$history->set('bkh_target_name', (string)$target->get('bkt_name'));
$history->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);

$error = null;
try {
	// As BackupRunner::run() does: the run is opened before anything is built.
	$history->set('bkh_remote_run_id', $plan['broker']->run_id());
	$history->set('bkh_remote_space_id', $plan['broker']->space_id());
	$history->save();
	$execute = new ReflectionMethod('BackupRunner', 'execute_chain');
	$execute->setAccessible(true);
	$execute->invoke(null, $plan, $history);
} catch (\Throwable $e) {
	$error = $e->getMessage();
	$plan['broker']->abort($error);
}
$history = new BackupHistory($history->key, TRUE);
$run = new ShelfRun((int)$history->get('bkh_remote_run_id'), TRUE);
check($error === null && (string)$history->get('bkh_outcome') === 'success', 'the run succeeds', (string)$error);
check($run->key && (string)$run->get('svr_state') === ShelfRun::STATE_FINISHED
	&& (int)$history->get('bkh_remote_space_id') === (int)$space->key,
	'getjoinery recorded the run finished, in the site\'s space', (string)$run->get('svr_state'));
check(strpos((string)BackupRunner::destination_of_plan($plan), 'space:') === 0,
	'once a run is open the chain follows getjoinery\'s space', (string)BackupRunner::destination_of_plan($plan));
check((string)$run->get('svr_chain') === (string)$history->get('bkh_chain_id') && (string)$run->get('svr_chain') !== '',
	'getjoinery records the chain the run extended, so its retention can keep the newest verified one',
	(string)$run->get('svr_chain') . ' / ' . (string)$history->get('bkh_chain_id'));
$again = $client->shelfFinishRun((int)$run->key, array(), '');
check(!empty($again['already_finished']), 'a finish asked again after a lost reply answers what was recorded', json_encode($again));
$keys = array_values(array_filter(s3fx_keys($fx), function ($k) use ($space) { return strpos($k, 'shelf/' . $space->base() . 'site/chain-') === 0; }));
check(count($keys) >= 3, 'the chain\'s archives and manifest are in the site\'s folder', json_encode(s3fx_keys($fx)));
$ledger = $db->query("SELECT count(*) FILTER (WHERE svo_completed_time IS NOT NULL AND svo_sha256 ~ '^[0-9a-f]{64}$'), count(*)
	FROM svo_shelf_objects WHERE svo_svr_shelf_run_id = " . (int)$run->key)->fetch(PDO::FETCH_NUM);
check((int)$ledger[0] > 0 && (int)$ledger[0] === (int)$ledger[1], 'getjoinery holds every object\'s size and sha256', json_encode($ledger));

// ---------------------------------------------------------------------------
section('The site reads it back through getjoinery');

$broker = $target->get_credentials();
$chain_prefix = $broker->base() . 'site/' . (string)$history->get('bkh_chain_id') . '/';
$listed = S3Signer::list($broker, (string)$target->get('bkt_bucket'), $chain_prefix);
check(count($listed) === count($keys) && strpos((string)($listed[0]['key'] ?? ''), $chain_prefix) === 0,
	'a listing names the same objects, keyed as the site names them', json_encode($listed));
$manifest_key = '';
foreach ($listed as $o) {
	if (BackupChain::is_manifest_name(basename((string)$o['key']))) { $manifest_key = (string)$o['key']; }
}
$got = S3Signer::get($broker, (string)$target->get('bkt_bucket'), '/' . $manifest_key);
check((int)($got['status'] ?? 0) === 200 && json_decode((string)($got['body'] ?? ''), true) !== null,
	'a download comes back on a link getjoinery signed', $manifest_key . ' ' . (int)($got['status'] ?? 0));
$url = S3Signer::presign_get($broker, (string)$target->get('bkt_bucket'), '/' . $manifest_key, 10800);
check(strpos($url, 'X-Amz-Signature=') !== false && strpos($url, 'X-Amz-Expires=10800') !== false,
	'a link to hand on is presigned there too, alive as long as a verify asks', $url);
try {
	$request = BackupVerifyLauncher::request($history, 2);
	check($request['manifest_url'] !== '' && count($request['artifact_urls']) >= 2,
		'a verify request is built from getjoinery\'s listing and links', json_encode(array_keys($request['artifact_urls'])));
} catch (\Throwable $e) {
	check(false, 'a verify request is built from getjoinery\'s listing and links', $e->getMessage());
}
check(BackupVerifier::report_to_service($history)
	&& (new ShelfRun((int)$run->key, TRUE))->get('svr_verified_time'),
	'a passed verify is reported, and getjoinery stamps the run verified');

// ---------------------------------------------------------------------------
section('Never a delete; every refusal is a sentence');

try {
	S3Signer::delete($broker, (string)$target->get('bkt_bucket'), '/' . $manifest_key);
	check(false, 'getjoinery signs no delete');
} catch (S3SignerException $e) {
	check(true, 'getjoinery signs no delete');
}
check(TargetTester::test_service()['success'] === true, 'the Test button asks getjoinery, which is taking backups');
$idle = new ServicesShelfBroker($target, $client);
$probe = tempnam(sys_get_temp_dir(), 'sst');
file_put_contents($probe, 'x');
try {
	S3Signer::put_file($idle, (string)$target->get('bkt_bucket'), '/' . $idle->base() . 'site/stray', $probe);
	check(false, 'nothing is written outside a backup run');
} catch (S3SignerException $e) {
	check(!$idle->begun() && strpos($e->getMessage(), 'outside a backup run') !== false,
		'nothing is written outside a backup run, and no run is opened for it', $e->getMessage());
}
@unlink($probe);
$readiness = array();
foreach (BackupReadinessItems::items() as $item) {
	if (($item['key'] ?? '') === 'bucket_console_' . (int)$target->key) { $readiness = $item; }
}
check(strpos((string)($readiness['title'] ?? ''), 'getjoinery account') !== false && (string)($readiness['action_url'] ?? 'x') !== 'x',
	'Recovery Readiness asks for the getjoinery sign-in, the way back to these backups, not a bucket console',
	(string)($readiness['title'] ?? 'no item'));

$allowance_was = $row->get('svt_allowance');
ShelfBroker::refreshFigure($row);
$row->set('svt_allowance', max(1, ShelfObject::completedBytes((int)$row->key)));
$row->save();
$over = new ServicesShelfBroker($target, $client);
try {
	$over->begin();
	check(false, 'a run over the allowance is refused');
} catch (S3SignerException $e) {
	check(strpos($e->getMessage(), 'allowance') !== false, 'a run at the allowance is refused with getjoinery\'s sentence', $e->getMessage());
}
$test = TargetTester::test_service();
check($test['success'] === false && strpos($test['message'], 'full') !== false, 'and the Test button says it is full', $test['message']);
$row->set('svt_allowance', $allowance_was);
$row->set('svt_notice', null);
$row->save();

$target->set('bkt_path_prefix', 'somewhere-else');
$moved = new ServicesShelfBroker($target, $client);
try {
	$moved->begin();
	check(false, 'a storage place that moved is refused');
} catch (S3SignerException $e) {
	check(strpos($e->getMessage(), 'moved') !== false, 'a storage place that moved refuses the run, saying so', $e->getMessage());
}
check((string)$target->get('bkt_path_prefix') === $space->prefix_part()
	&& (string)Globalvars::get_instance()->get_setting('backup_service_folder') === $space->folder(),
	'and the new place is on record, so the next run goes there', (string)$target->get('bkt_path_prefix'));

// ---------------------------------------------------------------------------
section('The site\'s records follow what getjoinery holds');

check(BackupRunner::enforce_chain_retention($plan, $none, time() + 400 * 86400) === 0
	&& !(new BackupHistory($history->key, TRUE))->get('bkh_delete_time'),
	'a run getjoinery still holds stays listed, however old by this site\'s window');
foreach (s3fx_keys($fx) as $k) {
	if (strpos($k, 'shelf/' . $space->base() . 'site/' . (string)$history->get('bkh_chain_id') . '/') === 0) {
		S3Signer::delete(s3fx_creds($fx), 'shelf', '/' . substr($k, strlen('shelf/')));
	}
}
// Something else stays in the space, so the listing is not empty.
S3Signer::put_file(s3fx_creds($fx), 'shelf', '/' . $space->base() . 'site/chain-20200101_000000/manifest-0000.json', __FILE__);
check(BackupRunner::enforce_chain_retention($plan, $none, time() + 3 * 3600) === 1
	&& (new BackupHistory($history->key, TRUE))->get('bkh_pruned_time'),
	'once getjoinery has pruned a chain, its runs leave this site\'s list too');

// ---------------------------------------------------------------------------
section('Listings: the object store is the active space\'s; a partial answer concludes nothing');

/** A getjoinery that answers listings from a script: two spaces, 7 active, 6 moved away from. */
class SstScriptedClient {
	public $answer;
	public $asked = array();
	public function shelfList($prefix = '') {
		$this->asked[] = $prefix;
		$answer = $this->answer;
		$answer['objects'] = array_values(array_filter($answer['objects'], function ($o) use ($prefix) {
			return strpos((string)$o['key'], (string)$prefix) === 0;
		}));
		return $answer;
	}
	public function shelfSign($run_id, $name, $operation, $args = array()) {
		return array('url' => 'https://store.example/' . $name . '?space=' . (int)($args['space_id'] ?? 0));
	}
}
$scripted = new SstScriptedClient();
$scripted->answer = array('spaces' => array(array('space_id' => 7, 'state' => 'active'), array('space_id' => 6, 'state' => 'draining')),
	'objects' => array(
		array('key' => 'site/objects/e1/new', 'size' => 1, 'space_id' => 7),
		array('key' => 'site/objects/e1/old', 'size' => 1, 'space_id' => 6),
		array('key' => 'site/chain-20200101_000000/manifest-0000.json', 'size' => 1, 'space_id' => 6)));
$sb = new ServicesShelfBroker($target, $scripted);
$objs = array_column(S3Signer::list($sb, 'b', $sb->base() . 'site/objects/'), 'key');
check($objs === array($sb->base() . 'site/objects/e1/new'),
	'what the object store holds, as a run decides what to send again, is the active space\'s alone', json_encode($objs));
$all = array_column(S3Signer::list($sb, 'b', $sb->base() . 'site/'), 'key');
check(count($all) === 3, 'a chain or the whole site lists every space, so runs from before a move stay readable');
$sb2 = new ServicesShelfBroker($target, $scripted);
$link = $sb2->read_link('/' . $sb2->base() . 'site/objects/e1/old', 0);
check(strpos($link, 'space=6') !== false && count($scripted->asked) === 3,
	'a read of a key no listing has placed finds its space by listing its folder, once', $link);
$scripted->answer['spaces'][] = array('space_id' => 5, 'state' => 'draining', 'error' => 'This part of backup storage cannot be read right now.');
S3Signer::list($sb, 'b', $sb->base() . 'site/');
check(!$sb->listing_complete(), 'a space that did not answer leaves the listing marked incomplete');

$rplan = $plan;
$rplan['broker'] = $sb;
$old_row = new BackupHistory(NULL);
$old_row->set('bkh_type', 'project'); $old_row->set('bkh_outcome', 'success'); $old_row->set('bkh_slug', $plan['slug']);
$old_row->set('bkh_profile', 'site'); $old_row->set('bkh_destination', 'target'); $old_row->set('bkh_bkt_backup_target_id', (int)$target->key);
$old_row->set('bkh_target_name', 'x'); $old_row->set('bkh_chain_id', 'chain-20200101_000000'); $old_row->set('bkh_chain_seq', 0);
$old_row->set('bkh_start_time', '2020-01-01 00:00:00'); $old_row->set('bkh_upload_time', '2020-01-01 00:05:00');
$old_row->set('bkh_encrypted', true);
$old_row->set('bkh_artifacts', json_encode(array(array('name' => 'manifest-0000.json',
	'key' => 'an-old-prefix/' . $space->folder() . '/site/chain-20200101_000000/manifest-0000.json'))));
$old_row->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $old_row->key);
BackupRunner::enforce_chain_retention($rplan, $none, time());
check(!(new BackupHistory($old_row->key, TRUE))->get('bkh_delete_time'),
	'with a space unanswered, the reconcile marks nothing pruned');
array_pop($scripted->answer['spaces']);
BackupRunner::enforce_chain_retention($rplan, $none, time());
check(!(new BackupHistory($old_row->key, TRUE))->get('bkh_delete_time'),
	'a run recorded under the place getjoinery has since moved from is still the run getjoinery holds');
$scripted->answer['objects'] = array_values(array_filter($scripted->answer['objects'], function ($o) {
	return strpos($o['key'], 'chain-20200101_000000') === false;
}));
$scripted->asked = array();
BackupRunner::enforce_chain_retention($rplan, $none, time());
check(in_array('site/chain-20200101_000000/', $scripted->asked, true) && !preg_grep('#/objects#', $scripted->asked),
	'the reconcile asks about each point\'s own folder and never lists the offloaded-file store', json_encode($scripted->asked));
check((bool)(new BackupHistory($old_row->key, TRUE))->get('bkh_pruned_time'),
	'and once getjoinery no longer holds it, that same run leaves the list (the checks above are not vacuous)');

harness_finish();
