<?php
/**
 * IncidentSourceNeverDo - plane:never_do: a machine did something that has no
 * legitimate source on a machine Joinery runs, or something changed that no
 * job of this plane explains (specs/node_unexplained_root.md § 5.1, the first
 * slice; NeverDoCheck keeps the state).
 *
 * Critical while the node holds any unexplained item. It clears only when a
 * person presses Accept on the node page: an attacker who removes what they
 * added does not clear it.
 *
 * @version 1.2 - every occurrence is its own incident (IncidentSourceEveryOccurrenceNew): what is held after
 *                 Mark safe is new on the machine, never folded into the cleared incident
 * @version 1.1 - Then points at Mark safe on the incident page
 * @version 1.0
 */
class IncidentSourceNeverDo implements IncidentSourceEveryOccurrenceNew {

	const NAME = 'plane:never_do';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$state = NeverDoCheck::state($node);
		$held = is_array($state['unexplained'] ?? null) ? $state['unexplained'] : array();
		if (!$held) {
			return null;
		}
		$lines = array_map(array('NeverDoCheck', 'describe'), array_slice($held, 0, 8));
		if (count($held) > 8) {
			$lines[] = (count($held) - 8) . ' more on the node page';
		}
		return array(
			'title'    => 'Unexplained change on the machine: ' . NeverDoCheck::describe($held[0]),
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'What'  => implode('; ', $lines),
				'Why'   => 'Nothing on a machine Joinery runs does this, and no upgrade, installer or other job of this management node ran when it appeared. It is how a break-in keeps its way back in.',
				'Then'  => 'If you or a job you ran did this, or it is a known false alarm, press Mark safe on this page, in the Security sentinel box (Resolve alone does not clear it). If not, treat the machine as compromised: do not mark it safe, keep the evidence, and replace the machine.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'Marked safe: nothing unexplained is held.';
	}
}
?>
