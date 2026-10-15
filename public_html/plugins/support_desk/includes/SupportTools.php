<?php
/**
 * SupportTools — which tools a support turn is offered, and how each reads on
 * the panel's status line (specs/support_chatbot.md, The engine).
 *
 * The tool set is fixed by the thread, never by a toggle:
 *
 *   docs     search_docs, read_doc_section                     every thread
 *   handoff  escalate (+ file_bug_report with a site bundle)  every thread
 *   plane    node_overview, node_incidents, node_backup_runs, node_jobs
 *                                                             a joined site
 *   node     one tool per SupportNodeTools::WORDS word the node reports
 *                                                             a joined site that allowed live reads
 *
 * None of them writes to a site. escalate and file_bug_report write on this
 * node only (the thread's state; a bug_reports row), which is what they are for.
 *
 * @version 0.1.0
 */
class SupportTools {

	const DOCS    = array('search_docs', 'read_doc_section');
	const PLANE   = array('node_overview', 'node_incidents', 'node_backup_runs', 'node_jobs');

	/** The tools a turn on this thread is offered, in a stable order. */
	public static function forThread(SupportThread $thread): array {
		$tools = self::DOCS;
		if ((string)$thread->get('sth_surface') === SupportThread::SURFACE_WIDGET) {
			return $tools;
		}
		$tools[] = 'escalate';
		if (class_exists('BugReportIntake') && $thread->bundle()) {
			$tools[] = 'file_bug_report';
		}
		$node = $thread->node();
		if ($node && SupportNodeTools::available()) {
			$tools = array_merge($tools, self::PLANE);
			if ((bool)$thread->get('sth_consent_live_reads')
					&& SupportDesk::intSetting('support_desk_node_reads_per_thread', 20) > 0) {
				$tools = array_merge($tools, SupportNodeTools::offered($node));
			}
		}
		return $tools;
	}

	/** The status line while a tool runs: what the person sees in the panel. */
	public static function activityLabel(string $name, array $input): string {
		switch ($name) {
			case 'search_docs':      return 'Searching the documentation…';
			case 'read_doc_section': return 'Reading ' . self::short((string)($input['path'] ?? 'the documentation')) . '…';
			case 'node_overview':
			case 'node_incidents':
			case 'node_backup_runs':
			case 'node_jobs':        return "Reading your site's record on the management node…";
			case 'escalate':         return 'Handing this to a person…';
			case 'file_bug_report':  return 'Filing a bug report…';
			case 'site_log':
				$labels = class_exists('JobCommandBuilder') ? JobCommandBuilder::SITE_LOG_FILES : array();
				$f = (string)($input['file'] ?? '');
				return "Reading your site's " . strtolower($labels[$f] ?? 'log') . '…';
			case 'log_table_tail':   return "Reading your site's " . str_replace('_', ' ', (string)($input['table'] ?? 'log')) . ' log…';
			case 'unit_journal':     return 'Reading why ' . (string)($input['unit'] ?? 'a service') . ' is in its state…';
			case 'file_head':        return "Reading your site's configuration…";
			case 'host_report':      return "Reading your site's machine report…";
			case 'disk_usage':       return "Reading your site's disk usage…";
			case 'schema_probe':     return "Describing a table of your site's database…";
			case 'page_probe':       return 'Opening ' . (string)($input['page'] ?? 'a page') . ' on your site…';
			case 'container_inventory': return "Listing your site's containers…";
			case 'listening_sockets':   return "Listing what your site listens on…";
			case 'spam_learning_status': return "Reading your site's spam learning…";
		}
		return $name !== '' ? 'Working…' : '';
	}

	private static function short(string $path): string {
		$base = basename($path, '.md');
		return mb_substr(str_replace('_', ' ', $base), 0, 60);
	}

	/** The thread's turn context, refusing any other (a tool of this plugin used from a recipe or a chat). */
	public static function context(ToolContext $ctx): ?SupportTurnContext {
		return $ctx instanceof SupportTurnContext ? $ctx : null;
	}

	public static function notHere(): array {
		return array('content' => 'This tool belongs to the support desk and runs only in a support thread.', 'is_error' => true);
	}
}
