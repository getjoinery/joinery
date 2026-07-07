<?php
/**
 * Outbound send protection ceremony for a sending identity domain
 * (specs/mailbox_outbound_send_protection.md, Phase 7).
 *
 * Reached from the domain editor. Walks the operator through: set the
 * forwarding subdomain → enable (seal a DKIM key in-window) → publish the DNS
 * records shown → verify the protected shape → activate (enforce). Staged
 * rotation (stage → publish → verify → cut over) and disable live here too.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_protect_domain_logic.php'));

$page_vars = process_logic(mailbox_protect_domain_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$self = '/plugins/mailbox/admin/admin_mailbox_protect';
$domain_id = (int)$domain->key;

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'Accounts' => '/plugins/mailbox/admin/admin_mailbox_accounts',
		'Protect ' . $domain_name => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

if (!empty($error)) {
	echo '<div class="alert alert-warning">' . htmlspecialchars($error) . '</div>';
}

$page->begin_box(array('title' => 'Protected sending identity — ' . $domain_name));

if ($is_protected) {
	echo '<p class="alert alert-success"><strong>Protection is enforced.</strong> While no vault is unlocked, '
		. 'nothing on the box can send DMARC-passing mail from ' . htmlspecialchars($domain_name)
		. '. Signing happens in-app, at compose time, inside an unlock window.</p>';
	if (!empty($has_pending_rotation)) {
		echo '<p class="alert alert-warning"><strong>A key rotation is staged</strong> under selector <code>'
			. htmlspecialchars($pending_selector) . '</code>. The current key keeps signing. Publish the pending '
			. 'DNS record below, then <strong>Verify &amp; cut over</strong>.</p>';
	}
} elseif ($has_key) {
	echo '<p>A DKIM key is sealed. Publish the DNS records below at your DNS provider, then <strong>Verify &amp; activate</strong>. '
		. 'Protection is not enforced until the DNS is verified.</p>';
} else {
	echo '<p>Enable protection to generate a DKIM key sealed to your vault. The plaintext key never touches disk and is '
		. 'never given to opendkim — a locked box cannot sign as this domain. This is an in-window action.</p>';
}

// ── forwarding subdomain (required: forwarding + bounces route while locked) ─
$fwd_form = $page->getFormWriter('fwd_form', array('action' => $self));
echo $fwd_form->begin_form();
$fwd_form->hiddeninput('ied_inbound_email_domain_id', '', array('value' => $domain_id));
$fwd_form->hiddeninput('action', '', array('value' => 'set_forwarding_subdomain'));
$fwd_form->textinput('ied_forwarding_subdomain', 'Forwarding subdomain', array(
	'value' => !empty($has_forwarding_subdomain) ? $forwarding_subdomain : 'fwd.' . $domain_name,
	'helptext' => 'Alias forwarding and its bounces leave from this subdomain while the vault is locked.',
));
$fwd_form->submitbutton('save_fwd_subdomain', 'Save subdomain');
echo $fwd_form->end_form();

// ── DNS records to publish (once a key exists) ──────────────────────────────
if ($has_key && !empty($dns_records)) {
	echo '<h3>DNS records to publish</h3>';
	echo '<div style="overflow-x:auto"><table class="table"><thead><tr>'
		. '<th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody>';
	foreach ($dns_records as $rec) {
		echo '<tr>'
			. '<td>' . htmlspecialchars($rec['type']) . '</td>'
			. '<td><code>' . htmlspecialchars($rec['name']) . '</code></td>'
			. '<td><code style="word-break:break-all">' . htmlspecialchars($rec['value']) . '</code>'
			. '<br><small>' . htmlspecialchars($rec['note']) . '</small></td>'
			. '</tr>';
	}
	echo '</tbody></table></div>';
}

// ── verification results (once a key exists) ────────────────────────────────
if (!empty($check_results)) {
	echo '<h3>Verification</h3><ul class="jy-check-list">';
	foreach ($check_results as $r) {
		$icon = ($r['status'] === InboundEmailSetupCheck::PASS) ? '✓'
			: (($r['status'] === InboundEmailSetupCheck::FAIL) ? '✗' : '…');
		$cls = ($r['status'] === InboundEmailSetupCheck::PASS) ? 'text-success'
			: (($r['status'] === InboundEmailSetupCheck::FAIL) ? 'text-danger' : 'text-muted');
		echo '<li><span class="' . $cls . '">' . $icon . '</span> <strong>' . htmlspecialchars($r['label']) . '</strong> — '
			. htmlspecialchars($r['summary']) . '</li>';
	}
	echo '</ul>';
}

// ── actions ─────────────────────────────────────────────────────────────────
echo '<div class="iea-page-actions" style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">';
$hidden_domain = array('ied_inbound_email_domain_id' => $domain_id);

if (!$has_key) {
	echo PublicPageBase::action_button('Enable protection (generate key)', $self,
		array('hidden' => $hidden_domain + array('action' => 'generate'), 'class' => 'btn btn-primary'));
} elseif (!$is_protected) {
	echo PublicPageBase::action_button('Verify & activate', $self,
		array('hidden' => $hidden_domain + array('action' => 'activate'), 'class' => 'btn btn-primary'));
	echo PublicPageBase::action_button('Re-generate key', $self,
		array('hidden' => $hidden_domain + array('action' => 'generate'), 'class' => 'btn btn-soft-default',
			'confirm' => 'Re-generating replaces the sealed key and selector. You must publish the new DNS record. Continue?'));
} elseif (!empty($has_pending_rotation)) {
	echo PublicPageBase::action_button('Verify & cut over', $self,
		array('hidden' => $hidden_domain + array('action' => 'activate_rotation'), 'class' => 'btn btn-primary'));
	echo PublicPageBase::action_button('Cancel rotation', $self,
		array('hidden' => $hidden_domain + array('action' => 'cancel_rotation'), 'class' => 'btn btn-soft-default',
			'confirm' => 'Abandon the staged key? The current key was never touched and keeps signing.'));
	echo PublicPageBase::action_button('Disable protection', $self,
		array('hidden' => $hidden_domain + array('action' => 'disable'), 'class' => 'btn btn-soft-danger',
			'confirm' => 'Disable protection? This domain will be able to send ambiently again.'));
} else {
	echo PublicPageBase::action_button('Rotate key', $self,
		array('hidden' => $hidden_domain + array('action' => 'rotate'), 'class' => 'btn btn-soft-default',
			'confirm' => 'Stage a new sealed key under a new selector? The current key keeps signing until the new DNS record verifies and you cut over.'));
	echo PublicPageBase::action_button('Disable protection', $self,
		array('hidden' => $hidden_domain + array('action' => 'disable'), 'class' => 'btn btn-soft-danger',
			'confirm' => 'Disable protection? This domain will be able to send ambiently again.'));
}
echo '</div>';

$page->end_box();

// ── the automated-subdomain question (resolved product decision) ────────────
$page->begin_box(array('title' => 'Does this domain send automated mail?'));
echo '<p>Lists, receipts, and notifications must run around the clock, but a protected identity can only send while '
	. 'unlocked. If ' . htmlspecialchars($domain_name) . ' sends automated mail, add a dedicated sending subdomain '
	. '(e.g. <code>mail.' . htmlspecialchars($domain_name) . '</code>) as an <strong>ordinary, non-protected</strong> '
	. 'domain, signed ambiently by <code>provision_dkim.sh</code> as usual. Under this domain\'s strict alignment the '
	. 'subdomain\'s key can never sign as the bare domain, so a locked box can send as '
	. '<code>list@mail.' . htmlspecialchars($domain_name) . '</code> but never as <code>you@' . htmlspecialchars($domain_name) . '</code>. '
	. 'Only the bare domain is really you — a human-trust boundary worth noting to recipients.</p>';
echo '<a class="btn btn-soft-default" href="/plugins/mailbox/admin/admin_mailbox_domains?action=add">+ Add a sending subdomain</a>';
$page->end_box();

$page->admin_footer();
?>
