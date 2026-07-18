<?php
/**
 * Server Manager - Provisioning Setup
 * URL: /admin/server_manager/provisioning_setup
 *
 * Guided activation of the hosting provisioning pipeline: every checklist
 * item shows its live state with a one-click action where the platform can
 * do the work itself.
 *
 * @version 1.0
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

<h4>1. Store API connection</h4>
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

<h4>2. Domain question</h4>
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
				<span class="badge bg-warning">None</span>
				— attach it as a requirement on each hosting product (product edit &rarr; Requirements).
				A product with this question attached is what makes an order a hosting order.
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

<h4>3. Emails</h4>
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

<h4>4. Scheduled tasks</h4>
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

<h4>5. Shared-host fulfillment</h4>
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

<h4>6. Customer-cloud fulfillment</h4>
<table class="table table-sm">
	<tr>
		<th style="width:260px">Linode OAuth app</th>
		<td>
			<?= smps_badge($cloud['oauth_configured'], 'Configured', 'Not configured') ?>
			— credentials at <a href="/admin/admin_oauth_providers">OAuth Providers</a>.
		</td>
	</tr>
	<tr>
		<th>Provisioning SSH key</th>
		<td>
			<?php if ($cloud['ssh_key_path'] === ''): ?>
				<span class="badge bg-warning">Not set</span>
			<?php else: ?>
				<?= smps_badge($cloud['ssh_key_exists'], 'Key found', 'Key file missing', 'danger') ?>
				<?= smps_badge($cloud['ssh_pub_exists'], '.pub found', '.pub missing', 'danger') ?>
				<code><?= htmlspecialchars($cloud['ssh_key_path']) ?></code>
			<?php endif; ?>
		</td>
	</tr>
</table>
<?php if ($cloud['ssh_key_path'] === '' || !$cloud['ssh_key_exists'] || !$cloud['ssh_pub_exists']): ?>
	<form method="post">
		<input type="hidden" name="action" value="generate_ssh_key">
		<button type="submit" class="btn btn-primary">Generate provisioning key</button>
	</form>
<?php endif; ?>
<?php
$fw_cloud = $page->getFormWriter('form_cloud');
echo $fw_cloud->begin_form();
echo '<input type="hidden" name="action" value="save_cloud">';
$fw_cloud->textinput('ssh_key_path', 'SSH private key path', ['value' => $cloud['ssh_key_path'],
	'helptext' => 'Its .pub sibling is installed on created instances.']);
$fw_cloud->textinput('referral_url', 'Linode referral URL', ['value' => $cloud['referral_url']]);
$fw_cloud->textinput('region', 'Default region', ['value' => $cloud['region']]);
$fw_cloud->textinput('instance_type', 'Default instance type', ['value' => $cloud['type']]);
$fw_cloud->textinput('image', 'OS image', ['value' => $cloud['image']]);
$fw_cloud->submitbutton('btn_save_cloud', 'Save customer-cloud settings');
echo $fw_cloud->end_form();
?>

<hr>

<h4>7. Products</h4>
<p>Per hosting product (product edit page): attach the domain question as a
requirement; for customer-cloud fulfillment set the fulfillment provider to
<code>customer_cloud</code> and put the Connect link
(<code><?= htmlspecialchars($api['is_self'] ? '' : rtrim($api['url'], '/')) ?>/profile/server_manager/connect_cloud</code>)
in the after-purchase message.</p>

<?php
$page->end_box();
$page->admin_footer();
?>
