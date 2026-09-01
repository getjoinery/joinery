<?php
/**
 * Reconcile the sealed-secret registry against what is actually stored.
 *
 * Walks every category in the registry table, checks the health of each secret
 * of that kind, and acts on the dead ones BY CATEGORY:
 *
 *   regenerable                the machine re-mints it, no consequence. Heal it,
 *                              silently. (Cold-only — see below.)
 *   regenerable-breaks-things  the machine could re-mint, but doing so unpairs
 *                              devices / drops pinned peers. Flag and wait for an
 *                              explicit operator OK; never touch it here.
 *   operator                   only a human has the value. Flag "needs re-entry";
 *                              never touch it.
 *   ephemeral                  a per-run value. A dead one is just discarded.
 *
 * Heal is COLD-ONLY on purpose. Re-minting writes a fresh SecretBox blob — a long
 * non-vault string — and SealedEgressGuard refuses exactly that write once a
 * request has opened sealed content. The reconciler runs cold (update_database,
 * or an operator "reconcile now"), so its heals are safe; a hot request that
 * finds a regenerable secret dead treats it as absent and lets the next cold pass
 * mint it.
 *
 * The cached verdict it writes back to each row (ssr_last_state / ssr_dead_count)
 * is what the setup-wizard pill and the management-node stats blob read, so
 * neither has to walk a live decrypt of every row on every admin request.
 *
 * @version 1.1 - an enumerator is consulted only while its plugin is active, and one that
 *                throws falls back to the code-free locator path instead of aborting the run
 * @version 1.0
 */
class SecretReconciler {

	/** The signal raised when a secret a human must act on goes dead. */
	const SIGNAL_UNREADABLE = 'secret.unreadable';

	/**
	 * Run a full reconcile.
	 *
	 * @param array $opts  'acting_user_id' => int for an operator-triggered run
	 *                     (audits the re-mint against them); omitted for the
	 *                     unattended update_database run.
	 * @return array{healed:int, needs_attention:int, dead_operator:int,
	 *               dead_needs_ack:int, dead_low:int, discarded:int,
	 *               key_mismatch:bool, summary:string}
	 */
	public static function reconcile(array $opts = array()): array {
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		require_once(PathHelper::getIncludePath('includes/SealedSecretsDeclarations.php'));
		require_once(PathHelper::getIncludePath('data/sealed_secret_registry_class.php'));

		$acting_user_id = isset($opts['acting_user_id']) ? (int)$opts['acting_user_id'] : null;

		// Heal and mint write long SecretBox blobs, which SealedEgressGuard refuses
		// once a request has opened sealed content. The reconciler's callers are
		// cold today; this makes the docblock's promise mechanical rather than
		// assumed, so a future hot caller degrades to count-and-flag instead of a
		// mid-loop fatal.
		$cold = self::is_cold();

		// A canary must exist before we can read a mass-death verdict from it.
		// Minting is a cold write; the read is always safe. Wrapped so a site with
		// no secret_box_key configured degrades rather than fatalling the run (or
		// 500-ing the "Reconcile now" action).
		$canary = SecretBox::OPEN_ABSENT;
		try {
			if ($cold) { SecretBox::provisionCanary(); }
			$canary = SecretBox::canaryState();
		} catch (\Throwable $e) { /* no key configured — treat as absent */ }

		$out = array(
			'healed' => 0, 'needs_attention' => 0,
			'dead_operator' => 0, 'dead_needs_ack' => 0, 'dead_low' => 0,
			'discarded' => 0, 'key_mismatch' => false, 'summary' => '',
		);
		$newly_dead = array();   // categories that transitioned into dead this run

		$rows = new MultiSealedSecretRegistry(array(), array('ssr_locator' => 'ASC'));
		foreach ($rows as $row) {
			$locator   = (string)$row->get('ssr_locator');
			$kind      = (string)$row->get('ssr_kind');
			$prior     = (string)$row->get('ssr_last_state');
			$is_orphan = $row->is_orphan();

			$inspect = self::inspect_category($row, $is_orphan);
			$new_state = $inspect['dead'] > 0 ? SealedSecretRegistry::STATE_DEAD
				: ($inspect['present'] > 0 ? SealedSecretRegistry::STATE_OK : SealedSecretRegistry::STATE_ABSENT);

			$transitioned_into_dead = ($prior !== SealedSecretRegistry::STATE_DEAD)
				&& ($new_state === SealedSecretRegistry::STATE_DEAD);

			if ($inspect['dead'] > 0) {
				if ($kind === 'ephemeral') {
					// A dead per-run value is meaningless — discard it so it stops
					// reading as a fault, never heal or alert.
					$out['discarded'] += self::discard_dead($row);
				} elseif ($kind === 'regenerable' && !$is_orphan) {
					// No-consequence heal — but only when cold. A hot pass leaves the
					// dead value; a hot request already treats it as absent, and the
					// next cold pass mints it. (regenerable never alerts, so a dead
					// one left for the next pass surfaces nothing.)
					if ($cold && self::heal_regenerable($row, $acting_user_id)) {
						$out['healed']++;
						$new_state = SealedSecretRegistry::STATE_OK;
					}
				} else {
					// operator, regenerable-breaks-things, or an orphan of any kind:
					// flag, do not touch. Severity depends on who owns it.
					$out['needs_attention']++;
					$severity = self::severity_for($row, $is_orphan);
					if     ($severity === 'operator') $out['dead_operator']++;
					elseif ($severity === 'needs_ack') $out['dead_needs_ack']++;
					else                               $out['dead_low']++;

					if ($transitioned_into_dead && $severity !== 'low') {
						$newly_dead[] = array('row' => $row, 'severity' => $severity);
					}
				}
			}

			// Opportunistically reseal any legacy plaintext (B3), cold only — the
			// write is a >64-char blob the egress guard refuses in a hot process.
			if ($cold) { self::reseal_plaintext($row); }

			// Write back the cached verdict the pill and the stats blob read.
			self::server_write(function () use ($row, $new_state, $inspect) {
				$row->set('ssr_last_state', $new_state);
				$row->set('ssr_dead_count', $inspect['dead']);
				$row->set('ssr_checked_time', gmdate('Y-m-d H:i:s'));
				$row->save();
			});
		}

		// Mass-death verdict: if the canary itself is dead, the key is wrong and
		// everything is dead together — one batched alert, not one per category.
		$out['key_mismatch'] = ($canary === SecretBox::OPEN_DEAD)
			&& ($out['dead_operator'] + $out['dead_needs_ack']) > 1;

		self::alert($newly_dead, $out);

		// Track the new key going forward, so a subsequent run reports individual
		// deaths rather than re-diagnosing the mismatch. Done AFTER the verdict, and
		// only cold (it re-mints a blob).
		if ($cold && $canary === SecretBox::OPEN_DEAD) {
			self::server_write(function () {
				$dblink = DbConnector::get_instance()->get_db_link();
				$dblink->prepare('DELETE FROM stg_settings WHERE stg_name = ?')
					->execute(array(SecretBox::CANARY_SETTING));
				SecretBox::provisionCanary();
			});
		}

		$out['summary'] = "healed {$out['healed']}, needs attention: "
			. ($out['dead_operator'] + $out['dead_needs_ack'])
			. ($out['dead_low'] ? " (+{$out['dead_low']} low)" : '')
			. ($out['discarded'] ? ", discarded {$out['discarded']} stale" : '')
			. ($cold ? '' : ' (ran hot — heals/mints deferred to a cold pass)');
		return $out;
	}

	/**
	 * The cached health verdict, read from ssr_last_state — NO decrypt walk.
	 *
	 * This is the single computation the setup-wizard pill and the management
	 * node's stats blob both read, so neither pays a per-request decrypt of every
	 * row (which on a mail-heavy site means every iem_account). The verdict is
	 * kept current by reconcile(), which writes ssr_last_state on every pass.
	 *
	 * @return array{operator:int, needs_ack:int, low:int}
	 */
	public static function attention_verdict(): array {
		require_once(PathHelper::getIncludePath('data/sealed_secret_registry_class.php'));
		$out = array('operator' => 0, 'needs_ack' => 0, 'low' => 0);
		try {
			$rows = new MultiSealedSecretRegistry(
				array('last_state' => SealedSecretRegistry::STATE_DEAD), array('ssr_locator' => 'ASC'));
			foreach ($rows as $row) {
				$severity = self::severity_for($row, $row->is_orphan());
				if     ($severity === 'operator')  $out['operator']++;
				elseif ($severity === 'needs_ack') $out['needs_ack']++;
				else                               $out['low']++;
			}
		} catch (\Throwable $e) { /* table not built yet — nothing to report */ }
		return $out;
	}

	/**
	 * Structured description of every currently-dead secret, for the admin acting
	 * page. Reads the cached ssr_last_state — no decrypt walk.
	 *
	 * @return array<array{locator:string, label:string, feature:string, kind:string,
	 *   severity:string, orphan:bool, can_remint:bool, is_singleton:bool}>
	 */
	public static function dead_items(): array {
		require_once(PathHelper::getIncludePath('data/sealed_secret_registry_class.php'));
		$items = array();
		$rows = new MultiSealedSecretRegistry(
			array('last_state' => SealedSecretRegistry::STATE_DEAD), array('ssr_feature' => 'ASC'));
		foreach ($rows as $row) {
			$orphan = $row->is_orphan();
			$reprovision = (string)$row->get('ssr_reprovision');
			$items[] = array(
				'locator'    => (string)$row->get('ssr_locator'),
				'label'      => (string)$row->get('ssr_label'),
				'feature'    => (string)$row->get('ssr_feature'),
				'kind'       => (string)$row->get('ssr_kind'),
				'severity'   => self::severity_for($row, $orphan),
				'orphan'     => $orphan,
				// A destructive re-mint is offered only when the code that can do it
				// is present (declared reprovision) and the plugin is not gone.
				'can_remint' => !$orphan && $row->get('ssr_kind') === 'regenerable-breaks-things'
					&& $reprovision !== '' && strpos($reprovision, '::') !== false && is_callable($reprovision),
				'is_singleton' => strpos((string)$row->get('ssr_locator'), '.') === false
					&& strpos((string)$row->get('ssr_locator'), 'session:') !== 0,
			);
		}
		return $items;
	}

	/**
	 * Acknowledge and perform a destructive re-mint of one regenerable-breaks-things
	 * secret. Cold path (an admin POST), so the fresh-blob write is allowed. Audits
	 * against the acting user — this unpaired devices / dropped peers and a human
	 * authorized it. Returns true on success.
	 */
	public static function acknowledge_remint(string $locator, int $acting_user_id): bool {
		require_once(PathHelper::getIncludePath('data/sealed_secret_registry_class.php'));
		require_once(PathHelper::getIncludePath('includes/SealedSecretsDeclarations.php'));
		$d = SealedSecretsDeclarations::get($locator);
		if ($d === null || ($d['kind'] ?? '') !== 'regenerable-breaks-things' || empty($d['reprovision'])) {
			return false;
		}
		$reprovision = (string)$d['reprovision'];
		if (strpos($reprovision, '::') === false || !is_callable($reprovision)) return false;
		// A re-mint writes a fresh blob; refuse if the process is hot rather than
		// tripping the egress guard mid-request.
		if (!self::is_cold()) return false;

		$ok = false;
		self::server_write(function () use ($locator, $reprovision, &$ok) {
			$dblink = DbConnector::get_instance()->get_db_link();
			if (strpos($locator, '.') === false) {
				$dblink->prepare('UPDATE stg_settings SET stg_value = \'\' WHERE stg_name = ?')->execute(array($locator));
			}
			$ok = (bool)call_user_func($reprovision);
		});
		if ($ok) {
			self::audit('secret.remint', $acting_user_id,
				"Operator-acknowledged re-mint of {$locator} — prior paired state was invalidated.");
		}
		return $ok;
	}

	/**
	 * The health of every secret in one category.
	 *
	 * Prefers the declared enumerator (exact blobs, works for wrapped columns
	 * like backup_target's jsonb {"enc":...}); falls back to the code-free
	 * locator, which is all an orphan of a deleted plugin leaves behind.
	 *
	 * @return array{present:int, dead:int}
	 */
	private static function inspect_category($row, bool $is_orphan): array {
		$locator = (string)$row->get('ssr_locator');
		$present = 0; $dead = 0;
		$box = null;
		try { $box = new SecretBox(); } catch (\Throwable $e) { /* no key: everything dead */ }

		$classify = function (?string $blob) use (&$present, &$dead, $box) {
			if ($blob === null || $blob === '') return;         // absent — not counted as present
			$present++;
			if ($box === null) { $dead++; return; }
			// A wrapped column ({"enc":"v1..."}) is not itself a blob. Pull the
			// embedded blob out so a dead WRAPPED secret is still seen on the
			// code-free path — e.g. when the owning plugin is deactivated and its
			// enumerator cannot resolve. A genuine plaintext value has no such
			// substring and stays "present, not dead".
			$candidate = $blob;
			if (!SecretBox::looksEncrypted($blob)
					&& preg_match('/v1\.(?:sodium|aesgcm)\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', $blob, $m)) {
				$candidate = $m[0];
			}
			if ($box->open($candidate)['state'] === SecretBox::OPEN_DEAD) $dead++;
		};

		// The declared enumerator belongs to its owning plugin's code, and is
		// only consulted while that plugin is active: an installed-but-inactive
		// plugin's classes may still resolve, and its tables may never have
		// been created. An enumerator that throws — a table gone with the
		// plugin, a query against a schema this deployment never had — is
		// logged and the category falls through to the code-free path below,
		// which tolerates a missing table. One category can never abort the
		// reconcile of every other secret.
		$enumerator = (string)$row->get('ssr_enumerator');
		$source = (string)$row->get('ssr_source');
		if (!$is_orphan && $enumerator !== '' && strpos($enumerator, '::') !== false
				&& ($source === '' || $source === 'core' || self::plugin_active($source))
				&& is_callable($enumerator)) {
			try {
				foreach ((array)call_user_func($enumerator) as $entry) {
					$classify(is_array($entry) ? ($entry['blob'] ?? null) : (string)$entry);
				}
				return array('present' => $present, 'dead' => $dead);
			} catch (\Throwable $e) {
				error_log('SecretReconciler: enumerator ' . $enumerator . ' for ' . $locator
					. ' failed, falling back to the locator: ' . $e->getMessage());
				$present = 0; $dead = 0;
			}
		}

		// Code-free path. A singleton locator is a setting name; a row-scoped one
		// is "table.column".
		$dblink = DbConnector::get_instance()->get_db_link();
		if (strpos($locator, '.') === false) {
			$q = $dblink->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array($locator));
			$classify(($v = $q->fetchColumn()) === false ? null : (string)$v);
		} else {
			list($table, $column) = explode('.', $locator, 2);
			if (preg_match('/^[a-z0-9_]+$/i', $table) && preg_match('/^[a-z0-9_]+$/i', $column)) {
				try {
					$q = $dblink->query("SELECT \"{$column}\" FROM \"{$table}\" WHERE \"{$column}\" IS NOT NULL");
					foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) {
						// Without the owning code we cannot unwrap a jsonb envelope,
						// so for an orphan we only count presence, never dead-ness.
						if ($is_orphan) { if ($v !== null && $v !== '') $present++; }
						else $classify((string)$v);
					}
				} catch (\Throwable $e) { /* table gone with the plugin */ }
			}
		}
		return array('present' => $present, 'dead' => $dead);
	}

	/** Heal a regenerable secret: clear the dead value, run its reprovision recipe. */
	private static function heal_regenerable($row, ?int $acting_user_id): bool {
		$locator     = (string)$row->get('ssr_locator');
		$reprovision = (string)$row->get('ssr_reprovision');
		if ($reprovision === '' || strpos($reprovision, '::') === false) return false;

		$ok = false;
		self::server_write(function () use ($locator, $reprovision, &$ok) {
			$dblink = DbConnector::get_instance()->get_db_link();
			if (strpos($locator, '.') === false) {
				$dblink->prepare('UPDATE stg_settings SET stg_value = \'\' WHERE stg_name = ?')
					->execute(array($locator));
			}
			if (is_callable($reprovision)) {
				$ok = (bool)call_user_func($reprovision);
			}
		});

		if ($ok) {
			// A key was rotated and outstanding signed URLs invalidated — the exact
			// silent change someone asks about later. Audit it (no user: automatic).
			self::audit('secret.remint', $acting_user_id ?? 0,
				"Auto-healed regenerable secret {$locator} (re-minted; prior value invalidated).");
		}
		return $ok;
	}

	/**
	 * Reseal any legacy plaintext value in this category (B3). Cold-only caller.
	 *
	 * Handles the two code-free storage shapes — a singleton setting and a
	 * bare-blob row column. A WRAPPED column (declared enumerator) is left to its
	 * consumer, which reseals on its next save; the reconciler cannot re-wrap a
	 * {"enc":…} envelope generically. Idempotent: once a value is sealed it is no
	 * longer plaintext, so a later pass skips it.
	 */
	private static function reseal_plaintext($row): void {
		// Never rewrite a deleted plugin's data — and its locator is no longer
		// declared, so seal() would throw and abort the whole reconcile.
		if ($row->is_orphan()) return;
		$locator = (string)$row->get('ssr_locator');
		if (strpos($locator, 'session:') === 0) return;
		if ((string)$row->get('ssr_enumerator') !== '') return;   // wrapped — consumer's job

		$box = null;
		try { $box = new SecretBox(); } catch (\Throwable $e) { return; }

		self::server_write(function () use ($locator, $box) {
			$dblink = DbConnector::get_instance()->get_db_link();
			if (strpos($locator, '.') === false) {
				$q = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
				$q->execute(array($locator));
				$v = $q->fetchColumn();
				if ($v !== false && $v !== '' && !SecretBox::looksEncrypted((string)$v)) {
					$dblink->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?")
						->execute(array($box->seal($locator, (string)$v), $locator));
				}
				return;
			}
			list($table, $column) = explode('.', $locator, 2);
			if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return;
			if (!$dblink->query("SELECT to_regclass('" . $table . "') IS NOT NULL")->fetchColumn()) return;
			$sel = $dblink->query("SELECT ctid, \"{$column}\" AS v FROM \"{$table}\""
				. " WHERE \"{$column}\" IS NOT NULL AND \"{$column}\" <> ''");
			foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
				if (SecretBox::looksEncrypted((string)$r['v'])) continue;
				// Bind the old value alongside ctid: a ctid that moved (concurrent
				// UPDATE) or was vacuum-reused for a different row then never matches,
				// so this destructive-ish rewrite can only touch the row we read.
				$dblink->prepare("UPDATE \"{$table}\" SET \"{$column}\" = ? WHERE ctid = ? AND \"{$column}\" = ?")
					->execute(array($box->seal($locator, (string)$r['v']), $r['ctid'], (string)$r['v']));
			}
		});
	}

	/** Discard the DEAD ephemeral value(s), leaving any live ones alone. */
	private static function discard_dead($row): int {
		$locator = (string)$row->get('ssr_locator');
		$cleared = 0;
		self::server_write(function () use ($locator, &$cleared) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$box = null;
			try { $box = new SecretBox(); } catch (\Throwable $e) { /* no key: everything dead */ }

			if (strpos($locator, '.') === false) {
				// Singleton: the category is dead because this one value is dead.
				$n = $dblink->prepare('UPDATE stg_settings SET stg_value = \'\' WHERE stg_name = ? AND stg_value <> \'\'');
				$n->execute(array($locator));
				$cleared = $n->rowCount() > 0 ? 1 : 0;
				return;
			}
			list($table, $column) = explode('.', $locator, 2);
			if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return;

			// Null ONLY the dead rows, addressed by ctid, so a live in-flight value
			// (an active relay-provisioning token, a device-link handshake mid-
			// ceremony) is never destroyed by a reconcile that happens to run during
			// it. "ephemeral" means a DEAD one is disposable, not every one.
			$sel = $dblink->query("SELECT ctid, \"{$column}\" AS v FROM \"{$table}\""
				. " WHERE \"{$column}\" IS NOT NULL AND \"{$column}\" <> ''");
			foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$is_dead = ($box === null) || ($box->open((string)$r['v'])['state'] === SecretBox::OPEN_DEAD);
				if (!$is_dead) continue;
				// Bind the old value with ctid so a moved/reused tuple slot can never
				// be clobbered by this null-out.
				$upd = $dblink->prepare("UPDATE \"{$table}\" SET \"{$column}\" = NULL WHERE ctid = ? AND \"{$column}\" = ?");
				$upd->execute(array($r['ctid'], (string)$r['v']));
				$cleared += $upd->rowCount();
			}
		});
		return $cleared;
	}

	/**
	 * How loud a dead secret is. A dead secret of a DEACTIVATED plugin, or an
	 * orphan of a deleted one, is low: nothing is breaking while the feature is
	 * off, but it will bite on reactivation.
	 */
	private static function severity_for($row, bool $is_orphan): string {
		$kind   = (string)$row->get('ssr_kind');
		$source = (string)$row->get('ssr_source');
		if ($is_orphan) return 'low';
		if ($source !== 'core' && !self::plugin_active($source)) return 'low';
		return $kind === 'regenerable-breaks-things' ? 'needs_ack' : 'operator';
	}

	private static function plugin_active(string $plugin): bool {
		try { return class_exists('PluginHelper') && PluginHelper::isPluginActive($plugin); }
		catch (\Throwable $e) { return false; }
	}

	/** Push the alert(s) for this run's newly-dead secrets. */
	private static function alert(array $newly_dead, array $out): void {
		if (!$newly_dead) return;
		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));

		// The in-system leg must always land — the outbound-mail path can itself
		// depend on a sealed credential, so it may be dead in exactly this
		// incident. Target every admin directly so a persistent notification is
		// created regardless of anyone's topic subscription.
		$recipients = self::admin_recipients();

		// One batched alert for a mass event, not a dozen — whether the canary
		// diagnosed a key mismatch, or simply many secrets died at once this run
		// (a manual clone with no canary row lands here too).
		if ($out['key_mismatch'] || count($newly_dead) >= 3) {
			$n = count($newly_dead);
			SignalBus::dispatch(self::SIGNAL_UNREADABLE, array(
				'label'      => "{$n} secrets unreadable",
				'feature'    => $out['key_mismatch'] ? 'Environment key mismatch' : 'Multiple secrets unreadable',
				'count'      => $n,
				'link'       => '/admin/admin_sealed_secrets',
				'recipients' => $recipients,
			));
			return;
		}
		foreach ($newly_dead as $item) {
			$row = $item['row'];
			SignalBus::dispatch(self::SIGNAL_UNREADABLE, array(
				'label'      => (string)$row->get('ssr_label'),
				'feature'    => (string)$row->get('ssr_feature'),
				'count'      => 1,
				'link'       => '/admin/admin_sealed_secrets',
				'recipients' => $recipients,
			));
		}
	}

	/** Every superadmin's user id — the people who must see a dead secret. */
	private static function admin_recipients(): array {
		$ids = array();
		try {
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->query('SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10 AND usr_delete_time IS NULL');
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id;
		} catch (\Throwable $e) { /* nobody to tell; the pill still shows */ }
		return $ids;
	}

	private static function audit(string $event, int $user_id, string $note): void {
		try {
			require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
			self::server_write(function () use ($event, $user_id, $note) {
				$log = new EventLog(NULL);
				$log->set('evl_event', $event);
				// NULL for an automatic (machine) heal — never fabricate attribution
				// to a real account. A real user id is set only for an operator ack.
				$log->set('evl_usr_user_id', $user_id > 0 ? $user_id : null);
				$log->set('evl_was_success', true);
				$log->set('evl_note', $note);
				$log->save();
			});
		} catch (\Throwable $e) {
			error_log("SecretReconciler audit ({$event}) failed: " . $e->getMessage());
		}
	}

	/**
	 * Every write here happens while a page may be a GET (the reconciler can run
	 * on an admin request, and the cold-read heal runs during a view). Mark them
	 * server-initiated so the GET-mutation tripwire lets them through. This does
	 * NOT lift the egress guard — that is handled by staying cold (is_cold).
	 */
	private static function server_write(callable $unit): void {
		SystemBase::server_initiated_write($unit);
	}

	/**
	 * Is the process cold enough to write a fresh SecretBox blob? SealedEgressGuard
	 * refuses a long non-vault write once a request has opened sealed content, so
	 * heals and mints are gated on this. Absent guard (or an error reading it) is
	 * treated as cold — the guard is a core class present on every install.
	 */
	private static function is_cold(): bool {
		if (!class_exists('SealedEgressGuard')) return true;
		try { return !SealedEgressGuard::isHot(); } catch (\Throwable $e) { return true; }
	}
}
