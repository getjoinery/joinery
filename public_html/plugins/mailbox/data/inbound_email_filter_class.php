<?php
/**
 * InboundEmailFilter - Gmail-parity inbound filters (rules).
 *
 * An operator-defined rule that matches locally-received mail at ingest and
 * applies disposition actions to it automatically — the inbound-email
 * equivalent of Gmail's "Settings -> Filters and Blocked Addresses". A filter
 * has two parts:
 *
 *   - Criteria: From, To, Subject, "Has the words", "Doesn't have", Size
 *     (greater/less than), and "Has attachment".
 *   - Actions: apply a label, star, mark read, skip the inbox (archive), mark
 *     as spam, never send to spam, forward to an address, delete.
 *
 * The match/act logic lives on this model (Active Record): a filter owns its
 * criteria and actions, so it judges and acts on a message itself. There is no
 * separate engine class.
 *
 *   - matches()      : pure criteria evaluation against a stored message.
 *   - applyActions() : apply THIS filter's actions to a message.
 *   - runForMessage(): static orchestrator — load every in-scope enabled filter,
 *                      accumulate the actions of all that match, apply them once
 *                      in a fixed order, and log the match.
 *
 * SCOPE. Filters apply ONLY to mail this platform receives as the mail server
 * (the Postfix milter path and the provider-webhook path, both of which funnel
 * through InboundEmailRouter::storeMessage). They do NOT run on IMAP-polled
 * feeds: an IMAP feed mirrors an upstream account that already runs its own
 * filters, and the reader's two-way sync treats the remote as the source of
 * truth for flag/label state. storeMessage is the local-only path, so the
 * ingest hook there scopes filters correctly without a per-path branch.
 *
 * AUTHORITY. runForMessage executes at ingest with system authority (no logged-
 * in viewer), so it writes flags/membership directly through the model layer
 * rather than the viewer-scoped MailboxService, whose grant checks are
 * meaningless here. The primitives are identical; only the authorization
 * wrapper differs.
 *
 * @see specs/implemented/inbound_email_filters.md
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_log_class.php'));

class InboundEmailFilterException extends SystemBaseException {}

class InboundEmailFilter extends SystemBase {
	public static $prefix = 'fil';
	public static $tablename = 'fil_inbound_email_filters';
	public static $pkey_column = 'fil_inbound_email_filter_id';

	const SIZE_OP_GT = 'gt';
	const SIZE_OP_LT = 'lt';

	// Gmail's size-unit enums -> bytes (used only when a real `size` value is present).
	const GMAIL_SIZE_UNIT_BYTES = array('s_sb' => 1, 's_skb' => 1024, 's_smb' => 1048576);

	protected static $foreign_key_actions = array(
		// A filter dies with its mailbox or its domain. The label-apply action
		// (fil_action_ilb_inbound_email_label_id) guards for a since-deleted label at
		// apply time, so it is intentionally not a cascade FK — deleting a label leaves
		// stale filter actions that simply no-op rather than cascading the filter away.
		'fil_iea_inbound_email_alias_id'  => array('action' => 'cascade'),
		'fil_ied_inbound_email_domain_id' => array('action' => 'cascade'),
	);

	public static $permanent_delete_actions = array();

	public static $field_specifications = array(
		'fil_inbound_email_filter_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// Scope: a filter belongs to a mailbox (alias). NULL alias = domain-wide,
		// running for every alias under fil_ied_inbound_email_domain_id.
		'fil_iea_inbound_email_alias_id'  => array('type'=>'int8'),
		'fil_ied_inbound_email_domain_id' => array('type'=>'int4', 'is_nullable'=>false),
		'fil_name'        => array('type'=>'varchar(255)'),
		'fil_is_enabled'  => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'fil_order'       => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),

		// criteria
		'fil_match_from'           => array('type'=>'varchar(500)'),
		'fil_match_to'             => array('type'=>'varchar(500)'),
		'fil_match_subject'        => array('type'=>'varchar(1000)'),
		'fil_match_has_words'      => array('type'=>'text'),
		'fil_match_excludes'       => array('type'=>'text'),
		'fil_match_size_op'        => array('type'=>'varchar(2)'),   // 'gt' | 'lt' | NULL
		'fil_match_size_bytes'     => array('type'=>'int8'),
		'fil_match_has_attachment' => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),

		// actions
		'fil_action_ilb_inbound_email_label_id' => array('type'=>'int8'),  // the custom label to apply (ilb_)
		'fil_action_star'        => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'fil_action_mark_read'   => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'fil_action_archive'     => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'fil_action_mark_spam'   => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'fil_action_never_spam'  => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'fil_action_forward_to'  => array('type'=>'varchar(500)'),
		'fil_action_delete'      => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),

		// backfill bookkeeping ("Also apply to existing")
		'fil_apply_existing_pending' => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'fil_apply_existing_cursor'  => array('type'=>'int8', 'default'=>'0', 'is_nullable'=>false),

		'fil_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'fil_update_time' => array('type'=>'timestamp(6)'),
		'fil_delete_time' => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		$this->set('fil_update_time', gmdate('Y-m-d H:i:s'));

		// A filter must do something to one of the criteria — reject an all-empty
		// rule so a stray save can't match (and act on) every message.
		if (!$this->hasAnyCriterion()) {
			throw new InboundEmailFilterException('A filter needs at least one matching criterion.');
		}

		// Normalize the size operator (blank unless a real op + a positive size).
		$op = (string)$this->get('fil_match_size_op');
		$bytes = intval($this->get('fil_match_size_bytes'));
		if (!in_array($op, array(self::SIZE_OP_GT, self::SIZE_OP_LT), true) || $bytes <= 0) {
			$this->set('fil_match_size_op', null);
			$this->set('fil_match_size_bytes', null);
		}

		// A forward target, when present, must be a valid address (operator-only;
		// no verification handshake, but never an obviously broken relay target).
		$fwd = trim((string)$this->get('fil_action_forward_to'));
		if ($fwd !== '' && !filter_var($fwd, FILTER_VALIDATE_EMAIL)) {
			throw new InboundEmailFilterException('Forward-to is not a valid email address: ' . htmlspecialchars($fwd));
		}
		$this->set('fil_action_forward_to', $fwd !== '' ? $fwd : null);
	}

	/** True when at least one criterion field is set (the save-time guard). */
	function hasAnyCriterion(): bool {
		foreach (array('fil_match_from', 'fil_match_to', 'fil_match_subject',
				'fil_match_has_words', 'fil_match_excludes') as $f) {
			if (trim((string)$this->get($f)) !== '') {
				return true;
			}
		}
		if ((bool)$this->get('fil_match_has_attachment')) {
			return true;
		}
		$op = (string)$this->get('fil_match_size_op');
		if (in_array($op, array(self::SIZE_OP_GT, self::SIZE_OP_LT), true)
				&& intval($this->get('fil_match_size_bytes')) > 0) {
			return true;
		}
		return false;
	}

	// ------------------------------------------------------------ matching

	/**
	 * Does this filter match the given stored message? Pure and side-effect-free
	 * (the single source of truth for criteria, shared by ingest and backfill).
	 *
	 * All non-empty criteria must match (AND across fields — Gmail's model).
	 *
	 * $plaintext, when supplied, carries the sender/subject/body_plain/body_html
	 * the caller already has in hand from the ingest parse (InboundEmailRouter::
	 * storeMessage) rather than reading them back off $msg — ingest runs with no
	 * unlock window, so a sealed row's content columns would raise
	 * VaultLockedException on read (specs/implemented/inbound_email_encryption_
	 * at_rest.md § 4.2). Omitted (the backfill task, ApplyInboundEmailFilters,
	 * scanning already-stored mail) falls back to $msg->get() — correct for
	 * unsealed mail, and for sealed mail only reachable in-window.
	 */
	function matches(InboundEmailMessage $msg, array $parsed = array(), ?array $plaintext = null): bool {
		$sender_raw      = $plaintext['sender']      ?? (string)$msg->get('iem_sender');
		$subject_raw     = $plaintext['subject']     ?? (string)$msg->get('iem_subject');
		$body_plain_raw  = $plaintext['body_plain']  ?? (string)$msg->get('iem_body_plain');
		$body_html_raw   = $plaintext['body_html']   ?? (string)$msg->get('iem_body_html');

		$sender    = mb_strtolower($sender_raw);
		$recipient = mb_strtolower((string)$msg->get('iem_recipient')); // never sealed
		$subject   = mb_strtolower($subject_raw);
		// The "has the words" / "doesn't have" field set mirrors the reader's
		// full-text search: sender + subject + plain + HTML body.
		$haystack  = mb_strtolower($sender_raw . ' ' . $subject_raw . ' ' . $body_plain_raw . ' ' . $body_html_raw);

		// From — case-insensitive substring; comma-separated terms are OR'd.
		$from = trim((string)$this->get('fil_match_from'));
		if ($from !== '' && !$this->anyTermIn($from, $sender)) {
			return false;
		}

		// To — same as From.
		$to = trim((string)$this->get('fil_match_to'));
		if ($to !== '' && !$this->anyTermIn($to, $recipient)) {
			return false;
		}

		// Subject — plain case-insensitive substring.
		$subj = trim((string)$this->get('fil_match_subject'));
		if ($subj !== '' && mb_strpos($subject, mb_strtolower($subj)) === false) {
			return false;
		}

		// Has the words — every whitespace-separated token must be present.
		$words = trim((string)$this->get('fil_match_has_words'));
		if ($words !== '') {
			foreach ($this->tokens($words) as $tok) {
				if (mb_strpos($haystack, $tok) === false) {
					return false;
				}
			}
		}

		// Doesn't have — the message must contain NONE of the tokens.
		$excl = trim((string)$this->get('fil_match_excludes'));
		if ($excl !== '') {
			foreach ($this->tokens($excl) as $tok) {
				if (mb_strpos($haystack, $tok) !== false) {
					return false;
				}
			}
		}

		// Size — compare against iem_size_bytes.
		$op = (string)$this->get('fil_match_size_op');
		$bytes = intval($this->get('fil_match_size_bytes'));
		if (in_array($op, array(self::SIZE_OP_GT, self::SIZE_OP_LT), true) && $bytes > 0) {
			$size = intval($msg->get('iem_size_bytes'));
			if ($op === self::SIZE_OP_GT && !($size > $bytes)) { return false; }
			if ($op === self::SIZE_OP_LT && !($size < $bytes)) { return false; }
		}

		// Has attachment — true iff the message has >= 1 manifest row.
		if ((bool)$this->get('fil_match_has_attachment') && !$this->messageHasAttachment(intval($msg->key))) {
			return false;
		}

		return true;
	}

	/** True if any comma-separated term of $list is a substring of $hay (both lowercased). */
	private function anyTermIn(string $list, string $hay): bool {
		foreach (explode(',', $list) as $term) {
			$term = mb_strtolower(trim($term));
			if ($term !== '' && mb_strpos($hay, $term) !== false) {
				return true;
			}
		}
		return false;
	}

	/** Whitespace-tokenize a phrase into lowercased, non-empty tokens. */
	private function tokens(string $phrase): array {
		$out = array();
		foreach (preg_split('/\s+/u', trim($phrase)) as $t) {
			$t = mb_strtolower(trim($t));
			if ($t !== '') { $out[] = $t; }
		}
		return $out;
	}

	/** True if the custom label still exists and is not deleted. */
	private static function labelExists(int $labelId): bool {
		if ($labelId <= 0) { return false; }
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT 1 FROM ilb_inbound_email_labels WHERE ilb_inbound_email_label_id = ? AND ilb_delete_time IS NULL LIMIT 1');
		$stmt->execute(array($labelId));
		return (bool)$stmt->fetchColumn();
	}

	/** True if the message has at least one attachment manifest row. */
	private function messageHasAttachment(int $messageId): bool {
		if ($messageId <= 0) { return false; }
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT 1 FROM ima_inbound_message_attachments
			 WHERE ima_iem_inbound_email_message_id = ? LIMIT 1');
		$stmt->execute(array($messageId));
		return (bool)$stmt->fetchColumn();
	}

	// ------------------------------------------------------------ actions

	/**
	 * The normalized action set this filter would apply, in a shape that merges
	 * cleanly across multiple matching filters (booleans OR; label/forward are
	 * sets unioned).
	 */
	function buildActionSet(): array {
		$label = intval($this->get('fil_action_ilb_inbound_email_label_id'));
		$fwd   = trim((string)$this->get('fil_action_forward_to'));
		return array(
			'never_spam' => (bool)$this->get('fil_action_never_spam'),
			'mark_spam'  => (bool)$this->get('fil_action_mark_spam'),
			'label_ids'  => $label > 0 ? array($label) : array(),
			'star'       => (bool)$this->get('fil_action_star'),
			'mark_read'  => (bool)$this->get('fil_action_mark_read'),
			'archive'    => (bool)$this->get('fil_action_archive'),
			'forward_to' => $fwd !== '' ? array($fwd) : array(),
			'delete'     => (bool)$this->get('fil_action_delete'),
		);
	}

	/**
	 * Apply THIS filter's actions to a message. Used directly by the backfill
	 * task (which passes $allow_forward = false — Gmail does not re-forward
	 * historical mail). Returns the list of action descriptors applied.
	 */
	function applyActions(InboundEmailMessage $msg, array $parsed = array(), bool $allow_forward = true): array {
		return self::applyActionSet($msg, $this->buildActionSet(), $allow_forward);
	}

	/**
	 * Apply a (possibly merged) action set to a message, in the fixed order that
	 * makes multi-filter interactions well-defined regardless of filter order:
	 *
	 *   1. never_spam — clear the verdict to 'ham' (an explicit allow always wins).
	 *   2. mark_spam  — only if no never_spam fired.
	 *   3. label / star / mark_read / archive — independent flag/membership writes.
	 *   4. forward_to — relay a copy (best-effort; failures logged, never fatal).
	 *   5. delete (soft) — last, so a logged/forwarded copy still happened.
	 *
	 * Writes go straight to the row at system authority (no viewer scope), the
	 * same fields MailboxService mutates for a logged-in user.
	 */
	static function applyActionSet(InboundEmailMessage $msg, array $a, bool $allow_forward = true): array {
		$mid = intval($msg->key);
		if ($mid <= 0) { return array(); }
		$db = DbConnector::get_instance()->get_db_link();
		$done = array();

		// 1/2. Spam disposition — never_spam beats mark_spam.
		if (!empty($a['never_spam'])) {
			$db->prepare('UPDATE iem_inbound_email_messages SET iem_spam_verdict = ?
				WHERE iem_inbound_email_message_id = ?')
				->execute(array(InboundEmailMessage::SPAM_VERDICT_HAM, $mid));
			$done[] = 'never_spam';
		} elseif (!empty($a['mark_spam'])) {
			$db->prepare('UPDATE iem_inbound_email_messages SET iem_spam_verdict = ?
				WHERE iem_inbound_email_message_id = ?')
				->execute(array(InboundEmailMessage::SPAM_VERDICT_SPAM, $mid));
			$done[] = 'mark_spam';
		}

		// 3a. Labels — apply each custom label to the message (same write as the
		// reader's Labels control). InboundLabelMember::apply is idempotent. Guard for a
		// since-deleted label so a stale filter action is simply skipped.
		foreach (($a['label_ids'] ?? array()) as $lid) {
			$lid = intval($lid);
			if ($lid > 0 && self::labelExists($lid)) {
				InboundLabelMember::apply($mid, $lid);
				$done[] = 'label:' . $lid;
			}
		}

		// 3b. Independent flag writes, batched into one UPDATE.
		$sets = array();
		if (!empty($a['star']))      { $sets[] = 'iem_is_starred = true'; $done[] = 'star'; }
		if (!empty($a['mark_read'])) { $sets[] = 'iem_is_read = true'; $sets[] = 'iem_read_time = COALESCE(iem_read_time, now())'; $done[] = 'mark_read'; }
		if (!empty($a['archive']))   { $sets[] = 'iem_is_archived = true'; $done[] = 'archive'; }
		if (count($sets)) {
			$sets[] = 'iem_local_state_modified = now()';
			$db->prepare('UPDATE iem_inbound_email_messages SET ' . implode(', ', $sets) .
				' WHERE iem_inbound_email_message_id = ?')->execute(array($mid));
		}

		// 4. Forward a copy through the same relay alias-forwarding uses.
		if ($allow_forward && !empty($a['forward_to'])) {
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
				$router = new InboundEmailRouter();
				$router->forwardStoredMessage($msg, $a['forward_to']);
				$done[] = 'forward:' . implode(',', $a['forward_to']);
			} catch (\Throwable $e) {
				error_log('InboundEmailFilter: forward action failed for message ' . $mid . ': ' . $e->getMessage());
			}
		}

		// 5. Soft-delete last, so any log/forward already happened.
		if (!empty($a['delete'])) {
			$db->prepare('UPDATE iem_inbound_email_messages SET iem_delete_time = now()
				WHERE iem_inbound_email_message_id = ?')->execute(array($mid));
			$done[] = 'delete';
		}

		return $done;
	}

	// ------------------------------------------------------------ orchestrator

	/**
	 * Evaluate every in-scope, enabled filter against a freshly-stored message and
	 * apply the merged actions of all that match. Called once from
	 * InboundEmailRouter::storeMessage (the single local-only post-persist point),
	 * so Postfix and webhook deliveries are filtered identically.
	 *
	 * @param InboundEmailMessage    $msg       the just-persisted message (has its id)
	 * @param array                  $parsed    parsed email (ingest context; not relied on)
	 * @param InboundEmailAlias|null $alias     the matched alias, or null for catch-all store
	 * @param array|null             $plaintext sender/subject/body_plain/body_html the caller
	 *                                          already has decoded, for a sealed row — see matches().
	 * @return array{matched:int[],actions:string[]}
	 */
	static function runForMessage(InboundEmailMessage $msg, array $parsed = array(), ?InboundEmailAlias $alias = null, ?array $plaintext = null): array {
		$aliasId  = $msg->get('iem_iea_inbound_email_alias_id') !== null
			? intval($msg->get('iem_iea_inbound_email_alias_id')) : null;
		$domainId = intval($msg->get('iem_ied_inbound_email_domain_id'));

		$filters = self::inScopeFor($aliasId, $domainId);
		if (!count($filters)) {
			return array('matched' => array(), 'actions' => array());
		}

		// Accumulate the actions of every matching filter, then apply once.
		$accum = array('never_spam'=>false, 'mark_spam'=>false, 'label_ids'=>array(),
			'star'=>false, 'mark_read'=>false, 'archive'=>false, 'forward_to'=>array(), 'delete'=>false);
		$matched = array();
		foreach ($filters as $f) {
			if (!$f->matches($msg, $parsed, $plaintext)) {
				continue;
			}
			$matched[] = intval($f->key);
			$set = $f->buildActionSet();
			$accum['never_spam'] = $accum['never_spam'] || $set['never_spam'];
			$accum['mark_spam']  = $accum['mark_spam']  || $set['mark_spam'];
			$accum['star']       = $accum['star']       || $set['star'];
			$accum['mark_read']  = $accum['mark_read']  || $set['mark_read'];
			$accum['archive']    = $accum['archive']    || $set['archive'];
			$accum['delete']     = $accum['delete']     || $set['delete'];
			$accum['label_ids']  = array_values(array_unique(array_merge($accum['label_ids'], $set['label_ids'])));
			$accum['forward_to'] = array_values(array_unique(array_merge($accum['forward_to'], $set['forward_to'])));
		}

		if (!count($matched)) {
			return array('matched' => array(), 'actions' => array());
		}

		$actions = self::applyActionSet($msg, $accum, true);
		self::logMatch($msg, $matched, $actions, $plaintext['sender'] ?? null);
		return array('matched' => $matched, 'actions' => $actions);
	}

	/**
	 * Every enabled, non-deleted filter that applies to a message: the alias's own
	 * filters plus the domain-wide (NULL-alias) filters for its domain, ordered by
	 * fil_order then id so application is deterministic.
	 *
	 * @return InboundEmailFilter[]
	 */
	static function inScopeFor(?int $aliasId, int $domainId): array {
		$multi = new MultiInboundEmailFilter(
			array('scope_alias_id' => $aliasId, 'scope_domain_id' => $domainId,
				'enabled' => true, 'deleted' => false),
			array('fil_order' => 'ASC', 'fil_inbound_email_filter_id' => 'ASC')
		);
		$multi->load();
		$out = array();
		foreach ($multi as $f) {
			$out[] = new InboundEmailFilter($f->key, TRUE);
		}
		return $out;
	}

	/**
	 * Record a filter match on the existing inbound transaction log (the iel_ log
	 * surfaced under the Logs tab), so "why did this message get labeled/archived/
	 * deleted" is answerable the same way auth/spam disposition is.
	 */
	private static function logMatch(InboundEmailMessage $msg, array $matchedIds, array $actions, ?string $sender = null): void {
		try {
			// $sender is a routing address, not content, so it is fine to log
			// (the class docblock's "metadata only" rule targets subject/body).
			// It is passed in rather than read via $msg->get('iem_sender') because
			// this runs at ingest with no unlock window — a sealed row's own
			// column would raise VaultLockedException. Subject/body are never
			// logged at all (specs/implemented/inbound_email_encryption_at_rest.md § 7).
			InboundEmailLog::CreateEntry(
				(string)($sender ?? ''),
				(string)$msg->get('iem_recipient'), // never sealed
				'', // never log subject/body — see specs/implemented/inbound_email_encryption_at_rest.md § 7
				'filters: #' . implode(', #', $matchedIds) . ' -> ' . (count($actions) ? implode(', ', $actions) : 'no-op'),
				InboundEmailLog::STATUS_FILTERED,
				$msg->get('iem_iea_inbound_email_alias_id') !== null ? intval($msg->get('iem_iea_inbound_email_alias_id')) : null,
				null,
				intval($msg->get('iem_ied_inbound_email_domain_id'))
			);
		} catch (\Throwable $e) {
			error_log('InboundEmailFilter: match logging failed for message ' . $msg->key . ': ' . $e->getMessage());
		}
	}

	// ------------------------------------------------------------ Gmail import

	/**
	 * Parse a Gmail `mailFilters.xml` export into candidate structs — pure data,
	 * NO DB writes, so it is unit-testable and safe to call for both the preview and
	 * the confirm step. Each Gmail `<entry>` becomes one candidate:
	 *
	 *   [
	 *     'name'       => 'From: dealnews',          // synthesized — Gmail filters are unnamed
	 *     'fields'     => [fil_match_from => 'dealnews', fil_action_archive => true, ...],
	 *     'label'      => 'deals',                   // Gmail label name, or null (resolved on confirm)
	 *     'skipped'    => ['categorize: Updates'],   // human-readable unmapped properties
	 *     'importable' => true,                      // >=1 criterion AND >=1 action (a label counts)
	 *   ]
	 *
	 * The label is carried by NAME, not resolved here: find-or-create is a DB write,
	 * so the parser leaves it to the confirm step. The "size trap" lives here too —
	 * Gmail emits a default `sizeOperator`/`sizeUnit` on every entry, so a size
	 * criterion is mapped ONLY when a `size` property carries a numeric value.
	 *
	 * @return array<int,array> one candidate per <entry>, in document order
	 * @throws InboundEmailFilterException on empty input, non-XML, or a wrong root
	 */
	static function parseGmailExport(string $xml): array {
		$xml = trim($xml);
		if ($xml === '') {
			throw new InboundEmailFilterException('The uploaded file is empty.');
		}
		// Untrusted input: LIBXML_NONET blocks network fetches; without LIBXML_NOENT
		// (never passed) modern libxml does not resolve external entities (XXE-safe).
		$prev = libxml_use_internal_errors(true);
		$feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if ($feed === false || strtolower($feed->getName()) !== 'feed') {
			throw new InboundEmailFilterException('This does not look like a Gmail mailFilters.xml export.');
		}

		$appsNs = 'http://schemas.google.com/apps/2006';
		$out = array();
		foreach ($feed->entry as $entry) {
			// Properties as ordered name/value pairs (label can legitimately repeat).
			$props = array();
			$children = $entry->children($appsNs);
			if (isset($children->property)) {
				foreach ($children->property as $p) {
					$attrs = $p->attributes(); // name/value are in no namespace
					$props[] = array((string)$attrs['name'], (string)$attrs['value']);
				}
			}
			$out[] = self::mapGmailEntry($props);
		}
		return $out;
	}

	/** Map one Gmail entry's property pairs into a candidate struct (pure). */
	private static function mapGmailEntry(array $props): array {
		$fields = array();
		$label = null;
		$skipped = array();
		$size = null; $sizeOp = null; $sizeUnit = null;

		foreach ($props as $pair) {
			list($name, $value) = $pair;
			switch ($name) {
				// ---- criteria
				case 'from':    if (trim($value) !== '') { $fields['fil_match_from'] = trim($value); } break;
				case 'to':      if (trim($value) !== '') { $fields['fil_match_to'] = trim($value); } break;
				case 'subject': if (trim($value) !== '') { $fields['fil_match_subject'] = trim($value); } break;
				case 'hasTheWord':         if (trim($value) !== '') { $fields['fil_match_has_words'] = trim($value); } break;
				case 'doesNotHaveTheWord': if (trim($value) !== '') { $fields['fil_match_excludes'] = trim($value); } break;
				case 'hasAttachment':      if (self::gmailTrue($value)) { $fields['fil_match_has_attachment'] = true; } break;
				case 'size':         $size = $value; break;
				case 'sizeOperator': $sizeOp = $value; break;
				case 'sizeUnit':     $sizeUnit = $value; break;

				// ---- actions
				case 'shouldArchive':    if (self::gmailTrue($value)) { $fields['fil_action_archive'] = true; } break;
				case 'shouldMarkAsRead': if (self::gmailTrue($value)) { $fields['fil_action_mark_read'] = true; } break;
				case 'shouldStar':       if (self::gmailTrue($value)) { $fields['fil_action_star'] = true; } break;
				case 'shouldTrash':      if (self::gmailTrue($value)) { $fields['fil_action_delete'] = true; } break;
				case 'shouldNeverSpam':  if (self::gmailTrue($value)) { $fields['fil_action_never_spam'] = true; } break;
				case 'forwardTo':        if (trim($value) !== '') { $fields['fil_action_forward_to'] = trim($value); } break;
				case 'label':
					if (trim($value) === '') { break; }
					// First label wins; Gmail rarely emits more than one per entry.
					if ($label === null) { $label = trim($value); }
					else { $skipped[] = 'extra label: ' . trim($value); }
					break;

				// ---- known but unmappable -> dropped VISIBLY (a decision, not a gap)
				case 'shouldAlwaysMarkAsImportant': if (self::gmailTrue($value)) { $skipped[] = 'mark important'; } break;
				case 'shouldNeverMarkAsImportant':  if (self::gmailTrue($value)) { $skipped[] = 'never important'; } break;
				case 'smartLabelToApply':           if (trim($value) !== '') { $skipped[] = 'categorize: ' . trim($value); } break;
				case 'excludeChats':                if (self::gmailTrue($value)) { $skipped[] = 'chats excluded'; } break;

				default:
					// Anything unrecognized is surfaced, never silently lost.
					if (trim($name) !== '') {
						$skipped[] = trim($name) . (trim($value) !== '' ? ': ' . trim($value) : '');
					}
					break;
			}
		}

		// Size is a criterion ONLY when `size` carries a numeric value (the size trap):
		// a lone sizeOperator/sizeUnit (emitted by default on every entry) is ignored.
		if ($size !== null && is_numeric($size) && (int)$size > 0) {
			$fields['fil_match_size_op'] = ($sizeOp === 's_sg') ? self::SIZE_OP_GT : self::SIZE_OP_LT;
			$mult = self::GMAIL_SIZE_UNIT_BYTES[$sizeUnit] ?? 1;
			$fields['fil_match_size_bytes'] = (int)$size * $mult;
		}

		return array(
			'name'       => self::synthesizeFilterName($fields, $label),
			'fields'     => $fields,
			'label'      => $label,
			'skipped'    => $skipped,
			'importable' => self::candidateHasCriterion($fields) && self::candidateHasAction($fields, $label),
		);
	}

	/** Gmail booleans are the literal string "true". */
	private static function gmailTrue($value): bool {
		return strtolower(trim((string)$value)) === 'true';
	}

	/** Synthesize a readable name from the first criterion (Gmail filters are unnamed). */
	private static function synthesizeFilterName(array $fields, ?string $label): string {
		if (!empty($fields['fil_match_from']))      { return 'From: ' . $fields['fil_match_from']; }
		if (!empty($fields['fil_match_to']))        { return 'To: ' . $fields['fil_match_to']; }
		if (!empty($fields['fil_match_subject']))   { return 'Subject: ' . $fields['fil_match_subject']; }
		if (!empty($fields['fil_match_has_words'])) { return 'Has: ' . $fields['fil_match_has_words']; }
		if (!empty($fields['fil_match_excludes']))  { return 'Excludes: ' . $fields['fil_match_excludes']; }
		if (!empty($fields['fil_match_has_attachment'])) { return 'Has attachment'; }
		if (!empty($fields['fil_match_size_op']))   { return 'By size'; }
		if ($label !== null)                        { return 'Label: ' . $label; }
		return '(unnamed)';
	}

	/** True when a candidate has at least one matching criterion. */
	private static function candidateHasCriterion(array $fields): bool {
		foreach (array('fil_match_from', 'fil_match_to', 'fil_match_subject',
				'fil_match_has_words', 'fil_match_excludes') as $f) {
			if (!empty($fields[$f])) { return true; }
		}
		return !empty($fields['fil_match_has_attachment']) || !empty($fields['fil_match_size_op']);
	}

	/** True when a candidate has at least one action (a label counts as an action). */
	private static function candidateHasAction(array $fields, ?string $label): bool {
		if ($label !== null) { return true; }
		foreach (array('fil_action_archive', 'fil_action_mark_read', 'fil_action_star',
				'fil_action_delete', 'fil_action_never_spam') as $f) {
			if (!empty($fields[$f])) { return true; }
		}
		return !empty($fields['fil_action_forward_to']);
	}
}

class MultiInboundEmailFilter extends SystemMultiBase {
	protected static $model_class = 'InboundEmailFilter';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['alias_id'])) {
			$filters['fil_iea_inbound_email_alias_id'] = array($this->options['alias_id'], PDO::PARAM_INT);
		}

		// Domain-wide rows (NULL alias) only.
		if (!empty($this->options['domain_wide'])) {
			$filters['fil_iea_inbound_email_alias_id'] = 'IS NULL';
		}

		if (isset($this->options['domain_id'])) {
			$filters['fil_ied_inbound_email_domain_id'] = array($this->options['domain_id'], PDO::PARAM_INT);
		}

		// In-scope-for-a-message: the alias's own filters OR the domain-wide ones
		// for its domain. Uses the split-parenthesis OR convention.
		if (array_key_exists('scope_domain_id', $this->options)) {
			$domainId = intval($this->options['scope_domain_id']);
			$aliasId = $this->options['scope_alias_id'] ?? null;
			$domainWide = 'fil_iea_inbound_email_alias_id IS NULL AND fil_ied_inbound_email_domain_id = ' . $domainId;
			if ($aliasId !== null) {
				$filters['(fil_iea_inbound_email_alias_id'] = '= ' . intval($aliasId)
					. ' OR (' . $domainWide . '))';
			} else {
				$filters['(fil_iea_inbound_email_alias_id'] = 'IS NULL AND fil_ied_inbound_email_domain_id = '
					. $domainId . ')';
			}
		}

		if (isset($this->options['enabled'])) {
			$filters['fil_is_enabled'] = $this->options['enabled'] ? '= true' : '= false';
		}

		if (!empty($this->options['pending_backfill'])) {
			$filters['fil_apply_existing_pending'] = '= true';
		}

		if (isset($this->options['deleted'])) {
			$filters['fil_delete_time'] = $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL';
		}

		return $this->_get_resultsv2('fil_inbound_email_filters', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
