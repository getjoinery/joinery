<?php
/**
 * Server Manager - Provisioning Setup
 * URL: /admin/server_manager/provisioning_setup
 *
 * Guided activation of the hosting provisioning pipeline: every checklist
 * item shows its live state with a one-click action where the platform can
 * do the work itself.
 *
 * @version 1.3 - the hosted-tier card, and the products section names both fulfillment references
 * @version 1.2 - the domain-registration card
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/admin_provisioning_setup_logic.php'));

$page_vars = process_logic(admin_provisioning_setup_logic(array_merge($_GET, $_POST, $params ?? [])));

$session = SessionControl::get_instance();
$status = $page_vars['status'];

$api = $status['api'];
$question = $status['question'];
$email = $status['email'];
$tasks = $status['tasks'];
$cloud = $status['cloud'];
$shared = $status['shared_hosts'];
$agent = $status['agent'];
$domains = $status['domains'];
$hosted = $status['hosted'];

function smps_badge(bool $ok, string $ok_label = 'OK', string $bad_label = 'Missing', string $bad_color = 'warning'): string {
	return $ok
		? '<span class="badge bg-success">' . htmlspecialchars($ok_label) . '</span>'
		: '<span class="badge bg-' . $bad_color . '">' . htmlspecialchars($bad_label) . '</span>';
}

$page = new AdminPage();

$page->admin_header(array(
	'menu-id' => 'server-manager',
	'page_title' => 'Provisioning Setup',
	'readable_title' => 'Provisioning Setup',
	'breadcrumbs' => array(
		'Server Manager' => '/admin/server_manager',
		'Provisioning Setup' => '',
	),
	'session' => $session,
));

$page->begin_box(array());
?>

<h4>1. Job agent</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Agent</th>
		<td>
			<?php if (!$agent['present']): ?>
				<?= smps_badge(false, '', 'Never connected', 'danger') ?>
				&nbsp; No agent has ever polled this site's job queue — jobs the pipeline
				creates will sit pending forever. Install joinery-agent on this host
				(<code>joinery-agent-installer.sh</code>; containers are auto-detected
				and supervised by cron).
			<?php else: ?>
				<?= smps_badge($agent['online'], 'Online', 'Offline', 'danger') ?>
				&nbsp; <?= htmlspecialchars($agent['name']) ?>
				<?php if ($agent['version']): ?>v<?= htmlspecialchars($agent['version']) ?><?php endif; ?>
				&nbsp;&middot;&nbsp; last heartbeat <?= htmlspecialchars($agent['last_heartbeat']) ?> UTC
			<?php endif; ?>
		</td>
	</tr>
</table>

<hr>

<h4>2. Store API connection</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Credentials</th>
		<td>
			<?= smps_badge($api['configured'], 'Configured', 'Not configured') ?>
			<?php if ($api['configured']): ?>
				&nbsp; <?= smps_badge($api['probe_ok'], 'API responding', 'API not responding', 'danger') ?>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th>Store</th>
		<td><?= $api['is_self'] ? 'This site' : htmlspecialchars($api['url']) ?></td>
	</tr>
	<tr>
		<th>Service user</th>
		<td><?= htmlspecialchars($api['service_user_email']) ?> &nbsp;
			<?= smps_badge($api['service_user_exists'], 'Exists', 'Will be created') ?></td>
	</tr>
</table>
<?php if (!$api['configured']): ?>
	<form method="post">
		<input type="hidden" name="action" value="setup_api">
		<button type="submit" class="btn btn-primary">Create service user + API key</button>
	</form>
<?php else: ?>
	<form method="post" onsubmit="return confirm('Mint a new API key and retire the current one?');">
		<input type="hidden" name="action" value="setup_api">
		<input type="hidden" name="rotate" value="1">
		<button type="submit" class="btn btn-outline-secondary btn-sm">Rotate API key</button>
	</form>
<?php endif; ?>

<hr>

<h4>3. Domain question</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Question</th>
		<td>
			<?= smps_badge($question['exists'], 'Created (ID ' . (int)$question['id'] . ')', 'Not created') ?>
			<?php if ($question['exists']): ?>
				&nbsp; <?= htmlspecialchars($question['text']) ?>
			<?php endif; ?>
		</td>
	</tr>
	<?php if ($question['exists']): ?>
	<tr>
		<th>Attached to products</th>
		<td>
			<?php if ($question['attached_products']): ?>
				<?php foreach ($question['attached_products'] as $pid => $pname): ?>
					<a href="/admin/admin_product_edit?pro_product_id=<?= (int)$pid ?>"><?= htmlspecialchars($pname) ?></a>&nbsp;
				<?php endforeach; ?>
			<?php else: ?>
				<span class="badge bg-warning">None attached directly</span>
				— customer-cloud products ask it automatically via their fulfillment
				provider; only shared-host products need it attached on the product
				edit page.
			<?php endif; ?>
		</td>
	</tr>
	<?php endif; ?>
</table>
<?php if (!$question['exists']): ?>
	<form method="post">
		<input type="hidden" name="action" value="create_question">
		<button type="submit" class="btn btn-primary">Create domain question</button>
	</form>
<?php endif; ?>

<hr>

<h4>4. Emails</h4>
<?php
$fw_email = $page->getFormWriter('form_email');
echo $fw_email->begin_form();
echo '<input type="hidden" name="action" value="save_email">';
$fw_email->textinput('welcome_from_email', 'Welcome email from address', ['value' => $email['welcome_from_email']]);
$fw_email->textinput('welcome_from_name', 'Welcome email from name', ['value' => $email['welcome_from_name']]);
$fw_email->textinput('admin_alert_email', 'Admin alert address', ['value' => $email['admin_alert_email']]);
$fw_email->submitbutton('btn_save_email', 'Save email settings');
echo $fw_email->end_form();
?>

<hr>

<h4>5. Scheduled tasks</h4>
<table class="table table-sm">
	<?php foreach ($tasks as $class => $info): ?>
	<tr>
		<th style="width:260px"><?= htmlspecialchars($info['name']) ?></th>
		<td><?= smps_badge($info['state'] === 'active', 'Active', ucfirst($info['state'])) ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<?php $all_active = !in_array(false, array_map(fn($t) => $t['state'] === 'active', $tasks), true); ?>
<?php if (!$all_active): ?>
	<form method="post">
		<input type="hidden" name="action" value="activate_tasks">
		<button type="submit" class="btn btn-primary">Activate provisioning tasks</button>
	</form>
<?php endif; ?>

<hr>

<h4>6. Shared-host fulfillment</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Provisioning-enabled hosts</th>
		<td>
			<?= smps_badge($shared['enabled_count'] > 0, $shared['enabled_count'] . ' enabled', 'None') ?>
			— opt a host in from the <a href="/admin/server_manager">dashboard</a> (Edit &rarr; Provisioning Enabled).
			Only needed for shared-host products; customer-cloud products create their own server.
		</td>
	</tr>
</table>

<hr>

<h4>7. Customer-cloud fulfillment</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Linode OAuth app</th>
		<td>
			<?= smps_badge($cloud['oauth_configured'], 'Configured', 'Not configured') ?>
			— credentials at <a href="/admin/admin_oauth_providers">OAuth Providers</a>.
		</td>
	</tr>
	<tr>
		<th>Instance access</th>
		<td>
			<span class="badge bg-success">Keyless</span>
			Instances are created with a one-time root password and no SSH key of ours — nothing is placed on a machine we create.
		</td>
	</tr>
</table>
<?php
$fw_cloud = $page->getFormWriter('form_cloud');
echo $fw_cloud->begin_form();
echo '<input type="hidden" name="action" value="save_cloud">';
$fw_cloud->textinput('referral_url', 'Linode referral URL', ['value' => $cloud['referral_url']]);
$fw_cloud->textinput('region', 'Default region', ['value' => $cloud['region']]);
$fw_cloud->textinput('instance_type', 'Default instance type', ['value' => $cloud['type']]);
$fw_cloud->textinput('image', 'OS image', ['value' => $cloud['image']]);
$fw_cloud->submitbutton('btn_save_cloud', 'Save customer-cloud settings');
echo $fw_cloud->end_form();
?>

<hr>

<h4>8. Domain registration</h4>
<p>With this configured, a hosting product can also sell the buyer their domain name — they type
the name they want at checkout, pay once, and the pipeline registers it with <strong>them</strong>
as the legal owner, wires DNS to their box and turns on email. Leave it unset and buyers bring
their own domain exactly as before.</p>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Registrar</th>
		<td>
			<?= smps_badge($domains['configured'], ($domains['label'] ?: 'Configured'), 'Not configured') ?>
			<?php if ($domains['sandbox']): ?>
				<span class="badge bg-warning">Sandbox</span> — names bought here are not real.
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th>API key</th>
		<td>
			<?= smps_badge($domains['key_present'], 'Stored', 'Not set') ?>
			— sealed at rest. Namecheap grants API access only to accounts with 20+ domains,
			$50 in the balance, or $50 spent in the last two years, and only from an allowlisted
			address.
		</td>
	</tr>
	<tr>
		<th>Domain-year product</th>
		<td>
			<?= smps_badge($domains['product_ok'],
				'Selected', $domains['product_id'] > 0 ? 'Selected but unusable' : 'Not selected') ?>
			— create a product with one version priced <em>user</em> and choose it in the store's
			Domain registration setting. A selected product that was later deleted, or whose
			version was deactivated, reads as unusable: the checkout field refuses rather than
			registering a domain nobody was charged for. The line's price is the live registrar quote, so the buyer
			pays exactly one year at cost.
		</td>
	</tr>
	<tr>
		<th>Sellable</th>
		<td>
			<?= smps_badge($domains['sellable'], 'Yes', 'No', 'warning') ?>
			— until both are set the checkout field refuses the order rather than selling a domain
			it cannot register. Queue and history: <a href="/admin/server_manager/domains">Domains</a>.
		</td>
	</tr>
</table>
<?php
$fw_domains = $page->getFormWriter('form_domains');
echo $fw_domains->begin_form();
echo '<input type="hidden" name="action" value="save_domains">';
$fw_domains->textinput('ncp_api_user', 'Namecheap username', ['value' => $domains['api_user'],
	'helptext' => 'The account the API calls are made as.']);
$fw_domains->passwordinput('ncp_api_key', 'Namecheap API key', [
	'helptext' => $domains['key_present']
		? 'A key is stored. Leave blank to keep it; enter a new one to replace it.'
		: 'From Profile, Tools, Namecheap API Access.']);
$fw_domains->textinput('ncp_client_ip', 'Allowlisted IP', ['value' => $domains['client_ip'],
	'helptext' => 'This server\'s public IPv4 address, added to the Whitelisted IPs list in the '
		. 'Namecheap API panel. IPv6 is not accepted there.']);
$fw_domains->textinput('domain_tlds', 'Offered endings', ['value' => $domains['tlds_raw'],
	'helptext' => 'Space-separated, without the dot. A name outside these is refused at checkout.']);
$fw_domains->checkboxinput('ncp_sandbox', 'Use the Namecheap sandbox',
	['checked' => $domains['sandbox'],
	 'helptext' => 'Point registrar calls at the sandbox for an end-to-end rehearsal.']);
$fw_domains->submitbutton('btn_save_domains', 'Save domain registrar settings');
echo $fw_domains->end_form();
?>

<hr>

<h4>9. Hosted tier</h4>
<p>The second hosting product: the server is created on <strong>this
operator's</strong> cloud account, its outbound mail goes through this
operator's SMTP2GO account, and its backups land on this fleet's shelf. The
buyer connects nothing and pastes nothing. Leave this card empty and the hosted
product simply cannot be fulfilled — the bring-your-own-cloud product is
unaffected.</p>
<table class="table table-sm svm-status-table">
	<tr>
		<th>Can fulfil a hosted order</th>
		<td>
			<?= smps_badge($hosted['ready'], 'Yes', 'No', 'warning') ?>
			— both the cloud token and the SMTP2GO key are needed. A hosted site without mail is a
			site whose owner cannot reset their own password.
		</td>
	</tr>
	<tr>
		<th>Allowances</th>
		<td>
			<?= (int)$hosted['send_allowance'] ?> sends a month and
			<?= (int)$hosted['shelf_allowance_gb'] ?> GB of backup shelf per customer.
			Exceeding one is an off-ramp to the customer's own account, never a bigger plan —
			the referral links below are what the site's banner offers, and only once the
			allowance is actually near.
		</td>
	</tr>
	<tr>
		<th>If a payment fails</th>
		<td>
			<?= (int)$hosted['grace_days'] ?> days of grace, then the instance is
			<em>shut down</em> and a deletion task is raised for a person — this platform never
			deletes a cloud instance itself. The backups are kept
			<?= (int)$hosted['shelf_days'] ?> days from the failed payment.
		</td>
	</tr>
</table>
<?php
$fw_hosted = $page->getFormWriter('form_hosted');
echo $fw_hosted->begin_form();
echo '<input type="hidden" name="action" value="save_hosted">';
$fw_hosted->passwordinput('operator_cloud_token', 'Operator cloud token', [
	'helptext' => ($hosted['token_present']
		? 'A token is stored. Leave blank to keep it; enter a new one to replace it.'
		: 'A Linode personal access token scoped linodes:read_write.')
		. ' It stays on this management node — no machine this plane creates ever receives it.']);
$fw_hosted->passwordinput('smtp2go_api_key', 'SMTP2GO master API key', [
	'helptext' => ($hosted['smtp2go_present']
		? 'A key is stored. Leave blank to keep it; enter a new one to replace it.'
		: 'The master key every customer subaccount is administered with.')
		. ' Only a per-customer SMTP user ever reaches a customer\'s box.']);
$fw_hosted->passwordinput('smtp2go_webhook_secret', 'SMTP2GO webhook secret', [
	'helptext' => ($hosted['webhook_present']
		? 'A secret is stored. Leave blank to keep it; enter a new one to replace it.'
		: 'Set the same value as the basic-auth password on the SMTP2GO webhook.')
		. ' SMTP2GO does not sign its webhooks, so a spoofed one can move a banner and never a cap.']);
$fw_hosted->textinput('send_allowance', 'Sends per month, per customer',
	['value' => $hosted['send_allowance'],
	 'helptext' => 'Set as the monthly limit on every customer subaccount. The provider counts and enforces it.']);
$fw_hosted->textinput('shelf_allowance_gb', 'Backup shelf per customer (GB)',
	['value' => $hosted['shelf_allowance_gb'],
	 'helptext' => 'Measured from the listing the retention pass already takes; runs stop at 100%.']);
$fw_hosted->textinput('trial_days', 'Free trial length (days, 0 for none)',
	['value' => $hosted['trial_days'],
	 'helptext' => 'Zero means hosting is billed from checkout and a new site opens as subscribed. Otherwise it must match the trial period on the product, and it is what a new site counts down to before its subscription has told us a date.']);
$fw_hosted->textinput('grace_days', 'Grace after a failed payment (days)',
	['value' => $hosted['grace_days']]);
$fw_hosted->textinput('shelf_days', 'Backup shelf kept after non-payment (days)',
	['value' => $hosted['shelf_days']]);
$fw_hosted->textinput('manage_url', 'Where customers manage their hosting',
	['value' => $hosted['manage_url'],
	 'helptext' => 'The https address of the buyer\'s sites page on the store, pushed to each hosted '
		. 'site as its Manage hosting link. Usually '
		. htmlspecialchars(($api['is_self'] ? '' : rtrim($api['url'], '/')) . '/profile/server_manager') . '.']);
$fw_hosted->textinput('smtp2go_referral_url', 'SMTP2GO referral URL',
	['value' => $hosted['smtp2go_referral_url'],
	 'helptext' => 'Offered to a customer whose send allowance is nearly gone.']);
$fw_hosted->textinput('storage_referral_url', 'Backup storage referral URL',
	['value' => $hosted['storage_referral_url'],
	 'helptext' => 'Offered to a customer whose backup shelf is nearly full.']);
$fw_hosted->submitbutton('btn_save_hosted', 'Save hosted tier settings');
echo $fw_hosted->end_form();
?>

<hr>

<h4>10. Products</h4>
<p>Per hosting product (product edit page): pick <em>Customer cloud server</em>
under Purchase grants — that is the entire setup, and the domain question is
asked automatically. The reference you pick beside it decides <strong>whose
account the server is born on</strong>:</p>
<ul>
	<li><strong>Create the server in the buyer's own cloud account</strong> — the buyer connects
		their own provider account and is billed by it directly. Put the Connect link
		(<code><?= htmlspecialchars($api['is_self'] ? '' : rtrim($api['url'], '/')) ?>/profile/server_manager/connect_cloud</code>)
		in the after-purchase message.</li>
	<li><strong>Create the server on the operator's account (hosted)</strong> — this card's
		settings apply. There is no Connect page: the buyer pays and the site appears. Give the
		product a subscription version with a trial period, and the setup fee as a one-time line
		in the same cart.</li>
</ul>
<p>For <strong>shared-host</strong> products, attach the domain question as a
requirement instead.</p>
<p>To sell the buyer their domain in the same click, also tick
<strong>Managed domain</strong> under <em>Info to collect before purchase</em>.
It works with either fulfillment mode, and replaces the domain question for
buyers who do not already own a name.</p>

<?php
$page->end_box();
$page->admin_footer();
?>
