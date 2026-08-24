<?php
/**
 * RelaySpoolConsumer - pull sealed blobs off the relay and store them durably.
 *
 * (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 4). The relay
 * runs no bespoke daemon: it spools <spoolid>.seal + <spoolid>.meta pairs, and
 * the main box dials out over WireGuard on a short poll to collect them. This
 * consumer:
 *
 *   1. rsyncs new entries COPY-ONLY (never --remove-source-files — that would
 *      delete before durability).
 *   2. Stores each durably with an idempotent store keyed on the spool id (a
 *      re-pull of an un-acked-but-stored item is a no-op = dedup):
 *        - key_kind=transport (Standard/Private): open the blob with the ambient
 *          transport secret and run today's store ingest (no re-forwarding — the
 *          relay already forwarded forward-mode aliases).
 *        - key_kind=user (Fortress): store a pending-parse row with the sealed
 *          blob; DeferredIngest parses it at the next unlock.
 *   3. Deletes the remote entries it durably stored — the delete-after-store IS
 *      the ack. A crash between store and delete just re-pulls, and the idempotent
 *      store makes that a no-op.
 *
 * Degradation is safe by construction: relay down → senders' MTAs retry; tunnel
 * down → the relay keeps spooling until the next successful pull.
 *
 * The pull runs over the deployment's RESTRICTED TENANT ACCOUNT on the relay
 * (specs/mailbox_relay_shared_fleet.md): the rsync is pinned to this tenant's
 * own spool subdirectory and the ack is the tenant shell's joinery-ack verb —
 * ids only, no paths, no root.
 *
 * @version 1.10 - the transport-key store path runs the deliverability report
 *   detector before storeMessage (specs/deliverability_report_ingest.md): the
 *   relay pull reaches storeMessage without passing processEmail, so this is
 *   its plaintext moment — a recognised report is filed and the spool entry
 *   acked, never stored as mail
 * @version 1.9 - a .direct needs no .meta sidecar (the container is
 *   self-describing and the sidecar's fields are never read), and a .seal is
 *   dedup-checked before its sidecar is required — both so an entry whose
 *   sidecar an older joinery-ack deleted (it removed only .seal + .meta,
 *   leaving acked .direct artifacts orphaned) resolves instead of throwing
 *   "missing .meta" on every pull forever
 * @version 1.8 - one pull at a time: pull() takes a per-relay advisory
 *   try-lock at the chokepoint both the scheduled reconcile and the reader's
 *   check-mail action pass through; a second pull reports 'skipped' instead of
 *   racing the first on the same staged blobs
 * @version 1.7 - the seal-target hold never ages out: the relay said 250, so
 *   dropping the blob later would be silent loss of accepted mail
 * @version 1.6 - a protected mailbox with no key to seal to holds the blob on
 *   the relay instead of storing it in plaintext
 * @version 1.5 - pull() reports 'seals' and 'errors' so a caller can tell an empty
 *                spool from an unproductive pass (the drain-before-wipe gate)
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/SRSRewriter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

class RelaySpoolConsumer {

	/** Safety cap on entries processed per pull pass. */
	const DEFAULT_MAX = 500;

	/** Advisory-lock class for the per-relay pull lock (74211/74212 are taken). */
	const PULL_LOCK_CLASS = 74213;

	/** @var MailboxRelay */
	private $relay;
	/** @var InboundEmailRouter */
	private $router;
	/** @var string|null lazily-opened ambient transport secret */
	private $transport_secret = null;

	public function __construct(MailboxRelay $relay) {
		$this->relay = $relay;
		$this->router = new InboundEmailRouter();
	}

	/**
	 * Pull, store, and ack one batch. Returns
	 * ['status'=>'success'|'error'|'skipped', 'message'=>..., 'stored'=>int, 'pending'=>int, 'acked'=>int].
	 */
	public function pull(int $max = self::DEFAULT_MAX): array {
		if (RelaySsh::host($this->relay) === '') {
			return array('status' => 'skipped', 'message' => 'relay has no tunnel host yet');
		}

		// One pull per relay at a time, enforced here at the chokepoint every pull
		// path passes through (the scheduled reconcile AND the reader's check-mail
		// action). A second caller reports 'skipped' — the running pull is already
		// bringing in the same blobs, so there is nothing for it to add.
		$db = DbConnector::get_instance()->get_db_link();
		$lock = $db->prepare('SELECT pg_try_advisory_lock(?, ?)');
		$lock->execute(array(self::PULL_LOCK_CLASS, intval($this->relay->key)));
		if (!$lock->fetchColumn()) {
			return array('status' => 'skipped', 'message' => 'another pull is already running');
		}
		try {
			return $this->pullLocked($max);
		} finally {
			// Session advisory locks are reentrant per connection, so this unlock
			// pairs exactly with the acquire above whatever the body did.
			$unlock = $db->prepare('SELECT pg_advisory_unlock(?, ?)');
			$unlock->execute(array(self::PULL_LOCK_CLASS, intval($this->relay->key)));
		}
	}

	private function pullLocked(int $max): array {
		$spool_path = $this->relay->spoolPath();

		$stage = $this->stageDir();
		if ($stage === null) {
			return array('status' => 'error', 'message' => 'could not create local staging dir');
		}

		try {
			// Copy-only pull of complete entries; the tmp/ working dir is excluded so
			// a half-written entry is never seen.
			$cmd = RelaySsh::rsyncCommand(
				$this->relay,
				$stage . '/',
				$spool_path . '/',
				true, // download
				array('--exclude=tmp/', "--include=*.seal", "--include=*.direct", "--include=*.meta", "--exclude=*")
			);
			list($code, $out) = RelaySsh::run($cmd);
			if ($code !== 0) {
				return array('status' => 'error', 'message' => 'rsync pull failed: ' . $out);
			}

			// Two artifact kinds share one spool and one listing: `.seal` is a
			// sealed MIME message from the MX path, `.direct` is a Joinery Direct
			// delivery the relay verified at its edge. Both commit their .meta
			// first, so seeing the artifact means the pair is complete.
			$seals = array_merge(glob($stage . '/*.seal') ?: array(), glob($stage . '/*.direct') ?: array());
			$stored = 0; $pending = 0; $errors = 0; $held = 0;
			$acked_ids = array();

			foreach ($seals as $seal_path) {
				if (($stored + $pending + $errors + $held) >= $max) {
					break;
				}
				$is_direct = (substr($seal_path, -7) === '.direct');
				$spool_id = basename($seal_path, $is_direct ? '.direct' : '.seal');
				$meta_path = $stage . '/' . $spool_id . '.meta';
				try {
					$outcome = $is_direct
						? $this->ingestDirect($seal_path, $meta_path, $spool_id)
						: $this->ingestOne($seal_path, $meta_path, $spool_id);
					if ($outcome === 'stored')  { $stored++; }
					if ($outcome === 'pending') { $pending++; }
					// 'hold' is recoverable mail we deliberately leave on the relay
					// (domain disabled/unconfigured, or Fortress owner not yet
					// resolvable) — do NOT ack it, so a later pull stores it once the
					// domain returns or the owner resolves. It is NOT an error, so it
					// does not inflate the error count or log per pass; the aggregate
					// held count below is the operator-visible signal. Every other
					// non-throwing outcome (stored/pending/dedup/bounce/unroutable/
					// aged_out) is durable or a deliberate ack-drop → safe to ack.
					if ($outcome === 'hold') {
						$held++;
					} else {
						$acked_ids[] = $spool_id;
					}
				} catch (\Throwable $e) {
					$errors++;
					error_log('RelaySpoolConsumer: failed on ' . $spool_id . ': ' . $e->getMessage());
					// Do NOT ack — leave it on the relay for the next pull.
				}
			}

			$acked = $this->ack($spool_path, $acked_ids);

			if ($held > 0) {
				// One aggregate line per pass — never per-blob (the pull runs every
				// cron pass and held blobs persist across passes).
				error_log('RelaySpoolConsumer: ' . $held . ' blob(s) HELD on the relay '
					. '(domain disabled/unconfigured, Fortress owner unresolved, or a protected '
					. 'mailbox with no key to seal to) — recoverable; the Setup tab names a '
					. 'sealing mailbox that needs repair.');
			}

			$this->relay->set('mrl_last_pull_time', gmdate('Y-m-d H:i:s'));
			$this->relay->set('mrl_last_pull_held', $held);
			$this->relay->save();

			$status = $errors > 0 && ($stored + $pending) === 0 ? 'error' : 'success';
			$msg = sprintf('pulled %d entr(y/ies): %d stored, %d pending-parse, %d held, %d acked, %d error(s)',
				count($seals), $stored, $pending, $held, $acked, $errors);
			// 'seals' is what the relay still had at the start of this pass, and it
			// is the only way a caller can tell "the spool is empty" from "this pass
			// moved nothing". A drain-before-wipe depends on that distinction.
			return array('status' => $status, 'message' => $msg, 'seals' => count($seals),
				'stored' => $stored, 'pending' => $pending, 'held' => $held,
				'acked' => $acked, 'errors' => $errors);
		} finally {
			$this->cleanup($stage);
		}
	}

	/**
	 * Store one spool entry. Returns one of:
	 *   'stored' | 'pending' | 'dedup' | 'bounce'  — durable (or handled) → ack;
	 *   'unroutable' — genuinely undeliverable (no/malformed recipient) → ack-drop
	 *                  with a loud log;
	 *   'hold'       — recoverable mail whose domain is disabled/unconfigured or
	 *                  whose Fortress owner is not yet resolvable → do NOT ack,
	 *                  leave on the relay for a later pull (Fixes 6/7);
	 *   'aged_out'   — a held blob past the grace window → ack-drop with a loud log.
	 * Throws only on a real failure (so the caller leaves it un-acked to retry).
	 */
	/**
	 * Store one Joinery Direct delivery the relay accepted on this box's behalf.
	 *
	 * The relay already did the half that needs no vault — verified the instance
	 * signature against the sender domain's published key, and verified every
	 * sealed-byte hash — so what arrives here is authenticated. What it is NOT is
	 * authorized: the contact gate needs the sealed contact list, which only an
	 * unlock window can read. So the delivery goes into the Direct spool exactly
	 * as one accepted locally at a sealed tier does, and the framework gates and
	 * ingests it at the next unlock. One deferred path, not two.
	 *
	 * The box does NOT re-verify the hashes here. It cannot: the parts are
	 * sealed to the recipient's vault key, and the signature that binds them was
	 * checked against the sender's published key at the edge — the same check
	 * the box would make, against the same key, from the same DNS. What the box
	 * does re-check is at unlock, where the sealed bytes are opened and their
	 * recorded hash is what the Direct framework verified them under.
	 */
	private function ingestDirect(string $data_path, string $meta_path, string $spool_id): string {
		// No .meta requirement: the container is self-describing (recipient, kind,
		// nonce, key kind all travel inside it) and nothing below reads the sidecar.
		// The writer commits .meta first and the artifact is temp-name + rename, so
		// a visible .direct is complete — a missing sidecar means it was deleted
		// afterwards (an older joinery-ack removed only .seal + .meta), and the
		// nonce dedup inside DirectRelayIngest::store makes re-processing safe.
		$container = json_decode((string)file_get_contents($data_path), true);
		if (!is_array($container) || empty($container['recipient'])) {
			error_log('RelaySpoolConsumer: malformed .direct container ' . $spool_id . ' — dropping');
			return 'unroutable';
		}

		require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectRelayIngest.php'));
		return DirectRelayIngest::store($container);
	}

	private function ingestOne(string $seal_path, string $meta_path, string $spool_id): string {
		// Idempotency FIRST, before the sidecar is required: if this spool id is
		// already stored, the prior pull just failed to ack — nothing to do but ack
		// it now. Checking after the .meta gate left a stored entry whose sidecar a
		// half-completed ack had already deleted throwing "missing .meta" forever.
		if ($this->alreadyStored($spool_id)) {
			return 'dedup';
		}

		if (!is_file($meta_path)) {
			// A .seal with no committed .meta is a torn pair; skip without acking so
			// the next pull (after the relay finishes writing) sees the complete pair.
			throw new \RuntimeException('missing .meta for ' . $spool_id);
		}
		$meta = json_decode((string)file_get_contents($meta_path), true);
		if (!is_array($meta)) {
			throw new \RuntimeException('unparseable .meta for ' . $spool_id);
		}
		$sealed_raw = trim((string)file_get_contents($seal_path));
		if ($sealed_raw === '') {
			throw new \RuntimeException('empty .seal for ' . $spool_id);
		}

		// The raw recipient keeps its local-part case — SRS bounce addresses encode
		// a case-sensitive hash (the pipe uses flags=DRh so the sealer never folds
		// it). Lowercase only for domain/alias lookups, which are case-insensitive.
		$recipient_raw = trim((string)($meta['recipient'] ?? ''));
		$recipient = strtolower($recipient_raw);
		if ($recipient === '' || strpos($recipient, '@') === false) {
			// Genuinely undeliverable — no recovery is possible, so ack-drop, but
			// never silently (specs/mailbox_data_loss_fixes.md, Fix 6).
			error_log('RelaySpoolConsumer: UNROUTABLE blob ' . $spool_id
				. ' — empty or malformed recipient (' . var_export($recipient_raw, true) . '); dropping.');
			return 'unroutable';
		}

		$key_kind = (string)($meta['key_kind'] ?? 'transport');

		// SRS bounce: a delivery-failure notice returning to a forwarded message's
		// SRS-rewritten sender. These are always transport-sealed. Decode and deliver
		// the NDR via the same handler colocated ingest uses — never store it as a
		// normal message (specs/mailbox_relay_fix_pack.md § Fix 6).
		if ($key_kind !== 'user' && SRSRewriter::isSRSAddress($recipient_raw)) {
			$raw = (new SealedBox())->openDek($sealed_raw, $this->transportSecret());
			$parsed = $this->router->parseEmail($raw);
			$handled = $this->router->handleSrsBounceIfApplicable($parsed, $raw, $recipient_raw);
			if ($handled === null) {
				// SRS is disabled, so nothing can decode this — an in-flight bounce
				// from before the setting flip (the map no longer accepts new ones).
				// The discard must never be silent.
				error_log('RelaySpoolConsumer: discarding SRS bounce ' . $spool_id
					. ' for ' . $recipient_raw . ' — mailbox_srs_enabled is off');
			}
			return 'bounce';
		}

		list($local, $domain_name) = explode('@', $recipient, 2);

		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->get('ied_is_enabled')) {
			// The domain was removed/disabled (temporarily or accidentally) since
			// the relay sealed this blob. HOLD it — don't ack — so re-enabling the
			// domain lets a later pull store the still-sealed mail. Age it out only
			// past the grace window (Fix 6).
			return $this->holdOrAgeOut($meta, $spool_id,
				"domain '" . $domain_name . "' is missing or disabled");
		}
		$alias = $this->router->lookupAlias($local, $domain);

		if ($key_kind === 'user') {
			$owner_id = ($alias !== null) ? InboundEmailMessage::singleOwnerUserId(intval($alias->key)) : null;
			if ($owner_id === null) {
				// The alias's grants changed (or it was deleted) between seal and
				// pull. The blob is still sealed to exactly one vault key — the
				// sealer recorded it in .meta — so resolve the owner from that key;
				// an ownerless pending row could never be drained.
				$owner_id = $this->ownerByPublicKey((string)($meta['public_key'] ?? ''));
			}
			if ($owner_id === null) {
				// No single owner AND no vault matches the seal key: an ownerless
				// pending row would be durable but permanently INVISIBLE (deferred
				// ingest selects by a specific owner), and since the blob is sealed
				// to one vault's key, assigning a fallback owner could not decrypt
				// it anyway. HOLD instead (Fix 7): a restored grant or re-enrolled
				// vault lets a later pull resolve the owner and store it correctly;
				// otherwise it ages out loudly rather than sitting invisibly stuck.
				return $this->holdOrAgeOut($meta, $spool_id,
					'Fortress blob for ' . $recipient . ' has no resolvable owner (no single grantee and no vault matches the seal key)');
			}
			$result = $this->router->storeRelayPending($meta, $sealed_raw, $domain, $alias,
				intval($owner_id), $this->relayAuthservId());
			return $result['dedup'] ? 'dedup' : 'pending';
		}

		// Transport: open now with the ambient secret and run the store ingest.
		$raw = (new SealedBox())->openDek($sealed_raw, $this->transportSecret());
		$parsed = $this->router->parseEmail($raw);

		// Deliverability report? (specs/deliverability_report_ingest.md) The
		// relay pull path reaches storeMessage without passing processEmail,
		// so the detector runs here — the same plaintext moment. A recognised
		// report is filed, never stored as mail; ack the spool entry.
		if (DeliverabilityReportIngest::intercept($this->router, $raw, $parsed, $domain, $recipient) !== null) {
			return 'stored';
		}

		$auth = $this->router->authFromRelayMeta($meta, $this->relayAuthservId());
		try {
			$result = $this->router->storeMessage($raw, $parsed, $alias, $domain, $recipient, $auth);
		} catch (MailboxSealTargetMissing $e) {
			// A protected mailbox with nobody to seal to. Storing it in plaintext
			// would defeat the level silently, so hold the blob on the relay — it
			// is still sealed there — and let a later pull store it once the
			// mailbox has one member with a vault. NO age-out on this hold: the
			// relay told the sender 250, so dropping the blob later would be
			// silent loss of accepted mail. Declining means "try again later"
			// for as long as it takes (specs/mailbox_connect_flow.md § E); the
			// Setup tab's sealing row is what keeps the wait short, and holds are
			// logged in aggregate by the caller, never per pass.
			return 'hold';
		}
		if (!$result['dedup'] && isset($result['message']) && $result['message'] !== null) {
			// Stamp the spool id so a re-pull dedups on it directly. TARGETED UPDATE
			// — storeMessage seals the content columns behind the model's back, so a
			// full save() here would blank them (specs/mailbox_relay_fix_pack.md § Fix 1).
			InboundEmailMessage::updateColumns(
				intval($result['message']->key),
				array('iem_relay_spool_id' => substr($spool_id, 0, 255))
			);
			return 'stored';
		}
		return $result['dedup'] ? 'dedup' : 'stored';
	}

	private function transportSecret(): string {
		if ($this->transport_secret === null) {
			$this->transport_secret = $this->relay->transportSecretKey();
		}
		return $this->transport_secret;
	}

	/**
	 * The authserv-id whose Authentication-Results stamps we trust on a pulled
	 * message: the relay's own mail hostname (MailboxRelay::authservId). The
	 * relay's milters did the verification and stamp under this name, and its
	 * opendkim strips sender-supplied lines carrying it (provision_relay.sh
	 * RemoveARFrom), so it is the one name on the message a sender cannot have
	 * written.
	 *
	 * Empty when the relay row records no hostname at all, which lets the router
	 * fall back to this deployment's own mail hostname — right for a colocated
	 * relay, where they are the same host.
	 */
	private function relayAuthservId(): string {
		return $this->relay->authservId();
	}

	/**
	 * The user whose vault public key the blob was sealed to (from .meta), or
	 * null. Fallback owner resolution for Fortress blobs whose alias grants
	 * changed between seal and pull.
	 */
	private function ownerByPublicKey(string $public_key): ?int {
		if ($public_key === '') {
			return null;
		}
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		$multi = new MultiUserEncryptionVault(array(
			'public_key' => $public_key,
			'scope'      => UserEncryptionVault::SCOPE_USER,
		));
		$multi->load();
		if ($multi->count() > 0) {
			return intval($multi->get(0)->get('uev_usr_user_id'));
		}
		return null;
	}

	/**
	 * Decide the outcome for recoverable-but-not-yet-storable mail (Fixes 6/7):
	 * HOLD it on the relay while there's a realistic chance of recovery (the
	 * domain is re-enabled, or the owner's grant/vault is restored), and age it
	 * out only once it is older than the grace window — so a permanently
	 * disabled domain or a deleted vault can't accumulate blobs forever.
	 *
	 * Age is measured from the .meta received_utc (RFC3339, stamped by the
	 * sealer and immutable), so the decision is stable across pulls. A missing
	 * or unparseable timestamp is treated as "keep holding" (never age out on
	 * doubt). 'hold' is logged only in aggregate by the caller, never per pass.
	 */
	private function holdOrAgeOut(array $meta, string $spool_id, string $reason): string {
		$grace_days = intval(Globalvars::get_instance()->get_setting('mailbox_relay_orphan_grace_days'));
		if ($grace_days <= 0) {
			$grace_days = 30;
		}
		$received = trim((string)($meta['received_utc'] ?? ''));
		$received_ts = ($received !== '') ? strtotime($received) : false;
		if ($received_ts !== false && $received_ts < (time() - $grace_days * 86400)) {
			error_log('RelaySpoolConsumer: AGED-OUT blob ' . $spool_id . ' (' . $reason
				. ') — held past the ' . $grace_days . '-day grace window (received ' . $received
				. '); dropping.');
			return 'aged_out';
		}
		return 'hold';
	}

	/** True if a message row already carries this spool id (durable). */
	private function alreadyStored(string $spool_id): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare("SELECT 1 FROM iem_inbound_email_messages WHERE iem_relay_spool_id = ? LIMIT 1");
		$stmt->execute(array($spool_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Delete the durably-stored entries on the relay — the ack. Batched into one
	 * ssh round trip via the tenant shell's joinery-ack verb (ids only; the
	 * shell resolves them inside this tenant's spool and rejects anything with a
	 * path separator). Returns the count acked.
	 */
	private function ack(string $spool_path, array $spool_ids): int {
		if (empty($spool_ids)) {
			return 0;
		}
		$ids = array();
		foreach ($spool_ids as $id) {
			// spool ids are our own <unixnano>-<hex>; keep only safe chars defensively.
			$safe = preg_replace('/[^A-Za-z0-9._-]/', '', $id);
			if ($safe !== '') { $ids[] = $safe; }
		}
		if (empty($ids)) {
			return 0;
		}
		$remote_cmd = 'joinery-ack ' . implode(' ', $ids);
		list($code, $out) = RelaySsh::run(RelaySsh::sshCommand($this->relay, $remote_cmd));
		if ($code !== 0) {
			error_log('RelaySpoolConsumer: ack failed: ' . $out);
			return 0;
		}
		return count($ids);
	}

	private function stageDir(): ?string {
		$dir = sys_get_temp_dir() . '/joinery-relay-pull-' . bin2hex(random_bytes(6));
		return @mkdir($dir, 0700, true) ? $dir : null;
	}

	private function cleanup(string $dir): void {
		foreach (glob($dir . '/*') ?: array() as $f) {
			@unlink($f);
		}
		@rmdir($dir);
	}
}
