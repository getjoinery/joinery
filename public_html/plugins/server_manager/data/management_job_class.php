<?php
/**
 * ManagementJob - A queued, running, or completed server management operation.
 *
 * @version 1.7 - mjb_agent_outcome records the wire outcome verbatim, so a refusal is countable
 *                without matching the text of an error message
 * @version 1.6 - primitive jobs for the agent channel: createPrimitiveJob, claim/report, and the
 *                claim timeout that returns an unreported job to pending instead of wedging it
 * @version 1.5 - run_command joins filterTypes (node console)
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ManagementJobException extends SystemBaseException {}

class ManagementJob extends SystemBase {
	public static $prefix = 'mjb';
	public static $tablename = 'mjb_management_jobs';
	public static $pkey_column = 'mjb_id';

	public static $json_vars = array('mjb_commands', 'mjb_parameters', 'mjb_result');

	public static $field_specifications = array(
		'mjb_id'                => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mjb_mgn_node_id'      => array('type'=>'int8'),
		'mjb_job_type'          => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false),
		'mjb_status'            => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending'),
		'mjb_commands'          => array('type'=>'jsonb', 'is_nullable'=>false),
		'mjb_parameters'        => array('type'=>'jsonb'),
		'mjb_output'            => array('type'=>'text'),
		'mjb_result'            => array('type'=>'jsonb'),
		'mjb_current_step'      => array('type'=>'int4', 'default'=>'0'),
		// How many times a node agent has claimed this job without reporting a
		// terminal result. A claim that never comes back — a crashed agent, a
		// lost network, a replayed claim — would otherwise leave the job wedged
		// in 'running' forever, holding the per-node concurrency lock with it.
		'mjb_claim_attempts'    => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		// The outcome a node agent reported, verbatim from the wire:
		// completed | failed | refused. NULL for every job no node agent
		// reported on — including a primitive job this plane gave up on after
		// repeated lost claims, because in that case the node never said
		// anything and recording a verdict for it would be inventing one.
		//
		// mjb_status stays the three-value vocabulary every dashboard filter
		// and status consumer already understands, so a refusal reads as the
		// terminal failure it is. But a refusal is ALSO a distinct fact: once
		// operate and destructive primitives are dispatched, a rise in
		// refusals is an attack or misconfiguration signal, and volume
		// anomalies are supposed to be legible (§3.5.6). A signal that can
		// only be found by matching a substring of a human-readable message is
		// not a signal, so the machine-readable answer lives here.
		'mjb_agent_outcome'     => array('type'=>'varchar(16)'),
		'mjb_total_steps'       => array('type'=>'int4'),
		'mjb_error_message'     => array('type'=>'text'),
		'mjb_external_order_item_id' => array('type'=>'int8'),
		'mjb_created_by'        => array('type'=>'int8'),
		'mjb_started_time'      => array('type'=>'timestamp(6)'),
		'mjb_completed_time'    => array('type'=>'timestamp(6)'),
		'mjb_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mjb_update_time'       => array('type'=>'timestamp(6)'),
		'mjb_delete_time'       => array('type'=>'timestamp(6)'),
	);

	protected static $foreign_key_actions = [
		'mjb_mgn_node_id' => ['action' => 'null'],
		'mjb_created_by'  => ['action' => 'null', 'source_table' => 'usr_users'],
	];

	/**
	 * Create a new job from a command builder result.
	 */
	static function createJob($node_id, $job_type, $steps, $parameters, $created_by) {
		$job = new ManagementJob(NULL);
		$job->set('mjb_mgn_node_id', $node_id);
		$job->set('mjb_job_type', $job_type);
		$job->set('mjb_status', 'pending');
		$job->set('mjb_commands', json_encode(['steps' => $steps]));
		$job->set('mjb_parameters', $parameters ? json_encode($parameters) : null);
		// Progress counts the main phase only: teardown appends never advance
		// mjb_current_step, so counting teardown steps would leave every job
		// looking short of its total.
		$main_steps = array_filter($steps, function ($s) { return empty($s['teardown']); });
		$job->set('mjb_total_steps', count($main_steps));
		$job->set('mjb_current_step', 0);
		$job->set('mjb_created_by', $created_by);
		$job->save();
		return $job;
	}

	// ── The agent channel (specs/agent_on_node_architecture.md §3.1) ──

	/** A claim not reported within this many seconds is treated as lost. */
	const CLAIM_TIMEOUT_SECONDS = 900;

	/** After this many lost claims the job fails rather than looping forever. */
	const MAX_CLAIM_ATTEMPTS = 3;

	/** Ceiling on a primitive job's params, matched byte-for-byte on the node. */
	const MAX_PARAMS_BYTES = 16384;

	/** The outcomes a node agent may report. Anything else is refused at the endpoint. */
	const AGENT_OUTCOMES = ['completed', 'failed', 'refused'];

	/**
	 * How many jobs a node's agent has refused since a given time.
	 *
	 * The count that matters for §3.5.6: a node refusing work is a node whose
	 * plane is asking for something it should not be, or whose policy has been
	 * tightened without the plane noticing. Either way the number, not the
	 * prose, is what an alert reads.
	 */
	static function refusalCountForNode($node_id, $since_utc) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT count(*) FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ?
			   AND mjb_agent_outcome = 'refused'
			   AND mjb_delete_time IS NULL
			   AND mjb_completed_time >= ?"
		);
		$q->execute([(int)$node_id, $since_utc]);
		return (int)$q->fetchColumn();
	}

	/**
	 * Create a job addressed to a node's agent.
	 *
	 * mjb_commands carries {primitive, params} instead of shell steps — the
	 * queue, progress and result plumbing are untouched, but the unit of work
	 * is a NAME the node looks up in its own compiled-in vocabulary rather than
	 * an instruction the plane composed. That difference is the migration.
	 *
	 * The params ceiling is enforced here as well as on the node. A job the
	 * node would refuse for size fails loudly when it is built, rather than
	 * travelling to a machine that will silently decline it.
	 */
	static function createPrimitiveJob($node_id, $job_type, $primitive, $params, $created_by) {
		$params = $params ?: new stdClass();

		// The payload carries a tripwire step alongside the primitive envelope.
		//
		// Nothing on the current release reads it: the node agent's remote
		// source reads `primitive` and `params`, and its LOCAL source skips any
		// job carrying a `primitive` key outright. It is here for an agent from
		// BEFORE this release, which knows neither rule — it would claim this
		// job out of the local queue, find no steps it recognises, and mark it
		// completed having done nothing at all. A green job that never ran is
		// the worst possible outcome for a status check.
		//
		// `primitive` is not a step type any released executor knows, so such
		// an agent fails the job naming exactly that instead. Loud beats
		// silent; the same rule A4 was decided under.
		$payload = json_encode([
			'primitive' => (string)$primitive,
			'params'    => $params,
			'steps'     => [[
				'type'  => 'primitive',
				'label' => 'Agent primitive: ' . (string)$primitive,
			]],
		]);
		$encoded_params = json_encode($params);
		if (strlen($encoded_params) > self::MAX_PARAMS_BYTES) {
			throw new ManagementJobException(
				'This job carries ' . strlen($encoded_params) . ' bytes of parameters, over the '
				. self::MAX_PARAMS_BYTES . '-byte limit the node enforces. It would be refused there.');
		}

		$job = new ManagementJob(NULL);
		$job->set('mjb_mgn_node_id', $node_id);
		$job->set('mjb_job_type', $job_type);
		$job->set('mjb_status', 'pending');
		$job->set('mjb_commands', $payload);
		$job->set('mjb_parameters', $params ? json_encode($params) : null);
		$job->set('mjb_total_steps', 1);
		$job->set('mjb_current_step', 0);
		$job->set('mjb_created_by', $created_by);
		$job->save();
		return $job;
	}

	/**
	 * Create a job from whatever a JobCommandBuilder returned.
	 *
	 * A builder now returns one of two shapes: the step list it always did, or
	 * a {primitive, params} envelope when the operation has crossed to the
	 * agent channel for this node. Callers dispatch operations, not transports,
	 * so the shape is read here — which means an operation crosses in Phase 2
	 * by gaining a build_<op>_primitive method, with no callsite touched.
	 */
	static function createFromBuild($node_id, $job_type, $built, $params, $created_by) {
		if (is_array($built) && isset($built['primitive'])) {
			return self::createPrimitiveJob($node_id, $job_type, $built['primitive'],
				$built['params'] ?? [], $created_by);
		}
		return self::createJob($node_id, $job_type, $built, $params, $created_by);
	}

	/**
	 * Is this job addressed to a node agent rather than to the step executor?
	 */
	function isPrimitiveJob() {
		$commands = $this->get('mjb_commands');
		if (is_string($commands)) {
			$commands = json_decode($commands, true);
		}
		return is_array($commands) && isset($commands['primitive']);
	}

	/**
	 * Return an unreported claim to the queue.
	 *
	 * A claim that never comes back is the normal consequence of an agent
	 * crashing mid-job, and it is also what a replayed claim would leave
	 * behind. Either way the job is wedged in 'running', holding the per-node
	 * concurrency lock, and nothing else for that node can move. Returning it
	 * to pending with a counted attempt turns a wedge into a delay; after
	 * MAX_CLAIM_ATTEMPTS it fails outright, because a job that kills three
	 * agents is not going to succeed on the fourth.
	 *
	 * @param int|null $node_id Sweep only this node's claims. A polling agent
	 *   passes its own id — the lock it cares about is its own, and a
	 *   fleet-wide scan on every poll of every node is a lot of scanning to
	 *   answer a question about one machine. The scheduled pass passes null,
	 *   which is what covers an agent that never polls again.
	 * @return int How many jobs were acted on.
	 */
	static function requeueStaleClaims($node_id = null) {
		$db = DbConnector::get_instance()->get_db_link();
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::CLAIM_TIMEOUT_SECONDS);

		$sql = "SELECT mjb_id, mjb_claim_attempts FROM mjb_management_jobs
			 WHERE mjb_status = 'running'
			   AND mjb_delete_time IS NULL
			   AND jsonb_exists(mjb_commands, 'primitive')
			   AND COALESCE(mjb_started_time, mjb_create_time) < ?";
		$args = [$cutoff];
		if ($node_id !== null) {
			$sql .= ' AND mjb_mgn_node_id = ?';
			$args[] = (int)$node_id;
		}

		$q = $db->prepare($sql);
		$q->execute($args);
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$acted = 0;
		foreach ($rows as $row) {
			$attempts = (int)$row['mjb_claim_attempts'];
			if ($attempts >= self::MAX_CLAIM_ATTEMPTS) {
				$fail = $db->prepare(
					"UPDATE mjb_management_jobs
					 SET mjb_status = 'failed',
					     mjb_error_message = ?,
					     mjb_completed_time = now(),
					     mjb_update_time = now()
					 WHERE mjb_id = ? AND mjb_status = 'running'"
				);
				$fail->execute([
					'Claimed ' . $attempts . ' times by the node agent without a result each time. '
					. 'The agent may be crashing on this job; check the node before re-running it.',
					$row['mjb_id'],
				]);
			} else {
				$requeue = $db->prepare(
					"UPDATE mjb_management_jobs
					 SET mjb_status = 'pending',
					     mjb_started_time = NULL,
					     mjb_output = COALESCE(mjb_output, '') || ?,
					     mjb_update_time = now()
					 WHERE mjb_id = ? AND mjb_status = 'running'"
				);
				$requeue->execute([
					"\n[The node agent claimed this job and did not report back within "
						. self::CLAIM_TIMEOUT_SECONDS . " seconds. Returned to the queue.]\n",
					$row['mjb_id'],
				]);
			}
			$acted++;
		}
		return $acted;
	}

	function prepare() {
		if (empty($this->get('mjb_job_type'))) {
			throw new ManagementJobException('Job type is required.');
		}
		$this->set('mjb_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Canonical job-type list for the filter dropdowns. One source so the node
	 * detail Jobs tab and the all-jobs page cannot drift apart (U-5). The
	 * all-jobs page also offers publish_upgrade (which is not node-scoped).
	 *
	 * @param bool $include_publish Append 'publish_upgrade' (all-jobs page).
	 * @return string[]
	 */
	static function filterTypes($include_publish = false) {
		$types = [
			'check_status', 'backup_database', 'backup_project',
			'copy_database', 'copy_database_local', 'restore_database',
			'restore_project', 'restore_chain', 'apply_update', 'decommission_node',
			'backup_run', 'run_command',
		];
		if ($include_publish) {
			$types[] = 'publish_upgrade';
		}
		return $types;
	}

	/**
	 * The subset of job types that count as "database operations" — used to
	 * filter the Recent Database Operations table. Derived from filterTypes()
	 * so it stays a genuine subset of the canonical list (U-5).
	 *
	 * @return string[]
	 */
	static function databaseOpTypes() {
		$db_ops = ['copy_database', 'copy_database_local', 'restore_database'];
		return array_values(array_intersect(self::filterTypes(), $db_ops));
	}

	/**
	 * The newest non-deleted job of a given type for a node, or null if none.
	 *
	 * Replaces the copy-pasted "SELECT mjb_id ... ORDER BY mjb_id DESC LIMIT 1"
	 * query the node detail page repeated for install banners, SSL status, the
	 * last check, backup-scan state, and the install-retry param carry-forward.
	 * Returns a fully loaded model so callers can read whatever field they need
	 * (->key for a link, ->get('mjb_status'), ->get('mjb_parameters'), ...).
	 *
	 * @param int    $node_id
	 * @param string $type
	 * @return ManagementJob|null
	 */
	static function latestForNode($node_id, $type) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_id FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL
			 ORDER BY mjb_id DESC LIMIT 1"
		);
		$q->execute([(int)$node_id, (string)$type]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return null;
		}
		return new ManagementJob($row['mjb_id'], TRUE);
	}
}

class MultiManagementJob extends SystemMultiBase {
	protected static $model_class = 'ManagementJob';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['node_id'])) {
			$filters['mjb_mgn_node_id'] = [$this->options['node_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['job_type'])) {
			$filters['mjb_job_type'] = [$this->options['job_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['status'])) {
			$filters['mjb_status'] = [$this->options['status'], PDO::PARAM_STR];
		}

		if (isset($this->options['external_order_item_id'])) {
			$filters['mjb_external_order_item_id'] = [$this->options['external_order_item_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['created_by'])) {
			$filters['mjb_created_by'] = [$this->options['created_by'], PDO::PARAM_INT];
		}


		return $this->_get_resultsv2('mjb_management_jobs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
