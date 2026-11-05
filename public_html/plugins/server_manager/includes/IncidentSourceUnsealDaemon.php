<?php
/**
 * IncidentSourceUnsealDaemon - plane:unseal_daemon: the node's unseal daemon
 * is not holding its vault keys the way it should (specs/unseal_daemon.md
 * § Every other way it dies, Managed nodes).
 *
 * Read from mgn_agent_unseal_daemon, which the agent reports on every poll
 * (1.109.0 and later). The node heals itself (restart in seconds, the previous
 * build after three crashes in a minute); this is where we see it, and the fix
 * is ours: a reinstall the converger carries out, or a release with a working
 * build. Four states hold it open:
 *
 *  - held: the sentinel's drop and hold is on. No Private vault opens on the
 *    node until root lifts it. Critical: a security case is open there.
 *  - down: the daemon has not been running for over a minute. Warning.
 *  - previous: the release's build crashed three times inside a minute and
 *    the node runs the previous one. Warning.
 *  - unlocked: running without locked memory, so its keys could reach swap.
 *    Warning.
 *
 * absent, running and restarting hold nothing open: absent is a node the
 * installer has not reached (the vault works on the pool path there), and
 * restarting is the seconds between a crash and its restart.
 *
 * @version 1.0
 */
class IncidentSourceUnsealDaemon implements IncidentSource {

	const NAME = 'plane:unseal_daemon';

	/** Every word the agent may report. */
	const STATES = array('absent', 'running', 'restarting', 'down', 'previous', 'held', 'unlocked');

	const OPEN = array(
		'held' => array(
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'title'    => 'The unseal daemon is held: no Private vault opens on this node',
			'means'    => 'Root put the sentinel\'s drop and hold on the node\'s unseal daemon: every open vault window was ended, '
				. 'and every Private unlock is refused until the hold is lifted. It is set when an infiltration case is open.',
			'next'     => 'Work the security case that set it. Once the node is known clean, root lifts the hold on the node: '
				. 'joinery-unseal-launch lift --all.',
		),
		'down' => array(
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'title'    => 'The unseal daemon is not running',
			'means'    => 'The node\'s unseal daemon has not been running for over a minute, so no member can open a Private vault '
				. 'through it. After five minutes the site falls back to holding open keys in its PHP pool.',
			'next'     => 'Read the daemon\'s journal on the node (unit_journal), then run its installer again (run_installer '
				. 'install_unseal_daemon.sh).',
		),
		'previous' => array(
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'title'    => 'The unseal daemon is running its previous build',
			'means'    => 'This release\'s unseal daemon crashed three times inside a minute on the node, so its launcher went back '
				. 'to the build the release before it shipped. Members can open their vaults; the node stays there until a '
				. 'release with a working build is installed.',
			'next'     => 'Read the daemon\'s journal on the node for why the new build will not stay up, fix it and publish a newer release.',
		),
		'unlocked' => array(
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'title'    => 'The unseal daemon is running without locked memory',
			'means'    => 'The daemon is serving, but its memory is not locked, so a key it holds could be written to swap.',
			'next'     => 'Run its installer again on the node (run_installer install_unseal_daemon.sh), which sets the memory-lock limit.',
		),
	);

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return null;
		}
		$state = (string)$node->get('mgn_agent_unseal_daemon');
		if (!isset(self::OPEN[$state])) {
			return null;
		}
		$open = self::OPEN[$state];
		return array(
			'title'    => $open['title'],
			'severity' => $open['severity'],
			'detail'   => array(
				'State'         => $state,
				'What it means' => $open['means'],
				'What to do'    => $open['next'],
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return 'The node is no longer paired, so its agent no longer reports its unseal daemon here.';
		}
		return 'The node\'s unseal daemon reports ' . ((string)$node->get('mgn_agent_unseal_daemon') ?: 'nothing') . '.';
	}
}
?>
