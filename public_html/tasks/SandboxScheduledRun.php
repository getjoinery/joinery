<?php
/**
 * SandboxScheduledRun — one schedule of one sandboxed plugin (door 3).
 *
 * A sandboxed plugin declares its schedules in its manifest; once an admin
 * approves them, SandboxPluginManager makes one row of this task per
 * schedule, tagged with the plugin's name (so it is suspended, resumed and
 * removed with the plugin) and configured {plugin, schedule}. Each run asks
 * the plugin host to call the module's on_schedule (SandboxHost::schedule()),
 * which records what happened in the plugin's call log.
 *
 * @version 1.1 - with the plugin host not answering yet (it starts within a minute of activation),
 *                a run is retried on the next tick instead of being spent as an error
 */

class SandboxScheduledRun implements ScheduledTaskInterface {
	public function run(array $config) {
		$plugin = (string)($config['plugin'] ?? '');
		$schedule = (string)($config['schedule'] ?? '');
		if ($plugin === '' || $schedule === '') {
			return array('status' => 'skipped', 'message' => 'No plugin or schedule configured.');
		}
		if (!SandboxPlugins::isActive($plugin)) {
			return array('status' => 'skipped', 'message' => "The plugin $plugin is not active.");
		}
		if (!isset(SandboxConsent::effective($plugin)['schedules'][$schedule])) {
			return array('status' => 'skipped', 'message' => "The schedule $schedule is not approved.");
		}
		// The host starts within a minute of the first sandboxed plugin; a run
		// due before then waits for it rather than being spent.
		if (!SandboxHost::isRunning()) {
			return array('status' => 'retry', 'message' => "The plugin host is not running yet; $plugin: $schedule runs once it is.");
		}
		$result = SandboxHost::schedule($plugin, $schedule);
		if ($result['status'] === SandboxLog::STATUS_OK) {
			return array('status' => 'success', 'message' => "$plugin: $schedule ran");
		}
		return array('status' => 'error', 'message' => "$plugin: $schedule: " . ($result['message'] !== '' ? $result['message'] : $result['status']));
	}
}
