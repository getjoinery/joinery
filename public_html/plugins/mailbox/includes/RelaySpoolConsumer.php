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
 * @version 1.1
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
		$spool_path = rtrim((string)$this->relay->get('mrl_spool_path') ?: '/var/spool/joinery-relay', '/');

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
				array('--exclude=tmp/', "--include=*.seal", "--include=*.meta", "--exclude=*")
			);
			list($code, $out) = RelaySsh::run($cmd);
			if ($code !== 0) {
				return array('status' => 'error', 'message' => 'rsync pull failed: ' . $out);
			}

			$seals = glob($stage . '/*.seal') ?: array();
			$stored = 0; $pending = 0; $errors = 0;
			$acked_ids = array();

			foreach ($seals as $seal_path) {
				if (($stored + $pending + $errors) >= $max) {
					break;
				}
				$spool_id = basename($seal_path, '.seal');
				$meta_path = $stage . '/' . $spool_id . '.meta';
				try {
					$outcome = $this->ingestOne($seal_path, $meta_path, $spool_id);
					if ($outcome === 'stored')  { $stored++; }
					if ($outcome === 'pending') { $pending++; }
					// Every non-throwing outcome (including dedup/orphan) is durable →
					// safe to ack.
					$acked_ids[] = $spool_id;
				} catch (\Throwable $e) {
					$errors++;
					error_log('RelaySpoolConsumer: failed on ' . $spool_id . ': ' . $e->getMessage());
					// Do NOT ack — leave it on the relay for the next pull.
				}
			}

			$acked = $this->ack($spool_path, $acked_ids);

			$this->relay->set('mrl_last_pull_time', gmdate('Y-m-d H:i:s'));
			$this->relay->save();

			$status = $errors > 0 && ($stored + $pending) === 0 ? 'error' : 'success';
			$msg = sprintf('pulled %d entr(y/ies): %d stored, %d pending-parse, %d acked, %d error(s)',
				count($seals), $stored, $pending, $acked, $errors);
			return array('status' => $status, 'message' => $msg,
				'stored' => $stored, 'pending' => $pending, 'acked' => $acked);
		} finally {
			$this->cleanup($stage);
		}
	}

	/**
	 * Store one spool entry. Returns 'stored' | 'pending' | 'dedup' | 'orphan'.
	 * Throws only on a real failure (so the caller leaves it un-acked to retry).
	 */
	private function ingestOne(string $seal_path, string $meta_path, string $spool_id): string {
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

		// Idempotency: if this spool id is already stored, the prior pull just failed
		// to ack — nothing to do but ack it now.
		if ($this->alreadyStored($spool_id)) {
			return 'dedup';
		}

		// The raw recipient keeps its local-part case — SRS bounce addresses encode
		// a case-sensitive hash (the pipe uses flags=DRh so the sealer never folds
		// it). Lowercase only for domain/alias lookups, which are case-insensitive.
		$recipient_raw = trim((string)($meta['recipient'] ?? ''));
		$recipient = strtolower($recipient_raw);
		if ($recipient === '' || strpos($recipient, '@') === false) {
			return 'orphan'; // nothing routable; ack to avoid a re-pull loop
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
			return 'orphan'; // domain removed/disabled since sealing; ack and drop
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
				error_log('RelaySpoolConsumer: storing OWNERLESS Fortress blob ' . $spool_id
					. ' for ' . $recipient . ' — no single owner and no vault matches the seal key;'
					. ' it cannot be parsed until an owner is assigned');
			}
			$result = $this->router->storeRelayPending($meta, $sealed_raw, $domain, $alias, intval($owner_id ?? 0));
			return $result['dedup'] ? 'dedup' : 'pending';
		}

		// Transport: open now with the ambient secret and run the store ingest.
		$raw = (new SealedBox())->openDek($sealed_raw, $this->transportSecret());
		$parsed = $this->router->parseEmail($raw);
		$auth = $this->router->authFromRelayMeta($meta);
		$result = $this->router->storeMessage($raw, $parsed, $alias, $domain, $recipient, $auth);
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

	/** True if a message row already carries this spool id (durable). */
	private function alreadyStored(string $spool_id): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare("SELECT 1 FROM iem_inbound_email_messages WHERE iem_relay_spool_id = ? LIMIT 1");
		$stmt->execute(array($spool_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Delete the durably-stored entries on the relay — the ack. Batched into one
	 * ssh round trip. Returns the count acked.
	 */
	private function ack(string $spool_path, array $spool_ids): int {
		if (empty($spool_ids)) {
			return 0;
		}
		$targets = array();
		foreach ($spool_ids as $id) {
			// spool ids are our own <unixnano>-<hex>; keep only safe chars defensively.
			$safe = preg_replace('/[^A-Za-z0-9._-]/', '', $id);
			if ($safe === '') { continue; }
			$targets[] = $spool_path . '/' . $safe . '.seal';
			$targets[] = $spool_path . '/' . $safe . '.meta';
		}
		if (empty($targets)) {
			return 0;
		}
		$remote_cmd = 'rm -f ' . implode(' ', array_map('escapeshellarg', $targets));
		list($code, $out) = RelaySsh::run(RelaySsh::sshCommand($this->relay, $remote_cmd));
		if ($code !== 0) {
			error_log('RelaySpoolConsumer: ack delete failed: ' . $out);
			return 0;
		}
		return count($spool_ids);
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
