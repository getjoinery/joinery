<?php
/**
 * Your sites — /profile/server_manager
 *
 * Where somebody who bought a site goes afterwards: what it is, how far setup
 * has got, and the one-time reveal of the password their admin account was born
 * with. The Connect card renders only for a bring-your-own-cloud site that is
 * still waiting for its account link; a hosted buyer connects nothing.
 *
 * @version 2.0 - the sites page (specs/hosted_trial_provisioning.md E7); it was a redirect
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_sites_logic.php', 'logic', 'system', null, 'server_manager'));

$page_vars = process_logic(profile_sites_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$status_labels = array(
	'pending_connect' => 'Waiting for your account connection',
	'ready'           => 'Queued — your server will be created shortly',
	'booting'         => 'Your server is starting',
	'installing'      => 'Installing your site',
	'done'            => 'Ready',
	'failed'          => 'Needs attention — our team has been notified',
);
$mail_labels = array(
	'pending'            => 'Setting up',
	'subaccount_created' => 'Setting up',
	'domain_added'       => 'Setting up',
	'records_published'  => 'Publishing mail records',
	'domain_verified'    => 'Almost ready',
	'smtp_user_created'  => 'Almost ready',
	'done'               => 'Working',
	'failed'             => 'Needs attention',
);
$plan_labels = array(
	'trial'      => 'Free trial',
	'subscribed' => 'Subscribed',
	'grace'      => 'Payment needed',
	'shutdown'   => 'Shut down',
);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Your Sites',
	'breadcrumbs' => array('Your Sites' => ''),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Your Sites', $hoptions);
?>

<?php if ($error): ?>
	<p class="sms-error"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<?php if ($revealed !== ''): ?>
<section class="sms-reveal">
	<h2>Your admin password for <?php echo htmlspecialchars($revealed_domain); ?></h2>
	<p><strong>Write this down now.</strong> It is shown once and we no longer have a copy.</p>
	<p class="sms-password"><code><?php echo htmlspecialchars($revealed); ?></code></p>
	<p>Sign in at <a href="https://<?php echo htmlspecialchars($revealed_domain); ?>/admin">https://<?php echo htmlspecialchars($revealed_domain); ?>/admin</a>
		with your own email address. Your site will ask you to choose a new password straight away.</p>
</section>
<?php endif; ?>

<?php if (count($sites) === 0): ?>
	<p>You have no sites yet.</p>
<?php else: ?>
<section class="sms-sites">
<?php foreach ($sites as $site): ?>
	<article class="sms-site">
		<h2><?php echo htmlspecialchars($site['domain']); ?></h2>
		<dl class="sms-facts">
			<dt>Setup</dt>
			<dd><?php echo htmlspecialchars($status_labels[$site['status']] ?? $site['status']); ?></dd>
			<?php if ($site['hosted'] && $site['mail_state'] !== ''): ?>
			<dt>Email</dt>
			<dd><?php echo htmlspecialchars($mail_labels[$site['mail_state']] ?? $site['mail_state']); ?></dd>
			<?php endif; ?>
			<?php if ($site['plan_state'] !== ''): ?>
			<dt>Hosting</dt>
			<dd><?php echo htmlspecialchars($plan_labels[$site['plan_state']] ?? $site['plan_state']);
				if ($site['plan_until'] !== '') {
					echo ' until ' . htmlspecialchars(LibraryFunctions::convert_time($site['plan_until'], 'UTC',
						$session->get_timezone(), 'F j, Y'));
				} ?></dd>
			<?php endif; ?>
		</dl>

		<?php if ($site['status'] === 'done'): ?>
			<p><a href="<?php echo htmlspecialchars($site['url']); ?>" class="sms-visit">Visit your site</a></p>
		<?php endif; ?>

		<?php if ($site['password_state'] === 'sealed'): ?>
			<form method="post" action="/profile/server_manager">
				<input type="hidden" name="action" value="reveal_password">
				<input type="hidden" name="cvp_id" value="<?php echo (int)$site['id']; ?>">
				<button type="submit" class="sms-reveal-btn">Show my admin password</button>
			</form>
			<p class="sms-note">Shown once, then we forget it. Your site asks you to choose a new one
				at first sign-in.</p>
		<?php elseif ($site['password_state'] === 'revealed'): ?>
			<p class="sms-note">Your admin password was shown once and we no longer have a copy. If you
				lost it, use the Forgot password link on your own site.</p>
		<?php endif; ?>
	</article>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($needs_connect): ?>
<section class="sms-connect">
	<h2>Connect your cloud account</h2>
	<p>One of your sites runs on a server in your own cloud account, billed directly to you with no
		markup from us. It cannot be created until you connect that account.</p>
	<p><a href="/profile/server_manager/connect_cloud" class="sms-visit">Connect your account</a></p>
</section>
<?php elseif ($account_connected): ?>
	<p class="sms-note">Your cloud account is connected.
		<a href="/profile/server_manager/connect_cloud">Manage the connection</a></p>
<?php endif; ?>

<style>
.sms-error { padding: 12px 16px; background: #fef2f2; border-radius: 4px; }
.sms-reveal { padding: 16px; margin: 1em 0 1.5em; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; }
.sms-password code { font-size: 1.4em; letter-spacing: .05em; background: #fff; padding: 8px 14px;
	border: 1px solid #e2e2e2; border-radius: 4px; display: inline-block; }
.sms-site { padding: 16px 0; border-bottom: 1px solid #e2e2e2; }
.sms-facts { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: .5em 0 1em; }
.sms-facts dt { font-weight: 600; }
.sms-facts dd { margin: 0; }
.sms-visit { display: inline-block; background: #02b159; color: #fff; padding: 10px 22px;
	border-radius: 4px; text-decoration: none; font-weight: bold; }
.sms-reveal-btn { background: #1f2937; color: #fff; border: 0; padding: 10px 22px; border-radius: 4px;
	font-size: 1em; cursor: pointer; }
.sms-note { color: #555; font-size: .9em; }
.sms-connect { margin-top: 2em; }
</style>

<?php if ($in_progress): ?>
<script>
/* Follow setup progress while a site is mid-pipeline. */
setTimeout(function () { window.location.reload(); }, 30000);
</script>
<?php endif; ?>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
