<?php
/**
 * UnsealDaemonNotice - the admin-header notice on a node whose unseal daemon
 * is not doing its job (specs/unseal_daemon.md § When the daemon is missing
 * or down, § The sentinel's hold).
 *
 * The finding is VaultHealth's unseal_daemon row (missing, not answering, on
 * the previous build, held, or unlocked memory), shown to the superadmin who
 * can act on it, with the row's own words. Information, never a gate: the
 * site works without the daemon. Silent while the row is verified.
 *
 * @version 1.0
 */
class UnsealDaemonNotice {

	public static function render(): string {
		if (!Access::permits(10)) {
			return '';
		}
		return self::forRow(VaultHealth::checkUnsealDaemon(UnsealDaemon::facts(true, 0.3)));
	}

	/** The notice for one health row, '' when it is verified. Public and pure so the wording can be tested. */
	public static function forRow(array $row): string {
		if (($row['state'] ?? '') === 'verified') {
			return '';
		}
		return self::css()
			. '<div class="jy-unseal-notice" role="status"><strong>Vault keys on this node:</strong> '
			. htmlspecialchars((string)($row['reason'] ?? ''), ENT_QUOTES, 'UTF-8')
			. '</div>';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-unseal-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;background:#fffbeb;color:#78350f;font-size:.95rem;overflow-wrap:anywhere}'
			. '</style>';
	}
}
?>
