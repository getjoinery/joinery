<?php
/**
 * OutboundCeiling — what the settings page says about this site's speed
 * ceiling (specs/node_outbound_and_transfer.md WP5).
 *
 * The ceiling is the machine's to hold: its joinery-limits unit
 * (maintenance_scripts/install_tools/outbound_limits.sh) holds every site to
 * the lower of root's figure and the site's own setting,
 * outbound_speed_ceiling_mbit, so the setting can only slow the site down. On
 * every pass the unit tells the site the outcome and what is in force, in
 * TOLD_FILE (inside its container, or on the host for a bare-metal site): the
 * limits' state, root's figure for this site, the one in force, and whether
 * the management node set the figures.
 * This reads that file and words it; nothing here changes a limit.
 *
 * @version 1.0
 */
class OutboundCeiling {

	const TOLD_FILE = '/run/joinery/outbound_limits.site';
	const SETTING = 'outbound_speed_ceiling_mbit';

	/** The states the machine tells a site: the limits on, off, not in force, on without their ceiling, or not covering it. */
	const STATES = array('on', 'off', 'refused', 'ceiling_failed', 'uncovered');

	/**
	 * What the machine last told this site: state (one of STATES; on when an
	 * older unit wrote no state), host_ceiling_mbit and ceiling_mbit (int, or
	 * null for none), and set_by ('plane' or ''). Null when the machine has
	 * said nothing (its limits are not installed, or have not run since boot).
	 */
	public static function told(string $path = self::TOLD_FILE): ?array {
		if (!is_readable($path)) {
			return null;
		}
		$out = array('state' => 'on', 'host_ceiling_mbit' => null, 'ceiling_mbit' => null, 'set_by' => '');
		foreach (file($path, FILE_IGNORE_NEW_LINES) ?: array() as $line) {
			if (!preg_match('/^([a-z_]+)=(.*)$/', $line, $m)) {
				continue;
			}
			if (($m[1] === 'host_ceiling_mbit' || $m[1] === 'ceiling_mbit') && preg_match('/^[1-9][0-9]{0,5}$/', $m[2])) {
				$out[$m[1]] = (int)$m[2];
			} elseif ($m[1] === 'set_by' && $m[2] === 'plane') {
				$out['set_by'] = 'plane';
			} elseif ($m[1] === 'state' && in_array($m[2], self::STATES, true)) {
				$out['state'] = $m[2];
			}
		}
		return $out;
	}

	/**
	 * The sentences after the setting's own help: the server's figure, the one
	 * in force, and how to go higher. $hosted: someone else hosts the site
	 * (OutboundTransferMeter::operator_hosted()). $site: the site's name, for
	 * the command on a container site; empty on bare metal.
	 */
	public static function helptext_append(?array $told, bool $hosted, string $site): string {
		if ($told === null) {
			return ' This server has not said what its own figure is: its outbound limits are not installed, or have not run since it started.';
		}
		$state = $told['state'] ?? 'on';
		if ($state === 'off') {
			return ' The server\'s outbound limits are off: nothing holds this site\'s speed, and a figure here has no effect until they are on again.';
		}
		if ($state === 'refused') {
			return ' The server\'s outbound limits are not in force (sudo joinery-limits status on the server says why): nothing holds this site\'s speed now.';
		}
		if ($state === 'uncovered') {
			return ' The server\'s outbound limits do not cover this site: it is not on a network of its own (move_site_to_own_network.sh on the server moves it).';
		}
		$host = $told['host_ceiling_mbit'];
		$now = $told['ceiling_mbit'];
		if ($state === 'ceiling_failed') {
			return ($host === null ? '' : ' The server\'s figure for this site: ' . $host . ' Mbit/s.')
				. ' The server could not put its speed ceiling in force for every site, so it may not hold this one now (sudo joinery-limits status on the server says why).';
		}
		$text = $host === null
			? ' The server sets no speed ceiling for this site, so a figure here is the only one.'
			: ' The server\'s figure for this site: ' . $host . ' Mbit/s.';
		$text .= $now === null ? ' In force now: none.' : ' In force now: ' . $now . ' Mbit/s.';
		if ($told['set_by'] === 'plane' || $hosted) {
			return $text . ' The server\'s figure is set by whoever hosts this site; to go higher, ask them.';
		}
		$command = 'sudo joinery-limits ceiling 500' . ($site !== '' ? ' --site=' . $site : '');
		return $text . ' To go higher than the server\'s figure, run on the server: ' . $command
			. ' (the figure in Mbit/s; off for none). It risks this: a site that is hacked, or suddenly popular, can then run up the provider\'s bill as fast as the machine\'s link allows.';
	}
}
