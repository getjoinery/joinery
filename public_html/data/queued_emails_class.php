<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class QueuedEmailException extends SystemBaseException {}

/**
 * The outbox: one row for every email the platform sends on its own behalf
 * (specs/email_outbox.md).
 *
 * A row says who an email went to, its subject, the template and the record it
 * was about, and what became of it. Its body and the rest of the message
 * (equ_message_extras) are written only while they may still be needed: a
 * failed send waiting for its retry, a queued email, a send-once email until it
 * is sent. The write that finishes a row clears them.
 *
 * Every status change is written here: claim(), settle(), settle_batch(),
 * give_up() and expire_stale(). EmailSender::send() writes the row and its
 * outcome; the SendQueuedEmails task sends Ready rows and retries Error rows.
 *
 * @version 3.1 - equ_given_up_time (kept across a send-once revive, cleared by Sent); readdress() for a retry sent
 *   to the test trap (reviewer2 F2, F3)
 * @version 3.0 - the outbox: recipients, template, record, send-once key, sent time, service, error and lock time;
 *   content only while it may be needed; one retention rule; statuses 1 and 7 removed
 * @version 2.1 - a send that throws leaves the row at Error sending; equ_to and equ_from hold 254 characters
 */
class QueuedEmail extends SystemBase {	public static $prefix = 'equ';
	public static $tablename = 'equ_queued_emails';
	public static $access_class = 'personal';
	public static $pkey_column = 'equ_queued_email_id';

	// REST CRUD exposure (Layer 1): the provisioning management node queues
	// welcome/alert emails through the API (POST QueuedEmail) with the old
	// columns. Writes require an admin-level principal (default authorization).
	public static $api_writable = true;

	// The outbox's own columns are written only by EmailSender (R11).
	public static $api_unwritable_fields = array(
		'equ_recipients', 'equ_message_extras', 'equ_template', 'equ_entity_type', 'equ_entity_id', 'equ_once_key',
		'equ_service', 'equ_error', 'equ_sent_time', 'equ_locked_time', 'equ_given_up_time',
	);

	// The states an email can be in
	const READY_TO_SEND = 2; // Queued, waiting for the task to send it
	const LOCKED = 3; // Being sent
	const SENT = 4; // Sent
	const DELETED = 5; // Never to be sent: suppressed, forgotten or withdrawn
	const ERROR_SENDING = 6; // Failed; the task retries it
	const PERMANENT_FAILURE = 8; // Failed for good

	public static $status_to_text = array(
		self::READY_TO_SEND => 'Ready To Send',
		self::LOCKED => 'Locked',
		self::SENT => 'Sent',
		self::ERROR_SENDING => 'Error Sending',
		self::DELETED => 'Deleted',
		self::PERMANENT_FAILURE => 'Permanent Failure',
	);

	/**
	 * The rows this process wrote (id => true), so a test harness can remove
	 * the outbox rows its sends left. Nothing in production reads it.
	 */
	public static $written_ids = array();

	/** The largest stored message (equ_message_extras) a retry may need, as JSON. */
	const EXTRAS_MAX_BYTES = 1048576;

	/** A row Locked longer than this belongs to a process that died. */
	const STALE_LOCK_MINUTES = 15;

	/** The service of a row whose suppression (not a person) withdrew it: a send-once key may be revived. */
	const SUPPRESSED_SERVICES = array('dry_run', 'test_trap_empty');

	public static $field_specifications = array(
	    'equ_queued_email_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'equ_from_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'equ_from' => array('type'=>'varchar(254)', 'required'=>true),
	    'equ_to' => array('type'=>'varchar(254)', 'required'=>true),
	    'equ_to_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'equ_body' => array('type'=>'text'),
	    'equ_subject' => array('type'=>'text', 'required'=>true),
	    'equ_timestamp' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'equ_status' => array('type'=>'int2', 'default'=>0, 'index'=>true),
	    'equ_retry_count' => array('type'=>'int2', 'default'=>0),
	    // Every address, whole: {to: [...], cc: [...], bcc: [...]}. Null on rows
	    // from before the outbox and on rows posted over the API (read equ_to).
	    'equ_recipients' => array('type'=>'jsonb'),
	    // The rest of the message a retry needs (EmailSender::buildExtras()).
	    'equ_message_extras' => array('type'=>'jsonb'),
	    'equ_template' => array('type'=>'varchar(100)'),
	    'equ_entity_type' => array('type'=>'varchar(64)', 'unique_with'=>array('equ_entity_id', 'equ_once_key')),
	    'equ_entity_id' => array('type'=>'int8'),
	    'equ_once_key' => array('type'=>'varchar(191)'),
	    'equ_sent_time' => array('type'=>'timestamp(6)'),
	    'equ_service' => array('type'=>'varchar(50)'),
	    'equ_error' => array('type'=>'varchar(255)'),
	    'equ_locked_time' => array('type'=>'timestamp(6)'),
	    // When it last failed for good. Kept when a send-once key is revived by
	    // its feature's next pass, cleared only by Sent, so the failed-email
	    // alarm still sees a notice that keeps failing (reviewer2 F2).
	    'equ_given_up_time' => array('type'=>'timestamp(6)'),
	);

	// Retention (S5): finished rows older than the window go, except a
	// send-once row whose record still exists (purge_finished()).
	public static $retention_policy = array(
		'label'          => 'Finished outbox emails',
		'purge_method'   => 'purge_finished',
		'window_setting' => 'email_outbox_retention_days',
	);

	/**
	 * A row posted over the API while email test mode is on is redirected to
	 * the trap address as it is written, exactly as EmailSender redirects a
	 * message as it is sent: the queue is drained by a different process (the
	 * SendQueuedEmails task) under the site's real settings, so the redirect
	 * has to be on the row. A row already addressed to the trap is left alone.
	 * Test mode with no trap address writes the row as DELETED, so it never
	 * waits for a process where the mode is off.
	 *
	 * EmailSender writes its own rows in SQL, after its own redirect; this is
	 * for rows built with save().
	 */
	function save($debug = false) {
		if ($this->key === NULL) {
			$trap = EmailSender::testModeTrap();
			$to   = trim((string)$this->get('equ_to'));
			if ($trap === '') {
				$this->set('equ_status', self::DELETED);
				$this->set('equ_service', 'test_trap_empty');
			} elseif ($trap !== null && $to !== '' && strcasecmp($to, $trap) !== 0) {
				$this->set('equ_to', $trap);
				// A blank subject stays blank: the required-field check below
				// still has to see it.
				$subject = (string)$this->get('equ_subject');
				if ($subject !== '') {
					$this->set('equ_subject', EmailSender::testModeSubject(array($to), $subject));
				}
			}
		}
		return parent::save($debug);
	}

	function get_status() {
		return self::$status_to_text[(int)$this->get('equ_status')] ?? ('Status ' . $this->get('equ_status'));
	}

	// ── Writing the outbox ───────────────────────────────────────────────

	/**
	 * Run an outbox write: as the server's own write (rows are written during
	 * page views), behind a SAVEPOINT when a caller's transaction is open (a
	 * failed INSERT would otherwise abort the caller's whole transaction), and
	 * inside the email_outbox content-free site when $content_free (a hot
	 * process whose send asserted content-free; such a row never holds content).
	 */
	public static function outbox_write(callable $write, bool $content_free = false) {
		$run = function () use ($write) {
			$db = DbConnector::get_instance()->get_db_link();
			if (!$db->inTransaction()) {
				return $write($db);
			}
			$db->exec('SAVEPOINT email_outbox');
			try {
				$result = $write($db);
			} catch (Throwable $e) {
				$db->exec('ROLLBACK TO SAVEPOINT email_outbox');
				throw $e;
			}
			$db->exec('RELEASE SAVEPOINT email_outbox');
			return $result;
		};
		return SystemBase::server_initiated_write(function () use ($run, $content_free) {
			if ($content_free) {
				require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
				return SealedEgressGuard::contentFree('email_outbox', $run);
			}
			return $run();
		});
	}

	/**
	 * The columns of a row for $message, as it stands after the test-mode
	 * redirect. Names and the address are held to their columns; the
	 * subject is kept whole.
	 */
	private static function row_values(EmailMessage $message): array {
		$people = function ($list) {
			$out = array();
			foreach ((array)$list as $r) {
				$out[] = array('email' => (string)$r['email'], 'name' => $r['name'] ?? null);
			}
			return $out;
		};
		$to = $message->getRecipients();
		$first = $to[0] ?? array('email' => '', 'name' => null);
		$about = $message->getAbout();
		$from = (string)$message->getFrom();
		return array(
			'equ_from' => mb_substr($from, 0, 254),
			'equ_from_name' => mb_substr((string)($message->getFromName() ?: $from), 0, 255),
			'equ_to' => mb_substr((string)$first['email'], 0, 254),
			'equ_to_name' => mb_substr((string)(($first['name'] ?? '') ?: $first['email']), 0, 255),
			'equ_subject' => (string)$message->getSubject(),
			'equ_recipients' => json_encode(array('to' => $people($to), 'cc' => $people($message->getCc()),
				'bcc' => $people($message->getBcc())), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'equ_template' => $message->getTemplateName() !== null ? mb_substr($message->getTemplateName(), 0, 100) : null,
			'equ_entity_type' => $about['type'] ?? null,
			'equ_entity_id' => $about['id'] ?? null,
			'equ_once_key' => $message->getOnceKey(),
		);
	}

	/**
	 * Insert a row for $message at $status (LOCKED for a send under way,
	 * READY_TO_SEND for a queued one, ERROR_SENDING for an unrecorded retry),
	 * with $body and $extras or none. Returns the new row's id.
	 *
	 * @throws Throwable on a database error; the caller decides what that costs
	 */
	public static function open_row(EmailMessage $message, int $status, ?string $body = null, ?string $extras = null,
			?string $service = null, ?string $error = null, bool $content_free = false): int {
		$v = self::row_values($message);
		$v['equ_status'] = $status;
		$v['equ_body'] = $body;
		$v['equ_message_extras'] = $extras;
		$v['equ_service'] = $service;
		$v['equ_error'] = $error !== null ? mb_substr($error, 0, 255) : null;
		$cols = array_keys($v);
		$sql = 'INSERT INTO equ_queued_emails (' . implode(', ', $cols) . ', equ_retry_count, equ_timestamp, equ_locked_time)'
			. ' VALUES (:' . implode(', :', $cols) . ', 0, now(), ' . ($status === self::LOCKED ? 'now()' : 'NULL') . ')'
			. ' RETURNING equ_queued_email_id';
		$id = (int)self::outbox_write(function ($db) use ($sql, $v) {
			$q = $db->prepare($sql);
			$q->execute(self::bind($v));
			return $q->fetchColumn();
		}, $content_free);
		self::$written_ids[$id] = true;
		return $id;
	}

	/**
	 * Take the send-once key of $message: insert its row, or revive the key's
	 * row when that row failed for good or was withdrawn by a suppression (the
	 * same row, new content, retry count 0). Returns the row's id, or null when
	 * the key is already taken (its row is Sent, Ready, Locked or Error sending,
	 * or Deleted by a person): the email is not sent.
	 *
	 * @throws Throwable on a database error: null means "already told", so a
	 *   failed claim must never look like one (N9)
	 */
	public static function claim_once(EmailMessage $message, int $status, ?string $body, ?string $extras,
			bool $content_free = false): ?int {
		$v = self::row_values($message);
		if ($v['equ_entity_type'] === null || $v['equ_once_key'] === null) {
			throw new InvalidArgumentException('A send-once email names its record (about()) and its key (once()).');
		}
		$v['equ_status'] = $status;
		$v['equ_body'] = $body;
		$v['equ_message_extras'] = $extras;
		$cols = array_keys($v);
		$update = array();
		foreach (array('equ_from', 'equ_from_name', 'equ_to', 'equ_to_name', 'equ_subject', 'equ_recipients', 'equ_template',
				'equ_status', 'equ_body', 'equ_message_extras', 'equ_locked_time') as $c) {
			$update[] = $c . ' = EXCLUDED.' . $c;
		}
		$suppressed = "'" . implode("', '", self::SUPPRESSED_SERVICES) . "'";
		$sql = 'INSERT INTO equ_queued_emails (' . implode(', ', $cols) . ', equ_retry_count, equ_timestamp, equ_locked_time)'
			. ' VALUES (:' . implode(', :', $cols) . ', 0, now(), ' . ($status === self::LOCKED ? 'now()' : 'NULL') . ')'
			. ' ON CONFLICT (equ_entity_type, equ_entity_id, equ_once_key) DO UPDATE SET ' . implode(', ', $update)
			. ', equ_retry_count = 0, equ_error = NULL, equ_sent_time = NULL, equ_service = NULL, equ_timestamp = now()'
			. ' WHERE equ_queued_emails.equ_status = ' . self::PERMANENT_FAILURE
			. ' OR (equ_queued_emails.equ_status = ' . self::DELETED . ' AND equ_queued_emails.equ_service IN (' . $suppressed . '))'
			. ' RETURNING equ_queued_email_id';
		$id = self::outbox_write(function ($db) use ($sql, $v) {
			$q = $db->prepare($sql);
			$q->execute(self::bind($v));
			return $q->fetchColumn();
		}, $content_free);
		if ($id === false || $id === null) {
			return null;
		}
		self::$written_ids[(int)$id] = true;
		return (int)$id;
	}

	private static function bind(array $v): array {
		$out = array();
		foreach ($v as $k => $value) {
			$out[':' . $k] = $value;
		}
		return $out;
	}

	/**
	 * Take a Ready or Error sending row to Locked for its send (one UPDATE, so
	 * two task runs never both send it). Returns whether this call took it.
	 */
	public function claim(): bool {
		$id = (int)$this->key;
		$took = self::outbox_write(function ($db) use ($id) {
			$q = $db->prepare('UPDATE equ_queued_emails SET equ_status = ' . self::LOCKED . ', equ_locked_time = now()'
				. ' WHERE equ_queued_email_id = ? AND equ_status IN (' . self::READY_TO_SEND . ', ' . self::ERROR_SENDING . ')');
			$q->execute(array($id));
			return $q->rowCount() === 1;
		});
		if ($took) {
			$this->load();
		}
		return $took;
	}

	/**
	 * Write a send's outcome to its row, only while the row is still Locked
	 * (a row expire_stale() already settled is left as it is). $status is
	 * SENT, ERROR_SENDING, PERMANENT_FAILURE or DELETED. $fields may carry:
	 *   service   which way it left, or why it did not (dry_run, test_trap_empty)
	 *   error     the failure, in short
	 *   body, extras  the content a retry needs (ERROR_SENDING only)
	 *   count_try one more try towards the retry limit
	 * Finishing a row (SENT, PERMANENT_FAILURE, DELETED) clears its content;
	 * finishing an unrecorded retry row deletes it. Never throws: an outcome
	 * that cannot be written is logged, and the stale-lock rule settles the row.
	 *
	 * @return bool whether the row was settled by this call
	 */
	public static function settle(int $id, int $status, array $fields = array(), bool $content_free = false): bool {
		try {
			return (bool)self::outbox_write(function ($db) use ($id, $status, $fields) {
				$finished = in_array($status, array(self::SENT, self::PERMANENT_FAILURE, self::DELETED), true);
				if ($finished) {
					$gone = $db->prepare("DELETE FROM equ_queued_emails WHERE equ_queued_email_id = ? AND equ_status = "
						. self::LOCKED . " AND equ_service = 'unrecorded'");
					$gone->execute(array($id));
					if ($gone->rowCount() === 1) {
						return true;
					}
				}
				$set = array('equ_status = :status');
				$bind = array(':status' => $status, ':id' => $id);
				if ($status === self::SENT) {
					$set[] = 'equ_sent_time = now()';
					$set[] = 'equ_error = NULL';
					$set[] = 'equ_given_up_time = NULL';
				} elseif ($status === self::PERMANENT_FAILURE) {
					$set[] = 'equ_given_up_time = now()';
				}
				if (array_key_exists('service', $fields)) {
					$set[] = 'equ_service = :service';
					$bind[':service'] = $fields['service'] !== null ? mb_substr((string)$fields['service'], 0, 50) : null;
				}
				if (array_key_exists('error', $fields)) {
					$set[] = 'equ_error = :error';
					$bind[':error'] = $fields['error'] !== null ? mb_substr((string)$fields['error'], 0, 255) : null;
				}
				if ($finished) {
					$set[] = 'equ_body = NULL';
					$set[] = 'equ_message_extras = NULL';
				} elseif (array_key_exists('body', $fields)) {
					$set[] = 'equ_body = :body';
					$set[] = 'equ_message_extras = :extras';
					$bind[':body'] = $fields['body'];
					$bind[':extras'] = $fields['extras'] ?? null;
				}
				if (!empty($fields['count_try'])) {
					$set[] = 'equ_retry_count = equ_retry_count + 1';
				}
				$q = $db->prepare('UPDATE equ_queued_emails SET ' . implode(', ', $set)
					. ' WHERE equ_queued_email_id = :id AND equ_status = ' . self::LOCKED);
				$q->execute($bind);
				return $q->rowCount() === 1;
			}, $content_free);
		} catch (Throwable $e) {
			error_log('[EmailOutbox] could not write the outcome of email ' . $id . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * A Locked row's recipients and subject, made $message's: a retry sent to
	 * the test trap says so on its row. Never throws.
	 */
	public static function readdress(int $id, EmailMessage $message): void {
		try {
			$v = self::row_values($message);
			self::outbox_write(function ($db) use ($id, $v) {
				$db->prepare('UPDATE equ_queued_emails SET equ_to = ?, equ_to_name = ?, equ_recipients = ?, equ_subject = ?'
					. ' WHERE equ_queued_email_id = ? AND equ_status = ' . self::LOCKED)
					->execute(array($v['equ_to'], $v['equ_to_name'], $v['equ_recipients'], $v['equ_subject'], $id));
			});
		} catch (Throwable $e) {
			error_log('[EmailOutbox] could not readdress email ' . $id . ' to the test trap: ' . $e->getMessage());
		}
	}

	/**
	 * Settle a batch's rows (EmailSender::sendBatch()): $rows maps each
	 * recipient's lower-cased address to its row id; a recipient in $failed
	 * goes to Error sending with its own content (from $content, called with
	 * the address: array{body, extras} or null when it cannot be stored, which
	 * is Permanent failure), every other to Sent by $service.
	 */
	public static function settle_batch(array $rows, array $failed, string $service, callable $content): void {
		$failed = array_map('strtolower', array_map('strval', $failed));
		foreach ($rows as $email => $id) {
			if (!in_array($email, $failed, true)) {
				self::settle((int)$id, self::SENT, array('service' => $service));
				continue;
			}
			$stored = $content($email);
			if ($stored === null) {
				self::settle((int)$id, self::PERMANENT_FAILURE, array('error' => 'The batch send failed; its retry could not be stored.'));
			} else {
				self::settle((int)$id, self::ERROR_SENDING, array('error' => 'The batch send failed for this recipient.',
					'body' => $stored['body'], 'extras' => $stored['extras']));
			}
		}
	}

	/**
	 * Error rows that have used their tries go to Permanent failure, content
	 * cleared; unrecorded ones are deleted. Returns how many.
	 */
	public static function give_up(int $max_retries): int {
		return (int)self::outbox_write(function ($db) use ($max_retries) {
			$gone = $db->prepare("DELETE FROM equ_queued_emails WHERE equ_status = " . self::ERROR_SENDING
				. " AND equ_retry_count >= ? AND equ_service = 'unrecorded'");
			$gone->execute(array($max_retries));
			$q = $db->prepare('UPDATE equ_queued_emails SET equ_status = ' . self::PERMANENT_FAILURE
				. ', equ_body = NULL, equ_message_extras = NULL, equ_given_up_time = now() WHERE equ_status = ' . self::ERROR_SENDING
				. ' AND equ_retry_count >= ?');
			$q->execute(array($max_retries));
			$count = $gone->rowCount() + $q->rowCount();
			if ($q->rowCount() > 0) {
				error_log('[EmailOutbox] ' . $q->rowCount() . ' email(s) failed for good after ' . $max_retries . ' tries');
			}
			return $count;
		});
	}

	/**
	 * Rows Locked longer than STALE_LOCK_MINUTES belong to a process that
	 * died. One holding its content (a queued or send-once email) goes back to
	 * Error sending, counting a try, so it is retried and a send that kills its
	 * process every time still reaches the limit. One without (an ordinary
	 * email: whether it went is unknown) goes to Permanent failure and is never
	 * sent twice. Returns how many.
	 */
	public static function expire_stale(): int {
		return (int)self::outbox_write(function ($db) {
			$age = "equ_locked_time < now() - INTERVAL '" . (int)self::STALE_LOCK_MINUTES . " minutes'";
			$retry = $db->prepare('UPDATE equ_queued_emails SET equ_status = ' . self::ERROR_SENDING
				. ", equ_retry_count = equ_retry_count + 1, equ_error = 'The sending process stopped before the outcome.'"
				. ' WHERE equ_status = ' . self::LOCKED . ' AND ' . $age . ' AND nullif(equ_body, \'\') IS NOT NULL');
			$retry->execute();
			$lost = $db->prepare('UPDATE equ_queued_emails SET equ_status = ' . self::PERMANENT_FAILURE
				. ", equ_given_up_time = now(), equ_error = 'The sending process stopped; whether it was sent is not known.'"
				. ' WHERE equ_status = ' . self::LOCKED . ' AND ' . $age . ' AND nullif(equ_body, \'\') IS NULL');
			$lost->execute();
			return $retry->rowCount() + $lost->rowCount();
		});
	}

	// ── Sending a row ────────────────────────────────────────────────────

	/**
	 * The message this row holds, rebuilt for its send: every address
	 * (equ_recipients, or equ_to on an older row), the subject, From, the body
	 * and the stored message (equ_message_extras) — or, after a partial
	 * delivery, only the addresses still owed. Marked with the row's id, so
	 * EmailSender::send() records to this row and writes none of its own.
	 */
	public function to_message(): EmailMessage {
		$recipients = $this->get_json_decoded('equ_recipients');
		$extras = $this->get_json_decoded('equ_message_extras');
		$extras = is_array($extras) ? $extras : array();
		$body = (string)$this->get('equ_body');

		$data = array(
			'from' => (string)$this->get('equ_from'),
			'from_name' => (string)$this->get('equ_from_name'),
			'subject' => (string)$this->get('equ_subject'),
			'to' => is_array($recipients) && !empty($recipients['to'])
				? $recipients['to'] : array(array('email' => (string)$this->get('equ_to'), 'name' => (string)$this->get('equ_to_name'))),
			'cc' => is_array($recipients) ? ($recipients['cc'] ?? array()) : array(),
			'bcc' => is_array($recipients) ? ($recipients['bcc'] ?? array()) : array(),
			'reply_to' => $extras['reply_to'] ?? null,
			'headers' => $extras['headers'] ?? array(),
			'metadata' => $extras['metadata'] ?? array(),
			'message_id' => $extras['message_id'] ?? null,
			'attachments' => $extras['attachments'] ?? array(),
		);
		if ($extras === array()) {
			// A row from before the outbox, or posted over the API: its body
			// alone, read as HTML when it has tags, as EmailMessage::create() reads it.
			$is_html = strip_tags($body) !== $body;
			$data['html'] = $is_html ? $body : null;
			$data['text'] = $is_html ? null : $body;
		} elseif (!empty($extras['plain'])) {
			$data['html'] = null;
			$data['text'] = $body;
		} else {
			$data['html'] = $body;
			$data['text'] = $extras['text'] ?? null;
		}
		if (!empty($extras['owed']) && is_array($extras['owed'])) {
			$owed = array_map('strtolower', array_map('strval', $extras['owed']));
			$data['to'] = array_values(array_filter($data['to'], function ($r) use ($owed) {
				return in_array(strtolower((string)$r['email']), $owed, true);
			}));
		}
		$message = EmailMessage::fromTransfer($data);
		if ($data['html'] !== null && ($data['text'] === null || $data['text'] === '')) {
			$message->html($data['html']); // the text part, made from the HTML
		}
		$message->fromRow((int)$this->key);
		$message->setTemplateName($this->get('equ_template') !== null ? (string)$this->get('equ_template') : null);
		if ($this->get('equ_service') === 'unrecorded') {
			$message->markUnrecordedFromRow();
		}
		return $message;
	}

	/**
	 * Send this Ready or Error sending row: claim it, rebuild its message and
	 * send it. EmailSender::send() writes the outcome to this row. A send that
	 * throws leaves the row at Error sending, never Locked, and the exception
	 * goes on.
	 *
	 * @return bool whether it was sent
	 */
	function send($queue_on_failure = true) {
		// The claim has to be durable before the email goes out. Inside a
		// caller's transaction, a rollback after the send would put it back
		// and the email would go out again.
		if (DbConnector::get_instance()->get_db_link()->inTransaction()) {
			throw new QueuedEmailException(
				'A queued email is sent outside any transaction, so its claim commits before it goes out.');
		}
		if (!$this->claim()) {
			throw new QueuedEmailException(
				'Attempting to send a Queued Email which is not in the correct state. Aborting...');
		}

		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		try {
			$message = $this->to_message();
		} catch (Throwable $e) {
			self::settle((int)$this->key, self::PERMANENT_FAILURE, array('error' => 'The stored message could not be read: ' . $e->getMessage()));
			throw $e;
		}
		$sender = new EmailSender();
		$result = $sender->send($message, $queue_on_failure);
		$this->load();
		return (bool)$result;
	}

	// ── Retention ────────────────────────────────────────────────────────

	/**
	 * Delete finished rows (Sent, Permanent failure, Deleted) older than
	 * $window days, except a send-once row whose record still exists — its key
	 * is what stops the email going twice. A record type is mapped to its model
	 * through the models' own $tablename, never put into SQL; a soft-deleted
	 * record counts as existing; a type whose model does not resolve (its
	 * plugin inactive) keeps its rows.
	 */
	public static function purge_finished($window) {
		$db = DbConnector::get_instance()->get_db_link();
		$finished = '(' . self::SENT . ', ' . self::PERMANENT_FAILURE . ', ' . self::DELETED . ')';
		$age = "equ_timestamp < now() - (INTERVAL '1 day' * :window)";

		$plain = $db->prepare('DELETE FROM equ_queued_emails WHERE equ_status IN ' . $finished . ' AND ' . $age
			. ' AND equ_once_key IS NULL');
		$plain->execute(array(':window' => (int)$window));
		$removed = $plain->rowCount();

		$types = $db->prepare('SELECT DISTINCT equ_entity_type FROM equ_queued_emails WHERE equ_status IN ' . $finished
			. ' AND ' . $age . ' AND equ_once_key IS NOT NULL');
		$types->execute(array(':window' => (int)$window));
		foreach ($types->fetchAll(PDO::FETCH_COLUMN) as $type) {
			$model = self::model_for_table((string)$type);
			if ($model === null) {
				continue;
			}
			// Table and key column come from the model's own declaration.
			$q = $db->prepare('DELETE FROM equ_queued_emails e WHERE e.equ_status IN ' . $finished
				. ' AND e.equ_timestamp < now() - (INTERVAL \'1 day\' * :window) AND e.equ_once_key IS NOT NULL'
				. ' AND e.equ_entity_type = :type AND NOT EXISTS (SELECT 1 FROM ' . $model::$tablename . ' r WHERE r.'
				. $model::$pkey_column . ' = e.equ_entity_id)');
			$q->execute(array(':window' => (int)$window, ':type' => $type));
			$removed += $q->rowCount();
		}
		return array('removed' => $removed, 'message' => $removed ? $removed . ' row(s)' : '');
	}

	/** The loaded model class whose $tablename is $table, or null. */
	public static function model_for_table(string $table): ?string {
		static $map = null;
		if ($map === null) {
			$map = array();
			// Every model the site has, core and active plugins, so a record
			// type from a plugin resolves while it is active.
			$classes = LibraryFunctions::discover_model_classes(array(
				'require_tablename' => true,
				'include_plugins' => true,
				'plugin_status' => 'active',
			));
			foreach ($classes as $class) {
				if (is_subclass_of($class, 'SystemBase') && !empty($class::$pkey_column)) {
					$map[strtolower($class::$tablename)] = $class;
				}
			}
		}
		return $map[strtolower($table)] ?? null;
	}
}

class MultiQueuedEmail extends SystemMultiBase {
	protected static $model_class = 'QueuedEmail';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['status'])) {
			$filters['equ_status'] = [$this->options['status'], PDO::PARAM_INT];
		}

		if (isset($this->options['multi_status'])) {
			$statuses = array_map('intval', (array)$this->options['multi_status']);
			$filters['equ_status'] = SqlCondition::bind('IN (?)', $statuses);
		}

		// The Outbox page: an address matches the first To or any address on the row.
		if (isset($this->options['address']) && trim((string)$this->options['address']) !== '') {
			$like = '%' . trim((string)$this->options['address']) . '%';
			$filters['(equ_to'] = SqlCondition::bind('ILIKE ? OR equ_recipients::text ILIKE ?)', $like, $like);
		}

		if (isset($this->options['since']) && $this->options['since'] !== '') {
			$filters['equ_timestamp'] = SqlCondition::bind('>= ?', (string)$this->options['since']);
		}

		if (isset($this->options['until']) && $this->options['until'] !== '') {
			$filters['(equ_timestamp'] = SqlCondition::bind('< ?)', (string)$this->options['until']);
		}

		return $this->_get_resultsv2('equ_queued_emails', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
