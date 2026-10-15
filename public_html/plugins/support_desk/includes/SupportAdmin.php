<?php
/**
 * SupportAdmin — what the operator does on the support desk's admin pages
 * (specs/support_chatbot.md, The admin page; FAQs): reply in a thread as a
 * person, take a thread or let it go, close and reopen it, and Answer once —
 * write a FAQ entry from a thread, which both answers that thread as a person
 * and puts the entry in the index.
 *
 * @version 0.1.0
 */
class SupportAdmin {

	const REPLY_MAX = 20000;

	/** A person's reply: an assistant-role row carrying the operator's id, then the notice. */
	public static function reply(SupportThread $thread, int $user_id, string $text): AiConversationMessage {
		$text = trim($text);
		if ($text === '') {
			throw new SupportThreadException('Write a reply.');
		}
		if (mb_strlen($text) > self::REPLY_MAX) {
			throw new SupportThreadException('Keep a reply under ' . number_format(self::REPLY_MAX) . ' characters.');
		}
		$conversation = $thread->conversation();
		if ($conversation === null) {
			throw new SupportThreadException('This thread has no transcript.');
		}
		$msg = new AiConversationMessage(NULL);
		$msg->set('aim_aic_conversation_id', (int)$conversation->key);
		$msg->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
		$msg->set('aim_author', $user_id);
		$msg->set('aim_content', $text);
		$msg->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
		$msg->save();
		$msg->load();
		SupportNotify::personReplied($thread);
		return $msg;
	}

	/** Take the thread (the model stays out of it) or let it go (null). */
	public static function pin(SupportThread $thread, ?int $user_id): void {
		SupportThread::touch((int)$thread->key, array('sth_pinned_user_id' => $user_id));
	}

	public static function close(SupportThread $thread): void {
		SupportThread::touch((int)$thread->key, array('sth_state' => SupportThread::STATE_CLOSED,
			'sth_closed_time' => gmdate('Y-m-d H:i:s'), 'sth_pinned_user_id' => null));
	}

	public static function reopen(SupportThread $thread): void {
		SupportThread::touch((int)$thread->key, array('sth_state' => SupportThread::STATE_OPEN, 'sth_closed_time' => null));
	}

	/**
	 * Save a FAQ entry. With a site's thread (Answer once) a new entry also
	 * answers that thread as a person, with the entry's answer.
	 */
	public static function saveFaq(?SupportFaqEntry $entry, array $fields, ?SupportThread $thread, int $user_id): SupportFaqEntry {
		$is_new = $entry === null || !$entry->key;
		$entry = $entry ?: new SupportFaqEntry(NULL);
		$entry->set('sfq_question', (string)($fields['sfq_question'] ?? ''));
		$entry->set('sfq_answer', (string)($fields['sfq_answer'] ?? ''));
		$topic = trim((string)($fields['sfq_topic'] ?? ''));
		$entry->set('sfq_topic', $topic !== '' ? mb_substr($topic, 0, 64) : null);
		if ($is_new) {
			$entry->set('sfq_author_user_id', $user_id);
			$entry->set('sfq_release', (string)LibraryFunctions::get_joinery_version());
			if ($thread !== null) {
				$entry->set('sfq_sth_support_thread_id', (int)$thread->key);
				if ($thread->get('sth_version')) {
					$entry->set('sfq_release', (string)$thread->get('sth_version'));
				}
			}
		}
		$entry->save();
		$entry->load();
		// A site's thread hears the answer as a reply; a widget visitor cannot be reached.
		if ($is_new && $thread !== null) {
			SupportGaps::close($thread, 'FAQ entry faq/local/' . (int)$entry->key);
		}
		if ($is_new && $thread !== null && (string)$thread->get('sth_surface') === SupportThread::SURFACE_SITE) {
			self::reply($thread, $user_id, (string)$entry->get('sfq_answer'));
		}
		return $entry;
	}

	/** The transcript as the operator reads it: every turn, its trace and tokens, a person's replies marked. */
	public static function transcriptHtml(SupportThread $thread, string $tz): string {
		$cid = (int)$thread->get('sth_aic_conversation_id');
		$out = '';
		foreach (new MultiAiConversationMessage(array('conversation_id' => $cid, 'deleted' => false),
				array('aim_conversation_message_id' => 'ASC')) as $m) {
			$time = LibraryFunctions::convert_time($m->get('aim_create_time'), 'UTC', $tz, 'M j, g:i A');
			if ((string)$m->get('aim_role') === AiConversationMessage::ROLE_USER) {
				$out .= ChatTranscript::userBubble((string)$m->get('aim_content'), $time, array('message_id' => (int)$m->key));
				continue;
			}
			$status = (string)$m->get('aim_status');
			if ($status === AiConversationMessage::STATUS_FAILED) {
				$out .= ChatTranscript::failedBubble('Failed: ' . (string)$m->get('aim_error'), (int)$m->key);
				continue;
			}
			if ($status === AiConversationMessage::STATUS_RUNNING) {
				$out .= ChatTranscript::failedBubble('Still being written (' . (string)$m->get('aim_activity') . ')', (int)$m->key);
				continue;
			}
			$by_person = (int)$m->get('aim_author') > 0;
			$meta = array();
			if (!$by_person) {
				$meta[] = '<span>' . number_format((int)$m->get('aim_input_tokens')) . ' in / ' . number_format((int)$m->get('aim_output_tokens')) . ' out</span>';
			} else {
				$author = new User((int)$m->get('aim_author'), TRUE);
				$meta[] = '<span>' . htmlspecialchars($author->key ? $author->display_name() : 'a person', ENT_QUOTES, 'UTF-8') . '</span>';
			}
			$fb = (string)$m->get('aim_feedback');
			if ($fb !== '') {
				$meta[] = '<span>' . ($fb === 'up' ? 'thumbs up' : 'thumbs down')
					. ($m->get('aim_feedback_note') ? ': ' . htmlspecialchars((string)$m->get('aim_feedback_note'), ENT_QUOTES, 'UTF-8') : '') . '</span>';
			}
			$out .= ChatTranscript::assistantBubble((string)$m->get('aim_content'), $time, array(
				'message_id' => (int)$m->key,
				'trace_html' => ChatTranscript::traceHtml(self::traceWithDetail((string)$m->get('aim_tool_calls'))),
				'meta_parts' => $meta,
				'cancelled'  => $status === AiConversationMessage::STATUS_CANCELLED,
				'by_person'  => $by_person,
			));
		}
		return $out !== '' ? '<div class="joai-chat-transcript sdk-transcript">' . $out . '</div>' : '<p>No messages yet.</p>';
	}

	/** Tool calls with their input folded into the name, so the trace says what was searched or read. */
	private static function traceWithDetail(string $json): array {
		$calls = json_decode($json, true);
		if (!is_array($calls)) {
			return array();
		}
		foreach ($calls as &$tc) {
			$input = is_array($tc['input'] ?? null) ? $tc['input'] : array();
			$detail = array();
			foreach ($input as $k => $v) {
				if (is_scalar($v) && (string)$v !== '') {
					$detail[] = $k . '=' . mb_substr((string)$v, 0, 80);
				}
			}
			if ($detail) {
				$tc['name'] = (string)($tc['name'] ?? '?') . ' (' . implode(', ', $detail) . ')';
			}
		}
		return $calls;
	}

	/** Thumbs per thread: thread id => [up, down]. */
	public static function thumbs(array $thread_ids): array {
		$out = array();
		if (!$thread_ids) {
			return $out;
		}
		$ph = implode(',', array_fill(0, count($thread_ids), '?'));
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT c.aic_support_thread_id AS tid,
			        COUNT(*) FILTER (WHERE m.aim_feedback = 'up') AS up, COUNT(*) FILTER (WHERE m.aim_feedback = 'down') AS down
			   FROM aim_conversation_messages m JOIN aic_conversations c ON c.aic_conversation_id = m.aim_aic_conversation_id
			  WHERE c.aic_support_thread_id IN ($ph) GROUP BY c.aic_support_thread_id");
		$stmt->execute(array_map('intval', $thread_ids));
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[(int)$r['tid']] = array((int)$r['up'], (int)$r['down']);
		}
		return $out;
	}
}
