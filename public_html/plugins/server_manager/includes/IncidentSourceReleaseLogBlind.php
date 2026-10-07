<?php
/**
 * IncidentSourceReleaseLogBlind — plane:release_log_blind: the release-log
 * watch cannot say whether the next release can be logged (spec
 * release_transparency, O6, WP3).
 *
 * Silence is never green: Sigstore unreachable or answering in a form the
 * client cannot read, no conclusion for three days, or the watch's task
 * missing or off, each holds this open. A warning, critical after two weeks
 * without a conclusion.
 *
 * @version 1.0
 */
class IncidentSourceReleaseLogBlind implements IncidentSource {

	const NAME = 'plane:release_log_blind';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node) || !ReleaseLogWatch::watches()) {
			return null;
		}
		return ReleaseLogWatch::conditions(ReleaseLogWatch::state(), ReleaseLogWatch::taskRow(), time())['blind'];
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The release-log watch concludes again.';
	}
}
?>
