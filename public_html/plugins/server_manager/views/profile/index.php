<?php
/**
 * Your sites — /profile/server_manager
 *
 * Where somebody who bought a site goes afterwards: what it is, how far setup
 * has got, and the one-time reveal of the password their admin account was born
 * with. The Connect card renders only for a bring-your-own-cloud site that is
 * still waiting for its account link; a hosted buyer connects nothing.
 *
 * @version 2.1 - the pre-payment cards (draft, pending_payment) with Continue, Finish payment, Edit and
 *                Delete; the taken-name card offering an alternate; a Set up a new site link
 *                (specs/managed_hosting_phase1_purchase.md §4.5, §7)
 * @version 2.0 - the sites page (specs/hosted_trial_provisioning.md E7); it was a redirect
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_sites_logic.php', 'logic', 'system', null, 'server_manager'));

$page_vars = process_logic(profile_sites_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$status_labels = array(
	'draft'           => 'Not finished',
	'pending_payment' => 'In your cart',
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
	<p>You have no sites yet.<?php if ($managed_on_sale): ?>
		<a href="<?php echo htmlspecialchars($configure_url); ?>" class="sms-visit">Set up a site</a><?php endif; ?></p>
<?php else: ?>
<section class="sms-sites">
<?php foreach ($sites as $site): ?>
	<article class="sms-site">
		<h2><?php echo htmlspecialchars($site['domain']); ?></h2>
		<?php if ($site['pre_payment']): ?>
		<dl class="sms-facts">
			<dt>Setup</dt>
			<dd><?php echo htmlspecialchars($status_labels[$site['status']] ?? $site['status']); ?></dd>
			<dt>Site</dt>
			<dd><?php echo htmlspecialchars($site['summary']); ?></dd>
		</dl>
		<div class="sms-actions">
			<?php if ($site['status'] === 'draft'): ?>
				<a href="<?php echo htmlspecialchars($configure_url . '?cvp_customer_cloud_provision_id=' . (int)$site['id']); ?>"
					class="sms-visit">Continue</a>
			<?php elseif ($site['in_cart']): ?>
				<a href="/cart" class="sms-visit">Finish payment</a>
			<?php elseif ($site['payment_form'] !== ''): ?>
				<?php echo $site['payment_form']; ?>
			<?php else: ?>
				<span class="sms-note">Managed hosting is not on sale right now; your setup is saved.</span>
			<?php endif; ?>
			<?php echo PublicPage::action_button('Edit', '/profile/server_manager', array(
				'hidden'  => array('action' => 'edit_draft', 'cvp_customer_cloud_provision_id' => (int)$site['id']),
				'confirm' => ($site['status'] === 'pending_payment')
					? 'This removes the site from your cart; you will continue to payment again when you are done.'
					: 'Continue editing this site setup?',
				'class'   => 'sms-secondary-btn',
			)); ?>
			<?php echo PublicPage::action_button('Delete', '/profile/server_manager', array(
				'hidden'  => array('action' => 'delete_draft', 'cvp_customer_cloud_provision_id' => (int)$site['id']),
				'confirm' => 'Remove this site setup? Nothing has been bought or created.',
				'class'   => 'sms-secondary-btn',
			)); ?>
		</div>
	</article>
	<?php continue; endif; ?>
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

		<?php if ($site['taken']): ?>
		<section class="sms-taken">
			<p><strong>The name <?php echo htmlspecialchars($site['taken']['domain']); ?> was taken before we could
				register it.</strong> Choose another name. You paid <?php echo htmlspecialchars($site['taken']['paid']); ?>
				for the domain year, and the new name is covered by that.</p>
			<?php
			$fw_alt = $page->getFormWriter('sms_alt_' . (int)$site['taken']['rdm_id']);
			echo $fw_alt->begin_form();
			$fw_alt->hiddeninput('action', '', array('value' => 'alternate_domain'));
			$fw_alt->hiddeninput('rdm_registered_domain_id', '', array('value' => (int)$site['taken']['rdm_id']));
			$fw_alt->textinput('alternate_domain', 'Another domain name', array(
				'maxlength' => 253, 'placeholder' => 'smithfamily.net',
				'validation' => array('required' => true),
			));
			echo '<div id="alternate_domain_status" class="form-text" aria-live="polite"></div>';
			$fw_alt->submitbutton('btn_alternate', 'Use this name instead');
			echo $fw_alt->end_form();
			?>
		</section>
		<script><?php echo $availability_js; ?></script>
		<?php endif; ?>

		<?php if ($site['password_state'] === 'sealed'): ?>
			<form method="post" action="/profile/server_manager">
				<input type="hidden" name="action" value="reveal_password">
				<input type="hidden" name="cvp_customer_cloud_provision_id" value="<?php echo (int)$site['id']; ?>">
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

<?php if (count($sites) > 0 && $managed_on_sale): ?>
	<p class="sms-note"><a href="<?php echo htmlspecialchars($configure_url); ?>">Set up another site</a></p>
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
.sms-actions form, .sms-pay { display: inline-block; margin-right: .5em; }
.sms-actions .sms-visit { border: 0; font-size: 1em; cursor: pointer; margin-right: .5em; }
.sms-secondary-btn { background: #fff; color: #1f2937; border: 1px solid #cbd5e1; padding: 8px 18px;
	border-radius: 4px; font-size: .95em; cursor: pointer; }
.sms-taken { padding: 12px 16px; margin: 1em 0; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; }
#alternate_domain_status[data-state="available"] { color: #1a7f37; }
#alternate_domain_status[data-state="unavailable"] { color: #b42318; }
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
