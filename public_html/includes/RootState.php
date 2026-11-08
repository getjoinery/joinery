<?php
/**
 * RootState - where root keeps what it writes for this site, and the pool reads.
 *
 * The host converger carries out root requests, runs plugin installers and
 * records what it did. Everything it writes for a site lives in root's own
 * directory, /var/lib/joinery/sites/<site>: the request queue and its
 * outcomes, the transcripts, the plugin installer records and logs, the
 * converge stamp and last-run record, and the certificate summary. Never in
 * the site's cache/ or logs/: those are the web user's, and the owner of a
 * directory can rename any entry in it, so a root-owned folder there could be
 * moved aside and a link put under its name for root's next write to follow
 * (specs/shell_command_audit.md D1).
 *
 * The one place the web user writes is the queue itself, a root-owned
 * directory (770 root:www-data) that holds nothing of root's: a request's
 * outcomes live beside it, not in it, so there is no entry there for the web
 * user to rename aside. The rest is root's, 750 root:www-data, read through
 * the group.
 *
 * A machine without the directory (no converger has run as root yet, or a
 * scratch site in a test) uses the fixture layout under the site root, the
 * same one the converger uses when it is not root. The two sides agree by
 * construction: the converger makes the directory on its first root run and
 * carries over any request still waiting under cache/root_requests.
 *
 * @version 1.1 - the log hint names a site container's log, in its state volume
 * @version 1.0
 */
final class RootState {

	const BASE = '/var/lib/joinery/sites';

	/** Tests point this at a scratch directory; null means the rule above. */
	public static ?string $override = null;

	/**
	 * Root's directory for the site, or null when it does not exist and the
	 * fixture layout under the site root applies.
	 */
	public static function dir(?string $site_root = null): ?string {
		if (self::$override !== null) {
			return self::$override;
		}
		$site_root = rtrim($site_root ?? PathHelper::getSiteRoot(), '/');
		$name = basename($site_root);
		if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $name)) {
			return null;
		}
		$dir = self::BASE . '/' . $name;
		return is_dir($dir) && !is_link($dir) ? $dir : null;
	}

	/** The queue the web side writes a request into. */
	public static function queueDir(?string $site_root = null): string {
		$dir = self::dir($site_root);
		return $dir !== null ? $dir . '/root_requests' : self::siteRoot($site_root) . '/cache/root_requests';
	}

	/**
	 * Where a request goes once root has taken it: running/, done/ and failed/
	 * under here. Beside the queue in root's directory; inside the queue in the
	 * fixture layout.
	 */
	public static function outcomeDir(?string $site_root = null): string {
		$dir = self::dir($site_root);
		return $dir !== null ? $dir . '/root_request_outcomes' : self::siteRoot($site_root) . '/cache/root_requests';
	}

	/** Root's transcript of a request, one file per id. */
	public static function requestLogDir(?string $site_root = null): string {
		$dir = self::dir($site_root);
		return $dir !== null ? $dir . '/root_request_logs' : self::siteRoot($site_root) . '/logs/root_requests';
	}

	/** One record per plugin of what its host installer did last. */
	public static function pluginRecordDir(?string $site_root = null): string {
		$dir = self::dir($site_root);
		return $dir !== null ? $dir . '/plugin_installers' : self::siteRoot($site_root) . '/cache/plugin_installers';
	}

	/** The output of each plugin's last host installer run. */
	public static function pluginLogDir(?string $site_root = null): string {
		$dir = self::dir($site_root);
		return $dir !== null ? $dir . '/plugin_installer_logs' : self::siteRoot($site_root) . '/logs/plugin_installers';
	}

	/** "<unix seconds> <outcome>", written by the converger at the start and end of every run. */
	public static function convergerLastFile(?string $site_root = null): string {
		$dir = self::dir($site_root);
		return ($dir ?? self::siteRoot($site_root) . '/cache') . '/host_converger.last';
	}

	/** The converger's certificate summary. */
	public static function certificatesFile(?string $site_root = null): string {
		$dir = self::dir($site_root);
		return ($dir ?? self::siteRoot($site_root) . '/cache') . '/certificates.json';
	}

	/**
	 * Where the converger's own log is: root's file beside the machine log on a
	 * host, the journal on a host with a data root, the state volume in a site
	 * container. The admin notices name it.
	 */
	public static function convergerLogHint(?string $site_root = null): string {
		$name = basename(rtrim($site_root ?? PathHelper::getSiteRoot(), '/'));
		if (is_file('/.dockerenv') || is_file('/run/.containerenv')) {
			return self::BASE . '/' . $name . '/host_converger.log in the site container';
		}
		return '/var/log/joinery/host_converger.' . $name . '.log on the host (journalctl -u joinery-host-converger on a host with a data root)';
	}

	private static function siteRoot(?string $site_root): string {
		return rtrim($site_root ?? PathHelper::getSiteRoot(), '/');
	}
}
