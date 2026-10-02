<?php
/**
 * Your sites — /profile/server_manager
 *
 * Where somebody who bought a site goes afterwards: what it is, how far setup
 * has got, and the one-time reveal of the password their admin account was born
 * with. The Connect card renders only for a bring-your-own-cloud site that is
 * still waiting for its account link; a hosted buyer connects nothing.
 *
 * @version 2.2 - the Move to your own Linode account card: its steps, the transfer code behind a button, and
 *                Stop managing this site after the move (specs/managed_to_self_hosted_transfer.md §6)
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

		<?php if ($site['transfer'] !== null): $t = $site['transfer']; ?>
		<section class="sms-move" data-provision="<?php echo (int)$site['id']; ?>">
			<h3>Move to your own Linode account</h3>
			<?php if ($t['state'] === ''): ?>
				<p>Your site runs on its own server in our Linode account. It can move into a Linode account of your
					own — running, with the same address, nothing copied or rebuilt. Linode then bills you for the
					server, and we stop billing you for hosting. Your email and backups keep working through us.</p>
				<button type="button" class="sms-secondary-btn" data-do="request">Move to my own Linode account</button>
			<?php elseif ($t['state'] === 'requested'): ?>
				<p><strong><?php echo htmlspecialchars($t['state_label']); ?>.</strong> We check your server first and
					email you the next step.</p>
			<?php elseif ($t['state'] === 'invited'): ?>
				<ol class="sms-steps">
					<li>Open a Linode account if you do not have one: <a href="<?php echo htmlspecialchars($t['signup_url']); ?>"
						rel="noopener" target="_blank">sign up at Linode</a>, and add a payment card to it.</li>
					<li>When it is ready, get your transfer code here. It works for 24 hours.</li>
				</ol>
				<button type="button" class="sms-visit" data-do="get_code">I'm ready — get my transfer code</button>
			<?php elseif ($t['state'] === 'code_issued'): ?>
				<p>Your transfer code is ready, valid until <strong><?php echo htmlspecialchars($t['code_expiry']); ?></strong>.
					Anyone with this code can take the server, so keep it to yourself.</p>
				<p class="sms-code" hidden><code data-code></code>
					<button type="button" class="sms-secondary-btn" data-copy>Copy</button></p>
				<button type="button" class="sms-reveal-btn" data-do="reveal_code">Show my transfer code</button>
				<ol class="sms-steps">
					<li>Sign in to Linode Cloud Manager with your own account.</li>
					<li>Open <strong>Account</strong> (your name at the top right), then <strong>Service Transfers</strong>.</li>
					<li>Choose <strong>Accept a Service Transfer</strong>, paste the code, review it and accept.</li>
				</ol>
				<p class="sms-note">Linode moves the server within about three hours of accepting, and the site keeps
					running while it does. Your account must not already have a Linode named
					<code><?php echo htmlspecialchars($t['label']); ?></code>.</p>
			<?php elseif ($t['state'] === 'done'): ?>
				<p><strong>Your server is now in your own Linode account.</strong>
					<?php if ($t['was_shut_down']): ?>It moved powered off: boot it in Cloud Manager to bring the site back.<?php endif; ?></p>
				<ul class="sms-steps">
					<li>Your email and backups carry on through us<?php if ($t['paid_until'] !== ''): ?>, paid until
						<?php echo htmlspecialchars($t['paid_until']); endif; ?>. See
						<a href="/profile/server_manager/services">Connected sites</a>.</li>
					<li>The server takes no password over SSH and holds no key of ours. To get in as root, reset the root
						password in Cloud Manager, sign in through the Lish console, and add your own SSH key.</li>
					<li>Reverse DNS for its address is now set in your own Cloud Manager.</li>
				</ul>
				<?php if ($t['managed']): ?>
				<p class="sms-note">We still look after this site: updates, backups and alerts.</p>
				<button type="button" class="sms-secondary-btn" data-do="stop_managing"
					data-confirm="Stop our management of this site? We forget its agent and stop its updates, backups and alerts. Backups already stored with us are deleted after 90 days. Outbound email carries on until its paid-through date.">Stop managing this site</button>
				<?php else: ?>
				<p class="sms-note">We no longer manage this site.</p>
				<?php endif; ?>
			<?php else: ?>
				<p><strong><?php echo htmlspecialchars($t['state_label']); ?>.</strong></p>
			<?php endif; ?>
			<?php if ($t['cancelable']): ?>
				<button type="button" class="sms-secondary-btn" data-do="cancel"
					data-confirm="Cancel the move? Your site stays with us as it is.">Cancel the move</button>
			<?php endif; ?>
			<p class="sms-move-notice" role="status" aria-live="polite"></p>
		</section>
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
.sms-move { padding: 12px 16px; margin: 1em 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; }
.sms-move h3 { margin: 0 0 .5em; font-size: 1.05em; }
.sms-steps { margin: .5em 0 1em; padding-left: 1.4em; }
.sms-code code { font-size: 1.1em; background: #fff; padding: 6px 10px; border: 1px solid #e2e2e2;
	border-radius: 4px; display: inline-block; word-break: break-all; }
.sms-move-notice:empty { display: none; }
.sms-move-notice { color: #b42318; }
.sms-taken { padding: 12px 16px; margin: 1em 0; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; }
#alternate_domain_status[data-state="available"] { color: #1a7f37; }
#alternate_domain_status[data-state="unavailable"] { color: #b42318; }
</style>

<script>
/* The move card's buttons call the transfer_customer action; the code is
   fetched only when its button is pressed, and never kept anywhere else. */
document.querySelectorAll('.sms-move [data-do]').forEach(function (button) {
	button.addEventListener('click', function () {
		var card = button.closest('.sms-move');
		var notice = card.querySelector('.sms-move-notice');
		var what = button.getAttribute('data-do');
		if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) { return; }
		notice.textContent = '';
		button.disabled = true;
		joineryApi.post('server_manager/transfer_customer', {
			'do': what, provision_id: parseInt(card.getAttribute('data-provision'), 10)
		}).then(function (data) {
			if (what === 'reveal_code' && data && data.code) {
				var box = card.querySelector('.sms-code');
				box.querySelector('[data-code]').textContent = data.code;
				box.hidden = false;
				button.hidden = true;
				return;
			}
			window.location.reload();
		}).catch(function (err) {
			button.disabled = false;
			notice.textContent = (err && err.message) ? err.message : 'That did not work. Try again in a minute.';
			var link = joineryApi.reportLink(err);
			if (link) { notice.appendChild(document.createTextNode(' ')); notice.appendChild(link); }
		});
	});
});
document.querySelectorAll('.sms-move [data-copy]').forEach(function (button) {
	button.addEventListener('click', function () {
		var code = button.closest('.sms-code').querySelector('[data-code]').textContent;
		if (navigator.clipboard) { navigator.clipboard.writeText(code); button.textContent = 'Copied'; }
	});
});
</script>

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
