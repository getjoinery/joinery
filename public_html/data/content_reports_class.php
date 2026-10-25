<?php
/**
 * ContentReport — a member saying "an admin should look at this" about one
 * piece of other people's content: a forum post, a chat message, anything a
 * feature registers a ContentReportHandler for.
 *
 * A report names its content by type and id (rpt_entity_type / rpt_entity_id),
 * never by a foreign key, so one table serves every feature. The handler for
 * the type (ContentReportRegistry) answers everything about the content
 * itself: whether the reporter may report it, what it looks like in the
 * queue, and what an admin may do to it.
 *
 * A person has at most one open report on a piece of content (a partial
 * unique index), so reporting twice is not two rows. An admin's decision —
 * acting on the content, or dismissing the report — settles every open report
 * on that content at once, since they all ask the same question.
 *
 * Statuses:
 *   open       waiting for an admin
 *   actioned   an admin acted on the content (hid it, deleted it, …)
 *   dismissed  an admin looked and left the content as it is
 *
 * See docs/social_features.md § Content reports.
 *
 * @version 1.0.0
 */

/** A refusal with a message the reporter or the admin can read. */
class ContentReportException extends SystemBaseException {}

class ContentReport extends SystemBase {
	public static $prefix = 'rpt';
	public static $tablename = 'rpt_content_reports';
	public static $access_class = 'personal';
	public static $pkey_column = 'rpt_content_report_id';

	const STATUS_OPEN      = 'open';
	const STATUS_ACTIONED  = 'actioned';
	const STATUS_DISMISSED = 'dismissed';

	const REASON_SPAM      = 'spam';
	const REASON_ABUSE     = 'abuse';
	const REASON_OFF_TOPIC = 'off_topic';
	const REASON_OTHER     = 'other';

	/** Longest note a reporter may add, in characters. */
	const NOTE_MAX = 1000;

	public static $field_specifications = array(
		'rpt_content_report_id'      => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		// The reporter.
		'rpt_usr_user_id'            => array('type' => 'int4', 'is_nullable' => false, 'index' => true),
		// What was reported: a handler's type and that content's own id.
		'rpt_entity_type'            => array('type' => 'varchar(64)', 'is_nullable' => false, 'required' => true),
		'rpt_entity_id'              => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'rpt_reason'                 => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true,
			'allowed_values' => array(self::REASON_SPAM, self::REASON_ABUSE, self::REASON_OFF_TOPIC, self::REASON_OTHER)),
		'rpt_note'                   => array('type' => 'text', 'is_nullable' => true),
		'rpt_status'                 => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'open',
			'allowed_values' => array(self::STATUS_OPEN, self::STATUS_ACTIONED, self::STATUS_DISMISSED)),
		// Who settled it, when, and what they did ("Hid the post").
		'rpt_usr_user_id_handled_by' => array('type' => 'int4', 'is_nullable' => true),
		'rpt_handled_time'           => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'rpt_outcome'                => array('type' => 'varchar(255)', 'is_nullable' => true),
		'rpt_create_time'            => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	public static $index_specifications = array(
		array('columns' => array('rpt_status', 'rpt_create_time')),
		array('columns' => array('rpt_entity_type', 'rpt_entity_id')),
		// One open report per reporter per piece of content.
		array('columns' => array('rpt_usr_user_id', 'rpt_entity_type', 'rpt_entity_id'), 'unique' => true,
			'where' => "rpt_status = 'open'"),
	);

	protected static $foreign_key_actions = array(
		// A report stays meaningful without the account that made or settled it:
		// the queue still shows what was reported and what was decided.
		'rpt_usr_user_id'            => array('action' => 'set_value', 'value' => User::USER_DELETED),
		'rpt_usr_user_id_handled_by' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	/** Reason code => the words the reporter and the queue show. */
	public static function reasons(): array {
		return array(
			self::REASON_SPAM      => 'Spam',
			self::REASON_ABUSE     => 'Abusive or harassing',
			self::REASON_OFF_TOPIC => 'Off topic',
			self::REASON_OTHER     => 'Something else',
		);
	}

	public function reason_label(): string {
		$reasons = self::reasons();
		return $reasons[(string)$this->get('rpt_reason')] ?? (string)$this->get('rpt_reason');
	}

	public function status_label(): string {
		switch ((string)$this->get('rpt_status')) {
			case self::STATUS_OPEN:      return 'Open';
			case self::STATUS_ACTIONED:  return 'Acted on';
			case self::STATUS_DISMISSED: return 'Dismissed';
		}
		return (string)$this->get('rpt_status');
	}

	public function is_open(): bool {
		return (string)$this->get('rpt_status') === self::STATUS_OPEN;
	}

	/** The handler for this report's content, or null when its feature is gone. */
	public function handler(): ?ContentReportHandler {
		return ContentReportRegistry::get((string)$this->get('rpt_entity_type'));
	}

	// A reporter may see their own reports (the owner rule); admins see every
	// report. Only admins change one: the reporter's part ends when they file it.
	function authenticate_write($data) {
		if ($this->key === NULL && $this->access_owner_is($data['current_user_id'] ?? 0)) {
			return;
		}
		if (!Access::allows(static::access_level('write'), (int)($data['current_user_permission'] ?? 0))) {
			throw new SystemAuthenticationError('Changing a report needs level ' . static::access_level('write') . '.');
		}
	}

	/**
	 * File a report, or find the one this person already has open on this
	 * content. Returns [report, created]. A second report on content someone
	 * has already reported is not a second row and not a second alert.
	 *
	 * @throws ContentReportException when the type is unknown, the person may
	 *   not report this content, or the reason is not one of reasons()
	 */
	public static function file(string $entity_type, int $entity_id, int $user_id, string $reason, ?string $note): array {
		$handler = ContentReportRegistry::get($entity_type);
		if ($handler === null || $entity_id <= 0) {
			throw new ContentReportException('That cannot be reported.');
		}
		if (!array_key_exists($reason, self::reasons())) {
			throw new ContentReportException('Choose a reason for the report.');
		}
		if (!$handler->mayReport($entity_id, $user_id)) {
			throw new ContentReportException('That is not something you can report.');
		}
		$note = trim((string)$note);
		if (mb_strlen($note) > self::NOTE_MAX) {
			throw new ContentReportException('Keep the note under ' . self::NOTE_MAX . ' characters.');
		}

		$existing = self::openBy($entity_type, $entity_id, $user_id);
		if ($existing) {
			return array($existing, false);
		}

		$report = new ContentReport(NULL);
		$report->set('rpt_usr_user_id', $user_id);
		$report->set('rpt_entity_type', $entity_type);
		$report->set('rpt_entity_id', $entity_id);
		$report->set('rpt_reason', $reason);
		$report->set('rpt_note', $note === '' ? null : $note);
		$report->set('rpt_status', self::STATUS_OPEN);
		try {
			$report->save();
		} catch (Throwable $e) {
			// Two submits racing: the partial unique index let one through.
			$existing = self::openBy($entity_type, $entity_id, $user_id);
			if ($existing) {
				return array($existing, false);
			}
			throw $e;
		}
		return array($report, true);
	}

	/** This person's open report on this content, if they have one. */
	public static function openBy(string $entity_type, int $entity_id, int $user_id): ?ContentReport {
		$found = new MultiContentReport(array(
			'rpt_entity_type' => $entity_type,
			'rpt_entity_id'   => $entity_id,
			'rpt_usr_user_id' => $user_id,
			'rpt_status'      => self::STATUS_OPEN,
		), array(), 1);
		foreach ($found as $report) {
			return $report;
		}
		return null;
	}

	/**
	 * Run one of the handler's actions on this report's content and settle
	 * every open report on it. Returns the handler's message.
	 *
	 * @throws ContentReportException when the report is settled, the feature
	 *   is gone, or the action is not one the handler offers
	 */
	public function act(string $action, int $admin_user_id): string {
		if (!$this->is_open()) {
			throw new ContentReportException('This report has already been settled.');
		}
		$handler = $this->handler();
		if ($handler === null) {
			throw new ContentReportException('The feature this content belongs to is not active; the report can only be dismissed.');
		}
		$entity_id = (int)$this->get('rpt_entity_id');
		if (!array_key_exists($action, $handler->actions($entity_id))) {
			throw new ContentReportException('That is not an action this content offers.');
		}
		try {
			$message = (string)$handler->act($entity_id, $action, $admin_user_id);
		} catch (ContentReportException $e) {
			throw $e;
		} catch (Throwable $e) {
			// A handler refuses with whatever exception its feature uses; the
			// queue shows its message and leaves the report open.
			throw new ContentReportException($e->getMessage(), 0, $e);
		}
		$this->settleAll(self::STATUS_ACTIONED, $admin_user_id, $message);
		return $message;
	}

	/** Leave the content as it is and settle every open report on it. */
	public function dismiss(int $admin_user_id): void {
		if (!$this->is_open()) {
			throw new ContentReportException('This report has already been settled.');
		}
		$this->settleAll(self::STATUS_DISMISSED, $admin_user_id, 'Dismissed');
	}

	/**
	 * Settle every open report on this report's content with one decision.
	 * Returns how many were settled.
	 */
	private function settleAll(string $status, int $admin_user_id, string $outcome): int {
		$q = DbConnector::GetPreparedStatement('UPDATE rpt_content_reports
			SET rpt_status = ?, rpt_usr_user_id_handled_by = ?, rpt_handled_time = now(), rpt_outcome = ?
			WHERE rpt_entity_type = ? AND rpt_entity_id = ? AND rpt_status = ?');
		$q->execute(array($status, $admin_user_id, mb_substr($outcome, 0, 255),
			(string)$this->get('rpt_entity_type'), (int)$this->get('rpt_entity_id'), self::STATUS_OPEN));
		$this->load();
		return $q->rowCount();
	}

	/** How many reports wait for an admin — the number beside the menu entry. */
	public static function open_count(): int {
		$q = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM rpt_content_reports WHERE rpt_status = 'open'");
		$q->execute();
		return (int)$q->fetchColumn();
	}

	/** How many open reports name this report's content, this one included. */
	public function open_on_same_content(): int {
		$q = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM rpt_content_reports
			WHERE rpt_entity_type = ? AND rpt_entity_id = ? AND rpt_status = 'open'");
		$q->execute(array((string)$this->get('rpt_entity_type'), (int)$this->get('rpt_entity_id')));
		return (int)$q->fetchColumn();
	}
}

class MultiContentReport extends SystemMultiBase {
	protected static $model_class = 'ContentReport';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		return $this->_get_resultsv2('rpt_content_reports', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
