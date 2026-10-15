<?php
/**
 * The support desk's tools (specs/support_chatbot.md, The engine). Each runs
 * only inside a support turn (SupportTurnContext) and refuses anywhere else,
 * so a recipe or a chat that names one gets nothing.
 *
 * Docs group: search_docs, read_doc_section. Handoff group: escalate,
 * file_bug_report. Plane group: node_overview, node_incidents,
 * node_backup_runs, node_jobs — the management node's own record of a joined
 * site, read from its own tables; nothing is asked of the site. Node group:
 * one tool per observe word, below, each a SupportNodeWordTool.
 *
 * Text that came from another machine (a node's notes, its error messages)
 * reaches the model inside the thread's envelope.
 *
 * @version 0.1.0
 */

class SupportSearchDocsTool implements RecipeToolInterface {
	public static function name(): string { return 'search_docs'; }

	public static function description(): string {
		return 'Search the documentation and FAQ of the release the person runs, and this node\'s public pages. '
			. 'Every word must match; when nothing does, any word may. Returns up to 8 sections: path, heading chain and an excerpt. '
			. 'Search with the words the docs would use; search again with other words before concluding the docs are silent.';
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => array(
			'query' => array('type' => 'string', 'description' => 'Words to search for, e.g. "restore backup storage".'),
		), 'required' => array('query'));
	}

	public function execute(array $input, ToolContext $ctx) {
		$sctx = SupportTools::context($ctx);
		if ($sctx === null) {
			return SupportTools::notHere();
		}
		$query = trim((string)($input['query'] ?? ''));
		if (!FullTextSearch::isSearchable($query)) {
			return array('content' => 'Give a few words to search for.', 'is_error' => true);
		}
		$hits = SupportDocIndex::search($sctx->release(), $query);
		if (!$hits) {
			return 'Nothing in the documentation of release ' . $sctx->release() . ' matches "' . $query . '". Try other words.';
		}
		$lines = array('Sections matching "' . $query . '" (release ' . $sctx->release() . '):');
		foreach ($hits as $i => $h) {
			$lines[] = ($i + 1) . '. ' . $h['path'] . ' — ' . $h['heading']
				. ($h['part'] > 0 ? ' (part ' . ($h['part'] + 1) . ')' : '')
				. ($h['kind'] === 'faq' || $h['kind'] === 'local_faq' ? ' [FAQ]' : '')
				. "\n   " . $h['snippet'];
		}
		$lines[] = 'Read a section with read_doc_section {path, heading} before relying on it.';
		return implode("\n", $lines);
	}
}

class SupportReadDocSectionTool implements RecipeToolInterface {
	public static function name(): string { return 'read_doc_section'; }

	public static function description(): string {
		return 'Read one section of the documentation, FAQ or a public page, by its path and heading (the heading chain '
			. 'search_docs shows, or just its last heading). An empty heading reads the whole document, cut to length.';
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => array(
			'path'    => array('type' => 'string', 'description' => 'e.g. docs/backups.md'),
			'heading' => array('type' => 'string', 'description' => 'e.g. "Backups > Restore" or "Restore"'),
		), 'required' => array('path', 'heading'));
	}

	public function execute(array $input, ToolContext $ctx) {
		$sctx = SupportTools::context($ctx);
		if ($sctx === null) {
			return SupportTools::notHere();
		}
		$path = trim((string)($input['path'] ?? ''));
		$heading = trim((string)($input['heading'] ?? ''));
		$section = SupportDocIndex::readSection($sctx->release(), $path, $heading);
		if ($section === null) {
			return array('content' => "There is no document '$path' in release " . $sctx->release() . '.', 'is_error' => true);
		}
		if (!$section['found']) {
			$list = array_slice($section['headings'], 0, 60);
			return array('content' => "'$path' has no section '$heading'. Its sections:\n- " . implode("\n- ", $list), 'is_error' => true);
		}
		if ($section['kind'] === SupportDocSection::KIND_LOCAL_FAQ && $section['ref_id']) {
			SupportFaqEntry::noteCited((int)$section['ref_id']);
		}
		$out = $section['text'];
		if ($section['truncated']) {
			$out .= "\n\n[Cut to " . SupportDocIndex::READ_CAP . ' characters; read a narrower heading for the rest.]';
		}
		// A public page and an operator's FAQ are this node's own words; the
		// release's docs are the platform's. Neither came from the asking site.
		return $out;
	}
}

class SupportEscalateTool implements RecipeToolInterface {
	public static function name(): string { return 'escalate'; }

	public static function description(): string {
		return 'Hand the thread to a person on the support desk. Use it when the documentation and the reads do not settle '
			. 'the question, when the person asks for a human, or when fixing it needs a change you cannot make. '
			. 'After it, end your answer: the person is told a human will reply in this thread.';
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => array(
			'reason' => array('type' => 'string', 'description' => 'One line for the person who picks it up: what is unresolved.'),
		), 'required' => array('reason'));
	}

	public function execute(array $input, ToolContext $ctx) {
		$sctx = SupportTools::context($ctx);
		if ($sctx === null) {
			return SupportTools::notHere();
		}
		SupportNotify::escalate($sctx->thread(), mb_substr(trim((string)($input['reason'] ?? '')), 0, 500));
		return 'The thread is now waiting on a person. End your answer now in one or two sentences; '
			. 'do not promise a time. The person will see a reply in this thread.';
	}
}

class SupportFileBugReportTool implements RecipeToolInterface {
	public static function name(): string { return 'file_bug_report'; }

	public static function description(): string {
		return 'File a bug report on this node from the thread, with the site\'s facts as it sent them. Use it when what '
			. 'the person describes is a defect in Joinery itself, not a setting or a question.';
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => array(
			'summary' => array('type' => 'string', 'description' => 'What is wrong, in a few sentences, for the developers.'),
		), 'required' => array('summary'));
	}

	public function execute(array $input, ToolContext $ctx) {
		$sctx = SupportTools::context($ctx);
		if ($sctx === null) {
			return SupportTools::notHere();
		}
		if (!class_exists('BugReportIntake')) {
			return array('content' => 'This node does not take bug reports.', 'is_error' => true);
		}
		$thread = $sctx->thread();
		$bundle = $thread->bundle();
		if (!$bundle) {
			return array('content' => 'This thread has no site facts to file a report with.', 'is_error' => true);
		}
		$summary = trim((string)($input['summary'] ?? ''));
		if ($summary === '') {
			return array('content' => 'Say what is wrong.', 'is_error' => true);
		}
		$comment = 'From support thread #' . (int)$thread->key . ":\n" . mb_substr($summary, 0, 4000);
		try {
			$report = BugReportIntake::receive(json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				$comment, null, (string)$thread->get('sth_sender_ip'));
		} catch (\Throwable $e) {
			return array('content' => 'The report was not filed: ' . $e->getMessage(), 'is_error' => true);
		}
		return 'Filed as bug report #' . (int)$report->key . ' on this node. Tell the person it is filed.';
	}
}

/** The management node's own record of a joined site: common reading. */
abstract class SupportPlaneTool {
	protected function node(ToolContext $ctx, &$error) {
		$sctx = SupportTools::context($ctx);
		if ($sctx === null) {
			$error = SupportTools::notHere();
			return null;
		}
		$node = SupportNodeTools::available() ? $sctx->thread()->node() : null;
		if (!$node) {
			$error = array('content' => 'This site is not joined to this management node.', 'is_error' => true);
			return null;
		}
		return $node;
	}

	protected function wrap(ToolContext $ctx, string $text): string {
		return UntrustedEnvelope::wrapBlock($text, $ctx->untrustedNonce());
	}

	protected static function count(array $input, string $key, int $default, int $max): int {
		$n = (int)($input[$key] ?? $default);
		return max(1, min($max, $n));
	}
}

class SupportNodeOverviewTool extends SupportPlaneTool implements RecipeToolInterface {
	public static function name(): string { return 'node_overview'; }

	public static function description(): string {
		return "The management node's record of the person's site: its version, its agent's version and last poll, "
			. 'whether log access is on, script trust, install and certificate state, the last backup, and the reads its agent offers.';
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => new stdClass());
	}

	public function execute(array $input, ToolContext $ctx) {
		$node = $this->node($ctx, $error);
		if ($node === null) {
			return $error;
		}
		$f = array(
			'Site'                 => $node->get('mgn_site_url'),
			'Joinery version'      => $node->get('mgn_joinery_version'),
			'Agent version'        => $node->get('mgn_agent_version'),
			'Agent last poll (UTC)' => $node->get('mgn_agent_last_poll'),
			'Log access'           => $node->get('mgn_agent_log_access') ?: 'unknown',
			'Script trust'         => trim($node->get('mgn_script_trust') . ' ' . $node->get('mgn_script_trust_reason')),
			'Install state'        => $node->get('mgn_install_state'),
			'Certificate'          => $node->get('mgn_cert_problem') ?: ($node->get('mgn_cert_expiry_ts') ? 'expires ' . gmdate('Y-m-d', (int)$node->get('mgn_cert_expiry_ts')) : 'unknown'),
			'Uptime check'         => $node->get('mgn_uptime_last_status'),
			'Last backup'          => trim($node->get('mgn_last_backup_time') . ' ' . $node->get('mgn_last_backup_outcome')),
			'Reads its agent offers' => implode(', ', SupportNodeTools::offered($node)) ?: 'none',
		);
		$lines = array();
		foreach ($f as $k => $v) {
			$lines[] = $k . ': ' . (($v === null || $v === '') ? '—' : (string)$v);
		}
		return "The management node's record of this site:\n" . $this->wrap($ctx, implode("\n", $lines));
	}
}

class SupportNodeIncidentsTool extends SupportPlaneTool implements RecipeToolInterface {
	public static function name(): string { return 'node_incidents'; }

	public static function description(): string {
		return "Incidents the management node holds for the person's site: what was noticed, when, how severe, and the latest note.";
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => array(
			'open_only' => array('type' => 'boolean', 'description' => 'Only incidents still open (default true).'),
		));
	}

	public function execute(array $input, ToolContext $ctx) {
		$node = $this->node($ctx, $error);
		if ($node === null) {
			return $error;
		}
		$open_only = !array_key_exists('open_only', $input) || !empty($input['open_only']);
		$sql = 'SELECT inc_incident_record_id, inc_status, inc_severity, inc_title, inc_reason, inc_source,
				inc_opened_time, inc_last_seen_time, inc_last_note, inc_closed_time, inc_close_reason
			FROM inc_incident_records WHERE inc_mgn_managed_node_id = ? AND inc_delete_time IS NULL'
			. ($open_only ? " AND inc_status = 'open'" : '')
			. ' ORDER BY inc_incident_record_id DESC LIMIT 15';
		$stmt = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$stmt->execute(array((int)$node->key));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		if (!$rows) {
			return $open_only ? 'No open incidents for this site.' : 'No incidents recorded for this site.';
		}
		$lines = array();
		foreach ($rows as $r) {
			$lines[] = '#' . $r['inc_incident_record_id'] . ' [' . $r['inc_status'] . ', ' . $r['inc_severity'] . '] '
				. ($r['inc_title'] ?: $r['inc_source']) . ' — opened ' . $r['inc_opened_time']
				. ', last seen ' . $r['inc_last_seen_time']
				. ($r['inc_reason'] ? "\n  reason: " . mb_substr($r['inc_reason'], 0, 400) : '')
				. ($r['inc_last_note'] ? "\n  latest note: " . mb_substr($r['inc_last_note'], 0, 400) : '')
				. ($r['inc_closed_time'] ? "\n  closed " . $r['inc_closed_time'] . ': ' . mb_substr((string)$r['inc_close_reason'], 0, 200) : '');
		}
		return "Incidents for this site:\n" . $this->wrap($ctx, implode("\n", $lines));
	}
}

class SupportNodeBackupRunsTool extends SupportPlaneTool implements RecipeToolInterface {
	public static function name(): string { return 'node_backup_runs'; }

	public static function description(): string {
		return "The person's site's most recent backup runs as the management node recorded them: when, outcome, and the error when one failed.";
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => array(
			'count' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 20),
		));
	}

	public function execute(array $input, ToolContext $ctx) {
		$node = $this->node($ctx, $error);
		if ($node === null) {
			return $error;
		}
		$n = self::count($input, 'count', 5, 20);
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT mjb_management_job_id, mjb_status, mjb_agent_outcome, mjb_create_time, mjb_completed_time, mjb_error_message
			   FROM mjb_management_jobs
			  WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type IN ('backup_run', 'backup_database', 'backup_project')
			    AND mjb_delete_time IS NULL
			  ORDER BY mjb_management_job_id DESC LIMIT " . $n);
		$stmt->execute(array((int)$node->key));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$lines = array('Last backup on record: ' . ($node->get('mgn_last_backup_time') ?: 'none')
			. ' (' . ($node->get('mgn_last_backup_outcome') ?: 'no outcome') . ')');
		foreach ($rows as $r) {
			$lines[] = 'job #' . $r['mjb_management_job_id'] . ' ' . $r['mjb_create_time'] . ' — ' . $r['mjb_status']
				. ($r['mjb_agent_outcome'] ? ' (' . $r['mjb_agent_outcome'] . ')' : '')
				. ($r['mjb_error_message'] ? "\n  error: " . mb_substr($r['mjb_error_message'], 0, 400) : '');
		}
		return "Backups of this site:\n" . $this->wrap($ctx, implode("\n", $lines));
	}
}

class SupportNodeJobsTool extends SupportPlaneTool implements RecipeToolInterface {
	public static function name(): string { return 'node_jobs'; }

	public static function description(): string {
		return "The most recent jobs on the person's site's job list (upgrades, backups, reads): type, outcome, when, and the error of a failed one.";
	}

	public static function inputSchema(): array {
		return array('type' => 'object', 'properties' => array(
			'count' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 30),
		));
	}

	public function execute(array $input, ToolContext $ctx) {
		$node = $this->node($ctx, $error);
		if ($node === null) {
			return $error;
		}
		$n = self::count($input, 'count', 10, 30);
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT mjb_management_job_id, mjb_job_type, mjb_status, mjb_agent_outcome, mjb_create_time, mjb_error_message
			   FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_delete_time IS NULL
			  ORDER BY mjb_management_job_id DESC LIMIT ' . $n);
		$stmt->execute(array((int)$node->key));
		$lines = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$lines[] = 'job #' . $r['mjb_management_job_id'] . ' ' . $r['mjb_job_type'] . ' ' . $r['mjb_create_time']
				. ' — ' . $r['mjb_status'] . ($r['mjb_agent_outcome'] ? ' (' . $r['mjb_agent_outcome'] . ')' : '')
				. ($r['mjb_error_message'] ? "\n  error: " . mb_substr($r['mjb_error_message'], 0, 300) : '');
		}
		return $lines ? "Recent jobs on this site:\n" . $this->wrap($ctx, implode("\n", $lines)) : 'No jobs on record for this site.';
	}
}

/** One observe word as a tool (SupportNodeTools). Not itself a tool: each word below is. */
abstract class SupportNodeWordTool {
	const WORD = '';

	public static function name(): string { return static::WORD; }

	public static function description(): string {
		return SupportNodeTools::description(static::WORD);
	}

	public static function inputSchema(): array {
		return SupportNodeTools::available()
			? SupportNodeTools::inputSchema(static::WORD)
			: array('type' => 'object', 'properties' => new stdClass());
	}

	public function execute(array $input, ToolContext $ctx) {
		$sctx = SupportTools::context($ctx);
		if ($sctx === null) {
			return SupportTools::notHere();
		}
		return SupportNodeTools::run($sctx, static::WORD, $input);
	}
}

class SupportHostReportTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'host_report'; }
class SupportSiteLogTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'site_log'; }
class SupportLogTableTailTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'log_table_tail'; }
class SupportUnitJournalTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'unit_journal'; }
class SupportFileHeadTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'file_head'; }
class SupportDiskUsageTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'disk_usage'; }
class SupportSchemaProbeTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'schema_probe'; }
class SupportPageProbeTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'page_probe'; }
class SupportContainerInventoryTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'container_inventory'; }
class SupportListeningSocketsTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'listening_sockets'; }
class SupportSpamLearningStatusTool extends SupportNodeWordTool implements RecipeToolInterface { const WORD = 'spam_learning_status'; }
