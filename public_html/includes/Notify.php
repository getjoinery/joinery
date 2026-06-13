<?php
/**
 * Notify — the signal bus's notification subscriber.
 *
 * Notify is subscriber #1 on the SignalBus: `Notify::handle_signal($signal,
 * $payload)` is invoked for every dispatched signal. For signals that carry a
 * `notify` block in the catalog (`signals.json` / a plugin's `signals` key), it
 * renders the block's title/body/link templates against the structured payload,
 * then delivers an in-app notification (and, per the recipient's preferences, a
 * queued email) to the signal's recipients. Signals with no `notify` block are
 * ignored here — they exist for other subscribers.
 *
 * Recipient resolution, the preferences model (ntp_notification_preferences),
 * and email enqueueing are unchanged; only the entry point and input (a
 * structured payload, not a pre-rendered message) differ.
 *
 * See docs/signals.md and docs/notifications.md.
 *
 * @version 2.0
 */

class Notify {

	/** Per-request dedupe of "missing template field" warnings. */
	private static $logged_missing = array();

	/**
	 * Filter the merged signal catalog to the entries that carry a `notify`
	 * block — the notifiable signals — preserving each entry's display metadata
	 * (label/category) for the preferences UI. Keyed by signal name.
	 *
	 * @return array  map of signal_name => signal declaration (incl. notify block)
	 */
	public static function notifiable_signals() {
		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));

		$notifiable = array();
		foreach (SignalBus::signals() as $name => $meta) {
			if (isset($meta['notify']) && is_array($meta['notify'])) {
				$notifiable[$name] = $meta;
			}
		}
		return $notifiable;
	}

	/**
	 * Signal-bus handler. Never throws into the bus: a notification failure must
	 * not break the request or operation that produced the signal.
	 *
	 * @param string $signal   declared signal name, e.g. 'comment.posted'
	 * @param array  $payload  structured payload from the dispatch site
	 */
	public static function handle_signal($signal, array $payload = array()) {
		try {
			self::_dispatch($signal, $payload);
		} catch (Throwable $e) {
			error_log('[Notify] handle_signal(' . $signal . ') failed: ' . $e->getMessage());
		}
	}

	private static function _dispatch($signal, array $payload) {
		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
		require_once(PathHelper::getIncludePath('data/notifications_class.php'));
		require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

		$signals  = SignalBus::signals();
		$declared = isset($signals[$signal]) ? $signals[$signal] : null;
		$notify   = ($declared && isset($declared['notify']) && is_array($declared['notify']))
			? $declared['notify'] : null;

		// Not a notifiable signal — nothing to do (cheap in-memory check).
		if ($notify === null) {
			return;
		}

		$ntf_type       = isset($notify['ntf_type']) ? $notify['ntf_type'] : 'system';
		$supports_topic = !empty($notify['supports_topic']);
		$default_email  = !empty($notify['default_email']);

		$title = trim(self::_render(isset($notify['title_template']) ? $notify['title_template'] : '', $payload, $signal));
		$body  = self::_render(isset($notify['body_template']) ? $notify['body_template'] : '', $payload, $signal);
		$link  = isset($notify['link_template'])
			? self::_render($notify['link_template'], $payload, $signal) : null;

		if ($title === '') {
			error_log('[Notify] ' . $signal . ' rendered an empty title; skipping.');
			return;
		}

		$source_user_id = isset($payload['source_user_id']) ? (int)$payload['source_user_id'] : null;

		// Targeted recipients — uid => true. (No core signal targets directly
		// today; kept so a future direct-notify signal can pass `recipients`.)
		$targeted = array();
		if (isset($payload['recipients'])) {
			$recips = is_array($payload['recipients']) ? $payload['recipients'] : array($payload['recipients']);
			foreach ($recips as $r) {
				$targeted[(int)$r] = true;
			}
		}

		// Topic subscribers — uid => NotificationPreference
		$topic_prefs = array();
		if ($supports_topic) {
			$subs = new MultiNotificationPreference(
				array('signal_name' => $signal, 'subscribed' => true, 'deleted' => false)
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
				$pref = NotificationPreference::get_for($uid, $signal);
			}

			// A targeted recipient who has muted this signal gets nothing.
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
	 * Dumb `{field}` template substitution: each placeholder is replaced with the
	 * payload value as plain text. A missing or null field substitutes the empty
	 * string and logs once per (signal, field) for the request. No conditionals,
	 * formatting, or modifiers — anything fancier is a derived payload field
	 * computed at the call site.
	 */
	private static function _render($template, array $payload, $signal) {
		$template = (string)$template;
		if ($template === '' || strpos($template, '{') === false) {
			return $template;
		}
		return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($m) use ($payload, $signal) {
			$key = $m[1];
			if (!array_key_exists($key, $payload) || $payload[$key] === null) {
				$dedupe = $signal . ':' . $key;
				if (!isset(self::$logged_missing[$dedupe])) {
					self::$logged_missing[$dedupe] = true;
					error_log('[Notify] ' . $signal . ' template references missing payload field {' . $key . '}.');
				}
				return '';
			}
			return (string)$payload[$key];
		}, $template);
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
