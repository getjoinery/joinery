<?php
/**
 * FleetBackupRun — this management node's own backups of the nodes it manages.
 *
 * The node does the backup. This decides when, prunes the shelf beforehand, and
 * dispatches one job per due node. Everything that makes a backup good — the
 * chain, the envelope, the upload, the local sweep — happens on the node
 * through the same engine it uses for its own copies.
 *
 * These are peers, not a hierarchy. A site's own scheduled backups are that
 * site's business, under its own key, and this task neither knows nor cares
 * whether it takes any. What it schedules here is this management node's copies,
 * under this management node's key.
 *
 * Three rules keep a fleet of these from behaving like a thundering herd:
 *
 *   - each node's slot is derived from its slug, spread across a window, so
 *     forty nodes do not all start a multi-hundred-megabyte upload at 03:00;
 *   - a node whose previous run is still pending or running is skipped, so a
 *     slow node gets fewer backups rather than a queue;
 *   - no more than N run at once across the whole fleet.
 *
 * @version 1.2 - dispatch is gated on the node's own verified recovery key: a node without one is
 *                reported as awaiting it, not dispatched at and failed every cycle
 * @version 1.1 - build_backup_run() returns a primitive envelope only; an unpaired node throws
 *                and lands in problems[]
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupRetention.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

class FleetBackupRun implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

	public function run(array $config) {
		return self::pass(false);
	}

	/** What a real pass would do, without dispatching or deleting anything. */
	public function dryRun(array $config) {
		return self::pass(true);
	}

	private static function pass($dry) {
		$now = gmdate('Y-m-d H:i:s');
		$in_flight = self::in_flight_count();
		$max = FleetBackupPolicy::max_concurrent();

		$dispatched = array();
		$skipped = array();
		$problems = array();

		foreach (FleetBackupPolicy::eligible_nodes() as $node) {
			$policy = FleetBackupPolicy::for_node($node);
			$slug   = (string)$node->get('mgn_slug');

			if (empty($policy['enabled'])) {
				continue;   // Somebody's decision, not a gap. Nothing to report.
			}

			// A node that hosts no Joinery site is not a backup candidate: the
			// run executes the site's own engine at {web_root}/utils/run_backup.php.
			// Relays and DNS boxes live here too, and reporting them as problems
			// on every pass trains an operator to stop reading the report.
			if (trim((string)$node->get('mgn_web_root')) === '') {
				$skipped[] = $slug . ' (hosts no Joinery site)';
				continue;
			}

			// A node with no verified recovery key of its own takes no backups,
			// for anybody — the node refuses, and the builder refuses before it.
			// That is a real state and the ordinary one for a fresh box, so it
			// is a SKIP here rather than a dispatch that fails every cycle,
			// writes a problem line into every fleet report, and trains an
			// operator to stop reading them. The gap itself is still reported —
			// once, and where it can be acted on — by the node's own health
			// (NodeMonitorHealth::fleet_backup_health, which leads with it).
			$rk = RecoveryKeyFleet::node_state($node);
			if ($rk['state'] !== 'n/a' && !RecoveryKeyFleet::has_own_key($rk)) {
				$skipped[] = $slug . ' (awaiting its recovery key)';
				continue;
			}

			$latest = ManagementJob::latestForNode($node->key, 'backup_run');
			if ($latest && in_array($latest->get('mjb_status'), array('pending', 'running'), true)) {
				$skipped[] = $slug . ' (previous run still going)';
				continue;
			}

			if (!FleetBackupPolicy::is_due($policy, $slug, $latest, $now)) {
				continue;
			}

			if ($in_flight >= $max) {
				$skipped[] = $slug . ' (fleet concurrency limit)';
				continue;
			}

			if ($dry) {
				$dispatched[] = $slug . ' at ' . FleetBackupPolicy::slot_time($policy, $slug);
				$in_flight++;
				continue;
			}

			try {
				// Prune BEFORE the run, not after: once per backup cycle rather
				// than once per tick, and everything it counts is already
				// confirmed present in the bucket, so a run that failed part-way
				// can never be counted as a restore point.
				$target = JobCommandBuilder::get_target($node);
				if ($target) {
					$pruned = FleetBackupRetention::prune($node, $target, $policy['keep']);
					if ($pruned['error'] !== '') {
						// Worth saying, never worth stopping for: too many restore
						// points is a bill, no backup is an outage.
						$problems[] = $slug . ' shelf: ' . $pruned['error'];
					}
					if (!empty($pruned['listed'])) {
						// The bucket's testimony, stamped beside the node's own
						// claim so the health check can compare the two. In its
						// own guard: a stamp that cannot be written is a health
						// gap, never a reason to skip the backup itself.
						try {
							$node->set('mgn_backup_shelf_checked_time', $now);
							$node->set('mgn_backup_shelf_newest_time',
								$pruned['newest_object_time'] !== '' ? $pruned['newest_object_time'] : null);
							// What the node is KEEPING, from that same listing. The hosted
							// tier's storage allowance is measured against this figure and
							// needs no meter of its own.
							$node->set('mgn_backup_shelf_bytes', (int)($pruned['bytes'] ?? 0));
							$node->save();
						} catch (Throwable $e) {
							error_log('FleetBackupRun: could not stamp the shelf check for node '
								. $slug . ': ' . $e->getMessage());
						}
					}
				}

				$params = array(
					'type'               => $policy['type'],
					'mode'               => $policy['mode'],
					'full_interval_days' => $policy['full_interval_days'],
				);
				// createFromBuild, not createJob: build_backup_run() returns a
				// primitive envelope, and only this entry point stores one
				// correctly. An unpaired node throws and lands in problems[].
				$built = JobCommandBuilder::build_backup_run($node, $params);
				ManagementJob::createFromBuild($node->key, 'backup_run', $built, $params, null);

				$dispatched[] = $slug;
				$in_flight++;
			} catch (Throwable $e) {
				$problems[] = $slug . ': ' . $e->getMessage();
			}
		}

		$parts = array();
		$parts[] = ($dry ? 'Would back up ' : 'Backing up ')
			. ($dispatched ? count($dispatched) . ' node' . (count($dispatched) === 1 ? '' : 's')
				. ' (' . implode(', ', $dispatched) . ')'
			  : 'no nodes — none are due');
		if ($skipped)  { $parts[] = 'skipped ' . implode(', ', $skipped); }
		if ($problems) { $parts[] = 'problems: ' . implode('; ', $problems); }

		return array(
			// A pass that dispatched nothing because nothing was due is a
			// successful pass, not a skipped one. 'error' is reserved for a pass
			// that could not do its job at all — every node it tried failed.
			// One node's shelf hiccup among successful dispatches is carried in
			// the message, where per-node monitoring picks the node up anyway.
			'status'  => ($problems && !$dispatched) ? 'error' : 'success',
			'message' => implode('; ', $parts) . '.',
		);
	}

	/** How many manager-profile runs are already in flight across the fleet. */
	private static function in_flight_count() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT COUNT(*) FROM mjb_management_jobs
			 WHERE mjb_job_type = 'backup_run' AND mjb_status IN ('pending', 'running')
			   AND mjb_delete_time IS NULL");
		$q->execute();
		return (int)$q->fetchColumn();
	}
}
