<?php
/**
 * Support Reply Check — daily. The Help button's unread mark for a site whose
 * administrators have not opened Help lately: asks the desk about this site's
 * threads (SupportChatClient::threads()), which records each thread's state
 * and unread mark on the site's own rows. A site that never asked has no rows
 * and makes no call (support_chatbot.md, Notification).
 *
 * @version 1.0.0
 */
class SupportReplyCheck implements ScheduledTaskInterface {
	public function run(array $config) {
		if (!class_exists('SiteSupportThread') || !SupportChatClient::enabled() || !SiteSupportThread::recent()) {
			return array('status' => 'success', 'message' => 'No support threads on this site.');
		}
		try {
			$r = SupportChatClient::threads(0);
		} catch (SupportChatException $e) {
			return array('status' => 'skipped', 'message' => $e->getMessage());
		}
		return array('status' => 'success', 'message' => count($r['threads']) . ' thread(s) checked, ' . (int)$r['unread'] . ' with an unread reply.');
	}
}
