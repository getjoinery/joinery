<?php
/**
 * DataRootStatus — what this site may know of its server's data root
 * (specs/hosted_data_promise_disk_encryption.md WP4): whether it is encrypted,
 * what opened it at the last boot, and whose recovery key its break-glass copy
 * is sealed to.
 *
 * Read from /run/joinery/data-root.status, which the host's data root tick
 * (joinery_data_root.sh, as root) writes each minute for exactly this: the
 * files it comes from are root's. A site in a container sees no such file, and
 * says nothing; its host's page says it.
 *
 * ENCRYPTING FROM HERE. A server that is not hosted by an operator is
 * encrypted from its own Backups page, its break-glass copy sealed to this
 * site's own proven recovery key (D2). The page cannot do it: the web user
 * runs nothing as root. It asks, through RootRequest ('data_root_encrypt',
 * 'data_root_reseal'), and the root side checks everything again itself.
 *
 * @version 1.0
 */
class DataRootStatus {

	const STATUS_FILE = '/run/joinery/data-root.status';

	/** The verified backup an encryption needs first, at most this old. */
	const BACKUP_MAX_AGE_SECONDS = 86400;

	/**
	 * The status, or null where this site sees none (no data root, or a site
	 * in a container). Keys: backing, encrypted, and on an encrypted one open,
	 * opened_by, recipient_fingerprint, recipient_set_by, sidecar,
	 * sidecar_fingerprint, reencrypt, reencrypt_percent.
	 */
	public static function read(?string $path = null): ?array {
		$raw = @file_get_contents($path ?? self::STATUS_FILE);
		if ($raw === false) {
			return null;
		}
		$out = array();
		foreach (preg_split('/\R/', $raw) as $line) {
			if ($line === '' || $line[0] === '#' || ($eq = strpos($line, '=')) === false) {
				continue;
			}
			$out[substr($line, 0, $eq)] = substr($line, $eq + 1);
		}
		return isset($out['backing']) ? $out : null;
	}

	public static function encrypted(?array $st): bool {
		return is_array($st) && ($st['encrypted'] ?? '') === '1';
	}

	/**
	 * Whose key the break-glass copy is sealed to: 'this_site' (this site's
	 * own recovery key), 'operator' (the operator's, which its management node
	 * set), 'other' (a key this site has retired, or the one given at
	 * install), or '' when it is not encrypted.
	 */
	public static function sealed_to(?array $st, string $site_fpr): string {
		if (!self::encrypted($st)) {
			return '';
		}
		$fpr = (string)($st['recipient_fingerprint'] ?? '');
		if ($site_fpr !== '' && hash_equals($site_fpr, $fpr)) {
			return 'this_site';
		}
		return ($st['recipient_set_by'] ?? '') === 'operator' ? 'operator' : 'other';
	}

	/**
	 * What the site's owner should know, worst first: a list of
	 * ['level' => 'danger'|'warning'|'info', 'text' => ..., 'reseal' => bool].
	 * 'reseal' offers sealing the copy to this site's own key.
	 */
	public static function advice(?array $st, string $site_fpr, bool $site_key_ready): array {
		if (!self::encrypted($st)) {
			return array();
		}
		$out = array();
		$fpr = (string)($st['recipient_fingerprint'] ?? '');
		if ($fpr === '') {
			$out[] = array('level' => 'danger', 'reseal' => $site_key_ready,
				'text' => 'This server\'s encrypted data root names no recovery key, so it has no break-glass copy: if its other keys are lost, nothing opens it.');
		} elseif (($st['sidecar'] ?? '') !== '1') {
			$out[] = array('level' => 'warning', 'reseal' => false,
				'text' => 'This server\'s data root has no break-glass copy right now. The server makes it again from the open volume within a minute.');
		} elseif (($st['sidecar_fingerprint'] ?? '') !== $fpr) {
			$out[] = array('level' => 'info', 'reseal' => false,
				'text' => 'The break-glass copy is being sealed to the recovery key ' . substr($fpr, 0, 16) . '…; this takes up to a minute.');
		}
		if ($fpr !== '' && self::sealed_to($st, $site_fpr) === 'other') {
			$out[] = array('level' => 'warning', 'reseal' => $site_key_ready,
				'text' => 'The break-glass copy is sealed to the recovery key ' . substr($fpr, 0, 16) . '…, which is not this site\'s'
					. ($site_fpr !== '' ? ' (' . substr($site_fpr, 0, 16) . '…)' : '')
					. ': one it has retired, or the one given when the server was installed. Whoever holds that key can open this server\'s data.');
		}
		return $out;
	}

	/** What opened it at the last boot, in words, or ''. */
	public static function opened_by(?array $st): string {
		return self::encrypted($st) ? trim((string)($st['opened_by'] ?? '')) : '';
	}

	/**
	 * Whether this site may encrypt its own data root, and what is missing:
	 * ['offered' => bool, 'ready' => bool, 'reasons' => [...]]. Offered only
	 * where data_root_encryption_offered is on and this site sees a data root
	 * that is not encrypted.
	 */
	public static function encrypt_offer(?array $st): array {
		$offered = is_array($st) && !self::encrypted($st)
			&& (string)Globalvars::get_instance()->get_setting('data_root_encryption_offered') === '1';
		$reasons = array();
		if ($offered) {
			if (!BackupRecoveryKey::is_ready()) {
				$reasons[] = 'Set up this site\'s recovery key and prove you hold it first: the break-glass copy is sealed to it.';
			}
			if (!self::verified_backup_recent()) {
				$reasons[] = 'Verify a backup first: one verified within the last day.';
			}
		}
		return array('offered' => $offered, 'ready' => $offered && !$reasons, 'reasons' => $reasons);
	}

	/** A backup of this site's own verified as passing within the last day. */
	public static function verified_backup_recent(): bool {
		$row = BackupVerifyLauncher::last_verified();
		if (!$row || (string)$row->get('bkh_verify_outcome') !== 'pass') {
			return false;
		}
		$at = strtotime((string)$row->get('bkh_verify_time') . ' UTC');
		return $at !== false && (time() - $at) < self::BACKUP_MAX_AGE_SECONDS;
	}
}
