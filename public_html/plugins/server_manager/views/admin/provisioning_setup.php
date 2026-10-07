<?php
/**
 * Server Manager - Provisioning Setup
 * URL: /admin/server_manager/provisioning_setup
 *
 * Guided activation of the hosting provisioning pipeline: every checklist
 * item shows its live state with a one-click action where the platform can
 * do the work itself.
 *
 * @version 1.10 - the test-account cleanup card (test_cloud_account_and_prod_management WP3)
 * @version 1.9 - the hosted card names the operator token's missing scopes (ProvisioningSetup::hostedStatus)
 * @version 1.8 - the operator token's helptext names ips:read_write, for a site copy's switch by IP swap
 * @version 1.7 - the operator token's helptext names the scopes a server handover needs
 * @version 1.6 - stored credentials are locked fields with Reset (passwordinput 'stored'); the promotion code's remove box is gone
 * @version 1.5 - the hosted card's master-key field is named hosted_smtp2go_master_key: smtp2go_api_key is the core email provider's declared setting, which FormWriter refuses to hand-draw
 * @version 1.4 - the registrar promotion code on the domain card; the domain question card serves
 *                shared-host products only and a customer-cloud site is configured on the buyer's
 *                configure page (specs/managed_hosting_phase1_purchase.md §8)
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
$cleanup = $page_vars['cleanup'];

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

<h4>3. Domain question (shared-host products)</h4>
<p>The Question a <strong>shared-host</strong> product asks at checkout for the buyer's domain, read by
the Orders poll from the store. A customer-cloud product does not use it: the buyer configures their
site — domain included — on the configure page before paying.</p>
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
				<span class="badge bg-warning">None attached</span>
				— attach it on a shared-host product's edit page. Customer-cloud
				products do not use it.
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
<p>With this configured, the configure page offers to register the buyer's domain name — they type
the name they want, see the live price, pay once with the hosting, and the pipeline registers it with
<strong>them</strong> as the legal owner, wires DNS to their box and turns on email. Leave it unset and
the page offers only "I own one already".</p>
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
		<th>Promotion code</th>
		<td>
			<?= smps_badge($domains['promotion_present'], 'Set', 'None', 'secondary') ?>
			— a registrar coupon. When set it rides every quote and every registration alike, so the
			buyer pays what the registrar charges; an ending the code does not cover is quoted and
			registered at the ordinary price.
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
	'stored' => $domains['key_present'],
	'helptext' => 'From Profile, Tools, Namecheap API Access.']);
$fw_domains->textinput('ncp_client_ip', 'Allowlisted IP', ['value' => $domains['client_ip'],
	'helptext' => 'This server\'s public IPv4 address, added to the Whitelisted IPs list in the '
		. 'Namecheap API panel. IPv6 is not accepted there.']);
$fw_domains->textinput('domain_tlds', 'Offered endings', ['value' => $domains['tlds_raw'],
	'helptext' => 'Space-separated, without the dot. A name outside these is refused at checkout.']);
$fw_domains->checkboxinput('ncp_sandbox', 'Use the Namecheap sandbox',
	['checked' => $domains['sandbox'],
	 'helptext' => 'Point registrar calls at the sandbox for an end-to-end rehearsal.']);
$fw_domains->passwordinput('ncp_promotion_code', 'Namecheap promotion code', [
	'stored' => $domains['promotion_present'],
	'helptext' => 'A registrar coupon, if the account holds one. Without one, quotes and '
		. 'registrations are at the ordinary price.']);
$fw_domains->submitbutton('btn_save_domains', 'Save domain registrar settings');
echo $fw_domains->end_form();
?>

<hr>

<h4>9. Hosted tier</h4>
<p>The second hosting product: the server is created on <strong>this
operator's</strong> cloud account, its outbound mail goes through this
operator's SMTP2GO account, and its backups land in this fleet's backup storage. The
buyer connects nothing and pastes nothing. Leave this card empty and the hosted
product simply cannot be fulfilled — the bring-your-own-cloud product is
unaffected.</p>
<table class="table table-sm svm-status-table">
	<tr>
		<th>Can fulfil a hosted order</th>
		<td>
			<?= smps_badge($hosted['ready'], 'Yes', 'No', 'warning') ?>
			— both the cloud token, able to create servers, and the SMTP2GO key are needed. A hosted site without mail is a
			site whose owner cannot reset their own password.
		</td>
	</tr>
	<?php if ($hosted['token_present']): ?>
	<tr>
		<th>Cloud token's scopes</th>
		<td>
			<?php if ($hosted['token_missing_scopes'] === null): ?>
				<?= smps_badge(false, '', 'Not checked', 'secondary') ?>
				— save this card to ask Linode what the token may do.
			<?php elseif (!$hosted['token_missing_scopes']): ?>
				<?= smps_badge(true, 'All present') ?>
			<?php else: ?>
				<?= smps_badge(false, '', 'Missing', 'warning') ?>
				— Linode will refuse these, and what needs them fails:
				<ul>
				<?php foreach ($hosted['token_missing_scopes'] as $scope => $purpose): ?>
					<li><code><?= htmlspecialchars($scope) ?></code>: <?= htmlspecialchars($purpose) ?></li>
				<?php endforeach; ?>
				</ul>
				Make a Linode token with these scopes added and enter it below.
			<?php endif; ?>
		</td>
	</tr>
	<?php endif; ?>
	<tr>
		<th>Allowances</th>
		<td>
			<?= (int)$hosted['send_allowance'] ?> sends a month and
			<?= (int)$hosted['shelf_allowance_gb'] ?> GB of backup storage per customer.
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
	'stored' => $hosted['token_present'],
	'helptext' => 'A Linode personal access token scoped linodes:read_write, account:read_write (the alert when the account\'s'
		. ' transfer allowance is nearly spent, and handing a server to its customer\'s own Linode account), firewall:read_only and volumes:read_only (the check before a handover),'
		. ' and ips:read_write (a site copy\'s switch-over by swapping two servers\' addresses).'
		. ' It stays on this management node — no machine this plane creates ever receives it.']);
$fw_hosted->passwordinput('hosted_smtp2go_master_key', 'SMTP2GO master API key', [
	'stored' => $hosted['smtp2go_present'],
	'helptext' => 'The master key every customer subaccount is administered with.'
		. ' Only a per-customer SMTP user ever reaches a customer\'s box.']);
$fw_hosted->checkboxinput('smtp2go_sandbox_users', 'Sandbox the SMTP users this plane mints',
	['checked' => $hosted['smtp2go_sandbox_users'],
	 'helptext' => 'Every customer SMTP user is created in SMTP2GO\'s sandbox status: accepted and counted, never '
		. 'delivered. For a rehearsal plane. Leave off where real customers are hosted.']);
$fw_hosted->passwordinput('smtp2go_webhook_secret', 'SMTP2GO webhook secret', [
	'stored' => $hosted['webhook_present'],
	'helptext' => 'Set the same value as the basic-auth password on the SMTP2GO webhook.'
		. ' SMTP2GO does not sign its webhooks, so a spoofed one can move a banner and never a cap.']);
$fw_hosted->textinput('send_allowance', 'Sends per month, per customer',
	['value' => $hosted['send_allowance'],
	 'helptext' => 'Set as the monthly limit on every customer subaccount. The provider counts and enforces it.']);
$fw_hosted->textinput('shelf_allowance_gb', 'Backup storage per customer (GB)',
	['value' => $hosted['shelf_allowance_gb'],
	 'helptext' => 'Measured from the listing the retention pass already takes; runs stop at 100%.']);
$fw_hosted->textinput('trial_days', 'Free trial length (days, 0 for none)',
	['value' => $hosted['trial_days'],
	 'helptext' => 'Zero means hosting is billed from checkout and a new site opens as subscribed. Otherwise it must match the trial period on the product, and it is what a new site counts down to before its subscription has told us a date.']);
$fw_hosted->textinput('grace_days', 'Grace after a failed payment (days)',
	['value' => $hosted['grace_days']]);
$fw_hosted->textinput('shelf_days', 'Backup storage kept after non-payment (days)',
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
	 'helptext' => 'Offered to a customer whose backup storage is nearly full.']);
$fw_hosted->submitbutton('btn_save_hosted', 'Save hosted tier settings');
echo $fw_hosted->end_form();
?>

<hr>

<h4>10. Products</h4>
<p>Per hosting product (product edit page): pick <em>Customer cloud server</em>
under Purchase grants — that is the entire setup. The site itself is configured
by the buyer on <code><?= htmlspecialchars($api['is_self'] ? '' : rtrim($api['url'], '/')) ?>/profile/server_manager/configure</code>
— the domain (their own, or one registered here), the site name, the admin
email — before they pay; the cart carries only the id of that configured site,
and nothing is attached to the product by hand. The reference you pick decides
<strong>whose account the server is born on</strong>:</p>
<ul>
	<li><strong>Create the server in the buyer's own cloud account</strong> — the buyer connects
		their own provider account and is billed by it directly. Put the Connect link
		(<code><?= htmlspecialchars($api['is_self'] ? '' : rtrim($api['url'], '/')) ?>/profile/server_manager/connect_cloud</code>)
		in the after-purchase message.</li>
	<li><strong>Create the server on the operator's account (hosted)</strong> — this card's
		settings apply. There is no Connect page: the buyer pays and the site appears. Give the
		product one subscription version; the domain year, when registered here, is its own
		one-time line in the same cart.</li>
</ul>
<p>For <strong>shared-host</strong> products, attach the domain question as a
requirement instead.</p>

<hr>

<h4>11. Test-account cleanup</h4>
<p>Only for a site whose operator token is for a cloud account kept for testing. Every hour the
<strong>Delete Old Test Servers</strong> task deletes servers and unattached volumes older than
<?= (int)$cleanup['max_age_hours'] ?> hours (setting <code><?= htmlspecialchars(TestCloudCleanup::MAX_AGE_SETTING) ?></code>).
It runs only when the account's company name, in the provider's account settings, is exactly
<code><?= htmlspecialchars(TestCloudCleanup::COMPANY) ?></code>; on any other account it deletes nothing and opens an
incident. Tag a server or volume <code><?= htmlspecialchars(TestCloudCleanup::KEEP_TAG) ?></code> at the provider to
spare it. A server at a managed node's address is never deleted.</p>
<table class="table table-sm svm-status-table">
	<tr>
		<th>Task</th>
		<td>
			<?php if ($cleanup['task'] && $cleanup['task']['active']): ?>
				<?= smps_badge(true, 'On') ?>
			<?php else: ?>
				<?= smps_badge(false, '', 'Off', 'secondary') ?> — after a preview below, turn on Delete Old Test Servers under
				<a href="/admin/admin_scheduled_tasks">Scheduled Tasks</a>.
			<?php endif; ?>
		</td>
	</tr>
	<?php if ($cleanup['missing_scopes']): ?>
	<tr>
		<th>Cloud token's scopes</th>
		<td>
			<?= smps_badge(false, '', 'Missing', 'warning') ?> — a run needs these as well:
			<ul>
			<?php foreach ($cleanup['missing_scopes'] as $scope => $purpose): ?>
				<li><code><?= htmlspecialchars($scope) ?></code>: <?= htmlspecialchars($purpose) ?></li>
			<?php endforeach; ?>
			</ul>
		</td>
	</tr>
	<?php endif; ?>
	<tr>
		<th>Last run</th>
		<td>
			<?php $cs = $cleanup['state']; ?>
			<?php if (!$cs): ?>
				None yet.
			<?php elseif ($cs['outcome'] === 'ran'): ?>
				<?= htmlspecialchars($cs['time']) ?> UTC on <strong><?= htmlspecialchars($cs['account']) ?></strong>:
				deleted <?= count($cs['deleted_instances']) ?> server(s)<?= $cs['deleted_instances'] ? ' (' . htmlspecialchars(implode(', ', $cs['deleted_instances'])) . ')' : '' ?>
				and <?= count($cs['deleted_volumes']) ?> volume(s)<?= $cs['deleted_volumes'] ? ' (' . htmlspecialchars(implode(', ', $cs['deleted_volumes'])) . ')' : '' ?>;
				<?= (int)$cs['kept'] ?> kept by tag.
				<?php if ($cs['held']): ?><br>Held, at a managed node's address: <?= htmlspecialchars(implode('; ', $cs['held'])) ?>.<?php endif; ?>
				<?php if ($cs['failures']): ?><br><span class="text-danger">Failed: <?= htmlspecialchars(implode('; ', $cs['failures'])) ?></span><?php endif; ?>
			<?php else: ?>
				<?= smps_badge(false, '', $cs['outcome'] === 'refused' ? 'Refused' : 'Failed', 'danger') ?>
				<?= htmlspecialchars($cs['time']) ?> UTC — nothing deleted. <?= htmlspecialchars($cs['reason']) ?>
			<?php endif; ?>
		</td>
	</tr>
</table>
<?php $cp = $cleanup['preview']; ?>
<?php if ($cp === null): ?>
	<p><a class="btn btn-sm btn-outline-secondary" href="/admin/server_manager/provisioning_setup?cleanup_preview=1#cleanup">Preview the next run</a>
	— asks the provider; deletes nothing.</p>
<?php else: ?>
	<div id="cleanup" class="mb-3">
	<?php if (isset($cp['error'])): ?>
		<?= smps_badge(false, '', 'Could not preview', 'danger') ?> <?= htmlspecialchars($cp['error']) ?>
	<?php elseif (!$cp['safe']): ?>
		<?= smps_badge(false, '', 'Safety catch fails', 'danger') ?> <?= htmlspecialchars($cp['reason']) ?>
	<?php else: ?>
		<p><?= smps_badge(true, 'Safety catch passes') ?> Account <strong><?= htmlspecialchars($cp['account']) ?></strong>.
		The next run would delete <?= count($cp['instances']) ?> server(s) and <?= count($cp['volumes']) ?> volume(s);
		<?= (int)$cp['kept'] ?> past the age are kept by tag.</p>
		<?php if ($cp['instances'] || $cp['volumes'] || $cp['held']): ?>
		<table class="table table-sm">
			<thead><tr><th>Would</th><th>What</th><th>Label</th><th>Age</th><th>Addresses</th></tr></thead>
			<tbody>
			<?php foreach ($cp['instances'] as $i): ?>
				<tr><td>Delete</td><td>Server <?= htmlspecialchars($i['id']) ?></td><td><?= htmlspecialchars($i['label']) ?></td>
					<td><?= (int)$i['age_hours'] ?> h</td><td><?= htmlspecialchars(implode(', ', $i['addresses'])) ?></td></tr>
			<?php endforeach; ?>
			<?php foreach ($cp['volumes'] as $v): ?>
				<tr><td>Delete</td><td>Volume <?= htmlspecialchars($v['id']) ?></td><td><?= htmlspecialchars($v['label']) ?></td>
					<td><?= (int)$v['age_hours'] ?> h</td><td>unattached</td></tr>
			<?php endforeach; ?>
			<?php foreach ($cp['held'] as $h): ?>
				<tr><td>Hold</td><td>Server <?= htmlspecialchars($h['id']) ?></td><td><?= htmlspecialchars($h['label']) ?></td>
					<td><?= (int)$h['age_hours'] ?> h</td><td><?= htmlspecialchars(implode(', ', $h['addresses'])) ?> — node #<?= htmlspecialchars(implode(', #', $h['node_ids'])) ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php if ($cp['volume_error'] !== ''): ?>
			<p class="text-danger">Volumes could not be listed: <?= htmlspecialchars($cp['volume_error']) ?></p>
		<?php endif; ?>
	<?php endif; ?>
	</div>
<?php endif; ?>

<?php
$page->end_box();
$page->admin_footer();
?>
