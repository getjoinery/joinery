<?php
/**
 * SecretReplacementList - what to replace after a break-in.
 *
 * Code running as the web user can open every value SecretBox seals, since
 * PHP holds the key to use them (security_inventory.md S10). So after a
 * break-in every stored credential, token and signing key has to be treated
 * as read, and replaced. The sealed-secrets registry already names every kind
 * (docs/sealed_secrets.md); this turns it into a list a person works through:
 *
 *   - remint:  the machine makes a new one (regenerable, and
 *              regenerable-breaks-things with its consequence named). A kind
 *              held in one setting with a reprovision recipe is re-minted in
 *              place by the page.
 *   - reenter: a person enters a new one from the provider's console
 *              (operator).
 *   - nothing: per-run values (ephemeral), shown so the list is complete.
 *
 * Only kinds with a value stored here are listed. When a list opens it records
 * a short hash of every stored value (SecretReconciler::present_hashes()) in
 * its own table (SecretReplacementSnapshot), out of the settings every request
 * reads. A re-seal always changes the stored blob, so the list can tell a
 * value that was replaced from one that was not: a kind is marked done once
 * none of the values it held when the list opened is still stored, or closed
 * without replacing with a reason a person types (a registrant's contact
 * details, a customer server's password: some kinds are not this admin's to
 * replace). The list closes once every kind to act on is done. Its state is
 * one managed setting (secret_replacement_list), changed under a row lock so
 * two admins marking at once do not lose a mark.
 *
 * Not on the list, because SecretBox does not hold them: the SecretBox key
 * itself and the backup site key (config/), which re-keying covers, and the
 * other steps of a recovery (signing everyone out, reviewing what could have
 * been added). The page names them.
 *
 * @version 1.2 - snapshot in its own table, short hashes; close a kind without replacing, with a typed reason (reviewer2 B23, B24)
 * @version 1.1 - snapshot of stored values; done only when replaced; re-mint only for a setting; row lock;
 *                a kind whose plugin is off says so (reviewer2 B17-B21)
 * @version 1.0 (specs/security_weak_spots_2026_11.md WP6)
 */
class SecretReplacementList {

	const SETTING = 'secret_replacement_list';

	const GROUP_REMINT  = 'remint';
	const GROUP_REENTER = 'reenter';
	const GROUP_NOTHING = 'nothing';

	/** Hex characters kept of each value's SHA-256: enough to tell unchanged from replaced. */
	const HASH_CHARS = 16;

	/** Which group a kind falls in. */
	public static function groupFor(string $kind): string {
		if ($kind === 'operator') {
			return self::GROUP_REENTER;
		}
		if ($kind === 'ephemeral') {
			return self::GROUP_NOTHING;
		}
		return self::GROUP_REMINT;
	}

	/**
	 * Every kind of stored secret with a value here, in list order (remint,
	 * then reenter, then nothing; by feature and label within each).
	 *
	 * @param array|null $hashes locator => hashes of its stored values; read live when null (tests pass a fixture)
	 * @param array|null $list   the open list, whose snapshot gives 'unchanged'; current() when null
	 * @return array<array{locator:string, label:string, feature:string, kind:string, group:string,
	 *   count:int, unchanged:int, can_remint:bool, breaks_things:bool, plugin_off:bool}>
	 */
	public static function items(?array $hashes = null, ?array $list = null): array {
		require_once(PathHelper::getIncludePath('data/sealed_secret_registry_class.php'));
		$hashes = self::shorten($hashes ?? SecretReconciler::present_hashes());
		$list = $list ?? self::current();
		$snapshot = $list !== null ? self::snapshot($list) : array();
		$order = array(self::GROUP_REMINT => 0, self::GROUP_REENTER => 1, self::GROUP_NOTHING => 2);
		$items = array();
		foreach (new MultiSealedSecretRegistry(array(), array('ssr_locator' => 'ASC')) as $row) {
			$locator = (string)$row->get('ssr_locator');
			$now = (array)($hashes[$locator] ?? array());
			if (!$now || $row->is_orphan()) {
				continue;
			}
			$kind = (string)$row->get('ssr_kind');
			$reprovision = (string)$row->get('ssr_reprovision');
			$source = (string)$row->get('ssr_source');
			$then = (array)($snapshot[$locator] ?? array());
			$items[] = array(
				'locator'       => $locator,
				'label'         => (string)$row->get('ssr_label') !== '' ? (string)$row->get('ssr_label') : $locator,
				'feature'       => (string)$row->get('ssr_feature'),
				'kind'          => $kind,
				'group'         => self::groupFor($kind),
				'count'         => count($now),
				'unchanged'     => count(array_intersect($now, $then)),
				// One setting, re-minted by its recipe and checked to have changed
				// (SecretReconciler::acknowledge_remint()); a row-scoped kind's
				// recipe only provisions what is missing.
				'can_remint'    => $kind !== 'operator' && $kind !== 'ephemeral' && strpos($locator, '.') === false
					&& $reprovision !== '' && strpos($reprovision, '::') !== false && is_callable($reprovision),
				'breaks_things' => $kind === 'regenerable-breaks-things',
				'plugin_off'    => $source !== '' && $source !== 'core' && !PluginHelper::isActiveOfEitherKind($source),
				// One value per member: replacing them means every member enrols again.
				'per_member'    => strpos($locator, 'usr_users.') === 0,
			);
		}
		usort($items, function ($a, $b) use ($order) {
			return array($order[$a['group']], $a['feature'], $a['label']) <=> array($order[$b['group']], $b['feature'], $b['label']);
		});
		return $items;
	}

	/** The open list, or null when none is open. */
	public static function current(): ?array {
		$raw = trim((string)Globalvars::get_instance()->get_setting(self::SETTING, false, true));
		return self::decode($raw);
	}

	private static function decode(string $raw): ?array {
		if ($raw === '') {
			return null;
		}
		$list = json_decode($raw, true);
		if (!is_array($list) || empty($list['started'])) {
			return null;
		}
		$list['done'] = is_array($list['done'] ?? null) ? $list['done'] : array();
		return $list;
	}

	/**
	 * Open a list, recording a hash of every value stored now. One already
	 * open is kept, with its snapshot and what is marked done on it.
	 */
	public static function start(int $user_id, ?array $hashes = null): array {
		return self::change(function (?array $list) use ($user_id, $hashes) {
			if ($list !== null) {
				return $list;
			}
			$started = gmdate('Y-m-d H:i:s');
			self::writeSnapshot($started, self::shorten($hashes ?? SecretReconciler::present_hashes()));
			return array('started' => $started, 'started_by' => $user_id, 'done' => array());
		});
	}

	/** The open list's snapshot: locator => short hashes of what was stored when it opened. */
	public static function snapshot(array $list): array {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT srs_locator, srs_hash FROM srs_secret_replacement_snapshots WHERE srs_list_started = ?');
		$q->execute(array((string)$list['started']));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[$r['srs_locator']][] = $r['srs_hash'];
		}
		return $out;
	}

	/** locator => full hashes, cut to HASH_CHARS. */
	private static function shorten(array $hashes): array {
		$out = array();
		foreach ($hashes as $locator => $list) {
			$out[$locator] = array_map(function ($h) { return substr((string)$h, 0, self::HASH_CHARS); }, (array)$list);
		}
		return $out;
	}

	private static function writeSnapshot(string $started, array $hashes): void {
		$db = DbConnector::get_instance()->get_db_link();
		$rows = array();
		foreach ($hashes as $locator => $list) {
			foreach ($list as $h) {
				$rows[] = array($started, (string)$locator, (string)$h);
			}
		}
		foreach (array_chunk($rows, 500) as $chunk) {
			$db->prepare('INSERT INTO srs_secret_replacement_snapshots (srs_list_started, srs_locator, srs_hash) VALUES '
				. implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')))->execute(array_merge(...$chunk));
		}
	}

	/**
	 * Mark one kind done, or not done. Returns '' on success, else why not:
	 * no list open, not a kind to act on, or values still unchanged with no
	 * reason given. A $reason closes a kind without replacing it (one this
	 * admin cannot replace); it is recorded with who and when, and the count
	 * of values left unchanged stays on the row.
	 */
	public static function mark(string $locator, bool $done, int $user_id, ?array $items = null, string $reason = ''): string {
		$why = '';
		$reason = trim($reason);
		self::change(function (?array $list) use ($locator, $done, $user_id, $items, $reason, &$why) {
			if ($list === null) {
				$why = 'No replacement list is open.';
				return null;
			}
			$item = null;
			foreach (self::tickable($items ?? self::items(null, $list)) as $i) {
				if ($i['locator'] === $locator) {
					$item = $i;
				}
			}
			if ($item === null) {
				$why = 'That is not a kind to replace on this list.';
				return $list;
			}
			if ($done && $item['unchanged'] > 0 && $reason === '') {
				$why = $item['unchanged'] . ' of the ' . $item['label'] . ' values stored when the list opened '
					. ($item['unchanged'] === 1 ? 'is' : 'are') . ' still unchanged. Replace '
					. ($item['unchanged'] === 1 ? 'it' : 'them') . ' first, or close it without replacing and say why.';
				return $list;
			}
			if ($done) {
				$entry = array('at' => gmdate('Y-m-d H:i:s'), 'by' => $user_id);
				if ($item['unchanged'] > 0) {
					$entry['reason'] = mb_substr($reason, 0, 500);
					$entry['unchanged'] = $item['unchanged'];
				}
				$list['done'][$locator] = $entry;
			} else {
				unset($list['done'][$locator]);
			}
			return $list;
		});
		return $why;
	}

	/** The items a person has to act on: everything but the per-run values. */
	public static function tickable(array $items): array {
		return array_values(array_filter($items, function ($i) { return $i['group'] !== self::GROUP_NOTHING; }));
	}

	/** How many of the items to act on are marked done, of how many. */
	public static function progress(?array $list, array $items): array {
		$tickable = self::tickable($items);
		$done = 0;
		foreach ($tickable as $i) {
			if (isset($list['done'][$i['locator']])) {
				$done++;
			}
		}
		return array('done' => $done, 'total' => count($tickable));
	}

	/** Close the list. Refused until every item to act on is done. */
	public static function finish(?array $items = null): bool {
		$closed = false;
		self::change(function (?array $list) use ($items, &$closed) {
			if ($list === null) {
				return null;
			}
			$p = self::progress($list, $items ?? self::items(null, $list));
			if ($p['done'] < $p['total']) {
				return $list;
			}
			$closed = true;
			DbConnector::get_instance()->get_db_link()
				->prepare('DELETE FROM srs_secret_replacement_snapshots WHERE srs_list_started = ?')
				->execute(array((string)$list['started']));
			return null;
		});
		return $closed;
	}

	/**
	 * The admin-header notice while a list is open, to superadmins: it stays
	 * in view until the list is closed. Reads only the setting (no count of
	 * stored values), since it renders on every admin page.
	 */
	public static function notice(): string {
		if (!Access::permits(10)) {
			return '';
		}
		$list = self::current();
		if ($list === null) {
			return '';
		}
		$done = count($list['done']);
		return '<style>.jy-replace-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;'
			. 'background:#fffbeb;color:#78350f;font-size:.95rem}</style>'
			. '<div class="jy-replace-notice" role="status"><strong>Stored secrets are being replaced after a break-in.</strong> '
			. 'The list opened ' . htmlspecialchars((string)$list['started'], ENT_QUOTES, 'UTF-8') . ' UTC has '
			. $done . ' kind' . ($done === 1 ? '' : 's') . ' marked done so far. '
			. '<a href="/admin/admin_sealed_secrets">Continue the list</a>.</div>';
	}

	/**
	 * Read, change and write the list under a lock on its settings row, so two
	 * admins marking at once do not lose a mark. $fn gets the open list (or
	 * null) and returns the list to store, or null to close it.
	 */
	private static function change(callable $fn): ?array {
		$db = DbConnector::get_instance()->get_db_link();
		$own = !$db->inTransaction();
		if ($own) {
			$db->beginTransaction();
		}
		try {
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ? FOR UPDATE');
			$q->execute(array(self::SETTING));
			$raw = $q->fetchColumn();
			$list = $fn(self::decode($raw === false ? '' : trim((string)$raw)));
			Setting::put(self::SETTING, $list === null ? '' : json_encode($list));
			if ($own) {
				$db->commit();
			}
			return $list;
		} catch (\Throwable $e) {
			if ($own && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}
}
?>
