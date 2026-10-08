<?php
/**
 * IncidentSourceReleaseLogTailBlind - plane:release_log_tail_blind: the
 * release-log reader is not keeping up, so an entry under our key would go
 * unseen (spec release_transparency, O5).
 *
 * From ReleaseLogTail::blindCondition(): the Tail Release Log task missing
 * or off, or a log nodes trust not read up to a settled checkpoint for a
 * day. Warning; critical after a week. Raised only on this management node's
 * own node, and only where this site logs releases.
 *
 * @version 1.0
 */
class IncidentSourceReleaseLogTailBlind implements IncidentSource {

	const NAME = 'plane:release_log_tail_blind';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node) || !ReleaseLogWatch::watches()) {
			return null;
		}
		return ReleaseLogTail::blindCondition(ReleaseLogTail::state(), ReleaseLogTail::taskRow(), ReleaseLogTail::logOrigins(), time());
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The release-log reader has read every log nodes trust up to date again.';
	}
}
?>
