<?php
/**
 * Setup wizard step: Receiving email (specs/setup_wizard.md § Step 4).
 * One form, one Apply, one results panel. Posts wizard_provision to the
 * mailbox Setup tab's logic; the results panel below is the live state, so
 * re-rendering after a partial failure always tells the truth.
 * Included by views/setup.php with $page, $viewer, $settings, $next_key in scope.
 *
 * @version 1.1
 * @changelog 1.1 - The DNS provider select and credential inputs pick up the
 *   kit's form-control styling.
 */
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));

$setup_mr_result = $_SESSION['setup_mail_receive_result'] ?? null;
unset($_SESSION['setup_mail_receive_result']);

$setup_mr_aliases = new MultiInboundEmailAlias(array(
	'delivery_mode' => InboundEmailAlias::MODE_STORE,
	'enabled' => true,
	'deleted' => false,
));
$setup_mr_aliases->load();
$setup_mr_have = array();
foreach ($setup_mr_aliases as $setup_mr_alias) {
	$setup_mr_have[] = $setup_mr_alias;
}
?>

<?php if (is_array($setup_mr_result)) { ?>
<?php if (!empty($setup_mr_result['error'])) { ?>
	<div class="jy-alert jy-alert-error"><?php echo htmlspecialchars($setup_mr_result['error']); ?></div>
<?php } else { ?>
	<div class="jy-callout jy-callout-info">
		<div class="jy-callout-title">Applied</div>
		<ul>
			<li>Domain <strong><?php echo htmlspecialchars($setup_mr_result['domain']); ?></strong><?php echo $setup_mr_result['domain_created'] ? ' registered.' : ' was already registered.'; ?></li>
			<li>Mailbox <strong><?php echo htmlspecialchars($setup_mr_result['address']); ?></strong><?php echo $setup_mr_result['alias_created'] ? ' created and granted to you.' : ' already existed — your access is confirmed.'; ?></li>
<?php if (!empty($setup_mr_result['publish_summary'])) { ?>
			<li>DNS: <?php echo htmlspecialchars($setup_mr_result['publish_summary']); ?></li>
<?php } ?>
		</ul>
	</div>
<?php if (!empty($setup_mr_result['publish_error'])) { ?>
	<div class="jy-alert jy-alert-error">DNS publish: <?php echo htmlspecialchars($setup_mr_result['publish_error']); ?></div>
<?php } ?>
<?php } ?>
<?php } ?>

<?php if ($setup_mr_have) {
	// A mailbox exists — show where things stand. DNS still propagating is an
	// amber Continue, never a block.
	require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_setup_logic.php'));
?>
	<ul class="setup-checklist">
<?php
	$setup_mr_shown_domains = array();
	foreach ($setup_mr_have as $setup_mr_alias) {
		$setup_mr_domain = new InboundEmailDomain((int)$setup_mr_alias->get('iea_ied_inbound_email_domain_id'), TRUE);
		$setup_mr_status = (string)$setup_mr_domain->get('ied_setup_status');
		$setup_mr_dot = ($setup_mr_status === 'ok') ? 'green' : 'amber';
		$setup_mr_shown_domains[(int)$setup_mr_domain->key] = $setup_mr_domain;
?>
		<li>
			<span class="setup-dot <?php echo $setup_mr_dot; ?>"></span>
			<span><?php echo htmlspecialchars($setup_mr_alias->get_full_address()); ?></span>
			<span class="jy-muted"><?php echo ($setup_mr_status === 'ok') ? 'receiving' : 'waiting for DNS'; ?></span>
		</li>
<?php } ?>
	</ul>

<?php
	// The records that still need to exist, read-only, per domain.
	foreach ($setup_mr_shown_domains as $setup_mr_domain) {
		if ((string)$setup_mr_domain->get('ied_setup_status') === 'ok') {
			continue;
		}
		$setup_mr_plan = _setup_dns_plan_for_domain((int)$setup_mr_domain->key);
		if ($setup_mr_plan === null || $setup_mr_plan->isEmpty()) {
			continue;
		}
?>
	<h4 class="jy-mt-3">DNS records for <?php echo htmlspecialchars($setup_mr_domain->get('ied_domain')); ?></h4>
	<p class="jy-muted">Add these at your DNS host if you haven't. New records can take a while to propagate — you can continue and check back later on the mail Setup tab.</p>
	<div style="overflow-x:auto">
		<table class="jy-table">
			<tr><th>Type</th><th>Name</th><th>Value</th></tr>
<?php foreach ($setup_mr_plan->getRecords() as $setup_mr_record) { if ($setup_mr_record->absent) { continue; } ?>
			<tr>
				<td><?php echo htmlspecialchars($setup_mr_record->type); ?></td>
				<td><code><?php echo htmlspecialchars($setup_mr_record->name); ?></code></td>
				<td><code style="word-break:break-all"><?php echo htmlspecialchars($setup_mr_record->value); ?></code></td>
			</tr>
<?php } ?>
		</table>
	</div>
<?php } ?>

<?php } else {
	// No mailbox yet — the one-go form.
	$setup_mr_local_default = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$viewer->get('usr_first_name')));
	$setup_mr_drivers = array();
	foreach (DnsDriverRegistry::all() as $setup_mr_key => $setup_mr_class) {
		if ($setup_mr_class::credentialMode() === DnsProvider::CREDENTIAL_API) {
			$setup_mr_drivers[$setup_mr_key] = $setup_mr_class;
		}
	}
	$formwriter = $page->getFormWriter('setup-mail-receive', array(
		'action' => '/plugins/mailbox/admin/admin_mailbox_setup',
		'method' => 'POST',
	));
	$formwriter->begin_form();
	$formwriter->hiddeninput('action', '', array('value' => 'wizard_provision'));
	$formwriter->hiddeninput('return_to', '', array('value' => '/setup'));

	echo $formwriter->textinput('domain', 'Mail domain', array(
		'required' => true,
		'placeholder' => 'example.com',
		'helptext' => 'A domain you own. Mail for it will arrive at this site.',
	));
	echo $formwriter->textinput('local_part', 'Your address', array(
		'required' => true,
		'value' => $setup_mr_local_default,
		'helptext' => 'The part before the @.',
	));
?>
	<fieldset class="jy-fieldset">
		<legend>DNS records</legend>
		<label class="jy-check"><input type="radio" name="dns_mode" value="records" checked> Show me the records — I'll add them at my DNS host</label>
		<label class="jy-check"><input type="radio" name="dns_mode" value="publish" <?php echo $setup_mr_drivers ? '' : 'disabled'; ?>> Publish them for me (your DNS credential is used once and never stored)</label>

		<div id="setup-mr-publish" class="d-none">
			<label for="dns_provider">DNS provider</label>
			<select name="dns_provider" id="dns_provider" class="form-control">
				<option value="">Choose…</option>
<?php foreach ($setup_mr_drivers as $setup_mr_key => $setup_mr_class) { ?>
				<option value="<?php echo htmlspecialchars($setup_mr_key); ?>"><?php echo htmlspecialchars($setup_mr_class::getLabel()); ?></option>
<?php } ?>
			</select>
<?php foreach ($setup_mr_drivers as $setup_mr_key => $setup_mr_class) { ?>
			<div class="setup-mr-cred d-none" data-dns-driver="<?php echo htmlspecialchars($setup_mr_key); ?>">
<?php foreach ($setup_mr_class::credentialFields() as $setup_mr_field => $setup_mr_spec) {
			if ($setup_mr_field === 'session_token' || $setup_mr_field === 'client_ip') { continue; }
			$setup_mr_input_type = !empty($setup_mr_spec['secret']) ? 'password' : 'text';
?>
				<label><?php echo htmlspecialchars($setup_mr_spec['label'] ?? $setup_mr_field); ?></label>
				<input type="<?php echo $setup_mr_input_type; ?>" name="dns_cred_<?php echo htmlspecialchars($setup_mr_field); ?>" class="form-control" autocomplete="off">
<?php if (!empty($setup_mr_spec['help'])) { ?>
				<p class="jy-muted"><?php echo htmlspecialchars($setup_mr_spec['help']); ?></p>
<?php } ?>
<?php } ?>
			</div>
<?php } ?>
		</div>
	</fieldset>
	<div class="jy-mt-2">
		<?php echo $formwriter->submitbutton('btn_mr_apply', 'Apply', array('class' => 'btn btn-primary')); ?>
	</div>
<?php
	$formwriter->end_form();
?>
	<script>
	(function () {
		var publishBox = document.getElementById('setup-mr-publish');
		var provider = document.getElementById('dns_provider');
		function sync() {
			var mode = document.querySelector('input[name="dns_mode"]:checked');
			publishBox.classList.toggle('d-none', !mode || mode.value !== 'publish');
			document.querySelectorAll('.setup-mr-cred').forEach(function (div) {
				div.classList.toggle('d-none', div.getAttribute('data-dns-driver') !== provider.value);
			});
		}
		document.querySelectorAll('input[name="dns_mode"]').forEach(function (r) { r.addEventListener('change', sync); });
		provider.addEventListener('change', sync);
		sync();
	})();
	</script>
<?php } ?>
