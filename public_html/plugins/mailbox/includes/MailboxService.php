<?php
/**
 * MailboxService - scope-checked reads and state mutations for the Mailbox
 * Reader.
 *
 * Constructed with a MailboxViewer; EVERY method funnels its visibility through
 * the viewer's scope so a viewer can never read or mutate a message outside its
 * grants. List/thread reads are grouped aggregations that don't fit a model, so
 * they use DbConnector directly with safe (intval'd id lists, bound text
 * params) SQL. Mutations are a single scoped bulk UPDATE — out-of-scope ids are
 * filtered in SQL and silently affect nothing.
 *
 * Threading: rows group by iem_thread_key. A row with no thread key is a
 * singleton, keyed by 'm:<message_id>' so the client always has a stable thread
 * handle. getThread()/messageIdsInThread() understand both forms.
 *
 * Unmatched mail (NULL alias) belongs to no mailbox; it is invisible to
 * grant-scoped viewers and surfaces only in a superadmin's unconstrained
 * "All mail" view.
 *
 * getThread() also returns the per-message SPF/DKIM/DMARC verdicts and their
 * source (iem_auth_source) so the reader can show sourced verdicts or an honest
 * "unverified" — it never recomputes authentication.
 *
 * Spam (specs/inbound_email_spam_filtering.md): the default list/switcher hide
 * judged-spam rows (iem_spam_verdict='spam'); the Spam view shows only them.
 * setSpamVerdict() is the manual "Mark as spam"/"Not spam" correction — which, with
 * content filtering on, also drives the LearnSpamFeedback reconcile
 * (specs/inbound_email_content_spam_filtering.md). getThread() returns the recorded
 * content-spam score (iem_spam_score) for display only.
 *
 * Archive (specs/implemented/inbound_email_filters.md): the default Inbox view hides
 * archived rows (iem_is_archived); All Mail shows them. setArchived() is the manual
 * Archive / Move-to-Inbox action, symmetric with a filter's archive action.
 *
 * Trash (specs/mailbox_trash_folder.md): softDelete() stamps iem_delete_time and
 * every read scope pins that column NULL, so a trashed message leaves every view.
 * The Trash view inverts the pin (trashScopeSql) and is the ONLY read that sees
 * those rows; restoreFromTrash() and purgeFromTrash() are the only mutations that
 * reach them (trashMutationScopeSql). The retention sweep purges on a window.
 *
 * Native clients (specs/implemented/mobile_native_email_server_api_and_ios.md): withSignedTransport() enriches a
 * getThread() payload with short-lived signed URLs (docs/file_signed_urls.md) so
 * sessionless API clients can fetch attachments and render inline cid: images.
 * Inline cid: rewriting lives in resolveInlineImages() — the one shared
 * implementation, used by every reader (web and native alike). It always mints
 * signed URLs: the sandbox="" reader iframe has an opaque origin that sends no
 * cookies, and mailbox-grant visibility is not expressible in
 * File::is_viewable() (owner-or-admin), so a session-gated /uploads URL can
 * never authorize this content.
 *
 * @version 1.33
 * @changelog 1.33 - Sent and Drafts are strictly reverse-chronological: the
 *   unread/starred sectioning is an Inbox affordance and meant nothing on mail
 *   the member sent or wrote, but it sorted every never-opened outbound row
 *   above today's send. The per-mailbox unread badge stops counting outbound
 *   rows, which the Inbox it lands on has never shown
 *   (specs/bugfix_sent_view_ordering.md).
 * @version 1.32
 * @changelog 1.32 - Sent view (filters[sent]): a pseudo-folder like Spam,
 *   listing conversations that carry an outbound row. Row-level filter,
 *   thread-level effect; Trash still wins; a Sent search stays bounded to
 *   sent mail (an explicit scope, not the default tab).
 * @version 1.31
 * @changelog 1.31 - a search is never narrowed by the Inbox tab: the query
 *   covers All Mail (archived and sent included) and the response carries
 *   search_scope so the reader labels it. Explicit scopes (Trash, Spam,
 *   Drafts, labels) still bound their own searches.
 * @version 1.30
 * @changelog 1.30 - list snippets clean the plain part through
 *   MailboxHtmlSanitizer::previewText(): received plain parts carry literal
 *   entities and invisible preheader padding (&zwnj;, &#847;) that rendered
 *   as garbage in the preview line; a padding-only plain part now falls
 *   through to the HTML preview.
 * @version 1.29
 * @changelog 1.29 - a sealed-mailbox search folds the index for at most
 *   SEARCH_FOLD_BUDGET_SECONDS and searches what is indexed; an incomplete
 *   index surfaces as search_indexing {remaining, total} in the response
 *   (specs/mailbox_search_incremental_fold.md), and the deferred-work drain
 *   finishes the backlog. A 100k-message import used to make every search
 *   attempt refold the whole backlog inside one web request and die on
 *   max_execution_time, with the high-water mark never advancing.
 * @version 1.28
 * @changelog 1.28 - thread payload says WHERE a message's original can come
 *   from (original_source: stored | imap | headers | none,
 *   specs/mailbox_show_original_coverage.md) instead of a stored-only boolean,
 *   so the reader can offer Show original for reference-backed rows (live
 *   IMAP fetch) and header-retaining lean records (labeled reconstruction).
 * @version 1.27
 * @changelog 1.27 - sealed attachment and inline-image URLs carry a serve
 *   grant (includes/FileServeGrant.php, specs/bugfix_sealed_inline_images.md):
 *   the key is resolved through the caller's open window at mint time and
 *   stashed server-side, so the cookie-less sandbox iframe and sessionless
 *   native clients can decrypt what the signature already lets them fetch.
 *   A closed window mints no grant and the URL serves 423 as before.
 * @version 1.26
 * @changelog 1.26 - a damaged column no longer raises content_locked
 *   (specs/bugfix_promoted_sent_row_sealing.md): that flag is the reader's
 *   "unlock your vault" prompt, and no unlock fixes a column that will not
 *   open — it still renders the placeholder and logs, but only a genuinely
 *   locked window or a pending-parse row raises the banner
 * @version 1.25
 * @changelog 1.25 - FULLTEXT_SQL left()-caps each column: an uncapped expression
 *   made any message past the 1 MiB tsvector limit unstorable at INSERT
 *   (migration iem_014 rebuilds the index to match)
 * @changelog 1.24 - each mailbox in the switcher reports its OWN protection level
 * @changelog 1.23 - The Inbox view excludes outbound rows: a sent-only thread lives in All Mail until a reply arrives
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

class MailboxService {

	/** Prefix marking a synthetic singleton thread key (no real thread header). */
	const SINGLETON_PREFIX = 'm:';

	/**
	 * Sentinel alias-scope meaning "unmatched (NULL-alias) mail only" — the
	 * superadmin-only pseudo-mailbox for mail that routed to no alias. A real
	 * alias id is always a positive serial, so -1 can never collide.
	 */
	const UNMATCHED = -1;

	/**
	 * Unmatched mail is offered PER DOMAIN, because that is the unit it actually
	 * belongs to: a catch-all message seals to the domain's owner, so one lumped box
	 * can hold mail sealed to several different people and can show no honest
	 * protection level. "Unmatched for domain D" encodes as UNMATCHED_DOMAIN_BASE - D.
	 *
	 * The encoding rides in the same int the alias scope already travels as, rather
	 * than a second parameter, because that scope is decoded in exactly two places
	 * (readScopeSql / trashScopeSql) and passed through everywhere else — widening the
	 * signature would touch every caller to serve two of them. Real alias ids are
	 * positive serials and the base is well below UNMATCHED, so nothing can collide.
	 */
	const UNMATCHED_DOMAIN_BASE = -1000;

	/**
	 * Parse an alias_id request parameter into a scope value for the read/mutation
	 * methods: '' / absent → null (all accessible); 'unmatched' → UNMATCHED;
	 * 'unmatched:{domain_id}' → that domain's unmatched box; else the integer alias
	 * id. One parser shared by every reader endpoint.
	 *
	 * @param mixed $raw
	 * @return int|null
	 */
	public static function parseAliasParam($raw): ?int {
		if ($raw === null || $raw === '') {
			return null;
		}
		if ($raw === 'unmatched') {
			return self::UNMATCHED;
		}
		if (is_string($raw) && strpos($raw, 'unmatched:') === 0) {
			$domain_id = intval(substr($raw, strlen('unmatched:')));
			// A malformed domain id degrades to the aggregate unmatched scope rather
			// than to alias 0, which would read as a real (non-existent) mailbox.
			return $domain_id > 0 ? (self::UNMATCHED_DOMAIN_BASE - $domain_id) : self::UNMATCHED;
		}
		return intval($raw);
	}

	/**
	 * The domain id inside a domain-scoped unmatched sentinel, or null when $aliasId
	 * is anything else (a real mailbox, aggregate unmatched, or all-mail).
	 */
	private static function unmatchedDomainId(?int $aliasId): ?int {
		if ($aliasId === null || $aliasId > self::UNMATCHED_DOMAIN_BASE) {
			return null;
		}
		$domain_id = self::UNMATCHED_DOMAIN_BASE - $aliasId;
		return $domain_id > 0 ? $domain_id : null;
	}

	/** SQL expression for a row's grouping key (real thread key, or m:<id>). */
	const GROUP_KEY_SQL = "COALESCE(NULLIF(iem_thread_key,''), 'm:' || iem_inbound_email_message_id)";

	/**
	 * The searchable-text expression for unsealed mail. Declared once as a
	 * constant because the GIN index that serves it (iem_013) must be built over
	 * a BYTE-IDENTICAL expression — two copies of this string in two files is how
	 * an index silently stops being used. The migration references this constant.
	 *
	 * Matching iem_body_html directly is deliberate and costs nothing in noise:
	 * PostgreSQL's text-search parser classifies markup as `tag` and skips it, so
	 * a stylesheet inside the document contributes no lexemes and an <a href>
	 * indexes only its link text. (A preview built with strip_tags gets no such
	 * help, which is why MailboxHtmlSanitizer::toReadableText exists for the
	 * reader — the two problems look alike and are not.)
	 *
	 * Each column is capped with left() because a tsvector has a hard 1 MiB
	 * limit and the expression is evaluated on INSERT (the GIN index), so one
	 * multi-megabyte body would otherwise make the message UNSTORABLE — the
	 * insert itself fails with "string is too long for tsvector". The caps sum
	 * to ~½ MiB of input, comfortably inside the limit even for pathological
	 * word shapes; search inside the first 250 KB of each body is the whole of
	 * what a person searches for, and the messages this size are almost always
	 * markup or encoded blobs anyway.
	 */
	const FULLTEXT_SQL = "to_tsvector('english',
					left(coalesce(iem_sender, ''), 1000)        || ' ' ||
					left(coalesce(iem_subject, ''), 4000)       || ' ' ||
					left(coalesce(iem_body_plain, ''), 250000)  || ' ' ||
					left(coalesce(iem_body_html, ''), 250000))";

	/**
	 * Neutral product placeholder shown wherever sealed content cannot be read
	 * — a locked vault, or a Fortress pending-parse row still sealed to the
	 * owner (specs/mailbox_security_levels.md § The Locked-State Surface
	 * Contract). One string, no bracket syntax; the reader keys off the result's
	 * `locked` flag to offer a one-tap unlock, never a third visible state.
	 */
	const SEALED_PLACEHOLDER = 'Sealed message';

	/** @var MailboxViewer */
	private $viewer;

	/** Set true whenever the most recent listThreads()/getThread() substituted a
	 *  sealed placeholder (locked window or pending-parse row). Read via
	 *  contentLocked() to raise the result's top-level `locked` flag. */
	/** Wall-clock budget for the in-request index fold on a sealed-mailbox
	 *  search. Small enough to clear any sane max_execution_time and proxy
	 *  timeout with the query itself still to run; the deferred-work drain
	 *  ('mailbox_fts_fold', plugins/mailbox/includes/bootstrap.php) is what
	 *  actually catches a large backlog up. */
	const SEARCH_FOLD_BUDGET_SECONDS = 5.0;

	private $content_locked = false;

	/** Set when a search ran against an index that does not yet cover the
	 *  mailbox: {remaining, total}, emitted as search_indexing so the reader
	 *  can say results may be incomplete. */
	private $search_indexing = null;

	public function __construct(MailboxViewer $viewer) {
		$this->viewer = $viewer;
	}

	/** True when the last read substituted a sealed placeholder for any row. */
	public function contentLocked(): bool {
		return $this->content_locked;
	}

	// ---------------------------------------------------------------- scope

	private function db() {
		return DbConnector::get_instance()->get_db_link();
	}

	/**
	 * WHERE fragment scoping a READ to what $aliasId resolves to for this viewer.
	 * Always pins iem_delete_time IS NULL. The superadmin "All mail" (null +
	 * all-access) case is unconstrained so NULL-alias unmatched mail surfaces.
	 */
	/**
	 * Every normal read/mutation excludes draft rows (specs/mailbox_compose_maturity.md
	 * § Phase 2) — a draft is compose scratch, visible ONLY in the Drafts view
	 * (draftScopeSql). A reply/forward draft even shares its source's thread key, so
	 * without this a draft would surface inside that conversation.
	 */
	const NO_DRAFTS = " AND iem_direction IS DISTINCT FROM 'draft'";

	private function readScopeSql(?int $aliasId): string {
		// "Unmatched, domain D" — the per-domain catch-all box. Superadmin-only, same
		// as the aggregate below; the domain id is an int by construction (decoded from
		// the sentinel), never interpolated user text.
		$unmatched_domain = self::unmatchedDomainId($aliasId);
		if ($unmatched_domain !== null) {
			return $this->viewer->isAllAccess()
				? 'iem_delete_time IS NULL AND iem_iea_inbound_email_alias_id IS NULL'
					. ' AND iem_ied_inbound_email_domain_id = ' . intval($unmatched_domain) . self::NO_DRAFTS
				: '1=0';
		}
		// "Unmatched" — NULL-alias mail that belongs to no mailbox. Superadmin-only;
		// everyone else gets a no-match scope even if they craft the parameter.
		if ($aliasId === self::UNMATCHED) {
			return $this->viewer->isAllAccess()
				? 'iem_delete_time IS NULL AND iem_iea_inbound_email_alias_id IS NULL' . self::NO_DRAFTS
				: '1=0';
		}
		if ($aliasId === null && $this->viewer->isAllAccess()) {
			return 'iem_delete_time IS NULL' . self::NO_DRAFTS;
		}
		$ids = array_map('intval', $this->viewer->scopeAliasIds($aliasId));
		$in = count($ids) ? implode(',', $ids) : 'NULL';
		return 'iem_delete_time IS NULL AND iem_iea_inbound_email_alias_id IN (' . $in . ')' . self::NO_DRAFTS;
	}

	/**
	 * WHERE fragment scoping a READ to the TRASHED rows $aliasId resolves to for
	 * this viewer — readScopeSql() with the delete pin inverted, branch for branch.
	 * The Trash view is the only read that sees a soft-deleted row.
	 *
	 * Every branch is reproduced rather than parameterised: dropping the
	 * "unmatched" case would show an all-access viewer a Trash that disagrees with
	 * their inbox, and dropping the grant restriction would show one viewer
	 * another mailbox's discarded mail.
	 */
	private function trashScopeSql(?int $aliasId): string {
		$unmatched_domain = self::unmatchedDomainId($aliasId);
		if ($unmatched_domain !== null) {
			return $this->viewer->isAllAccess()
				? 'iem_delete_time IS NOT NULL AND iem_iea_inbound_email_alias_id IS NULL'
					. ' AND iem_ied_inbound_email_domain_id = ' . intval($unmatched_domain) . self::NO_DRAFTS
				: '1=0';
		}
		if ($aliasId === self::UNMATCHED) {
			return $this->viewer->isAllAccess()
				? 'iem_delete_time IS NOT NULL AND iem_iea_inbound_email_alias_id IS NULL' . self::NO_DRAFTS
				: '1=0';
		}
		if ($aliasId === null && $this->viewer->isAllAccess()) {
			return 'iem_delete_time IS NOT NULL' . self::NO_DRAFTS;
		}
		$ids = array_map('intval', $this->viewer->scopeAliasIds($aliasId));
		$in = count($ids) ? implode(',', $ids) : 'NULL';
		return 'iem_delete_time IS NOT NULL AND iem_iea_inbound_email_alias_id IN (' . $in . ')' . self::NO_DRAFTS;
	}

	/**
	 * READ scope for a Drafts view: the viewer's own draft rows. Drafts live in each
	 * mailbox's own Drafts folder, so $aliasId normally names one mailbox; null keeps
	 * the cross-mailbox form (every accessible mailbox at once), which is what an
	 * "All mail" Drafts read would want.
	 *
	 * Never all-access-broad — drafts are personal compose state, keyed by their From
	 * alias (a grant the viewer holds). An unmatched sentinel resolves to no mailbox at
	 * all: unmatched mail has no From identity, so it can hold no drafts.
	 */
	private function draftScopeSql(?int $aliasId = null): string {
		if ($aliasId !== null && ($aliasId === self::UNMATCHED || self::unmatchedDomainId($aliasId) !== null)) {
			return '1=0';
		}
		$accessible = array_map('intval', $this->viewer->accessibleAliasIds());
		$ids = ($aliasId !== null)
			? array_values(array_intersect($accessible, array(intval($aliasId))))
			: $accessible;
		$in = count($ids) ? implode(',', $ids) : 'NULL';
		// A draft belongs to its author alone (fix pack Fix 1) — never a co-grantee
		// of a shared mailbox, never an all-access superadmin.
		return "iem_delete_time IS NULL AND iem_direction = 'draft'"
			. ' AND iem_iea_inbound_email_alias_id IN (' . $in . ')'
			. ' AND iem_draft_author_user_id = ' . intval($this->viewer->getUserId());
	}

	/**
	 * WHERE fragment scoping a MUTATION to the rows this viewer may change.
	 * All-access may touch any non-deleted row (including NULL-alias); everyone
	 * else only rows in mailboxes they hold a grant for.
	 */
	private function mutationScopeSql(): string {
		// A draft is never a target of read/star/archive/spam/delete thread actions.
		$base = 'iem_delete_time IS NULL' . self::NO_DRAFTS;
		if ($this->viewer->isAllAccess()) {
			return $base;
		}
		$ids = array_map('intval', $this->viewer->accessibleAliasIds());
		$in = count($ids) ? implode(',', $ids) : 'NULL';
		return $base . ' AND iem_iea_inbound_email_alias_id IN (' . $in . ')';
	}

	/**
	 * MUTATION scope for the two actions that act ON a trashed row — restore and
	 * delete-forever. Identical to mutationScopeSql() with the delete pin inverted.
	 *
	 * restoreFromTrash() and purgeFromTrash() are its ONLY callers and must stay
	 * so. The IS NULL pin every other mutation carries is what keeps discarded
	 * mail out of the read/star/archive/spam/label paths, so it must never become
	 * a parameter those methods can be handed.
	 */
	private function trashMutationScopeSql(): string {
		$base = 'iem_delete_time IS NOT NULL' . self::NO_DRAFTS;
		if ($this->viewer->isAllAccess()) {
			return $base;
		}
		$ids = array_map('intval', $this->viewer->accessibleAliasIds());
		$in = count($ids) ? implode(',', $ids) : 'NULL';
		return $base . ' AND iem_iea_inbound_email_alias_id IN (' . $in . ')';
	}

	/**
	 * How many days mail stays in Trash before the retention sweep deletes it for
	 * good; 0 means never. Read from the declared setting, so the reader's purge
	 * dates and the task's cutoff can never disagree.
	 */
	public static function trashRetentionDays(): int {
		$days = intval(Globalvars::get_instance()->get_setting('mailbox_trash_retention_days'));
		return $days > 0 ? $days : 0;
	}

	// ------------------------------------------------------------ switcher

	/**
	 * Switcher data: one entry per accessible mailbox (address, domain, unread, total,
	 * any-starred, plus that mailbox's own draft count). For an all-access superadmin,
	 * adds an "All mail" pseudo-mailbox and one "Unmatched" entry PER DOMAIN that holds
	 * NULL-alias mail.
	 *
	 * @return array
	 */
	public function listMailboxes(): array {
		$alias_ids = $this->viewer->accessibleAliasIds();
		$db = $this->db();

		// The aliases the viewer actually holds a GRANT for (a personal signature
		// lives on a grant). For a plain member this equals the accessible set; for
		// an all-access superadmin it is the subset they are truly a member of, so
		// the reader shows the signature gear only where a signature can be set.
		$own_alias_ids = array();
		foreach (InboundEmailMailboxGrant::alias_ids_for_user($this->viewer->getUserId()) as $gid) {
			$own_alias_ids[intval($gid)] = true;
		}

		// Per-alias aggregates for the accessible set.
		$agg = array();
		if (count($alias_ids)) {
			$in = implode(',', array_map('intval', $alias_ids));
			// The badge counts the INBOX's unread, because the Inbox is where
			// clicking the badge lands: judged spam is excluded (it lives in the
			// Spam view — specs/inbound_email_spam_filtering.md) and so is archived
			// mail, matching listThreads' inbox filter exactly — outbound rows
			// included (bar a self-addressed send, which the Inbox does list),
			// since the Inbox does not list those either and their
			// unread flag is whatever the source's \Seen said, never something the
			// member set. A count that spans a view the click cannot reach sends
			// the reader looking for unread mail the Inbox does not hold. IS NOT TRUE, not "= false", so a NULL
			// from before the column existed counts as not-archived — the same
			// idiom listThreads uses, or the two would disagree on legacy rows.
			//
			// `total` deliberately stays mailbox-wide: it answers "does this box
			// hold anything at all", which decides whether the box is offered in
			// the rail. Narrowing it to the Inbox would hide a fully-archived
			// mailbox and its Trash with it.
			$sql = "SELECT iem_iea_inbound_email_alias_id AS alias_id,
						COUNT(*) AS total,
						COUNT(*) FILTER (
							WHERE iem_is_read = false AND iem_is_archived IS NOT TRUE
							AND (iem_direction IS DISTINCT FROM 'outbound' OR iem_self_delivered)
						) AS unread
					FROM iem_inbound_email_messages
					WHERE iem_delete_time IS NULL
					AND iem_spam_verdict IS DISTINCT FROM 'spam'" . self::NO_DRAFTS . "
					AND iem_iea_inbound_email_alias_id IN ($in)
					GROUP BY iem_iea_inbound_email_alias_id";
			$stmt = $db->query($sql);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$agg[intval($r['alias_id'])] = $r;
			}
		}

		// Resolve addresses (alias local-part + domain) for the accessible set.
		$mailboxes = array();
		if (count($alias_ids)) {
			$domains = new MultiInboundEmailDomain(array());
			$domains->load();
			$domain_map = array();
			foreach ($domains as $d) {
				$domain_map[intval($d->key)] = $d->get('ied_domain');
			}
			// A mailbox is `locked` for the switcher when its domain seals content
			// and the viewer holds no open unlock window — the native switcher then
			// shows the sealed placeholder and offers a one-tap unlock. The window
			// is per-user (the viewer's), so one check covers every mailbox.
			$viewer_unlocked = VaultUnlock::isOpen($this->viewer->getUserId());

			$aliases = new MultiInboundEmailAlias(array('deleted' => false),
				array('iea_alias' => 'ASC'));
			$aliases->load();
			foreach ($aliases as $a) {
				$aid = intval($a->key);
				if (!in_array($aid, array_map('intval', $alias_ids), true)) {
					continue;
				}
				$domain_id = intval($a->get('iea_ied_inbound_email_domain_id'));
				$domain = $domain_map[$domain_id] ?? '?';
				// Each mailbox states its OWN level (specs/mailbox_connect_flow.md
				// § D) — two pulled-in mailboxes on the same provider domain can
				// legitimately differ, and the chip beside each name has to say so.
				$level = $a->security_level();
				$seals = $a->seals_content();
				$row = $agg[$aid] ?? null;
				$mailboxes[] = array(
					'alias_id'       => $aid,
					'address'        => $a->get('iea_alias') . '@' . $domain,
					'domain'         => $domain,
					'security_level' => $level,
					'locked'         => ($seals && !$viewer_unlocked),
					'unread'         => $row ? intval($row['unread']) : 0,
					'total'          => $row ? intval($row['total']) : 0,
					// The viewer's own compose signature for this mailbox (§ Phase 3),
					// inserted client-side on compose open. Personal per grant. `own`
					// marks a mailbox the viewer is a member of (a signature can be set).
					'own'            => isset($own_alias_ids[$aid]),
					'signature'      => isset($own_alias_ids[$aid])
						? InboundEmailMailboxGrant::signatureFor($this->viewer->getUserId(), $aid) : '',
				);
				// Tracked membership folders for the reader's folder rail + the move/
				// labels control; the \All coverage view is excluded (the mailbox root
				// is the folder-unfiltered "All Mail" view). `folders_exclusive` drives
				// whether the reader offers single-folder "Move" or multi-label toggles.
				$info = $this->mailboxFolderInfo($aid);
				$mailboxes[count($mailboxes) - 1]['folders'] = $info['folders'];
				$mailboxes[count($mailboxes) - 1]['folders_exclusive'] = $info['exclusive'];
				// Whether this mailbox pulls from a source server. The Trash view says
				// so, because restore and delete-forever act locally — the copy in the
				// provider's own Trash goes on the provider's schedule.
				$mailboxes[count($mailboxes) - 1]['has_feed'] = $info['has_feed'];
			}
		}

		$result = array(
			'all_access' => $this->viewer->isAllAccess(),
			'mailboxes'  => $mailboxes,
		);

		// Drafts (specs/mailbox_compose_maturity.md § Phase 2), counted PER MAILBOX for
		// each mailbox's own Drafts folder. Every draft is bound to the From mailbox it
		// was saved against (MailboxDrafts::save rejects a draft with no alias), so a
		// draft always has exactly one mailbox to sit under and none can be stranded.
		// Personal compose state, so scoped by author even for a superadmin.
		$drafts_by_alias = array();
		if (count($alias_ids)) {
			$in = implode(',', array_map('intval', $alias_ids));
			$stmt = $db->query("SELECT iem_iea_inbound_email_alias_id AS alias_id, COUNT(*) AS total
				FROM iem_inbound_email_messages
				WHERE iem_delete_time IS NULL AND iem_direction = 'draft'
				AND iem_iea_inbound_email_alias_id IN ($in)
				AND iem_draft_author_user_id = " . intval($this->viewer->getUserId()) . "
				GROUP BY iem_iea_inbound_email_alias_id");
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$drafts_by_alias[intval($r['alias_id'])] = intval($r['total']);
			}
		}
		foreach ($mailboxes as $i => $mb) {
			$mailboxes[$i]['drafts'] = $drafts_by_alias[intval($mb['alias_id'])] ?? 0;
		}
		$result['mailboxes'] = $mailboxes;

		if ($this->viewer->isAllAccess()) {
			// "All mail" — every non-deleted, non-spam row, including NULL-alias.
			$row = $db->query("SELECT COUNT(*) AS total,
					COUNT(*) FILTER (WHERE iem_is_read = false) AS unread
				FROM iem_inbound_email_messages
				WHERE iem_delete_time IS NULL AND iem_spam_verdict IS DISTINCT FROM 'spam'" . self::NO_DRAFTS . "")
				->fetch(PDO::FETCH_ASSOC);
			$result['all_mail'] = array(
				'unread' => intval($row['unread']),
				'total'  => intval($row['total']),
			);

			// "Unmatched" — rows that belong to no mailbox, one box PER DOMAIN.
			//
			// Per domain because that is the unit the mail actually belongs to: a
			// catch-all message seals to its DOMAIN's owner, so a single lumped box can
			// hold mail sealed to several different people and can state no honest
			// protection level for what it contains.
			//
			// `trashed` is counted because a box is only offered when it has something
			// in it, and Trash is scoped to the selected box. Count live rows alone and
			// emptying it hides it — along with the only route to its own trash, leaving
			// discarded mail recoverable in the database and unreachable in the interface
			// until retention purges it (specs/mailbox_unmatched_sealing.md § unmatched
			// mail you cannot reach). `unread` excludes archived, judged spam and
			// trashed rows for the same reason the per-mailbox badge does: selecting a
			// box opens its Inbox view, so the count has to describe what that view
			// shows — a badge counting mail sitting in Spam or Trash sends the reader
			// looking for unread mail the Inbox does not hold.
			$stmt = $db->query("SELECT iem_ied_inbound_email_domain_id AS domain_id,
					COUNT(*) FILTER (WHERE iem_delete_time IS NULL) AS total,
					COUNT(*) FILTER (
						WHERE iem_delete_time IS NULL AND iem_is_read = false
						AND iem_is_archived IS NOT TRUE
						AND iem_spam_verdict IS DISTINCT FROM 'spam'
					) AS unread,
					COUNT(*) FILTER (WHERE iem_delete_time IS NOT NULL) AS trashed
				FROM iem_inbound_email_messages
				WHERE iem_iea_inbound_email_alias_id IS NULL" . self::NO_DRAFTS . "
				GROUP BY iem_ied_inbound_email_domain_id");

			// Domain names + their protection level, so each box can carry an honest
			// chip. A row whose domain record has gone is still listed, under its id —
			// unreachable mail is the failure this box exists to prevent.
			$unmatched_domains = new MultiInboundEmailDomain(array());
			$unmatched_domains->load();
			$name_map = array();
			$umlevel_map = array();
			foreach ($unmatched_domains as $d) {
				$name_map[intval($d->key)] = $d->get('ied_domain');
				$umlevel_map[intval($d->key)] = $d->security_level();
			}

			$unmatched = array();
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$did = intval($r['domain_id']);
				$unmatched[] = array(
					'domain_id'      => $did,
					'domain'         => $name_map[$did] ?? ('#' . $did),
					'security_level' => $umlevel_map[$did] ?? InboundEmailDomain::LEVEL_STANDARD,
					'unread'         => intval($r['unread']),
					'total'          => intval($r['total']),
					'trashed'        => intval($r['trashed']),
				);
			}
			// Stable, name-ordered so the rail does not reshuffle as counts move.
			usort($unmatched, function ($a, $b) { return strcmp($a['domain'], $b['domain']); });
			$result['unmatched'] = $unmatched;
		}

		return $result;
	}

	/**
	 * The label rail for a mailbox (folder rail + the move/labels control), keyed by the
	 * custom-label id (ilb_) — a label is global, so the same id space spans local and
	 * IMAP mail and the reader never sees IMAP folder ids.
	 *
	 * A mailbox with a bound IMAP feed lists that feed's tracked custom-label folders
	 * (each carries its bound label id, displayed by its remote folder name), and the
	 * feed's cardinality drives Move-vs-Labels. A mailbox with no feed lists the global
	 * custom labels — applying one is pure (clean) membership with no remote to sync.
	 * Special-use folders (Sent/Trash/Junk/…) and the \All coverage view are excluded:
	 * their state is a column on iem_inbound_email_messages, not a label.
	 *
	 * @return array{folders: array[], exclusive: bool, has_feed: bool}
	 */
	private function mailboxFolderInfo(int $aliasId): array {
		$accounts = new MultiInboundImapAccount(array(
			'alias_id' => $aliasId, 'enabled' => true, 'deleted' => false,
		));
		$accounts->load();
		$account = count($accounts) ? new InboundImapAccount($accounts->get(0)->key, TRUE) : null;

		if ($account && $account->key) {
			$folders = new MultiInboundImapFolder(array(
				'account_id' => intval($account->key), 'tracked' => true,
			), array('iif_name' => 'ASC'));
			$folders->load();
			$out = array();
			foreach ($folders as $row) {
				$f = new InboundImapFolder($row->key, TRUE);
				$labelId = $f->ensureLabel();
				if ($labelId === null) {
					continue; // special-use / coverage: a column, not a label
				}
				$out[] = array(
					'id'   => $labelId,
					'name' => $f->get('iif_name'),
					'role' => $f->get('iif_role'),
				);
			}
			return array('folders' => $out, 'exclusive' => $account->foldersExclusive(),
				'has_feed' => true);
		}

		// No feed: the global custom-label set, applied as pure membership (never synced).
		$labels = new MultiInboundEmailLabel(array('deleted' => false), array('ilb_name' => 'ASC'));
		$labels->load();
		$out = array();
		foreach ($labels as $l) {
			$out[] = array(
				'id'   => intval($l->key),
				'name' => $l->get('ilb_name'),
				'role' => InboundImapFolder::ROLE_CUSTOM,
			);
		}
		return array('folders' => $out, 'exclusive' => false, 'has_feed' => false);
	}

	/**
	 * The custom-label ids a thread currently carries — the union of its in-scope
	 * messages' present_local memberships. Pre-checks the reader's move/labels control.
	 * The reader matches these against the active mailbox's rail and ignores any not shown.
	 *
	 * @return int[]
	 */
	public function threadFolderIds(?int $aliasId, string $thread_key, bool $trashed = false): array {
		$ids = $this->messageIdsInThread($aliasId, $thread_key, $trashed);
		if (!count($ids)) {
			return array();
		}
		$in = implode(',', array_map('intval', $ids));
		$rows = $this->db()->query(
			"SELECT DISTINCT ilm_ilb_inbound_email_label_id AS lid
			 FROM ilm_inbound_label_members
			 WHERE ilm_iem_inbound_email_message_id IN ($in) AND ilm_present_local = true")
			->fetchAll(PDO::FETCH_COLUMN);
		$out = array();
		foreach ($rows as $lid) {
			$out[] = intval($lid);
		}
		return $out;
	}

	/**
	 * Apply or remove a custom label for a set of messages — the reader's move / labels
	 * control. InboundLabelMember::apply/remove is the truth write; it resolves whether
	 * the label is bound to the message's feed and records the dirtiness accordingly, so
	 * two-way push later reconciles a bound change to the source as a COPY (label add) /
	 * MOVE (exclusive) / EXPUNGE (label remove), while an unbound (local) label never
	 * touches a remote. Scoped to messages the viewer may mutate. Returns the count changed.
	 */
	public function setMembership(array $message_ids, int $labelId, bool $present): int {
		$ids = $this->intList($message_ids);
		if (!count($ids) || $labelId <= 0) {
			return 0;
		}
		// The label must still exist (a stale rail entry no-ops rather than mis-applies).
		$lk = $this->db()->prepare(
			'SELECT 1 FROM ilb_inbound_email_labels WHERE ilb_inbound_email_label_id = ? AND ilb_delete_time IS NULL LIMIT 1');
		$lk->execute(array($labelId));
		if (!$lk->fetchColumn()) {
			return 0;
		}

		$in = implode(',', $ids);
		$rows = $this->db()->query(
			"SELECT iem_inbound_email_message_id AS id FROM iem_inbound_email_messages
			 WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql())
			->fetchAll(PDO::FETCH_COLUMN);

		$count = 0;
		foreach ($rows as $mid) {
			$mid = intval($mid);
			if ($present) {
				InboundLabelMember::apply($mid, $labelId);
			} else {
				InboundLabelMember::remove($mid, $labelId);
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Create a label for a mailbox from the reader, returning {id (the label id), name,
	 * role} or null when the viewer can't mutate the mailbox or the name is empty.
	 *
	 * A label is an ilb_ row in the global namespace. When the mailbox has an IMAP feed,
	 * the label is also bound to a tracked, pending `iif_` folder that does not yet exist
	 * on the source — the sync push issues the IMAP CREATE and clears the pending flag
	 * (§14) so the label materializes as a remote folder. With no feed the label is
	 * membership-only. Idempotent: a same-named label/folder is reused (and re-tracked).
	 */
	public function createFolder(int $aliasId, string $name): ?array {
		$name = trim(str_replace(array("\r", "\n", '"'), '', $name));
		if ($name === '' || $aliasId <= 0) {
			return null;
		}
		$name = substr($name, 0, 255);
		if (!$this->viewer->isAllAccess() && !$this->viewer->canAccess($aliasId)) {
			return null;
		}

		// The label (global namespace) — created regardless of feed.
		$label = InboundEmailLabel::findOrCreate($name);
		if ($label === null) {
			return null;
		}
		$labelId = intval($label->key);

		// With a feed, also bind a tracked, pending folder so the sync push CREATEs it on
		// the source and files membership into it. Reuse a same-named folder (re-track it).
		$accounts = new MultiInboundImapAccount(array(
			'alias_id' => $aliasId, 'enabled' => true, 'deleted' => false,
		));
		$accounts->load();
		if (count($accounts)) {
			$accountId = intval($accounts->get(0)->key);
			$existing = new MultiInboundImapFolder(array('account_id' => $accountId, 'name' => $name));
			$existing->load();
			if (count($existing)) {
				$folder = new InboundImapFolder($existing->get(0)->key, TRUE);
				if (!$folder->get('iif_is_tracked')) {
					$folder->set('iif_is_tracked', true);
					$folder->prepare();
					$folder->save();
				}
			} else {
				$folder = new InboundImapFolder(NULL);
				$folder->set('iif_iia_inbound_imap_account_id', $accountId);
				$folder->set('iif_name', $name);
				$folder->set('iif_role', InboundImapFolder::ROLE_CUSTOM);
				$folder->set('iif_is_tracked', true);
				$folder->set('iif_pending_remote_create', true);
				$folder->prepare();
				$folder->save();
				$folder->load();
			}
			$folder->ensureLabel(); // bind the folder to the (same-named) label
		}
		return array('id' => $labelId, 'name' => $label->get('ilb_name'),
			'role' => InboundImapFolder::ROLE_CUSTOM);
	}

	// -------------------------------------------------------------- threads

	/**
	 * Conversation list within scope, grouped by thread, latest-first.
	 *
	 * @param int|null $aliasId  null = all accessible (unconstrained for superadmin)
	 * @param array    $filters  q, unread_only, starred_only, spam
	 * @param int      $page     1-based
	 * @param int      $perpage
	 * @return array  ['threads'=>[...], 'has_more'=>bool, 'page'=>int]
	 */
	public function listThreads(?int $aliasId, array $filters = array(), int $page = 1, int $perpage = 50, ?int $folderId = null): array {
		$this->content_locked = false;
		$db = $this->db();
		$page = max(1, $page);
		$perpage = max(1, min(200, $perpage));
		$offset = ($page - 1) * $perpage;

		// Deferred ingest (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 5):
		// if this scope's owner holds an unlocked vault, parse any relay-sealed
		// Fortress backlog before listing, so the mailbox view reflects fully-parsed
		// mail. No-op on colocated deployments (no pending rows ever exist).
		$this->drainRelayBacklog($aliasId);

		// Drafts view (specs/mailbox_compose_maturity.md § Phase 2): the viewer's saved
		// drafts, each a singleton (grouped by its own id, not a shared thread key).
		// Every other view excludes drafts via readScopeSql.
		$drafts = !empty($filters['drafts']);
		// Trash view (specs/mailbox_trash_folder.md): the soft-deleted rows in scope.
		// Mutually exclusive with every other view — a discarded message is in Trash
		// and nowhere else, whatever its read/archive/spam/label state says.
		$trash = !$drafts && !empty($filters['trash']);
		$where = array($drafts ? $this->draftScopeSql($aliasId)
			: ($trash ? $this->trashScopeSql($aliasId) : $this->readScopeSql($aliasId)));
		$params = array();

		// Spam disposition (specs/inbound_email_spam_filtering.md): the Spam view shows
		// only judged-spam rows; every other view hides them. A verdict is independent
		// of folder membership, so this works for local and IMAP mailboxes alike.
		// Trash is the exception: it holds everything discarded, spam-judged included,
		// or mail a filter trashed on arrival would be invisible in both views.
		if (!$trash) {
			if (!empty($filters['spam'])) {
				$where[] = "iem_spam_verdict = 'spam'";
			} else {
				$where[] = "iem_spam_verdict IS DISTINCT FROM 'spam'";
			}
		}

		// Inbox view (specs/implemented/inbound_email_filters.md): the default list
		// is the Inbox — archived mail ("Skip the Inbox") is hidden. The "All Mail"
		// view passes no inbox flag, so archived conversations remain reachable.
		// IS NOT TRUE (not "= false") so a NULL iem_is_archived counts as not-archived
		// — messages stored before the column existed are never-archived, not hidden.
		//
		// Outbound rows are not inbox material either: a conversation the member
		// just started lives in All Mail (tagged Sent) until a reply arrives —
		// the reply row is what puts the thread in the Inbox. Row-level filter,
		// thread-level effect: a thread with any qualifying inbound row still
		// lists, with its full history in the thread view.
		//
		// The one outbound row the Inbox does admit is a message the member
		// addressed to THIS mailbox (specs/bugfix_self_addressed_send.md). It was
		// delivered here, so it is inbox material, and it is a single row rather
		// than a Sent copy plus a delivered copy — the delivered copy reconciles
		// onto the composer's row at ingest. iem_self_delivered is that row saying
		// so; without it a self-send would appear in Sent and nowhere else.
		//
		// Sent view: conversations carrying a message the member sent. A
		// pseudo-folder like Spam — it reads a column (the direction), not
		// folder membership, so it works for local and IMAP mailboxes alike.
		// Row-level filter, thread-level effect: the thread lists with its
		// latest SENT message as the row, and opening it shows the full
		// history. Trash still wins (a discarded sent message is in Trash and
		// nowhere else), and drafts are already outside every read scope.
		$sent = !$trash && !$drafts && !empty($filters['sent']);
		if ($sent) {
			$where[] = "iem_direction = 'outbound'";
		}

		// A SEARCH is never narrowed by this default tab: the Inbox flag shapes
		// the list, but a query from the Inbox covers All Mail — archived and
		// sent included — because a search that silently drops matches over
		// which tab happened to be open reads as broken, not as scoped (an
		// imported Gmail archive made 96% of hits vanish this way). Explicit
		// scopes — Trash, Spam, Drafts, a label — still bound their own
		// searches; the response says the widening happened (search_scope) so
		// the reader can label it.
		$searching = !empty($filters['q']);
		if (!$trash && !$sent && !empty($filters['inbox']) && !$searching) {
			$where[] = "iem_is_archived IS NOT TRUE";
			$where[] = "(iem_direction IS DISTINCT FROM 'outbound' OR iem_self_delivered)";
		}

		// Label dimension: restrict to messages carrying the chosen custom label.
		// $folderId is a label id (ilb_). Null = the label-unfiltered "All Mail" view, so
		// unlabeled messages are reachable at the mailbox root. Each message row is
		// unique, so the thread aggregation is not double-counted.
		if ($folderId !== null && $folderId > 0) {
			$where[] = 'iem_inbound_email_message_id IN (SELECT ilm_iem_inbound_email_message_id
						FROM ilm_inbound_label_members
						WHERE ilm_ilb_inbound_email_label_id = ? AND ilm_present_local = true)';
			$params[] = $folderId;
		}

		// Full-text search (specs/implemented/inbound_email_encryption_at_rest.md §
		// 6): a sealed mailbox's content columns are ciphertext, unsearchable in
		// SQL — MailboxIndex (a sealed, in-window SQLite FTS5 working copy) is the
		// only way to search it. This resolves per single-mailbox scope, since the
		// index is per owner: a single alias whose owner holds a Sealed Vault AND
		// whose mailbox still has sealed content — its domain seals, or sealed
		// rows remain from an earlier level (specs/mailbox_lowering_unseal.md) —
		// searches via the index (locked → an explicit signal, not silently empty
		// forever). Every other scope (all-mail, a converged lowered mailbox,
		// "unmatched") keeps the Postgres tsvector search on the plaintext
		// columns — a sealed row elsewhere in a broad scope simply never matches
		// its own ciphertext, which is inert degradation, not a leak.
		$search_locked = false;
		if (!empty($filters['q'])) {
			$owner_id = ($aliasId !== null && $aliasId > 0) ? InboundEmailMessage::singleOwnerUserId($aliasId) : null;
			$vault = $owner_id !== null ? UserEncryptionVault::loadForUser($owner_id) : null;

			if ($vault !== null && InboundEmailMessage::aliasSealedContentActive((int)$aliasId)) {
				$secret = VaultUnlock::secretKey($owner_id);
				if ($secret === null) {
					$search_locked = true;
					$where[] = '1=0'; // no query is meaningful while locked
				} else {
					// Budgeted: fold a bounded slice of any backlog, then search
					// what is indexed. A backlog too large for the slice (a bulk
					// import, a long-offline owner) drains in the background via
					// the 'mailbox_fts_fold' deferred-work consumer; until it
					// catches up, the response says the index is still building.
					$index = new MailboxIndex();
					$fold = $index->fold($owner_id, $secret,
						microtime(true) + self::SEARCH_FOLD_BUDGET_SECONDS);
					if (empty($fold['complete'])) {
						$this->search_indexing = array(
							'remaining' => intval($fold['remaining']),
							'total'     => intval($fold['total']),
						);
					}
					$ids = $index->search($owner_id, $filters['q']);
					$where[] = count($ids)
						? 'iem_inbound_email_message_id IN (' . implode(',', array_map('intval', $ids)) . ')'
						: '1=0';
				}
			} else {
				// The expression MUST stay byte-identical to iem_013's GIN index
				// expression (plugins/mailbox/migrations/migrations.php), or the
				// planner will not use the index. websearch_to_tsquery tolerates
				// arbitrary user input (stray quotes/operators won't raise).
				$where[] = self::FULLTEXT_SQL . " @@ websearch_to_tsquery('english', ?)";
				$params[] = $filters['q'];
			}
		}

		// Thread-level filters.
		$having = array();
		if (!empty($filters['unread_only'])) {
			$having[] = 'COUNT(*) FILTER (WHERE iem_is_read = false) > 0';
		}
		if (!empty($filters['starred_only'])) {
			$having[] = 'BOOL_OR(iem_is_starred)';
		}

		$gk = $drafts ? "'m:' || iem_inbound_email_message_id" : self::GROUP_KEY_SQL;

		// Sectioning (unread first, then starred, then the rest) answers "what
		// still needs me?" — an Inbox question. On a view of mail the member SENT
		// or WROTE there is no such question: an outbound row's unread flag is
		// whatever the source's \Seen said when it was pulled, or the ingest
		// default of false, and never something the member decided. Ranking by it
		// sorted 25,000 never-opened imported sent messages above today's send, so
		// the top of Sent was months old. Those two views are strictly
		// reverse-chronological instead, which is also what every mail client
		// does. Emitting rank 2 (rather than dropping the column) keeps the reader
		// rendering one plain list with no section header
		// (specs/bugfix_sent_view_ordering.md).
		$sectioned = !$sent && !$drafts;
		$rank_sql = $sectioned
			? "CASE
						WHEN COUNT(*) FILTER (WHERE iem_is_read = false) > 0 THEN 0
						WHEN BOOL_OR(iem_is_starred) THEN 1
						ELSE 2
					END"
			: '2';
		// section_rank buckets each thread for the Gmail-style sectioned list:
		// 0 = has unread, 1 = starred (all read), 2 = everything else. Ordering by
		// it first keeps the buckets contiguous across pages, so the client renders
		// one header per section even with pagination. On the unsectioned views it
		// is the constant 2 (see $rank_sql above), which leaves the same ORDER BY
		// sorting purely by time.
		//
		// No content columns (sender/subject/body) are aggregated here — they may
		// be ciphertext (specs/implemented/inbound_email_encryption_at_rest.md §
		// 6.1). member_ids carries every message id in the thread so the caller can
		// decrypt (or read plain) each one in PHP via the Sealed Vault raw-row hook.
		$sql = "SELECT
					$gk AS thread_key,
					MAX(iem_received_time) AS latest_time,
					COUNT(*) AS msg_count,
					COUNT(*) FILTER (WHERE iem_is_read = false) AS unread_count,
					BOOL_OR(iem_is_starred) AS any_starred,
					BOOL_OR(iem_is_archived) AS any_archived,
					MAX(iem_ai_danger_score) AS danger_score,
					BOOL_OR(iem_direct_verified) AS any_direct_verified,
					MIN(iem_delete_time) AS trashed_time,
					$rank_sql AS section_rank,
					ARRAY_AGG(iem_inbound_email_message_id) AS member_ids,
					(ARRAY_AGG(iem_inbound_email_message_id ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS latest_id
				FROM iem_inbound_email_messages
				WHERE " . implode(' AND ', $where) . "
				GROUP BY $gk";
		if (count($having)) {
			$sql .= ' HAVING ' . implode(' AND ', $having);
		}
		$sql .= " ORDER BY section_rank ASC, latest_time DESC
				LIMIT ? OFFSET ?";

		// Fetch one extra to detect a further page.
		$params[] = $perpage + 1;
		$params[] = $offset;

		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$has_more = count($rows) > $perpage;
		if ($has_more) {
			$rows = array_slice($rows, 0, $perpage);
		}

		// Batch-decrypt every message on this page in one query (mirrors
		// ModelQueryExecutor::decryptSealedFields()) — bounded to the page's
		// threads, not the whole mailbox.
		$page_ids = array();
		foreach ($rows as $r) {
			foreach ($this->pgIntArray($r['member_ids']) as $mid) {
				$page_ids[] = $mid;
			}
		}
		$content = $this->fetchAndDecryptContent(array_unique($page_ids));
		// Which of this page's messages carry a real attachment, for the list
		// paperclip. Presence only — the manifest itself is a thread-open cost.
		$clipped = $this->messageIdsWithAttachments(array_unique($page_ids));

		// When this thread purges, for the Trash list's date column. Computed for
		// display and never stored: the window is a setting an operator can change,
		// so a stored date would be a promise the next edit breaks. The earliest
		// discard in the thread decides, since that message goes first.
		$purge_days = $trash ? self::trashRetentionDays() : 0;

		$section_for = array(0 => 'unread', 1 => 'starred', 2 => 'other');
		$threads = array();
		foreach ($rows as $r) {
			$rank = intval($r['section_rank']);
			$latest_id = intval($r['latest_id']);
			$latest = $content[$latest_id] ?? array('sender' => '', 'subject' => '', 'body_plain' => '', 'body_html' => '');

			$senders = array();
			$has_attachment = false;
			foreach ($this->pgIntArray($r['member_ids']) as $mid) {
				$s = trim((string)($content[$mid]['sender'] ?? ''));
				if ($s !== '' && !in_array($s, $senders, true)) {
					$senders[] = $s;
				}
				if (isset($clipped[$mid])) {
					$has_attachment = true;
				}
			}

			$threads[] = array(
				'thread_key'   => $r['thread_key'],
				'subject'      => $latest['subject'],
				'senders'      => implode(', ', $senders),
				'sender'       => $latest['sender'],
				// The HTML is passed whole — the preview extractor caps its own input,
				// and a fixed prefix of a marketing email is all stylesheet. The plain
				// prefix must clear a preheader padding run (commonly over a thousand
				// characters of entities), or the cleaner sees only padding and a cut
				// mid-entity leaves a fragment as the whole preview.
				'snippet'      => $this->buildSnippet(mb_substr($latest['body_plain'], 0, 4000), $latest['body_html']),
				// AI triage (specs/implemented/joinery_ai_email_triage.md): the latest
				// message's one-line AI summary, empty if untriaged. The reader shows
				// this in place of the snippet when present.
				'ai_summary'   => trim((string)($latest['ai_summary'] ?? '')),
				'section'      => $section_for[$rank] ?? 'other',
				'msg_count'    => intval($r['msg_count']),
				'unread_count' => intval($r['unread_count']),
				'any_starred'  => (bool)$this->pgBool($r['any_starred']),
				'any_archived' => (bool)$this->pgBool($r['any_archived']),
				// True when any message in the thread has a real (non-inline)
				// attachment — the list shows a paperclip beside the time.
				'has_attachment' => $has_attachment,
				// AI security scan (specs/joinery_ai_email_security_scan.md):
				// the highest danger score among the thread's messages, or
				// null if none has been scanned. The list badge is silent
				// below 3 — see the reader JS.
				'danger_score' => $r['danger_score'] !== null ? intval($r['danger_score']) : null,
				// Joinery Direct (docs/joinery_direct.md § The social signal): true
				// when any message in the thread arrived on the direct channel AND
				// its sender is in this recipient's contacts. Applied by the
				// receiver from verified transport plus contact membership, so
				// nothing in a message's content can reproduce it.
				'direct_verified' => (bool)$this->pgBool($r['any_direct_verified']),
				'latest_time'  => $r['latest_time'],
				'latest_id'    => $latest_id,
				// Trash only: when this thread is permanently deleted (UTC), or null
				// when nothing purges it — retention 0, or any other view.
				'purge_time'   => ($purge_days > 0 && !empty($r['trashed_time']))
					? LibraryFunctions::time_shift($r['trashed_time'], $purge_days . ' days', 'Y-m-d H:i:s')
					: null,
			);
		}

		$result = array(
			'threads'  => $threads,
			'has_more' => $has_more,
			'page'     => $page,
		);
		if ($trash) {
			// The window itself, so the Trash view can say what it is (0 = nothing
			// purges) without the client inferring it from absent dates.
			$result['trash_retention_days'] = $purge_days;
		}
		if ($search_locked) {
			// The vault-holding mailbox being searched has no open window — the
			// reader prompts to unlock rather than showing an empty result forever.
			$result['search_locked'] = true;
		}
		if ($this->search_indexing !== null) {
			// The search ran, but against an index that does not yet cover the
			// mailbox — the reader says so instead of letting missing results
			// read as missing mail.
			$result['search_indexing'] = $this->search_indexing;
		}
		if ($searching && !$trash && !$sent && !empty($filters['inbox'])) {
			// The Inbox tab was open but the search covered All Mail (see the
			// scope block above) — the reader shows a one-line note so results
			// beyond the Inbox are explained, not surprising.
			$result['search_scope'] = 'all_mail';
		}
		if ($this->content_locked) {
			// At least one row rendered a sealed placeholder (locked window or a
			// Fortress pending-parse row). The reader shows metadata now and turns
			// any content action into a one-tap unlock prompt.
			$result['locked'] = true;
		}
		return $result;
	}

	/**
	 * Every message id in $ids, with sender/subject/body_plain/body_html/ai_summary
	 * resolved through the Sealed Vault raw-row read hook (docs/sealed_vault.md)
	 * — decrypted when sealed and in-window, a locked placeholder when sealed
	 * and locked, plain as-is when never sealed. Mirrors
	 * plugins/joinery_ai/includes/ModelQueryExecutor.php's decryptSealedFields().
	 *
	 * @return array<int, array{sender:string,subject:string,body_plain:string,body_html:string,ai_summary:string}>
	 */
	private function fetchAndDecryptContent(array $ids): array {
		$out = array();
		if (!count($ids)) {
			return $out;
		}
		$in = implode(',', array_map('intval', $ids));
		$sql = "SELECT iem_inbound_email_message_id, iem_iea_inbound_email_alias_id, iem_direction,
					iem_content_sealed, iem_sealed_key, iem_sealed_owner_user_id, iem_pending_parse,
					iem_sender, iem_subject, iem_body_plain, iem_body_html, iem_ai_summary
				FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id IN ($in)";
		$rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

		$fields = array('iem_sender' => 'sender', 'iem_subject' => 'subject',
			'iem_body_plain' => 'body_plain', 'iem_body_html' => 'body_html',
			'iem_ai_summary' => 'ai_summary');
		foreach ($rows as $row) {
			$mid = intval($row['iem_inbound_email_message_id']);
			$entry = array();
			// A Fortress pending-parse row is sealed to the owner and not yet
			// parsed — its content columns are empty. It renders the SAME
			// placeholder as a locked sealed row, never a visible third state.
			$pending = $this->pgBool($row['iem_pending_parse'] ?? false);
			foreach ($fields as $col => $key) {
				if ($pending) {
					$entry[$key] = self::SEALED_PLACEHOLDER;
					$this->content_locked = true;
					continue;
				}
				$value = $row[$col];
				if ($value !== null && $value !== '') {
					try {
						$value = InboundEmailMessage::decryptSealedFieldStatic($col, $value, $row);
					} catch (VaultLockedException $e) {
						$value = self::SEALED_PLACEHOLDER;
						$this->content_locked = true;
					} catch (Throwable $e) {
						// A column that will not open — a damaged blob, or one
						// left plaintext under a sealed flag by a rogue write
						// path. One bad row must not take down the whole thread
						// list, so it renders as unreadable and says so in the
						// log, naming the message and column. content_locked
						// stays down: that flag is the reader's "unlock your
						// vault" prompt, and no unlock fixes a damaged column —
						// raising it here made a write-path bug masquerade as a
						// locked vault (specs/bugfix_promoted_sent_row_sealing.md).
						error_log('MailboxService: could not read ' . $col . ' on message '
							. $mid . ': ' . $e->getMessage());
						$value = self::SEALED_PLACEHOLDER;
					}
				}
				$entry[$key] = (string)$value;
			}
			$out[$mid] = $entry;
		}
		return $out;
	}

	/** Parse a Postgres integer array literal ("{1,2,3}", PDO returns it as a string) to int[]. */
	private function pgIntArray($raw): array {
		$raw = trim((string)$raw, "{}");
		if ($raw === '') {
			return array();
		}
		return array_map('intval', explode(',', $raw));
	}

	/**
	 * One-line preview of the latest message for the list row. Prefers the plain
	 * body, cleaned through MailboxHtmlSanitizer::previewText() — a received
	 * plain part is generated from the sender's HTML often enough to carry
	 * literal entities and invisible preheader padding, and a part that was
	 * NOTHING but padding cleans to '' so the HTML preview takes over instead
	 * of an empty line. An HTML-only message is read through
	 * MailboxHtmlSanitizer::toReadableText(), which parses rather than pattern-
	 * matches so a sender's embedded stylesheet cannot surface as the preview.
	 * Whitespace is collapsed and the result trimmed to a short, single line.
	 */
	private function buildSnippet(?string $plain, ?string $html): string {
		$text = MailboxHtmlSanitizer::previewText((string)$plain);
		if ($text === '' && trim((string)$html) !== '') {
			$text = MailboxHtmlSanitizer::toReadableText((string)$html);
		}
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		if (function_exists('mb_strimwidth')) {
			return mb_strimwidth($text, 0, 160, '…', 'UTF-8');
		}
		return strlen($text) > 160 ? substr($text, 0, 159) . '…' : $text;
	}

	/**
	 * All in-scope messages in a thread, chronological, each with read/star
	 * flags AND its plain/HTML body (rendered client-side in a sandboxed iframe).
	 * Empty if the thread is outside scope.
	 *
	 * @return array[]  message rows
	 */
	/**
	 * Parse the relay-sealed pending-parse backlog for this scope's owner, once
	 * per request per owner, when their vault is unlocked. Cheap and skipped
	 * entirely on colocated deployments (the pending query hits an owner index and
	 * returns nothing). Never throws into the caller — a drain failure must not
	 * break the mailbox view.
	 */
	private function drainRelayBacklog(?int $aliasId): void {
		static $drained = array();

		// Resolve whose pending-parse backlog to drain. A single-alias scope drains
		// that alias's single owner; the combined "all mailboxes" view ($aliasId
		// null) is the primary reader surface (thread_list_logic + native apps), so
		// it must drain too — the session user, whose own Fortress mail is what the
		// relay pulled (specs/mailbox_relay_fix_pack.md § Fix 9). Without this, a
		// Fortress owner's default inbox shows blank sender/subject/body forever.
		if ($aliasId !== null && $aliasId > 0) {
			$owner_id = InboundEmailMessage::singleOwnerUserId($aliasId);
		} else {
			$owner_id = $this->viewer->getUserId();
		}
		if ($owner_id === null || $owner_id <= 0 || isset($drained[$owner_id])) {
			return;
		}
		$drained[$owner_id] = true;

		try {
			$vault = UserEncryptionVault::loadForUser($owner_id);
			if ($vault === null) {
				return;
			}
			$secret = VaultUnlock::secretKey($owner_id);
			if ($secret === null) {
				return; // locked — nothing to parse until the next unlocked view
			}
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DeferredIngest.php'));
			DeferredIngest::drainForUser($owner_id, $secret);
		} catch (\Throwable $e) {
			error_log('MailboxService: relay backlog drain failed for owner ' . $owner_id . ': ' . $e->getMessage());
		}
	}

	public function getThread(?int $aliasId, string $thread_key, bool $trashed = false): array {
		$this->content_locked = false;
		$ids = $this->messageIdsInThread($aliasId, $thread_key, $trashed);
		if (!count($ids)) {
			return array();
		}
		$in = implode(',', array_map('intval', $ids));
		$db = $this->db();
		$sql = "SELECT iem_inbound_email_message_id, iem_iea_inbound_email_alias_id,
					iem_sender, iem_recipient, iem_bcc, iem_subject, iem_received_time,
					iem_is_read, iem_is_starred, iem_read_time, iem_dkim_result,
					iem_spf_result, iem_dmarc_result, iem_auth_source, iem_spam_score,
					iem_mir_mail_import_run_id, iem_iia_inbound_imap_account_id,
					iem_size_bytes, iem_message_id_header, iem_direction,
					iem_body_plain, iem_body_html, iem_content_sealed, iem_sealed_key,
					iem_sealed_owner_user_id, iem_pending_parse, iem_ai_danger_score, iem_ai_scan, iem_ai_scan_time,
					iem_ai_summary, iem_transport, iem_direct_verified,
					iem_raw_storage_driver, iem_raw_storage_key,
					(COALESCE(length(iem_raw_message), 0) > 0) AS iem_has_inline_raw,
					(COALESCE(length(iem_raw_headers), 0) > 0) AS iem_has_raw_headers
				FROM iem_inbound_email_messages
				WHERE iem_inbound_email_message_id IN ($in)
				ORDER BY iem_received_time ASC, iem_inbound_email_message_id ASC";
		$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

		// Per-message attachment list for the reader (non-inline parts only — inline
		// cid: parts belong to the HTML body). One query for the whole thread.
		$att_by_msg = $this->attachmentsForMessages($ids);

		$out = array();
		foreach ($rows as $r) {
			$mid = intval($r['iem_inbound_email_message_id']);
			$decrypted = $this->decryptThreadRow($r);
			$out[] = array(
				'id'                => intval($r['iem_inbound_email_message_id']),
				'alias_id'          => $r['iem_iea_inbound_email_alias_id'] !== null
										? intval($r['iem_iea_inbound_email_alias_id']) : null,
				'sender'            => $decrypted['iem_sender'],
				'recipient'         => $decrypted['iem_recipient'],
				// Bcc: real content only on an outbound (Sent) row; the reader shows a
				// separate "Bcc:" line when present. Its own sealed column, so it never
				// leaks into iem_recipient / a reply-all.
				'bcc'               => $decrypted['iem_bcc'],
				'subject'           => $decrypted['iem_subject'],
				'received_time'     => $r['iem_received_time'],
				'is_read'           => (bool)$this->pgBool($r['iem_is_read']),
				'is_starred'        => (bool)$this->pgBool($r['iem_is_starred']),
				'read_time'         => $r['iem_read_time'],
				'dkim_result'       => $r['iem_dkim_result'],
				'spf_result'        => $r['iem_spf_result'],
				'dmarc_result'      => $r['iem_dmarc_result'],
				'auth_source'       => $r['iem_auth_source'],
				// Plain-language authentication readout, resolved server-side so the
				// web reader and every native/API consumer say the same thing — and
				// so no client keeps its own list of which sources count as verified.
				'auth'              => InboundEmailMessage::authReadout(
										$r['iem_auth_source'], $r['iem_spf_result'],
										$r['iem_dkim_result'], $r['iem_dmarc_result'],
										($r['iem_mir_mail_import_run_id'] !== null) ? 'import'
											: (($r['iem_iia_inbound_imap_account_id'] !== null) ? 'imap' : null)),
				// Content-spam score (specs/inbound_email_content_spam_filtering.md):
				// display only, NULL when none reported. Never drives disposition.
				'spam_score'        => ($r['iem_spam_score'] !== null) ? (float)$r['iem_spam_score'] : null,
				// How the message reached the box, and whether it earned the
				// verified-direct mark. The mark asserts exactly two things: the
				// sending instance was cryptographically verified, and the sender
				// is in this recipient's contacts. Never "trusted human".
				'transport'         => (string)($r['iem_transport'] ?? ''),
				'direct_verified'   => (bool)$this->pgBool($r['iem_direct_verified']),
				'size_bytes'        => intval($r['iem_size_bytes']),
				'message_id_header' => $r['iem_message_id_header'],
				'direction'         => $r['iem_direction'] ?: 'inbound',
				'body_plain'        => $decrypted['iem_body_plain'],
				'body_html'         => $decrypted['iem_body_html'],
				// AI security scan (specs/joinery_ai_email_security_scan.md):
				// null score/scan = not yet scanned by any recipe.
				'ai_danger_score'   => ($r['iem_ai_danger_score'] !== null) ? intval($r['iem_ai_danger_score']) : null,
				// Sealed on a protected row, so it goes through the decrypted map
				// like the body does — never straight off $r.
				'ai_scan'           => self::decodeScan($decrypted['iem_ai_scan']),
				'ai_scan_time'      => $r['iem_ai_scan_time'],
				// AI triage (specs/implemented/joinery_ai_email_triage.md): carried for
				// native/API consumers. The web thread view does not render it — the
				// full body is already on screen, so a summary of it is noise there.
				'ai_summary'        => $decrypted['iem_ai_summary'],
				// Where "the original" of this message can come from — drives the
				// reader's Show original / Download .eml items
				// (specs/mailbox_show_original_coverage.md). 'stored' and 'imap'
				// are the true RFC822 bytes (stored, or fetched live from the
				// source mailbox); 'headers' is a lean record whose retained
				// header block supports a labeled reconstruction (no .eml);
				// 'none' hides both items rather than offering dead ends.
				'original_source'   => $this->originalSource($r),
				'attachments'       => $att_by_msg[$mid] ?? array(),
			);
		}
		return $out;
	}

	/**
	 * Does this row have a whole RFC822 original to export? Reads the storage
	 * descriptor (see InboundEmailMessage's header): 'remote' rows never have
	 * one, file/object-backed rows have one when they carry a key, and an
	 * 'inline' row has one when the column is non-empty. Says nothing about
	 * whether a sealed one can be opened right now — that is the vault's answer,
	 * given when the export is actually asked for.
	 */
	private function hasStoredOriginal(array $row): bool {
		$driver = (string)($row['iem_raw_storage_driver'] ?? '') ?: 'inline';
		if ($driver === 'remote') {
			return false;
		}
		if ($driver === 'local' || $driver === 'cloud') {
			return trim((string)($row['iem_raw_storage_key'] ?? '')) !== '';
		}
		return (bool)$this->pgBool($row['iem_has_inline_raw'] ?? false);
	}

	/**
	 * Where "the original" of this row can come from
	 * (specs/mailbox_show_original_coverage.md) — the thread payload's
	 * original_source. Mirrors mailbox_resolve_original()'s dispatch so the
	 * menu never offers what the endpoint would refuse:
	 *   'stored'  — a whole raw is stored here (hasStoredOriginal)
	 *   'imap'    — reference-backed with its source account still connected;
	 *               fetched live when asked for
	 *   'headers' — a lean record that retained its wire header block; Show
	 *               original renders a labeled reconstruction, no .eml
	 *   'none'    — nothing to offer (rows from before header retention)
	 */
	private function originalSource(array $row): string {
		if ($this->hasStoredOriginal($row)) {
			return 'stored';
		}
		$driver = (string)($row['iem_raw_storage_driver'] ?? '') ?: 'inline';
		if ($driver === 'remote') {
			return intval($row['iem_iia_inbound_imap_account_id'] ?? 0) > 0 ? 'imap' : 'none';
		}
		return $this->pgBool($row['iem_has_raw_headers'] ?? false) ? 'headers' : 'none';
	}

	/**
	 * Decrypt a getThread() row's sealed fields (docs/sealed_vault.md raw-row
	 * hook) — sender/subject/body_plain/body_html/ai_summary always; recipient only for an
	 * outbound row (InboundEmailMessage's $sealed_fields / decryptSealedFieldStatic
	 * apply the same direction check). A locked vault becomes a placeholder per
	 * field, never a thrown error into the reader.
	 *
	 * @return array<string,string> the same column names, decrypted (or unchanged)
	 */
	private function decryptThreadRow(array $row): array {
		$fields = array('iem_sender', 'iem_recipient', 'iem_bcc', 'iem_subject', 'iem_body_plain', 'iem_body_html', 'iem_ai_summary', 'iem_ai_scan');
		// A Fortress pending-parse row is sealed to the owner and not yet parsed —
		// its content columns are empty. Recipient stays cleartext metadata; the
		// content fields render the same placeholder as a locked sealed row.
		$pending = $this->pgBool($row['iem_pending_parse'] ?? false);
		$content_fields = array('iem_sender' => true, 'iem_subject' => true,
			'iem_body_plain' => true, 'iem_body_html' => true, 'iem_ai_summary' => true,
			'iem_ai_scan' => true);
		$out = array();
		foreach ($fields as $col) {
			if ($pending && isset($content_fields[$col])) {
				$out[$col] = self::SEALED_PLACEHOLDER;
				$this->content_locked = true;
				continue;
			}
			$value = $row[$col];
			if ($value !== null && $value !== '') {
				try {
					$value = InboundEmailMessage::decryptSealedFieldStatic($col, $value, $row);
				} catch (VaultLockedException $e) {
					$value = self::SEALED_PLACEHOLDER;
					$this->content_locked = true;
				} catch (Throwable $e) {
					// A column that will not open — a damaged blob, or one left
					// plaintext under a sealed flag by a rogue write path. The
					// thread list already survives this per column
					// (fetchAndDecryptContent); opening the conversation has to as
					// well, or one bad column makes the whole thread unreadable
					// while the row beside it renders fine. Renders as unreadable
					// and says so in the log, naming the message and column.
					// content_locked stays down: it drives the reader's "unlock
					// your vault" banner, and no unlock fixes a damaged column
					// (specs/bugfix_promoted_sent_row_sealing.md).
					error_log('MailboxService: could not read ' . $col . ' on message '
						. intval($row['iem_inbound_email_message_id'] ?? 0) . ': ' . $e->getMessage());
					$value = self::SEALED_PLACEHOLDER;
				}
			}
			$out[$col] = (string)$value;
		}
		return $out;
	}

	/**
	 * Raster image types the reader will show in the preview modal. SVG is
	 * deliberately absent: it is markup wearing an image's name, and it already
	 * previews as text through the extractor, which is the safer answer.
	 */
	const PREVIEW_IMAGE_MIMES = array(
		'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
	);

	/** Extensions that name one of those, for the same reason the text side
	 *  consults the filename: senders declare octet-stream constantly. */
	const PREVIEW_IMAGE_EXTENSIONS = array(
		'png' => 'image/png',  'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',  'webp' => 'image/webp', 'avif' => 'image/avif',
		'bmp' => 'image/bmp',
	);

	/**
	 * What Preview can offer for one attachment: 'text', 'image', or null.
	 *
	 * A UI HINT ONLY. It draws a button; it never decides what anything is
	 * handed. The text path re-sniffs the bytes inside its sandbox. The image
	 * path fetches them through the gated download endpoint and gives them an
	 * image type in the browser, so a sender's declared type never decides how
	 * the response is treated and a file that is not a picture simply fails to
	 * decode.
	 *
	 * A picture is the one preview that is not extraction — there is no text in
	 * it to pull out. Showing it here is still the smaller exposure than the
	 * alternative, which is downloading it and opening it on your own computer;
	 * the modal says plainly that this one is decoded as an image.
	 */
	public static function previewKind($declared, ?string $filename): ?string {
		if (DocumentText::canPreview($declared, $filename)) {
			return 'text';
		}
		$mime = DocumentText::normalizeMime($declared);
		if (in_array($mime, self::PREVIEW_IMAGE_MIMES, true)) {
			return 'image';
		}
		$ext = strtolower((string)pathinfo((string)$filename, PATHINFO_EXTENSION));
		return isset(self::PREVIEW_IMAGE_EXTENSIONS[$ext]) ? 'image' : null;
	}

	/**
	 * The non-inline attachment manifest for a set of messages, grouped by message
	 * id — what the reader lists below each message. Each entry carries what a
	 * download chip needs: the manifest id (the download endpoint's key), filename,
	 * content type, size, and which kind of preview (if any) to offer.
	 * Inline (cid:) parts are excluded (they belong to the HTML body). Bytes are
	 * never loaded here — the chip links to the gated per-attachment download
	 * endpoint.
	 *
	 * @param int[] $message_ids
	 * @return array<int, array<int, array>>  message_id => [ {id, filename, content_type, size_bytes, preview_kind}, ... ]
	 */
	private function attachmentsForMessages(array $message_ids): array {
		if (!count($message_ids)) {
			return array();
		}
		$in = implode(',', array_map('intval', $message_ids));
		$db = $this->db();
		$sql = "SELECT ima_inbound_message_attachment_id, ima_iem_inbound_email_message_id,
					ima_filename, ima_content_type, ima_size_bytes
				FROM ima_inbound_message_attachments
				WHERE ima_iem_inbound_email_message_id IN ($in) AND ima_is_inline = false
				ORDER BY ima_inbound_message_attachment_id ASC";
		$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

		$by_msg = array();
		foreach ($rows as $r) {
			$mid = intval($r['ima_iem_inbound_email_message_id']);
			$filename = $r['ima_filename'] ?: 'attachment';
			$declared = $r['ima_content_type'] ?: 'application/octet-stream';
			$by_msg[$mid][] = array(
				'id'           => intval($r['ima_inbound_message_attachment_id']),
				'filename'     => $filename,
				'content_type' => $declared,
				'size_bytes'   => intval($r['ima_size_bytes']),
				// What the Preview button offers, if anything: 'text', 'image',
				// or null. See previewKind().
				'preview_kind' => self::previewKind($declared, $filename),
			);
		}
		return $by_msg;
	}

	/**
	 * Which of the given messages carry at least one real attachment, as a set
	 * keyed by message id. Same rule as attachmentsForMessages() — inline (cid:)
	 * parts belong to the HTML body and are not attachments a reader would look
	 * for. Ids only: the thread list needs to know whether to draw a paperclip,
	 * not what the files are.
	 *
	 * @param int[] $message_ids
	 * @return array<int, true>
	 */
	private function messageIdsWithAttachments(array $message_ids): array {
		if (!count($message_ids)) {
			return array();
		}
		$in = implode(',', array_map('intval', $message_ids));
		$sql = "SELECT DISTINCT ima_iem_inbound_email_message_id AS mid
				FROM ima_inbound_message_attachments
				WHERE ima_iem_inbound_email_message_id IN ($in) AND ima_is_inline = false";
		$out = array();
		foreach ($this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[intval($r['mid'])] = true;
		}
		return $out;
	}

	/**
	 * Enrich a getThread() payload for sessionless clients (the native mail
	 * screens): every file-backed attachment gains a short-lived signed
	 * download URL ('url', absolute), and each HTML body has its cid:
	 * references rewritten via resolveInlineImages(). Minting is the
	 * authorization statement — this runs only on a payload getThread()
	 * already scope-checked. Attachments whose bytes are not a private File
	 * (IMAP on-demand / raw-section parts) get url=null; they stream only
	 * through the sessioned member endpoint.
	 *
	 * @param array $messages getThread() rows
	 * @param int   $ttl_seconds Signed-URL lifetime
	 * @return array The same rows, enriched
	 */
	public function withSignedTransport(array $messages, int $ttl_seconds = 900): array {
		$messages = self::resolveInlineImages($messages, $ttl_seconds);

		$ids = array();
		foreach ($messages as $m) {
			$ids[] = intval($m['id']);
		}
		if (!count($ids)) {
			return $messages;
		}
		require_once(PathHelper::getIncludePath('data/files_class.php'));

		// getThread()'s manifest lists non-inline parts only; inline parts ride
		// inside the body, rewritten by resolveInlineImages() above.
		$in = implode(',', array_map('intval', $ids));
		$sql = "SELECT ima_inbound_message_attachment_id, ima_fil_file_id, ima_is_sealed,
					ima_iem_inbound_email_message_id
				FROM ima_inbound_message_attachments
				WHERE ima_iem_inbound_email_message_id IN ($in)
					AND ima_is_inline = false AND ima_fil_file_id IS NOT NULL";
		$rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

		$signed_by_att = array();   // attachment id => signed url
		foreach ($rows as $r) {
			$file = new File(intval($r['ima_fil_file_id']), TRUE);
			if (!$file->key || $file->get('fil_delete_time')) {
				continue;
			}
			// Sealed attachments get the same decryption grant inline images do,
			// which is what makes the URL work for a sessionless native client
			// (specs/bugfix_sealed_inline_images.md).
			$signed_by_att[intval($r['ima_inbound_message_attachment_id'])] =
				$file->mintSignedUrl('original', $ttl_seconds, 'full')
				. self::serveGrantParam($file, $r['ima_is_sealed'],
					intval($r['ima_iem_inbound_email_message_id']), $ttl_seconds);
		}

		foreach ($messages as &$m) {
			foreach ($m['attachments'] as &$att) {
				$att['url'] = $signed_by_att[intval($att['id'])] ?? null;
			}
			unset($att);
		}
		unset($m);
		return $messages;
	}

	/**
	 * Signed-URL lifetime for inline images in the web readers, which mint once
	 * per page/thread open and don't refresh while the page sits idle. A link
	 * that outlives this renders broken until the message is reopened (which
	 * mints fresh ones) — the accepted trade-off of a self-authorizing URL.
	 */
	const INLINE_IMAGE_TTL = 3600;

	/**
	 * Rewrite each message's cid: references to short-lived signed URLs
	 * (docs/file_signed_urls.md) for that message's inline file-backed parts.
	 * The only cid-rewrite implementation — shared by the web thread endpoint,
	 * the admin single-message page, and withSignedTransport().
	 *
	 * Always signed, never session-gated: the bodies render inside a
	 * sandbox="" iframe whose opaque origin sends no cookies, and mail
	 * visibility is a mailbox-grant decision that File::is_viewable()
	 * (owner-or-admin) cannot express — so a gated /uploads URL can never
	 * authorize this content for any reader. Minting is the authorization
	 * statement: callers run this only on messages they have already
	 * scope-checked (MailboxViewer, or the admin permission gate).
	 *
	 * @param array $messages Rows each carrying 'id' and 'body_html'
	 * @param int   $ttl_seconds Signed-URL lifetime
	 * @return array The same rows, bodies rewritten
	 */
	public static function resolveInlineImages(array $messages, int $ttl_seconds = self::INLINE_IMAGE_TTL): array {
		$ids = array();
		foreach ($messages as $m) {
			if ((string)($m['body_html'] ?? '') !== '') {
				$ids[] = intval($m['id']);
			}
		}
		if (!count($ids)) {
			return $messages;
		}
		require_once(PathHelper::getIncludePath('data/files_class.php'));

		$in = implode(',', array_map('intval', $ids));
		$sql = "SELECT ima_iem_inbound_email_message_id, ima_content_id, ima_fil_file_id, ima_is_sealed
					FROM ima_inbound_message_attachments
					WHERE ima_iem_inbound_email_message_id IN ($in)
						AND ima_is_inline = true AND ima_fil_file_id IS NOT NULL";
		$rows = DbConnector::get_instance()->get_db_link()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

		$cids_by_msg = array();   // message id => [content id => signed url]
		foreach ($rows as $r) {
			$cid = trim((string)$r['ima_content_id'], " \t<>");
			if ($cid === '') {
				continue;
			}
			$file = new File(intval($r['ima_fil_file_id']), TRUE);
			if (!$file->key || $file->get('fil_delete_time')) {
				continue;
			}
			$url = $file->mintSignedUrl('original', $ttl_seconds, 'full');
			// Sealed bytes need a decryption grant beside the signature: the
			// reader's sandbox iframe sends no cookies, so the serve path can
			// never see this caller's window (specs/bugfix_sealed_inline_images.md).
			$url .= self::serveGrantParam($file, $r['ima_is_sealed'],
				intval($r['ima_iem_inbound_email_message_id']), $ttl_seconds);
			$cids_by_msg[intval($r['ima_iem_inbound_email_message_id'])][$cid] = $url;
		}

		foreach ($messages as &$m) {
			$mid = intval($m['id']);
			if (empty($cids_by_msg[$mid]) || (string)($m['body_html'] ?? '') === '') {
				continue;
			}
			$map = $cids_by_msg[$mid];
			$m['body_html'] = preg_replace_callback(
				'/cid:([^"\'\s>]+)/i',
				function ($matches) use ($map) {
					$id = trim(rawurldecode($matches[1]), '<>');
					return isset($map[$id]) ? $map[$id] : $matches[0];
				},
				(string)$m['body_html']
			);
		}
		unset($m);
		return $messages;
	}

	/**
	 * The '&grant=…' suffix for a signed URL to a SEALED attachment file, or ''
	 * (a plaintext file, or no open window to resolve the key with).
	 *
	 * Minting is the authorization statement, extended to decryption
	 * (specs/bugfix_sealed_inline_images.md): this runs only where signed URLs
	 * are minted — on messages the caller already scope-checked, in a request
	 * whose window just decrypted the bodies — and it resolves the content key
	 * through that same window. The key goes into FileServeGrant's server-side
	 * store; only the random token rides the URL. A closed window mints no
	 * grant and the URL serves 423 exactly as before — the reader is showing
	 * sealed placeholders in that state anyway.
	 *
	 * Two sealed shapes, same dispatch order as openSealedAttachment():
	 * a self-sealed container File carries its own key; otherwise a sealed
	 * manifest row's bytes open under the owning message's DEK.
	 */
	private static function serveGrantParam(File $file, $ima_is_sealed, int $message_id, int $ttl_seconds): string {
		static $dek_cache = array();   // message id => DEK|false, per request

		$shape = null;
		$key = null;
		if ($file->get('fil_content_sealed')) {
			try {
				$key = DriveSealed::fileKey($file);
				$shape = FileServeGrant::SHAPE_FILE_KEY;
			} catch (VaultLockedException $e) {
				return '';
			} catch (Throwable $e) {
				error_log('MailboxService: could not resolve the sealed key for file '
					. intval($file->key) . ': ' . $e->getMessage());
				return '';
			}
		} elseif ($ima_is_sealed === true || $ima_is_sealed === 't' || $ima_is_sealed === '1' || $ima_is_sealed === 1) {
			if (!array_key_exists($message_id, $dek_cache)) {
				$dek_cache[$message_id] = false;
				$msg = new InboundEmailMessage($message_id, TRUE);
				$owner = $msg->key ? InboundEmailMessage::sealedOwnerFor($msg) : null;
				$sealed_key = $msg->key ? (string)$msg->get('iem_sealed_key') : '';
				if ($owner !== null && $sealed_key !== '') {
					$dek = InboundEmailMessage::unwrapDekInWindow($owner, $sealed_key);
					if ($dek !== null) {
						$dek_cache[$message_id] = $dek;
					}
				}
			}
			if ($dek_cache[$message_id] === false) {
				return '';
			}
			$key = $dek_cache[$message_id];
			$shape = FileServeGrant::SHAPE_MESSAGE_DEK;
		} else {
			return ''; // plaintext bytes — the signature alone serves them
		}

		$token = FileServeGrant::mint(intval($file->key), 'original', $shape, $key, $ttl_seconds);
		return $token !== null ? '&grant=' . $token : '';
	}

	/**
	 * Resolve a thread key to its in-scope message ids — the only
	 * thread-expansion logic, reused by every thread-level action.
	 *
	 * $trashed swaps the read scope for the Trash view's, so a discarded
	 * conversation expands for restore / delete-forever and for nothing else.
	 *
	 * @return int[]
	 */
	public function messageIdsInThread(?int $aliasId, string $thread_key, bool $trashed = false): array {
		$db = $this->db();
		$scope = $trashed ? $this->trashScopeSql($aliasId) : $this->readScopeSql($aliasId);

		// Synthetic singleton key (m:<id>) → that one message, if in scope.
		if (strncmp($thread_key, self::SINGLETON_PREFIX, strlen(self::SINGLETON_PREFIX)) === 0) {
			$mid = intval(substr($thread_key, strlen(self::SINGLETON_PREFIX)));
			if ($mid <= 0) {
				return array();
			}
			$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE iem_inbound_email_message_id = ? AND $scope";
			$stmt = $db->prepare($sql);
			$stmt->execute(array($mid));
		} else {
			$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE iem_thread_key = ? AND $scope";
			$stmt = $db->prepare($sql);
			$stmt->execute(array($thread_key));
		}

		$ids = array();
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$ids[] = intval($id);
		}
		return $ids;
	}

	// ------------------------------------------------------------ mutations

	/**
	 * Mark in-scope rows read/unread. Sets iem_read_time on first read.
	 * @return int rows affected
	 */
	public function markRead(array $message_ids, bool $read): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		// Stamp the flag-dirty signal so two-way sync pushes the change (§7.1); inert
		// for non-IMAP rows, which are never synced.
		if ($read) {
			$sql = "UPDATE iem_inbound_email_messages
					SET iem_is_read = true, iem_read_time = COALESCE(iem_read_time, now()),
						iem_local_state_modified = now()
					WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		} else {
			$sql = "UPDATE iem_inbound_email_messages
					SET iem_is_read = false, iem_local_state_modified = now()
					WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		}
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Star/unstar in-scope rows.
	 * @return int rows affected
	 */
	public function setStarred(array $message_ids, bool $starred): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_is_starred = " . ($starred ? 'true' : 'false') . ",
					iem_local_state_modified = now()
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Archive ("Skip the Inbox") or restore in-scope rows
	 * (specs/implemented/inbound_email_filters.md). Archived mail is hidden from the
	 * default Inbox view but stays in All Mail; orthogonal to read/star/spam.
	 * @return int rows affected
	 */
	public function setArchived(array $message_ids, bool $archived): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_is_archived = " . ($archived ? 'true' : 'false') . ",
					iem_local_state_modified = now()
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Manual spam correction (specs/inbound_email_spam_filtering.md): set the verdict
	 * on in-scope rows — 'spam' (Mark as spam) moves them to the Spam view, 'ham'
	 * (Not spam) returns them to the inbox. Rejects any other value.
	 * @return int rows affected
	 */
	public function setSpamVerdict(array $message_ids, string $verdict): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		if (!in_array($verdict, array(
				InboundEmailMessage::SPAM_VERDICT_SPAM,
				InboundEmailMessage::SPAM_VERDICT_HAM), true)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_spam_verdict = " . $this->db()->quote($verdict) . "
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Soft-delete in-scope rows (hidden from the list; the retention purge task
	 * hard-deletes later).
	 * @return int rows affected
	 */
	public function softDelete(array $message_ids): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_delete_time = now()
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		$affected = $stmt->rowCount();

		// The soft-delete column is the truth; two-way sync's pushTrash relocates the
		// source message to its Trash folder (§7.5). No membership bridge is needed —
		// trashing is column-driven, not a label.

		return $affected;
	}

	/**
	 * Take in-scope messages back out of Trash (specs/mailbox_trash_folder.md).
	 * Clearing the column is the whole restore: read, star, archive, spam verdict
	 * and label membership were never touched by trashing, so the message returns
	 * exactly as it left. Nothing to do for the search index either — it holds
	 * every stored message and the read scope decides what a search returns.
	 *
	 * An IMAP-backed message returns here while the source copy stays in the
	 * provider's own Trash; the reader says so in the Trash view.
	 *
	 * @return int rows affected
	 */
	public function restoreFromTrash(array $message_ids): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_delete_time = NULL, iem_local_state_modified = now()
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->trashMutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Delete in-scope trashed messages for good — the reader's "Delete forever"
	 * and what the retention sweep does on a timer.
	 *
	 * Row by row through the model, never a bulk DELETE: permanent_delete()
	 * reclaims the attachment Files and the stored raw object, and raw SQL would
	 * drop the row and leak both. Each id is enqueued for refold first, so the
	 * owner's sealed search index drops the entry at their next fold.
	 *
	 * @return int messages deleted
	 */
	public function purgeFromTrash(array $message_ids): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$rows = $this->db()->query(
			"SELECT iem_inbound_email_message_id AS id, iem_iea_inbound_email_alias_id AS alias_id
			 FROM iem_inbound_email_messages
			 WHERE iem_inbound_email_message_id IN ($in) AND " . $this->trashMutationScopeSql())
			->fetchAll(PDO::FETCH_ASSOC);

		$count = 0;
		foreach ($rows as $row) {
			$mid = intval($row['id']);
			$alias_id = intval($row['alias_id']);
			if ($alias_id > 0) {
				MailboxIndex::enqueueRefold($alias_id, $mid);
			}
			$msg = new InboundEmailMessage($mid, TRUE);
			if (!$msg->key) {
				continue;
			}
			$msg->permanent_delete();
			$count++;
		}
		return $count;
	}

	// --------------------------------------------------------------- helpers

	private function intList(array $ids): array {
		$out = array();
		foreach ($ids as $id) {
			$id = intval($id);
			if ($id > 0) {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}

	/** Normalize a PDO-returned boolean (PostgreSQL yields 't'/'f' or bool). */
	private function pgBool($value): bool {
		if (is_bool($value)) {
			return $value;
		}
		return ($value === 't' || $value === 'true' || $value === '1' || $value === 1 || $value === true);
	}

	/** Decode iem_ai_scan (jsonb, PDO returns it as a JSON string) to
	 *  {verdict, red_flags, summary, model, recipe_id}, or null when unset. */
	private static function decodeScan($raw): ?array {
		if ($raw === null || $raw === '') return null;
		$decoded = json_decode((string)$raw, true);
		return is_array($decoded) ? $decoded : null;
	}
}
?>
