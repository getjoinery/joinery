<?php
/**
 * IncidentSourceBreakGlassNoHolder - plane:break_glass_no_holder: emergency
 * root access is on for the beta (server_manager_beta_root_access) but the
 * holder is unset or unfit, with no vault or no second factor
 * (specs/implemented/beta_break_glass_root_access.md 3.4, 3.7), so new boxes are retired
 * instead of kept. Raised on this management node's own node; cleared when the
 * holder is fit or the setting is off.
 *
 * @version 1.0
 */
class IncidentSourceBreakGlassNoHolder implements IncidentSource {

	const NAME = 'plane:break_glass_no_holder';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node) || !BreakGlassRootAccess::beta_on()) {
			return null;
		}
		$unfit = BreakGlassRootAccess::holder_unfit_reason(BreakGlassRootAccess::holder_id());
		if ($unfit === null) {
			return null;
		}
		return array(
			'title'    => 'New installs cannot keep emergency root access: ' . $unfit,
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'Why'           => ucfirst($unfit) . '.',
				'What it means' => 'Emergency root access is on for the beta, but a kept password must be sealed to a holder who can '
					. 'read and retire it. Until one is named and fit, every new install\'s password is retired as soon as its '
					. 'agents are admitted, leaving no way back in if the agent breaks.',
				'What to do'    => 'Name the holder in Server Manager settings (Emergency root access holder, a user id). They need a '
					. 'vault and a second factor. Or turn emergency root access off.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The holder of kept root passwords is named and fit, or emergency root access is off.';
	}
}
