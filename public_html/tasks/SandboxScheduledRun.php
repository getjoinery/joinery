<?php
/**
 * SandboxScheduledRun — one schedule of one sandboxed plugin (door 3).
 *
 * A sandboxed plugin declares its schedules in its manifest; once an admin
 * approves them, SandboxPluginManager makes one row of this task per
 * schedule, tagged with the plugin's name (so it is suspended, resumed and
 * removed with the plugin) and configured {plugin, schedule}. Each run makes
 * the plugin's on_schedule call (SandboxHost::schedule()), which records what
 * happened in the plugin's call log. A call nothing could run is an error.
 *
 * @version 1.2 - a run takes the call's answer; there is no host to wait for
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
		$result = SandboxHost::schedule($plugin, $schedule);
		if ($result['status'] === SandboxLog::STATUS_OK) {
			return array('status' => 'success', 'message' => "$plugin: $schedule ran");
		}
		return array('status' => 'error', 'message' => "$plugin: $schedule: " . ($result['message'] !== '' ? $result['message'] : $result['status']));
	}
}
