<?php
/** @joinery-test
 * name: sandbox_background_calls
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Signals and schedules through the fence (specs/sandboxed_php_plugins.md
 * WP6, design 7): a raised signal reaches the plugin's on_signal through the
 * delivery queue, and an approved schedule's run reaches its on_schedule,
 * each running the plugin's code, its asks reaching the API, each call in the
 * call log; the background slots bound them.
 *
 * What is pinned here, unfenced through the same PHP command line and, where
 * this box has the plugin's user, in the real fence:
 *  - a delivered signal runs on_signal with its payload, which saves a row
 *    of the plugin's own model, and is marked delivered;
 *  - a plugin that fails a signal leaves it to be retried;
 *  - a scheduled run runs on_schedule, which saves a row, and succeeds;
 *  - each call is logged with its kind, status and asks;
 *  - with both background slots taken, a call is busy at once, and a signal
 *    stays queued.
 *
 * Run: php tests/run.php db --filter=sandbox_background_calls
 *
 * @version 1.1 - reviewer2 L1-L4: a withdrawn signal ends unsent, a plugin's share of a pass, busy not counted, the wording
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
require_once(PathHelper::getIncludePath('tasks/SandboxScheduledRun.php'));
harness_boot();

$signal = 'account.signup';
$files = array('plugin.php' => <<<'PHP'
<?php
function on_signal(array $input) {
	if (($input['data']['fail'] ?? false) === true) {
		throw new RuntimeException('not today');
	}
	$e = new Entry();
	$e->set('what', 'signal ' . $input['signal'] . ' ' . (int)($input['data']['user_id'] ?? 0));
	$e->save();
	return array('saved' => $e->key);
}
function on_schedule(array $input) {
	$e = new Entry();
	$e->set('what', 'schedule ' . $input['schedule']);
	$e->save();
	return array('saved' => $e->key);
}
PHP);
$manifest = array(
	'signals' => array($signal),
	'schedules' => array(array('name' => 'nightly', 'frequency' => 'daily', 'time' => '03:00')),
	'models' => array(array('name' => 'Entry', 'fields' => array(array('name' => 'what', 'type' => 'string', 'max_length' => 200)))),
	'limits' => array('memory_mb' => 32, 'timeout_ms' => 8000),
);
$db = DbConnector::get_instance()->get_db_link();

$checks = function (string $name, string $label) use ($signal, $db) {
	$class = SandboxManifest::classFor($name, 'Entry');
	$prefix = SandboxManifest::prefixFor($name, 'Entry');
	$receivers = iterator_to_array(new MultiSignalReceiver(array('kind' => SignalReceiver::KIND_SANDBOX, 'plugin' => $name)), false);
	check(count($receivers) === 1, $label . ': it has a receiver for its approved signal');
	$last_delivery = function () use ($db, $receivers) {
		$q = $db->prepare('SELECT sgd_signal_delivery_id FROM sgd_signal_deliveries WHERE sgd_sgr_signal_receiver_id = ? ORDER BY sgd_signal_delivery_id DESC LIMIT 1');
		$q->execute(array((int)$receivers[0]->key));
		return new SignalDelivery((int)$q->fetchColumn(), TRUE);
	};
	SignalDeliveryQueue::reset_cache();
	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 4242));
	$delivery = $last_delivery();
	$outcome = SignalDeliveryQueue::attempt($delivery, $receivers[0], null);
	check($outcome['result'] === 'delivered' && ($outcome['message'] ?? '') === 'Delivered to the plugin.',
		$label . ': a raised signal is delivered to its on_signal', json_encode($outcome));
	$multi = "Multi" . $class;
	$rows = iterator_to_array(new $multi(array("what" => "signal " . $signal . " 4242")), false);
	check(count($rows) === 1, $label . ': which saved a row of its own model through the API, with the payload', json_encode(count($rows)));

	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 1, 'fail' => true));
	$outcome = SignalDeliveryQueue::attempt($last_delivery(), $receivers[0], null);
	check($outcome['result'] === 'retrying', $label . ': a signal the plugin fails is kept to retry', json_encode($outcome));

	$tasks = iterator_to_array(new MultiScheduledTask(array('plugin_name' => $name)), false);
	check(count($tasks) === 1, $label . ': its approved schedule is a scheduled run');
	$run = (new SandboxScheduledRun())->run($tasks[0]->get_task_config());
	$rows = iterator_to_array(new $multi(array('what' => 'schedule nightly')), false);
	check($run['status'] === 'success' && count($rows) === 1, $label . ': the run calls on_schedule, which saved a row', json_encode($run));

	$q = $db->prepare('SELECT sbl_kind, sbl_status, sbl_calls FROM sbl_sandbox_logs WHERE sbl_plugin = ? ORDER BY sbl_sandbox_log_id');
	$q->execute(array($name));
	$logs = $q->fetchAll(PDO::FETCH_ASSOC);
	$kinds = array_map(function ($l) { return $l['sbl_kind'] . ':' . $l['sbl_status']; }, $logs);
	check($kinds === array('signal:ok', 'signal:error', 'schedule:ok'), $label . ': each call is in the call log, by kind and outcome', json_encode($kinds));
	$asks = json_decode((string)($logs[0]['sbl_calls'] ?? ''), true) ?: array();
	check(($asks[0]['door'] ?? '') === 'api' && strpos((string)($asks[0]['target'] ?? ''), 'POST ' . $class) === 0, $label . ': with the asks it made', json_encode($asks));

	// Both background slots taken: a call is busy at once.
	$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/cache/sandbox';
	$held = array();
	for ($i = 0; $i < SandboxHost::BACKGROUND_SLOTS; $i++) {
		$fh = fopen($dir . '/bg_slot_' . $i . '.lock', 'c');
		flock($fh, LOCK_EX);
		$held[] = $fh;
	}
	$started = microtime(true);
	$busy = SandboxHost::schedule($name, 'nightly');
	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 2));
	$waiting = $last_delivery();
	$outcome = SignalDeliveryQueue::attempt($waiting, $receivers[0], null);
	foreach ($held as $fh) {
		flock($fh, LOCK_UN);
		fclose($fh);
	}
	check($busy['status'] === SandboxLog::STATUS_BUSY && (microtime(true) - $started) < 2, $label . ': with both background slots taken, a call is busy at once', json_encode($busy['status']));
	check($outcome['result'] === 'retrying' && (int)(new SignalDelivery((int)$waiting->key, TRUE))->get('sgd_attempts') === 0,
		$label . ': and a signal waits to be tried again, the try not counted (its code never ran)', json_encode($outcome));
};

try {
	section('Unfenced, the same PHP command line');
	$p = sandbox_fixture_plugin($manifest, array('files' => $files));
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$checks($name, 'Unfenced');
	SandboxRunner::$command_override = null;

	section('A signal no longer approved, and a slow plugin\'s share of a pass (reviewer2 L1, L2)');
	$receiver = iterator_to_array(new MultiSignalReceiver(array('kind' => SignalReceiver::KIND_SANDBOX, 'plugin' => $name)), false)[0];
	SignalDeliveryQueue::reset_cache();
	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 77));
	$q = $db->prepare('SELECT sgd_signal_delivery_id FROM sgd_signal_deliveries WHERE sgd_sgr_signal_receiver_id = ? ORDER BY sgd_signal_delivery_id DESC LIMIT 1');
	$q->execute(array((int)$receiver->key));
	$queued = new SignalDelivery((int)$q->fetchColumn(), TRUE);
	// The plugin's manifest no longer asks for the signal: what it may do is
	// what it asks for AND was approved.
	$data = json_decode((string)file_get_contents($p['dir'] . '/manifest.json'), true);
	$data['signals'] = array('account.updated');
	file_put_contents($p['dir'] . '/manifest.json', json_encode($data));
	SandboxPlugins::reset();
	$calls_before = (int)$db->query("SELECT COUNT(*) FROM sbl_sandbox_logs WHERE sbl_plugin = " . $db->quote($name))->fetchColumn();
	$outcome = SignalDeliveryQueue::attempt($queued, $receiver, null);
	$calls_after = (int)$db->query("SELECT COUNT(*) FROM sbl_sandbox_logs WHERE sbl_plugin = " . $db->quote($name))->fetchColumn();
	check($outcome['result'] === 'given_up' && strpos($outcome['message'], 'no longer approved') !== false && $calls_after === $calls_before,
		'A queued signal the plugin is no longer approved for ends unsent, with no call', json_encode($outcome));
	$data['signals'] = array($signal);
	file_put_contents($p['dir'] . '/manifest.json', json_encode($data));
	SandboxPlugins::reset();
	$host = sandbox_stand_in_runner(array('on_signal' => array('ok' => true, 'output' => null, 'calls' => array())));
	$sleepy = SandboxHost::$runner;
	SandboxHost::$runner = function (array $envelope) use ($sleepy) {
		usleep(600000);
		return call_user_func($sleepy, $envelope);
	};
	$saved_share = SignalDeliveryQueue::$sandbox_share_seconds;
	SignalDeliveryQueue::$sandbox_share_seconds = 1;
	for ($i = 0; $i < 4; $i++) {
		SignalDeliveryQueue::handle_signal($signal, array('user_id' => 100 + $i));
	}
	try {
		$counts = SignalDeliveryQueue::drain(null, 30);
	} finally {
		SignalDeliveryQueue::$sandbox_share_seconds = $saved_share;
		SandboxHost::$runner = null;
	}
	check($counts['delivered'] === 2 && $counts['left'] >= 2, 'A plugin past its share of a pass leaves its other rows for the next', json_encode($counts));

	// A plugin this server cannot run: uncounted tries still end, once the
	// row is older than the whole counted schedule.
	SignalDeliveryQueue::handle_signal($signal, array('user_id' => 500));
	$q->execute(array((int)$receiver->key));
	$old = new SignalDelivery((int)$q->fetchColumn(), TRUE);
	SignalDelivery::updateColumns((int)$old->key, array('sgd_create_time' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)));
	$old = new SignalDelivery((int)$old->key, TRUE);
	SandboxHost::$runner = function () { return array('unavailable' => 'no fence here'); };
	try {
		$outcome = SignalDeliveryQueue::attempt($old, $receiver, null);
	} finally {
		SandboxHost::$runner = null;
	}
	check($outcome['result'] === 'given_up' && strpos($outcome['message'], 'could not be run here') !== false,
		'Uncounted tries end once the row is older than the counted schedule', json_encode($outcome));

	section('In the fence');
	$fence = 'sbxbackground';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced signal and schedule', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => $files, 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced signal and schedule', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence, false);
			sandbox_approve($fence);
			$pm->activate($fence);
			SandboxPlugins::reset();
			$checks($fence, 'Fenced');
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
