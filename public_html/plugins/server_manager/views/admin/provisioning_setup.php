<?php
/**
 * Server Manager - Provisioning Setup
 * URL: /admin/server_manager/provisioning_setup?tab=setup|hosting|hosted|shop|domains|cleanup
 *
 * Guided activation of the hosting provisioning pipeline: every checklist
 * item shows its live state with a one-click action where the platform can
 * do the work itself. One subject per tab:
 *   Setup        the job agent, the store API connection, scheduled tasks, emails
 *   Hosting      the cloud accounts servers are created in (ours, added by API key
 *                or a Linode sign-in, one the default; customers', read-only), the
 *                Linode sign-in app, and the defaults for a new server
 *   Hosted tier  allowances, trial, grace, SMTP2GO, referral links
 *   Shop         the domain question, shared-host fulfillment, products
 *   Domains      domain registration
 *   Cleanup      the test-account cleanup
 *
 * @version 2.5 - API not responding says why (the HTTP status and the API's own error, or the connection's) (B21)
 * @version 2.4 - The Cleanup tab lists the address records a run removed with its servers (fleet_security_patching WP10)
 * @version 2.3 - Add an account asks its provider, and a Hetzner project's name (specs/site_copy_anything_to_anything.md D2)
 * @version 2.2 - Remove names the encrypted servers it drops to Tier 1 (B77 N1)
 * @version 2.1 - an API key's expiry on its account's row, and Expires soon within a week of it (B77)
 * @version 2.0 - tabs; the Hosting tab and its cloud accounts list, with both ways to add one; the hosted card names
 *                the default account instead of taking a token; the cleanup card covers every test account
 *                (cloud_accounts_list WP4)
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
$tab = $page_vars['tab'];

$api = $status['api'];
$question = $status['question'];
$email = $status['email'];
$tasks = $status['tasks'];
$cloud = $status['cloud'];
$shared = $status['shared_hosts'];
$agent = $status['agent'];
$domains = $status['domains'];
$hosted = $status['hosted'];
$accounts = $page_vars['accounts'];
$cleanup = $page_vars['cleanup'];

$base_url = '/admin/server_manager/provisioning_setup';
$store_origin = $api['is_self'] ? '' : rtrim($api['url'], '/');

function smps_badge(bool $ok, string $ok_label = 'OK', string $bad_label = 'Missing', string $bad_color = 'warning'): string {
	return $ok
		? '<span class="badge bg-success">' . htmlspecialchars($ok_label) . '</span>'
		: '<span class="badge bg-' . $bad_color . '">' . htmlspecialchars($bad_label) . '</span>';
}

/** A single-button POST on the Hosting tab: an account action with the page's CSRF token. */
function smps_account_button(string $action, int $oca_id, string $label, string $class = 'btn btn-sm btn-outline-secondary', string $confirm = ''): string {
	return '<form method="post" action="/admin/server_manager/provisioning_setup?tab=hosting" style="display:inline"'
		. ($confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ');"' : '') . '>'
		. '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
		. ($oca_id ? '<input type="hidden" name="oca_id" value="' . $oca_id . '">' : '')
		. SmAdminCsrf::field()
		. '<button type="submit" class="' . htmlspecialchars($class) . '">' . htmlspecialchars($label) . '</button></form>';
}

/** The scopes a credential lacks, as a list saying what each is for. */
function smps_missing_scopes(array $missing): string {
	$out = '<ul class="mb-0">';
	foreach ($missing as $scope => $purpose) {
		$out .= '<li><code>' . htmlspecialchars($scope) . '</code>: ' . htmlspecialchars($purpose) . '</li>';
	}
	return $out . '</ul>';
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

$tab_links = array();
foreach ($page_vars['tabs'] as $key => $label) {
	$tab_links[$label] = $base_url . '?tab=' . $key;
}
echo $page->tab_menu($tab_links, $page_vars['tabs'][$tab]);

$page->begin_box(array());

if ($tab === 'setup'):
?>

<h4>Job agent</h4>
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

<h4>Store API connection</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Credentials</th>
		<td>
			<?= smps_badge($api['configured'], 'Configured', 'Not configured') ?>
			<?php if ($api['configured']): ?>
				&nbsp; <?= smps_badge($api['probe_ok'], 'API responding', 'API not responding', 'danger') ?>
				<?php if (!$api['probe_ok']): ?><div class="small text-danger mt-1"><?= htmlspecialchars($api['probe_problem']) ?></div><?php endif; ?>
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
	<form method="post" action="<?= $base_url ?>?tab=setup">
		<input type="hidden" name="action" value="setup_api">
		<button type="submit" class="btn btn-primary">Create service user + API key</button>
	</form>
<?php else: ?>
	<form method="post" action="<?= $base_url ?>?tab=setup" onsubmit="return confirm('Mint a new API key and retire the current one?');">
		<input type="hidden" name="action" value="setup_api">
		<input type="hidden" name="rotate" value="1">
		<button type="submit" class="btn btn-outline-secondary btn-sm">Rotate API key</button>
	</form>
<?php endif; ?>

<hr>

<h4>Scheduled tasks</h4>
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
	<form method="post" action="<?= $base_url ?>?tab=setup">
		<input type="hidden" name="action" value="activate_tasks">
		<button type="submit" class="btn btn-primary">Activate provisioning tasks</button>
	</form>
<?php endif; ?>

<hr>

<h4>Emails</h4>
<?php
$fw_email = $page->getFormWriter('form_email', ['action' => $base_url . '?tab=setup']);
echo $fw_email->begin_form();
echo '<input type="hidden" name="action" value="save_email">';
$fw_email->textinput('welcome_from_email', 'Welcome email from address', ['value' => $email['welcome_from_email']]);
$fw_email->textinput('welcome_from_name', 'Welcome email from name', ['value' => $email['welcome_from_name']]);
$fw_email->textinput('admin_alert_email', 'Admin alert address', ['value' => $email['admin_alert_email']]);
$fw_email->submitbutton('btn_save_email', 'Save email settings');
echo $fw_email->end_form();
?>

<?php elseif ($tab === 'hosting'): ?>

<h4>Our cloud accounts</h4>
<p>The accounts of ours this management node creates and manages servers in. Every server remembers the
account it is in, and everything done to it later (removing it, its reverse DNS, a handover, an address
swap) uses that account's credential. The <strong>default</strong> is where a server goes when nobody
chooses: a hosted order. The forms that create a server (a site's Copy tab, Install a new node) list
every account, the default chosen.</p>

<?php if (!$accounts['ours']): ?>
	<p><?= smps_badge(false, '', 'None', 'warning') ?> No cloud account of ours is held here. Add one below.</p>
<?php else: ?>
<table class="table table-sm svm-status-table">
	<thead>
		<tr><th>Account</th><th>Added by</th><th>State</th><th>Servers</th><th></th></tr>
	</thead>
	<tbody>
	<?php foreach ($accounts['ours'] as $row):
		$oca = $row['account'];
		$is_connect = (string)$oca->get('oca_method') === OperatorCloudAccount::METHOD_CONNECT; ?>
		<tr>
			<td>
				<strong><?= htmlspecialchars($oca->label()) ?></strong>
				<?php if ($oca->is_default()): ?><span class="badge bg-success">Default</span><?php endif; ?>
				<?php if ($oca->get('oca_is_test')): ?><span class="badge bg-secondary">Test</span><?php endif; ?>
				<?php if (trim((string)$oca->get('oca_company')) !== ''): ?>
					<br><span class="text-muted small">Company: <?= htmlspecialchars((string)$oca->get('oca_company')) ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?= $is_connect ? 'Linode sign-in' : 'API key' ?>
				<br><span class="text-muted small"><?= htmlspecialchars($oca->get_local('oca_create_time', 'M j, Y')) ?></span>
			</td>
			<td>
				<?php if ($row['problem'] !== ''): ?>
					<?= smps_badge(false, '', $oca->expired() ? 'Expired' : 'Not usable', $oca->expired() ? 'secondary' : 'danger') ?>
					<span class="small"><?= htmlspecialchars($row['problem']) ?></span>
				<?php else: ?>
					<?= smps_badge(true, 'Usable') ?>
					<?php if ($is_connect): ?>
						<span class="small">until <?= htmlspecialchars($oca->get_local('oca_token_expires', 'M j, g:i a T')) ?>.
						A sign-in lasts two hours and cannot be renewed: right for one server in somebody's account,
						wrong for an account this node keeps managing.</span>
					<?php elseif ($oca->key_expires_within(OperatorCloudAccount::KEY_EXPIRY_WARNING_SECONDS) !== null): ?>
						<br><?= smps_badge(false, '', 'Expires soon', 'warning') ?>
						<span class="small">The key expires <?= htmlspecialchars($oca->get_local('oca_token_expires', 'M j, g:i a T')) ?>.
						Replace it before then: once it stops working, nothing here reaches the account, and an encrypted
						server in it that reboots is not opened until it is replaced.</span>
					<?php elseif (trim((string)$oca->get('oca_token_expires')) !== ''): ?>
						<br><span class="text-muted small">The key expires <?= htmlspecialchars($oca->get_local('oca_token_expires', 'M j, Y')) ?>.</span>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ($row['problem'] === '' && $row['missing_scopes'] === null): ?>
					<br><?= smps_badge(false, '', 'Scopes not checked', 'secondary') ?> Check it to ask the provider.
				<?php elseif ($row['problem'] === '' && $row['missing_scopes']): ?>
					<br><?= smps_badge(false, '', 'Missing scopes', 'warning') ?> The provider will refuse these, and what needs them fails:
					<?= smps_missing_scopes($row['missing_scopes']) ?>
				<?php endif; ?>
				<?php if (trim((string)$oca->get('oca_checked_time')) !== ''): ?>
					<br><span class="text-muted small">Checked <?= htmlspecialchars($oca->get_local('oca_checked_time', 'M j, g:i a T')) ?></span>
				<?php endif; ?>
			</td>
			<td><?= (int)$row['servers'] ?></td>
			<td style="white-space:nowrap">
				<?php if (!$oca->is_default()): ?>
					<?php if ($row['default_refusal'] === ''): ?>
						<?= smps_account_button('default_account', (int)$oca->key, 'Make default') ?>
					<?php endif; ?>
				<?php endif; ?>
				<?php if (!$oca->expired()): ?>
					<?= smps_account_button('check_account', (int)$oca->key, 'Check') ?>
				<?php endif; ?>
				<?php if ($is_connect): ?>
					<?= smps_account_button('reconnect_account', (int)$oca->key, 'Reconnect') ?>
				<?php endif; ?>
				<?php if (!$oca->is_default()): ?>
					<?= smps_account_button('remove_account', (int)$oca->key, 'Remove', 'btn btn-sm btn-outline-danger',
						'Remove ' . $oca->label() . '? Its credential is erased. '
						. ((int)$row['servers'] ? $row['servers'] . ' server(s) in it will no longer be reached from here.' : '')
						. ($row['tier2'] ? ' Its encrypted server(s) ' . implode(', ', $row['tier2']) . ' are opened here only after their activity log at the provider is read (Tier 2); without the account they are opened without that check (Tier 1).' : '')) ?>
				<?php endif; ?>
				<?php if (!$is_connect): ?>
					<details class="mt-1"><summary class="small">Replace key</summary>
					<?php
					$fw_replace = $page->getFormWriter('form_replace_' . (int)$oca->key, ['action' => $base_url . '?tab=hosting']);
					echo $fw_replace->begin_form();
					echo '<input type="hidden" name="action" value="replace_account_key">';
					echo '<input type="hidden" name="oca_id" value="' . (int)$oca->key . '">';
					echo SmAdminCsrf::field();
					$fw_replace->passwordinput('cloud_api_token', 'New API token for this account', [
						'helptext' => 'A token for the same account; servers in it keep pointing here.']);
					$fw_replace->submitbutton('btn_replace_' . (int)$oca->key, 'Replace key', ['class' => 'btn btn-sm btn-outline-primary']);
					echo $fw_replace->end_form();
					?>
					</details>
				<?php endif; ?>
			</td>
		</tr>
		<?php if (!$oca->is_default() && $row['default_refusal'] !== '' && $row['problem'] === ''): ?>
		<tr><td colspan="5" class="small text-muted">Cannot be the default: <?= htmlspecialchars($row['default_refusal']) ?></td></tr>
		<?php endif; ?>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<h5 class="mt-4">Add an account</h5>
<p>Two ways, each its own entry in the list above:</p>
<div class="row">
	<div class="col-md-6">
		<p><strong>By API key</strong> — for an account this node keeps managing. The key is sealed here and
		never shown again; it never reaches a machine this node creates.</p>
		<?php
		$fw_key = $page->getFormWriter('form_add_key', ['action' => $base_url . '?tab=hosting']);
		echo $fw_key->begin_form();
		echo '<input type="hidden" name="action" value="add_account_key">';
		echo SmAdminCsrf::field();
		$smps_providers = ComputeDriverRegistry::providers();
		$fw_key->dropinput('cloud_provider', 'Provider', ['options' => $smps_providers, 'value' => 'linode',
			'visibility_rules' => ['hetzner' => ['show' => ['cloud_account_name']], 'linode' => ['hide' => ['cloud_account_name']]]]);
		$fw_key->textinput('cloud_account_name', 'Project name', [
			'helptext' => 'Hetzner\'s API does not say which project a token is for: name it as you know it in the Hetzner Cloud Console.']);
		$fw_key->passwordinput('cloud_api_token', 'API token', [
			'helptext' => 'Linode: a personal access token with ' . implode(', ', array_keys(OperatorCloudAccount::SCOPES))
				. '; the provider is asked for the account\'s name and company and what the token may do before anything is saved. '
				. 'Hetzner: a Read & Write API token of one Hetzner Cloud project (Security, API tokens); it is tried before anything is saved.']);
		$fw_key->submitbutton('btn_add_key', 'Add account');
		echo $fw_key->end_form();
		?>
	</div>
	<div class="col-md-6">
		<p><strong>Connect with Linode</strong> — sign in to the account at Linode instead of making a key.
		The permission lasts <strong>two hours</strong> and cannot be renewed, so it suits a one-off: a copy into
		somebody's own account. It is never the default, and once it expires it is offered nowhere.</p>
		<?php if ($cloud['oauth_configured']): ?>
			<?= smps_account_button('connect_account', 0, 'Connect with Linode', 'btn btn-outline-primary') ?>
		<?php else: ?>
			<?= smps_badge(false, '', 'Not available', 'secondary') ?> The Linode sign-in app is not configured (below).
		<?php endif; ?>
	</div>
</div>

<hr>

<h4>Customers' connected accounts</h4>
<p>Accounts customers connected from their own profile
(<code><?= htmlspecialchars($store_origin) ?>/profile/server_manager/connect_cloud</code>), for a server in their
own account. They belong to the customer, and only the customer reconnects one. A copy or a new node can be created in
one while its two-hour permission lasts.</p>
<?php if (!$accounts['customers']): ?>
	<p class="text-muted">None.</p>
<?php else: ?>
<table class="table table-sm">
	<thead><tr><th>Account</th><th>Connected by</th><th>Last connected</th><th>State</th></tr></thead>
	<tbody>
	<?php foreach ($accounts['customers'] as $c): ?>
		<tr>
			<td><?= htmlspecialchars($c['provider'] . ' — ' . ($c['name'] !== '' ? $c['name'] : 'account name not read')) ?></td>
			<td><a href="/admin/admin_user?usr_user_id=<?= (int)$c['user_id'] ?>"><?= htmlspecialchars($c['who']) ?></a></td>
			<td><?= htmlspecialchars($c['connected']) ?></td>
			<td><?= htmlspecialchars($c['state']) ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<hr>

<h4>Linode sign-in app</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Linode OAuth app</th>
		<td>
			<?= smps_badge($cloud['oauth_configured'], 'Configured', 'Not configured') ?>
			— credentials at <a href="/admin/admin_oauth_providers">OAuth Providers</a>. Both Connect with Linode
			above and a customer's own connection use it.
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

<hr>

<h4>A new server's defaults</h4>
<?php
$fw_cloud = $page->getFormWriter('form_cloud', ['action' => $base_url . '?tab=hosting']);
echo $fw_cloud->begin_form();
echo '<input type="hidden" name="action" value="save_cloud">';
$fw_cloud->textinput('region', 'Default region', ['value' => $cloud['region']]);
$fw_cloud->textinput('instance_type', 'Default instance type', ['value' => $cloud['type']]);
$fw_cloud->textinput('image', 'OS image', ['value' => $cloud['image']]);
$fw_cloud->textinput('referral_url', 'Linode referral URL', ['value' => $cloud['referral_url'],
	'helptext' => 'Offered to a buyer who has no Linode account yet, on their Connect page.']);
$fw_cloud->submitbutton('btn_save_cloud', 'Save server defaults');
echo $fw_cloud->end_form();
?>

<?php elseif ($tab === 'hosted'): ?>

<h4>Hosted tier</h4>
<p>The second hosting product: the server is created in <strong>this operator's</strong> default cloud
account, its outbound mail goes through this operator's SMTP2GO account, and its backups land in this
fleet's backup storage. The buyer connects nothing and pastes nothing. Leave this tab empty and the hosted
product simply cannot be fulfilled — the bring-your-own-cloud product is unaffected.</p>
<table class="table table-sm svm-status-table">
	<tr>
		<th>Can fulfil a hosted order</th>
		<td>
			<?= smps_badge($hosted['ready'], 'Yes', 'No', 'warning') ?>
			— a default cloud account able to create servers, and the SMTP2GO key, are both needed. A hosted site
			without mail is a site whose owner cannot reset their own password.
		</td>
	</tr>
	<tr>
		<th>Where hosted servers go</th>
		<td>
			<?php if ($hosted['account_label'] === ''): ?>
				<?= smps_badge(false, '', 'No default account', 'warning') ?>
				— add a cloud account and make it the default on the <a href="<?= $base_url ?>?tab=hosting">Hosting</a> tab.
			<?php elseif (!$hosted['account_usable']): ?>
				<?= smps_badge(false, '', 'Not usable', 'danger') ?> <?= htmlspecialchars($hosted['account_label']) ?>:
				<?= htmlspecialchars($hosted['account_problem']) ?>
			<?php else: ?>
				<?= htmlspecialchars($hosted['account_label']) ?>, the default (<a href="<?= $base_url ?>?tab=hosting">Hosting</a> tab).
				<?php if ($hosted['account_missing_scopes'] === null): ?>
					<br><?= smps_badge(false, '', 'Scopes not checked', 'secondary') ?> Check it on the Hosting tab.
				<?php elseif ($hosted['account_missing_scopes']): ?>
					<br><?= smps_badge(false, '', 'Missing scopes', 'warning') ?> The provider will refuse these, and what needs them fails:
					<?= smps_missing_scopes($hosted['account_missing_scopes']) ?>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	</tr>
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
$fw_hosted = $page->getFormWriter('form_hosted', ['action' => $base_url . '?tab=hosted']);
echo $fw_hosted->begin_form();
echo '<input type="hidden" name="action" value="save_hosted">';
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
		. htmlspecialchars($store_origin . '/profile/server_manager') . '.']);
$fw_hosted->textinput('smtp2go_referral_url', 'SMTP2GO referral URL',
	['value' => $hosted['smtp2go_referral_url'],
	 'helptext' => 'Offered to a customer whose send allowance is nearly gone.']);
$fw_hosted->textinput('storage_referral_url', 'Backup storage referral URL',
	['value' => $hosted['storage_referral_url'],
	 'helptext' => 'Offered to a customer whose backup storage is nearly full.']);
$fw_hosted->submitbutton('btn_save_hosted', 'Save hosted tier settings');
echo $fw_hosted->end_form();
?>

<?php elseif ($tab === 'shop'): ?>

<h4>Domain question (shared-host products)</h4>
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
	<form method="post" action="<?= $base_url ?>?tab=shop">
		<input type="hidden" name="action" value="create_question">
		<button type="submit" class="btn btn-primary">Create domain question</button>
	</form>
<?php endif; ?>

<hr>

<h4>Shared-host fulfillment</h4>
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

<h4>Products</h4>
<p>Per hosting product (product edit page): pick <em>Customer cloud server</em>
under Purchase grants — that is the entire setup. The site itself is configured
by the buyer on <code><?= htmlspecialchars($store_origin) ?>/profile/server_manager/configure</code>
— the domain (their own, or one registered here), the site name, the admin
email — before they pay; the cart carries only the id of that configured site,
and nothing is attached to the product by hand. The reference you pick decides
<strong>whose account the server is born on</strong>:</p>
<ul>
	<li><strong>Create the server in the buyer's own cloud account</strong> — the buyer connects
		their own provider account and is billed by it directly. Put the Connect link
		(<code><?= htmlspecialchars($store_origin) ?>/profile/server_manager/connect_cloud</code>)
		in the after-purchase message.</li>
	<li><strong>Create the server on the operator's account (hosted)</strong> — the
		<a href="<?= $base_url ?>?tab=hosted">Hosted tier</a> settings apply, and the server goes in the default
		cloud account. There is no Connect page: the buyer pays and the site appears. Give the
		product one subscription version; the domain year, when registered here, is its own
		one-time line in the same cart.</li>
</ul>
<p>For <strong>shared-host</strong> products, attach the domain question as a
requirement instead.</p>

<?php elseif ($tab === 'domains'): ?>

<h4>Domain registration</h4>
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
$fw_domains = $page->getFormWriter('form_domains', ['action' => $base_url . '?tab=domains']);
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

<?php elseif ($tab === 'cleanup'): ?>

<h4>Test-account cleanup</h4>
<p>For the cloud accounts of ours flagged <strong>test</strong> on the <a href="<?= $base_url ?>?tab=hosting">Hosting</a>
tab. Every hour the <strong>Delete Old Test Servers</strong> task deletes servers and unattached volumes older than
<?= (int)$cleanup['max_age_hours'] ?> hours (setting <code><?= htmlspecialchars(TestCloudCleanup::MAX_AGE_SETTING) ?></code>)
in each of them, one account at a time. An account is cleaned only when its company name, in the provider's account
settings, is exactly <code><?= htmlspecialchars(TestCloudCleanup::COMPANY) ?></code>, read again on every run; on any
other it deletes nothing and opens an incident. Tag a server or volume <code><?= htmlspecialchars(TestCloudCleanup::KEEP_TAG) ?></code>
at the provider to spare it. A server at a managed node's address is never deleted.</p>
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
	<tr>
		<th>Test accounts</th>
		<td>
			<?php if (!$cleanup['accounts']): ?>
				None held here, so a run deletes nothing.
			<?php else: ?>
				<?php foreach ($cleanup['accounts'] as $ta): ?>
					<div><strong><?= htmlspecialchars($ta['name']) ?></strong>
					<?php if ($ta['missing_scopes'] === null): ?>
						— <?= smps_badge(false, '', 'Scopes not checked', 'secondary') ?>
					<?php elseif ($ta['missing_scopes']): ?>
						— <?= smps_badge(false, '', 'Missing', 'warning') ?> a run needs these as well:
						<?= smps_missing_scopes($ta['missing_scopes']) ?>
					<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th>Last run</th>
		<td>
			<?php if (!$cleanup['state']): ?>
				None yet.
			<?php endif; ?>
			<?php foreach ($cleanup['state'] as $cs): ?>
				<div class="mb-1">
				<?php if (($cs['name'] ?? '') !== ''): ?><strong><?= htmlspecialchars($cs['name']) ?></strong>: <?php endif; ?>
				<?php if ($cs['outcome'] === 'ran'): ?>
					<?= htmlspecialchars($cs['time']) ?> UTC, company <strong><?= htmlspecialchars($cs['account']) ?></strong>:
					deleted <?= count($cs['deleted_instances']) ?> server(s)<?= $cs['deleted_instances'] ? ' (' . htmlspecialchars(implode(', ', $cs['deleted_instances'])) . ')' : '' ?>
					and <?= count($cs['deleted_volumes']) ?> volume(s)<?= $cs['deleted_volumes'] ? ' (' . htmlspecialchars(implode(', ', $cs['deleted_volumes'])) . ')' : '' ?><?= !empty($cs['deleted_records']) ? ', and their address records (' . htmlspecialchars(implode(', ', $cs['deleted_records'])) . ')' : '' ?>;
					<?= (int)$cs['kept'] ?> kept by tag.
					<?php if ($cs['held']): ?><br>Held, at a managed node's address: <?= htmlspecialchars(implode('; ', $cs['held'])) ?>.<?php endif; ?>
					<?php if ($cs['failures']): ?><br><span class="text-danger">Failed: <?= htmlspecialchars(implode('; ', $cs['failures'])) ?></span><?php endif; ?>
				<?php else: ?>
					<?= smps_badge(false, '', $cs['outcome'] === 'refused' ? 'Refused' : 'Failed', 'danger') ?>
					<?= htmlspecialchars($cs['time']) ?> UTC — nothing deleted. <?= htmlspecialchars($cs['reason']) ?>
				<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</td>
	</tr>
</table>
<?php if ($cleanup['preview'] === null): ?>
	<p><a class="btn btn-sm btn-outline-secondary" href="<?= $base_url ?>?tab=cleanup&amp;cleanup_preview=1#cleanup">Preview the next run</a>
	— asks the provider; deletes nothing.</p>
<?php else: ?>
	<div id="cleanup" class="mb-3">
	<?php if (!$cleanup['preview']): ?>
		<p>No test account is held here, so the next run deletes nothing.</p>
	<?php endif; ?>
	<?php foreach ($cleanup['preview'] as $cp): ?>
		<h5><?= htmlspecialchars($cp['name']) ?></h5>
		<?php if (isset($cp['error'])): ?>
			<p><?= smps_badge(false, '', 'Could not preview', 'danger') ?> <?= htmlspecialchars($cp['error']) ?></p>
		<?php elseif (!$cp['safe']): ?>
			<p><?= smps_badge(false, '', 'Safety catch fails', 'danger') ?> <?= htmlspecialchars($cp['reason']) ?></p>
		<?php else: ?>
			<p><?= smps_badge(true, 'Safety catch passes') ?> Company <strong><?= htmlspecialchars($cp['account']) ?></strong>.
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
	<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php endif; ?>

<?php
$page->end_box();
$page->admin_footer();
?>
