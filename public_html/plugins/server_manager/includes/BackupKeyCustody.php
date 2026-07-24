<?php
/**
 * BackupKeyCustody — mints, escrows, and transports node backup keys.
 *
 * The custody model is sealed-box escrow: the control plane seals a node's
 * backup key to the recovery PUBLIC key (server_manager_escrow_public_key) and
 * stores the sealed blob in bke_backup_key_escrow, but can never open it — only
 * the offline private key can (escrow_keypair.php). Encrypted backups refuse to
 * run unless a public key is configured, so a key is never minted that only the
 * node knows.
 *
 * Key material NEVER touches a ManagementJob: job cmd/output rows are persisted
 * forever, so the key is read and written over a direct in-process SSH channel
 * (the same transport node_exec.php uses), and only ever crosses the wire on
 * stdin — never in a command string or in this process's argv.
 *
 * The escrow-before-push invariant: a generated key is sealed and its row saved
 * BEFORE it is written to the node. A key that exists on a node without an
 * escrow row is therefore impossible on this path.
 *
 * @version 1.3 - writeNodeKey is no-clobber (a racing mint fails loudly instead of overwriting
 *                the winner's key); sshExec drains stdout/stderr concurrently (select loop)
 * @version 1.2 - prove-possession: the recovery public key is honored only after the operator
 *                unseals a challenge with the offline key (a mistyped key otherwise "works"
 *                while every blob it seals is permanently unopenable); readNodeKey requires
 *                positive evidence of key absence (docker/exec failures no longer read as
 *                "no key" and mint orphans); escrow source recorded truthfully
 * @version 1.1 - sshExec fails loud on SSH transport failure (exit 255) with a key-access hint, instead of readNodeKey silently minting a second key
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_key_escrow_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

class BackupKeyCustodyException extends Exception {}

class BackupKeyCustody {

	/** Remote path of the node backup key, relative to the SSH/container user's home. */
	const REMOTE_KEY_PATH = '$HOME/.joinery_backup_key';

	/** stg_settings name holding the sha256 of the PROVEN recovery public key. */
	const PROOF_SETTING = 'server_manager_escrow_public_key_proven_fpr';

	/**
	 * The configured recovery public key (raw binary), or throw. Callers that
	 * mint or escrow keys must fail loudly when escrow is not configured rather
	 * than silently falling back to node-only custody.
	 *
	 * The key is only honored after prove-possession: sealed-box output is
	 * unverifiable without the private key, so a mistyped public key would
	 * "work" — every escrow row saves and reports recoverable — while every
	 * blob is permanently unopenable, discovered only at disaster time. The
	 * operator must unseal a challenge once (Backup Targets page) before any
	 * key is sealed to this value.
	 */
	public static function escrow_public_key() {
		$raw = self::parse_public_key();
		if (self::read_proof_setting() !== hash('sha256', $raw)) {
			throw new BackupKeyCustodyException(
				'The recovery public key has not been verified. Complete the possession check on the '
				. 'Backup Targets page (unseal the challenge with escrow_keypair.php and paste the result) '
				. 'before backup keys can be escrowed to it.');
		}
		return $raw;
	}

	/** Parse the configured public key without the possession check, or throw. */
	public static function parse_public_key() {
		$settings = Globalvars::get_instance();
		$b64 = trim((string)$settings->get_setting('server_manager_escrow_public_key'));
		if ($b64 === '') {
			throw new BackupKeyCustodyException(
				'Backup-key escrow is not configured (server_manager_escrow_public_key is empty). '
				. 'Encrypted backups refuse to run until a recovery public key is set.');
		}
		$raw = base64_decode($b64, true);
		if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new BackupKeyCustodyException('server_manager_escrow_public_key is not a valid base64 box public key.');
		}
		return $raw;
	}

	/** True when a public key is set but not yet proven (the verify card should show). */
	public static function needs_possession_proof() {
		try {
			$raw = self::parse_public_key();
		} catch (BackupKeyCustodyException $e) {
			return false; // nothing configured (or unparseable) — nothing to prove yet
		}
		return self::read_proof_setting() !== hash('sha256', $raw);
	}

	/**
	 * The exact string the operator must recover from the challenge blob. Bound
	 * to the public key's fingerprint, so a proof recorded for one key can never
	 * satisfy a different one.
	 */
	public static function expected_proof_string() {
		return 'sm-escrow-proof:' . hash('sha256', self::parse_public_key());
	}

	/**
	 * A fresh challenge: the expected proof string sealed to the configured
	 * public key. Only the holder of the matching private key can open it.
	 * (Sealed boxes are randomized, so the blob differs per call — the content
	 * is what matters.)
	 */
	public static function possession_challenge() {
		return base64_encode(sodium_crypto_box_seal(self::expected_proof_string(), self::parse_public_key()));
	}

	/**
	 * Record proof of possession: the pasted value must be the unsealed
	 * challenge content. On match, the public key's fingerprint is persisted
	 * and escrow_public_key() starts honoring the key. Throws on mismatch.
	 */
	public static function record_possession_proof($pasted) {
		$expected = self::expected_proof_string();
		if (!is_string($pasted) || !hash_equals($expected, trim($pasted))) {
			throw new BackupKeyCustodyException(
				'That is not the unsealed challenge for the configured public key. Unseal the challenge '
				. 'shown on this page with escrow_keypair.php and your offline private key, and paste the '
				. 'exact output.');
		}
		self::write_proof_setting(hash('sha256', self::parse_public_key()));
	}

	/** Direct stg_settings read — this internal marker is not a declared plugin setting. */
	private static function read_proof_setting() {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute([self::PROOF_SETTING]);
			$v = $q->fetchColumn();
			return ($v === false) ? '' : (string)$v;
		} catch (\Throwable $e) {
			return '';
		}
	}

	private static function write_proof_setting($value) {
		$db = DbConnector::get_instance()->get_db_link();
		$up = $db->prepare(
			"INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
			 VALUES (?, ?, 1, NOW(), NOW(), 'server_manager')
			 ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()");
		$up->execute([self::PROOF_SETTING, $value]);
	}

	public static function is_escrow_configured() {
		try { self::escrow_public_key(); return true; }
		catch (BackupKeyCustodyException $e) { return false; }
	}

	/** Base64 sealed-box of a key string, sealed to the recovery public key. */
	public static function seal($key) {
		$pub = self::escrow_public_key();
		return base64_encode(sodium_crypto_box_seal($key, $pub));
	}

	/** sha256 hex fingerprint of the raw key string (matches sha256sum on the node). */
	public static function fingerprint($key) {
		return hash('sha256', $key);
	}

	/**
	 * Ensure the node has a backup key AND it is escrowed. Returns the key.
	 *
	 *  - node already has a key, already escrowed  -> no-op
	 *  - node already has a key, NOT escrowed       -> escrow it (source=migrated),
	 *      never regenerate (that would orphan archives under the old key)
	 *  - node has no key                            -> generate, seal, SAVE ROW,
	 *      then push to the node (escrow-before-push)
	 */
	public static function ensureNodeKey($node) {
		self::escrow_public_key(); // fail fast if escrow is unconfigured

		$existing = self::readNodeKey($node);
		if ($existing !== null && $existing !== '') {
			$fpr = self::fingerprint($existing);
			if (self::escrow_row_exists($node->key, $fpr)) {
				return $existing; // already escrowed
			}
			self::save_escrow_row($node->key, $existing, 'migrated');
			return $existing;
		}

		// No key on the node: mint one, escrow it FIRST, then push.
		$key = base64_encode(random_bytes(32));
		self::save_escrow_row($node->key, $key, 'generated');
		self::writeNodeKey($node, $key);
		return $key;
	}

	/**
	 * Escrow a node's EXISTING key (the "Escrow existing key" / "Escrow all"
	 * admin action). Reads the key over the direct channel, seals, and appends a
	 * migrated row. No-op (returns the current row) if already escrowed. Returns
	 * the BackupKeyEscrow row, or null if the node has no key yet.
	 */
	public static function escrowExistingKey($node) {
		self::escrow_public_key();
		$key = self::readNodeKey($node);
		if ($key === null || $key === '') {
			return null;
		}
		$fpr = self::fingerprint($key);
		$existing = new MultiBackupKeyEscrow(['node_id' => $node->key, 'fingerprint' => $fpr, 'kind' => 'backup']);
		$existing->load();
		if (count($existing) > 0) {
			return $existing->get(0);
		}
		return self::save_escrow_row($node->key, $key, 'migrated');
	}

	/**
	 * Escrow the platform agent signing secret key (bke_kind='agent_signing',
	 * no node). Idempotent by fingerprint. Closes the parked agent-signing-key
	 * backup to-do: the same recovery keypair that protects backup keys now also
	 * lets the operator recover the signing key if the control plane is lost.
	 * Returns the row, or null if escrow is not configured.
	 */
	public static function escrowAgentSigningKey($secret_b64, $source = 'migrated') {
		if (!self::is_escrow_configured()) {
			return null;
		}
		$fpr = self::fingerprint($secret_b64);
		$existing = new MultiBackupKeyEscrow(['fingerprint' => $fpr, 'kind' => 'agent_signing']);
		$existing->load();
		if (count($existing) > 0) {
			return $existing->get(0);
		}
		return self::save_escrow_row(null, $secret_b64, $source, 'agent_signing');
	}

	/**
	 * True when the agent signing key file exists but has no agent_signing
	 * escrow row — the fleet trust root would be lost with the control plane.
	 * Only meaningful when escrow is configured; the dashboard surfaces it.
	 */
	public static function agent_signing_key_unescrowed() {
		if (!self::is_escrow_configured()) {
			return false;
		}
		$secret_path = PathHelper::getSiteRoot() . '/config/agent_signing_key';
		$raw = @file_get_contents($secret_path);
		if ($raw === false || trim($raw) === '') {
			return false; // no key minted yet — nothing to escrow
		}
		$rows = new MultiBackupKeyEscrow(['fingerprint' => self::fingerprint(trim($raw)), 'kind' => 'agent_signing']);
		$rows->load();
		return count($rows) === 0;
	}

	/** True if a backup-kind escrow row already exists for this node + fingerprint. */
	private static function escrow_row_exists($node_id, $fingerprint) {
		$rows = new MultiBackupKeyEscrow(['node_id' => $node_id, 'fingerprint' => $fingerprint, 'kind' => 'backup']);
		$rows->load();
		return count($rows) > 0;
	}

	/** Seal + persist an escrow row, then replicate the blob offsite. Returns the row. */
	private static function save_escrow_row($node_id, $key, $source, $kind = 'backup') {
		$row = new BackupKeyEscrow(NULL);
		$row->set('bke_mgn_node_id', $kind === 'backup' ? $node_id : null);
		$row->set('bke_key_fingerprint', self::fingerprint($key));
		$row->set('bke_sealed_blob', self::seal($key));
		$row->set('bke_kind', $kind);
		$row->set('bke_source', $source);
		$row->save();

		// Best-effort offsite replication of the sealed blob so DR survives the
		// control plane being the casualty. A failure here never blocks escrow.
		try {
			if ($kind === 'backup') {
				$node = new ManagedNode(intval($node_id), true);
				self::replicateBlob($node, $row);
			}
		} catch (Throwable $e) {
			error_log('BackupKeyCustody: offsite blob replication failed: ' . $e->getMessage());
		}
		return $row;
	}

	/**
	 * Upload a sealed blob to each of the node's enabled cloud targets as
	 * escrow/{slug}/{fingerprint}.sealed. The blob is already unopenable without
	 * the private key, so storing it beside the archives is safe and means a
	 * recovery needs only the bucket + the password-manager key.
	 */
	public static function replicateBlob($node, $row) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php'));
		$target = JobCommandBuilder::get_target($node);
		if (!$target) return false;
		$creds  = $target->get_credentials();
		$bucket = $target->get('bkt_bucket');
		$slug   = $node->get('mgn_slug') ?: ('node' . $node->key);
		$key    = 'escrow/' . $slug . '/' . $row->get('bke_key_fingerprint') . '.sealed';

		$tmp = tempnam(sys_get_temp_dir(), 'bke_');
		file_put_contents($tmp, (string)$row->get('bke_sealed_blob'));
		try {
			$resp = S3Signer::put_file($creds, $bucket, '/' . $key, $tmp);
		} finally {
			@unlink($tmp);
		}
		return isset($resp['status']) && $resp['status'] === 200;
	}

	// --- Direct in-process SSH channel (no ManagementJob, no persisted key) ----

	/**
	 * Read the node's backup key, or null when the node POSITIVELY has none.
	 *
	 * "No key" requires evidence (the file demonstrably absent), not the absence
	 * of evidence: a stopped container, a failed docker exec, or an unreadable
	 * file all exit non-zero through ssh with a non-255 code, and reading any of
	 * those as "no key" would mint + escrow an orphan second key on every retry.
	 * Those cases throw instead.
	 */
	public static function readNodeKey($node) {
		$script = 'if [ ! -e ' . self::REMOTE_KEY_PATH . ' ]; then echo SM_NO_KEY; exit 0; fi; cat ' . self::REMOTE_KEY_PATH;
		$res = self::sshExec($node, $script, null);
		if ($res['exit'] !== 0) {
			throw new BackupKeyCustodyException(
				'Could not determine whether the node has a backup key (remote exit ' . $res['exit'] . '): '
				. (trim($res['stderr']) ?: 'no error output')
				. ' — refusing to treat this as "no key" (a stopped container or unreadable key file'
				. ' must never cause a replacement key to be minted).');
		}
		$out = trim($res['stdout']);
		return ($out === 'SM_NO_KEY' || $out === '') ? null : $out;
	}

	/**
	 * Write the node's backup key (mode 600). The key crosses only on stdin.
	 *
	 * No-clobber (`set -C`): this system NEVER legitimately overwrites a key
	 * that exists on a node — that would orphan every archive encrypted under
	 * it. The only write path is the "node has no key" mint, so if a key
	 * appeared meanwhile (two mints racing), the loser fails loudly here
	 * instead of silently replacing the winner's key; a retry reads the
	 * existing key and escrows it.
	 */
	public static function writeNodeKey($node, $key) {
		$script = 'umask 077 && set -C && cat > ' . self::REMOTE_KEY_PATH
			. ' && chmod 600 ' . self::REMOTE_KEY_PATH;
		$res = self::sshExec($node, $script, $key . "\n");
		if ($res['exit'] !== 0) {
			$err  = trim($res['stderr']);
			$hint = (stripos($err, 'exist') !== false)
				? ' A key already exists on the node and is never overwritten (that would orphan its archives) — retry to read and escrow the existing key.'
				: '';
			throw new BackupKeyCustodyException('Failed to write backup key to node: ' . ($err !== '' ? $err : 'exit ' . $res['exit']) . $hint);
		}
	}

	/**
	 * Run a shell script on the node over SSH (through docker exec when the node
	 * is containerised, mirroring node_exec.php). $stdin, when given, is piped to
	 * the remote command. Returns ['exit'=>int, 'stdout'=>string, 'stderr'=>string].
	 */
	private static function sshExec($node, $remote_script, $stdin = null) {
		$host     = $node->get('mgn_host');
		$ssh_user = $node->get('mgn_ssh_user') ?: 'root';
		$key_path = $node->get('mgn_ssh_key_path');
		$ssh_port = intval($node->get('mgn_ssh_port') ?: 22) ?: 22;
		$container = $node->get('mgn_container_name');

		if (empty($host) || empty($key_path)) {
			throw new BackupKeyCustodyException('Node has no host or SSH key configured.');
		}

		$ssh = JobCommandBuilder::ssh_prefix($host, $ssh_user, $key_path, $ssh_port);
		$dash_i = ($stdin !== null) ? '-i ' : '';
		$remote = $container
			? "docker exec {$dash_i}" . escapeshellarg($container) . ' bash -c ' . escapeshellarg($remote_script)
			: 'bash -c ' . escapeshellarg($remote_script);
		$full = $ssh . ' ' . escapeshellarg($remote);

		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($full, $descriptors, $pipes);
		if (!is_resource($proc)) {
			throw new BackupKeyCustodyException('Failed to open SSH channel to node.');
		}
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}
		fclose($pipes[0]);

		// Drain BOTH pipes as data arrives (select loop). Reading one stream to
		// EOF before touching the other deadlocks as soon as the remote writes
		// more than a pipe buffer (~64KB) to the un-drained stream: it blocks on
		// a full buffer, we block waiting for EOF on the other. Unreachable with
		// today's tiny outputs, but this helper is shaped for reuse.
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$stdout = '';
		$stderr = '';
		$open = [1 => $pipes[1], 2 => $pipes[2]];
		while ($open) {
			$read = array_values($open);
			$write = null;
			$except = null;
			if (@stream_select($read, $write, $except, 10) === false) {
				break;
			}
			foreach ($read as $stream) {
				$chunk = fread($stream, 65536);
				if ($chunk !== false && $chunk !== '') {
					if ($stream === $pipes[1]) { $stdout .= $chunk; } else { $stderr .= $chunk; }
				}
				if (feof($stream)) {
					unset($open[$stream === $pipes[1] ? 1 : 2]);
				}
			}
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($proc);

		// ssh exits 255 for a transport failure (cannot connect or authenticate),
		// which is categorically different from a remote command that ran and
		// exited non-zero (e.g. `cat` on an absent key file). Fail loud here: if we
		// returned this to readNodeKey it would read a transport failure as "the
		// node has no key" and silently mint + escrow a SECOND key (the orphan-row
		// trap). The common cause in practice is the web-server user not being able
		// to read the SSH key — in-process escrow runs as the web user.
		if ($exit === 255) {
			$err  = trim($stderr) ?: 'ssh exited 255 (could not connect or authenticate)';
			$hint = '';
			if (stripos($err, 'not accessible') !== false || stripos($err, 'Identity file') !== false) {
				$hint = ' — the SSH key at ' . $key_path . ' is not readable by the user running this'
				      . ' PHP process. In-process key escrow runs as the web-server user, so that user'
				      . ' needs read access to the node SSH key (or run the escrow from a CLI/cron context'
				      . ' that owns the key).';
			}
			throw new BackupKeyCustodyException('SSH to node failed: ' . $err . $hint);
		}

		return ['exit' => $exit, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
	}
}
