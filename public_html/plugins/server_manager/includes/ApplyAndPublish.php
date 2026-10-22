<?php
/**
 * ApplyAndPublish — apply the release a management node's upgrade source
 * offers, then publish it from that node, as one request.
 *
 * Publish Release on the node page republishes the version the node runs.
 * When its upgrade source offers a newer one, the operator would apply it,
 * wait, come back and publish. Apply and publish queues the apply with the
 * publish that is to follow it recorded on the job (then_publish: the notes,
 * the version the apply is expected to reach, whether to deploy to every
 * managed node afterwards). Nothing about the publish is sent to the node
 * with the apply.
 *
 * Once the apply has finished, advance() judges it with the staged rollout's
 * gate (job completed, deploy tier passed, no rollback, at least the expected
 * version) and queues the publish of the version the node reports, through
 * queuePublish(), the path the Publish button takes too. What became of it is
 * written into then_publish on the apply job: outcome 'started' with the
 * publish job, or 'refused' with the reason. The AdvanceStagedRollouts task
 * advances it every run.
 *
 * @version 1.0
 */

class ApplyAndPublish {

	const LOCK_KEY = 7720231022;

	/** An apply older than this is no longer followed by its publish. */
	const WINDOW_HOURS = 24;

	/** The job states an apply does not leave. */
	const FINISHED = array('completed', 'failed', 'cancelled');

	/**
	 * Queue a publish of $node's own release: the one way the node page
	 * queues one, whether the operator pressed Publish or an apply finished.
	 * The builder refuses a node without the primitive; the publisher on the
	 * node keeps every rule about the number (may this site mint it,
	 * duplicate, downgrade).
	 *
	 * @param array $params release_notes, major, minor, patch
	 */
	public static function queuePublish(ManagedNode $node, array $params, bool $deploy_all, $uid): ManagementJob {
		$built = JobCommandBuilder::build_publish_upgrade($node, $params);
		self::assertCommitted($node);
		// Recorded on the job, never sent to the node: the plane starts the
		// rollout itself once the publish completes.
		if ($node->is_self() && $deploy_all) {
			$params['deploy_all'] = true;
		}
		return ManagementJob::createFromBuild($node->key, 'publish_upgrade', $built, $params, $uid);
	}

	/**
	 * Queue the apply, with the publish that is to follow it recorded on the
	 * job. $target is the version the node's upgrade source offers now.
	 */
	public static function request(ManagedNode $node, string $notes, string $target, bool $deploy_all, $uid): ManagementJob {
		if (!JobCommandBuilder::can_publish_release($node)) {
			throw new Exception('This node does not publish releases, so there is nothing to publish after an apply.');
		}
		$notes = trim($notes);
		if ($notes === '') {
			throw new Exception('A publish carries release notes.');
		}
		if (!preg_match('/^\d+\.\d+\.\d+$/', $target)) {
			throw new Exception('Which version the apply brings in is not known, so it cannot be followed by a publish.');
		}
		// What the publish would refuse after the apply, refused before it.
		self::assertCommitted($node);
		$built = JobCommandBuilder::build_apply_update($node);
		return ManagementJob::createFromBuild($node->key, 'apply_update', $built, array('then_publish' => array(
			'release_notes' => $notes,
			'target'        => $target,
			'deploy_all'    => $deploy_all && $node->is_self(),
		)), $uid);
	}

	/** The publish an apply job is to be followed by, with what became of it; null when none. */
	public static function followUp($job): ?array {
		if ((string)$job->get('mjb_job_type') !== 'apply_update') {
			return null;
		}
		$then = StagedRolloutRunner::job_params($job)['then_publish'] ?? null;
		return is_array($then) ? $then : null;
	}

	/** Advance every recent apply whose publish has not started or been refused. What the task calls each run. */
	public static function advanceAll(): int {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs
			WHERE mjb_job_type = 'apply_update' AND mjb_delete_time IS NULL
			  AND mjb_parameters->'then_publish' IS NOT NULL
			  AND mjb_parameters->'then_publish'->>'outcome' IS NULL
			  AND mjb_create_time > now() AT TIME ZONE 'UTC' - make_interval(hours => ?)
			ORDER BY mjb_management_job_id");
		$q->execute(array(self::WINDOW_HOURS));
		$started = 0;
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			if (self::advance(new ManagementJob((int)$id, TRUE))) {
				$started++;
			}
		}
		return $started;
	}

	/**
	 * Start the publish a finished apply is to be followed by, or record why
	 * not. Waits — returns false and records nothing — while the apply is
	 * still running or another caller holds the lock.
	 *
	 * @return bool whether a publish was queued
	 */
	public static function advance(ManagementJob $job): bool {
		$db = DbConnector::get_instance()->get_db_link();
		if (!$db->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn()) {
			return false;
		}
		try {
			$job->load();
			$then = self::followUp($job);
			if ($then === null || isset($then['outcome'])
				|| !in_array((string)$job->get('mjb_status'), self::FINISHED, true)) {
				return false;
			}
			if (JobResultProcessor::process_if_due($job)) {
				$job->load();
			}
			$why = StagedRolloutRunner::gate($job, (string)($then['target'] ?? ''));
			if ($why !== null) {
				self::record($job, $then, array('outcome' => 'refused', 'reason' => 'the apply did not succeed: ' . $why));
				return false;
			}
			// The gate has proved version_after a version at least the target.
			$version = (string)json_decode((string)$job->get('mjb_result'), true)['apply']['version_after'];
			list($major, $minor, $patch) = explode('.', $version);
			try {
				$publish = self::queuePublish(new ManagedNode((int)$job->get('mjb_mgn_managed_node_id'), TRUE), array(
					'release_notes' => (string)$then['release_notes'],
					'major'         => $major,
					'minor'         => $minor,
					'patch'         => $patch,
				), !empty($then['deploy_all']), $job->get('mjb_created_by'));
			} catch (Exception $e) {
				self::record($job, $then, array('outcome' => 'refused', 'reason' => $e->getMessage()));
				return false;
			}
			self::record($job, $then, array('outcome' => 'started', 'version' => $version, 'publish_job_id' => (int)$publish->key));
			return true;
		} finally {
			$db->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
		}
	}

	private static function assertCommitted(ManagedNode $node): void {
		if ($node->is_self() && ($blocked = AgentDistPublisher::uncommittedAdvice()['lines'])) {
			throw new Exception('Commit and push first. ' . implode(' ', $blocked));
		}
	}

	private static function record(ManagementJob $job, array $then, array $outcome): void {
		$params = StagedRolloutRunner::job_params($job);
		$params['then_publish'] = array_merge($then, $outcome);
		$job->set('mjb_parameters', json_encode($params));
		$job->save();
	}
}
