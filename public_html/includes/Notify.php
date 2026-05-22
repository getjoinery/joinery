<?php
/**
 * Notify — notification hooks dispatcher.
 *
 * `Notify::fire($hook_point, $params)` delivers an in-app notification (and,
 * per the recipient's preferences, a queued email) to the recipients of a
 * declared hook point.
 *
 * Hook points are declared in `notification_hooks.json` (core) and the
 * `notificationHooks` key of plugin manifests — there is no database catalog.
 *
 * See specs/notification_hooks.md.
 *
 * @version 1.0
 */

class Notify {

	/** Per-request cache of merged hook point declarations. */
	private static $hook_points_cache = null;

	/**
	 * Merged hook point declarations: core `notification_hooks.json` plus the
	 * `notificationHooks` key of every active plugin's manifest. Cached for the
	 * duration of the request.
	 *
	 * @return array  map of hook_point_name => declaration array
	 */
	public static function hook_points() {
		if (self::$hook_points_cache !== null) {
			return self::$hook_points_cache;
		}

		$merged = array();

		$core_file = PathHelper::getIncludePath('notification_hooks.json');
		if (is_file($core_file)) {
			$decoded = json_decode(file_get_contents($core_file), true);
			if (is_array($decoded)) {
				$merged = $decoded;
			}
		}

		try {
			foreach (PluginHelper::getActivePlugins() as $plugin) {
				$hooks = $plugin->get('notificationHooks', null);
				if (is_array($hooks)) {
					foreach ($hooks as $name => $meta) {
						$merged[$name] = $meta;
					}
				}
			}
		} catch (Exception $e) {
			error_log('[Notify] plugin hook point read failed: ' . $e->getMessage());
		}

		self::$hook_points_cache = $merged;
		return $merged;
	}

	/**
	 * Fire a hook point.
	 *
	 * Never throws into the caller: a notification failure must not break the
	 * request or operation that triggered the event. On the checkout path,
	 * call this only AFTER the charge transaction has committed.
	 *
	 * @param string $hook_point  declared hook point name, e.g. 'comment.posted'
	 * @param array  $params      title (required), body, link, recipients,
	 *                             source_user_id
	 */
	public static function fire($hook_point, array $params = array()) {
		try {
			self::_dispatch($hook_point, $params);
		} catch (Throwable $e) {
			error_log('[Notify] fire(' . $hook_point . ') failed: ' . $e->getMessage());
		}
	}

	private static function _dispatch($hook_point, array $params) {
		require_once(PathHelper::getIncludePath('data/notifications_class.php'));
		require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

		$hooks    = self::hook_points();
		$declared = isset($hooks[$hook_point]) ? $hooks[$hook_point] : null;

		if ($declared === null) {
			error_log('[Notify] fire() called for undeclared hook point: ' . $hook_point
				. ' — delivering to targeted recipients only.');
		}

		$ntf_type       = ($declared && isset($declared['ntf_type'])) ? $declared['ntf_type'] : 'system';
		$supports_topic = ($declared && !empty($declared['supports_topic']));
		$default_email  = ($declared && !empty($declared['default_email']));

		$title = isset($params['title']) ? trim($params['title']) : '';
		$body  = isset($params['body'])  ? $params['body']  : '';
		$link  = isset($params['link'])  ? $params['link']  : null;
		$source_user_id = isset($params['source_user_id']) ? (int)$params['source_user_id'] : null;

		if ($title === '') {
			error_log('[Notify] fire(' . $hook_point . ') called without a title; skipping.');
			return;
		}

		// Targeted recipients — uid => true
		$targeted = array();
		if (isset($params['recipients'])) {
			$recips = is_array($params['recipients']) ? $params['recipients'] : array($params['recipients']);
			foreach ($recips as $r) {
				$targeted[(int)$r] = true;
			}
		}

		// Topic subscribers — uid => NotificationPreference
		$topic_prefs = array();
		if ($supports_topic) {
			$subs = new MultiNotificationPreference(
				array('hook_point' => $hook_point, 'subscribed' => true, 'deleted' => false)
			);
			$subs->load();
			foreach ($subs as $pref) {
				$topic_prefs[(int)$pref->get('ntp_usr_user_id')] = $pref;
			}
		}

		$all_ids = array_unique(array_merge(array_keys($targeted), array_keys($topic_prefs)));

		foreach ($all_ids as $uid) {
			$uid = (int)$uid;
			if ($uid <= 0) {
				continue;
			}
			// Never notify someone of their own action.
			if ($source_user_id !== null && $uid === $source_user_id) {
				continue;
			}

			$is_targeted = isset($targeted[$uid]);
			$pref = isset($topic_prefs[$uid]) ? $topic_prefs[$uid] : null;
			if ($pref === null && $is_targeted) {
				$pref = NotificationPreference::get_for($uid, $hook_point);
			}

			// A targeted recipient who has muted this hook point gets nothing.
			if ($is_targeted && $pref !== null && !$pref->get('ntp_subscribed')) {
				continue;
			}

			// In-app notification is the baseline channel.
			try {
				Notification::create_notification($uid, $ntf_type, $title, $body, $link, $source_user_id);
			} catch (Exception $e) {
				error_log('[Notify] in-app delivery failed for user ' . $uid . ': ' . $e->getMessage());
			}

			// Email is the secondary, opt-in channel.
			if ($pref !== null) {
				$send_email = (bool)$pref->get('ntp_email_enabled');
			} elseif ($is_targeted) {
				$send_email = $default_email;
			} else {
				$send_email = false;
			}

			if ($send_email) {
				try {
					self::_enqueue_email($uid, $title, $body);
				} catch (Exception $e) {
					error_log('[Notify] email enqueue failed for user ' . $uid . ': ' . $e->getMessage());
				}
			}
		}
	}

	/**
	 * Queue an email for a recipient. The email is written to `equ_queued_emails`
	 * with READY_TO_SEND status; the SendQueuedEmails scheduled task delivers it.
	 * Nothing is sent inline.
	 */
	private static function _enqueue_email($user_id, $title, $body) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		require_once(PathHelper::getIncludePath('data/queued_email_class.php'));

		$user  = new User($user_id, TRUE);
		$email = $user->get('usr_email');
		if (!$email) {
			return;
		}

		$name = trim($user->display_name());
		if ($name === '') {
			$name = $email;
		}

		$settings  = Globalvars::get_instance();
		$from      = $settings->get_setting('defaultemail');
		$from_name = $settings->get_setting('defaultemailname');
		if (!$from) {
			error_log('[Notify] no defaultemail configured; cannot queue notification email.');
			return;
		}
		if (!$from_name) {
			$from_name = $from;
		}

		// Plain-text body -> safe HTML, then wrap in the standard inner template.
		$html  = '<p>' . nl2br(htmlspecialchars((string)$body, ENT_QUOTES, 'UTF-8')) . '</p>';
		$inner = $settings->get_setting('individual_email_inner_template');
		if ($inner) {
			try {
				require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
				$message  = EmailMessage::fromTemplate($inner, array(
					'subject'   => $title,
					'body'      => $html,
					'recipient' => $user->export_as_array(),
				));
				$rendered = $message->getHtmlBody();
				if ($rendered) {
					$html = $rendered;
				}
			} catch (Exception $e) {
				error_log('[Notify] inner template render failed: ' . $e->getMessage());
			}
		}

		$queued = new QueuedEmail(NULL);
		$queued->set('equ_from', $from);
		$queued->set('equ_from_name', mb_substr($from_name, 0, 70));
		$queued->set('equ_to', $email);
		$queued->set('equ_to_name', mb_substr($name, 0, 70));
		$queued->set('equ_subject', mb_substr($title, 0, 128));
		$queued->set('equ_body', $html);
		$queued->set('equ_status', QueuedEmail::READY_TO_SEND);
		$queued->set('equ_retry_count', 0);
		$queued->save();
	}
}
