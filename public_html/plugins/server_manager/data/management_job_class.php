<?php
/**
 * ManagementJob - A queued, running, or completed server management operation.
 *
 * @version 1.14 - BOOTSTRAP_JOB_TYPES: retire_install_password joins install_node as a job the plane
 *                 runs itself over the provision's sealed password, so both start 'queued'
 * @version 1.13 - rerun(): a new job carrying this job's work in whichever shape it has. A
 *                  primitive job re-run through createJob(steps) lost its envelope and
 *                  completed green having done nothing; rerun() copies the envelope
 * @version 1.12 - backup_database and backup_project leave the job-type filter (retired ops)
 * @version 1.11 - createFromBuild routes a probe envelope to NodeHealthProbe, which completes it
 *                 in-request and files an already-finished row
 * @version 1.10 - claim budgets for the three restore primitives, set before anything can
 *                 dispatch them: the plane must never give up on a restore the node is still
 *                 running, and a restore is the worst job to hand out twice
 * @version 1.9 - a claim budget for apply_update, which works for up to an hour on the node
 * @version 1.8 - activeOrRecentForNode: the dedupe test for work the plane queues on its own, so a
 *                reconciler that notices a condition fleet-wide queues one job per node, not per notice
 * @version 1.7 - mjb_agent_outcome records the wire outcome verbatim, so a refusal is countable
 *                without matching the text of an error message
 * @version 1.6 - primitive jobs for the agent channel: createPrimitiveJob, claim/report, and the
 *                claim timeout that returns an unreported job to pending instead of wedging it
 * @version 1.6 - run_command and the copy_database types leave filterTypes (A1/A3 retirement);
 *                list_backups joins it. Historical rows of retired types still render
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
	 * The jobs the plane runs itself, over a keyless provision's sealed install
	 * password, because the machine has no admitted agent to run them: the
	 * bootstrap install, and the retirement of that same password once the
	 * agent is admitted. InstallJobExecutor claims exactly these and nothing
	 * else; they start 'queued', a status the node agent's claim ('pending'
	 * only) never matches, so the two never fight over a job.
	 */
	const BOOTSTRAP_JOB_TYPES = array('install_node', 'retire_install_password');

	/**
	 * Create a new job from a command builder result.
	 */
	static function createJob($node_id, $job_type, $steps, $parameters, $created_by) {
		// A primitive envelope is not a step list, and burying one here is silent
		// and expensive. json_encode(['steps' => $envelope]) produces
		// {"steps":{"primitive":...}} — no top-level "primitive", so
		// isPrimitiveJob() says no, the job goes to the step executor, and the
		// agent dies on it with "cannot unmarshal object into ... []main.Step".
		//
		// That is not hypothetical either. It took the nightly fleet backup off
		// every paired node the moment build_backup_run() grew a primitive
		// branch: the builder started returning an envelope and four call sites
		// kept handing it to createJob, so backups failed at 04:00 with a JSON
		// error and nothing said the word "backup".
		//
		// createFromBuild() is the entry point that reads a build result and
		// picks the right storage for it. Refusing here rather than coping means
		// a builder that gains a primitive branch breaks its callers loudly, at
		// the moment they are wrong, instead of at 4am on a node.
		if (is_array($steps) && isset($steps['primitive'])) {
			throw new Exception(
				"createJob() was handed a primitive envelope for '{$job_type}' — it would be stored "
				. "where isPrimitiveJob() cannot see it and the node would refuse to parse it. "
				. "Call ManagementJob::createFromBuild() instead; it stores either shape correctly."
			);
		}

		$job = new ManagementJob(NULL);
		$job->set('mjb_mgn_node_id', $node_id);
		$job->set('mjb_job_type', $job_type);
		// A bootstrap job (see BOOTSTRAP_JOB_TYPES) is executed by the plane-side
		// InstallJobExecutor over the provision's sealed install password, not by
		// a node agent, and starts 'queued' so no agent ever claims it. Everything
		// else is 'pending'.
		$job->set('mjb_status', in_array($job_type, self::BOOTSTRAP_JOB_TYPES, true) ? 'queued' : 'pending');
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

	/**
	 * A claim not reported within this many seconds is treated as lost — the
	 * floor, used for any primitive without its own entry below.
	 */
	const CLAIM_TIMEOUT_SECONDS = 900;

	/**
	 * How long each primitive is allowed to hold a claim, in seconds.
	 *
	 * MUST NOT be shorter than the deadline the agent applies to itself. If the
	 * plane gives up first it requeues a job that is still running, and the node
	 * starts a second copy of work the first copy has not finished — two
	 * concurrent backups writing one chain. The agent is the authority on how
	 * long its own primitives may take (primitives.Primitive.Timeout, compiled
	 * in); these are that value plus room for the result to be posted, and
	 * primitive_transport_parity_test asserts none of them has fallen behind the
	 * agent's own declaration.
	 *
	 * Anything absent gets CLAIM_TIMEOUT_SECONDS, which suits every primitive
	 * that reads a directory or writes one small file.
	 */
	const PRIMITIVE_CLAIM_BUDGETS = [
		'backup_run'            => 15720, // 4h20m + slack
		'upload_backup'         => 5220,  // 85m + slack
		'run_plugin_installers' => 1020,  // 15m + slack
		// 60m + slack. An upgrade downloads a release, deploys it, runs
		// migrations, runs the deploy-tier suite against the deployed tree and
		// then every host installer. Requeuing one that is still running would
		// start a second upgrade on a node mid-deploy, which is the worst
		// moment on the list to do it twice.
		'apply_update'          => 4200,
		// The three restores, budgeted before they are dispatchable
		// (specs/restore_over_agent_primitives.md). Deliberately generous:
		// the safety property is one-directional — a plane budget longer than
		// the node's own deadline only delays recovery from a genuine crash,
		// while a shorter one requeues a restore that is still running and
		// starts a SECOND restore over the first, which is the one job in this
		// vocabulary where doing it twice concurrently destroys the thing it
		// was recovering. Sized above the SSH path's own step timeouts (3600
		// for a database or project, 7200 for a chain's restore step) so the
		// agent's declared Timeout has room underneath whatever it lands on.
		// The agent declares 70m for restore_database; this is that plus room to
		// post the result, not the same number — a budget equal to the node's
		// deadline requeues a job whose result is still in flight.
		//
		// Each of these carries the agent's APPROVAL WINDOW as well as its work.
		// A destructive job is claimed, and then held while the node's own
		// operator opens a challenge on the node's own site and answers it — the
		// wait happens inside the claim, because a challenge is bound to a
		// specific job and re-dispatching would issue a different one. ONE HOUR
		// of that is inside every number below (it was fifteen minutes until
		// 2026-08-30). A budget sized for the restore alone would requeue a job
		// during the approval the restore requires — and requeuing a restore
		// starts a second one over the first.
		//
		// These grow with the window, which is the cost that bounds it: see
		// `deferred_destructive_approval.md`, where the wait stops being held
		// inside the claim and these numbers go back to being about the work.
		'restore_database'      => 8400,  // 70m + 60m approval + slack
		'restore_project'       => 8400,  // 70m + 60m approval + slack
		'restore_chain'         => 15720, // 2h20m + 60m approval, with room
		// Bringing a backup back off the shelf. Mirrors upload_backup's budget,
		// because it is the same transfer in the other direction and S3Signer's
		// window is what bounds both.
		'download_backup'       => 5220,  // 85m + slack
		// A whole chain: a full plus every incremental, each of them possibly
		// gigabytes. Sized to the agent's own declaration for it.
		'stage_chain'           => 8700,  // 2h20m + slack
		// Removing a container site from its host: the teardown is minutes,
		// the victim's approval window is the hour. Sized above the agent's
		// declared 15m + ApprovalWindow.
		'decommission_site'     => 5400,  // 15m + 60m approval + slack
	];

	/** The shortest budget in play — the SQL prefilter cannot use less. */
	private static function shortest_claim_budget() {
		return min(array_merge([self::CLAIM_TIMEOUT_SECONDS], self::PRIMITIVE_CLAIM_BUDGETS));
	}

	/** The claim budget for a job, read from the primitive it names. */
	private static function claim_budget_for($commands) {
		if (is_string($commands)) {
			$commands = json_decode($commands, true);
		}
		$name = is_array($commands) ? ($commands['primitive'] ?? '') : '';
		return self::PRIMITIVE_CLAIM_BUDGETS[$name] ?? self::CLAIM_TIMEOUT_SECONDS;
	}

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
	static function createPrimitiveJob($node_id, $job_type, $primitive, $params, $created_by, $record_params = null) {
		// $params is the envelope — what the node receives. $record_params is
		// what the plane writes down about the job (mjb_parameters): the
		// envelope plus caller context its own result processing needs, like
		// decommission's victim_node_id. The node reads mjb_commands only, so
		// recorded context never reaches the wire.
		$record_params = $record_params ?? $params;
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
		$job->set('mjb_parameters', $record_params ? json_encode($record_params) : null);
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
		// A probe is not queued work. NodeHealthProbe reaches the machine over
		// HTTP or TCP inside this request, folds what it learned onto the node,
		// and files a row that is already complete. It is handled here so that
		// callers keep dispatching an operation rather than choosing a transport.
		if (is_array($built) && isset($built['probe'])) {
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeHealthProbe.php'));
			return NodeHealthProbe::run_and_record(new ManagedNode($node_id, TRUE), $created_by);
		}
		if (is_array($built) && isset($built['primitive'])) {
			// The caller's $params survive into the job record alongside the
			// envelope's (envelope wins on a shared key — those are the
			// validated values). Dropping them here is how decommission's
			// victim_node_id once vanished and a verified teardown finalized
			// the HOST's record instead of the victim's.
			$record = array_merge(
				is_array($params) ? $params : [],
				is_array($built['params'] ?? null) ? $built['params'] : []
			);
			return self::createPrimitiveJob($node_id, $job_type, $built['primitive'],
				$built['params'] ?? [], $created_by, $record ?: null);
		}
		return self::createJob($node_id, $job_type, $built, $params, $created_by);
	}

	/**
	 * Queue a fresh job that does what this one did, for the same node, with
	 * the same parameters. The unit of work is copied in whichever shape the
	 * job carries: a primitive envelope goes back through createPrimitiveJob
	 * (with the record params the plane wrote down about it), a step list back
	 * through createJob. Reading only the `steps` key silently turned a
	 * primitive re-run into a zero-step job that completed green having done
	 * nothing — the one outcome a re-run must never produce, so a job with no
	 * work in it refuses here instead.
	 */
	function rerun($created_by) {
		$commands = $this->get('mjb_commands');
		if (is_string($commands)) {
			$commands = json_decode($commands, true);
		}
		$parameters = $this->get('mjb_parameters');
		if (is_string($parameters)) {
			$parameters = json_decode($parameters, true);
		}
		$node_id  = $this->get('mjb_mgn_node_id');
		$job_type = $this->get('mjb_job_type');

		if ($this->isPrimitiveJob()) {
			return self::createPrimitiveJob($node_id, $job_type, $commands['primitive'],
				$commands['params'] ?? [], $created_by, $parameters ?: null);
		}
		$steps = is_array($commands) ? ($commands['steps'] ?? null) : null;
		if (!is_array($steps) || count($steps) === 0) {
			throw new ManagementJobException(
				"Job #{$this->key} ({$job_type}) carries no steps and no primitive — re-running it "
				. 'would queue a job that completes without doing anything.');
		}
		return self::createJob($node_id, $job_type, $steps, $parameters ?: null, $created_by);
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

		// Select on the SHORTEST budget, then filter each row against its own
		// primitive's. A single global cutoff cannot be right for a vocabulary
		// whose members range from a directory read to a four-hour backup.
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::shortest_claim_budget());

		$sql = "SELECT mjb_id, mjb_claim_attempts, mjb_commands,
			        EXTRACT(EPOCH FROM (now() - COALESCE(mjb_started_time, mjb_create_time)))::int AS running_for
			 FROM mjb_management_jobs
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
			// Has this job actually outrun ITS OWN budget? A backup_run is
			// allowed hours; requeueing it at fifteen minutes does not rescue a
			// wedge, it starts a SECOND backup of the same node while the first
			// is still writing — and the agent's own poll is what triggers it,
			// because the endpoint sweeps this node's claims on every poll.
			$budget = self::claim_budget_for($row['mjb_commands']);
			if ((int)$row['running_for'] < $budget) {
				continue;
			}

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
						. $budget . " seconds, this primitive's whole budget. Returned to the queue.]\n",
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
		// Retired types (run_command, both copy_database kinds, backup_database,
		// backup_project) are not offered: historical rows keep their type strings
		// and still render, but a filter is for kinds of job that can still happen.
		$types = [
			'check_status',
			'restore_database', 'list_backups',
			'restore_project', 'restore_chain', 'apply_update', 'decommission_node',
			'backup_run',
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
		$db_ops = ['restore_database'];
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

	/**
	 * Is this node already covered for a job of this type — either one waiting or
	 * in flight, or one that finished recently enough that asking again would
	 * measure the same thing?
	 *
	 * The dedupe test for work the PLANE queues on its own rather than work a
	 * person asked for. A person clicking a button twice means it; a reconciler
	 * that queues a job every time it notices a condition queues one per notice,
	 * and conditions are noticed in bulk. Result processing runs on page render
	 * across the whole fleet at once, so one status sweep put 33 identical
	 * recovery-key reports into the queue in a minute — nine nodes' worth, several
	 * times over, each measuring a fact that changes at a human's pace.
	 *
	 * A FAILED recent job deliberately does not count as cover: a measurement that
	 * did not happen is a reason to try again, not a reason to wait.
	 *
	 * @param int    $node_id
	 * @param string $type
	 * @param int    $recent_seconds how long a completed job keeps counting as cover
	 * @return bool
	 */
	static function activeOrRecentForNode($node_id, $type, $recent_seconds) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT 1 FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL
			   AND (mjb_status IN ('pending', 'running')
			        OR (mjb_status = 'completed' AND mjb_completed_time >= ?))
			 LIMIT 1"
		);
		$q->execute([
			(int)$node_id,
			(string)$type,
			gmdate('Y-m-d H:i:s', time() - (int)$recent_seconds),
		]);
		return (bool)$q->fetchColumn();
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
