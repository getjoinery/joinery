<?php
/**
 * IncidentSourceReleaseLog — plane:release_log: a release cannot be logged,
 * now or when Sigstore's next log takes over (spec release_transparency,
 * O6, WP3).
 *
 * Read from the conclusion ReleaseLogWatch stores each day. Raised only on
 * this management node's own node, and only where this site logs releases.
 * A future log whose key nodes do not hold yet (unpublished by Sigstore,
 * unpinned here, or pinned and not yet shipped) is a warning, critical in its
 * last 30 days; any other refusal is critical, since publishing is refused now. A
 * watch that cannot see keeps the last conclusion standing: this clears only
 * when a run concludes clear.
 *
 * @version 1.0
 */
class IncidentSourceReleaseLog implements IncidentSource {

	const NAME = 'plane:release_log';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node) || !ReleaseLogWatch::watches()) {
			return null;
		}
		return ReleaseLogWatch::conditions(ReleaseLogWatch::state(), ReleaseLogWatch::taskRow(), time())['refused'];
	}

	public function cleared_text(ManagedNode $node): string {
		return 'Sigstore\'s logs and the keys this repository pins agree again, so the next release can be logged.';
	}
}
?>
