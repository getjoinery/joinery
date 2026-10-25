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
 * A signal that always goes to a fixed group of people declares that group as
 * its `audience`; Notify resolves it at send time, and the preferences pages
 * read the same declaration to show each person what they will get.
 *
 * See docs/signals.md and docs/notifications.md.
 *
 * Push is the third channel: anyone who gets the in-app notification also
 * gets it on every browser or phone they turned push on for
 * (PushSender), unless they switched push off for that signal. A signal
 * whose notify block says `"push_text": "generic"`, or a dispatch whose
 * payload carries `push_protected` true, is pushed as the generic text only.
 *
 * @version 2.7
 * @changelog 2.7 - The queued email saves as the server's own write: a member who causes a signal does not own the recipient's mail.
 * @changelog 2.6 - Push channel through PushSender; a preference carries ntp_push_enabled.
 * @changelog 2.5 - The `admins` and `superadmins` audiences leave out disabled accounts.
 * @changelog 2.4 - An `admins` audience: every admin (permission 5+) who is a person.
 * @changelog 2.3 - A declared `audience` names the targeted recipients; the
 *   preference state and save rules for both preferences pages live here.
 * @changelog 2.2 - A recipient deleted since the recipients were read gets
 *   nothing on either channel.
 * @changelog 2.1 - Placeholder users (system, deleted) are never recipients on
 *   either channel.
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
	 * The user ids a declared `audience` names, read now. NULL for an audience
	 * Notify does not know.
	 *
	 * - `superadmins` — every superadmin who is a person (not the system or
	 *   deleted placeholder).
	 * - `admins` — every admin (permission 5 and up) who is a person: the
	 *   people who settle content reports.
	 */
	public static function audience_ids($audience) {
		$ranges = array('superadmins' => array(10, 10), 'admins' => array(5, 10));
		if (!isset($ranges[$audience])) {
			return null;
		}
		$ids = array();
		foreach (new MultiUser(array('permission_range' => $ranges[$audience], 'deleted' => false, 'disabled' => false, 'not_system_users' => true)) as $u) {
			$ids[] = (int)$u->key;
		}
		return $ids;
	}

	/**
	 * What a user gets from a signal while they have no saved preference — the
	 * same rule delivery applies. A signal is on for the people it addresses:
	 * everyone, for one sent only to the people it is about (no topic
	 * subscription), and the members of its declared audience. Email follows
	 * the signal's `default_email`, and push follows the alert itself. Anything
	 * else waits for an opt-in.
	 *
	 * @param array $meta     the signal's declaration (with its notify block)
	 * @param array $audiences audience => ids, for reuse across signals
	 * @return array  array('subscribed' => bool, 'email' => bool, 'push' => bool)
	 */
	public static function default_preference(array $meta, $user_id, array &$audiences = array()) {
		$notify = isset($meta['notify']) && is_array($meta['notify']) ? $meta['notify'] : array();
		$on = empty($notify['supports_topic']);
		if (!$on && isset($notify['audience'])) {
			$audience = $notify['audience'];
			if (!array_key_exists($audience, $audiences)) {
				$audiences[$audience] = (array)self::audience_ids($audience);
			}
			$on = in_array((int)$user_id, $audiences[$audience], true);
		}
		return array('subscribed' => $on, 'email' => $on && !empty($notify['default_email']), 'push' => $on);
	}

	/**
	 * Each signal's state for a user: their saved row where they have one,
	 * otherwise default_preference().
	 *
	 * @param array $signals  signal_name => declaration
	 * @return array  signal_name => array('subscribed' => bool, 'email' => bool, 'push' => bool)
	 */
	public static function preference_states($user_id, array $signals) {
		require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

		$saved = array();
		foreach (new MultiNotificationPreference(array('user_id' => $user_id, 'deleted' => false)) as $pref) {
			$saved[$pref->get('ntp_signal_name')] = array(
				'subscribed' => (bool)$pref->get('ntp_subscribed'),
				'email'      => (bool)$pref->get('ntp_email_enabled'),
				'push'       => (bool)$pref->get('ntp_push_enabled'),
			);
		}

		$states = array();
		$audiences = array();
		foreach ($signals as $name => $meta) {
			$states[$name] = isset($saved[$name])
				? $saved[$name]
				: self::default_preference($meta, $user_id, $audiences);
		}
		return $states;
	}

	/**
	 * Save what a preferences form submitted. $subscribed_signals,
	 * $email_signals and $push_signals are the ticked signal names; email and
	 * push need subscribed. A
	 * signal with a saved row is updated. One without a row gets one only when
	 * the choice differs from the default, so a default that changes later —
	 * the signal's own, or an admin promoted into its audience — still reaches
	 * someone who never chose otherwise.
	 *
	 * @param array $signals  signal_name => declaration: the signals the form showed
	 */
	public static function save_preferences($user_id, array $signals, array $subscribed_signals, array $email_signals, array $push_signals = array()) {
		require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));

		$audiences = array();
		foreach ($signals as $signal_name => $meta) {
			$subscribed = in_array($signal_name, $subscribed_signals, true);
			$email      = $subscribed && in_array($signal_name, $email_signals, true);
			$push       = $subscribed && in_array($signal_name, $push_signals, true);

			$pref = NotificationPreference::get_for($user_id, $signal_name);
			if (!$pref) {
				$default = self::default_preference($meta, $user_id, $audiences);
				if ($default['subscribed'] === $subscribed && $default['email'] === $email && $default['push'] === $push) {
					continue;
				}
				$pref = new NotificationPreference(NULL);
				$pref->set('ntp_usr_user_id', $user_id);
				$pref->set('ntp_signal_name', $signal_name);
			}
			$pref->set('ntp_subscribed', $subscribed);
			$pref->set('ntp_email_enabled', $email);
			$pref->set('ntp_push_enabled', $push);
			$pref->save();
		}
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
		$push_protected = (($notify['push_text'] ?? '') === 'generic') || !empty($payload['push_protected']);

		$title = trim(self::_render(isset($notify['title_template']) ? $notify['title_template'] : '', $payload, $signal));
		$body  = self::_render(isset($notify['body_template']) ? $notify['body_template'] : '', $payload, $signal);
		$link  = isset($notify['link_template'])
			? self::_render($notify['link_template'], $payload, $signal) : null;

		if ($title === '') {
			error_log('[Notify] ' . $signal . ' rendered an empty title; skipping.');
			return;
		}

		$source_user_id = isset($payload['source_user_id']) ? (int)$payload['source_user_id'] : null;

		// Targeted recipients — uid => true. Signals that notify particular people
		// (mailbox and Boards signals) pass `recipients`; signals that always go to
		// one group declare it as their `audience`.
		$targeted = array();
		if (isset($payload['recipients'])) {
			$recips = is_array($payload['recipients']) ? $payload['recipients'] : array($payload['recipients']);
			foreach ($recips as $r) {
				$targeted[(int)$r] = true;
			}
		}
		if (isset($notify['audience'])) {
			// A failed read still lets the topic subscribers hear.
			try {
				$ids = self::audience_ids($notify['audience']);
			} catch (Throwable $e) {
				$ids = array();
				error_log('[Notify] ' . $signal . ': could not read audience ' . $notify['audience'] . ': ' . $e->getMessage());
			}
			if ($ids === null) {
				$ids = array();
				error_log('[Notify] ' . $signal . ' declares an unknown audience: ' . $notify['audience']);
			}
			foreach ($ids as $id) {
				$targeted[(int)$id] = true;
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
			// The system and deleted users are not people: a run that a task
			// owns has nobody to tell, and mail to their placeholder address
			// would leave the building addressed to a stranger's domain.
			if (User::is_placeholder($uid)) {
				continue;
			}
			// Nor is a user deleted since the recipients were read: their
			// deletion already took their notifications, so a row written
			// now would outlive them.
			if (!User::check_if_exists($uid)) {
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
					self::_enqueue_email($uid, $title, $body, $link);
				} catch (Exception $e) {
					error_log('[Notify] email enqueue failed for user ' . $uid . ': ' . $e->getMessage());
				}
			}

			// Push follows the alert, unless switched off for this signal. It is
			// held to the end of the request; a member with no browser or phone
			// registered costs nothing more.
			if ($pref === null || (bool)$pref->get('ntp_push_enabled')) {
				try {
					PushSender::queue($uid, array(
						'title'     => $title,
						'body'      => $body,
						'link'      => $link,
						'tag'       => $signal,
						'protected' => $push_protected,
					));
				} catch (Throwable $e) {
					error_log('[Notify] push queue failed for user ' . $uid . ': ' . $e->getMessage());
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
	 *
	 * $link is the signal's rendered link_template — the same destination the
	 * in-app notification points at. It is included as a real link, because an
	 * email telling somebody that something needs their attention and then not
	 * saying where leaves them to go and find it.
	 */
	private static function _enqueue_email($user_id, $title, $body, $link = null) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		require_once(PathHelper::getIncludePath('data/queued_emails_class.php'));
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

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

		// The link, made absolute — a mail client has no site to resolve "/profile/…"
		// against. An already-absolute link (a signal pointing somewhere else
		// entirely) is left as it is.
		$link = trim((string)$link);
		if ($link !== '') {
			$url = preg_match('#^https?://#i', $link)
				? $link
				: LibraryFunctions::get_absolute_url($link);
			$safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
			$html .= '<p><a href="' . $safe . '">' . $safe . '</a></p>';
		}
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
		// The platform's own mail to a recipient, which the person who caused
		// the signal neither owns nor asked for: the server's write.
		SystemBase::server_initiated_write(function () use ($queued) { $queued->save(); });
	}
}
