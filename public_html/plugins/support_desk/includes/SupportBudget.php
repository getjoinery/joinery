<?php
/**
 * SupportBudget — what the support desk may spend, counted on this node
 * (specs/support_chatbot.md, Model and cost; Trust posture).
 *
 * Three ceilings, checked before a turn is started, never during one:
 *   - one site's tokens in a UTC day (support_desk_daily_tokens_per_host),
 *   - one public-widget address's tokens in a UTC day
 *     (support_desk_widget_daily_tokens_per_address),
 *   - every support turn's tokens in a UTC day (support_desk_daily_tokens_total),
 * and Joinery AI's own monthly cap (CostGuard), which support turns count
 * toward because they are chat messages.
 *
 * Tokens are input (fresh, cache-written and cache-read) plus output, as the
 * turn recorded them on its assistant row.
 *
 * @version 0.1.0
 */
class SupportBudget {

	/** The sentence the panel shows when a budget is spent, or null when the turn may run. */
	public static function refusal(SupportThread $thread): ?string {
		try {
			CostGuard::enforceGlobalCap();
		} catch (CapExceededException $e) {
			return 'The support desk has reached its limit for this month. The documentation is still open to you, and you can ask for a person.';
		}
		$total = SupportDesk::intSetting('support_desk_daily_tokens_total', 4000000);
		if ($total > 0 && self::spentToday(null, null) >= $total) {
			return 'The support desk has answered all it can for today. The documentation is still open to you, and you can ask for a person.';
		}
		if ((string)$thread->get('sth_surface') === SupportThread::SURFACE_WIDGET) {
			$per = SupportDesk::intSetting('support_desk_widget_daily_tokens_per_address', 60000);
			if ($per > 0 && self::spentToday(null, (string)$thread->get('sth_sender_ip')) >= $per) {
				return 'You have asked all this desk can answer for one visitor today. The documentation is still open to you.';
			}
			return null;
		}
		$per = SupportDesk::intSetting('support_desk_daily_tokens_per_host', 400000);
		if ($per > 0 && self::spentToday((string)$thread->get('sth_host'), null) >= $per) {
			return 'Your site has used the support desk\'s answers for today. The documentation is still open to you, and you can ask for a person.';
		}
		return null;
	}

	/** Tokens spent today by support turns: all, one host's, or one widget address's. */
	public static function spentToday(?string $host, ?string $widget_ip): int {
		$sql = "SELECT COALESCE(SUM(m.aim_input_tokens + m.aim_output_tokens), 0)
			FROM aim_conversation_messages m
			JOIN aic_conversations c ON c.aic_conversation_id = m.aim_aic_conversation_id
			JOIN sth_support_threads t ON t.sth_support_thread_id = c.aic_support_thread_id
			WHERE m.aim_role = 'assistant' AND m.aim_create_time >= (now() AT TIME ZONE 'UTC')::date";
		$params = array();
		if ($host !== null) {
			$sql .= ' AND t.sth_host = ?';
			$params[] = $host;
		}
		if ($widget_ip !== null) {
			$sql .= " AND t.sth_surface = 'widget' AND t.sth_sender_ip = ?";
			$params[] = $widget_ip;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$stmt->execute($params);
		return (int)$stmt->fetchColumn();
	}
}
