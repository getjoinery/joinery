<?php
/** @joinery-test
 * name: sandbox_consent
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Approval, credentials, signals and schedules for sandboxed plugins
 * (plugin_tiers.md WP5), against a stand-in host.
 *
 * What is pinned here:
 *  - a plugin needing an action, a model, a signal or a platform this site
 *    does not have is refused, naming each;
 *  - activation is refused until an admin approves what it asks for; the
 *    approval page lists it in plain words and approves and activates in one;
 *  - activation mints a key scoped to exactly what was approved plus every
 *    verb on its own models, owned by the approver, loopback only, whose
 *    sealed secret the host is handed and which authenticates;
 *  - a receiver carries the approved signals, and a raised signal reaches the
 *    module through the queue with its payload; a module failure is retried;
 *  - each approved schedule is a scheduled run, never offered for activation
 *    by hand, which calls the module's on_schedule;
 *  - a newer manifest asking for more changes nothing until approved again
 *    (the host is handed only approved hosts), then the key is minted anew;
 *  - deactivation switches the key, receiver and runs off; reactivation needs
 *    no new approval; the approver's account is held while the key is live,
 *    and the key cannot be deleted or reset from the key pages;
 *  - uninstall removes the key, the approval, the receiver and the runs.
 *
 * Run: php tests/run.php db --filter=sandbox_consent
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();

$sign_in = function (User $user) {
	// A session started later (FormWriter starts one) would replace what is
	// set here, so it is started first.
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	$_SESSION['saved_messages'] = array();
};
$reset = function () {
	SandboxPlugins::reset();
	SandboxModels::reset();
	SettingsDeclarations::reset();
	SignalDeliveryQueue::reset_cache();
};
// Approve as the signed-in admin, posting back what the page showed.
$approve = function (string $plugin) {
	$shown = harness_call_logic('adm/logic/admin_sandbox_approve_logic.php', 'admin_sandbox_approve_logic', array('plugin' => $plugin), 'GET');
	return harness_call_logic('adm/logic/admin_sandbox_approve_logic.php', 'admin_sandbox_approve_logic',
		array('plugin' => $plugin, 'action' => 'approve', 'reviewed' => (string)($shown->data['reviewed'] ?? '')), 'POST');
};
$rewrite = function (string $dir, array $changes) {
	$manifest = json_decode(file_get_contents($dir . '/manifest.json'), true);
	file_put_contents($dir . '/manifest.json', json_encode(array_replace($manifest, $changes), JSON_PRETTY_PRINT));
};

// What this site has, to ask for: a read action a machine key may call, a
// core model the API reads, and a core signal.
$action = null;
foreach (ApiLogicEndpoint::actionCatalog() as $name => $entry) {
	if (strpos($name, '/') === false && ApiLogicEndpoint::machineKeyCanCall($entry['meta'])
			&& ApiLogicEndpoint::actionCapability($entry['meta']) === 'read') {
		$action = $name;
		break;
	}
}
$model = null;
foreach (LibraryFunctions::discover_model_classes(array('include_plugins' => false)) as $class) {
	if ($class::$api_readable) {
		$model = $class;
		break;
	}
}
$signal = 'account.signup';

try {
	section('What this site has');
	check($action !== null && $model !== null && isset(SignalBus::signals()[$signal]),
		'The site has an action, a model and a signal to ask for', "$action / $model / $signal");

	$p = sandbox_fixture_plugin(array(
		'permissions' => array('actions' => array($action), 'records' => array($model => array('read'))),
		'signals' => array($signal),
		'schedules' => array(array('name' => 'digest', 'frequency' => 'daily', 'time' => '08:30')),
		'outbound_hosts' => array('hooks.example.com'),
		'models' => array(array('name' => 'Sent', 'fields' => array(array('name' => 'note', 'type' => 'text')))),
	));
	$name = $p['name'];
	$pm = PluginManager::getInstance();

	section('Compatibility (Q13)');
	$m = SandboxManifest::fromArray(array('name' => $name, 'title' => 'x', 'version' => '1.0.0', 'platform' => SandboxManifest::PLATFORM_VERSION + 1,
		'permissions' => array('actions' => array('no_such_action_x'), 'records' => array('NoSuchModelX' => array('read'))),
		'signals' => array('no.such_signal')));
	$problems = SandboxCompatibility::problems($m);
	$joined = implode(' | ', $problems);
	check(count($problems) === 4, 'A plugin for a later platform, needing a missing action, model and signal, is refused four times over', $joined);
	check(strpos($joined, 'platform version') !== false && strpos($joined, 'no_such_action_x') !== false
		&& strpos($joined, 'NoSuchModelX') !== false && strpos($joined, 'no.such_signal') !== false, 'naming each', $joined);
	check(SandboxCompatibility::problems(SandboxPlugins::manifest($name)) === array(), 'The fixture asks only for what the site has');

	// Core checks a native plugin by name, meaning its PHP is here: a
	// sandboxed plugin may never hold one of those names.
	$native = '';
	foreach (glob(PathHelper::getIncludePath('plugins') . '/*/plugin.json') ?: array() as $json) {
		if (preg_match(SandboxManifest::NAME_PATTERN, basename(dirname($json)))) {
			$native = basename(dirname($json));
			break;
		}
	}
	if ($native === '') {
		harness_skip('A native plugin\'s name is refused', 'no native plugin on disk here');
	} else {
		$taken = SandboxCompatibility::problems(SandboxManifest::fromArray(array('name' => $native, 'title' => 'x', 'version' => '1.0.0',
			'platform' => SandboxManifest::PLATFORM_VERSION)));
		check(count(preg_grep('~is a Joinery plugin~', $taken)) === 1, "A sandboxed plugin named $native, a native plugin's name, is refused",
			implode(' | ', $taken));
	}
	$catalog = MarketplaceClient::published_names('plugins');
	check($catalog === null || array_diff($catalog, SandboxCompatibility::nativePluginNames()) === array(),
		'and so is every name in the publisher\'s catalog, as far as this site knows it');

	section('Activation waits on an approval');
	$pm->install($name, false);
	$reset();
	check(SandboxConsent::needsApproval($name), 'An installed plugin waits on an approval');
	$refused = null;
	try {
		$pm->activate($name);
	} catch (SandboxApprovalNeededException $e) {
		$refused = $e->getMessage();
	}
	$reset();
	check($refused !== null && strpos($refused, SandboxPluginManager::approvalUrl($name)) !== false,
		'Activation is refused, pointing at the approval page', (string)$refused);
	check(!SandboxPlugins::isActive($name) && !PluginHelper::isActiveOfEitherKind($name), 'and it stays inactive');

	$described = SandboxConsent::describe(SandboxConsent::grantsOf(SandboxPlugins::manifest($name)));
	$headings = array_keys($described);
	check(in_array('Run these site actions', $headings, true) && in_array('Be told when these happen', $headings, true)
		&& in_array('Run on a schedule', $headings, true) && in_array('Send data to these websites', $headings, true)
		&& count(preg_grep('~^Records it may use: ~', $headings)) === 1, 'What it asks is described in groups', json_encode($headings));
	check(in_array('digest: every day at 08:30', $described['Run on a schedule'], true), 'in plain words', json_encode($described['Run on a schedule']));
	$records = current(preg_grep('~^Records it may use: ~', array_keys($described)) ?: array(''));
	check($records !== '' && strpos(implode(' ', $described[$records]), 'All ') === 0, 'a record grant saying it reaches every record', json_encode($described[$records] ?? null));

	section('The approval page');
	$superadmin = make_user('SbxSuper', 10);
	$sign_in($superadmin);
	$page = 'adm/logic/admin_sandbox_approve_logic.php';
	$result = harness_call_logic($page, 'admin_sandbox_approve_logic', array('plugin' => $name), 'GET');
	check(!$result->error && !empty($result->data['asked']) && $result->data['needs_approval'] && !$result->data['problems'],
		'It shows what the plugin asks for', (string)$result->error);
	$result = harness_call_logic($page, 'admin_sandbox_approve_logic', array('plugin' => 'nosuchplugin'), 'GET');
	check((bool)$result->error, 'and refuses a name that is no sandboxed plugin');
	$stale = harness_call_logic($page, 'admin_sandbox_approve_logic', array('plugin' => $name, 'action' => 'approve', 'reviewed' => str_repeat('0', 64)), 'POST');
	$reset();
	check(!SandboxPlugins::isActive($name) && SandboxConsent::needsApproval($name),
		'An approval of something other than what the page showed is refused, and nothing is approved');
	$result = $approve($name);
	harness_defer(function () use ($name) {
		try {
			SandboxCredentials::revoke($name);
		} catch (Throwable $e) {
		}
	});
	$reset();
	check($result->redirect === '/admin/admin_plugins', 'Approving returns to the Plugins page', (string)$result->error . ' ' . json_encode($_SESSION['saved_messages'] ?? null));
	check(SandboxPlugins::isActive($name), 'and the plugin is active');
	check(PluginHelper::isActiveOfEitherKind($name), 'as the question about a plugin of either kind says too');
	check(!PluginHelper::isPluginActive($name), 'while a check for a native plugin by that name still says no: there is no PHP to use');
	check(!SandboxConsent::needsApproval($name), 'with nothing left to approve');

	section('Its credential');
	$install = SandboxInstall::forPlugin($name);
	$key = new ApiKey((int)$install->get('sbi_apk_api_key_id'), TRUE);
	$own = SandboxManifest::classFor($name, 'Sent');
	$expected = array($action, $model . ':read');
	foreach (ApiKey::RECORD_VERBS as $verb) {
		$expected[] = $own . ':' . $verb;
	}
	$scope = $key->scope();
	sort($scope);
	sort($expected);
	check($key->key && $scope === $expected, 'A key scoped to exactly what was approved and its own models', json_encode($scope));
	check((int)$key->get('apk_usr_user_id') === (int)$superadmin->key, 'owned by the approver');
	check($key->get('apk_type') === ApiKey::TYPE_MACHINE && $key->get('apk_ip_restriction') === '127.0.0.1,::1' && $key->get('apk_is_active'),
		'a machine key, from this machine only, active');
	check($key->sandbox_plugin() === $name, 'that knows it is the plugin\'s');
	$raw = (string)$install->get('sbi_api_secret');
	check(SecretBox::looksEncrypted($raw), 'Its secret is stored sealed');
	$credential = SandboxInstall::credentialFor($name);
	check($credential !== null && $credential['public_key'] === $key->get('apk_public_key')
		&& $key->check_secret_key($credential['secret_key']), 'and opens to the secret the key checks');

	section('Its signals');
	$receivers = iterator_to_array(new MultiSignalReceiver(array('kind' => SignalReceiver::KIND_SANDBOX, 'plugin' => $name)), false);
	check(count($receivers) === 1 && $receivers[0]->signals() === array($signal) && $receivers[0]->get('sgr_is_active'),
		'One receiver carries the approved signal');
	$host = fake_sandbox_host_start(array('on_signal' => array('ok' => true, 'output' => null, 'duration_ms' => 3, 'calls' => array())));
	SignalDeliveryQueue::reset_cache();
	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 7, 'email' => 'x@example.com'));
	$q = $db->prepare('SELECT sgd_signal_delivery_id FROM sgd_signal_deliveries WHERE sgd_sgr_signal_receiver_id = ? ORDER BY sgd_signal_delivery_id DESC LIMIT 1');
	$q->execute(array((int)$receivers[0]->key));
	$delivery = new SignalDelivery((int)$q->fetchColumn(), TRUE);
	check($delivery->key && $delivery->get('sgd_status') === SignalDelivery::STATUS_PENDING, 'A raised signal is queued for it');
	$outcome = SignalDeliveryQueue::attempt($delivery, $receivers[0], null);
	$requests = $host['requests']();
	$sent = end($requests);
	check($outcome['result'] === 'delivered', 'and handed to the module', json_encode($outcome));
	check(($sent['export'] ?? '') === 'on_signal' && ($sent['input']['signal'] ?? '') === $signal
		&& ($sent['input']['data']['user_id'] ?? null) === 7, 'as on_signal, with its payload', json_encode($sent['input'] ?? null));
	check(($sent['outbound_hosts'] ?? null) === array('hooks.example.com') && !empty($sent['api']['secret_key'])
		&& $sent['api']['secret_key'] === $credential['secret_key'], 'with the approved hosts and its credential');

	$host['set_replies'](array('on_signal' => array('ok' => false, 'error' => 'module trapped', 'duration_ms' => 2, 'calls' => array())));
	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 8));
	$q->execute(array((int)$receivers[0]->key));
	$delivery = new SignalDelivery((int)$q->fetchColumn(), TRUE);
	$outcome = SignalDeliveryQueue::attempt($delivery, $receivers[0], null);
	$delivery = new SignalDelivery((int)$delivery->key, TRUE);
	check($outcome['result'] === 'retrying' && strpos((string)$delivery->get('sgd_last_error'), 'module trapped') !== false,
		'A module failure is retried, and says why', (string)$delivery->get('sgd_last_error'));
	check(empty($outcome['unreachable']), 'and the plugin is tried again in the same pass');
	$host['set_replies'](array('on_signal' => array('ok' => false, 'timed_out' => true, 'error' => 'too slow', 'duration_ms' => 5000, 'calls' => array())));
	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 9));
	$q->execute(array((int)$receivers[0]->key));
	$delivery = new SignalDelivery((int)$q->fetchColumn(), TRUE);
	$outcome = SignalDeliveryQueue::attempt($delivery, $receivers[0], null);
	check($outcome['result'] === 'retrying' && !empty($outcome['unreachable']),
		'A plugin that times out is not tried again this pass, so it cannot spend the drain for everyone', json_encode($outcome));

	section('Its schedules');
	$tasks = iterator_to_array(new MultiScheduledTask(array('plugin_name' => $name, 'deleted' => false)), false);
	check(count($tasks) === 1 && $tasks[0]->get('sct_task_class') === 'SandboxScheduledRun'
		&& $tasks[0]->get('sct_frequency') === 'daily' && substr((string)$tasks[0]->get('sct_schedule_time'), 0, 5) === '08:30'
		&& $tasks[0]->get_task_config() === array('plugin' => $name, 'schedule' => 'digest') && $tasks[0]->get('sct_is_active'),
		'Each approved schedule is a run at its time', count($tasks) ? json_encode($tasks[0]->get_task_config()) : 'none');
	$listing = harness_call_logic('adm/logic/admin_scheduled_tasks_logic.php', 'admin_scheduled_tasks_logic', array(), 'GET');
	check(!isset($listing->data['available_tasks']['SandboxScheduledRun']), 'never offered for activation by hand');
	$host['set_replies'](array('on_schedule' => array('ok' => true, 'output' => null, 'duration_ms' => 1, 'calls' => array())));
	$run = (new SandboxScheduledRun())->run($tasks[0]->get_task_config());
	$requests = $host['requests']();
	$sent = end($requests);
	check($run['status'] === 'success' && ($sent['export'] ?? '') === 'on_schedule' && ($sent['input']['schedule'] ?? '') === 'digest',
		'A run calls on_schedule', json_encode($run));
	$run = (new SandboxScheduledRun())->run(array('plugin' => $name, 'schedule' => 'not_approved'));
	check($run['status'] === 'skipped', 'and runs nothing it was not approved for');

	section('A newer version asking for more');
	$old_key = (int)$key->key;
	$rewrite($p['dir'], array('version' => '1.1.0', 'outbound_hosts' => array('hooks.example.com', 'more.example.com'),
		'profile_pages' => array(array('slug' => 'ask', 'title' => 'Tell us', 'sections' => array(
			array('type' => 'form', 'handler' => 'ask', 'fields' => array(array('name' => 'secret', 'label' => 'Your password', 'type' => 'text')))))),
		'models' => array(array('name' => 'Sent', 'fields' => array(array('name' => 'note', 'type' => 'text'))),
			array('name' => 'Extra', 'fields' => array(array('name' => 'v', 'type' => 'integer'))))));
	$reset();
	$pm->install($name, false);
	$reset();
	check(SandboxPlugins::isActive($name), 'Replacing its files leaves it active');
	check(SandboxConsent::needsApproval($name) && SandboxConsent::pending($name) === array('outbound_hosts' => array('more.example.com'),
		'member_pages' => array('ask' => 'Tell us (with a form members fill in)')),
		'waiting on approval of exactly what is new: a host, and a page asking members for something', json_encode(SandboxConsent::pending($name)));
	$member_page = harness_call_logic('logic/sandbox_page_logic.php', 'sandbox_page_logic', array('plugin' => $name, 'page' => 'ask'), 'GET');
	check((bool)$member_page->error, 'The new member page is not there until approved');
	$envelope = SandboxHost::envelope($name, 'on_ui', array());
	check($envelope['outbound_hosts'] === array('hooks.example.com'), 'The host is handed only what was approved', json_encode($envelope['outbound_hosts']));
	$key = new ApiKey((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id'), TRUE);
	check(in_array(SandboxManifest::classFor($name, 'Extra') . ':create', $key->scope(), true), 'Its new model is its own at once');
	$result = harness_call_logic($page, 'admin_sandbox_approve_logic', array('plugin' => $name), 'GET');
	check(!empty($result->data['new']['Send data to these websites']) && $result->data['new']['Send data to these websites'] === array('more.example.com'),
		'The approval page names what is new', json_encode($result->data['new'] ?? null));
	$approve($name);
	$reset();
	check(!SandboxConsent::needsApproval($name) && SandboxHost::envelope($name, 'on_ui', array())['outbound_hosts'] === array('hooks.example.com', 'more.example.com'),
		'Approved, it has the new host');
	$member_page = harness_call_logic('logic/sandbox_page_logic.php', 'sandbox_page_logic', array('plugin' => $name, 'page' => 'ask'), 'GET');
	check(!$member_page->error, 'and its member page', (string)$member_page->error);
	check((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id') !== $old_key
		&& !(new ApiKey($old_key, TRUE))->key, 'and a new key, the old one gone');

	section('Deactivation and reactivation');
	$pm->deactivate($name);
	$reset();
	$key = new ApiKey((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id'), TRUE);
	$receiver = new SignalReceiver((int)$receivers[0]->key, TRUE);
	$task = new ScheduledTask((int)$tasks[0]->key, TRUE);
	check(!$key->get('apk_is_active') && !$receiver->get('sgr_is_active') && !$task->get('sct_is_active'),
		'Deactivating switches its key, receiver and run off');
	check(SandboxInstall::credentialFor($name) === null, 'and the host gets no credential');
	$pm->activate($name);
	$reset();
	$receiver = new SignalReceiver((int)$receivers[0]->key, TRUE);
	check(SandboxPlugins::isActive($name) && SandboxInstall::credentialFor($name) !== null && $receiver->get('sgr_is_active'),
		'Reactivating needs no new approval, and switches them on');

	section('The approver and the key pages');
	$key_id = (int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id');
	foreach (array('permanent_delete', 'regenerate_secret', 'soft_delete') as $act) {
		$result = harness_call_logic('adm/logic/admin_api_key_logic.php', 'admin_api_key_logic', array('apk_api_key_id' => $key_id, 'action' => $act), 'POST');
		check((bool)$result->error && (new ApiKey($key_id, TRUE))->key && !(new ApiKey($key_id, TRUE))->get('apk_delete_time'),
			"The key pages refuse $act on it");
	}
	$second = make_user('SbxSecond', 10);
	$sign_in($second);
	$result = harness_call_logic($page, 'admin_sandbox_approve_logic', array('plugin' => $name), 'GET');
	check(!$result->data['needs_approval'] && (int)$result->data['approved_by']->key === (int)$superadmin->key, 'Another admin sees whose approval stands');
	$approve($name);
	$reset();
	$key = new ApiKey((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id'), TRUE);
	check((int)$key->get('apk_usr_user_id') === (int)$second->key, 'and approving it themselves moves the key to them');

	section('An approver who can no longer approve');
	$demoted = new User((int)$second->key, TRUE);
	$demoted->set('usr_permission', 8);
	$demoted->save();
	$reset();
	$key = new ApiKey((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id'), TRUE);
	check(!$key->get('apk_is_active') && SandboxInstall::credentialFor($name) === null,
		'Demoting the approving admin switches the plugin\'s key off at once');
	check(SandboxConsent::needsApproval($name), 'and the plugin waits on another admin\'s approval');
	$sign_in($superadmin);
	$approve($name);
	$reset();
	$key = new ApiKey((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id'), TRUE);
	check($key->get('apk_is_active') && (int)$key->get('apk_usr_user_id') === (int)$superadmin->key && !SandboxConsent::needsApproval($name),
		'which mints a key as them');

	section('An approver who is deleted');
	$third = make_user('SbxThird', 10);
	$sign_in($third);
	$approve($name);
	$reset();
	$key_id = (int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id');
	$sign_in($superadmin);
	$third->soft_delete();
	$reset();
	check(!(new ApiKey($key_id, TRUE))->get('apk_is_active') && SandboxInstall::credentialFor($name) === null,
		'Deleting the approving admin is not refused: the plugin\'s key switches off at once, as on a demotion');
	check(SandboxConsent::needsApproval($name), 'and the plugin waits on another admin\'s approval');
	$fourth = make_user('SbxFourth', 10);
	$sign_in($fourth);
	$approve($name);
	$reset();
	$key_id = (int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id');
	$sign_in($superadmin);
	$fourth->permanent_delete();
	$reset();
	$row = SandboxInstall::forPlugin($name);
	check(!(new ApiKey($key_id, TRUE))->key && !$row->get('sbi_apk_api_key_id') && !$row->get('sbi_usr_user_id'),
		'Deleting them for good takes the key with the account, and the approval names nobody');
	check(SandboxConsent::needsApproval($name) && SandboxInstall::credentialFor($name) === null, 'and the plugin waits on approval');
	$approve($name);
	$reset();
	$key = new ApiKey((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id'), TRUE);
	check($key->get('apk_is_active') && (int)$key->get('apk_usr_user_id') === (int)$superadmin->key, 'which another admin gives as themselves');
	$machine = make_machine_key((int)$third->key, 'SbxOtherMachine', 1);
	$machine['api_key']->set('apk_scope', $action);
	$machine['api_key']->save();
	$held = null;
	try {
		(new User((int)$third->key, TRUE))->permanent_delete();
	} catch (Throwable $e) {
		$held = $e->getMessage();
	}
	check($held !== null && strpos($held, 'SbxOtherMachine') !== false,
		'A scoped key another machine holds still keeps its owner\'s account until it is deleted', (string)$held);
	$machine['api_key']->permanent_delete();

	section('Uninstall');
	$pm->deactivate($name);
	$key_id = (int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id');
	$pm->uninstall($name);
	$reset();
	check(SandboxInstall::forPlugin($name) === null && !(new ApiKey($key_id, TRUE))->key, 'Uninstall removes its key and approval');
	check(iterator_to_array(new MultiSignalReceiver(array('kind' => SignalReceiver::KIND_SANDBOX, 'plugin' => $name)), false) === array(),
		'its receiver');
	check(iterator_to_array(new MultiScheduledTask(array('plugin_name' => $name)), false) === array(), 'and its runs');
} catch (Throwable $e) {
	check(false, 'No exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

harness_finish();
