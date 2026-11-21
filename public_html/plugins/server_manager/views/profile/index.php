<?php
/**
 * Your sites — /profile/server_manager
 *
 * Where somebody who bought a site goes afterwards: what it is, how far setup
 * has got, and the one-time reveal of the password their admin account was born
 * with. The Connect card renders only for a bring-your-own-cloud site that is
 * still waiting for its account link; a hosted buyer connects nothing.
 *
 * @version 2.30 - Servers you run: a server the buyer added themselves, the claim step and the Add a server card
 * @version 2.29 - Stop managing and remove on a site on the buyer's own server; Remove on an order that never built
 * @version 2.28 - the page does not reload itself while a one-time password is shown (another site building took it
 *                away after 30 seconds); the password has a Copy button
 * @version 2.27 - every DNS name and value has a Copy button (assets/js/profile-copy.js) and wraps on a phone
 * @version 2.26 - a suspended or shut-down site reads as switched off: no Ready, no working email, no Visit, no move offer
 * @version 2.25 - a site whose server the buyer's own Linode account refused shows Linode's words above Connect
 * @version 2.24 - the one-time admin password and Visit your site wait until the domain points at the site
 * @version 2.23 - Ask us tells the support desk which page, and which site, the buyer came from
 * @version 2.22 - Move to your own Linode account is one closed line on a card until a move is asked for
 * @version 2.21 - a buyer's own-domain records appear once, all together, with a line saying so while they are gathered (walkthrough Q16)
 * @version 2.20 - Finishing setup: built, not yet told what it comes with (walkthrough Q4)
 * @version 2.19 - a site waiting for its Linode connection says so on its own card, with the button, and says when
 *                Linode refused the last connection (findings 71, 76, 77)
 * @version 2.18 - a saved setup that is not in the cart reads Ready to pay, not In your cart; email waiting on the buyer's own
 *                records says so (specs/hosting_purchase_walkthrough_test.md, findings 21 and 48)
 * @version 2.17 - each site names its licence: free, the business licence covering it and its key (WP7)
 * @version 2.16 - Manage: links to the site's subscription, billing, domain, email and backups (hosting_after_purchase N2)
 * @version 2.15 - every setup state has words (held, failed with where to write); the hosting row is one sentence
 *                with its date (profile_sites_plan_line) (hosting_after_purchase P2, P4, P5, S3)
 * @version 2.14 - a draft's card offers payment for every plan; Connect belongs to a paid own-Linode site
 * @version 2.13 - buttons and colours follow the theme: the page sits in the jy-ui kit, buttons are btn btn-primary / btn-secondary, colours are theme tokens
 * @version 2.12 - an own-Linode draft waiting on its Linode connection offers Connect, not payment
 * @version 2.11 - a site on its buyer's own Linode says whose server it is and whether email and backups came with it
 * @version 2.10 - Add these records for me (the DNS publish box) above the record lists, and the optional
 *                 IPv6 record on its own
 * @version 2.9 - an optional DMARC record on its own, below the email records (hosting_paid_launch B4);
 *   the move card says email sent to the domain stops reaching the site when the move finishes (B11)
 * @version 2.8 - a suspended site shows its reason (HostedSuspensions)
 * @version 2.7 - the email records box covers the records that bring mail in as well as those that sign it
 * @version 2.6 - no inline script: the move card is assets/js/profile-move.js, the reload data-jy-reload-after
 * @version 2.5 - a site on the buyer's own domain lists the email records still to add (B3)
 * @version 2.4 - not found where this site does not sell hosting (SmHosting)
 * @version 2.3 - a built site on the buyer's own domain shows the address record to add until it takes effect
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

// A buyer's page exists only where this site sells hosting (SmHosting).
SmHosting::not_found_unless_enabled();

$page_vars = process_logic(profile_sites_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$status_labels = array(
	'draft'           => 'Not finished',
	'pending_payment' => 'In your cart',
	'pending_connect' => 'Waiting for your account connection',
	'ready'           => 'Queued. The whole build takes ' . SmHosting::BUILD_TIME,
	'booting'         => 'Your server is starting. The whole build takes ' . SmHosting::BUILD_TIME,
	'installing'      => 'Installing your site. The whole build takes ' . SmHosting::BUILD_TIME,
	'finishing'       => 'Finishing setup. A minute or two more',
	'done'            => 'Ready',
	'failed'          => 'Something went wrong. We have been told, and we email you when it is sorted out',
	'held'            => 'Being checked by a person. We email you within one working day',
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
$support_email = SmHosting::support_email();
// How a DNS host's form usually wants a record's name written: @ for the
// domain itself, the first part for a name under it. Which it is depends on
// where the zone starts, which we cannot see, so both are offered as "often".
$sms_short_name = function (string $domain): string {
	$parts = explode('.', $domain);
	if (count($parts) <= 2) {
		return 'often written as <code>@</code>';
	}
	return 'often written as <code>' . htmlspecialchars($parts[0]) . '</code>, or <code>@</code> if this is the whole domain';
};

$page = new PublicPage();
$hoptions = array(
	'title' => 'Your Sites',
	'breadcrumbs' => array('Your Sites' => ''),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Your Sites', $hoptions);
echo '<div class="jy-ui">';
// What an action on this page just said (a DNS publish, a refused credential):
// shown here, where it happened, not on whichever page next shows messages.
echo $page->render_messages();
?>
<?php if (!empty($email_unconfirmed)): // said while they are still signed in (walkthrough Q6) ?>
<section class="sms-taken">
	<p><?php echo $email_unconfirmed; ?></p>
	<?php echo PublicPage::action_button('Send the link again', '/profile/server_manager', array(
		'hidden' => array('action' => 'resend_confirmation'),
		'class'  => 'btn btn-secondary',
	)); ?>
</section>
<?php endif; ?>

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
		<a href="<?php echo htmlspecialchars($configure_url); ?>" class="btn btn-primary">Set up a site</a><?php endif; ?></p>
<?php else: ?>
<section class="sms-sites">
<?php foreach ($sites as $site): ?>
	<article class="sms-site">
		<h2><?php echo htmlspecialchars($site['domain']); ?></h2>
		<?php if ($site['pre_payment']): ?>
		<dl class="sms-facts">
			<dt>Setup</dt>
			<dd><?php
				// Saved and priced is not "in your cart" until it is: the cart may be empty.
				$setup_label = ($site['status'] === 'pending_payment' && !$site['in_cart'])
					? 'Ready to pay. Nothing has been charged'
					: ($status_labels[$site['status']] ?? $site['status']);
				echo htmlspecialchars($setup_label); ?></dd>
			<dt>Site</dt>
			<dd><?php echo htmlspecialchars($site['summary']); ?></dd>
		</dl>
		<div class="sms-actions">
			<?php if ($site['status'] === 'draft'): ?>
				<a href="<?php echo htmlspecialchars($configure_url . '?cvp_customer_cloud_provision_id=' . (int)$site['id']); ?>"
					class="btn btn-primary">Continue</a>
			<?php elseif ($site['in_cart']): ?>
				<a href="/cart" class="btn btn-primary">Finish payment</a>
			<?php elseif ($site['payment_form'] !== ''): ?>
				<?php echo $site['payment_form']; ?>
			<?php else: ?>
				<span class="sms-note">Managed hosting is not on sale right now; your setup is saved.</span>
			<?php endif; ?>
			<?php echo PublicPage::action_button('Edit', '/profile/server_manager', array(
				'hidden'  => array('action' => 'edit_draft', 'cvp_customer_cloud_provision_id' => (int)$site['id']),
				'confirm' => $site['in_cart']
					? 'This removes the site from your cart; you will continue to payment again when you are done.'
					: 'Continue editing this site setup?',
				'class'   => 'btn btn-secondary',
			)); ?>
			<?php echo PublicPage::action_button('Delete', '/profile/server_manager', array(
				'hidden'  => array('action' => 'delete_draft', 'cvp_customer_cloud_provision_id' => (int)$site['id']),
				'confirm' => 'Remove this site setup? Nothing has been bought or created.',
				'class'   => 'btn btn-secondary',
			)); ?>
		</div>
	</article>
	<?php continue; endif; ?>
		<dl class="sms-facts">
			<dt>Setup</dt>
			<dd><?php // A suspended or shut-down site is switched off, whatever state its build reached.
				$sms_shut = (($site['plan_state'] ?? '') === HostedTrial::STATE_SHUTDOWN);
				$sms_off = $site['suspended'] !== null || $sms_shut;
				echo $site['suspended'] !== null ? 'Switched off while it is suspended'
					: ($sms_shut ? 'Shut down'
					: htmlspecialchars($status_labels[$site['status']] ?? $site['status']));
				if (in_array($site['status'], array('failed', 'held'), true) && $support_email !== '') {
					echo '. Questions? Write to <a href="mailto:' . htmlspecialchars($support_email) . '">'
						. htmlspecialchars($support_email) . '</a>.';
				} ?></dd>
			<?php if ($site['own_linode']): ?>
			<dt>Server</dt>
			<dd>In your own Linode account. Linode bills you for it.</dd>
			<dt><?php echo htmlspecialchars(ManagedSiteDraft::BUNDLE_NAME); ?></dt>
			<dd><?php echo $site['with_services'] ? 'Included: email and backups through us, with your subscription.'
				: 'Not included. Your site\'s setup offers our email and backups, or set up your own.'; ?></dd>
			<?php endif; ?>
			<?php if ($site['show_mail'] && $site['mail_state'] !== '' && !$site['needs_connect']): ?>
			<dt>Email</dt>
			<dd><?php
				// Waiting on records only the buyer can add is said as that, not as work we are doing.
				if ($site['suspended'] !== null) {
					$mail_label = 'Stopped while the site is suspended';
				} elseif ($sms_shut) {
					$mail_label = 'Stopped: the site is shut down';
				} elseif ($site['mail_state'] === 'records_published' && !empty($site['mail_records'])) {
					$mail_label = 'Waiting for the email records below';
				} else {
					$mail_label = $mail_labels[$site['mail_state']] ?? $site['mail_state'];
				}
				echo htmlspecialchars($mail_label); ?></dd>
			<?php endif; ?>
			<?php if ($site['suspended'] !== null): ?>
			<dt>Suspended</dt>
			<dd><?php echo htmlspecialchars($site['suspended']); ?> Reply to the email we sent if this is a mistake or once it is fixed.</dd>
			<?php endif; ?>
			<?php if ($site['plan_line'] !== ''): ?>
			<dt>Hosting</dt>
			<dd><?php echo htmlspecialchars($site['plan_line']); ?></dd>
			<?php endif; ?>
			<?php if ($site['licence_line'] !== ''): ?>
			<dt>Licence</dt>
			<dd><?php echo htmlspecialchars($site['licence_line']); ?></dd>
			<?php endif; ?>
		</dl>

		<?php if ($site['needs_connect']): // the one thing this site waits on, said on its own card ?>
		<section class="sms-connect">
			<?php if ($site['provider_refusal'] !== ''): ?>
			<p class="sms-error">Linode would not create the server in your account, so nothing was built and nothing
				was charged there. Linode said: &ldquo;<?php echo htmlspecialchars($site['provider_refusal']); ?>&rdquo;
				Once that is put right with Linode, <?php echo $site['grant_live'] ? 'try again' : 'connect again'; ?> and we build the site.</p>
				<?php if ($site['grant_live']): ?>
				<form method="post" action="/profile/server_manager">
					<input type="hidden" name="action" value="retry_refused">
					<input type="hidden" name="cvp_customer_cloud_provision_id" value="<?php echo (int)$site['id']; ?>">
					<button type="submit" class="btn btn-primary">Try again</button>
				</form>
				<?php endif; ?>
			<?php elseif ($site['connect_refused']): ?>
			<p class="sms-error">Linode did not accept the last connection, so nothing was created in your account.
				Connect again to continue.</p>
			<?php endif; ?>
			<?php if (!($site['provider_refusal'] !== '' && $site['grant_live'])): // a live connection needs no second one ?>
			<p><strong>This site is not being built yet.</strong> It runs on a server in your own Linode account,
				billed to you by Linode with no markup from us, and we cannot create that server until you connect
				the account. We ask only for permission to create servers. The site is ready <?php echo SmHosting::BUILD_TIME; ?>
				after you connect.</p>
			<p><a href="/profile/server_manager/connect_cloud" class="btn btn-primary">Connect your Linode account</a></p>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<?php
		// Where the domain's DNS host has a driver, the records below can be
		// written for the buyer in one press; the lists stay for doing it by hand.
		if ($site['dns_box'] !== null && !empty($site['dns_box']['provider_label'])) {
			require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
			echo '<section class="sms-dns"><h3>Let us add your records</h3><p>Your domain\'s DNS is at '
				. htmlspecialchars($site['dns_box']['provider_label']) . '. We can add every record below there '
				. 'for you: you see each one before anything is written, and the access you give us is used for that '
				. 'one write and not kept.</p>';
			dns_publish_box_render($page, $site['dns_box'],
				'Add these records at ' . $site['dns_box']['provider_label'] . ' for me');
			echo '</section><p class="sms-note">Or add them yourself, where your domain is registered:</p>';
		}
		?>

		<?php if ($site['dns_pending']): ?>
		<section class="sms-dns">
			<h3>Your domain's DNS records</h3>
			<p>Your site needs a few records added where your domain is registered. We list every one here, all at
				once, a few minutes after your site is built, and nothing needs adding before then.</p>
		</section>
		<?php endif; ?>

		<?php if ($site['dns_record'] !== null): ?>
		<section class="sms-dns">
			<h3>Point your domain at your site</h3>
			<p>Your site has no address and no security certificate until you add this record where your domain
				is registered:</p>
			<dl class="sms-facts">
				<dt>Type</dt><dd><?php echo htmlspecialchars($site['dns_record']['type']); ?></dd>
				<dt>Name</dt><dd><code><?php echo htmlspecialchars($site['domain']); ?></code> (<?php echo $sms_short_name($site['domain']); ?>)</dd>
				<dt>Value</dt><dd><code><?php echo htmlspecialchars($site['dns_record']['value']); ?></code></dd>
			</dl>
			<p class="sms-note">It usually takes effect within a few minutes to a few hours. The certificate follows
				on its own, and this box goes away once the name points here.</p>
		</section>
		<?php endif; ?>

		<?php if ($site['mail_records']): ?>
		<section class="sms-dns">
			<h3>Add your email and messaging records</h3>
			<p>Add <?php echo count($site['mail_records']) === 1 ? 'this record' : 'these records'; ?> where your domain
				is registered, in the same visit. Until they are in place, email your site sends is more likely to land
				in spam, email sent to your domain may not reach your site, and other Joinery sites cannot reach
				yours directly.</p>
			<?php foreach ($site['mail_records'] as $record): ?>
			<dl class="sms-facts">
				<dt>Type</dt><dd><?php echo htmlspecialchars($record['type']); ?></dd>
				<dt>Name</dt><dd><code><?php echo htmlspecialchars($record['name']); ?></code></dd>
				<dt>Value</dt><dd><code><?php echo htmlspecialchars($record['value']); ?></code></dd>
				<?php if ($record['note'] !== ''): ?>
				<dt>What it does</dt><dd><?php echo htmlspecialchars($record['note']); ?></dd>
				<?php endif; ?>
			</dl>
			<?php endforeach; ?>
			<p class="sms-note">Some providers want the name without your domain on the end. This box goes away
				once the records are in place.</p>
		</section>
		<?php endif; ?>

		<?php if (!empty($site['mx_left_alone'])): $mx = $site['mx_left_alone']; ?>
		<section class="sms-dns sms-dns-optional">
			<h3>Your email stays where it is</h3>
			<p><?php echo htmlspecialchars($mx['name']); ?> already receives its email at
				<strong><?php echo htmlspecialchars(implode(', ', $mx['hosts'])); ?></strong>, so we have left that alone:
				the record that brings email to your new site is not in the list above, and we never add it for you.
				Until it is added, email sent to this domain does not reach your new site.</p>
			<p>Only if you want email for this domain to arrive on your new site instead: replace those records, where your
				domain is registered, with this one. Email stops arriving at the old place when you do.</p>
			<dl class="sms-facts">
				<dt>Type</dt><dd>MX</dd>
				<dt>Name</dt><dd><code><?php echo htmlspecialchars($mx['name']); ?></code></dd>
				<dt>Value</dt><dd><code><?php echo htmlspecialchars((int)$mx['priority'] . ' ' . $mx['value']); ?></code></dd>
			</dl>
		</section>
		<?php endif; ?>

		<?php if ($site['dmarc_record'] !== null): $d = $site['dmarc_record']; ?>
		<section class="sms-dns">
			<h3>Optional: a forgery policy for your domain</h3>
			<p>Your email works without this. Adding it tells other servers what to do with mail that pretends to
				be from you. Skip it if your domain already has a DMARC record.</p>
			<dl class="sms-facts">
				<dt>Type</dt><dd><?php echo htmlspecialchars($d['type']); ?></dd>
				<dt>Name</dt><dd><code><?php echo htmlspecialchars($d['name']); ?></code></dd>
				<dt>Value</dt><dd><code><?php echo htmlspecialchars($d['value']); ?></code></dd>
			</dl>
		</section>
		<?php endif; ?>

		<?php if ($site['aaaa_record'] !== null): $v6 = $site['aaaa_record']; ?>
		<section class="sms-dns">
			<h3>Optional: reach your site over IPv6</h3>
			<p>Your site works without this. Adding it lets visitors whose internet uses the newer IPv6 addresses
				reach your site directly.</p>
			<dl class="sms-facts">
				<dt>Type</dt><dd><?php echo htmlspecialchars($v6['type']); ?></dd>
				<dt>Name</dt><dd><code><?php echo htmlspecialchars($site['domain']); ?></code> (<?php echo $sms_short_name($site['domain']); ?>)</dd>
				<dt>Value</dt><dd><code><?php echo htmlspecialchars($v6['value']); ?></code></dd>
			</dl>
		</section>
		<?php endif; ?>

		<?php if ($site['status'] === 'done' && !$sms_off && $site['dns_record'] === null && empty($site['dns_pending'])): ?>
			<p><a href="<?php echo htmlspecialchars($site['url']); ?>" class="btn btn-primary">Visit your site</a></p>
		<?php endif; ?>
		<?php if (!empty($site['links'])): ?>
			<p class="sms-links">Manage:
			<?php $sep = ''; foreach ($site['links'] as $label => $href): ?><?php echo $sep; ?><a href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($label); ?></a><?php $sep = ' · '; endforeach; ?></p>
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
		<?php echo $availability_html; ?>
		<?php endif; ?>

		<?php if ($site['password_state'] === 'sealed' && ($site['dns_record'] !== null || !empty($site['dns_pending']))): ?>
			<?php // Shown once: not offered while there is nowhere to sign in with it. ?>
			<p class="sms-note">Your admin password is shown here, once, as soon as your domain points at
				your site. Until then there is nowhere to sign in with it.</p>
		<?php elseif ($site['password_state'] === 'sealed'): ?>
			<form method="post" action="/profile/server_manager">
				<input type="hidden" name="action" value="reveal_password">
				<input type="hidden" name="cvp_customer_cloud_provision_id" value="<?php echo (int)$site['id']; ?>">
				<button type="submit" class="btn btn-secondary">Show my admin password</button>
			</form>
			<p class="sms-note">Shown once, then we forget it. Your site asks you to choose a new one
				at first sign-in.</p>
		<?php elseif ($site['password_state'] === 'revealed'): ?>
			<p class="sms-note">Your admin password was shown once and we no longer have a copy. If you
				lost it, use the Forgot password link on your own site.</p>
		<?php endif; ?>

		<?php if ($site['removal'] !== ''): // letting go of a site on the buyer's own server ?>
			<p class="sms-remove"><?php echo PublicPage::action_button(
				$site['removal'] === SiteRemoval::KIND_STOP ? 'Stop managing and remove' : 'Remove',
				'/profile/server_manager', array(
					'hidden'  => array('action' => 'remove_site', 'cvp_customer_cloud_provision_id' => (int)$site['id']),
					'confirm' => $site['removal_question'],
					'class'   => 'btn btn-ghost btn-sm',
				)); ?></p>
		<?php endif; ?>

		<?php // A move is not offered on a suspended site; one already under way is still shown.
		if ($site['transfer'] !== null && !($sms_off && $site['transfer']['state'] === '')): $t = $site['transfer']; ?>
		<section class="sms-move<?php echo $t['state'] === '' ? ' sms-move-quiet' : ''; ?>" data-provision="<?php echo (int)$site['id']; ?>">
			<?php if ($t['state'] === ''): ?>
				<details class="sms-move-offer">
				<summary>Move this site to your own Linode account</summary>
				<p>Your site runs on its own server in our Linode account. It can move into a Linode account of your
					own — running, with the same address, nothing copied or rebuilt. Linode then bills you for the
					server, and we stop billing you for hosting. Your outgoing email and backups keep working through us.
					Email sent to your domain stops reaching your site when the move finishes: set up receiving of your
					own before then.</p>
				<button type="button" class="btn btn-secondary" data-do="request">Move to my own Linode account</button>
				</details>
			<?php else: ?>
			<h3>Move to your own Linode account</h3>
			<?php endif; ?>
			<?php if ($t['state'] === ''): ?>
			<?php elseif ($t['state'] === 'requested'): ?>
				<p><strong><?php echo htmlspecialchars($t['state_label']); ?>.</strong> We check your server first and
					email you the next step.</p>
			<?php elseif ($t['state'] === 'invited'): ?>
				<ol class="sms-steps">
					<li>Open a Linode account if you do not have one: <a href="<?php echo htmlspecialchars($t['signup_url']); ?>"
						rel="noopener" target="_blank">sign up at Linode</a>, and add a payment card to it.</li>
					<li>When it is ready, get your transfer code here. It works for 24 hours.</li>
				</ol>
				<button type="button" class="btn btn-primary" data-do="get_code">I'm ready — get my transfer code</button>
			<?php elseif ($t['state'] === 'code_issued'): ?>
				<p>Your transfer code is ready, valid until <strong><?php echo htmlspecialchars($t['code_expiry']); ?></strong>.
					Anyone with this code can take the server, so keep it to yourself.</p>
				<p class="sms-code" hidden><code data-code></code>
					<button type="button" class="btn btn-secondary" data-copy>Copy</button></p>
				<button type="button" class="btn btn-secondary" data-do="reveal_code">Show my transfer code</button>
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
					<li>Email sent to your domain no longer reaches your site through us: set up receiving of your own.</li>
					<li>Your outgoing email and backups carry on through us<?php if ($t['paid_until'] !== ''): ?>, paid until
						<?php echo htmlspecialchars($t['paid_until']); endif; ?>. See
						<a href="/profile/server_manager/services">Connected sites</a>.</li>
					<li>The server takes no password over SSH and holds no key of ours. To get in as root, reset the root
						password in Cloud Manager, sign in through the Lish console, and add your own SSH key.</li>
					<li>Reverse DNS for its address is now set in your own Cloud Manager.</li>
				</ul>
				<?php if ($t['managed']): ?>
				<p class="sms-note">We still look after this site: updates, backups and alerts.</p>
				<button type="button" class="btn btn-secondary" data-do="stop_managing"
					data-confirm="Stop our management of this site? We forget its agent and stop its updates, backups and alerts. Backups already stored with us are deleted after 90 days. Outbound email carries on until its paid-through date.">Stop managing this site</button>
				<?php else: ?>
				<p class="sms-note">We no longer manage this site.</p>
				<?php endif; ?>
			<?php else: ?>
				<p><strong><?php echo htmlspecialchars($t['state_label']); ?>.</strong></p>
			<?php endif; ?>
			<?php if ($t['cancelable']): ?>
				<button type="button" class="btn btn-secondary" data-do="cancel"
					data-confirm="Cancel the move? Your site stays with us as it is."
					data-confirm-label="Cancel the move" data-cancel-label="Keep moving">Cancel the move</button>
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

<?php // ---- Servers the buyer already runs (JoinedServers) -------------------- ?>
<?php if ($joined_servers || $joined_waiting): ?>
<section class="sms-joined">
	<h2>Servers you run</h2>
	<?php foreach ($joined_servers as $server): ?>
	<article class="sms-site">
		<h3><?php echo htmlspecialchars($server['name']); ?></h3>
		<dl class="sms-facts">
			<dt>Server</dt><dd>Your own. We do not host it or bill you for it.</dd>
			<dt>Management</dt>
			<dd><?php echo $server['connected'] ? 'Managed by us: updates, alerts and health checks' : 'Not connected'; ?></dd>
		</dl>
		<p class="sms-remove"><?php echo PublicPage::action_button('Stop managing and remove', '/profile/server_manager', array(
			'hidden'  => array('action' => 'let_go_server', 'mgn_managed_node_id' => (int)$server['id']),
			'confirm' => 'Stop our management of ' . $server['name'] . ' and remove it from this page? Your server keeps running and '
				. 'nothing on it is changed. To have it managed again later, add it from this page.',
			'class'   => 'btn btn-ghost btn-sm',
		)); ?></p>
	</article>
	<?php endforeach; ?>
	<?php foreach ($joined_waiting as $waiting_name): ?>
	<article class="sms-site">
		<h3><?php echo htmlspecialchars($waiting_name); ?></h3>
		<p class="sms-note">Added to your account, and waiting for one of us to approve it. Nothing more is needed from you.</p>
	</article>
	<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($claim_request !== null): // sent here by their own site's Management Node page ?>
<section class="sms-reveal">
	<h2>Add <?php echo htmlspecialchars($claim_request['name'] !== '' ? $claim_request['name'] : 'this server'); ?> to your account?</h2>
	<p>Your site asked to be managed from here. Adding it lists it on this page under your account<?php
		echo $claim_request['instant'] ? ', and its updates, alerts and health checks start at once.'
			: '. One of us then approves it, usually within a working day.'; ?>
		Nothing on your server changes, and you can stop our management again from this page.</p>
	<?php echo PublicPage::action_button('Add this server', '/profile/server_manager', array(
		'hidden' => array('action' => 'claim_server', 'fingerprint' => $claim_request['fingerprint']),
		'class'  => 'btn btn-primary',
	)); ?>
</section>
<?php elseif ($claim_missing): ?>
	<p class="sms-error">We could not find that request: it may have run out (a request lasts an hour). Press Connect again on
		your site's Management Node page.</p>
<?php endif; ?>

<?php if ($add_server_url !== ''): $add_host = (string)parse_url($add_server_url, PHP_URL_HOST); ?>
<section class="sms-reveal">
	<h2>Next: connect <?php echo htmlspecialchars($add_host); ?></h2>
	<p>Open its Management Node page (you sign in to your own site as its administrator), press <strong>Connect</strong>,
		then <strong>Add this server to my account there</strong>. Our address is already filled in.</p>
	<p><a class="btn btn-primary" href="<?php echo htmlspecialchars($add_server_url); ?>">Open <?php echo htmlspecialchars($add_host); ?></a></p>
</section>
<?php endif; ?>

<details class="sms-add-server">
	<summary>Add a server I already run</summary>
	<p>A Joinery site you installed yourself can be looked after from here: updates, alerts and health checks, with
		nothing of yours moved or copied. Enter its address and we send you to its own Management Node page, where one
		press connects it. You need to be an administrator of that site.</p>
	<?php
	$add_form = $page->getFormWriter('add_server_form', array('action' => '/profile/server_manager'));
	echo $add_form->begin_form();
	$add_form->hiddeninput('action', '', array('value' => 'add_server'));
	$add_form->textinput('server_address', 'Your site\'s address', array('placeholder' => 'example.com', 'required' => true));
	$add_form->submitbutton('btn_add_server', 'Continue on my site', array('class' => 'btn btn-secondary'));
	echo $add_form->end_form();
	?>
</details>

<?php
// Where a buyer gets help: the support chat, with a person behind it, and the
// address to write to (hosting_after_purchase H1).
$chat_on = class_exists('SupportWidget') && SupportWidget::enabled();
if ($chat_on || $support_email !== ''): ?>
	<p class="sms-note">Questions?
		<?php if ($chat_on): ?><a href="/support_desk/ask?from=<?php echo urlencode(count($sites) === 1 ? SmHosting::support_route((int)array_values($sites)[0]['id']) : '/profile/server_manager'); ?>">Ask us</a><?php echo $support_email !== '' ? ', or write to ' : ''; else: ?>Write to <?php endif; ?>
		<?php if ($support_email !== ''): ?><a href="mailto:<?php echo htmlspecialchars($support_email); ?>"><?php echo htmlspecialchars($support_email); ?></a><?php endif; ?>.</p>
<?php endif; ?>

<?php if (!$needs_connect && $account_connected): ?>
	<p class="sms-note">Your cloud account is connected.
		<a href="/profile/server_manager/connect_cloud">Manage the connection</a></p>
<?php endif; ?>

<style>
.sms-error { padding: 12px 16px; background: var(--jy-color-danger-bg); border-radius: 4px; }
.sms-reveal { padding: 16px; margin: 1em 0 1.5em; background: var(--jy-color-warning-bg); border: 1px solid var(--jy-color-warning); border-radius: 6px; }
.sms-password code { max-width: 100%; box-sizing: border-box; overflow-wrap: anywhere; font-size: 1.4em; letter-spacing: .05em; background: var(--jy-color-bg); padding: 8px 14px;
	border: 1px solid var(--jy-color-border); border-radius: 4px; display: inline-block; }
.sms-site { padding: 16px 0; border-bottom: 1px solid var(--jy-color-border); }
.sms-facts { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: .5em 0 1em; }
.sms-facts dt { font-weight: 600; }
.sms-add-server { margin: 1.5em 0; }
.sms-add-server summary { cursor: pointer; color: var(--jy-color-text-muted, inherit); }
.sms-remove { margin: .5em 0 0; }
.sms-facts dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
.sms-facts code { overflow-wrap: anywhere; }
.sms-copy { margin-left: .5em; padding: 0 .5em; font-size: .8em; background: none; border: 1px solid var(--jy-color-border); border-radius: 4px; color: var(--jy-color-text-muted, inherit); cursor: pointer; }
.sms-links { font-size: .95em; }
.sms-note { color: var(--jy-color-text-muted); font-size: .9em; }
.sms-connect { padding: 12px 16px; margin: 1em 0; background: var(--jy-color-warning-bg); border: 1px solid var(--jy-color-warning); border-radius: 6px; }
.sms-actions form, .sms-pay { display: inline-block; margin-right: .5em; }
.sms-actions .btn { margin-right: .5em; }
.sms-move { padding: 12px 16px; margin: 1em 0; background: var(--jy-color-surface); border: 1px solid var(--jy-color-border); border-radius: 6px; }
.sms-move-quiet { padding: 0; background: none; border: 0; }
.sms-move-offer summary { cursor: pointer; color: var(--jy-color-text-muted, inherit); font-size: .95em; }
.sms-move-offer[open] summary { margin-bottom: .5em; }
.sms-move h3 { margin: 0 0 .5em; font-size: 1.05em; }
.sms-steps { margin: .5em 0 1em; padding-left: 1.4em; }
.sms-code code { font-size: 1.1em; background: var(--jy-color-bg); padding: 6px 10px; border: 1px solid var(--jy-color-border);
	border-radius: 4px; display: inline-block; word-break: break-all; }
.sms-move-notice:empty { display: none; }
.sms-move-notice { color: var(--jy-color-danger); }
.sms-taken { padding: 12px 16px; margin: 1em 0; background: var(--jy-color-warning-bg); border: 1px solid var(--jy-color-warning); border-radius: 6px; }
#alternate_domain_status[data-state="available"] { color: var(--jy-color-success); }
#alternate_domain_status[data-state="unavailable"] { color: var(--jy-color-danger); }
</style>

<?php echo PageScript::src('plugins/server_manager/assets/js/profile-move.js'); ?>
<?php echo PageScript::src('plugins/server_manager/assets/js/profile-copy.js'); ?>

<?php if ($in_progress && $revealed === ''): ?>
<?php /* Follow setup progress while a site is mid-pipeline. Never while a one-time
         password is on the screen: the reload would take it away for good. */ ?>
<span hidden data-jy-reload-after="30"></span>
<?php endif; ?>

<?php
echo '</div>';
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
