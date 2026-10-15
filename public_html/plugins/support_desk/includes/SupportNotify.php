<?php
/**
 * SupportNotify — the two emails the support desk sends, and the state change
 * that goes with each (specs/support_chatbot.md, Notification).
 *
 *   escalate()       a thread enters waiting_on_person: one email to
 *                    support_desk_notify_email with the host, the version, the
 *                    person's last message and a link, at most one an hour
 *                    for a thread.
 *   personReplied()  a person on the desk replied: the thread is answered by
 *                    a person and unread; a connected site's account gets one
 *                    email that carries no content ("A person replied to your
 *                    question. Open Help on your site to read it."), so a
 *                    follow-up comes back in the thread. An unconnected site
 *                    has no address and learns of it from the Help button's
 *                    mark.
 *
 * @version 0.1.0
 */
class SupportNotify {

	const REPLY_SUBJECT = 'A person replied to your support question';
	const REPLY_BODY = "A person replied to your question. Open Help on your site to read it.\n";

	/** Hand a thread to a person. Safe to call twice; the email is throttled. */
	public static function escalate(SupportThread $thread, string $reason = ''): void {
		$id = (int)$thread->key;
		SupportThread::touch($id, array('sth_state' => SupportThread::STATE_WAITING));
		$thread->set('sth_state', SupportThread::STATE_WAITING);

		$to = trim((string)Globalvars::get_instance()->get_setting('support_desk_notify_email', true, true));
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return;
		}
		// One email per thread per hour: claimed with one UPDATE, so two
		// handoffs at once send one email.
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"UPDATE sth_support_threads SET sth_notified_time = now()
			  WHERE sth_support_thread_id = ? AND (sth_notified_time IS NULL OR sth_notified_time < now() - INTERVAL '1 hour')
			  RETURNING sth_support_thread_id");
		$stmt->execute(array($id));
		if ($stmt->fetchColumn() === false) {
			return;
		}
		$last = self::lastQuestion($thread);
		$who = (string)$thread->get('sth_surface') === SupportThread::SURFACE_WIDGET
			? 'A visitor on the public pages'
			: ((string)$thread->get('sth_host') . ' (release ' . $thread->get('sth_version') . ')');
		$body = "A support thread is waiting for a person.\n\n"
			. 'From: ' . $who . "\n"
			. ($reason !== '' ? 'Why: ' . $reason . "\n" : '')
			. "Last message:\n" . mb_substr($last, 0, 1500) . "\n\n"
			. 'Open it: ' . SupportDesk::baseUrl() . '/plugins/support_desk/admin/admin_support_thread?sth_support_thread_id=' . $id . "\n";
		try {
			EmailSender::quickSend($to, 'Support thread waiting: ' . ($thread->get('sth_host') ?: 'public widget'), $body);
		} catch (\Throwable $e) {
			error_log('[support_desk] the waiting-thread email was not sent: ' . $e->getMessage());
		}
	}

	/** A person replied in the thread: mark it, and tell a connected account that there is something to read. */
	public static function personReplied(SupportThread $thread): void {
		$id = (int)$thread->key;
		SupportThread::touch($id, array('sth_state' => SupportThread::STATE_ANSWERED, 'sth_unread_reply' => true));
		$user_id = (int)$thread->get('sth_account_user_id');
		if ($user_id <= 0 || User::is_placeholder($user_id)) {
			return;
		}
		$user = new User($user_id, TRUE);
		$email = (string)$user->get('usr_email');
		if (!$user->key || $user->get('usr_delete_time') || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return;
		}
		// No more than one notice an hour for a thread, however many replies.
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"UPDATE sth_support_threads SET sth_reply_notified_time = now()
			  WHERE sth_support_thread_id = ? AND (sth_reply_notified_time IS NULL OR sth_reply_notified_time < now() - INTERVAL '1 hour')
			  RETURNING sth_support_thread_id");
		$stmt->execute(array($id));
		if ($stmt->fetchColumn() === false) {
			return;
		}
		try {
			EmailSender::quickSend($email, self::REPLY_SUBJECT, self::REPLY_BODY);
		} catch (\Throwable $e) {
			error_log('[support_desk] the reply notice was not sent: ' . $e->getMessage());
		}
	}

	private static function lastQuestion(SupportThread $thread): string {
		$cid = (int)$thread->get('sth_aic_conversation_id');
		if ($cid <= 0) {
			return '';
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT aim_content FROM aim_conversation_messages WHERE aim_aic_conversation_id = ? AND aim_role = 'user'
			   AND aim_delete_time IS NULL ORDER BY aim_conversation_message_id DESC LIMIT 1");
		$stmt->execute(array($cid));
		return (string)$stmt->fetchColumn();
	}
}
