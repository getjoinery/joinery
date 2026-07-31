<?php
/**
 * ReconcileSubscriptions - Scheduled Task
 *
 * Periodic backstop for every subscription provider the store has enabled.
 *
 * Provider webhooks are the authoritative real-time path; this catches anything
 * a webhook missed — a cancellation that never arrived, a status change that
 * was dropped. Each provider reconciles independently, so a Stripe API outage
 * cannot leave PayPal subscriptions unchecked, and a third provider costs a
 * reconciler class rather than another scheduled task.
 *
 * Dry run previews every enabled provider in one view.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ReconcileSubscriptions implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

	/** Provider label => [file, class]. A new provider is one line here. */
	private function providers() {
		$base = 'plugins/store/includes/subscriptions/';
		return array(
			'Stripe' => array($base . 'StripeSubscriptionReconciler.php', 'StripeSubscriptionReconciler'),
			'PayPal' => array($base . 'PaypalSubscriptionReconciler.php', 'PaypalSubscriptionReconciler'),
		);
	}

	public function run(array $config) {
		return $this->each($config, 'run');
	}

	public function dryRun(array $config) {
		$result = $this->each($config, 'dryRun');
		if (!empty($result['html'])) {
			return $result;
		}
		return $result;
	}

	/**
	 * Run one method across every provider, isolating failures.
	 *
	 * A provider that is not configured returns 'skipped' and contributes
	 * nothing; if every provider skips, so does the task, because "no payment
	 * provider is set up" is not an error worth alerting on.
	 */
	private function each(array $config, $method) {
		$parts = array();
		$html = '';
		$failed = 0;
		$active = 0;

		foreach ($this->providers() as $label => $provider) {
			list($file, $class) = $provider;
			try {
				require_once(PathHelper::getIncludePath($file));
				$runner = new $class();
				if (!method_exists($runner, $method)) {
					continue; // e.g. a provider with no dry-run preview
				}
				$result = $runner->$method($config);

				$status = $result['status'] ?? 'error';
				if ($status === 'skipped') {
					continue;
				}
				$active++;
				if ($status === 'error') {
					$failed++;
				}
				$parts[] = $label . ': ' . ($result['message'] ?? $status);
				if (!empty($result['html'])) {
					$html .= '<h4>' . htmlspecialchars($label) . '</h4>' . $result['html'];
				}
			} catch (Throwable $e) {
				$failed++;
				$active++;
				$parts[] = $label . ': FAILED — ' . $e->getMessage();
				error_log('ReconcileSubscriptions: ' . $label . ' failed: ' . $e->getMessage());
			}
		}

		if ($active === 0) {
			return array('status' => 'skipped', 'message' => 'No subscription provider is configured');
		}

		$result = array(
			'status' => $failed ? 'error' : 'success',
			'message' => implode('; ', $parts),
		);
		if ($html !== '') {
			$result['html'] = $html;
		}
		return $result;
	}
}
