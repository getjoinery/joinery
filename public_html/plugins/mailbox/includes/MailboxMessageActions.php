<?php
/**
 * MailboxMessageActions - buttons other plugins add to a message in the reader.
 *
 * A plugin declares them in plugin.json:
 *
 *   "mailboxMessageActions": {
 *       "boards_make_card": { "label": "Make a card", "icon": "tasks", "url": "/profile/boards/from_message" }
 *   }
 *
 * The reader (mailbox_reader.js) renders each after the built-in items and opens
 * `{url}?message_id=N`. Standard-level mailboxes only: a Private-level row is decrypted for
 * the viewer and a Fortress one is sealed to their devices, and putting that text in a
 * plugin's tables would break the promise of the level.
 *
 * The target page opens the message with openForAction(), which applies the mailbox's own
 * access rule (MailboxViewer::canReadMessage) and the Standard-level rule, so an action never
 * widens access to a message.
 *
 * @version 1.0
 */

class MailboxMessageActions {

	/**
	 * The actions declared by active plugins, in plugin load order:
	 * [['key','label','icon','url','plugin'], ...]. Malformed entries are skipped. The url
	 * is a same-site path.
	 */
	public static function declared(): array {
		$out = array();
		try {
			foreach (PluginHelper::getActivePlugins() as $plugin_name => $plugin) {
				$actions = $plugin->get('mailboxMessageActions', null);
				if (!is_array($actions)) {
					continue;
				}
				foreach ($actions as $key => $decl) {
					$url = is_array($decl) ? trim((string)($decl['url'] ?? '')) : '';
					$label = is_array($decl) ? trim((string)($decl['label'] ?? '')) : '';
					// One leading slash: a same-site path, never "//host" or a scheme.
					if ($label === '' || !preg_match('#^/[^/\\\\]#', $url) || preg_match('#[\x00-\x1f]#', $url)) {
						error_log('[MailboxMessageActions] ignoring malformed action "' . $key . '" in ' . $plugin_name);
						continue;
					}
					$out[] = array(
						'key'    => (string)$key,
						'label'  => $label,
						'icon'   => (string)($decl['icon'] ?? ''),
						'url'    => $url,
						'plugin' => (string)$plugin_name,
					);
				}
			}
		} catch (\Throwable $e) {
			error_log('[MailboxMessageActions] reading plugin actions failed: ' . $e->getMessage());
		}
		return $out;
	}

	/**
	 * Open a message for a reader action's target page.
	 *
	 * @return array{message:?InboundEmailMessage, error:?string} error is a sentence for the
	 *         person, set when the message cannot be used
	 */
	public static function openForAction(MailboxViewer $viewer, int $message_id): array {
		$exists = false;
		if ($message_id > 0) {
			$q = DbConnector::get_instance()->get_db_link()->prepare(
				'SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
			$q->execute(array($message_id));
			$exists = (bool)$q->fetchColumn();
		}
		$message = $exists ? new InboundEmailMessage($message_id, TRUE) : null;
		if (!$message || !$message->key || $message->get('iem_delete_time')) {
			return array('message' => null, 'error' => 'That message no longer exists.');
		}
		if (!$viewer->canReadMessage($message)) {
			return array('message' => null, 'error' => 'You do not have access to that mailbox.');
		}
		$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
		if ($alias_id <= 0) {
			return array('message' => null, 'error' => 'That message belongs to no mailbox, so it cannot be used here.');
		}
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if (!$alias->key || $alias->seals_content()) {
			return array('message' => null,
				'error' => 'That mailbox is protected (Private or Fortress), so its mail cannot be used by other features.');
		}
		return array('message' => $message, 'error' => null);
	}
}
