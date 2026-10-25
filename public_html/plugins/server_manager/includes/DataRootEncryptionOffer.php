<?php
/**
 * DataRootEncryptionOffer — whether this management node offers to encrypt a
 * node's data root where it stands, what must be true first, and the jobs that
 * do it (specs/hosted_data_promise_disk_encryption.md D5, D1a, D-O).
 *
 * OFFERED only where the core setting data_root_encryption_offered is on (D-O,
 * "How it is built"): off, which is the default and production until launch,
 * the node page shows no Encrypt action and nothing here queues anything.
 *
 * WHAT MUST BE TRUE (D5 step 1), each shown on the node page:
 *   - its machine is in one of our accounts (CloudAccounts::is_ours): its
 *     break-glass copy is sealed to our key, and one in its owner's account
 *     is sealed to its owner's, from its own Backups page (D2);
 *   - the host has a data root, and it is not encrypted;
 *   - its agent has the words (data_root_recipient, data_root_encrypt);
 *   - this management node's own recovery key is proven: a hosted machine's
 *     break-glass copy is sealed to it (D2);
 *   - a verified backup from here, newer than a day;
 *   - one layer (D1a): where the provider's disk encryption is on, or not
 *     known, the encryption runs only with "keep the provider's layer as well",
 *     and only on a management node, which never copies itself
 *     (specs/site_copy_final_test.md E2): any other machine is copied onto a
 *     new server born with one layer instead.
 * The 32 MiB past the end of the backing the script checks for itself.
 *
 * @version 1.4 - provider_layer_fix(): what to do while the provider's layer is on, one answer for the refusal,
 *                the checklist and the node page; a machine in its owner's account is pointed at a copy into
 *                that account, after its recovery key is proven where it is not, never at its own Backups
 *                page as well (B114)
 * @version 1.3 - "keep the provider's layer as well" only on a management node; anywhere else the refusal says to copy
 *                the site onto a new server born with one layer (specs/site_copy_final_test.md E2)
 * @version 1.2 - provider_layer(): a data root on a storage volume is under the volume's encryption, which the
 *                provider is asked (B28), not the server's disk's
 * @version 1.1 - what it says names no spec ids, and the provider-layer refusal reads "on for this machine" (B26)
 * @version 1.0
 */

class DataRootEncryptionOffer {

	const BACKUP_MAX_AGE_SECONDS = 86400;

	public static function offered(): bool {
		return (string)Globalvars::get_instance()->get_setting('data_root_encryption_offered') === '1';
	}

	/** The node's host report section, or null. */
	private static function report(ManagedNode $node, string $key) {
		$hr = json_decode((string)$node->get('mgn_last_host_report'), true);
		return is_array($hr) ? ($hr[$key] ?? null) : null;
	}

	/** What provider_layer() has read this request, by node: the volume case asks the provider. */
	private static $layer_seen = array();

	/**
	 * The provider's own encryption under this node's data root: 'enabled',
	 * 'disabled' or 'unknown'. The server's disk's, recorded on its
	 * provision; but a data root on a storage volume (its host report names
	 * the device) sits under the volume's, which the provider is asked.
	 */
	public static function provider_layer(ManagedNode $node): string {
		$key = (int)$node->key;
		if (!isset(self::$layer_seen[$key])) {
			self::$layer_seen[$key] = self::read_provider_layer($node);
		}
		return self::$layer_seen[$key];
	}

	/** Forget what provider_layer() has read: a layer was recorded since. */
	public static function forget_layers(): void {
		self::$layer_seen = array();
	}

	private static function read_provider_layer(ManagedNode $node): string {
		$provision = IpSwapMove::provision_of($node);
		if (!$provision) {
			return 'unknown';
		}
		$pool = self::report($node, 'disk_pool');
		$device = is_array($pool) ? (string)($pool['device'] ?? '') : '';
		if ($device !== '') {
			$volume = self::volume_layer($provision, $device);
			if ($volume !== '') {
				return $volume;
			}
		}
		$v = (string)$provision->get('cvp_provider_disk_encryption');
		return in_array($v, array('enabled', 'disabled'), true) ? $v : 'unknown';
	}

	/** The volume's layer, '' where the device is a disk of the server itself, 'unknown' where it cannot be read. */
	private static function volume_layer(CustomerCloudProvision $provision, string $device): string {
		$oca_id = (int)$provision->get('cvp_oca_operator_cloud_account_id');
		$account = $oca_id ? new OperatorCloudAccount($oca_id, TRUE) : null;
		if (!$account || !$account->key || !$account->usable()) {
			return 'unknown';
		}
		try {
			$driver = $account->driver();
			if (!($driver instanceof CloudVolumeFacts)) {
				return 'unknown';
			}
			return $driver->volumeEncryption((string)$provision->get('cvp_instance_id'), $device);
		} catch (Exception $e) {
			return 'unknown';
		}
	}

	/**
	 * Each precondition: ['label' => ..., 'ok' => bool, 'note' => ...]. The
	 * provider layer is never a blocker here; it decides whether the
	 * "keep the provider's layer as well" tick is required.
	 */
	public static function preconditions(ManagedNode $node): array {
		$out = array();
		$pool = self::report($node, 'disk_pool');
		$enc = self::report($node, 'data_root_encryption');
		$ours = DataRootEscrowSync::sealed_to_operator($node);
		$out[] = array('label' => 'In one of our accounts', 'ok' => $ours,
			'note' => $ours ? 'its break-glass copy is sealed to this management node\'s recovery key'
				: (self::provider_layer($node) === 'enabled' ? 'it is in its owner\'s account, where its provider already encrypts its disk'
					: 'it is in its owner\'s account: it is encrypted from its own Backups page, sealed to its owner\'s recovery key'));
		$out[] = array('label' => 'A data root, not encrypted',
			'ok' => is_array($pool) && $enc === 'none',
			'note' => is_array($pool) ? ($enc === 'none' ? '' : 'it is encrypted already, or its host report does not say') : 'this host has no data root (move its data there first)');
		$words = JobCommandBuilder::has_primitive($node, 'data_root_encrypt') && JobCommandBuilder::has_primitive($node, 'data_root_recipient');
		$out[] = array('label' => 'Its agent can do it', 'ok' => $words,
			'note' => $words ? '' : AgentVocabulary::needs_newer_agent_text($node, array('data_root_encrypt', 'data_root_recipient')));
		$key_ready = BackupRecoveryKey::is_ready();
		$out[] = array('label' => 'This management node\'s recovery key is proven', 'ok' => $key_ready,
			'note' => $key_ready ? 'its break-glass copy is sealed to it' : 'set it up and prove it on this management node\'s Backups page');
		$verdict = IncidentBackupVerdict::for_node($node);
		$last = $node->get('mgn_last_backup_time');
		$fresh = $last && (time() - strtotime($last . ' UTC')) < self::BACKUP_MAX_AGE_SECONDS;
		$backup_ok = is_array($verdict) && ($verdict['kind'] ?? '') === 'ok' && $fresh && (string)$node->get('mgn_last_backup_outcome') === 'success';
		$out[] = array('label' => 'A verified backup, newer than a day', 'ok' => $backup_ok,
			'note' => $backup_ok ? '' : 'run a backup from the Backups tab and let it verify first');
		return $out;
	}

	/**
	 * What to do while the provider's own layer is on (E2), one answer for the
	 * refusal, the checklist and the node page: copy the site onto a new
	 * server born with ours and the provider's off. In one of our accounts,
	 * sealed to this management node's key; in its owner's, sealed to the
	 * site's own proven key, which a copy needs first (no backup runs without
	 * it). COPY_TAB is how the page names the Copy tab (a link there).
	 */
	public static function provider_layer_fix(ManagedNode $node, string $copy_tab = 'its Copy tab'): string {
		if (DataRootEscrowSync::sealed_to_operator($node)) {
			return 'Copy the site onto a new server from ' . $copy_tab . ': it is born encrypted by us with the provider\'s layer off.';
		}
		if (trim((string)$node->get('mgn_backup_recovery_public_key')) !== '') {
			return 'Copy the site onto a new server in its owner\'s account from ' . $copy_tab . ': it is born encrypted by us, '
				. 'sealed to its owner\'s recovery key, with the provider\'s layer off.';
		}
		return 'Prove this site\'s recovery key on its own Backups page first (until then no backup of it runs, so no copy '
			. 'either); then copy the site onto a new server in its owner\'s account from ' . $copy_tab . ': it is born '
			. 'encrypted by us, sealed to that key, with the provider\'s layer off.';
	}

	/**
	 * May this machine keep the provider's layer under ours? Only a management
	 * node: it never copies itself onto a new server, so it is encrypted where
	 * it stands, with two layers until it is replaced.
	 */
	public static function may_keep_provider_layer(ManagedNode $node): bool {
		return $node->is_self() || $node->is_management_node();
	}

	public static function ready(ManagedNode $node): bool {
		foreach (self::preconditions($node) as $p) {
			if (!$p['ok']) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Queue the encryption: the recovery recipient first, then the start.
	 * Returns the encryption's job. Throws, naming every reason, when it may not.
	 */
	public static function queue(ManagedNode $node, bool $keep_provider_layer, ?int $user_id): ManagementJob {
		if (!self::offered()) {
			throw new Exception('Encrypting a data root is not offered on this management node (data_root_encryption_offered is off).');
		}
		$why = array();
		foreach (self::preconditions($node) as $p) {
			if (!$p['ok']) {
				$why[] = $p['label'] . ($p['note'] !== '' ? ': ' . $p['note'] : '');
			}
		}
		if (self::provider_layer($node) !== 'disabled' && (!$keep_provider_layer || !self::may_keep_provider_layer($node))) {
			$why[] = 'The provider\'s disk encryption ' . (self::provider_layer($node) === 'enabled' ? 'is on for' : 'is not known to be off for')
				. ' this machine: one layer only. ' . self::provider_layer_fix($node)
				. ($keep_provider_layer ? ' Keeping the provider\'s layer as well is only for a management'
				. ' node, which never copies itself.' : '');
		}
		if ($why) {
			throw new Exception('Not encrypted: ' . implode(' · ', $why));
		}
		$recipient = JobCommandBuilder::build_data_root_recipient($node, base64_encode(BackupRecoveryKey::public_key()));
		ManagementJob::createFromBuild($node->key, 'data_root_recipient', $recipient, null, $user_id);
		$built = JobCommandBuilder::build_data_root_encrypt($node, $keep_provider_layer);
		return ManagementJob::createFromBuild($node->key, 'data_root_encrypt', $built, null, $user_id);
	}
}
