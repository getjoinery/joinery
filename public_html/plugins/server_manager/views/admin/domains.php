<?php
/**
 * Server Manager - Managed Domains
 * URL: /admin/server_manager/domains
 *
 * The whole domain leg in one page, ordered by what needs a person:
 * hand-overs waiting for a dashboard push first (that push has no API), then
 * registrations that failed and need a decision, then everything else as a
 * ledger.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/admin_domains_logic.php'));

$page_vars = process_logic(admin_domains_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$status_labels = array(
	'pending'    => 'Waiting to be registered',
	'registered' => 'Registered — wiring up DNS',
	'active'     => 'Live',
	'failed'     => 'Failed',
);
$custody_labels = array(
	'operator_managed' => 'Ours to manage',
	'push_requested'   => 'Hand-over requested',
	'push_sent'        => 'Pushed — waiting for them to accept',
	'self_custody'     => 'Theirs',
);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'server-manager',
	'page_title'     => 'Domains',
	'readable_title' => 'Domains',
	'breadcrumbs'    => array(
		'Server Manager' => '/admin/server_manager',
		'Domains'        => '',
	),
	'session'        => $session,
));

$page->begin_box(array());
?>

<p>Domains registered for buyers at checkout. The buyer is the legal owner of every one of these
from the day it was bought; what this page tracks is whether it is still <em>managed and billed</em>
through our registrar account, and how far along the hand-over is.</p>

<?php if (!$registrar_ready || !$product_ready): ?>
<div class="alert alert-warning">
	<strong>Domain registration is not sellable yet.</strong>
	<?php if (!$registrar_ready): ?>
		No registrar is configured — add the credentials on the
		<a href="/admin/server_manager/provisioning_setup">Provisioning Setup</a> page.
	<?php endif; ?>
	<?php if (!$product_ready): ?>
		No domain-year product is selected — create a product with one version priced
		<em>user</em> and choose it in the store's Domain registration setting.
	<?php endif; ?>
	Until both are set, the checkout field refuses the order rather than selling a domain it
	cannot register.
</div>
<?php endif; ?>

<h4>Hand-overs waiting for you</h4>
<?php if (count($pending_pushes) === 0): ?>
	<p class="text-muted">Nothing waiting.</p>
<?php else: ?>
	<p>Open each domain in the <?php echo htmlspecialchars($registrar_label ?: 'registrar'); ?>
	dashboard, use <strong>Change Ownership</strong> to push it to the account named here, then mark
	it sent. The push is free and immediate; DNS records, privacy and auto-renew all survive it. The
	pipeline confirms on its own once the domain actually leaves the account, so marking it sent is
	only what moves the buyer's page to its "finish in your dashboard" instructions.</p>
	<table class="table table-sm">
		<thead><tr><th>Domain</th><th>Their account</th><th>Buyer</th><th>Expires</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($pending_pushes as $row): ?>
			<tr>
				<td><strong><?php echo htmlspecialchars($row->get('rdm_domain')); ?></strong></td>
				<td><code><?php echo htmlspecialchars($row->get('rdm_ncp_username')); ?></code></td>
				<td><?php echo htmlspecialchars($row->get('rdm_buyer_email')); ?></td>
				<td><?php echo htmlspecialchars($row->get_local('rdm_expiry_time', 'M j, Y') ?: '—'); ?></td>
				<td><?php echo AdminPage::action_button('Mark push sent', '/admin/server_manager/domains',
					array('hidden' => array('action' => 'mark_pushed', 'rdm_id' => $row->key))); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<hr>

<h4>Failed registrations</h4>
<?php if (count($failures) === 0): ?>
	<p class="text-muted">None.</p>
<?php else: ?>
	<p>These are parked and never retried on their own — a registration that failed terminally
	usually needs a conversation with the buyer (a refund, or a different name) before it is worth
	trying again. Retry puts the row back in the queue as it is.</p>
	<table class="table table-sm">
		<thead><tr><th>Domain</th><th>Buyer</th><th>Why</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($failures as $row): ?>
			<tr>
				<td><strong><?php echo htmlspecialchars($row->get('rdm_domain')); ?></strong></td>
				<td><?php echo htmlspecialchars($row->get('rdm_buyer_email')); ?></td>
				<td><?php echo nl2br(htmlspecialchars((string)$row->get('rdm_error'))); ?></td>
				<td><?php echo AdminPage::action_button('Retry', '/admin/server_manager/domains',
					array('hidden' => array('action' => 'retry', 'rdm_id' => $row->key),
						'confirm' => 'Queue ' . $row->get('rdm_domain') . ' for registration again?')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<hr>

<h4>All registered domains</h4>
<?php if ($total_domains > $ledger_limit): ?>
	<p class="text-muted">Showing the newest <?php echo (int)$ledger_limit; ?>
	of <?php echo (int)$total_domains; ?>.</p>
<?php endif; ?>
<?php if (count($all_domains) === 0): ?>
	<p class="text-muted">No domains have been registered yet. Offered endings:
		<?php echo htmlspecialchars($offered_tlds); ?>.</p>
<?php else: ?>
	<table class="table table-sm">
		<thead><tr>
			<th>Domain</th><th>Buyer</th><th>Status</th><th>Custody</th>
			<th>Expires</th><th>Paid</th><th>Steps</th>
		</tr></thead>
		<tbody>
		<?php foreach ($all_domains as $row):
			$steps = array();
			$steps[] = $row->get('rdm_dns_bootstrap_time') ? 'web ✓' : 'web —';
			$steps[] = $row->get('rdm_dns_mail_time') ? 'mail ✓' : 'mail —';
			$steps[] = $row->get('rdm_ptr_time') ? 'ptr ✓' : 'ptr —';
		?>
			<tr>
				<td><strong><?php echo htmlspecialchars($row->get('rdm_domain')); ?></strong></td>
				<td><?php echo htmlspecialchars($row->get('rdm_buyer_email')); ?></td>
				<td><?php echo htmlspecialchars($status_labels[$row->get('rdm_status')] ?? $row->get('rdm_status')); ?></td>
				<td><?php echo htmlspecialchars($custody_labels[$row->get('rdm_graduation_state')] ?? $row->get('rdm_graduation_state')); ?></td>
				<td><?php echo htmlspecialchars($row->get_local('rdm_expiry_time', 'M j, Y') ?: '—'); ?></td>
				<td><?php echo htmlspecialchars((string)$row->get('rdm_price_paid')); ?></td>
				<td><?php echo htmlspecialchars(implode(' · ', $steps)); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php
$page->end_box();
$page->admin_footer();
?>
