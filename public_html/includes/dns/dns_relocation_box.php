<?php
/**
 * Renderer for the DNS relocation flow (specs/wizard_dns_relocation.md Part 3).
 *
 * Mounted wherever a gated DNS host leaves an operator with no automatic path:
 * the setup wizard's sending step and the shared publish box. The caller says
 * where the POST goes ('form_action' + 'hidden'); everything else — the target
 * choice, the credential fields with their guides, the seeded handover with
 * the nameserver list, the copied-records honesty table — renders the same on
 * every surface.
 *
 * Faces, chosen by what the caller hands in as 'result' (a DnsRelocation::seed
 * outcome stashed for one render, per the wizard's session-notice pattern):
 *   none    the offer: pick a destination, paste its credential, press go.
 *   error   what went wrong, with the form open to try again.
 *   success the handover: the zone is seeded and waiting; here are the
 *           nameservers to enter at the registrar, here is exactly what was
 *           copied, and here is why a subdomain we could not guess is not in
 *           that list.
 *
 * @version 1.3 - the handover no longer claims the nameserver change happens
 *                at the source DNS host; the per-registrar help line says where
 * @version 1.2 - a single destination asks nothing: no dropdown (hidden
 *                target field), notes folded into the credential guide's
 *                caution, no footer paragraph, and the button names the host
 * @version 1.1 - 'only' narrows the destination offer for a mount that has
 *                already named it; a single target renders preselected
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRelocation.php'));

/**
 * @param object $page Anything with getFormWriter() — PublicPage or AdminPage.
 * @param array  $vars 'domain', 'source_key', 'source_label', 'form_action',
 *                     'hidden' (name => value routing fields),
 *                     'result' (seed outcome + target/target_label, or null),
 *                     'recheck_hint' (how this surface re-checks delegation).
 */
function dns_relocation_render($page, array $vars): void {
	$domain  = (string)($vars['domain'] ?? '');
	$targets = DnsRelocation::targets();
	// A mount that has already named the destination ("move to Linode")
	// narrows the offer to it; a single target is preselected by the form.
	$only = (array)($vars['only'] ?? array());
	if ($only) {
		$targets = array_intersect_key($targets, array_flip($only));
	}
	if ($domain === '' || !$targets) {
		return;
	}
	$source_label = (string)($vars['source_label'] ?? 'your current DNS host');
	$result = is_array($vars['result'] ?? null) ? $vars['result'] : null;
	$moved  = ($result !== null && ($result['error'] ?? '') === '');

	echo '<div class="jy-ui">';

	if ($result !== null && !$moved) {
		echo '<div class="jy-alert jy-alert-error">' . htmlspecialchars((string)$result['error']) . '</div>';
	}

	if ($moved) {
		$target_label = (string)($result['target_label'] ?? 'the new host');
		echo '<div class="jy-callout jy-callout-info">';
		echo '<div class="jy-callout-title">Your DNS is set up and waiting at ' . htmlspecialchars($target_label) . '</div>';
		if (($result['summary'] ?? '') !== '') {
			echo '<p>' . htmlspecialchars((string)$result['summary']) . '</p>';
		}
		echo '<p>One step remains: change the domain\'s nameservers to these.</p>';
		echo '<p style="margin:8px 0">';
		foreach ((array)$result['nameservers'] as $ns) {
			echo '<code style="display:inline-block; margin:2px 6px 2px 0; padding:2px 8px">'
				. htmlspecialchars((string)$ns) . '</code>';
		}
		echo '</p>';
		echo '<p>' . htmlspecialchars(DnsRelocation::registrarNameserverHelp((string)($vars['source_key'] ?? ''))) . '</p>';
		echo '<p class="jy-muted">Your site and mail keep working through the change — everything is '
			. 'already in place at ' . htmlspecialchars($target_label) . '. The switch usually reaches the '
			. 'internet within an hour, sometimes a day; '
			. htmlspecialchars((string)($vars['recheck_hint'] ?? 'check back here'))
			. ' and this page will pick it up.</p>';
		echo '</div>';

		// Exactly what carried over — and, plainly, what could not have.
		echo '<details class="jy-mt-2"><summary>What was copied to ' . htmlspecialchars($target_label) . '</summary>';
		if (!empty($result['copied'])) {
			echo '<div style="overflow-x:auto"><table class="jy-table">'
				. '<tr><th>Type</th><th>Name</th><th>Value</th></tr>';
			foreach ((array)$result['copied'] as $row) {
				echo '<tr><td>' . htmlspecialchars((string)$row['type']) . '</td>'
					. '<td><code>' . htmlspecialchars((string)$row['name']) . '</code></td>'
					. '<td><code style="word-break:break-all">' . htmlspecialchars((string)$row['value']) . '</code></td></tr>';
			}
			echo '</table></div>';
		} else {
			echo '<p class="jy-muted">Nothing was visible to copy — the records this site needs were '
				. 'still created.</p>';
		}
		echo '<p class="jy-muted">This is everything the domain answers publicly at the names we could '
			. 'guess. DNS does not let anyone list a domain\'s records from outside, so a name we did not '
			. 'guess — <code>shop.' . htmlspecialchars($domain) . '</code>, <code>blog.' . htmlspecialchars($domain)
			. '</code> — did <strong>not</strong> carry over. If you use one, add it at '
			. htmlspecialchars($target_label) . ' before changing the nameservers.</p>';
		echo '</details>';

		echo '<details class="jy-mt-2"><summary>Run the setup again</summary>';
	}

	dns_relocation_form($page, $vars, $targets, $domain);

	if ($moved) {
		echo '</details>';
	}
	echo '</div>';
}

/**
 * The destination and its credential, posted to wherever the mount says.
 *
 * A mount that already named the destination (the wizard's "move to Linode"
 * radio) renders no question at all: the target rides as a hidden field and
 * the form is one credential and one button. Notes and instructions live in
 * the field's "How do I do this?" modal, never as paragraphs over the form.
 */
function dns_relocation_form($page, array $vars, array $targets, string $domain): void {
	$single = count($targets) === 1 ? (string)array_key_first($targets) : '';

	$form = $page->getFormWriter('dns-relocation', array(
		'action' => (string)($vars['form_action'] ?? ''), 'method' => 'POST'));
	$form->begin_form();
	foreach ((array)($vars['hidden'] ?? array()) as $name => $value) {
		$form->hiddeninput($name, '', array('value' => (string)$value));
	}
	if ($single !== '') {
		$form->hiddeninput('dns_move_target', '', array('value' => $single));
	} else {
		$options = array();
		foreach ($targets as $key => $class) {
			$precondition = ($key === 'linode') ? 'free with any active Linode service'
				: (($key === 'cloudflare') ? 'free for anyone' : '');
			$options[$key] = $class::getLabel() . ($precondition !== '' ? ' — ' . $precondition : '');
		}
		$form->dropinput('dns_move_target', 'Where should your DNS live?', array(
			'options' => $options,
			'empty_option' => 'Choose…',
			'value' => '',
		));
	}
	foreach ($targets as $key => $class) {
		echo '<div class="dns-move-cred' . ($single === $key ? '' : ' d-none')
			. '" data-move-target="' . htmlspecialchars($key) . '">';
		// What must be true at this vendor rides as the guide's caution: the
		// Cloudflare zone that has to exist first, or Linode's active-service
		// requirement.
		$note = ($key === 'cloudflare')
			? 'First add ' . $domain . ' as a site in your Cloudflare account — the free plan is fine; '
				. 'that creates the zone this fills and shows your two nameservers.'
			: $class::prerequisiteNote();
		$guide = $class::credentialGuide();
		if ($note !== '' && is_array($guide)) {
			$guide['caution'] = trim($note . ' ' . (string)($guide['caution'] ?? ''));
		}
		foreach ($class::credentialFields() as $field => $spec) {
			if ($field === 'session_token' || $field === 'client_ip') { continue; }
			$opts = array(
				'autocomplete' => 'off',
				'help_modal' => $guide,
			);
			$guide = null;
			if (!empty($spec['secret'])) {
				$form->passwordinput('move_cred_' . $field, $spec['label'] ?? $field, $opts);
			} else {
				$form->textinput('move_cred_' . $field, $spec['label'] ?? $field, $opts);
			}
		}
		echo '</div>';
	}
	echo '<div class="jy-mt-2">';
	$form->submitbutton('btn_dns_move', 'Set up my DNS at '
		. ($single !== '' ? $targets[$single]::getLabel() : 'the new host'),
		array('class' => 'btn btn-primary'));
	echo '</div>';
	$form->end_form();
?>
<script>
(function () {
	var target = document.getElementById('dns_move_target');
	if (!target) { return; }
	function sync() {
		document.querySelectorAll('.dns-move-cred').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-move-target') !== target.value);
		});
	}
	target.addEventListener('change', sync);
	sync();
})();
</script>
<?php
}
