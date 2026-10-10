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
 *   - one machine runs one backup at a time. The cost of a backup lands on
 *     the machine that takes it, so container sites on one host take turns,
 *     and a node on a machine of its own waits for nobody. Only work a node
 *     has claimed occupies its machine: a job no agent picks up uses nothing,
 *     and must not hold anyone else back.
 *
 * A node whose agent is not checking in is skipped and named: a job sent to it
 * would wait unclaimed and run whenever the agent came back, not in its slot.
 *
 * @version 1.13 - busy_machines names failStaleClaims and markLost, which free a lost claim (no requeue)
 * @version 1.12 - the pass finishes what earlier deletes only hid in each backup target's folder
 *                 (HiddenVersionSweep; specs/storage_targets.md S28)
 * @version 1.11 - the pass aborts node broker runs whose token expired and writes any run ledger file that did not
 *                 reach backup storage (specs/storage_targets.md WP5)
 * @version 1.10 - retention prunes across every storage space of the node (FleetBackupRetention::prune); the
 *                 scheduled verify names the space of the chain it reads, which is how a moved node's old space
 *                 learns its successor holds a verified chain
 * @version 1.9 - a verify that is due checks its own node's work under its own name, so it no longer replaces the
 *                pass's map of busy machines (the next sibling started beside the verify, or the pass died)
 * @version 1.8 - one backup at a time per machine, counting only claimed work, replaces the fleet-wide cap
 *                (a job no agent would ever claim held a fleet slot forever); a node whose agent is
 *                not checking in is skipped and named, not sent a job
 * @version 1.7 - a verify it sends records the recovery key it is sent under
 * @version 1.6 - retention is the site's own reported window, never below the policy's keep_days; after a node reports a successful run the pass lists its
 *                backup storage once (witness_landing), so "Backups are not landing" is known within a
 *                tick instead of at the next night's dispatch
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
		$busy = self::busy_machines();

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

			// The bucket's word on the run the node just reported, while it is
			// still news. The listing taken at dispatch predates the upload, so
			// without this the health check hears about an upload that never
			// landed only at the next night's dispatch.
			if (!$dry) {
				$witness = self::witness_landing($node, $now);
				if ($witness !== '') { $problems[] = $slug . ' backup storage: ' . $witness; }
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

			$unheard = FleetBackupPolicy::agent_unheard($node, $now);
			if ($unheard !== '') {
				$skipped[] = $slug . ' (' . $unheard . ')';
				continue;
			}
			$machine = FleetBackupPolicy::machine_key($node);

			// A verify, when one is due, before the backup decision: it reads
			// the plane-side stamps and the newest verify job only, and costs
			// nothing unless it fires. It never runs beside a backup, a Prepare
			// or another verify of the same node, and it takes its machine's
			// turn the way a backup does: it downloads and reads as much.
			$verify_job = ManagementJob::latestForNode($node->key, 'verify_backup');
			if (FleetBackupPolicy::is_verify_due($policy, $node, $now, $verify_job)) {
				$own_work = self::active_backup_work($node->key);
				if ($own_work !== '') {
					$verify_skipped[] = $slug . ' (' . $own_work . ' still going)';
				} elseif (isset($busy[$machine])) {
					$verify_skipped[] = $slug . ' (waiting for ' . $busy[$machine] . ' on the same machine)';
				} elseif ($dry) {
					$verified[] = $slug;
					$busy[$machine] = $slug . '\'s verification';
					continue;   // the backup waits for the verify, as below
				} else {
					try {
						self::dispatch_verify($node);
						$verified[] = $slug;
						$busy[$machine] = $slug . '\'s verification';
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

			if (isset($busy[$machine])) {
				$skipped[] = $slug . ' (waiting for ' . $busy[$machine] . ' on the same machine)';
				continue;
			}

			if ($dry) {
				$dispatched[] = $slug . ' at ' . FleetBackupPolicy::slot_time($policy, $slug);
				$busy[$machine] = $slug . '\'s backup';
				continue;
			}

			try {
				// Prune BEFORE the run, not after: once per backup cycle rather
				// than once per tick, and everything it counts is already
				// confirmed present in the bucket, so a run that failed part-way
				// can never be counted as a restore point.
				$pruned = null;   // this node's listing, never a previous node's
				if (StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$node->key)) {
					$pruned = FleetBackupRetention::prune($node,
						FleetBackupPolicy::retention_days($policy, $node));
					if ($pruned['error'] !== '') {
						// Worth saying, never worth stopping for: too many restore
						// points is a bill, no backup is an outage.
						$problems[] = $slug . ' backup storage: ' . $pruned['error'];
					}
					if (!empty($pruned['listed']) && $pruned['space']) {
						$active_target = $pruned['space']->target();
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
								$active_target->get_credentials(), (string)$active_target->get('bkt_bucket'), null,
								(int)(JobCommandBuilder::node_space($node) ? JobCommandBuilder::node_space($node)->key : 0));
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
				$busy[$machine] = $slug . '\'s backup';
			} catch (Throwable $e) {
				$problems[] = $slug . ': ' . $e->getMessage();
			}
		}

		// The broker's own housekeeping (specs/storage_targets.md WP5): a node
		// run whose token expired is aborted, and a finished run whose ledger
		// file did not reach backup storage is written again.
		if (!$dry) {
			try {
				$expired = NodeBroker::abortExpired();
				if ($expired) { $problems[] = $expired . ' backup run' . ($expired === 1 ? ' was' : 's were') . ' never finished on the node and aborted'; }
				$ledger = ShelfBroker::writeMissingLedgerFiles();
				if ($ledger['stuck']) {
					$problems[] = $ledger['stuck'] . ' backup run ledger file' . ($ledger['stuck'] === 1 ? '' : 's')
						. ' could not be written to backup storage (' . $ledger['problem'] . '); tried again daily';
				}
			} catch (Throwable $e) {
				$problems[] = 'backup broker: ' . $e->getMessage();
			}
		}

		// What earlier deletes only hid, in the whole folder of every backup
		// target this management node keeps: every node's and every customer's
		// backups (HiddenVersionSweep, daily per target, within a budget).
		$hidden_said = array();
		if (!$dry) {
			foreach (new MultiBackupTarget(array('deleted' => false)) as $target) {
				try {
					$folder = BackupTarget::normalise_prefix((string)$target->get('bkt_path_prefix')) . '/';
					$r = HiddenVersionSweep::run($target, $folder, 120);
					$line = HiddenVersionSweep::sentence($r, (string)$target->get('bkt_name'));
					if ($line !== '') { $hidden_said[] = $line; }
				} catch (Throwable $e) {
					$problems[] = 'hidden-version sweep of ' . $target->get('bkt_name') . ': ' . $e->getMessage();
				}
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
		foreach ($hidden_said as $line) { $parts[] = $line; }
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
			'space_id' => (int)$newest['space_id'],
			'profile'  => BackupProfile::MANAGER,
			'level'    => BackupVerifier::LEVEL_READ,
		);
		$built = JobCommandBuilder::build_verify_backup($node, $params);
		ManagementJob::createFromBuild($node->key, 'verify_backup', $built, $params, null);
		FleetBackupPolicy::note_verify_sent($node);
	}

	/**
	 * List the node's backup storage once after it reports a successful run,
	 * and stamp what landed beside the node's claim for the health check
	 * (NodeMonitorHealth, "Backups are not landing"). Due when the claimed run
	 * started after the last listing; the stamp makes it not due again until
	 * the next run, and a failed listing is retried on the next tick.
	 *
	 * Returns '' or what went wrong.
	 */
	private static function witness_landing($node, $now) {
		if ((string)$node->get('mgn_last_backup_outcome') !== 'success') { return ''; }
		$claimed = trim((string)$node->get('mgn_last_backup_time'));
		if ($claimed === '') { return ''; }
		$checked = trim((string)$node->get('mgn_backup_shelf_checked_time'));
		if ($checked !== '' && strtotime($checked . ' UTC') > strtotime($claimed . ' UTC')) { return ''; }

		$space = JobCommandBuilder::node_space($node);
		if (!$space) { return ''; }
		try {
			$newest = FleetBackupRetention::newest_landed($space);
			$node->set('mgn_backup_shelf_checked_time', $now);
			$node->set('mgn_backup_shelf_newest_time', $newest !== '' ? $newest : null);
			$node->save();
		} catch (Throwable $e) {
			return $e->getMessage();
		}
		return '';
	}

	/** The job types that must not overlap a verify on one node. */
	const BACKUP_WORK_TYPES = array('backup_run', 'stage_chain', 'verify_backup');

	/** Each of those, named for a person. */
	const BACKUP_WORK_NAMES = array('backup_run' => 'backup', 'stage_chain' => 'prepare', 'verify_backup' => 'verification');

	/**
	 * Which backup-related job is pending or running on this node, named for
	 * a person ('' when none). A verify reads the chain a backup may be
	 * writing and a Prepare may be staging, so none of the three overlaps.
	 */
	private static function active_backup_work($node_id) {
		foreach (self::BACKUP_WORK_TYPES as $type) {
			$latest = ManagementJob::latestForNode((int)$node_id, $type);
			if ($latest && in_array($latest->get('mjb_status'), array('pending', 'running'), true)) {
				return self::BACKUP_WORK_NAMES[$type];
			}
		}
		return '';
	}

	/**
	 * The machines already taking a backup, a Prepare or a verify, each with
	 * the work named for a person ("getjoinery's backup"). Claimed work only:
	 * an agent claims within seconds of polling, so a job still pending is one
	 * no agent is running (its node is busy with something else, its agent is
	 * gone) and it uses nothing on the machine. A claim that outlives its
	 * budget, or whose agent comes back idle, is failed as lost by ManagementJob
	 * (failStaleClaims, markLost), so a lost one frees its machine on its own.
	 */
	private static function busy_machines(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT n.mgn_managed_node_id, n.mgn_mgh_managed_host_id, n.mgn_slug, j.mjb_job_type
			 FROM mjb_management_jobs j
			 JOIN mgn_managed_nodes n ON n.mgn_managed_node_id = j.mjb_mgn_managed_node_id
			 WHERE j.mjb_job_type IN ('" . implode("', '", self::BACKUP_WORK_TYPES) . "')
			   AND j.mjb_status = 'running'
			   AND j.mjb_delete_time IS NULL");
		$q->execute();
		$busy = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$key = FleetBackupPolicy::machine_key_for((int)$row['mgn_managed_node_id'], (int)$row['mgn_mgh_managed_host_id']);
			$busy[$key] = $row['mgn_slug'] . '\'s ' . self::BACKUP_WORK_NAMES[$row['mjb_job_type']];
		}
		return $busy;
	}
}
