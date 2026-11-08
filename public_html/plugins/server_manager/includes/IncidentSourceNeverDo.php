<?php
/**
 * IncidentSourceNeverDo - plane:never_do: a machine shows something nothing
 * Joinery, Ubuntu's own packages or a normal operator does can put there
 * (specs/node_unexplained_root.md § 5.1, specs/never_do_false_alarms.md;
 * NeverDoCheck keeps the state and decides what is in the alarm).
 *
 * Critical while the node's alarm holds anything. It clears only when a
 * person presses Mark safe: an attacker who removes what they added does not
 * clear it. The review list raises nothing.
 *
 * @version 1.3 - only the alarm (NeverDoCheck::ALARM_KINDS, two checks in a row) raises; the text says what it is
 *                 and that Mark safe remembers the file; an incident whose items moved to the review list clears
 *                 saying so
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
		$held = $state['alarm'] ?? array();
		if (!$held) {
			return null;
		}
		$lines = array_map(array('NeverDoCheck', 'describe'), array_slice($held, 0, 8));
		if (count($held) > 8) {
			$lines[] = (count($held) - 8) . ' more on the node page';
		}
		return array(
			'title'    => 'Security alarm on the machine: ' . NeverDoCheck::describe($held[0]),
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'What'  => implode('; ', $lines),
				'Why'   => 'Nothing Joinery, Ubuntu\'s own packages or a normal operator does puts this on a machine, and two checks in a row, five minutes apart, saw it. It is how a break-in keeps its way back in.',
				'Then'  => 'If you made this yourself (a setuid program you built, say), press Mark safe on this page, in the Security sentinel box: that file stays quiet until its content changes. Resolve alone does not clear it. If you did not, treat the machine as compromised: do not mark it safe, keep the evidence, and replace the machine.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		$state = NeverDoCheck::state($node);
		$moved = (int)($state['moved_at'] ?? 0);
		if ($moved && (int)($state['accepted_at'] ?? 0) < $moved) {
			return 'Not an alarm any more: what it held can happen in ordinary operation, and moved to Changes to review on the node page.';
		}
		return 'Marked safe: the alarm holds nothing.';
	}
}
?>
