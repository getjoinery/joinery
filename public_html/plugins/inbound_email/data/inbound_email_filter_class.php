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
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_log_class.php'));

class InboundEmailFilterException extends SystemBaseException {}

class InboundEmailFilter extends SystemBase {
	public static $prefix = 'fil';
	public static $tablename = 'fil_inbound_email_filters';
	public static $pkey_column = 'fil_inbound_email_filter_id';

	const SIZE_OP_GT = 'gt';
	const SIZE_OP_LT = 'lt';

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
	 * All non-empty criteria must match (AND across fields — Gmail's model). The
	 * evaluation reads the persisted message columns so ingest and backfill agree
	 * exactly; $parsed is accepted for signature symmetry but is not relied upon.
	 */
	function matches(InboundEmailMessage $msg, array $parsed = array()): bool {
		$sender    = mb_strtolower((string)$msg->get('iem_sender'));
		$recipient = mb_strtolower((string)$msg->get('iem_recipient'));
		$subject   = mb_strtolower((string)$msg->get('iem_subject'));
		// The "has the words" / "doesn't have" field set mirrors the reader's
		// full-text search: sender + subject + plain + HTML body.
		$haystack  = mb_strtolower(
			(string)$msg->get('iem_sender') . ' ' .
			(string)$msg->get('iem_subject') . ' ' .
			(string)$msg->get('iem_body_plain') . ' ' .
			(string)$msg->get('iem_body_html'));

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
				require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));
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
	 * @param InboundEmailMessage    $msg    the just-persisted message (has its id)
	 * @param array                  $parsed parsed email (ingest context; not relied on)
	 * @param InboundEmailAlias|null $alias  the matched alias, or null for catch-all store
	 * @return array{matched:int[],actions:string[]}
	 */
	static function runForMessage(InboundEmailMessage $msg, array $parsed = array(), ?InboundEmailAlias $alias = null): array {
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
			if (!$f->matches($msg, $parsed)) {
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
		self::logMatch($msg, $matched, $actions);
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
	private static function logMatch(InboundEmailMessage $msg, array $matchedIds, array $actions): void {
		try {
			InboundEmailLog::CreateEntry(
				(string)$msg->get('iem_sender'),
				(string)$msg->get('iem_recipient'),
				(string)$msg->get('iem_subject'),
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
