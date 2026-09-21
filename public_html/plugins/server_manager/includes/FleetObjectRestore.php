<?php
/**
 * FleetObjectRestore — the management node's side of bringing a node's
 * offloaded files home, one page of signed links at a time.
 *
 * A node never lists its manager-profile backup storage and never holds a read credential, so
 * every object it brings home arrives by a link this plane signed
 * (specs/backup_offloaded_files.md § Restore). A link is a few hundred bytes
 * and a job is ManagementJob::MAX_PARAMS_BYTES, so a store is many small jobs,
 * and the loop is driven from here:
 *
 *   1. A SURVEY job — the restore_objects primitive with the run's index link
 *      and no object links. The node answers with the names it would bring
 *      home (RESTORE_OBJECTS_WANT, capped; RESTORE_OBJECTS_MORE when there are
 *      more), having HEADed its file bucket in missing mode.
 *   2. A PAGE job per slice of that answer, filled to the byte ceiling: the
 *      object links plus the envelope of each epoch the page's objects are
 *      sealed under. Each page is issued when the one before it reports, so a
 *      link never sits in a queue past its expiry.
 *   3. When the pages are done and the survey said there was more, a new
 *      survey; the names already home are local rows now, so it names the
 *      next thousand. When a survey names nothing, the loop is over.
 *
 * The loop's state is the job records: a page job names its origin (the
 * survey whose answer it pages) and its cursor into that answer; the survey's
 * result holds the names. Nothing is carried per page beyond that, so ten
 * thousand names are stored once. A failed job ends the loop with its own
 * result saying why; running Bring them back again picks up where it stopped,
 * because a restored file's row says local and is not wanted twice.
 *
 * The dashboard's chain restore starts this in missing mode as its last step
 * (JobResultProcessor::process_restore_chain); Bring them back on a node's
 * Backups tab starts it by hand.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupObjectRestore.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));

class FleetObjectRestore {

	/** The job type every survey and page carries. */
	const JOB_TYPE = 'restore_objects';

	/** The two steps a restore_objects job record names. */
	const STEP_SURVEY = 'survey';
	const STEP_PAGE   = 'page';

	/**
	 * One page of links, filled to the job's byte ceiling. Pure: the index,
	 * the names in order, the params the page is added to, and a signer.
	 *
	 * Names are taken from the front of $names until the next one would push
	 * the encoded params over ManagementJob::MAX_PARAMS_BYTES or the page
	 * reaches BackupObjectRestore::PAGE_MAX. A name the index does not mark
	 * stored is passed over (the node would refuse the whole page for it) and
	 * counted as taken, so the cursor moves past it. Each epoch a taken object
	 * is sealed under gets its envelope link once.
	 *
	 * @param array    $index  the run's decoded objects index
	 * @param array    $names  candidate names, in order
	 * @param array    $params the primitive params so far (chain_id, profile, seq, mode, index_url)
	 * @param callable $sign   fn(string $relname): string — a signed GET for objects/… in backup storage
	 * @return array ['params' => the params with object_urls and epoch_envelope_urls, 'count' => n names consumed,
	 *                'unindexed' => n passed over]
	 */
	public static function page(array $index, array $names, array $params, callable $sign) {
		$entries = BackupObjects::index_entries($index);
		$objects = [];
		$envelopes = [];
		$count = 0;
		$unindexed = 0;
		foreach ($names as $name) {
			$name = (string)$name;
			if (!isset($entries[$name])) {
				$count++;
				$unindexed++;
				continue;
			}
			if (count($objects) >= BackupObjectRestore::PAGE_MAX) {
				break;
			}
			$epoch = (string)$entries[$name]['epoch'];
			$try_objects   = $objects + [$name => $sign(BackupObjects::object_relname($epoch, $name))];
			$try_envelopes = $envelopes + (isset($envelopes[$epoch]) ? [] : [$epoch => $sign(BackupObjects::envelope_relname($epoch))]);
			$size = strlen((string)json_encode($params + ['epoch_envelope_urls' => $try_envelopes, 'object_urls' => $try_objects]));
			if ($size > ManagementJob::MAX_PARAMS_BYTES) {
				if (!$objects) {
					throw new Exception('The link for ' . $name . ' alone would put the job over the '
						. ManagementJob::MAX_PARAMS_BYTES . '-byte limit.');
				}
				break;
			}
			$objects   = $try_objects;
			$envelopes = $try_envelopes;
			$count++;
		}
		if ($objects) {
			ksort($envelopes);
			$params['epoch_envelope_urls'] = $envelopes;
			$params['object_urls']         = $objects;
		}
		return ['params' => $params, 'count' => $count, 'unindexed' => $unindexed];
	}

	/**
	 * Start the loop: a survey of the run. $params: chain_id (required),
	 * profile (default manager), seq (default the newest in backup storage), mode
	 * (default missing). $root is the job this loop is the last step of (a
	 * chain restore), recorded on every job of the loop.
	 *
	 * @return ManagementJob the survey job
	 * @throws Exception when the node cannot take the primitive or the run has no index
	 */
	public static function start($node, array $params, $created_by, $root = null) {
		$built = JobCommandBuilder::build_restore_objects($node, [
			'chain_id' => (string)($params['chain_id'] ?? ''),
			'profile'  => (string)($params['profile'] ?? ''),
			'seq'      => $params['seq'] ?? '',
			'mode'     => (string)($params['mode'] ?? BackupObjectRestore::MODE_MISSING),
		]);
		$record = [
			'chain_id' => $built['params']['chain_id'],
			'profile'  => $built['params']['profile'],
			'seq'      => $built['params']['seq'],
			'mode'     => $built['params']['mode'],
			'step'     => self::STEP_SURVEY,
			'root'     => $root !== null ? (int)$root : null,
		];
		return ManagementJob::createFromBuild($node->key, self::JOB_TYPE, $built, $record, $created_by);
	}

	/**
	 * What follows a finished restore_objects job: the first page after a
	 * survey that named anything, the next page while the survey's answer has
	 * more, a new survey when the answer was capped, and nothing when the loop
	 * is over or the job failed. $verdict is the job's parsed contract
	 * (JobResultProcessor::parse_restore_objects_result).
	 *
	 * @return ManagementJob|null the job issued, or null with $why saying why none was
	 */
	public static function continue_after($job, array $verdict, &$why = '') {
		$record = $job->get('mjb_parameters');
		if (is_string($record)) { $record = json_decode($record, true); }
		$record = is_array($record) ? $record : [];
		$step = (string)($record['step'] ?? '');

		if (($verdict['result'] ?? '') !== BackupObjectRestore::RESULT_OK) {
			$why = 'the job did not succeed, so no further page was issued';
			return null;
		}
		if ($step !== self::STEP_SURVEY && $step !== self::STEP_PAGE) {
			$why = 'the job record names no step of the loop';
			return null;
		}
		$node = new ManagedNode((int)$job->get('mjb_mgn_managed_node_id'), TRUE);
		if (!$node->key) {
			$why = 'the node is gone';
			return null;
		}
		$created_by = $job->get('mjb_created_by');

		if ($step === self::STEP_SURVEY) {
			$names = (array)($verdict['want'] ?? []);
			if (!$names) {
				$why = 'the survey named nothing to bring home';
				return null;
			}
			$origin = (int)$job->key;
			$answer = ['want' => $names, 'more' => !empty($verdict['more'])];
			$next   = 0;
		} else {
			// A page: the next slice of its origin's answer, or a fresh survey.
			$origin_job = new ManagementJob((int)($record['origin'] ?? 0), TRUE);
			if (!$origin_job->key) {
				$why = 'the survey this page came from is gone';
				return null;
			}
			$origin = (int)$origin_job->key;
			$answer = $origin_job->get('mjb_result');
			if (is_string($answer)) { $answer = json_decode($answer, true); }
			$answer = is_array($answer) ? $answer : [];
			$names  = (array)($answer['want'] ?? []);
			$next   = (int)($record['cursor'] ?? 0) + max(1, (int)($record['count'] ?? 0));
		}

		// The next page with anything on it. A slice the index no longer
		// marks stored is passed over rather than sent as an empty page —
		// which the node would read as a survey.
		while ($next < count($names)) {
			list($page, $consumed) = self::page_job($node, $record, $origin, $next, array_slice($names, $next), $created_by);
			if ($page !== null) {
				return $page;
			}
			$next += max(1, $consumed);
		}
		if (!empty($answer['more'])) {
			return self::start($node, $record, $created_by, $record['root'] ?? null);
		}
		$why = 'every page of the survey is done';
		return null;
	}

	/**
	 * One page job: the builder takes what fits from the front of $names; the
	 * record says which slice. [null, n] when the slice's front carries
	 * nothing the index marks stored (n names to move past).
	 */
	private static function page_job($node, array $record, $origin, $cursor, array $names, $created_by) {
		$built = JobCommandBuilder::build_restore_objects($node, [
			'chain_id' => (string)$record['chain_id'],
			'profile'  => (string)$record['profile'],
			'seq'      => $record['seq'],
			'mode'     => (string)$record['mode'],
			'names'    => $names,
		]);
		if (empty($built['params']['object_urls'])) {
			return [null, (int)$built['count']];
		}
		$page_record = [
			'chain_id' => $built['params']['chain_id'],
			'profile'  => $built['params']['profile'],
			'seq'      => $built['params']['seq'],
			'mode'     => $built['params']['mode'],
			'step'     => self::STEP_PAGE,
			'origin'   => (int)$origin,
			'cursor'   => (int)$cursor,
			'count'    => (int)$built['count'],
			'root'     => isset($record['root']) ? (int)$record['root'] : null,
		];
		return [ManagementJob::createFromBuild($node->key, self::JOB_TYPE, $built, $page_record, $created_by), (int)$built['count']];
	}
}
