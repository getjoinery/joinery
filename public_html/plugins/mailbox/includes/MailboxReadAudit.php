<?php
/**
 * MailboxReadAudit — the record of an all-access viewer reading mail they hold
 * no grant on (specs/mail_access_hardening.md WP1).
 *
 * A read is FOREIGN when the viewer reaches a message through all-access
 * (MailboxViewer::isAllAccess(), level 10) rather than a grant: they do not
 * hold the message (MailboxViewer::holdsMessageRow(): no grant on its mailbox,
 * not its sealed owner, not the draft's author), or the message belongs to no
 * mailbox at all (unmatched mail). A grant holder reading their own mailbox is
 * never foreign and never written down; a member cannot reach a foreign
 * message at all, because every read is scoped by the viewer first.
 *
 * Rows land in the platform's general event log (evl_event_logs), beside the
 * vault's own audit rows (VaultAudit), as one event: `mail_foreign_read`.
 * ONE ROW PER MAILBOX: an all-access viewer's "All mail" list spans every
 * mailbox on the site, so a page or an AI query that touched several mailboxes
 * writes one row for each alias it touched, carrying that alias's message ids.
 * The holder of a mailbox then finds every read of it by the note's first word,
 * and no row ever names two mailboxes.
 *
 * WHAT IS RECORDED IS METADATA ONLY: who looked, which mailbox, through which
 * surface, how many messages and which ids. Never a subject, an address or a
 * body. The note is `key=value` words separated by single spaces, the vault
 * audit's shape, so parseNote() reads it back and a plane reader can later
 * (agent_event_log_filter.md):
 *
 *   alias={id} domain={id} surface={word} count={n} ids={comma list}
 *
 * `alias=0` is unmatched mail; `domain=0` is a message with no domain; `ids=`
 * (empty) is a whole-mailbox surface such as an export start.
 *
 * The write is the server's own observation on a GET-shaped action, so it runs
 * under SystemBase::server_initiated_write(), and it is declared content-free
 * (SealedEgressGuard::contentFree('mail_read_audit')): a page that showed the
 * viewer sealed mail has made the process hot, and a 200-id list is longer than
 * the guard's threshold, so an undeclared write would throw and take the page
 * down. A failed write is logged and never breaks the read that noticed.
 *
 * @version 1.1 - messages() takes $reaches_everything for a surface the viewer does not scope (the AI query path), since a
 *                viewer built without a session is never all-access (WP3)
 * @version 1.0
 */

class MailboxReadAudit {

	const EVENT = 'mail_foreign_read';

	/** The egress guard's declared content-free write site for these rows. */
	const SITE = 'mail_read_audit';

	// Where the read happened. A closed set: parseNote() reads anything else as 'other'.
	const SURFACE_THREAD          = 'thread';           // MailboxService::getThread()
	const SURFACE_LIST            = 'list';             // MailboxService::listThreads(), the previewed messages
	const SURFACE_SOURCE          = 'source';           // the original's headers and source (message_source)
	const SURFACE_ORIGINAL        = 'original';         // the .eml download or print view (profile_original)
	const SURFACE_ATTACHMENT      = 'attachment';       // an attachment's bytes
	const SURFACE_ATTACHMENT_TEXT = 'attachment_text';  // an attachment's extracted text preview
	const SURFACE_EXPORT          = 'export';           // a mailbox download started
	const SURFACE_FORENSIC        = 'forensic';         // the admin message page
	const SURFACE_AI_QUERY        = 'ai_query';         // query_model over InboundEmailMessage
	const SURFACE_SENDER_CONTEXT  = 'sender_context';   // the sender panel beside a thread

	const SURFACES = array(
		self::SURFACE_THREAD, self::SURFACE_LIST, self::SURFACE_SOURCE, self::SURFACE_ORIGINAL,
		self::SURFACE_ATTACHMENT, self::SURFACE_ATTACHMENT_TEXT, self::SURFACE_EXPORT,
		self::SURFACE_FORENSIC, self::SURFACE_AI_QUERY, self::SURFACE_SENDER_CONTEXT,
	);

	/** How far back the holder's view looks. */
	const HOLDER_VIEW_DAYS = 90;

	/**
	 * The viewer has just read these messages' content on $surface. The ones
	 * they hold are dropped; the rest are written down, one row per mailbox.
	 * Called after the scope decision and before the content leaves, by the
	 * code that authorized the read, never by a helper machines also call.
	 */
	public static function messages(MailboxViewer $viewer, string $surface, array $message_ids, bool $reaches_everything = false): void {
		// A grant holder or a member can reach nothing foreign through a surface
		// the viewer scopes. $reaches_everything says the surface was not scoped
		// by this viewer (the AI query path reads by the person's level), so
		// every unheld message is foreign whatever the viewer's oversight says.
		if (!$viewer->isAllAccess() && !$reaches_everything) {
			return;
		}
		$ids = array();
		foreach ($message_ids as $id) {
			$id = intval($id);
			if ($id > 0) {
				$ids[$id] = true;
			}
		}
		if (!$ids) {
			return;
		}
		$viewer_id = $viewer->getUserId();
		try {
			$in = implode(',', array_keys($ids));
			$rows = DbConnector::get_instance()->get_db_link()->query(
				"SELECT iem_inbound_email_message_id, iem_iea_inbound_email_alias_id, iem_ied_inbound_email_domain_id,
						iem_direction, iem_draft_author_user_id, iem_sealed_owner_user_id
				 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			error_log(self::EVENT . ': could not look up the messages read by user ' . $viewer_id . ' — ' . $e->getMessage());
			return;
		}
		$by_alias = array();   // alias id => array('domain' => ?int, 'ids' => int[])
		foreach ($rows as $r) {
			$alias_id = intval($r['iem_iea_inbound_email_alias_id']);
			if (MailboxViewer::holdsMessageRow($viewer_id, $alias_id, (string)$r['iem_direction'],
					intval($r['iem_draft_author_user_id']), intval($r['iem_sealed_owner_user_id']))) {
				continue;
			}
			if (!isset($by_alias[$alias_id])) {
				$by_alias[$alias_id] = array('domain' => null, 'ids' => array());
			}
			if ($by_alias[$alias_id]['domain'] === null && $r['iem_ied_inbound_email_domain_id'] !== null) {
				$by_alias[$alias_id]['domain'] = intval($r['iem_ied_inbound_email_domain_id']);
			}
			$by_alias[$alias_id]['ids'][] = intval($r['iem_inbound_email_message_id']);
		}
		foreach ($by_alias as $alias_id => $group) {
			self::foreign($viewer_id, $alias_id, $group['domain'], $surface, $group['ids']);
		}
	}

	/**
	 * The viewer has just reached a whole mailbox on $surface without naming
	 * messages (a download started). Written down unless they hold it.
	 */
	public static function mailbox(MailboxViewer $viewer, string $surface, int $alias_id, int $count = 0): void {
		if (!$viewer->isAllAccess() || $alias_id <= 0) {
			return;
		}
		$viewer_id = $viewer->getUserId();
		if (MailboxViewer::holdsMailbox($viewer_id, $alias_id)) {
			return;
		}
		$domain_id = null;
		try {
			$q = DbConnector::get_instance()->get_db_link()->prepare(
				'SELECT iea_ied_inbound_email_domain_id FROM iea_inbound_email_aliases WHERE iea_inbound_email_alias_id = ?');
			$q->execute(array($alias_id));
			$d = $q->fetchColumn();
			$domain_id = $d === false || $d === null ? null : intval($d);
		} catch (\Throwable $e) {
			error_log(self::EVENT . ': could not look up mailbox ' . $alias_id . ' — ' . $e->getMessage());
		}
		self::foreign($viewer_id, $alias_id, $domain_id, $surface, array(), $count);
	}

	/**
	 * The row itself: $viewer_id read $message_ids of mailbox $alias_id (0 for
	 * unmatched mail) on $surface. $count defaults to the number of ids; a
	 * whole-mailbox surface passes its own count with no ids.
	 */
	public static function foreign(int $viewer_id, int $alias_id, ?int $domain_id, string $surface,
			array $message_ids, ?int $count = null): void {
		if (!in_array($surface, self::SURFACES, true)) {
			throw new InvalidArgumentException("MailboxReadAudit: '$surface' is not a read surface.");
		}
		$ids = array_values(array_unique(array_filter(array_map('intval', $message_ids), function ($id) { return $id > 0; })));
		sort($ids);
		$note = 'alias=' . max(0, $alias_id)
			. ' domain=' . max(0, intval($domain_id))
			. ' surface=' . $surface
			. ' count=' . max(0, $count === null ? count($ids) : $count)
			. ' ids=' . implode(',', $ids);
		self::write($viewer_id, $note);
	}

	/**
	 * The fields of a note this class wrote, for a reader that has only the
	 * row (the holder's view; a plane reader later). Every number is read as
	 * digits, the surface against the closed set, the id list as integers;
	 * anything else reads as 'other', and a field the note lacks is absent.
	 *
	 * @return array<string, int|string|int[]> alias, domain, count (int), surface (word), ids (int[])
	 */
	public static function parseNote(string $note): array {
		$fields = array();
		foreach (preg_split('/\s+/', trim($note)) as $pair) {
			$eq = strpos($pair, '=');
			if ($eq === false || $eq === 0) {
				continue;
			}
			$name = substr($pair, 0, $eq);
			$value = substr($pair, $eq + 1);
			if ($name === 'alias' || $name === 'domain' || $name === 'count') {
				$fields[$name] = preg_match('/^[0-9]{1,12}$/', $value) ? (int)$value : 'other';
			} elseif ($name === 'surface') {
				$fields[$name] = in_array($value, self::SURFACES, true) ? $value : 'other';
			} elseif ($name === 'ids') {
				if ($value === '') {
					$fields[$name] = array();
				} elseif (preg_match('/^[0-9]{1,12}(,[0-9]{1,12})*$/', $value)) {
					$fields[$name] = array_map('intval', explode(',', $value));
				} else {
					$fields[$name] = 'other';
				}
			}
		}
		return $fields;
	}

	/**
	 * Every foreign read of mailbox $alias_id in the last $days days, newest
	 * first: who, when (UTC, as stored), which surface, how many messages. For
	 * the holder's "Who has looked at this mailbox" section. Found by the
	 * note's fixed first word, which is why the note is written in a fixed
	 * shape, over the (evl_event, evl_create_time) index.
	 *
	 * @return array<int, array{viewer_id:int, time:string, surface:string, count:int}>
	 */
	public static function forMailbox(int $alias_id, int $days = self::HOLDER_VIEW_DAYS): array {
		if ($alias_id <= 0) {
			return array();
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT evl_usr_user_id, evl_create_time, evl_note FROM evl_event_logs
			 WHERE evl_event = ? AND evl_create_time > now() - make_interval(days => ?) AND evl_note LIKE ?
			 ORDER BY evl_create_time DESC, evl_event_log_id DESC");
		$q->execute(array(self::EVENT, max(1, $days), 'alias=' . $alias_id . ' %'));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$f = self::parseNote((string)$r['evl_note']);
			if (($f['alias'] ?? null) !== $alias_id) {
				continue;   // the parsed note is the authority, whatever the LIKE matched
			}
			$out[] = array(
				'viewer_id' => intval($r['evl_usr_user_id']),
				'time'      => (string)$r['evl_create_time'],
				'surface'   => is_string($f['surface'] ?? null) ? $f['surface'] : 'other',
				'count'     => is_int($f['count'] ?? null) ? $f['count'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Wrapped in server_initiated_write(): a read is a GET-shaped action and
	 * this row is the server's own observation of it. Declared content-free:
	 * the note is metadata only (see the class comment), and the request that
	 * read sealed mail is hot. Losing the row must never break the read.
	 */
	private static function write(int $viewer_id, string $note): void {
		try {
			SealedEgressGuard::contentFree(self::SITE, function () use ($viewer_id, $note) {
				SystemBase::server_initiated_write(function () use ($viewer_id, $note) {
					$log = new EventLog(NULL);
					$log->set('evl_event',       self::EVENT);
					$log->set('evl_usr_user_id', $viewer_id);
					$log->set('evl_was_success', true);
					$log->set('evl_note',        $note);
					$log->save();
				});
			});
		} catch (\Throwable $e) {
			error_log(self::EVENT . ': could not write the read audit row for user '
				. $viewer_id . ' — ' . $e->getMessage());
		}
	}
}
?>
