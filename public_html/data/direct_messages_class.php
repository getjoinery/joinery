<?php
/**
 * DirectMessage — a subject and a message an admin sends to the people who
 * belong to something, about it: one member, a group's members, an event's
 * registrants or its waiting list (spec email_lists_plugin §3).
 *
 * Not a campaign: no list, no unsubscribe list, no scheduling. The audience is
 * a recipient-group provider and a reference (RecipientGroupProviderRegistry:
 * 'user', 'group', and event_manager's 'event' and 'event_waiting_list'), plus
 * any extra people the sender names (an event's leader, the sender's own copy).
 *
 * queue_to() writes the message and one DirectMessageRecipient per address;
 * nothing is sent in that request. The SendQueuedEmails task calls
 * send_pending(), which claims each waiting recipient, sends it through
 * EmailSender and records the outcome, so a large group never holds a page
 * open and a pass that stops part way resumes where it stopped. The rows are
 * the history the group, event and user pages list.
 *
 * @version 1.0
 */
class DirectMessage extends SystemBase {
	public static $prefix = 'dmg';
	public static $tablename = 'dmg_direct_messages';
	public static $access_class = 'personal';
	public static $pkey_column = 'dmg_direct_message_id';

	protected static $foreign_key_actions = array(
		'dmg_usr_user_id_sender' => array('action' => 'null'),
		'dmg_eml_email_id' => array('action' => 'null'),
	);

	const QUEUED = 1;   // recipients waiting
	const SENT   = 2;   // every recipient tried

	/** How many recipients one send pass tries, across every message. */
	const SEND_BATCH = 500;
	/** Tries per recipient before it is not sent. */
	const MAX_ATTEMPTS = 3;
	/** Minutes before a refused send is tried again, times its attempts so far. */
	const RETRY_MINUTES = 10;
	/** Refusals in a row that stop a pass: the provider is down or out of allowance. */
	const STOP_AFTER_FAILURES = 5;
	/** A claim this old belongs to a pass that died mid-send. */
	const CLAIM_STALE_MINUTES = 15;
	/** A message still waiting after this many days stops waiting. */
	const GIVE_UP_DAYS = 3;

	public static $field_specifications = array(
		'dmg_direct_message_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'dmg_usr_user_id_sender' => array('type'=>'int4', 'index'=>true),
		'dmg_subject' => array('type'=>'varchar(255)', 'required'=>true),
		'dmg_message_html' => array('type'=>'text', 'required'=>true),
		'dmg_inner_template' => array('type'=>'varchar(255)'),
		'dmg_provider' => array('type'=>'varchar(64)', 'required'=>true),
		'dmg_reference_id' => array('type'=>'int4', 'required'=>true, 'index'=>true),
		'dmg_recipient_count' => array('type'=>'int4', 'default'=>0),
		'dmg_status' => array('type'=>'int2', 'default'=>1),
		'dmg_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'dmg_sent_time' => array('type'=>'timestamp(6)'),
		// The campaign row a past message was copied from, so the copy runs once.
		'dmg_eml_email_id' => array('type'=>'int4', 'index'=>true),
		'dmg_delete_time' => array('type'=>'timestamp(6)'),
	);

	/**
	 * Queue a message to an audience. Each person is written once, by account
	 * and by address; an account with no address is skipped.
	 *
	 * @param User   $sender
	 * @param string $provider     recipient-group provider key ('user', 'group', 'event', ...)
	 * @param int    $reference_id the provider's thing (a user, group or event id)
	 * @param int[]  $also_user_ids more people to send to (an event's leader, the sender's copy)
	 * @return DirectMessage the saved message; dmg_recipient_count says how many
	 * @throws DirectMessageException for a provider nothing has registered
	 */
	public static function queue_to(User $sender, string $provider, int $reference_id, string $subject,
			string $message_html, ?string $inner_template, array $also_user_ids = array()): DirectMessage {
		$audience = RecipientGroupProviderRegistry::get($provider);
		if (!$audience) {
			throw new DirectMessageException('No audience called ' . $provider . ' is available here.');
		}
		$user_ids = array_map('intval', $audience->resolve($reference_id));
		foreach ($also_user_ids as $uid) {
			$user_ids[] = (int)$uid;
		}

		return DbConnector::transaction(function () use ($sender, $provider, $reference_id, $subject, $message_html, $inner_template, $user_ids) {
			$message = new DirectMessage(NULL);
			$message->set('dmg_usr_user_id_sender', $sender->key);
			$message->set('dmg_subject', mb_substr($subject, 0, 255));
			$message->set('dmg_message_html', $message_html);
			$message->set('dmg_inner_template', $inner_template);
			$message->set('dmg_provider', $provider);
			$message->set('dmg_reference_id', $reference_id);
			$message->set('dmg_status', self::QUEUED);
			$message->save();
			$message->load();

			$seen_users = array();
			$seen_addresses = array();
			$count = 0;
			foreach ($user_ids as $uid) {
				if ($uid <= 0 || isset($seen_users[$uid])) {
					continue;
				}
				$seen_users[$uid] = true;
				$user = new User($uid, TRUE);
				$address = strtolower(trim((string)$user->get('usr_email')));
				if (!$user->key || $address === '' || $user->get('usr_delete_time') || isset($seen_addresses[$address])) {
					continue;
				}
				$seen_addresses[$address] = true;
				$recipient = new DirectMessageRecipient(NULL);
				$recipient->set('dmr_dmg_direct_message_id', $message->key);
				$recipient->set('dmr_usr_user_id', $user->key);
				$recipient->set('dmr_email', $address);
				$recipient->set('dmr_name', mb_substr(trim($user->display_name()), 0, 255));
				$recipient->set('dmr_status', DirectMessageRecipient::PENDING);
				$recipient->save();
				$count++;
			}

			$message->set('dmg_recipient_count', $count);
			if ($count === 0) {
				$message->set('dmg_status', self::SENT);
				$message->set('dmg_sent_time', 'now()');
			}
			$message->save();
			return $message;
		});
	}

	/**
	 * Send waiting recipients, oldest first, up to $limit across every
	 * message. Each is claimed before it is sent, so two passes never send one
	 * twice; a message whose recipients have all been tried is marked sent.
	 *
	 * A refused send is tried again later (RETRY_MINUTES times its attempts)
	 * up to MAX_ATTEMPTS, then it is not sent. After STOP_AFTER_FAILURES
	 * refusals in a row the pass stops and leaves the rest waiting: the
	 * provider is down or the month's allowance is spent, and trying everyone
	 * would spend their attempts on it. Before sending, settle() closes out
	 * what an earlier pass left: a claim older than CLAIM_STALE_MINUTES (the
	 * pass died mid-send) is not sent, never sent twice, and a message older
	 * than GIVE_UP_DAYS stops waiting, since mail that late is no longer news.
	 *
	 * @param int|null $only_message_id just this message's recipients (a test's own)
	 * @return array{sent:int, failed:int}
	 */
	public static function send_pending(int $limit = self::SEND_BATCH, ?int $only_message_id = null): array {
		$db = DbConnector::get_instance()->get_db_link();
		self::settle($only_message_id);

		$q = $db->prepare('SELECT dmr_direct_message_recipient_id FROM dmr_direct_message_recipients
			JOIN dmg_direct_messages ON dmg_direct_message_id = dmr_dmg_direct_message_id
			WHERE dmr_status = ? AND dmg_delete_time IS NULL AND (?::int8 IS NULL OR dmg_direct_message_id = ?::int8)
			  AND (dmr_next_try_time IS NULL OR dmr_next_try_time <= now())
			ORDER BY dmr_direct_message_recipient_id LIMIT ?');
		$q->execute(array(DirectMessageRecipient::PENDING, $only_message_id, $only_message_id, $limit));
		$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

		$claim = $db->prepare('UPDATE dmr_direct_message_recipients
			SET dmr_status = ?, dmr_claim_time = now(), dmr_attempts = COALESCE(dmr_attempts, 0) + 1
			WHERE dmr_direct_message_recipient_id = ? AND dmr_status = ?
			RETURNING dmr_attempts');
		$finish = $db->prepare('UPDATE dmr_direct_message_recipients SET dmr_status = ?, dmr_sent_time = CASE WHEN ? THEN now() ELSE NULL END
			WHERE dmr_direct_message_recipient_id = ?');
		$retry = $db->prepare("UPDATE dmr_direct_message_recipients
			SET dmr_status = ?, dmr_next_try_time = now() + (? * interval '1 minute')
			WHERE dmr_direct_message_recipient_id = ?");

		$messages = array();
		$sent = 0;
		$failed = 0;
		$refused_in_a_row = 0;
		foreach ($ids as $id) {
			$claim->execute(array(DirectMessageRecipient::SENDING, $id, DirectMessageRecipient::PENDING));
			$attempts = $claim->fetchColumn();
			if ($attempts === false) {
				continue;
			}
			$attempts = (int)$attempts;
			$recipient = new DirectMessageRecipient($id, TRUE);
			$message_id = (int)$recipient->get('dmr_dmg_direct_message_id');
			if (!isset($messages[$message_id])) {
				$messages[$message_id] = new DirectMessage($message_id, TRUE);
			}
			$ok = false;
			try {
				$ok = $messages[$message_id]->send_one($recipient);
			} catch (Throwable $e) {
				error_log('[DirectMessage] message ' . $message_id . ' to recipient ' . $id . ': ' . $e->getMessage());
			}
			if ($ok) {
				$finish->execute(array(DirectMessageRecipient::SENT, 1, $id));
				$sent++;
				$refused_in_a_row = 0;
				continue;
			}
			if ($attempts < self::MAX_ATTEMPTS) {
				$retry->execute(array(DirectMessageRecipient::PENDING, self::RETRY_MINUTES * $attempts, $id));
			} else {
				$finish->execute(array(DirectMessageRecipient::FAILED, 0, $id));
				$failed++;
			}
			if (++$refused_in_a_row >= self::STOP_AFTER_FAILURES) {
				break;
			}
		}

		foreach ($messages as $message) {
			$message->mark_sent_when_done();
		}
		return array('sent' => $sent, 'failed' => $failed);
	}

	/**
	 * Close out what earlier passes left: stale claims and messages too old to
	 * send are not sent, and any message with nothing left waiting is marked
	 * sent, whichever pass touched it last.
	 */
	public static function settle(?int $only_message_id = null): void {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("UPDATE dmr_direct_message_recipients SET dmr_status = ?
			WHERE dmr_status = ? AND dmr_claim_time < now() - (? * interval '1 minute')
			  AND (?::int8 IS NULL OR dmr_dmg_direct_message_id = ?::int8)")
			->execute(array(DirectMessageRecipient::FAILED, DirectMessageRecipient::SENDING, self::CLAIM_STALE_MINUTES, $only_message_id, $only_message_id));
		$db->prepare("UPDATE dmr_direct_message_recipients SET dmr_status = ?
			WHERE dmr_status = ? AND (?::int8 IS NULL OR dmr_dmg_direct_message_id = ?::int8)
			  AND dmr_dmg_direct_message_id IN (SELECT dmg_direct_message_id FROM dmg_direct_messages
				WHERE dmg_create_time < now() - (? * interval '1 day'))")
			->execute(array(DirectMessageRecipient::FAILED, DirectMessageRecipient::PENDING, $only_message_id, $only_message_id, self::GIVE_UP_DAYS));
		$q = $db->prepare("SELECT dmg_direct_message_id FROM dmg_direct_messages
			WHERE dmg_status = ? AND (?::int8 IS NULL OR dmg_direct_message_id = ?::int8)
			  AND NOT EXISTS (SELECT 1 FROM dmr_direct_message_recipients
				WHERE dmr_dmg_direct_message_id = dmg_direct_message_id AND dmr_status IN (?, ?))");
		$q->execute(array(self::QUEUED, $only_message_id, $only_message_id, DirectMessageRecipient::PENDING, DirectMessageRecipient::SENDING));
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			(new DirectMessage((int)$id, TRUE))->mark_sent_when_done();
		}
	}

	/** Render and send this message to one recipient. True when the provider took it. */
	public function send_one(DirectMessageRecipient $recipient): bool {
		$user = $recipient->get('dmr_usr_user_id') ? new User($recipient->get('dmr_usr_user_id'), TRUE) : null;
		$recipient_data = ($user && $user->key) ? $user->export_as_array() : array(
			'usr_email' => $recipient->get('dmr_email'),
			'usr_first_name' => $recipient->get('dmr_name'),
		);
		$vars = array(
			'subject'      => $this->get('dmg_subject'),
			'body'         => $this->get('dmg_message_html'),
			'utm_medium'   => 'email',
			'utm_content'  => urlencode((string)$this->get('dmg_subject')),
			'recipient'    => $recipient_data,
		);
		$template = trim((string)$this->get('dmg_inner_template'));
		$message = $template !== ''
			? EmailMessage::fromTemplate($template, $vars)
			: EmailMessage::create($recipient->get('dmr_email'), $this->get('dmg_subject'), $this->get('dmg_message_html'));
		$message->subject($this->get('dmg_subject'))
			->to($recipient->get('dmr_email'), (string)$recipient->get('dmr_name'));

		$sender = new EmailSender();
		return (bool)$sender->send($message, false);
	}

	/** Mark the message sent once no recipient is waiting or being sent. */
	public function mark_sent_when_done(): void {
		$counts = $this->delivery_counts();
		if ($counts['waiting'] === 0 && (int)$this->get('dmg_status') !== self::SENT) {
			$this->set('dmg_status', self::SENT);
			$this->set('dmg_sent_time', 'now()');
			$this->save();
		}
	}

	/**
	 * How the send went: sent, not sent (the provider refused), and waiting
	 * (queued or mid-send).
	 *
	 * @return array{sent:int, failed:int, waiting:int}
	 */
	public function delivery_counts(): array {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT dmr_status, COUNT(*) FROM dmr_direct_message_recipients
			WHERE dmr_dmg_direct_message_id = ? GROUP BY dmr_status');
		$q->execute(array($this->key));
		$out = array('sent' => 0, 'failed' => 0, 'waiting' => 0);
		foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
			$status = (int)$row[0];
			if ($status === DirectMessageRecipient::SENT) {
				$out['sent'] += (int)$row[1];
			} elseif ($status === DirectMessageRecipient::FAILED) {
				$out['failed'] += (int)$row[1];
			} else {
				$out['waiting'] += (int)$row[1];
			}
		}
		return $out;
	}

	/** Who the message went to, in words: "Members of Volunteers", "Jane Doe". */
	public function audience_label(): string {
		$provider = RecipientGroupProviderRegistry::get((string)$this->get('dmg_provider'));
		if (!$provider) {
			return (string)$this->get('dmg_provider') . ' #' . (int)$this->get('dmg_reference_id');
		}
		return $provider->label() . ': ' . $provider->reference_label((int)$this->get('dmg_reference_id'));
	}

	function get_status_text(): string {
		return (int)$this->get('dmg_status') === self::SENT ? 'Sent' : 'Sending';
	}
}

class DirectMessageException extends SystemBaseException {}

class MultiDirectMessage extends SystemMultiBase {
	protected static $model_class = 'DirectMessage';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		return $this->_get_resultsv2('dmg_direct_messages', $filters, $this->order_by, $only_count, $debug);
	}
}
