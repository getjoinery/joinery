<?php
/**
 * VaultHostNotice - the admin-header notice on a site whose host lets an open
 * vault window's key reach the disk (VaultHostHealth: a core dump, swap in the
 * clear, an exception trace, APCu or the RAM store on a file).
 *
 * Shown to the superadmin, who can act on it, with each check's own words.
 * Information, never a gate: windows still open. Silent while every check is
 * met or could not be read, on a site with no vault, and on a site somebody
 * else hosts (HostedPlanNotice), whose admins cannot change the server.
 *
 * @version 1.1 - silent on a hosted site and on a site with no vault (reviewer2 B15, B16)
 * @version 1.0 (specs/security_weak_spots_2026_11.md WP3)
 */
class VaultHostNotice {

	public static function render(): string {
		if (!Access::permits(10)) {
			return '';
		}
		// A site somebody else hosts: its admins cannot change the server, and
		// the operator sees the same finding on the management node's badge.
		if (class_exists('HostedPlanNotice') && HostedPlanNotice::applies()) {
			return '';
		}
		if (!VaultHostHealth::relevant()) {
			return '';   // no vault here, so no key to reach the disk
		}
		return self::forSummary(VaultHostHealth::summary());
	}

	/** The notice for one summary, '' when nothing is unmet. Public and pure so the wording can be tested. */
	public static function forSummary(array $summary): string {
		$unmet = $summary['unmet'] ?? array();
		if (!$unmet) {
			return '';
		}
		$items = '';
		foreach ($unmet as $u) {
			$items .= '<li><strong>' . htmlspecialchars((string)$u['label'], ENT_QUOTES, 'UTF-8') . ':</strong> '
				. htmlspecialchars((string)$u['reason'], ENT_QUOTES, 'UTF-8') . '</li>';
		}
		return self::css()
			. '<div class="jy-vault-host-notice" role="status"><strong>Vault keys could reach this server\'s disk.</strong> '
			. 'While a member has their vault open, its key is in memory, and these let memory be written out:'
			. '<ul>' . $items . '</ul></div>';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-vault-host-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;background:#fffbeb;color:#78350f;font-size:.95rem;overflow-wrap:anywhere}'
			. '.jy-vault-host-notice ul{margin:.5rem 0 0 1.25rem;padding:0}'
			. '</style>';
	}
}
?>
