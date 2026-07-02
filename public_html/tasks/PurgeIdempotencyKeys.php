<?php
/**
 * PurgeIdempotencyKeys - Scheduled Task
 *
 * Deletes stored API idempotency outcomes older than the retry-dedup window
 * (docs/api.md § Contract — Idempotent writes). Rows exist only so a client
 * retry can replay its original response; past the window they are dead weight.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('data/api_idempotency_keys_class.php'));

class PurgeIdempotencyKeys implements ScheduledTaskInterface {

	public function run(array $config) {
		$hours_to_keep = isset($config['hours_to_keep']) ? (int)$config['hours_to_keep'] : 24;
		if ($hours_to_keep <= 0) {
			$hours_to_keep = 24;
		}

		$deleted = ApiIdempotencyKey::purge_older_than($hours_to_keep);

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No expired idempotency keys to purge');
		}

		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' idempotency key(s) older than ' . $hours_to_keep . ' hours');
	}
}
