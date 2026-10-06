<?php
/**
 * Member "Email" settings section, mounted at /profile/mailbox/settings.
 *
 * One section in the settings rail for everything a member sets up about their
 * mail, rather than a row apiece: the signature they sign with, written here,
 * and the way on to filters and to bringing old mail in.
 *
 * A signature lives on the grant, so there is one editor per mailbox the member
 * holds and a mailbox two people share carries a signature for each of them.
 * Saving posts to the mailbox/signature_save API action, which sanitizes the
 * HTML and writes the caller's own grant.
 *
 * Declared as the plugin's settingsMenu entry, and linked from the gear on the
 * mailbox itself.
 *
 * A member with an end-to-end encrypted (Fortress) mailbox also sets where
 * their own AI model answers ("Your AI model"), through the
 * mailbox/device_ai_host API action, which asks for a second-factor
 * confirmation. The key and model name are kept in the browser, and Test
 * sends the model a real-sized judgement from here.
 *
 * @version 2.6 - More links Contacts
 * @version 2.5 - "Your AI model" tests the model saved in this browser, and the page may
 *   reach the registered model's origin (CSP) to do it
 * @version 2.4 - "Your AI model": the site's model is the primary choice; the form takes the
 *   address, key and model name together and opens on request
 * @version 2.3 - "Your AI model" offers the site's own model with one click
 * @version 2.2 - "Your AI model" links the page "Using your own model"
 * @version 2.1 - "Your AI model" for members with a Fortress mailbox
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_settings_page_logic.php'));

$page_vars = process_logic(mailbox_settings_page_logic(array_merge($_GET, $_POST, $params ?? array())));

$page = new PublicPage();
// Test calls the member's own model from this page: the one origin they
// registered under a second-factor confirmation, and no other.
if (!empty($page_vars['has_fortress']) && !empty($page_vars['device_ai_origin'])) {
	$page->allow_connect_origin($page_vars['device_ai_origin']);
}
$page->public_header(array('title' => 'Email'));

$sig_asset = PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_signature.js');
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<div class="jy-settings-shell">

			<div class="jy-page-header">
				<div class="jy-page-header-bar">
					<h1>Email</h1>
					<nav class="jy-breadcrumbs" aria-label="breadcrumb">
						<ol>
							<li><a href="/">Home</a></li>
							<li><a href="/profile">Dashboard</a></li>
							<li class="active">Email</li>
						</ol>
					</nav>
				</div>
			</div>

			<?php echo PublicPage::settings_layout_start(); ?>

			<div class="jy-panel jy-form-actions">
				<h2>Signature</h2>
			<?php if (empty($page_vars['mailboxes'])): ?>
				<p class="jy-muted">No mailboxes are assigned to your account, so there is nothing to sign yet.</p>
			<?php else: ?>
				<p class="jy-muted">Added to the bottom of every new message you write from that mailbox.
					Replies and forwards keep the signature that is already in the draft.</p>
				<?php foreach ($page_vars['mailboxes'] as $mb): ?>
					<?php
					$fid = 'sig-form-' . intval($mb['alias_id']);
					$formwriter = $page->getFormWriter($fid, array('action' => '/profile/mailbox/settings'));
					echo $formwriter->begin_form();
					$formwriter->hiddeninput('alias_id', '', array('value' => intval($mb['alias_id'])));
					?>
					<div class="mbx-sig-card" data-alias-id="<?php echo intval($mb['alias_id']); ?>">
						<h3 class="mbx-sig-address"><?php echo htmlspecialchars($mb['address']); ?></h3>
						<div class="mbx-toolbar" data-sig-toolbar></div>
						<div class="mbx-rich mbx-sig-editor" contenteditable="true"
							aria-label="Signature for <?php echo htmlspecialchars($mb['address']); ?>"
							data-sig-editor><?php echo $mb['signature']; ?></div>
						<p class="mbx-sig-note" data-sig-note hidden></p>
					</div>
					<?php
					$formwriter->submitbutton('save_signature', 'Save signature');
					echo $formwriter->end_form();
					?>
				<?php endforeach; ?>
			<?php endif; ?>
			</div>

			<?php if (!empty($page_vars['has_fortress'])): ?>
			<div class="jy-panel jy-form-actions" id="your-model">
				<h2>Your AI model</h2>
				<p class="jy-muted">AI summaries and the security scan for your end-to-end encrypted mail run in
					your browser, against an AI model you choose: a service you have a key for, or one on a
					computer of your own. Your mail is sent from your browser to that model; Joinery never sees
					it.
					<a href="/documentation?doc=plugin/mailbox/using_your_own_model" target="_blank" rel="noopener">Using your own model</a></p>
				<?php
				$current = (string)($page_vars['device_ai_origin'] ?? '');
				$site = $page_vars['device_ai_site_model'] ?? null;
				$site_offered = is_array($site) && $site['origin'] !== $current;
				?>
				<?php if ($current !== ''): ?>
					<p>Your mail may be sent to <code><?php echo htmlspecialchars($current); ?></code>.
						<button type="button" class="btn btn-secondary" data-device-ai-test>Test</button>
						<button type="button" class="btn btn-secondary" data-device-ai-remove>Remove</button></p>
					<p class="mbx-sig-note" role="status" data-device-ai-test-note hidden></p>
				<?php endif; ?>
				<?php if ($site_offered): ?>
					<p class="jy-muted">This site runs its own model, <code><?php echo htmlspecialchars($site['model']); ?></code>
						at <code><?php echo htmlspecialchars($site['host']); ?></code>.
						<?php echo $site['operator'] ? 'It runs on hardware you operate.'
							: 'The operator of this site runs that machine and could see mail sent to it.'; ?></p>
					<p><button type="button" class="btn btn-primary" data-device-ai-use-site>Use this site's model</button>
						<button type="button" class="btn btn-secondary" data-device-ai-enter>Enter a different model</button></p>
				<?php elseif ($current !== ''): ?>
					<p><button type="button" class="btn btn-secondary" data-device-ai-enter>Change the model</button></p>
				<?php endif; ?>
				<div data-device-ai-form<?php echo ($site_offered || $current !== '') ? ' hidden' : ''; ?>>
					<p class="jy-muted">Choosing where your mail is sent asks you to confirm it is you. The key and the
						model name stay in this browser; on another computer, enter them again here.</p>
					<?php
					$formwriter = $page->getFormWriter('device-ai-host-form', array('action' => '/profile/mailbox/settings'));
					echo $formwriter->begin_form();
					$formwriter->textinput('device_ai_address', 'Your model\'s address', array(
						'value'       => $current,
						'placeholder' => 'https://api.fireworks.ai/inference/v1',
						'helptext'    => 'Paste the address your model\'s service gives you. The start of it, up to the host name, is kept with your account; the rest stays in this browser.',
					));
					$formwriter->passwordinput('device_ai_key', 'Key', array(
						'placeholder' => 'Leave empty if your model needs none',
						'helptext'    => 'Kept in this browser only. It never reaches Joinery.',
					));
					$formwriter->textinput('device_ai_model', 'Model name', array(
						'placeholder' => 'e.g. accounts/fireworks/models/… or llama3.1:8b',
						'helptext'    => 'Exactly as your service names it.',
					));
					?>
					<p class="mbx-sig-note" data-device-ai-note hidden></p>
					<?php
					$formwriter->submitbutton('save_device_ai', 'Save model');
					echo $formwriter->end_form();
					?>
				</div>
			</div>
			<?php endif; ?>

			<div class="jy-panel jy-form-actions">
				<h2>More</h2>
				<ul class="mbx-settings-links">
					<li>
						<a href="/profile/mailbox/contacts">Contacts</a>
						<span class="jy-muted">The people you keep for each mailbox — add, import, change or
							remove them.</span>
					</li>
					<li>
						<a href="/profile/mailbox/filters">Filters</a>
						<span class="jy-muted">Rules that act on mail as it arrives — label it, archive it,
							send it to spam, forward it on.</span>
					</li>
					<?php if (!empty($page_vars['import_enabled'])): ?>
					<li>
						<a href="/profile/mailbox/import">Import old mail</a>
						<span class="jy-muted">Bring in a Proton export, a Gmail Takeout, or an mbox from
							another provider.</span>
					</li>
					<?php endif; ?>
					<li>
						<a href="/profile/mailbox/mailbox">Go to your mail</a>
						<span class="jy-muted">Read and write, with these settings in force.</span>
					</li>
				</ul>
			</div>

			<?php echo PublicPage::settings_layout_end(); ?>
		</div>
	</div>
</section>
</div>
<script src="/plugins/mailbox/assets/mailbox_signature.js?v=<?php echo is_file($sig_asset) ? filemtime($sig_asset) : '1'; ?>"></script>
<?php if (!empty($page_vars['has_fortress'])): ?>
<script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script>window.MAILBOX_DEVICE_AI = <?php echo json_encode(array('origin' => ($page_vars['device_ai_origin'] ?? null) ?: null, 'user_id' => (int)$page_vars['session']->get_user_id(), 'site_model' => $page_vars['device_ai_site_model'] ?? null)); ?>;</script>
<?php $dai_panel = PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_device_ai.js'); ?>
<script src="/plugins/mailbox/assets/mailbox_device_ai.js?v=<?php echo is_file($dai_panel) ? filemtime($dai_panel) : '1'; ?>"></script>
<?php endif; ?>
<?php
$page->public_footer();
?>
