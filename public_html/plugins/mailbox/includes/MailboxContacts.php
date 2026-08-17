<?php
/**
 * MailboxContacts — the contact store service (specs/mailbox_compose_maturity.md § Phase 4):
 * add, import, list (for autocomplete + management), look up, delete.
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
 * a Private/Fortress mailbox seals end to end — and there is no "plaintext mail, sealed
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
 * address) and a plain SHA-256 otherwise.
 *
 * @version 2.3
 * @changelog 2.3 - the posture question follows the MAILBOX, and an unreadable
 *   one fails toward sealed rather than toward plaintext
 * @changelog 2.2 - listForUser(): the user's whole contact store for surfaces not scoped to one mailbox (Messenger people picker)
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));

class MailboxContacts {

	/** Max contacts returned to the client (the whole list is small; guard runaways). */
	const MAX_LIST = 2000;

	private function db() {
		return DbConnector::get_instance()->get_db_link();
	}

	/** @var array<int,bool> request-scoped: does this mailbox's domain seal content? */
	private static $seals_cache = array();

	/**
	 * The vault a contact row on this mailbox seals under, or null when rows
	 * here are plaintext.
	 *
	 * Two conditions, and both are required: the user must HOLD a vault, and the
	 * mailbox's domain must be one that seals content (Private or Fortress).
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
	 * a keyed HMAC (blind index) under a subkey derived from the in-window vault secret,
	 * so DB access alone can't recover the sealed address; for a user with no vault it is
	 * a plain SHA-256 (the address column is plaintext anyway).
	 *
	 * The mailbox is hashed alongside the address so that the same address on two
	 * mailboxes digests differently — which is what lets the (hash, user) unique
	 * constraint enforce one row per (user, mailbox, address) without a composite key
	 * over an encrypted column.
	 */
	public function addressHash(string $normalized, ?string $secret, int $alias_id): string {
		$material = $alias_id . ':' . $normalized;
		if ($secret !== null) {
			$subkey = hash_hmac('sha256', 'joinery:contact-index:v1', $secret, true);
			return hash_hmac('sha256', $material, $subkey); // 64 hex chars
		}
		return hash('sha256', 'joinery:contact:' . $material);
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
	 * new one is inserted (sealed when the user holds a vault). $tokens are raw
	 * "Name <email>" / bare-address strings.
	 *
	 * PRIVATE ON PURPOSE. The public writers are manualAdd() and import(), both of which
	 * are a user deciding to keep an address. Exposing this again is how mail traffic
	 * would start filing contacts by itself.
	 *
	 * A write with no mailbox to attribute to is dropped rather than stored unscoped:
	 * an unscoped row would be invisible to every mailbox-scoped read, so writing one
	 * only grows the table.
	 *
	 * @param string[] $tokens
	 */
	private function upsertBatch(int $user_id, array $tokens, string $source, int $alias_id): void {
		if ($user_id <= 0 || $alias_id <= 0 || !count($tokens)) {
			return;
		}
		try {
			$vault = $this->sealingVault($user_id, $alias_id);
			$secret = ($vault !== null) ? VaultUnlock::secretKey($user_id) : null;
			// A sealed mailbox whose window is closed: can't compute the keyed hash or
			// read for dedup, so there is nowhere to put the address. The caller
			// surfaces this as a failed add rather than silently dropping it.
			if ($vault !== null && $secret === null) {
				return;
			}

			$seen = array();
			foreach ($tokens as $raw) {
				$parsed = self::parseAddress((string)$raw);
				if ($parsed === null) {
					continue;
				}
				list($addr, $name) = $parsed;
				if (isset($seen[$addr])) {
					continue; // one bump per address per batch
				}
				$seen[$addr] = true;
				$this->upsertOne($user_id, $addr, $name, $source, $vault, $secret, $alias_id);
			}
		} catch (\Throwable $e) {
			error_log('MailboxContacts::upsertBatch failed for user ' . $user_id . ': ' . $e->getMessage());
		}
	}

	private function upsertOne(int $user_id, string $addr, string $name, string $source, ?UserEncryptionVault $vault, ?string $secret, int $alias_id): void {
		$hash = $this->addressHash($addr, $secret, $alias_id);
		$db = $this->db();

		$existing = $this->findRow($user_id, $addr, $secret, $alias_id);
		if ($existing !== null) {
			$bump = $db->prepare('UPDATE imc_mailbox_contacts
				SET imc_use_count = imc_use_count + 1, imc_last_used_time = now()
				WHERE imc_mailbox_contact_id = ?');
			$bump->execute(array(intval($existing['imc_mailbox_contact_id'])));
			return;
		}

		$row = new MailboxContact(NULL);
		$row->set('imc_usr_user_id', $user_id);
		$row->set('imc_iea_inbound_email_alias_id', $alias_id);
		$row->set('imc_address_hash', $hash);
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
	 * plaintext, so their digest is the unkeyed form (secret null) and the match is
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
	private function findRow(int $user_id, string $normalized, ?string $secret, int $alias_id): ?array {
		$stmt = $this->db()->prepare('SELECT * FROM imc_mailbox_contacts
			WHERE imc_usr_user_id = ? AND imc_address_hash = ? LIMIT 1');
		$stmt->execute(array($user_id, $this->addressHash($normalized, $secret, $alias_id)));
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
			$secret = ($vault !== null) ? VaultUnlock::secretKey($user_id) : null;
			if ($vault !== null && $secret === null) {
				return array('locked' => true);
			}
			$row = $this->findRow($user_id, $parsed[0], $secret, $alias_id);
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
	 * The user's contacts ON ONE MAILBOX, decrypted, de-duplicated by address (a rotation
	 * can leave two rows for one address — keep the most-used), ranked use_count desc then
	 * recency. Returns ['contacts'=>[{id,address,name,use_count,source}], 'locked'=>bool].
	 * A vault holder with a closed window returns ['locked'=>true] and no contacts.
	 */
	public function listForMailbox(int $user_id, int $alias_id): array {
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
			ORDER BY imc_use_count DESC, imc_last_used_time DESC LIMIT ' . self::MAX_LIST);
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
	 * ['imported'=>int, 'skipped'=>int]. A minimal, forgiving parser; a row/card with no
	 * valid email is skipped.
	 */
	public function import(int $user_id, string $content, string $filename, int $alias_id): array {
		$content = (string)$content;
		$is_vcard = (stripos($filename, '.vcf') !== false) || (stripos($content, 'BEGIN:VCARD') !== false);
		// Each parser returns ['tokens'=>[...], 'empty'=>int] — 'empty' counts cards/rows
		// that carried content but no email at all (skipped, per the spec).
		$parsed = $is_vcard ? $this->parseVcard($content) : $this->parseCsv($content);

		$imported = 0;
		$skipped = intval($parsed['empty']);
		$batch = array();
		foreach ($parsed['tokens'] as $t) {
			if (self::parseAddress($t) === null) {
				$skipped++;
				continue;
			}
			$batch[] = $t;
			$imported++;
		}
		if (count($batch)) {
			$this->upsertBatch($user_id, $batch, MailboxContact::SOURCE_IMPORT, $alias_id);
		}
		return array('imported' => $imported, 'skipped' => $skipped);
	}

	/**
	 * Add one address by hand (the contacts panel's "add" form, the reader's Add button
	 * beside a sender). Returns false when the address is unusable, no mailbox was named,
	 * or the row could not be written — a sealed store with a closed vault window has
	 * nowhere to put it, and the caller must say so rather than appear to have saved it.
	 */
	public function manualAdd(int $user_id, string $raw, int $alias_id): bool {
		$parsed = self::parseAddress($raw);
		if ($parsed === null || $alias_id <= 0) {
			return false;
		}
		$this->upsertBatch($user_id, array($raw), MailboxContact::SOURCE_MANUAL, $alias_id);
		// An address already held as an import keeps its row; the add re-stamps it manual
		// and fills a display name the import never carried.
		return $this->markSaved($user_id, $parsed[0], $parsed[1], $alias_id);
	}

	/**
	 * Stamp the stored row as a deliberate save, filling in the display name when the add
	 * supplied one and the row has none. Returns whether a row was actually found — which
	 * is what tells manualAdd() the address landed.
	 */
	private function markSaved(int $user_id, string $normalized, string $name, int $alias_id): bool {
		try {
			$vault = $this->sealingVault($user_id, $alias_id);
			$secret = ($vault !== null) ? VaultUnlock::secretKey($user_id) : null;
			if ($vault !== null && $secret === null) {
				return false;
			}
			$row = $this->findRow($user_id, $normalized, $secret, $alias_id);
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
					$this->setDisplayName($row, $name, $secret);
				}
			}
			return true;
		} catch (\Throwable $e) {
			error_log('MailboxContacts::markSaved failed for user ' . $user_id . ': ' . $e->getMessage());
			return false;
		}
	}

	/** Write a display name onto an existing row, re-sealing under that row's own DEK. */
	private function setDisplayName(array $row, string $name, ?string $secret): void {
		$id = intval($row['imc_mailbox_contact_id']);
		if (!empty($row['imc_content_sealed']) && !empty($row['imc_sealed_key'])) {
			// $secret is the row owner's only when the row was sealed to their own vault
			// (always so in practice) — otherwise leave the stored name alone.
			$sealed_owner = intval($row['imc_sealed_owner_user_id'] ?? 0);
			if ($secret === null || $sealed_owner !== intval($row['imc_usr_user_id'])) {
				return;
			}
			require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
			$crypto = new VaultCrypto();
			// Re-seal under the row's OWN DEK, so the address sealed beside it stays
			// readable; a reused DEK needs no vault (the wrapping is already written).
			$dek = $crypto->openItemDek((string)$row['imc_sealed_key'], $secret);
			MailboxContact::sealColumns($id, null, array('imc_display_name' => $name), $dek);
			return;
		}
		$stmt = $this->db()->prepare('UPDATE imc_mailbox_contacts SET imc_display_name = ?
			WHERE imc_mailbox_contact_id = ?');
		$stmt->execute(array($name, $id));
	}

	/**
	 * Extract "Name <email>" tokens from a vCard (FN + EMAIL per card).
	 * @return array{tokens: string[], empty: int}
	 */
	private function parseVcard(string $content): array {
		$out = array();
		$empty = 0;
		foreach (preg_split('/BEGIN:VCARD/i', $content) as $card) {
			if (trim($card) === '') {
				continue;
			}
			$name = '';
			if (preg_match('/^FN(?:;[^:]*)?:(.+)$/im', $card, $m)) {
				$name = trim($m[1]);
			}
			if (preg_match_all('/^EMAIL(?:;[^:]*)?:(.+)$/im', $card, $em)) {
				foreach ($em[1] as $addr) {
					$addr = trim($addr);
					$out[] = ($name !== '' ? $name . ' <' . $addr . '>' : $addr);
				}
			} else {
				$empty++; // a real card with no email address
			}
		}
		return array('tokens' => $out, 'empty' => $empty);
	}

	/**
	 * Extract "Name <email>" tokens from a Google-contacts CSV export.
	 *
	 * Parsed with an empty escape character. Google Contacts emits RFC 4180,
	 * where a backslash is an ordinary character; PHP's default treats it as an
	 * escape, so a backslash in a name or notes field swallows the quote after
	 * it and shifts every remaining column on that row.
	 *
	 * Known limitation, unrelated to the escape: the split below is done before
	 * any field is parsed, so a quoted field containing a newline — the Notes
	 * column, routinely — still breaks row alignment.
	 *
	 * @return array{tokens: string[], empty: int}
	 */
	private function parseCsv(string $content): array {
		$lines = preg_split('/\r\n|\r|\n/', $content);
		if (!count($lines)) {
			return array();
		}
		$header = str_getcsv(array_shift($lines), ',', '"', '');
		$name_cols = array();
		$email_cols = array();
		foreach ($header as $i => $col) {
			$c = strtolower(trim($col));
			if ($c === 'name' || strpos($c, 'display name') !== false || $c === 'full name' || strpos($c, 'given name') !== false) {
				$name_cols[] = $i;
			}
			if (strpos($c, 'e-mail') !== false || strpos($c, 'email') !== false) {
				// Google CSV uses "E-mail 1 - Value" etc. — take only the value columns.
				if (strpos($c, 'value') !== false || preg_match('/e-?mail(\s*\d+)?$/', $c)) {
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
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			$cells = str_getcsv($line, ',', '"', '');
			$name = '';
			foreach ($name_cols as $ci) {
				if (isset($cells[$ci]) && trim($cells[$ci]) !== '') { $name = trim($cells[$ci]); break; }
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
					$out[] = ($name !== '' ? $name . ' <' . $addr . '>' : $addr);
				}
			}
			if (count($out) === $before) {
				$empty++; // a data row with no email
			}
		}
		return array('tokens' => $out, 'empty' => $empty);
	}
}
?>
