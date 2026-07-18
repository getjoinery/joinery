<?php
/**
 * Connect page — /profile/server_manager/connect_cloud
 *
 * A customer-cloud hosting buyer links (or re-links) their Linode account
 * here; their provisions and setup progress are shown below. The Connect
 * button is a single-button action form (no user-entered fields).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_connect_cloud_logic.php', 'logic', 'system', null, 'server_manager'));

$page_vars = process_logic(profile_connect_cloud_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$status_labels = array(
	'pending_connect' => 'Waiting for your account connection',
	'ready'           => 'Queued — your server will be created shortly',
	'booting'         => 'Your server is starting',
	'installing'      => 'Installing your site',
	'done'            => 'Ready',
	'failed'          => 'Needs attention — our team has been notified',
);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Your Server Account',
	'breadcrumbs' => array(
		'Your Server Account' => '',
	),
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Your Server Account', $hoptions);
?>

<?php if ($message): ?>
	<p class="smcc-message"><?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<section class="smcc-connect">
<?php if ($account_connected): ?>
	<p class="smcc-connected">✓ Your Linode account is connected. Servers are created in your account and billed directly to you by Linode.</p>
	<form method="post" action="/profile/server_manager/connect_cloud">
		<input type="hidden" name="action" value="connect">
		<button type="submit" class="smcc-reconnect-btn">Reconnect</button>
	</form>
<?php else: ?>
	<h2>Connect your Linode account</h2>
	<p>Your site runs on a server in your own Linode account — billed directly to you by Linode, with no markup from us. Connect your account and everything else is automatic.</p>
	<?php if ($provider_configured): ?>
	<form method="post" action="/profile/server_manager/connect_cloud">
		<input type="hidden" name="action" value="connect">
		<button type="submit" class="smcc-connect-btn">Connect your Linode account</button>
	</form>
	<?php endif; ?>
	<?php if ($referral_url): ?>
	<p class="smcc-signup">Don't have a Linode account yet?
		<a href="<?php echo htmlspecialchars($referral_url); ?>" target="_blank" rel="noopener">Create one here</a>
		— new accounts get a $100 credit. Then come back and connect.</p>
	<?php endif; ?>
<?php endif; ?>
</section>

<?php if (count($provisions) > 0): ?>
<section class="smcc-provisions">
	<h2>Your sites</h2>
	<table class="smcc-table">
		<thead><tr><th>Domain</th><th>Status</th></tr></thead>
		<tbody>
		<?php foreach ($provisions as $provision): ?>
			<tr>
				<td><?php echo htmlspecialchars($provision->get('cvp_domain')); ?></td>
				<td><?php echo htmlspecialchars($status_labels[$provision->get('cvp_status')] ?? $provision->get('cvp_status')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
<?php endif; ?>

<style>
.smcc-message { padding: 12px 16px; background: #eef6ff; border-radius: 4px; }
.smcc-connect { margin: 1.5em 0; }
.smcc-connected { color: #1a7f37; font-weight: bold; }
.smcc-connect-btn { background: #02b159; color: #fff; border: 0; padding: 12px 28px; border-radius: 4px; font-size: 1em; font-weight: bold; cursor: pointer; }
.smcc-connect-btn:hover { filter: brightness(1.05); }
.smcc-signup { color: #555; margin-top: 1em; }
.smcc-reconnect-btn { background: none; border: 0; color: #1565c0; cursor: pointer; padding: 0; text-decoration: underline; font-size: .9em; }
.smcc-table { border-collapse: collapse; width: 100%; }
.smcc-table th, .smcc-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #e2e2e2; }
</style>

<?php if ($in_progress): ?>
<script>
/* Follow setup progress while a provision is mid-pipeline. */
setTimeout(function () { window.location.reload(); }, 30000);
</script>
<?php endif; ?>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
