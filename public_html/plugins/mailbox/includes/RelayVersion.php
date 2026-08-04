<?php
/**
 * RelayVersion - is the code on a relay the code this deployment ships?
 *
 * A relay runs provision_relay.sh's output: a tenant shell, a sealing binary and
 * an rspamd configuration that all change when the platform changes. Nobody can
 * log in to a cloud relay to patch it (no root credential exists — see
 * specs/mailbox_relay_upgrade_without_server_manager.md), so the only remedy is
 * to replace the machine's contents. This class decides whether that is worth
 * offering.
 *
 * The shipped version is read from RELAY_VERSION in provision_relay.sh, which is
 * already the source of truth. Parsing it here rather than duplicating it means a
 * version bump has exactly one place to happen.
 *
 * @version 1.0
 */

class RelayVersion {

	/** The relay is running what this deployment ships. */
	const CURRENT = 'current';
	/** The relay is older than what this deployment ships — offer the upgrade. */
	const BEHIND  = 'behind';
	/** The relay is newer. The DEPLOYMENT is the thing that is behind. */
	const AHEAD   = 'ahead';
	/** The relay has not said, or said something unparseable — offer the upgrade. */
	const UNKNOWN = 'unknown';

	/**
	 * The relay version this deployment ships, e.g. '2.3'. '' if the declaration
	 * cannot be found, which reads as unknown everywhere downstream rather than
	 * as a version that beats or loses to anything.
	 */
	public static function shipped(): string {
		$path = PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_relay.sh');
		$source = @file_get_contents($path);
		if ($source === false) {
			return '';
		}
		// Anchored to the start of a line so the version HISTORY comments above it
		// ("# Version: 2.2 - ...") can never be mistaken for the declaration.
		if (preg_match('/^RELAY_VERSION="([^"]*)"/m', $source, $m)) {
			return trim($m[1]);
		}
		return '';
	}

	/**
	 * Compare a relay's reported version against what this deployment ships.
	 *
	 * version_compare(), never string comparison: '2.10' < '2.9' is TRUE as text,
	 * so a relay would report itself current one minor bump after the tenth. That
	 * bug would be silent, would take a decade of releases to appear, and would
	 * make the upgrade control stop offering itself exactly when it mattered.
	 *
	 * A version that does not look like a version reads as UNKNOWN, and unknown
	 * offers the upgrade: a relay that cannot say what it runs is a relay nobody
	 * can vouch for.
	 */
	public static function compare(string $running, ?string $shipped = null): string {
		// NULL means "look it up"; '' means "there is no shipped version", which is
		// UNKNOWN. Collapsing the two would make a deployment whose
		// provision_relay.sh cannot be read report every relay as current — the
		// precise failure this class exists to prevent.
		$running = trim($running);
		$shipped = ($shipped === null) ? self::shipped() : trim($shipped);

		if ($running === '' || $shipped === ''
			|| !preg_match('/^\d+(\.\d+)*$/', $running)
			|| !preg_match('/^\d+(\.\d+)*$/', $shipped)) {
			return self::UNKNOWN;
		}
		if (version_compare($running, $shipped, '<')) {
			return self::BEHIND;
		}
		if (version_compare($running, $shipped, '>')) {
			return self::AHEAD;
		}
		return self::CURRENT;
	}

	/**
	 * Where a relay stands, from its own cached health answer.
	 *
	 * A relay that has never answered, or answered the legacy plain-text PONG,
	 * reports no version at all — which is UNKNOWN, which offers the upgrade. That
	 * is the intended reading: a PONG relay predates the version marker and is by
	 * definition old.
	 */
	public static function forRelay(MailboxRelay $relay): string {
		return self::compare($relay->provisionedVersion());
	}

	/**
	 * Should an upgrade be offered? BEHIND and UNKNOWN yes, CURRENT and AHEAD no.
	 *
	 * AHEAD deliberately offers nothing: replacing newer code on the relay with
	 * older code from this deployment would be a downgrade wearing an upgrade's
	 * label, and the honest reading of a newer relay is that the deployment is the
	 * thing that needs updating.
	 */
	public static function offersUpgrade(string $standing): bool {
		return in_array($standing, array(self::BEHIND, self::UNKNOWN), true);
	}

	/** One plain sentence naming where a relay stands, for the Relay section. */
	public static function describe(string $standing, string $running, ?string $shipped = null): string {
		$shipped = ($shipped === null) ? self::shipped() : trim($shipped);
		$running = trim($running);
		switch ($standing) {
			case self::CURRENT:
				return 'The relay is running the current version (' . $running . ').';
			case self::BEHIND:
				return 'The relay is running version ' . $running . '; this site ships ' . $shipped . '.';
			case self::AHEAD:
				return 'The relay is running version ' . $running . ', which is newer than the '
					. $shipped . ' this site ships — update this site rather than the relay.';
		}
		return 'The relay has not reported which version it is running.';
	}
}
?>
