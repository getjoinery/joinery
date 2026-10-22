<?php
/**
 * MailboxContacts — the contact store service (specs/mailbox_compose_maturity.md § Phase 4):
 * add, import, list (for autocomplete + management), look up, edit, delete.
 *
 * A CONTACT IS A DELIBERATE ACT. The only two ways in are manualAdd() and import(); mail
 * traffic writes nothing here. Filing correspondents automatically would mean anyone who can
 * send you mail can put themselves in your address book, so the list fills with spam senders
 * and stops being usable for what it is for — offering the people you meant to write to.
 *
 * Every method is scoped to one user id AND one mailbox (alias id). A contact belongs to the
 * mailbox it was added to, so composing from a work mailbox never suggests an address kept
 * in a personal one; the same person on two mailboxes is two rows, which is fine because this
 * store is a cache. The mailbox scope rides in the address hash rather than in a separate key
 * column — see addressHash() — so the existing (hash, user) unique constraint already means one
 * row per (user, mailbox, address).
 *
 * A row is sealed when the adding user holds a Sealed Vault AND the mailbox seals content —
 * the SAME RULE AS THE MAIL BESIDE IT (`$alias->seals_content()`, the identical condition
 * InboundEmailRouter resolves at store time). Sealing an address book while the mail it describes sits
 * in plaintext would protect nothing: every correspondent is already visible in that plaintext
 * mail. So both hang off one posture switch — a Standard mailbox is server-readable end to end,
 * a Private mailbox seals end to end — and there is no "plaintext mail, sealed
 * contacts" mixed state.
 *
 * That is what makes the live contact gate possible at Standard: contacts are genuinely
 * readable there, so no locked vault can block it, and no lock-state oracle appears on the
 * Direct channel (docs/joinery_direct.md § Security tiers).
 *
 * An add always runs in-window (the user is right there doing it), so the plaintext address is
 * available to hash and seal. Sealing follows the USER within that posture, not the mailbox:
 * two grantees sharing one sealed mailbox each build their own contacts, each readable only by
 * them. The address hash is a keyed blind index for sealed rows (never leaks the sealed
 * address) and a plain SHA-256 otherwise; the key is the user's own random index key,
 * sealed to their vault (MailboxContactIndexKey) and opened in-window like any row DEK, so
 * a vault rotation moves its wrapping and every hash survives.
 *
 * @version 2.8 - export: toVcard() and toGoogleCsv(), which import() reads back as written. The CSV is read
 *   as whole records (a quoted line break no longer splits a row) and takes First/Last Name; the vCard is
 *   unfolded and unescaped first; the parsers hand names over apart from addresses, so a name holding
 *   quotes or angle brackets survives; listForMailbox() can read every row. vCard import reads a quoted
 *   parameter holding a colon, a byte order mark, 2.1's quoted-printable values and CHARSET, and takes N
 *   when FN is missing or is one of the card's addresses; CSV import reads Outlook's E-mail Address
 *   columns and no other e-mail column beside them, and Yahoo's First / Middle / Last. Text that is
 *   not UTF-8 is read as Windows-1252. import() counts what was stored: one failed row no longer drops
 *   the rows after it, and a sealed store with the window shut returns locked
 * @version 2.7 - updateContact(): change a contact's name or address; manualAdd() takes the name
 *   apart from the address
 * @version 2.6 - every add writes imc_sender_fingerprint, the spam filter's contact signal
 * @version 2.5 - forgetPosture(): a level flip in the same request drops the memoized posture
 * @version 2.4.1 - comment wording: Private plus the relay-sealing and sending-lock add-ons
 * @version 2.4
 * @changelog 2.4 - the blind index is keyed by the user's sealed index key
 *   (MailboxContactIndexKey), opened through the VaultKey; nothing here ever
 *   holds the vault secret
 * @changelog 2.3 - the posture question follows the MAILBOX, and an unreadable
 *   one fails toward sealed rather than toward plaintext
 * @changelog 2.2 - listForUser(): the user's whole contact store for surfaces not scoped to one mailbox (Messenger people picker)
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contact_index_keys_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));

class MailboxContacts {

	/** Max contacts returned to the client (the whole list is small; guard runaways). */
	const MAX_LIST = 2000;

	private function db() {
		return DbConnector::get_instance()->get_db_link();
	}

	/** @var array<int,bool> request-scoped: does this mailbox's domain seal content? */
	private static $seals_cache = array();

	/** Forget the memoized posture — a level flip in this request changed the answer. */
	public static function forgetPosture(): void {
		self::$seals_cache = array();
	}

	/**
	 * The vault a contact row on this mailbox seals under, or null when rows
	 * here are plaintext.
	 *
	 * Two conditions, and both are required: the user must HOLD a vault, and the
	 * mailbox's domain must be one that seals content (Private).
	 * Vault possession alone is not enough — a vault holder reading a Standard
	 * mailbox stores contacts exactly as a user with no vault does, because the
	 * mail beside them is plaintext and sealing the address book would secure
	 * nothing while costing the live gate its readability.
	 */
	private function sealingVault(int $user_id, int $alias_id): ?UserEncryptionVault {
		if (!$this->mailboxSealsContent($alias_id)) {
			return null;
		}
		return UserEncryptionVault::loadForUser($user_id);
	}

	/** Does the domain behind this mailbox seal stored content? */
	private function mailboxSealsContent(int $alias_id): bool {
		if ($alias_id <= 0) {
			return false;
		}
		if (array_key_exists($alias_id, self::$seals_cache)) {
			return self::$seals_cache[$alias_id];
		}
		// The MAILBOX's posture, which is its own level where it has one and the
		// domain's otherwise (specs/mailbox_connect_flow.md § D) — the same
		// question the mail beside these contacts asks.
		try {
			$alias = new InboundEmailAlias($alias_id, TRUE);
			$seals = $alias->key ? $alias->seals_content() : false;
		} catch (\Throwable $e) {
			// Fail toward the sealed path. An unreadable posture is not a licence
			// to store plaintext where ciphertext was expected; the callers' own
			// guards decide what to do with a mailbox nothing can be filed against.
			error_log('MailboxContacts: could not read the posture of mailbox ' . $alias_id . ': ' . $e->getMessage());
			$seals = true;
		}
		return self::$seals_cache[$alias_id] = $seals;
	}

	/**
	 * The dedup digest for a normalized address ON ONE MAILBOX. For a vault holder it is
	 * a keyed HMAC (blind index) under the user's index key (indexKey()), so DB access
	 * alone can't recover the sealed address; for a user with no vault it is a plain
	 * SHA-256 (the address column is plaintext anyway).
	 *
	 * The mailbox is hashed alongside the address so that the same address on two
	 * mailboxes digests differently — which is what lets the (hash, user) unique
	 * constraint enforce one row per (user, mailbox, address) without a composite key
	 * over an encrypted column.
	 */
	public function addressHash(string $normalized, ?string $index_key, int $alias_id): string {
		$material = $alias_id . ':' . $normalized;
		if ($index_key !== null) {
			return hash_hmac('sha256', $material, $index_key); // 64 hex chars
		}
		return hash('sha256', 'joinery:contact:' . $material);
	}

	/**
	 * The user's contact-index key for a sealed store, or null when the store is
	 * plaintext ($vault null). Needs the window: returns false when it is closed,
	 * which every caller reports as locked rather than absent.
	 *
	 * @return array{index_key: ?string, key: ?VaultKey}|false
	 */
	private function indexKey(int $user_id, ?UserEncryptionVault $vault) {
		if ($vault === null) {
			return array('index_key' => null, 'key' => null);
		}
		$key = VaultUnlock::secretKey($user_id);
		if ($key === null) {
			return false;
		}
		return array('index_key' => MailboxContactIndexKey::openForUser($user_id, $vault, $key), 'key' => $key);
	}

	/**
	 * Normalize a raw "Name <email>" / bare-address token to [address, name] with a
	 * lowercased, trimmed, validated address — or null when there is no valid email.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function parseAddress(string $raw): ?array {
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		$name = '';
		if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $raw, $m)) {
			$name = trim($m[1]);
			$addr = trim($m[2]);
		} else {
			$addr = $raw;
		}
		$addr = strtolower(trim($addr));
		if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
			return null;
		}
		return array($addr, $name);
	}

	// ── write ────────────────────────────────────────────────────────────────

	/**
	 * Upsert a batch of addresses for a user ON ONE MAILBOX (best-effort; never throws
	 * into the caller). Each existing contact has its use_count and last_used bumped; a
	 * new one is inserted (sealed when the user holds a vault). Each entry is
	 * [address, name]: the address raw ("Name <email>" or bare), and a name that, when
	 * not empty, is kept as given instead of the one parsed out of the address.
	 *
	 * Returns how many entries landed (an entry whose address repeats an earlier one
	 * lands with it), or null when the mailbox seals and the vault window is closed, so
	 * nothing could. A row whose write fails is logged and skipped; the rest still land.
	 *
	 * PRIVATE ON PURPOSE. The public writers are manualAdd() and import(), both of which
	 * are a user deciding to keep an address. Exposing this again is how mail traffic
	 * would start filing contacts by itself.
	 *
	 * A write with no mailbox to attribute to is dropped rather than stored unscoped:
	 * an unscoped row would be invisible to every mailbox-scoped read, so writing one
	 * only grows the table.
	 *
	 * @param array<int, array{0:string, 1:string}> $entries
	 */
	private function upsertBatch(int $user_id, array $entries, string $source, int $alias_id): ?int {
		if ($user_id <= 0 || $alias_id <= 0 || !count($entries)) {
			return 0;
		}
		try {
			$vault = $this->sealingVault($user_id, $alias_id);
			// A sealed mailbox whose window is closed: can't compute the keyed hash or
			// read for dedup, so there is nowhere to put the address. The caller
			// surfaces this as a failed add rather than silently dropping it.
			$keys = $this->indexKey($user_id, $vault);
		} catch (\Throwable $e) {
			error_log('MailboxContacts::upsertBatch failed for user ' . $user_id . ': ' . $e->getMessage());
			return 0;
		}
		if ($keys === false) {
			return null;
		}

		$landed = 0;
		$seen = array(); // address => did it land
		foreach ($entries as $entry) {
			$parsed = self::parseAddress((string)$entry[0]);
			if ($parsed === null) {
				continue;
			}
			$addr = $parsed[0];
			$name = trim((string)$entry[1]) !== '' ? trim((string)$entry[1]) : $parsed[1];
			if (!isset($seen[$addr])) { // one bump per address per batch
				try {
					$this->upsertOne($user_id, $addr, $name, $source, $vault, $keys['index_key'], $alias_id);
					$seen[$addr] = true;
				} catch (\Throwable $e) {
					error_log('MailboxContacts::upsertBatch: one contact failed for user ' . $user_id . ': ' . $e->getMessage());
					$seen[$addr] = false;
				}
			}
			$landed += $seen[$addr] ? 1 : 0;
		}
		return $landed;
	}

	private function upsertOne(int $user_id, string $addr, string $name, string $source, ?UserEncryptionVault $vault, ?string $index_key, int $alias_id): void {
		$hash = $this->addressHash($addr, $index_key, $alias_id);
		$db = $this->db();

		// The spam filter's `contact` signal reads this at ingest, sealed rows
		// included, so it is written with every add (SpamSenderRecords).
		$fingerprint = SpamSenderRecords::fingerprint($addr);

		$existing = $this->findRow($user_id, $addr, $index_key, $alias_id);
		if ($existing !== null) {
			$bump = $db->prepare('UPDATE imc_mailbox_contacts
				SET imc_use_count = imc_use_count + 1, imc_last_used_time = now(),
					imc_sender_fingerprint = ?
				WHERE imc_mailbox_contact_id = ?');
			$bump->execute(array($fingerprint, intval($existing['imc_mailbox_contact_id'])));
			return;
		}

		$row = new MailboxContact(NULL);
		$row->set('imc_usr_user_id', $user_id);
		$row->set('imc_iea_inbound_email_alias_id', $alias_id);
		$row->set('imc_address_hash', $hash);
		$row->set('imc_sender_fingerprint', $fingerprint);
		$row->set('imc_source', $source);
		$row->set('imc_use_count', 1);
		$row->set('imc_last_used_time', gmdate('Y-m-d H:i:s'));
		if ($vault !== null) {
			$row->set('imc_address', '');
			$row->set('imc_display_name', '');
			$row->save();
			$this->sealContact(intval($row->key), $vault, $addr, $name);
		} else {
			$row->set('imc_address', $addr);
			$row->set('imc_display_name', $name);
			$row->save();
		}
	}

	/** Seal a just-inserted contact's address + display name under a per-row DEK. */
	private function sealContact(int $contact_id, UserEncryptionVault $vault, string $addr, string $name): void {
		MailboxContact::sealColumns($contact_id, $vault, array(
			'imc_address'      => $addr,
			'imc_display_name' => $name,
		));
	}

	/**
	 * Is $address in a mailbox's SHARED, unencrypted address book — an entry any
	 * grantee added? For a shared mailbox with no single vault the contacts are
	 * plaintext, so their digest is the unkeyed form (index key null) and the match is
	 * across grantees rather than scoped to one user. Sealed per-grantee contacts
	 * are deliberately not visible here — they need that grantee's unlock — but a
	 * shared/group mailbox's book is plaintext by nature, which is the case this
	 * serves.
	 */
	public function aliasHasContact(int $alias_id, string $address): bool {
		$parsed = self::parseAddress($address);
		if ($alias_id <= 0 || $parsed === null) {
			return false;
		}
		try {
			$hash = $this->addressHash($parsed[0], null, $alias_id);
			$stmt = $this->db()->prepare('SELECT 1 FROM imc_mailbox_contacts
				WHERE imc_iea_inbound_email_alias_id = ? AND imc_address_hash = ? LIMIT 1');
			$stmt->execute(array($alias_id, $hash));
			return $stmt->fetchColumn() !== false;
		} catch (\Throwable $e) {
			error_log('MailboxContacts: alias contact lookup failed: ' . $e->getMessage());
			return false;
		}
	}

	/** The row for one normalized address on one mailbox, or null when there is none. */
	private function findRow(int $user_id, string $normalized, ?string $index_key, int $alias_id): ?array {
		$stmt = $this->db()->prepare('SELECT * FROM imc_mailbox_contacts
			WHERE imc_usr_user_id = ? AND imc_address_hash = ? LIMIT 1');
		$stmt->execute(array($user_id, $this->addressHash($normalized, $index_key, $alias_id)));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row : null;
	}

	// ── lookup ───────────────────────────────────────────────────────────────

	/**
	 * What the user's contact store holds for one address on one mailbox:
	 * ['id','name','source','added_time'], null when there is no row, or ['locked'=>true]
	 * when a vault holder's window is closed — the keyed hash can't be computed then, so
	 * the state is unknown rather than absent, and the caller must not present it as
	 * "not a contact".
	 *
	 * A row present at all means the user put it there, so there is no intent flag to
	 * report: 'source' says by hand or by import, and nothing else can create a row.
	 */
	public function lookup(int $user_id, string $address, int $alias_id): ?array {
		$parsed = self::parseAddress($address);
		if ($user_id <= 0 || $alias_id <= 0 || $parsed === null) {
			return null;
		}
		try {
			$vault = $this->sealingVault($user_id, $alias_id);
			$keys = $this->indexKey($user_id, $vault);
			if ($keys === false) {
				return array('locked' => true);
			}
			$row = $this->findRow($user_id, $parsed[0], $keys['index_key'], $alias_id);
			if ($row === null) {
				return null;
			}
			return array(
				'id'         => intval($row['imc_mailbox_contact_id']),
				'name'       => (string)MailboxContact::decryptSealedFieldStatic('imc_display_name', $row['imc_display_name'], $row),
				'source'     => (string)$row['imc_source'],
				'added_time' => $row['imc_create_time'],
			);
		} catch (\Throwable $e) {
			error_log('MailboxContacts::lookup failed for user ' . $user_id . ': ' . $e->getMessage());
			return null;
		}
	}

	// ── list ─────────────────────────────────────────────────────────────────

	/**
	 * The user's contacts ON ONE MAILBOX, decrypted, de-duplicated by address (a row hashed
	 * before the user's index key existed can sit beside its re-add — keep the most-used),
	 * ranked use_count desc then recency. Returns ['contacts'=>[{id,address,name,use_count,source}], 'locked'=>bool].
	 * A vault holder with a closed window returns ['locked'=>true] and no contacts.
	 * $limit caps the rows read (MAX_LIST, for the pickers); an export passes null for all.
	 */
	public function listForMailbox(int $user_id, int $alias_id, ?int $limit = self::MAX_LIST): array {
		if ($user_id <= 0 || $alias_id <= 0) {
			return array('contacts' => array());
		}
		$vault = $this->sealingVault($user_id, $alias_id);
		if ($vault !== null && !VaultUnlock::isOpen($user_id)) {
			return array('contacts' => array(), 'locked' => true);
		}

		$db = $this->db();
		$stmt = $db->prepare('SELECT * FROM imc_mailbox_contacts
			WHERE imc_usr_user_id = ? AND imc_iea_inbound_email_alias_id = ?
			ORDER BY imc_use_count DESC, imc_last_used_time DESC'
			. ($limit === null ? '' : ' LIMIT ' . intval($limit)));
		$stmt->execute(array($user_id, $alias_id));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$by_address = array();
		foreach ($rows as $r) {
			try {
				$addr = (string)MailboxContact::decryptSealedFieldStatic('imc_address', $r['imc_address'], $r);
				$name = (string)MailboxContact::decryptSealedFieldStatic('imc_display_name', $r['imc_display_name'], $r);
			} catch (VaultLockedException $e) {
				// Mid-list the window lapsed — report locked rather than a partial list.
				return array('contacts' => array(), 'locked' => true);
			}
			$addr = strtolower(trim($addr));
			if ($addr === '') {
				continue;
			}
			$entry = array(
				'id'         => intval($r['imc_mailbox_contact_id']),
				'address'    => $addr,
				'name'       => $name,
				'use_count'  => intval($r['imc_use_count']),
				'source'     => (string)$r['imc_source'],
			);
			// De-dup by address: keep the row with the higher use_count (already
			// ordered use_count desc, so the first win stands; merge names).
			if (!isset($by_address[$addr])) {
				$by_address[$addr] = $entry;
			} elseif ($entry['name'] !== '' && $by_address[$addr]['name'] === '') {
				$by_address[$addr]['name'] = $entry['name'];
			}
		}
		return array('contacts' => array_values($by_address));
	}

	/**
	 * Every contact the user holds, across all their mailboxes — for surfaces
	 * that are not scoped to one mailbox, like the Messenger people picker.
	 *
	 * Decryption is per row: a sealed row whose vault window is closed is
	 * silently absent rather than blocking the readable rest, matching how a
	 * closed window reads everywhere else (absent, never an error).
	 */
	public function listForUser(int $user_id): array {
		if ($user_id <= 0) {
			return array('contacts' => array());
		}

		$stmt = $this->db()->prepare('SELECT * FROM imc_mailbox_contacts
			WHERE imc_usr_user_id = ?
			ORDER BY imc_use_count DESC, imc_last_used_time DESC LIMIT ' . self::MAX_LIST);
		$stmt->execute(array($user_id));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$by_address = array();
		foreach ($rows as $r) {
			try {
				$addr = (string)MailboxContact::decryptSealedFieldStatic('imc_address', $r['imc_address'], $r);
				$name = (string)MailboxContact::decryptSealedFieldStatic('imc_display_name', $r['imc_display_name'], $r);
			} catch (VaultLockedException $e) {
				continue;
			}
			$addr = strtolower(trim($addr));
			if ($addr === '') {
				continue;
			}
			if (!isset($by_address[$addr])) {
				$by_address[$addr] = array(
					'address' => $addr,
					'name'    => $name,
				);
			} elseif ($name !== '' && $by_address[$addr]['name'] === '') {
				$by_address[$addr]['name'] = $name;
			}
		}
		return array('contacts' => array_values($by_address));
	}

	// ── delete ───────────────────────────────────────────────────────────────

	/** Delete one contact, scoped to the owner. Returns true when a row went. */
	public function deleteContact(int $user_id, int $contact_id): bool {
		if ($user_id <= 0 || $contact_id <= 0) {
			return false;
		}
		$stmt = $this->db()->prepare('DELETE FROM imc_mailbox_contacts
			WHERE imc_mailbox_contact_id = ? AND imc_usr_user_id = ?');
		$stmt->execute(array($contact_id, $user_id));
		return $stmt->rowCount() > 0;
	}

	// ── import ───────────────────────────────────────────────────────────────

	/**
	 * Import contacts from a vCard (.vcf) or Google-contacts CSV export. Parses name +
	 * email(s) (everything else discarded) and stores them (source='import'). Returns
	 * ['imported'=>int, 'skipped'=>int], counting what was stored: a row/card with no
	 * valid email is skipped, and so is one whose write failed. A sealed store with the
	 * vault window closed stores nothing and returns 'locked'=>true. Reads back what
	 * toVcard() and toGoogleCsv() write.
	 */
	public function import(int $user_id, string $content, string $filename, int $alias_id): array {
		$content = (string)$content;
		$is_vcard = (stripos($filename, '.vcf') !== false) || (stripos($content, 'BEGIN:VCARD') !== false);
		// Each parser returns ['entries'=>[[address, name], ...], 'empty'=>int] — 'empty'
		// counts cards/rows that carried content but no email at all (skipped, per the spec).
		$parsed = $is_vcard ? $this->parseVcard($content) : $this->parseCsv($content);

		$skipped = intval($parsed['empty']);
		$batch = array();
		foreach ($parsed['entries'] as $entry) {
			if (self::parseAddress($entry[0]) === null) {
				$skipped++;
				continue;
			}
			$batch[] = $entry;
		}
		$imported = count($batch) ? $this->upsertBatch($user_id, $batch, MailboxContact::SOURCE_IMPORT, $alias_id) : 0;
		if ($imported === null) {
			return array('imported' => 0, 'skipped' => $skipped, 'locked' => true);
		}
		// A row whose write failed is skipped: the counts say what was stored.
		return array('imported' => $imported, 'skipped' => $skipped + count($batch) - $imported);
	}

	/**
	 * Add one address by hand (the contacts page's add form, the reader's Add button
	 * beside a sender). Returns false when the address is unusable, no mailbox was named,
	 * or the row could not be written — a sealed store with a closed vault window has
	 * nowhere to put it, and the caller must say so rather than appear to have saved it.
	 */
	public function manualAdd(int $user_id, string $raw, int $alias_id, ?string $name = null): bool {
		$parsed = self::parseAddress($raw);
		if ($parsed === null || $alias_id <= 0) {
			return false;
		}
		// A name given on its own (the contacts page's two fields) is taken as
		// typed, whatever characters it holds, rather than parsed out of the
		// "Name <email>" form.
		$name = $name === null ? $parsed[1] : trim($name);
		$this->upsertBatch($user_id, array(array($parsed[0], '')), MailboxContact::SOURCE_MANUAL, $alias_id);
		// An address already held as an import keeps its row; the add re-stamps it manual
		// and fills a display name the import never carried.
		return $this->markSaved($user_id, $parsed[0], $name, $alias_id);
	}

	// ── edit ─────────────────────────────────────────────────────────────────

	/**
	 * Change one of the user's contacts: its display name, its address, or both.
	 *
	 * The same address keeps its row, with the name rewritten (re-sealed under the
	 * row's own DEK when sealed). A new address is a different blind-index entry on
	 * the same mailbox, so it is stored the way a hand add stores one — joining a
	 * row that already holds that address — given this name, and the old row goes.
	 *
	 * Returns 'saved', 'invalid' (no usable address), 'missing' (no such contact of
	 * this user's) or 'locked' (a sealed store with the vault window closed: nothing
	 * can be read or written, and nothing was).
	 */
	public function updateContact(int $user_id, int $contact_id, string $address, string $name): string {
		$parsed = self::parseAddress($address);
		if ($parsed === null) {
			return 'invalid';
		}
		$name = trim($name);
		$stmt = $this->db()->prepare('SELECT * FROM imc_mailbox_contacts
			WHERE imc_mailbox_contact_id = ? AND imc_usr_user_id = ?');
		$stmt->execute(array($contact_id, $user_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return 'missing';
		}
		$alias_id = intval($row['imc_iea_inbound_email_alias_id']);
		$keys = $this->indexKey($user_id, $this->sealingVault($user_id, $alias_id));
		if ($keys === false) {
			return 'locked';
		}
		try {
			$old = strtolower(trim((string)MailboxContact::decryptSealedFieldStatic('imc_address', $row['imc_address'], $row)));
		} catch (VaultLockedException $e) {
			return 'locked';
		}

		if ($old === $parsed[0]) {
			$this->setDisplayName($row, $name, $keys['key']);
			return 'saved';
		}

		$this->upsertBatch($user_id, array(array($parsed[0], '')), MailboxContact::SOURCE_MANUAL, $alias_id);
		$new = $this->findRow($user_id, $parsed[0], $keys['index_key'], $alias_id);
		if ($new === null) {
			// The window closed between the read and the write.
			return 'locked';
		}
		$mark = $this->db()->prepare('UPDATE imc_mailbox_contacts SET imc_source = ?
			WHERE imc_mailbox_contact_id = ? AND imc_usr_user_id = ?');
		$mark->execute(array(MailboxContact::SOURCE_MANUAL, intval($new['imc_mailbox_contact_id']), $user_id));
		$this->setDisplayName($new, $name, $keys['key']);
		if (intval($new['imc_mailbox_contact_id']) !== $contact_id) {
			$this->deleteContact($user_id, $contact_id);
		}
		return 'saved';
	}

	/**
	 * Stamp the stored row as a deliberate save, filling in the display name when the add
	 * supplied one and the row has none. Returns whether a row was actually found — which
	 * is what tells manualAdd() the address landed.
	 */
	private function markSaved(int $user_id, string $normalized, string $name, int $alias_id): bool {
		try {
			$vault = $this->sealingVault($user_id, $alias_id);
			$keys = $this->indexKey($user_id, $vault);
			if ($keys === false) {
				return false;
			}
			$row = $this->findRow($user_id, $normalized, $keys['index_key'], $alias_id);
			if ($row === null) {
				return false;
			}
			$id = intval($row['imc_mailbox_contact_id']);
			$stmt = $this->db()->prepare('UPDATE imc_mailbox_contacts SET imc_source = ?
				WHERE imc_mailbox_contact_id = ? AND imc_usr_user_id = ?');
			$stmt->execute(array(MailboxContact::SOURCE_MANUAL, $id, $user_id));
			if ($name !== '') {
				$stored = (string)MailboxContact::decryptSealedFieldStatic('imc_display_name', $row['imc_display_name'], $row);
				if (trim($stored) === '') {
					$this->setDisplayName($row, $name, $keys['key']);
				}
			}
			return true;
		} catch (\Throwable $e) {
			error_log('MailboxContacts::markSaved failed for user ' . $user_id . ': ' . $e->getMessage());
			return false;
		}
	}

	/** Write a display name onto an existing row, re-sealing under that row's own DEK. */
	private function setDisplayName(array $row, string $name, ?VaultKey $key): void {
		$id = intval($row['imc_mailbox_contact_id']);
		if (!empty($row['imc_content_sealed']) && !empty($row['imc_sealed_key'])) {
			// $key is the row owner's only when the row was sealed to their own vault
			// (always so in practice) — otherwise leave the stored name alone.
			$sealed_owner = intval($row['imc_sealed_owner_user_id'] ?? 0);
			if ($key === null || $sealed_owner !== intval($row['imc_usr_user_id'])) {
				return;
			}
			require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
			$crypto = new VaultCrypto();
			// Re-seal under the row's OWN DEK, so the address sealed beside it stays
			// readable; a reused DEK needs no vault (the wrapping is already written).
			$dek = $crypto->openItemDek((string)$row['imc_sealed_key'], $key);
			MailboxContact::sealColumns($id, null, array('imc_display_name' => $name), $dek);
			return;
		}
		$stmt = $this->db()->prepare('UPDATE imc_mailbox_contacts SET imc_display_name = ?
			WHERE imc_mailbox_contact_id = ?');
		$stmt->execute(array($name, $id));
	}

	// ── export ───────────────────────────────────────────────────────────────

	/**
	 * A vCard 3.0 file of these contacts (listForMailbox() entries): one card per
	 * contact, with FN as the display name exactly and N as the same name cut at its
	 * last space. A contact with no name carries its address as FN, which vCard
	 * requires; parseVcard() reads that back as no name.
	 */
	public static function toVcard(array $contacts): string {
		$out = '';
		foreach ($contacts as $c) {
			$name = trim((string)$c['name']);
			list($given, $family) = self::splitName($name);
			$card = "BEGIN:VCARD\r\n"
				. "VERSION:3.0\r\n"
				. 'FN:' . self::vcardEscape($name !== '' ? $name : (string)$c['address']) . "\r\n"
				. 'N:' . self::vcardEscape($family) . ';' . self::vcardEscape($given) . ";;;\r\n"
				. 'EMAIL;TYPE=INTERNET:' . $c['address'] . "\r\n"
				. "END:VCARD\r\n";
			$out .= IcsHelper::foldLines($card);
		}
		return $out;
	}

	/**
	 * A Google Contacts CSV of these contacts (listForMailbox() entries), in the
	 * columns Google's own export uses. The name is cut at its last space into First
	 * Name and Last Name; parseCsv() joins the two back with one space, so a name
	 * reads back exactly up to its spacing (a run of spaces, or a line break at the
	 * cut, comes back as one space; the vCard keeps a name exactly). Cells are
	 * defused against formulas (CsvWriter), since a display name can come from a
	 * stranger's mail.
	 */
	public static function toGoogleCsv(array $contacts): string {
		$out = CsvWriter::BOM . CsvWriter::line(array('First Name', 'Last Name', 'E-mail 1 - Value'));
		foreach ($contacts as $c) {
			list($given, $family) = self::splitName((string)$c['name']);
			$out .= CsvWriter::line(array($given, $family, $c['address']));
		}
		return $out;
	}

	/** [given, family]: a display name cut at its last space, so the two joined by a space give it back. */
	private static function splitName(string $name): array {
		$name = trim($name);
		$at = strrpos($name, ' ');
		if ($at === false) {
			return array($name, '');
		}
		return array(rtrim(substr($name, 0, $at)), substr($name, $at + 1));
	}

	/** vCard 3.0 text value escaping (RFC 2426 §4). */
	private static function vcardEscape(string $text): string {
		return str_replace(array('\\', ';', ',', "\r\n", "\n", "\r"), array('\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'), $text);
	}

	private static function vcardUnescape(string $text): string {
		return preg_replace_callback('/\\\\(.)/s', function ($m) {
			return ($m[1] === 'n' || $m[1] === 'N') ? "\n" : $m[1];
		}, $text);
	}

	// ── import parsers ───────────────────────────────────────────────────────

	/** A vCard structured value (N's family;given;...) split at its unescaped semicolons, unescaped. */
	private static function vcardFields(string $value): array {
		$fields = array('');
		$last = 0;
		for ($i = 0, $n = strlen($value); $i < $n; $i++) {
			if ($value[$i] === '\\' && $i + 1 < $n) {
				$fields[$last] .= $value[$i] . $value[++$i];
			} elseif ($value[$i] === ';') {
				$fields[++$last] = '';
			} else {
				$fields[$last] .= $value[$i];
			}
		}
		return array_map(function ($f) { return trim(self::vcardUnescape($f)); }, $fields);
	}

	/**
	 * A property value as UTF-8 text, decoded as its parameters say (vCard 2.1): a
	 * QUOTED-PRINTABLE value is decoded, and one in another CHARSET that is not
	 * already valid UTF-8 is converted from it, or from Windows-1252 when it names
	 * none (or one PHP does not know).
	 */
	private static function vcardValue(string $params, string $value): string {
		if (stripos($params, 'QUOTED-PRINTABLE') !== false) {
			$value = quoted_printable_decode($value);
		}
		// A value that claims UTF-8 and is not goes straight to the fallback below.
		if (!mb_check_encoding($value, 'UTF-8') && preg_match('/;CHARSET="?([\w.:-]+)/i', $params, $cs)
				&& strcasecmp($cs[1], 'UTF-8') !== 0) {
			try {
				$value = mb_convert_encoding($value, 'UTF-8', $cs[1]);
			} catch (\ValueError $e) {
				// a charset PHP does not know: the fallback below reads it
			}
		}
		if (!mb_check_encoding($value, 'UTF-8')) {
			// Still not UTF-8: raw 8-bit text with no CHARSET, as desktop Outlook saves a
			// contact. Windows-1252 maps every byte, so the result is always valid UTF-8,
			// which the database insists on.
			$value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
		}
		return $value;
	}

	/**
	 * Extract [address, name] entries from a vCard (FN + EMAIL per card).
	 *
	 * Lines are unfolded first (a long line continues after a line break and a
	 * space or tab; a vCard 2.1 quoted-printable value continues after a trailing
	 * '='), and a property may carry a group prefix: Apple writes item1.EMAIL for an
	 * address with a custom label. A quoted parameter value may hold a colon. A
	 * card with no FN (vCard 2.1 allows that) takes its name from N.
	 *
	 * @return array{entries: array<int, array{0:string, 1:string}>, empty: int}
	 */
	private function parseVcard(string $content): array {
		if (strncmp($content, CsvWriter::BOM, 3) === 0) {
			$content = substr($content, 3);
		}
		$content = str_replace(array("\r\n", "\r"), "\n", $content);
		$content = preg_replace_callback('/^([^:\n]*QUOTED-PRINTABLE[^:\n]*:)((?:[^\n]*=\n)*[^\n]*)$/im', function ($m) {
			return $m[1] . str_replace("=\n", '', $m[2]);
		}, $content);
		$content = preg_replace('/\n[ \t]/', '', $content);
		// group prefix, property name, parameters (a quoted value may hold a colon), value
		$prop = '/^(?:[\w-]+\.)?%s((?:;(?:[^:"\n]|"[^"\n]*")*)?):(.*)$/im';
		$out = array();
		$empty = 0;
		foreach (preg_split('/BEGIN:VCARD/i', $content) as $card) {
			if (trim($card) === '') {
				continue;
			}
			$addrs = array();
			if (preg_match_all(sprintf($prop, 'EMAIL'), $card, $em, PREG_SET_ORDER)) {
				foreach ($em as $e) {
					$addr = trim(self::vcardValue($e[1], $e[2]));
					if ($addr !== '') {
						$addrs[] = $addr;
					}
				}
			}
			if (!$addrs) {
				$empty++; // a real card with no email address
				continue;
			}
			$name = '';
			if (preg_match(sprintf($prop, 'FN'), $card, $m)) {
				$name = trim(self::vcardUnescape(self::vcardValue($m[1], $m[2])));
			}
			// FN is required, so a card for a bare address names it by an address: no name.
			foreach ($addrs as $addr) {
				if (strcasecmp($name, $addr) === 0) {
					$name = '';
				}
			}
			if ($name === '' && preg_match(sprintf($prop, 'N'), $card, $m)) {
				// family;given;additional;prefix;suffix, read in the order people write them
				$n = self::vcardFields(self::vcardValue($m[1], $m[2])) + array('', '', '', '', '');
				$name = implode(' ', array_filter(array($n[3], $n[1], $n[2], $n[0], $n[4]), 'strlen'));
			}
			foreach ($addrs as $addr) {
				$out[] = array($addr, $name);
			}
		}
		return array('entries' => $out, 'empty' => $empty);
	}

	/**
	 * Extract [address, name] entries from a Google-contacts CSV export.
	 *
	 * Read as whole records (CsvReader), so a quoted field holding a line break —
	 * the Notes column, routinely — stays inside its row.
	 *
	 * The name is the Name / Display Name / Full Name column (Google's export before
	 * 2023), or else First, Middle and Last Name joined (Google's export since, and
	 * Outlook's); Given / Additional / Family Name are Google's older spellings of
	 * those, and First / Middle / Last Yahoo's.
	 *
	 * @return array{entries: array<int, array{0:string, 1:string}>, empty: int}
	 */
	private function parseCsv(string $content): array {
		$records = CsvReader::records($content);
		if (!count($records)) {
			return array('entries' => array(), 'empty' => 0);
		}
		$header = array_shift($records);
		$full_cols = array();
		$part_cols = array(array(), array(), array()); // first, middle, last
		$email_cols = array();
		foreach ($header as $i => $col) {
			$c = strtolower(trim($col));
			if (in_array($c, array('name', 'display name', 'full name'), true)) {
				$full_cols[] = $i;
			} elseif (in_array($c, array('first name', 'given name', 'first'), true)) {
				$part_cols[0][] = $i;
			} elseif (in_array($c, array('middle name', 'additional name', 'middle'), true)) {
				$part_cols[1][] = $i;
			} elseif (in_array($c, array('last name', 'family name', 'last'), true)) {
				$part_cols[2][] = $i;
			}
			if (strpos($c, 'e-mail') !== false || strpos($c, 'email') !== false) {
				// The address columns only: Google's "E-mail 1 - Value", Outlook's "E-mail Address"
				// and "E-mail 2 Address", Thunderbird's "Primary Email" — never a Label, Type or
				// Display Name column beside them.
				if (strpos($c, 'value') !== false || preg_match('/e-?mail(\s*\d+)?(\s+address)?$/', $c)) {
					$email_cols[] = $i;
				}
			}
		}
		if (!count($email_cols)) {
			// Fall back: any column whose header mentions mail.
			foreach ($header as $i => $col) {
				if (stripos($col, 'mail') !== false) {
					$email_cols[] = $i;
				}
			}
		}
		$out = array();
		$empty = 0;
		foreach ($records as $cells) {
			if (trim(implode('', $cells)) === '') {
				continue;
			}
			$pick = function (array $cols) use ($cells) {
				foreach ($cols as $ci) {
					if (isset($cells[$ci]) && trim($cells[$ci]) !== '') {
						return trim($cells[$ci]);
					}
				}
				return '';
			};
			$name = $pick($full_cols);
			if ($name === '') {
				$name = implode(' ', array_filter(array_map($pick, $part_cols), 'strlen'));
			}
			$before = count($out);
			foreach ($email_cols as $ci) {
				if (!isset($cells[$ci])) {
					continue;
				}
				// A cell may hold several addresses (Google joins with " ::: ").
				foreach (preg_split('/\s*:::\s*|\s*;\s*|\s*,\s*/', $cells[$ci]) as $addr) {
					$addr = trim($addr);
					if ($addr === '') {
						continue;
					}
					$out[] = array($addr, $name);
				}
			}
			if (count($out) === $before) {
				$empty++; // a data row with no email
			}
		}
		return array('entries' => $out, 'empty' => $empty);
	}
}
?>
