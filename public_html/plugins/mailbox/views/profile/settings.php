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
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_settings_page_logic.php'));

$page_vars = process_logic(mailbox_settings_page_logic(array_merge($_GET, $_POST, $params ?? array())));

$page = new PublicPage();
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

			<div class="jy-panel jy-form-actions">
				<h2>More</h2>
				<ul class="mbx-settings-links">
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
<?php
$page->public_footer();
?>
