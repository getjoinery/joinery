<?php
/**
 * SupportGaps — the two reports that say what the support desk could not do
 * (specs/support_chatbot.md, The admin page):
 *
 *   - questions the docs could not answer: every turn that escalated or was
 *     thumbed down, with the searches the model ran and the release asked,
 *     until its thread is answered once or closed by naming what answers it
 *     now (sth_gap_note). Read from the transcripts at view time.
 *   - words the site could not read: an observe word the model reached for
 *     that the site's agent does not offer, counted in SupportWordGap.
 *
 * @version 0.1.0
 */
class SupportGaps {

	/** Count one word a joined site's agent did not offer. One statement, so two turns at once both count. */
	public static function noteMissingWord($node, string $word): void {
		if (!in_array($word, SupportNodeTools::WORDS, true)) {
			return;
		}
		$version = $node ? (string)AgentVocabulary::version($node) : '';
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"INSERT INTO swg_support_word_gaps (swg_word, swg_agent_version, swg_count, swg_first_time, swg_last_time)
			 VALUES (?, ?, 1, now(), now())
			 ON CONFLICT (swg_word, swg_agent_version)
			 DO UPDATE SET swg_count = swg_support_word_gaps.swg_count + 1, swg_last_time = now()");
		try {
			$stmt->execute(array($word, $version !== '' ? $version : 'unknown'));
		} catch (\Throwable $e) {
			error_log('[support_desk] could not count a missing word: ' . $e->getMessage());
		}
	}

	/**
	 * After a turn: every node word the model reached for that the loop
	 * refused as not offered is a word this site could not read.
	 */
	public static function noteRefusedWords(SupportThread $thread, array $tool_calls): void {
		$node = $thread->node();
		foreach ($tool_calls as $tc) {
			$name = (string)($tc['name'] ?? '');
			if (in_array($name, SupportNodeTools::WORDS, true)
					&& !empty($tc['is_error'])
					&& (string)($tc['output'] ?? '') === 'refused: not offered in this run') {
				self::noteMissingWord($node, $name);
			}
		}
	}

	/**
	 * The questions the docs could not answer: assistant turns that escalated
	 * or were thumbed down, each with the searches the model ran and the
	 * question that started it. Newest first.
	 *
	 * @return array<int, array{thread_id:int, message_id:int, release:string, host:string, question:string,
	 *         queries:array, reason:string, time:string, state:string}>
	 */
	public static function unanswered(int $limit = 100): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare("SELECT m.aim_conversation_message_id AS mid, m.aim_tool_calls, m.aim_feedback, m.aim_feedback_note,
				m.aim_create_time, t.sth_support_thread_id AS tid, t.sth_version, t.sth_host, t.sth_state, t.sth_surface, t.sth_gap_note
			FROM aim_conversation_messages m
			JOIN aic_conversations c ON c.aic_conversation_id = m.aim_aic_conversation_id
			JOIN sth_support_threads t ON t.sth_support_thread_id = c.aic_support_thread_id
			WHERE m.aim_role = 'assistant' AND m.aim_author IS NULL AND m.aim_delete_time IS NULL
			  AND t.sth_delete_time IS NULL AND t.sth_gap_closed_time IS NULL
			  AND (m.aim_feedback = 'down' OR m.aim_tool_calls LIKE '%\"name\":\"escalate\"%')
			ORDER BY m.aim_conversation_message_id DESC LIMIT " . (int)$limit);
		$stmt->execute();
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$calls = json_decode((string)$r['aim_tool_calls'], true);
			$queries = array();
			$escalated = false;
			foreach (is_array($calls) ? $calls : array() as $tc) {
				if (($tc['name'] ?? '') === 'search_docs' && isset($tc['input']['query'])) {
					$queries[] = (string)$tc['input']['query'];
				}
				if (($tc['name'] ?? '') === 'escalate') {
					$escalated = true;
				}
			}
			$reason = array();
			if ($escalated) {
				$reason[] = 'handed to a person';
			}
			if ($r['aim_feedback'] === 'down') {
				$reason[] = 'thumbed down' . ($r['aim_feedback_note'] ? ': ' . $r['aim_feedback_note'] : '');
			}
			$out[] = array(
				'thread_id'  => (int)$r['tid'],
				'message_id' => (int)$r['mid'],
				'release'    => (string)$r['sth_version'],
				'host'       => (string)($r['sth_host'] ?: ($r['sth_surface'] === 'widget' ? 'public widget' : '')),
				'question'   => self::questionBefore((int)$r['mid']),
				'queries'    => array_values(array_unique($queries)),
				'reason'     => implode('; ', $reason),
				'time'       => (string)$r['aim_create_time'],
				'state'      => (string)$r['sth_state'],
			);
		}
		return $out;
	}

	/**
	 * Close a thread's row on the gap report by naming what answers it now: a
	 * doc commit, an agent word, a FAQ entry. An Answer once closes it too.
	 */
	public static function close(SupportThread $thread, string $note): void {
		$note = trim($note);
		if ($note === '') {
			throw new SupportThreadException('Say what answers it now: a doc commit, an agent word, a FAQ entry.');
		}
		SupportThread::updateColumns((int)$thread->key, array('sth_gap_note' => mb_substr($note, 0, 500),
			'sth_gap_closed_time' => gmdate('Y-m-d H:i:s')));
	}

	/** The person's message the assistant row answered. */
	public static function questionBefore(int $assistant_message_id): string {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT q.aim_content FROM aim_conversation_messages q
			  JOIN aim_conversation_messages a ON a.aim_aic_conversation_id = q.aim_aic_conversation_id
			 WHERE a.aim_conversation_message_id = ? AND q.aim_role = 'user'
			   AND q.aim_conversation_message_id < a.aim_conversation_message_id AND q.aim_delete_time IS NULL
			 ORDER BY q.aim_conversation_message_id DESC LIMIT 1");
		$stmt->execute(array($assistant_message_id));
		return (string)$stmt->fetchColumn();
	}

}
