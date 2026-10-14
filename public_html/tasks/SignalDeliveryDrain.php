<?php
/**
 * SignalDeliveryDrain — sends what SignalDeliveryQueue queued.
 *
 * The scheduler entry point for SignalDeliveryQueue::drain(): every minute,
 * send each due delivery (to webhooks, and to sandboxed plugins through the
 * plugin host), and schedule the next attempt for any that failed. See
 * docs/webhooks.md and docs/sandboxed_plugins.md.
 *
 * @version 1.1 - also delivers to sandboxed plugins
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class SignalDeliveryDrain implements ScheduledTaskInterface {
	public function run(array $config) {
		$counts = SignalDeliveryQueue::drain();
		if ($counts['attempted'] === 0 && $counts['left'] === 0) {
			return array('status' => 'success', 'message' => 'Nothing due');
		}
		$parts = array($counts['delivered'] . ' delivered');
		if ($counts['retrying'] > 0) {
			$parts[] = $counts['retrying'] . ' failed, will retry';
		}
		if ($counts['given_up'] > 0) {
			$parts[] = $counts['given_up'] . ' given up';
		}
		if ($counts['left'] > 0) {
			$parts[] = $counts['left'] . ' left for the next run';
		}
		return array('status' => 'success', 'message' => implode(', ', $parts));
	}
}
