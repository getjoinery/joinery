<?php
/**
 * The hot-turn rule — Layer 2 of specs/implemented/sealed_content_egress.md.
 *
 * The promise a protected mailbox makes is that its content cannot be read
 * without the owner's key. Everything that reads such content correctly can
 * still break that promise the moment it writes what it read somewhere else:
 * a run log, a memory row, a note, a cached API response, an outgoing email.
 * The audit behind the spec found that pattern in a dozen places, written by
 * people who were not being careless — nothing in the platform made the safe
 * thing easier than the copy.
 *
 * So the rule is not per-sink. It is one rule at one anchor:
 *
 *   Once a process has actually opened sealed content, any long string it
 *   writes to the database must land somewhere that seals.
 *
 * Two states, one flag on the process:
 *
 *   cold  — nothing sealed has been opened. Every write pays one boolean
 *           check and proceeds. This is virtually every request.
 *   hot   — VaultCrypto::openField() has handed out a plaintext. From then
 *           on, an INSERT or UPDATE carrying a string longer than THRESHOLD
 *           must either be writing ciphertext already, or be updating a row
 *           that is sealed to the same owner whose scope was opened.
 *           Anything else throws SealedContentEgressException.
 *
 * Deliberately coarse. There is no string matching, so it cannot be defeated
 * by paraphrase — and paraphrase is the majority leak class: an AI summary of
 * a sealed body shares no substring with it, so no matcher could ever have
 * seen it. The cost of the coarseness is false positives on a hot process
 * writing unrelated long strings; per resolved decision 8 those are fixed at
 * the write site (store a reference, cap the value, or make the destination
 * sealable), never by exempting a table. There is no table exemption
 * mechanism, on purpose: the audit found no legitimate case for one, and it
 * would exist only to be misused.
 *
 * Owner attribution comes from VaultUnlock::secretKey(), which is the only
 * way to reach a DEK and therefore runs before any open. When exactly one
 * owner's scope has been opened, that owner is the one a destination row must
 * be sealed to. When more than one has, no single owner can be named and the
 * only writes that pass are ciphertext ones.
 *
 * The counterpart at the SMTP boundary is assertSendAllowed(): mail is an
 * unencrypted channel, so a hot process may only send a message its call site
 * explicitly declares content-free.
 *
 * This is the confidentiality twin of joinery_ai's TaintGate, which tracks
 * injection provenance through the same predicate-at-a-choke-point shape.
 *
 * Egress has a second, durable arm: restrictEgress()/egressGated(). A per-turn
 * hot flag is the right proxy for the write-guard (this process holds
 * plaintext) but the wrong one for outbound egress across turns — a chat
 * conversation's transcript carries sealed-derived context into later,
 * possibly cold, processes. The egress-approval gate therefore asks
 * egressGated() (hot OR a caller-declared durable restriction), while the
 * write-guard keeps asking isHot() alone.
 *
 * @version 1.1
 */

if (!class_exists('SealedContentEgressException', false)) {
	/**
	 * A hot process tried to write sealed-derived content somewhere that
	 * cannot protect it. The message names the destination and the scope that
	 * made the process hot, because those two facts are the whole fix.
	 */
	class SealedContentEgressException extends RuntimeException {}
}

class SealedEgressGuard {

	/**
	 * Values at or below this length pass while hot. The residual gap is any
	 * copy shorter than this; accepted in resolved decision 8 because the
	 * surfaces that actually carry short sealed content (subjects in run rows,
	 * summaries on message rows) are sealed structurally by Layer 1. Recalibrate
	 * only from dev estate evidence, never to quiet one write site.
	 */
	const THRESHOLD = 64;

	/**
	 * The blob prefixes SealedBox stamps: content sealed under a per-item DEK,
	 * and the DEK itself sealed to a vault public key. Both are already
	 * protected, so both pass — writing a sealed DEK is what sealColumns() does
	 * on every seal, and it is longer than the threshold.
	 */
	const SEALED_PREFIXES = array('v1.aead.', 'v1.seal.');

	/** How many distinct source descriptions an exception message reports. */
	const SOURCES_REPORTED = 5;

	/**
	 * The only claims that let mail leave a hot process. Kept here rather than
	 * read off EmailSender so the guard does not depend on the thing it guards;
	 * the constants live on EmailSender because that is where call sites reach
	 * for them, and tests/vault/sealed_egress_guard_test.php pins the pair.
	 */
	const SEND_ASSERTIONS = array('content-free', 'user-compose', 'acknowledged-forward');

	/** @var bool has this process opened sealed content? */
	private static $hot = false;

	/**
	 * @var bool is outbound egress restricted for a reason OTHER than this
	 * process being hot? Set by a caller that knows the surrounding context is
	 * sealed-derived and durable even though this particular process may be cold
	 * — the case a chat conversation makes when an earlier turn (a different
	 * process) opened sealed content and left it in the transcript. Deliberately
	 * separate from $hot: arming egress restriction must NOT arm the write-guard,
	 * or a standard conversation's ordinary plaintext writes would start being
	 * refused on a turn that only inherits restriction from its history.
	 */
	private static $egress_restricted = false;

	/** @var array<int,bool> user ids whose vault scope has been opened */
	private static $scope_owners = array();

	/** @var array<string,bool> descriptions of what was opened, for the error message */
	private static $sources = array();

	/** @var bool master switch — off only while a test drives the rule directly */
	private static $armed = true;

	/** @var array<string,array<string,string>>|null table => [flag, owner] seal columns */
	private static $seal_columns = null;

	// ---------------------------------------------------------------- state

	/**
	 * Called by VaultUnlock::secretKey() every time a vault secret is handed
	 * out. This does NOT make the process hot: fetching a key is not reading
	 * content, and the enrolment and rotation paths legitimately do it. It only
	 * records who could be read, so that if an open follows there is an owner
	 * to name.
	 */
	public static function noteScopeOpened(int $user_id, string $scope = 'user'): void {
		if ($user_id > 0) {
			self::$scope_owners[$user_id] = true;
		}
	}

	/**
	 * Called by VaultCrypto::openField() the moment a sealed plaintext exists
	 * in this process. Sticky for the life of the process (one web request, one
	 * CLI run): there is no way to prove the plaintext stopped being reachable,
	 * so the flag never clears.
	 */
	public static function markHot(string $source = ''): void {
		self::$hot = true;
		if ($source !== '' && count(self::$sources) < 50) {
			self::$sources[$source] = true;
		}
	}

	public static function isHot(): bool {
		return self::$hot && self::$armed;
	}

	/**
	 * Declare that outbound egress is restricted for this process for a durable,
	 * context-level reason — not because the process is hot. The one caller is
	 * interactive chat: when a conversation carries the durable "has opened
	 * sealed content" mark, it arms this at the start of every later turn so a
	 * fresh (cold) process cannot fetch inline using sealed-derived context that
	 * is sitting in the transcript. Does NOT set $hot, so the write-guard is
	 * untouched and ordinary plaintext writes still pass.
	 */
	public static function restrictEgress(string $source = ''): void {
		self::$egress_restricted = true;
		if ($source !== '' && count(self::$sources) < 50) {
			self::$sources[$source] = true;
		}
	}

	public static function isEgressRestricted(): bool {
		return self::$egress_restricted && self::$armed;
	}

	/**
	 * The predicate the egress-approval gate asks: is sealed content in play for
	 * outbound purposes, for EITHER reason — this process opened it (isHot), or a
	 * caller declared the surrounding context sealed-derived (restrictEgress)?
	 * The write-guard keeps asking isHot() alone; only egress consults this.
	 */
	public static function egressGated(): bool {
		return self::isHot() || self::isEgressRestricted();
	}

	/**
	 * The single owner whose content this process has opened, or null when
	 * none or several have been. Several means no destination row can be
	 * proven to protect the right person, so only ciphertext writes pass.
	 */
	public static function ownerUserId(): ?int {
		if (count(self::$scope_owners) !== 1) {
			return null;
		}
		return (int)array_key_first(self::$scope_owners);
	}

	/** @return string[] what this process opened, for diagnostics */
	public static function sources(): array {
		return array_keys(self::$sources);
	}

	/**
	 * Turn the rule off for the rest of the process. The ONLY supported caller
	 * is a test that needs to write fixtures after driving the rule; there is
	 * no production path to it and adding one would be the table-exemption
	 * mechanism resolved decision 8 refuses.
	 */
	public static function setArmed(bool $armed): void {
		self::$armed = $armed;
	}

	/**
	 * Run one independent unit of work with its own hot state, then restore
	 * whatever the caller had.
	 *
	 * This exists because "per process" is the wrong granularity for a worker
	 * that does several unrelated things in a row. One AI recipe run reads one
	 * mailbox and writes one run's trace, tally and verdicts; the next run in
	 * the same drain slice reads a different mailbox. Without a boundary, a
	 * protected run poisons every later run in the process — a standard
	 * mailbox's triage would start failing purely because a protected one
	 * happened to go first.
	 *
	 * The caller is asserting that nothing the unit decrypted is still in play
	 * when it returns. That is a real claim about the code, and it is only true
	 * where units genuinely do not share derived state — which is why there are
	 * very few callers and each one is worth arguing about. It is NOT a way to
	 * quiet a refusal: a refusal inside a unit is still a refusal, and wrapping
	 * a write site rather than a unit boundary defeats the whole rule.
	 *
	 * An outer hot state survives the unit, so nesting cannot be used to launder
	 * a process cold.
	 */
	public static function isolate(callable $unit) {
		$was_hot = self::$hot;
		$was_owners = self::$scope_owners;
		$was_sources = self::$sources;
		$was_restricted = self::$egress_restricted;
		self::$hot = false;
		self::$scope_owners = array();
		self::$sources = array();
		self::$egress_restricted = false;
		try {
			return $unit();
		} finally {
			self::$hot = $was_hot;
			self::$scope_owners = $was_owners;
			self::$sources = $was_sources;
			self::$egress_restricted = $was_restricted;
		}
	}

	/** Test-only: return the process to cold. */
	public static function reset(): void {
		self::$hot = false;
		self::$scope_owners = array();
		self::$sources = array();
		self::$egress_restricted = false;
		self::$armed = true;
	}

	// ----------------------------------------------------------- the rule

	/**
	 * The write guard, called from the PDO statement layer for every INSERT and
	 * UPDATE this process executes. Cold: returns immediately. Hot: throws
	 * unless every long string value is one the destination can protect.
	 *
	 * @param string $sql   the statement text
	 * @param array  $bound the values this statement will write, keyed as the SQL
	 *                      refers to them (':name', or 1-based positional index)
	 */
	public static function assertStatementAllowed(string $sql, array $bound): void {
		if (!self::isHot()) {
			return;
		}
		$target = self::writeTarget($sql);
		if ($target === null) {
			return; // not a write
		}
		if (!self::hasLongPlaintext($bound)) {
			return; // nothing worth protecting is being written
		}
		if (self::destinationProtectsOwner($sql, $target, $bound)) {
			return;
		}
		throw new SealedContentEgressException(self::refusalMessage($target));
	}

	/**
	 * The SMTP boundary. Mail is an unencrypted channel, so sealed content must
	 * not go through it at all — not even to the owner. A hot process may send
	 * only a message whose call site asserts it was built without sealed
	 * content; that assertion is a reviewed claim at one of a handful of sites
	 * (the sealed-run pointer emails, the acknowledged forward filter), not a
	 * blanket flag.
	 *
	 * Refusing the send is also what keeps hot content out of
	 * equ_queued_emails: a message that is never sent is never queued for retry.
	 */
	public static function assertSendAllowed(string $assertion, string $subject = ''): void {
		if (!self::isHot()) {
			return;
		}
		// Only the declared assertions count. Anything else — a typo, a stray
		// truthy value someone passed to make a send work — is no assertion at
		// all, and letting it through would turn a reviewed claim into a flag.
		if (in_array(trim($assertion), self::SEND_ASSERTIONS, true)) {
			return;
		}
		throw new SealedContentEgressException(
			'Refusing to send mail from a process that has opened sealed content'
			. ($subject === '' ? '' : ' (subject: ' . self::excerpt($subject) . ')') . '. '
			. 'Opened: ' . self::sourceSummary() . '. '
			. 'Mail is an unencrypted channel. Either build the message from unsealed data only and '
			. 'send it with EmailSender::EGRESS_CONTENT_FREE, send a link to where the content lives '
			. 'instead of the content, or — if the egress is the point and a person consented to it — '
			. 'name that consent with the matching EmailSender::EGRESS_* assertion.');
	}

	// ------------------------------------------------------------ internals

	/**
	 * The table an INSERT or UPDATE writes to, or null for anything else.
	 * Schema-qualified and quoted names are normalised to the bare table name,
	 * which is what information_schema reports.
	 */
	private static function writeTarget(string $sql): ?string {
		if (!preg_match('/^\s*(?:INSERT\s+INTO|UPDATE)\s+("?[A-Za-z0-9_]+"?(?:\."?[A-Za-z0-9_]+"?)?)/i',
				$sql, $m)) {
			return null;
		}
		$name = str_replace('"', '', $m[1]);
		$dot = strrpos($name, '.');
		return strtolower($dot === false ? $name : substr($name, $dot + 1));
	}

	/** Any bound string long enough to carry content, and not already sealed. */
	private static function hasLongPlaintext(array $values): bool {
		foreach ($values as $value) {
			if (!is_string($value)) continue;
			if (strlen($value) <= self::THRESHOLD) continue;
			if (self::isSealedBlob($value)) continue;
			return true;
		}
		return false;
	}

	/** True for anything SealedBox produced — already protected, so free to write. */
	public static function isSealedBlob(string $value): bool {
		foreach (self::SEALED_PREFIXES as $prefix) {
			if (strncmp($value, $prefix, strlen($prefix)) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when this statement updates a single existing row that is already
	 * sealed to the owner whose scope this process opened. That row protects
	 * whatever lands in it, including a column outside $sealed_fields, so the
	 * write is not an egress.
	 *
	 * Only the UPDATE-by-primary-key shape is recognised. An INSERT never
	 * qualifies: the AD binds a sealed value to its row id, so a row must exist
	 * before anything can be sealed into it — the Layer 0 contract is to insert
	 * the row with its content columns empty and then call sealColumns().
	 * Anything this cannot parse is refused, which is the fail-closed direction.
	 */
	private static function destinationProtectsOwner(string $sql, string $table, array $bound): bool {
		$owner_id = self::ownerUserId();
		if ($owner_id === null) {
			return false;
		}
		$cols = self::sealColumnsFor($table);
		if ($cols === null) {
			return false;
		}
		if (!preg_match('/^\s*UPDATE\b/i', $sql)) {
			return false;
		}
		// A single equality on the table's own id column, bound or literal.
		if (!preg_match('/\bWHERE\s+"?([A-Za-z0-9_]+)"?\s*=\s*(:[A-Za-z0-9_]+|\?|\d+)\s*$/i',
				rtrim($sql, "; \t\n\r"), $m)) {
			return false;
		}
		$where_column = strtolower($m[1]);
		if (substr($where_column, -3) !== '_id') {
			return false;
		}
		$row_id = self::resolvePlaceholder($sql, $m[2], $bound);
		if ($row_id === null) {
			return false;
		}

		try {
			$dblink = DbConnector::get_instance()->get_db_link();
			$stmt = $dblink->prepare(
				'SELECT ' . $cols['flag'] . ' AS f, ' . $cols['owner'] . ' AS o'
				. ' FROM ' . $table . ' WHERE ' . $where_column . ' = ?');
			$stmt->execute(array($row_id));
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			return false;
		}
		if (!$row) {
			return false;
		}
		$flag = $row['f'];
		$sealed = ($flag === true || $flag === 't' || $flag === 1 || $flag === '1');
		return $sealed && (int)$row['o'] === $owner_id;
	}

	/**
	 * The integer a WHERE-clause token stands for: an inline literal, a named
	 * placeholder, or a positional one (whose index is its ordinal among all the
	 * ? marks in the statement — and since the clause matched at the very end,
	 * that is simply how many there are). Null when it cannot be resolved, which
	 * refuses the write.
	 */
	private static function resolvePlaceholder(string $sql, string $token, array $bound): ?int {
		if (ctype_digit($token)) {
			return (int)$token;
		}
		if ($token === '?') {
			$position = substr_count($sql, '?');
			$value = $bound[$position] ?? null;
			return is_numeric($value) ? (int)$value : null;
		}
		$value = $bound[$token] ?? $bound[ltrim($token, ':')] ?? null;
		return is_numeric($value) ? (int)$value : null;
	}

	/**
	 * The seal flag and owner columns for a table, by the Layer 0 naming
	 * convention ({prefix}_content_sealed / {prefix}_sealed_owner_user_id), or
	 * null when the table has no sealing at all. Read from the live schema
	 * rather than a model registry so it holds for core and plugin tables alike
	 * without either knowing about this guard.
	 */
	private static function sealColumnsFor(string $table): ?array {
		if (self::$seal_columns === null) {
			self::$seal_columns = array();
			try {
				$tables = LibraryFunctions::get_tables_and_columns();
			} catch (Throwable $e) {
				$tables = array();
			}
			foreach ($tables as $name => $columns) {
				foreach ((array)$columns as $column) {
					if (substr((string)$column, -15) !== '_content_sealed') continue;
					$prefix = substr((string)$column, 0, -15);
					$owner = $prefix . '_sealed_owner_user_id';
					if (!in_array($owner, (array)$columns, true)) continue;
					self::$seal_columns[strtolower($name)] = array('flag' => $column, 'owner' => $owner);
				}
			}
		}
		return self::$seal_columns[$table] ?? null;
	}

	private static function refusalMessage(string $table): string {
		return 'Refusing to write sealed-derived content into ' . $table . '. '
			. 'This process opened sealed content (' . self::sourceSummary() . '), so anything longer '
			. 'than ' . self::THRESHOLD . ' characters it writes must land somewhere that protects it. '
			. 'Fix at the write site, in preference order: store a reference to the source instead of a '
			. 'copy of it; give ' . $table . ' the Layer 0 sealing columns and seal the value with '
			. 'sealColumns(); or do not write the content. See docs/sealed_vault.md.';
	}

	private static function sourceSummary(): string {
		$sources = self::sources();
		if (empty($sources)) {
			return 'source not recorded';
		}
		$shown = array_slice($sources, 0, self::SOURCES_REPORTED);
		$summary = implode(', ', $shown);
		if (count($sources) > count($shown)) {
			$summary .= ' and ' . (count($sources) - count($shown)) . ' more';
		}
		return $summary;
	}

	/** A short, non-revealing excerpt for an error message. */
	private static function excerpt(string $value): string {
		$value = preg_replace('/\s+/', ' ', $value);
		return strlen($value) > 40 ? substr($value, 0, 40) . '...' : $value;
	}
}
?>
