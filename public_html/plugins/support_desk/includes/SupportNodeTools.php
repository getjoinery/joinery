<?php
/**
 * SupportNodeTools — the observe words a support turn may ask a joined site's
 * agent for, and the one way it asks (specs/support_chatbot.md, The engine,
 * node group; Consent, the ledger and the floor).
 *
 * WORDS is the allowed list: a subset of the observe class, each reviewed as if
 * the caller were hostile, none of which changes anything. Each word is one
 * tool, and each tool's input schema is derived from the plane's mirror of the
 * word's closed parameter set in JobCommandBuilder, so the model can only name
 * a log, a table, a unit or a file the node would accept. A turn is offered
 * WORDS intersected with the words the node reported at its last poll: an
 * older agent gives the model fewer tools, never a refusal to explain.
 *
 * One tool call is one ManagementJob on the node's own job list, created by
 * the support desk's reserved user with the requester recorded as
 * support:{thread id} in the job's parameters, so the owner reads every line
 * the assistant read on the node page. The call waits for the job up to
 * CALL_CEILING_SECONDS; past it the tool says the site did not answer in time
 * and the turn continues (the job still finishes on the node's list). What
 * comes back is the node's own redacted output, handed to the model inside the
 * thread's untrusted envelope. The thread keeps no second copy: the job ledger
 * is the audit.
 *
 * Three layers decide whether a read happens, each on its own: the node's own
 * agent_log_access switch (the plane's builder refuses the log words while it
 * is off, in the node's words, and the node refuses them anyway); the
 * thread's live-read consent; and the per-thread daily allowance.
 *
 * @version 0.1.0
 */
class SupportNodeTools {

	/** The observe words a support turn may use. */
	const WORDS = array(
		'host_report', 'site_log', 'log_table_tail', 'unit_journal', 'file_head', 'disk_usage',
		'schema_probe', 'page_probe', 'container_inventory', 'listening_sockets', 'spam_learning_status',
	);

	/** How long one read waits for the node. */
	const CALL_CEILING_SECONDS = 60;

	/** Longest read handed to the model, in characters. */
	const RESULT_CAP = 12000;

	/** Seconds between looks at the job while waiting. */
	const POLL_SECONDS = 1.5;

	/** Whether the plane's agent machinery is here at all. */
	public static function available(): bool {
		return class_exists('JobCommandBuilder') && class_exists('ManagementJob') && class_exists('ManagedNode');
	}

	/**
	 * The words a turn on this node is offered: WORDS the node reported, in
	 * WORDS order. Empty when the node reports nothing usable.
	 */
	public static function offered($node): array {
		if (!self::available() || !$node) {
			return array();
		}
		$reported = AgentVocabulary::reported_words($node);
		if (AgentVocabulary::below_floor($node)) {
			return array();
		}
		return array_values(array_intersect(self::WORDS, $reported));
	}

	/** The words a node does not report, among WORDS: what the gap report counts. */
	public static function missing($node): array {
		if (!self::available() || !$node) {
			return array();
		}
		return array_values(array_diff(self::WORDS, self::offered($node)));
	}

	// ------------------------------------------------------------ schemas

	/** The tool description for a word. */
	public static function description(string $word): string {
		$d = array(
			'host_report'          => "Read the site's machine: services and their state, units that failed, disk and memory, certificates, updates waiting, the agent's own state. Counts and states, never content.",
			'site_log'             => "Read the last lines of one of the site's own log files (redacted on the site before they are sent). Refused while the site's owner has log access switched off.",
			'log_table_tail'       => "Read the newest rows of one of the site's log tables (logins, requests, events, form errors, webhooks), from a fixed column list, redacted on the site. Refused while log access is off.",
			'unit_journal'         => "Read why one service is in the state it is in: its state, systemd's recorded result, exit status and the last lines of its journal, redacted on the site. Refused while log access is off.",
			'file_head'            => "Read the first lines of one of the site's host configuration files (web server, PHP, mail, spam filter, cron, logrotate), with secrets masked on the site. Refused while log access is off.",
			'disk_usage'           => "Read how the site's disk is used: the largest directories and the free space, sizes only.",
			'schema_probe'         => "Describe one table of the site's own database: whether it exists, its columns, indexes and row count. No rows are read.",
			'page_probe'           => "Render one of the site's own pages as a throwaway visitor, member or administrator and report facts about the render (status, errors, size); nothing on the page is changed.",
			'container_inventory'  => "List the containers on the site's host: image, state, mounts and run settings.",
			'listening_sockets'    => "List the addresses and ports the site's machine listens on, with the program holding each.",
			'spam_learning_status' => "Report whether members' spam corrections reach the site's spam filter: counts only.",
		);
		return ($d[$word] ?? 'Read one fact about the site.')
			. ' Each call is one job on the site\'s own job list, visible to its owner.';
	}

	/** The input schema for a word, from the plane's closed sets. */
	public static function inputSchema(string $word): array {
		$none = array('type' => 'object', 'properties' => new stdClass());
		switch ($word) {
			case 'site_log':
				return array('type' => 'object', 'properties' => array(
					'file'     => array('type' => 'string', 'enum' => array_keys(JobCommandBuilder::SITE_LOG_FILES),
						'description' => self::labels(JobCommandBuilder::SITE_LOG_FILES)),
					'previous' => array('type' => 'boolean', 'description' => 'Read the most recent rotation instead of the current file.'),
					'lines'    => array('type' => 'integer', 'minimum' => 1, 'maximum' => JobCommandBuilder::LOG_MAX_COUNT),
				), 'required' => array('file'));
			case 'log_table_tail':
				return array('type' => 'object', 'properties' => array(
					'table' => array('type' => 'string', 'enum' => array_keys(JobCommandBuilder::LOG_TABLES),
						'description' => self::labels(JobCommandBuilder::LOG_TABLES)),
					'rows'  => array('type' => 'integer', 'minimum' => 1, 'maximum' => JobCommandBuilder::LOG_MAX_COUNT),
				), 'required' => array('table'));
			case 'unit_journal':
				return array('type' => 'object', 'properties' => array(
					'unit'  => array('type' => 'string', 'enum' => array_keys(JobCommandBuilder::UNIT_JOURNAL_UNITS),
						'description' => self::labels(JobCommandBuilder::UNIT_JOURNAL_UNITS)),
					'lines' => array('type' => 'integer', 'minimum' => 1, 'maximum' => JobCommandBuilder::LOG_MAX_COUNT),
				), 'required' => array('unit'));
			case 'file_head':
				return array('type' => 'object', 'properties' => array(
					'file'  => array('type' => 'string', 'enum' => array_keys(JobCommandBuilder::FILE_HEAD_FILES),
						'description' => self::labels(JobCommandBuilder::FILE_HEAD_FILES)),
					'lines' => array('type' => 'integer', 'minimum' => 1, 'maximum' => JobCommandBuilder::FILE_HEAD_MAX_LINES),
				), 'required' => array('file'));
			case 'schema_probe':
				return array('type' => 'object', 'properties' => array(
					'table' => array('type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$',
						'description' => 'A table name, e.g. usr_users.'),
				), 'required' => array('table'));
			case 'page_probe':
				return array('type' => 'object', 'properties' => array(
					'page'   => array('type' => 'string', 'description' => 'A page path on the site, lowercase, no query string, e.g. /events.'),
					'viewer' => array('type' => 'string', 'enum' => array_keys(JobCommandBuilder::PAGE_PROBE_VIEWERS)),
				), 'required' => array('page', 'viewer'));
			default:
				return $none;
		}
	}

	private static function labels(array $map): string {
		$out = array();
		foreach ($map as $k => $label) {
			$out[] = $k . ' = ' . $label;
		}
		return implode('; ', $out);
	}

	/** The builder envelope for a word and its input; throws with the reason when the plane refuses. */
	private static function build($node, string $word, array $in): array {
		switch ($word) {
			case 'site_log':
				return JobCommandBuilder::build_site_log($node, (string)($in['file'] ?? ''), !empty($in['previous']), (int)($in['lines'] ?? 100));
			case 'log_table_tail':
				return JobCommandBuilder::build_log_table_tail($node, (string)($in['table'] ?? ''), (int)($in['rows'] ?? 50));
			case 'unit_journal':
				return JobCommandBuilder::build_unit_journal($node, (string)($in['unit'] ?? ''), (int)($in['lines'] ?? 100));
			case 'file_head':
				return JobCommandBuilder::build_file_head($node, (string)($in['file'] ?? ''), (int)($in['lines'] ?? 200));
			case 'schema_probe':
				return JobCommandBuilder::build_schema_probe($node, (string)($in['table'] ?? ''));
			case 'page_probe':
				return JobCommandBuilder::build_page_probe($node, (string)($in['page'] ?? ''), (string)($in['viewer'] ?? ''));
			case 'host_report':
				return JobCommandBuilder::build_host_report($node);
			case 'disk_usage':
				return JobCommandBuilder::build_disk_usage($node);
			case 'container_inventory':
				return JobCommandBuilder::build_container_inventory($node);
			case 'listening_sockets':
				return JobCommandBuilder::build_listening_sockets($node);
			case 'spam_learning_status':
				return JobCommandBuilder::build_spam_learning_status($node);
		}
		throw new Exception("'$word' is not a word the support desk reads.");
	}

	// ------------------------------------------------------------ running

	/**
	 * Ask the node for one word and wait for it. Returns the tool result
	 * ['content' => string, 'is_error' => bool].
	 */
	public static function run(SupportTurnContext $ctx, string $word, array $input): array {
		if (!in_array($word, self::WORDS, true)) {
			return array('content' => "'$word' is not a word the support desk reads.", 'is_error' => true);
		}
		$thread = $ctx->thread();
		$node = $thread->node();
		if (!self::available() || !$node) {
			return array('content' => 'This site is not joined to this management node, so nothing on it can be read from here.', 'is_error' => true);
		}
		if (!(bool)$thread->get('sth_consent_live_reads')) {
			return array('content' => 'The site has not allowed the support desk to read it. Answer from the documentation, or ask the person to allow live reads in the Help panel.', 'is_error' => true);
		}
		if (!in_array($word, self::offered($node), true)) {
			SupportGaps::noteMissingWord($node, $word);
			return array('content' => "The site's agent does not offer '$word' (agent " . AgentVocabulary::version($node) . ').', 'is_error' => true);
		}
		$per_day = SupportDesk::intSetting('support_desk_node_reads_per_thread', 20);
		if (!SupportThread::takeNodeRead((int)$thread->key, $per_day)) {
			return array('content' => 'This thread has used its ' . $per_day . ' reads of the site for today. Answer from what has been read, or hand the thread to a person.', 'is_error' => true);
		}

		try {
			$built = self::build($node, $word, $input);
		} catch (\Throwable $e) {
			// The plane's refusal, in the node's words (the log switch, a value
			// outside the closed set): the model repeats it.
			return array('content' => 'Refused: ' . $e->getMessage(), 'is_error' => true);
		}

		$requested_by = 'support:' . (int)$thread->key;
		try {
			$job = ManagementJob::createFromBuild((int)$node->key, $word, $built,
				array('requested_by' => $requested_by), SupportDesk::systemUserId());
		} catch (\Throwable $e) {
			return array('content' => 'The read could not be queued: ' . $e->getMessage(), 'is_error' => true);
		}
		$job_id = (int)$job->key;

		$deadline = microtime(true) + self::CALL_CEILING_SECONDS;
		while (true) {
			$job = new ManagementJob($job_id, TRUE);
			$status = (string)$job->get('mjb_status');
			if ($status === 'completed' || $status === 'failed') {
				break;
			}
			if (microtime(true) >= $deadline) {
				return array('content' => "The site did not answer in time. The read is job #$job_id on the site's job list and finishes there; it is not part of this answer.", 'is_error' => true);
			}
			if ($ctx->shouldAbort()) {
				return array('content' => 'Cancelled by the person.', 'is_error' => true);
			}
			usleep((int)(self::POLL_SECONDS * 1000000));
		}

		$nonce = $ctx->untrustedNonce();
		if ($status === 'failed') {
			$outcome = (string)$job->get('mjb_agent_outcome');
			$why = trim((string)$job->get('mjb_error_message')) ?: 'no reason given';
			return array('content' => ($outcome === 'refused' ? 'The site refused the read (job #' . $job_id . "):\n" : 'The read failed (job #' . $job_id . "):\n")
				. UntrustedEnvelope::wrapBlock(mb_substr($why, 0, 2000), $nonce), 'is_error' => true);
		}
		$text = (string)$job->transcript();
		$truncated = mb_strlen($text) > self::RESULT_CAP;
		if ($truncated) {
			$text = mb_substr($text, 0, self::RESULT_CAP);
		}
		return array('content' => 'What the site returned for ' . $word . ' (job #' . $job_id . ', redacted on the site'
			. ($truncated ? ', cut to ' . self::RESULT_CAP . ' characters' : '') . "):\n"
			. UntrustedEnvelope::wrapBlock($text, $nonce), 'is_error' => false);
	}
}
