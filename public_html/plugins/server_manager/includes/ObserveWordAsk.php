<?php
/**
 * ObserveWordAsk — ask a joined node one observe word and wait for its answer.
 *
 * The one way anything off the node page asks a node a question: the support
 * desk's tool turns (SupportNodeTools) and a working session's API call
 * (server_manager/ask_node). Each ask is one ManagementJob on the node's own
 * job list, so the owner reads every answer on the node page, and the job
 * ledger is the audit.
 *
 * WORDS is the closed list: observe words only, each of which changes nothing
 * on the node (specs/agent_recipes_and_vocabulary.md, Rules every word obeys).
 * build() maps a word and its input onto JobCommandBuilder's own builder, which
 * holds the closed parameter sets and refuses a value outside them, a node
 * whose agent does not report the word, and a log word while the node's owner
 * has log access switched off. A builder whose envelope names another
 * primitive than the word asked for is refused here, so the list cannot be
 * widened to an operate word by a builder's mapping.
 *
 * @version 1.2.0 - log_table_tail takes kind (the error table's kinds, agent 1.127.0)
 * @version 1.1.0 - mailbox_status, sent as spam_learning_status to an older agent
 * @version 1.0.0 - shared by SupportNodeTools and ask_node (the 2026-11-10 running-list entry)
 */
class ObserveWordAsk {

	/** Every observe word this class can ask. Callers narrow it (SupportNodeTools::WORDS). */
	const WORDS = array(
		'host_report', 'check_status', 'install_report', 'site_log', 'site_log_count', 'log_table_tail',
		'unit_journal', 'site_converger', 'file_head', 'disk_usage', 'largest_files', 'schema_probe',
		'page_probe', 'container_inventory', 'listening_sockets', 'site_state_report', 'net_health',
		'web_workers', 'mailbox_status', 'relay_status',
	);

	/** The older observe word a builder may send instead, to an agent that predates the word asked for. */
	const OLDER_NAMES = array('mailbox_status' => array('spam_learning_status'));

	/** Seconds between looks at the job while waiting. */
	const POLL_SECONDS = 1.5;

	/** Whether the plane's agent machinery is here at all. */
	public static function available(): bool {
		return class_exists('JobCommandBuilder') && class_exists('ManagementJob') && class_exists('ManagedNode');
	}

	/** The WORDS a node reported at its last poll, in WORDS order; empty below the agent floor. */
	public static function offered($node, array $words = self::WORDS): array {
		if (!self::available() || !$node || AgentVocabulary::below_floor($node)) {
			return array();
		}
		$reported = AgentVocabulary::reported_words($node);
		foreach (self::OLDER_NAMES as $word => $older) {
			if (array_intersect($older, $reported)) {
				$reported[] = $word;
			}
		}
		return array_values(array_intersect(array_intersect($words, self::WORDS), $reported));
	}

	/**
	 * The builder envelope for a word and its input. Throws with the plane's
	 * reason (a value outside the closed set, the log switch, an old agent).
	 */
	public static function build($node, string $word, array $in): array {
		if (!in_array($word, self::WORDS, true)) {
			throw new Exception("'$word' is not an observe word that can be asked from here.");
		}
		switch ($word) {
			case 'site_log':
				$built = JobCommandBuilder::build_site_log($node, (string)($in['file'] ?? ''), !empty($in['previous']), (int)($in['lines'] ?? 100));
				break;
			case 'site_log_count':
				$built = JobCommandBuilder::build_site_log_count($node, (int)($in['since'] ?? 0));
				break;
			case 'log_table_tail':
				$built = JobCommandBuilder::build_log_table_tail($node, (string)($in['table'] ?? ''), (int)($in['rows'] ?? 50),
					isset($in['event']) && $in['event'] !== '' ? (string)$in['event'] : null,
					isset($in['hours']) && $in['hours'] !== '' ? (int)$in['hours'] : null,
					isset($in['kind']) && $in['kind'] !== '' ? (string)$in['kind'] : null);
				break;
			case 'unit_journal':
				$built = JobCommandBuilder::build_unit_journal($node, (string)($in['unit'] ?? ''), (int)($in['lines'] ?? 100));
				break;
			case 'site_converger':
				$built = JobCommandBuilder::build_site_converger($node, (string)($in['site'] ?? ''), (int)($in['lines'] ?? 100));
				break;
			case 'file_head':
				$built = JobCommandBuilder::build_file_head($node, (string)($in['file'] ?? ''), (int)($in['lines'] ?? 200),
					isset($in['site']) && $in['site'] !== '' ? (string)$in['site'] : null);
				break;
			case 'schema_probe':
				$built = JobCommandBuilder::build_schema_probe($node, (string)($in['table'] ?? ''));
				break;
			case 'page_probe':
				$built = JobCommandBuilder::build_page_probe($node, (string)($in['page'] ?? ''), (string)($in['viewer'] ?? ''));
				break;
			case 'host_report':         $built = JobCommandBuilder::build_host_report($node); break;
			case 'check_status':        $built = JobCommandBuilder::build_check_status($node); break;
			case 'install_report':      $built = JobCommandBuilder::build_install_report($node); break;
			case 'disk_usage':          $built = JobCommandBuilder::build_disk_usage($node); break;
			case 'largest_files':       $built = JobCommandBuilder::build_largest_files($node); break;
			case 'container_inventory': $built = JobCommandBuilder::build_container_inventory($node); break;
			case 'listening_sockets':   $built = JobCommandBuilder::build_listening_sockets($node); break;
			case 'site_state_report':   $built = JobCommandBuilder::build_site_state_report($node); break;
			case 'net_health':          $built = JobCommandBuilder::build_net_health($node); break;
			case 'web_workers':         $built = JobCommandBuilder::build_web_workers($node); break;
			case 'mailbox_status':
				$built = JobCommandBuilder::build_mailbox_status($node, isset($in['domain']) ? (string)$in['domain'] : null);
				break;
			case 'relay_status':        $built = JobCommandBuilder::build_relay_status($node); break;
			default:
				throw new Exception("'$word' is not an observe word that can be asked from here.");
		}
		// check_status on a node with no agent is the plane's own health probe
		// over HTTP (NodeHealthProbe), which also only reads.
		$probe = ($word === 'check_status' && is_array($built) && isset($built['probe']));
		$primitive = is_array($built) ? ($built['primitive'] ?? null) : null;
		if (!$probe && $primitive !== $word && !in_array($primitive, self::OLDER_NAMES[$word] ?? array(), true)) {
			throw new Exception("The plane built something other than '$word' for it, so it was not queued.");
		}
		return $built;
	}

	/**
	 * Queue the word as a job on the node's list and wait for it to settle, up
	 * to $ceiling seconds. $abort, when given, is asked between looks and ends
	 * the wait early. Returns ['job' => ManagementJob, 'status' => 'completed' |
	 * 'failed' | 'waiting' | 'aborted']; a job still waiting finishes on the
	 * node's list. Throws when the word cannot be built or queued.
	 */
	public static function ask($node, string $word, array $in, string $requested_by, $created_by,
			int $ceiling, ?callable $abort = null): array {
		$built = self::build($node, $word, $in);
		$job = ManagementJob::createFromBuild((int)$node->key, $word, $built,
			array('requested_by' => $requested_by), $created_by);
		return self::waitFor($job, $ceiling, $abort);
	}

	/**
	 * Wait for a queued job to settle, up to $ceiling seconds, as ask() does.
	 * For a caller that queued its own job (mail_test_send, an operate word
	 * with its own action).
	 */
	public static function waitFor($job, int $ceiling, ?callable $abort = null): array {
		$job_id = (int)$job->key;
		$deadline = microtime(true) + $ceiling;
		while (true) {
			$job = new ManagementJob($job_id, TRUE);
			$status = (string)$job->get('mjb_status');
			if ($status === 'completed' || $status === 'failed') {
				return array('job' => $job, 'status' => $status);
			}
			if (microtime(true) >= $deadline) {
				return array('job' => $job, 'status' => 'waiting');
			}
			if ($abort && $abort()) {
				return array('job' => $job, 'status' => 'aborted');
			}
			usleep((int)(self::POLL_SECONDS * 1000000));
		}
	}
}
