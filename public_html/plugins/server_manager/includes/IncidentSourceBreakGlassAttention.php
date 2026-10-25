<?php
/**
 * IncidentSourceBreakGlassAttention - plane:break_glass_attention: kept root
 * passwords (specs/implemented/beta_break_glass_root_access.md 3.7) that need their
 * holder's Retire: the site's owner turned emergency root access off, the
 * box's node record is gone, or the password is sealed to someone other than
 * the current holder. One incident, raised on this management node's own
 * node (a box's own node may be gone), whose detail lists each box; cleared
 * when the list is empty.
 *
 * @version 1.0
 */
class IncidentSourceBreakGlassAttention implements IncidentSource {

	const NAME = 'plane:break_glass_attention';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node)) {
			return null;
		}
		$holder = BreakGlassRootAccess::holder_id();
		$lines = array();
		foreach (BreakGlassRootAccess::kept_rows() as $provision) {
			$reasons = BreakGlassRootAccess::attention_reasons($provision, $holder);
			if ($reasons) {
				$lines[] = $provision->get('cvp_domain') . ' (provision #' . (int)$provision->key . '): ' . implode('; ', $reasons);
			}
		}
		if (!$lines) {
			return null;
		}
		return array(
			'title'    => count($lines) === 1
				? 'A kept root password needs its holder to retire it'
				: count($lines) . ' kept root passwords need their holders to retire them',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Boxes'         => implode("\n", $lines),
				'What it means' => 'These machines still accept a root password this management node keeps for the beta. '
					. 'Nothing removes it on its own: removal waits for the holder.',
				'What to do'    => 'The holder (named on each row) unlocks their vault and clicks Retire on the Server Manager dashboard. '
					. 'The machine then stops accepting the password, as any install password is retired.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'No kept root password needs its holder.';
	}
}
