<?php
/**
 * FleetBackupRun — this management node's own backups of the nodes it manages.
 *
 * The node does the backup. This decides when, prunes backup storage beforehand, and
 * dispatches one job per due node. Everything that makes a backup good — the
 * chain, the envelope, the upload, the local sweep — happens on the node
 * through the same engine it uses for its own copies.
 *
 * These are peers, not a hierarchy. A site's own scheduled backups are that
 * site's business, under its own key, and this task neither knows nor cares
 * whether it takes any. What it schedules here is this management node's copies,
 * under this management node's key.
 *
 * The same pass also proves the backups it takes. Every retention listing is
 * checked against each backup's own manifest (the backup storage check, free), and every
 * verify_every_days the node is asked to open and read its newest backup to the
 * end (a verify_backup job, level 2). Verification is dispatched under the same
 * concurrency cap as a backup and never on a node whose backup, stage or verify
 * is still running.
 *
 * Three rules keep a fleet of these from behaving like a thundering herd:
 *
 *   - each node's slot is derived from its slug, spread across a window, so
 *     forty nodes do not all start a multi-hundred-megabyte upload at 03:00;
 *   - a node whose previous run is still pending or running is skipped, so a
 *     slow node gets fewer backups rather than a queue;
 *   - no more than N run at once across the whole fleet.
 *
 * @version 1.5 - the run request carries the object store: the newest index and every epoch envelope
 *                in the manager-profile backup storage, read off the listing the prune already took, are handed
 *                to the builder to sign (specs/implemented/backup_offloaded_files.md § Rollout)
 * @version 1.4 - a manifest the backup storage check could not read is reported by the pass, not stamped as an
 *                incomplete backup (the stamp is written only from a complete reading); the verify decision is handed the node's newest verify_backup job, so a verify
 *                that failed on the node counts as attempted and is not re-dispatched every tick
 * @version 1.3 - the pass verifies as well as backs up: the backup storage check (level 1) runs on every
 *                retention listing and stamps mgn_backup_shelf_problem, and a level 2 verify of the
 *                newest backup is dispatched when the policy says one is due, under the same
 *                concurrency cap and never beside a running backup, stage or verify
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
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupChainListHelper.php'));

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
		$verified = array();
		$verify_skipped = array();

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

			// A verify, when one is due, before the backup decision: it reads
			// the plane-side stamps and the newest verify job only, and costs
			// nothing unless it fires. It never runs beside a backup, a Prepare
			// or another verify of the same node, and it takes a slot from the
			// same concurrency budget.
			$verify_job = ManagementJob::latestForNode($node->key, 'verify_backup');
			if (FleetBackupPolicy::is_verify_due($policy, $node, $now, $verify_job)) {
				$busy = self::active_backup_work($node->key);
				if ($busy !== '') {
					$verify_skipped[] = $slug . ' (' . $busy . ' still going)';
				} elseif ($in_flight >= $max) {
					$verify_skipped[] = $slug . ' (fleet concurrency limit)';
				} elseif ($dry) {
					$verified[] = $slug;
					$in_flight++;
					continue;   // the backup waits for the verify, as below
				} else {
					try {
						self::dispatch_verify($node);
						$verified[] = $slug;
						$in_flight++;
						// The backup of this node waits for its next tick: the
						// verify reads the chain the backup would extend.
						continue;
					} catch (Throwable $e) {
						$problems[] = $slug . ' verify: ' . $e->getMessage();
						// Said on the card too, beside the last real result, the
						// way a skip is: the node was not verified and this is why.
						try {
							$node->set('mgn_backup_verify_message', 'Could not start a verification: ' . $e->getMessage());
							$node->save();
						} catch (Throwable $inner) {
							error_log('FleetBackupRun: could not record the verify problem for node '
								. $slug . ': ' . $inner->getMessage());
						}
					}
				}
			}

			$latest = ManagementJob::latestForNode($node->key, 'backup_run');
			if ($latest && in_array($latest->get('mjb_status'), array('pending', 'running'), true)) {
				$skipped[] = $slug . ' (previous run still going)';
				continue;
			}
			if ($verify_job && in_array($verify_job->get('mjb_status'), array('pending', 'running'), true)) {
				$skipped[] = $slug . ' (verification still going)';
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
				$pruned = null;   // this node's listing, never a previous node's
				if ($target) {
					$pruned = FleetBackupRetention::prune($node, $target, $policy['keep']);
					if ($pruned['error'] !== '') {
						// Worth saying, never worth stopping for: too many restore
						// points is a bill, no backup is an outage.
						$problems[] = $slug . ' backup storage: ' . $pruned['error'];
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
							// The backup storage check: is every backup in backup storage whole? Read
							// from the listing just taken, one small GET per backup for
							// its manifest. Empty when nothing is wrong; the health check
							// turns anything else into a problem on the node's card. A
							// manifest that could not be READ this pass is this pass's
							// problem, said in the report; the stamp is written only from
							// a complete reading, so a blip neither shows as an incomplete
							// backup nor clears a real one found last time.
							$shelf = FleetBackupRetention::check_shelf(
								(array)($pruned['objects'] ?? array()), (string)($pruned['base'] ?? ''),
								$target->get_credentials(), (string)$target->get('bkt_bucket'));
							if ($shelf['unread'] !== '') {
								$problems[] = $slug . ' backup storage: ' . $shelf['unread'];
							} else {
								$node->set('mgn_backup_shelf_problem', $shelf['problem']);
							}
							$node->save();
						} catch (Throwable $e) {
							error_log('FleetBackupRun: could not stamp the backup storage check for node '
								. $slug . ': ' . $e->getMessage());
						}
					}
				}

				$params = array(
					'type'               => $policy['type'],
					'mode'               => $policy['mode'],
					'full_interval_days' => $policy['full_interval_days'],
				);
				// What the node's manager-profile backup storage holds of its offloaded files —
				// the newest index and the epoch envelopes — from the listing
				// just taken, so the builder signs links without listing again.
				if (is_array($pruned) && !empty($pruned['listed'])) {
					$params['objects_links'] = FleetBackupRetention::index_links(
						(array)($pruned['objects'] ?? array()), (string)($pruned['base'] ?? ''));
				}
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
		if ($verified) {
			$parts[] = ($dry ? 'would verify ' : 'verifying ') . count($verified) . ' node'
				. (count($verified) === 1 ? '' : 's') . ' by opening and reading the newest backup ('
				. implode(', ', $verified) . ')';
		}
		if ($verify_skipped) { $parts[] = 'verification skipped ' . implode(', ', $verify_skipped); }
		if ($problems) { $parts[] = 'problems: ' . implode('; ', $problems); }

		return array(
			// A pass that dispatched nothing because nothing was due is a
			// successful pass, not a skipped one. 'error' is reserved for a pass
			// that could not do its job at all — every node it tried failed.
			// One node's backup storage hiccup among successful dispatches is carried in
			// the message, where per-node monitoring picks the node up anyway.
			'status'  => ($problems && !$dispatched) ? 'error' : 'success',
			'message' => implode('; ', $parts) . '.',
		);
	}

	/**
	 * Dispatch a level 2 verify of this node's newest backup from here.
	 *
	 * The newest manager-profile chain in backup storage, as the Backups tab lists
	 * it; the node picks the newest run inside it (no seq is sent). Always
	 * level 2: a rehearsal is a person's decision, and no schedule can select
	 * it.
	 */
	private static function dispatch_verify($node) {
		$listed = BackupChainListHelper::for_node($node, 20);
		if (!empty($listed['error'])) {
			throw new Exception('backup storage could not be listed: ' . $listed['error']);
		}
		$newest = null;
		foreach ($listed['chains'] as $chain) {
			if (($chain['profile'] ?? '') === BackupProfile::MANAGER) { $newest = $chain; break; }
		}
		if ($newest === null) {
			throw new Exception('no backup taken from here is in backup storage to verify');
		}
		$params = array(
			'chain_id' => $newest['chain_id'],
			'profile'  => BackupProfile::MANAGER,
			'level'    => BackupVerifier::LEVEL_READ,
		);
		$built = JobCommandBuilder::build_verify_backup($node, $params);
		ManagementJob::createFromBuild($node->key, 'verify_backup', $built, $params, null);
	}

	/** The job types that must not overlap a verify on one node. */
	const BACKUP_WORK_TYPES = array('backup_run', 'stage_chain', 'verify_backup');

	/**
	 * Which backup-related job is pending or running on this node, named for
	 * a person ('' when none). A verify reads the chain a backup may be
	 * writing and a Prepare may be staging, so none of the three overlaps.
	 */
	private static function active_backup_work($node_id) {
		$names = array('backup_run' => 'backup', 'stage_chain' => 'prepare', 'verify_backup' => 'verification');
		foreach (self::BACKUP_WORK_TYPES as $type) {
			$latest = ManagementJob::latestForNode((int)$node_id, $type);
			if ($latest && in_array($latest->get('mjb_status'), array('pending', 'running'), true)) {
				return $names[$type];
			}
		}
		return '';
	}

	/**
	 * How many backups and verifies are already in flight across the fleet.
	 * One budget for both: a verify downloads and reads as much as a backup
	 * uploads, and the cap is about backup storage and the network, not the kind of
	 * job.
	 */
	private static function in_flight_count() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT COUNT(*) FROM mjb_management_jobs
			 WHERE mjb_job_type IN ('backup_run', 'verify_backup') AND mjb_status IN ('pending', 'running')
			   AND mjb_delete_time IS NULL");
		$q->execute();
		return (int)$q->fetchColumn();
	}
}
