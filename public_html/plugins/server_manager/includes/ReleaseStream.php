<?php
/**
 * ReleaseStream — where a server this management node installs (or copies)
 * fetches its release, and takes its upgrades, themes and plugins from after.
 *
 * A management node that publishes releases serves them itself: its archives
 * under static_files, utils/latest_release, and the ?serve-upgrade manifest
 * the servers' upgrades read. One that does not — built from a release and
 * never published from — has none of them. A server told to take its release
 * and its upgrades from it comes up with no theme and no plugins (every page
 * a 500), and a copy pinned to its source's version gets a 404. Such a node's
 * servers take all of it from where the node takes its own: its
 * upgrade_source. The stream stays one either way: the release a server runs
 * is the one this node runs, from the same publisher, under the same key.
 *
 * A node that publishes and lacks the one version asked for does not pass the
 * question upstream: its numbers name its own builds, and an upstream's
 * 0.8.198 is the same build as its 0.8.198 only by coincidence.
 *
 * @version 1.0
 */
class ReleaseStream {

	/**
	 * The base URL (https://host) a server installed from this node takes its
	 * release from and upgrades from. $plane_url is this node's own address;
	 * $version pins a release (a copy installs at its source's exact one).
	 */
	public static function source_for(string $plane_url, string $version = ''): string {
		$settings = Globalvars::get_instance();
		return self::choose(
			$plane_url,
			self::serves_releases(),
			$version !== '' && self::serves_releases($version),
			(string)$settings->get_setting('upgrade_source'),
			DeploymentHelper::isOriginNode(),
			$version
		);
	}

	/** The rule, pure over what it reads, for the test. */
	public static function choose(string $plane_url, bool $serves_any, bool $serves_version, string $upgrade_source,
			bool $is_origin, string $version = ''): string {
		$plane_url = rtrim($plane_url, '/');
		if ($serves_any) {
			// It publishes: its own builds, the pinned one included or not at all.
			return $plane_url;
		}
		$upstream = rtrim(trim($upgrade_source), '/');
		if ($upstream === '' || $is_origin) {
			// Nowhere else to send it: the install's preflight says what is missing.
			return $plane_url;
		}
		if (!preg_match('#^https?://#i', $upstream)) {
			$upstream = 'https://' . $upstream;
		}
		return $upstream;
	}

	/**
	 * Whether this node serves a release itself: one it published whose
	 * archive is still on disk (the rule utils/latest_release.php serves by),
	 * or, with $version, that one.
	 */
	public static function serves_releases(string $version = ''): bool {
		$filters = array();
		if ($version !== '') {
			if (!preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)$/', $version, $parts)) {
				return false;
			}
			$filters = array('major_version' => (int)$parts[1], 'minor_version' => (int)$parts[2],
				'patch_version' => (int)$parts[3]);
		}
		$static_dir = PathHelper::getSiteRoot() . '/static_files/';
		foreach (new MultiUpgrade($filters, array('upg_upgrade_id' => 'DESC'), 10) as $upgrade) {
			$name = (string)$upgrade->get('upg_name');
			if ($name !== '' && file_exists($static_dir . $name)) {
				return true;
			}
		}
		return false;
	}
}
