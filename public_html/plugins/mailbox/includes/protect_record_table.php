<?php
/**
 * The send-protection records as one ordered table
 * (specs/send_protection_one_press.md WP4).
 *
 * Renders mailbox_protect_record_list(): group 1, *Publish now*, then group 2,
 * *Publish after signing is on*, greyed out until the flag is set. The order
 * on the page is the order that keeps mail flowing, so nobody has to read it
 * out of prose. assets/protect_records.js asks mailbox/protect_record_list
 * every 30 seconds while something is outstanding, repaints each row's status,
 * and turns on the [data-protect-turn-on] button once the activate gate would
 * pass.
 *
 * @version 1.2 - each row carries its value, so the script can tell when a value to publish changed
 * @version 1.1 - a row whose value could not be built shows only why
 * @version 1.0
 */

/**
 * @param array $list    mailbox_protect_record_list()
 * @param int   $domain_id what the script asks about
 */
function mailbox_protect_record_table(array $list, int $domain_id): string {
	$poll = $list['protected'] ? !$list['strict_live'] : !$list['signing_ready'];
	$titles = array(
		'now'   => array('1. Publish now',
			'These let your key sign. None of them asks any mail server to reject anything, so the mail you '
			. 'send today is unaffected.'),
		'after' => array('2. Publish after signing is on',
			$list['protected']
				? 'Signing is on, so these are safe now. They make other servers reject mail your key did not sign.'
				: 'These make other servers reject mail your key did not sign. Published before signing is on, '
					. 'they would reject your own mail. They stay greyed out until then.'),
	);

	$html = '<div class="mb-3" data-protect-records data-domain-id="' . (int)$domain_id . '"'
		. ' data-poll="' . ($poll ? '1' : '0') . '" data-protected="' . ($list['protected'] ? '1' : '0') . '">';
	$html .= '<div class="table-responsive mb-2"><table class="table table-sm table-bordered mb-0">';
	$html .= '<thead><tr><th>Record</th><th>Value to publish</th><th>Status</th></tr></thead>';
	foreach ($list['groups'] as $group) {
		$active = !empty($group['active']);
		list($title, $why) = $titles[$group['key']];
		$html .= '<tbody data-group="' . htmlspecialchars($group['key']) . '"'
			. ($active ? '' : ' aria-disabled="true" style="opacity:.55"') . '>';
		$html .= '<tr><th colspan="3">' . htmlspecialchars($title)
			. '<div class="small text-muted fw-normal">' . htmlspecialchars($why) . '</div></th></tr>';
		foreach ($group['rows'] as $row) {
			$html .= '<tr data-record-key="' . htmlspecialchars($row['key']) . '" data-record-value="'
				. htmlspecialchars($row['value']) . '">';
			$html .= '<td><strong>' . htmlspecialchars($row['type']) . '</strong><div><code>'
				. htmlspecialchars($row['name']) . '</code></div></td>';
			$html .= '<td>';
			if ($row['absent']) {
				$html .= '<div class="small">Remove this record.</div>';
			} elseif ($row['value'] === '') {
				// Its value could not be built (the hint says why).
			} elseif ($active) {
				$html .= PublicPageBase::copy_field($row['value']);
			} else {
				// Greyed: shown so the whole order is visible, not offered to copy.
				$html .= '<code class="small" style="word-break:break-all">' . htmlspecialchars($row['value']) . '</code>';
			}
			if ($row['hint'] !== '') {
				$html .= '<div class="small text-muted mt-1">' . htmlspecialchars($row['hint']) . '</div>';
			}
			$html .= '</td>';
			$html .= '<td data-record-status>' . mailbox_protect_record_status($row) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody>';
	}
	$html .= '</table></div>';
	if ($poll) {
		$html .= '<p class="small text-muted mb-0"><span data-protect-checked>This page checks again every 30 '
			. 'seconds, asking your domain\'s own nameservers, so a record shows as live as soon as they serve '
			. 'it.</span> <button type="button" class="btn btn-sm btn-soft-default ms-1" data-protect-check-now>'
			. 'Check now</button></p>';
	}
	$html .= '</div>';
	return $html . PageScript::src('plugins/mailbox/assets/protect_records.js');
}

/** A row's status cell. The script builds the same markup (protect_records.js statusHtml()). */
function mailbox_protect_record_status(array $row): string {
	$class = array(
		'live'    => 'badge-subtle-success',
		'pending' => 'badge-subtle-primary',
		'missing' => 'badge-subtle-primary',
		'differs' => 'badge-subtle-warning',
		'remove'  => 'badge-danger',
		'waiting' => 'badge-subtle-secondary',
	)[$row['status']] ?? 'badge-subtle-secondary';
	$html = '<span class="badge ' . $class . '">' . htmlspecialchars($row['label']) . '</span>';
	foreach ($row['live'] as $v) {
		$html .= '<div class="small text-muted mt-1" style="word-break:break-all">Live now: '
			. htmlspecialchars($v) . '</div>';
	}
	return $html;
}
?>
