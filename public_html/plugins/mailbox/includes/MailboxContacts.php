<?php
/**
 * MailboxContacts — the per-user contact store service (specs/mailbox_compose_maturity.md
 * § Phase 4): harvest, list (for autocomplete + management), import, delete.
 *
 * Every method is scoped to one user id. A row is sealed when that user holds a Sealed
 * Vault; harvest always runs in-window (on send, or on opening a thread), so the plaintext
 * address is available to hash and seal. The address hash is a keyed blind index for vault
 * holders (never leaks the sealed address) and a plain SHA-256 otherwise — see addressHash().
 *
 * A row is SAVED when the user added it deliberately (source manual or import) and merely
 * SEEN when it warmed up through use (source sent or received) — lookup() reports which,
 * and manualAdd() stamps a seen row as saved.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));

class MailboxContacts {

	/** Max contacts returned to the client (the whole list is small; guard runaways). */
	const MAX_LIST = 2000;

	private function db() {
		return DbConnector::get_instance()->get_db_link();
	}

	/**
	 * The dedup digest for a normalized address. For a vault holder it is a keyed
	 * HMAC (blind index) under a subkey derived from the in-window vault secret, so
	 * DB access alone can't recover the sealed address; for a user with no vault it
	 * is a plain SHA-256 (the address column is plaintext anyway). Returns '' when a
	 * vault holder is locked (no secret) — the caller then skips harvest.
	 */
	public function addressHash(string $normalized, ?string $secret): string {
		if ($secret !== null) {
			$subkey = hash_hmac('sha256', 'joinery:contact-index:v1', $secret, true);
			return hash_hmac('sha256', $normalized, $subkey); // 64 hex chars
		}
		return hash('sha256', 'joinery:contact:' . $normalized);
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

	// ── harvest ──────────────────────────────────────────────────────────────

	/**
	 * Upsert a batch of addresses for a user (best-effort; never throws into the
	 * caller). Each existing contact has its use_count and last_used bumped; a new
	 * one is inserted (sealed when the user holds a vault). $tokens are raw
	 * "Name <email>" / bare-address strings.
	 *
	 * @param string[] $tokens
	 */
	public function harvest(int $user_id, array $tokens, string $source): void {
		if ($user_id <= 0 || !count($tokens)) {
			return;
		}
		try {
			$vault = UserEncryptionVault::loadForUser($user_id);
			$secret = ($vault !== null) ? VaultUnlock::secretKey($user_id) : null;
			// A vault holder whose window is closed: can't compute the keyed hash or
			// read for dedup — skip harvest (opportunistic, re-warms next in-window op).
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
				$this->upsertOne($user_id, $addr, $name, $source, $vault, $secret);
			}
		} catch (\Throwable $e) {
			error_log('MailboxContacts::harvest failed for user ' . $user_id . ': ' . $e->getMessage());
		}
	}

	private function upsertOne(int $user_id, string $addr, string $name, string $source, ?UserEncryptionVault $vault, ?string $secret): void {
		$hash = $this->addressHash($addr, $secret);
		$db = $this->db();

		$existing = $this->findRow($user_id, $addr, $secret);
		if ($existing !== null) {
			$bump = $db->prepare('UPDATE imc_mailbox_contacts
				SET imc_use_count = imc_use_count + 1, imc_last_used_time = now()
				WHERE imc_mailbox_contact_id = ?');
			$bump->execute(array(intval($existing['imc_mailbox_contact_id'])));
			return;
		}

		$row = new MailboxContact(NULL);
		$row->set('imc_usr_user_id', $user_id);
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

	/** The row for one normalized address, or null when the user has none. */
	private function findRow(int $user_id, string $normalized, ?string $secret): ?array {
		$stmt = $this->db()->prepare('SELECT * FROM imc_mailbox_contacts
			WHERE imc_usr_user_id = ? AND imc_address_hash = ? LIMIT 1');
		$stmt->execute(array($user_id, $this->addressHash($normalized, $secret)));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row : null;
	}

	// ── lookup ───────────────────────────────────────────────────────────────

	/**
	 * What the user's contact store knows about one address:
	 * ['id','name','source','saved','use_count','first_time','last_time'], null when there
	 * is no row, or ['locked'=>true] when a vault holder's window is closed — the keyed
	 * hash can't be computed then, so the state is unknown rather than absent, and the
	 * caller must not present it as "not a contact".
	 *
	 * 'saved' distinguishes a contact the user deliberately added from one the store
	 * merely warmed up on: every address in an opened thread is harvested, so presence
	 * alone says nothing about intent.
	 */
	public function lookup(int $user_id, string $address): ?array {
		$parsed = self::parseAddress($address);
		if ($user_id <= 0 || $parsed === null) {
			return null;
		}
		try {
			$vault = UserEncryptionVault::loadForUser($user_id);
			$secret = ($vault !== null) ? VaultUnlock::secretKey($user_id) : null;
			if ($vault !== null && $secret === null) {
				return array('locked' => true);
			}
			$row = $this->findRow($user_id, $parsed[0], $secret);
			if ($row === null) {
				return null;
			}
			$source = (string)$row['imc_source'];
			return array(
				'id'         => intval($row['imc_mailbox_contact_id']),
				'name'       => (string)MailboxContact::decryptSealedFieldStatic('imc_display_name', $row['imc_display_name'], $row),
				'source'     => $source,
				'saved'      => in_array($source, array(MailboxContact::SOURCE_MANUAL, MailboxContact::SOURCE_IMPORT), true),
				'use_count'  => intval($row['imc_use_count']),
				'first_time' => $row['imc_create_time'],
				'last_time'  => $row['imc_last_used_time'],
			);
		} catch (\Throwable $e) {
			error_log('MailboxContacts::lookup failed for user ' . $user_id . ': ' . $e->getMessage());
			return null;
		}
	}

	// ── list ─────────────────────────────────────────────────────────────────

	/**
	 * The user's contacts, decrypted, de-duplicated by address (a rotation can leave
	 * two rows for one address — keep the most-used), ranked use_count desc then
	 * recency. Returns ['contacts'=>[{id,address,name,use_count,source}], 'locked'=>bool].
	 * A vault holder with a closed window returns ['locked'=>true] and no contacts.
	 */
	public function listForUser(int $user_id): array {
		if ($user_id <= 0) {
			return array('contacts' => array());
		}
		$vault = UserEncryptionVault::loadForUser($user_id);
		if ($vault !== null && !VaultUnlock::isOpen($user_id)) {
			return array('contacts' => array(), 'locked' => true);
		}

		$db = $this->db();
		$stmt = $db->prepare('SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = ?
			ORDER BY imc_use_count DESC, imc_last_used_time DESC LIMIT ' . self::MAX_LIST);
		$stmt->execute(array($user_id));
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
	 * email(s) (everything else discarded) and upserts through the harvest path
	 * (source='import'). Returns ['imported'=>int, 'skipped'=>int]. A minimal, forgiving
	 * parser; a row/card with no valid email is skipped.
	 */
	public function import(int $user_id, string $content, string $filename): array {
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
			$this->harvest($user_id, $batch, MailboxContact::SOURCE_IMPORT);
		}
		return array('imported' => $imported, 'skipped' => $skipped);
	}

	/** Manual single add (the contacts management "add" form, the reader's Add button). */
	public function manualAdd(int $user_id, string $raw): bool {
		$parsed = self::parseAddress($raw);
		if ($parsed === null) {
			return false;
		}
		$this->harvest($user_id, array($raw), MailboxContact::SOURCE_MANUAL);
		// harvest() only bumps a row that already exists, and every address in an opened
		// thread is already harvested — so a deliberate add stamps the source itself,
		// which is what turns a merely-seen address into a saved contact.
		$this->markSaved($user_id, $parsed[0], $parsed[1]);
		return true;
	}

	/**
	 * Stamp an existing row as deliberately saved, filling in the display name when the
	 * add supplied one and the row has none. Best-effort: a locked vault holder or a
	 * missing row leaves the harvest result as it stands.
	 */
	private function markSaved(int $user_id, string $normalized, string $name): void {
		try {
			$vault = UserEncryptionVault::loadForUser($user_id);
			$secret = ($vault !== null) ? VaultUnlock::secretKey($user_id) : null;
			if ($vault !== null && $secret === null) {
				return;
			}
			$row = $this->findRow($user_id, $normalized, $secret);
			if ($row === null) {
				return;
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
		} catch (\Throwable $e) {
			error_log('MailboxContacts::markSaved failed for user ' . $user_id . ': ' . $e->getMessage());
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
	 * @return array{tokens: string[], empty: int}
	 */
	private function parseCsv(string $content): array {
		$lines = preg_split('/\r\n|\r|\n/', $content);
		if (!count($lines)) {
			return array();
		}
		$header = str_getcsv(array_shift($lines));
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
			$cells = str_getcsv($line);
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
