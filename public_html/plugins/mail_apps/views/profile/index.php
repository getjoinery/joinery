<?php
/**
 * Member "Mail apps" settings section, mounted at /profile/mail_apps:
 * "Use Thunderbird or another mail program."
 *
 * The person names a device, gets an app password shown once, and copies the
 * settings into their mail program. Their mailboxes are listed with whether a
 * mail program can open each, and every app password with when and where it
 * was last used, each revocable. When a mailbox is Private the page says what
 * that costs before the first password is made.
 *
 * Making a password calls core's app_password_create (which asks the person
 * to confirm it is them), revoking calls app_credential_revoke.
 *
 * @version 1.1.0 - Your app passwords shows whenever the person has any, mail apps on or off, so a password can
 *                  be revoked while an administrator has mail apps off (it works again when they are turned back on)
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mail_apps/logic/mail_apps_page_logic.php'));

$page_vars = process_logic(mail_apps_page_logic(array_merge($_GET, $_POST, $params ?? array())));
$tz = $page_vars['session']->get_timezone();
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5); };

$page = new PublicPage();
$page->public_header(array('title' => 'Mail apps'));
$asset = PathHelper::getIncludePath('plugins/mail_apps/assets/mail_apps.js');
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<div class="jy-settings-shell">

			<div class="jy-page-header">
				<div class="jy-page-header-bar">
					<h1>Mail apps</h1>
					<nav class="jy-breadcrumbs" aria-label="breadcrumb">
						<ol>
							<li><a href="/">Home</a></li>
							<li><a href="/profile">Dashboard</a></li>
							<li class="active">Mail apps</li>
						</ol>
					</nav>
				</div>
			</div>

			<?php echo PublicPage::settings_layout_start(); ?>

			<div class="jy-panel">
				<h2>Use Thunderbird or another mail program</h2>
			<?php if (!$page_vars['enabled']): ?>
				<p class="jy-muted">Mail programs are not turned on for this site. An administrator can turn them on.</p>
			<?php elseif (!$page_vars['mailboxes']): ?>
				<p class="jy-muted">No mailboxes are assigned to your account, so there is nothing for a mail program to open yet.</p>
			<?php else: ?>
				<p class="jy-muted">Read and send your mail from Thunderbird, Apple Mail, or any program that speaks IMAP
					and SMTP. Each computer or phone gets its own app password, which you can revoke here at any time.</p>
				<table class="jy-table jy-w-full">
					<thead><tr><th>Mailbox</th><th>Protection</th><th>In a mail program</th></tr></thead>
					<tbody>
					<?php foreach ($page_vars['mailboxes'] as $mb): ?>
						<tr>
							<td><?php echo $esc($mb['address']); ?></td>
							<td><?php echo $esc($mb['level']); ?></td>
							<td><?php echo $mb['offered'] ? 'Yes' : 'No'; ?><?php if ($mb['note'] !== ''): ?>
								<br><span class="jy-muted"><?php echo $esc($mb['note']); ?></span><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			</div>

			<?php if ($page_vars['enabled'] && $page_vars['any_offered']): ?>

			<?php if ($page_vars['private_domains']): ?>
			<div class="jy-panel" data-mail-apps-warning>
				<h2>Before you add a mail app</h2>
				<p><strong>While a mail app is checking your mail, your mail stays unlocked on the server</strong> —
					for up to 7 days after you last unlocked on the website<?php
					if ($page_vars['hardened_domains']) {
						echo ' (24 hours on ' . $esc(implode(', ', $page_vars['hardened_domains'])) . ')';
					} ?>. That is the same as staying signed in to the website the whole time. A stolen copy of the
					database or a backup still can't be read.</p>
				<p><strong>Your mail app also keeps its own copy of your mail on this computer.</strong> Anyone who can
					open this computer's files can read it. Turn on disk encryption if you haven't.</p>
				<p class="jy-muted">While your vault is locked, your mail program keeps showing the mail it already
					has, new mail waits, and its Inbox shows one notice with a link to unlock.</p>
			</div>
			<?php endif; ?>

			<div class="jy-panel jy-form-actions">
				<h2>Add a mail app</h2>
				<p class="jy-muted">Name the computer or phone, then copy the password into your mail program. It is
					shown once.</p>
				<?php
				$formwriter = $page->getFormWriter('mail-apps-add', array('action' => '/profile/mail_apps'));
				echo $formwriter->begin_form();
				$formwriter->textinput('device_name', 'Device name', array(
					'placeholder' => 'Laptop',
					'maxlength'   => 32,
					'helptext'    => 'So you can tell your app passwords apart, and revoke the right one.',
				));
				$formwriter->submitbutton('make_password', 'Make an app password');
				echo $formwriter->end_form();
				?>
				<div class="jy-alert" role="status" data-mail-apps-result hidden></div>
				<div data-mail-apps-password hidden>
					<p>Your app password for <strong data-mail-apps-device></strong>:</p>
					<p><code class="jy-text-lg" data-mail-apps-secret></code>
						<button type="button" class="btn btn-secondary" data-mail-apps-copy>Copy</button></p>
					<p class="jy-muted">Type it with or without the spaces. It will not be shown again.</p>
				</div>
			</div>

			<div class="jy-panel">
				<h2>Settings for your mail program</h2>
				<p class="jy-muted">Thunderbird usually finds these by itself from your address. If it asks, enter:</p>
				<table class="jy-table jy-w-full">
					<tbody>
						<tr><th>Incoming</th><td>IMAP, <code><?php echo $esc($page_vars['host']); ?></code>, port 993, SSL/TLS, normal password</td></tr>
						<tr><th>Outgoing</th><td>SMTP, <code><?php echo $esc($page_vars['host']); ?></code>, port 465, SSL/TLS, normal password</td></tr>
						<tr><th>Username</th><td>your mailbox address, such as <code><?php
							$first = '';
							foreach ($page_vars['mailboxes'] as $mb) { if ($mb['offered']) { $first = $mb['address']; break; } }
							echo $esc($first); ?></code></td></tr>
						<tr><th>Password</th><td>an app password from this page — one works for every mailbox you hold</td></tr>
					</tbody>
				</table>
			</div>

			<?php endif; ?>

			<?php $can_add = $page_vars['enabled'] && $page_vars['any_offered']; ?>
			<?php if ($can_add || $page_vars['credentials']): ?>
			<div class="jy-panel">
				<h2>Your app passwords</h2>
			<?php if (!$can_add): ?>
				<p class="jy-muted"><?php echo $page_vars['enabled']
					? 'None of your mailboxes can be opened in a mail program now, so these do not work.'
					: 'Mail programs are turned off for this site, so these do not work now.'; ?>
					They work again if that changes. Revoke any you no longer use.</p>
				<div class="jy-alert" role="status" data-mail-apps-result hidden></div>
			<?php endif; ?>
			<?php if (!$page_vars['credentials']): ?>
				<p class="jy-muted">None yet.</p>
			<?php else: ?>
				<table class="jy-table jy-w-full">
					<thead><tr><th>Device</th><th>Made</th><th>Last used</th><th></th></tr></thead>
					<tbody>
					<?php foreach ($page_vars['credentials'] as $c): ?>
						<tr>
							<td><?php echo $esc($c['name']); ?><?php if ($c['kind'] !== ''): ?>
								<br><span class="jy-muted"><?php echo $esc($c['kind']); ?></span><?php endif; ?></td>
							<td><?php echo $esc(LibraryFunctions::convert_time($c['created'], 'UTC', $tz, 'M j, Y')); ?></td>
							<td><?php echo $c['last_used']
								? $esc(LibraryFunctions::convert_time($c['last_used'], 'UTC', $tz, 'M j, Y g:i A'))
									. ($c['last_ip'] !== '' ? '<br><span class="jy-muted">from ' . $esc($c['last_ip']) . '</span>' : '')
								: 'Never'; ?></td>
							<td class="text-end"><button type="button" class="btn btn-danger"
								data-mail-apps-revoke="<?php echo intval($c['id']); ?>"
								data-mail-apps-name="<?php echo $esc($c['name']); ?>">Revoke</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php echo PublicPage::settings_layout_end(); ?>
		</div>
	</div>
</section>
</div>
<script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script src="/plugins/mail_apps/assets/mail_apps.js?v=<?php echo is_file($asset) ? filemtime($asset) : '1'; ?>"></script>
<?php
$page->public_footer();
?>
