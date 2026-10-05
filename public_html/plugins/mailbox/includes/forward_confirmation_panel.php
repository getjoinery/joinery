<?php
/**
 * The confirmation state of each forwarding destination, as the mailbox and
 * domain editors show it below their form: confirmed, waiting, or not asked
 * yet, with Resend for any that has not confirmed
 * (specs/relay_receive_only_forwarding.md, rule 4). Rendered OUTSIDE the
 * editor's form, since each Resend is a form of its own.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ForwardConfirmation.php'));

/**
 * @param int      $domain_id
 * @param int|null $alias_id      null = the domain's catch-all
 * @param string[] $destinations
 * @param string   $post_url      the editor the Resend posts back to
 * @param array    $hidden        the editor's own identifying fields for the post
 */
function mailbox_forward_confirmation_panel(int $domain_id, ?int $alias_id, array $destinations, string $post_url, array $hidden): string {
	$destinations = array_values(array_filter(array_map(function ($d) { return strtolower(trim((string)$d)); }, $destinations), 'strlen'));
	if (!$destinations) {
		return '';
	}
	$html = '<div class="jy-ui" style="margin-top: 1rem;"><h3>Forwarding destinations</h3>'
		. '<p>Nothing is forwarded to an address until the person at it confirms. Each new address is sent '
		. 'one message asking them to.</p><table class="table"><tbody>';
	foreach ($destinations as $dest) {
		$status = ForwardConfirmation::status($domain_id, $alias_id, $dest);
		if ($status === InboundForwardDestination::STATUS_CONFIRMED) {
			$label = 'Confirmed';
		} elseif ($status === InboundForwardDestination::STATUS_PENDING) {
			$label = 'Waiting for confirmation — nothing is forwarded yet';
		} else {
			$label = 'Not asked yet — nothing is forwarded until it is asked and confirms';
		}
		$action = '';
		if ($status !== InboundForwardDestination::STATUS_CONFIRMED) {
			$action = AdminPage::action_button($status === null ? 'Send request' : 'Resend request', $post_url,
				array('hidden' => array_merge($hidden, array(
					'action' => 'resend_forward_confirmation',
					'forward_destination' => $dest,
				))));
		}
		$html .= '<tr><td>' . htmlspecialchars($dest) . '</td><td>' . htmlspecialchars($label) . '</td><td>' . $action . '</td></tr>';
	}
	return $html . '</tbody></table></div>';
}

/**
 * Handle a Resend from the panel. Returns the message to show, or null when
 * the input is not a Resend.
 */
function mailbox_forward_confirmation_resend(array $input, int $domain_id, ?int $alias_id, array $destinations): ?string {
	if (($input['action'] ?? '') !== 'resend_forward_confirmation') {
		return null;
	}
	$dest = strtolower(trim((string)($input['forward_destination'] ?? '')));
	$known = array_map(function ($d) { return strtolower(trim((string)$d)); }, $destinations);
	if ($dest === '' || !in_array($dest, $known, true)) {
		return 'That address is not a forwarding destination here.';
	}
	switch (ForwardConfirmation::request($domain_id, $alias_id, $dest)) {
		case ForwardConfirmation::OUTCOME_SENT:
			return 'A confirmation request was sent to ' . $dest . '.';
		case ForwardConfirmation::OUTCOME_CONFIRMED:
			return $dest . ' has already confirmed.';
		case ForwardConfirmation::OUTCOME_THROTTLED:
			return 'A request was sent to ' . $dest . ' within the last hour. Try again later.';
		default:
			return 'The request to ' . $dest . ' could not be sent. The error log has the reason.';
	}
}
