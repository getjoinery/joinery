<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('security_logic.php', 'logic'));

	$page_vars = process_logic(security_logic($_GET, $_POST));

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Security Settings',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div style="max-width: 720px; margin: 0 auto;">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Security Settings</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Security</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::tab_menu($page_vars['tab_menus'] ?? [], 'Security'); ?>

            <div class="jy-panel" style="margin-top: var(--jy-space-4);">

                <?php
                foreach ($page_vars['display_messages'] ?? [] as $display_message) {
                    if ($display_message->identifier == 'securitybox') {
                        echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
                    }
                }
                ?>

                <h2>Two-Factor Authentication</h2>

                <?php if (!empty($page_vars['just_enabled']) && !empty($page_vars['backup_codes'])): ?>
                    <div class="jy-alert jy-alert-success">
                        <strong>Two-factor authentication is now enabled.</strong>
                        Save the backup codes below — you'll need one if you lose access to your authenticator app. They're shown only once.
                    </div>

                    <h3>Backup codes</h3>
                    <p>Store these somewhere safe. Each can be used once.</p>
                    <pre style="background: #f5f5f5; padding: 1em; border-radius: 4px; font-size: 1.1em;"><?php
                        foreach ($page_vars['backup_codes'] as $code) {
                            echo htmlspecialchars($code) . "\n";
                        }
                    ?></pre>

                    <p><a href="/profile/security" class="btn btn-primary">Done</a></p>

                <?php elseif (!empty($page_vars['totp_enabled']) && !empty($page_vars['backup_codes'])): ?>
                    <div class="jy-alert jy-alert-success">
                        <strong>New backup codes generated.</strong>
                        Your previous backup codes are no longer valid.
                    </div>

                    <h3>Backup codes</h3>
                    <p>Store these somewhere safe. Each can be used once.</p>
                    <pre style="background: #f5f5f5; padding: 1em; border-radius: 4px; font-size: 1.1em;"><?php
                        foreach ($page_vars['backup_codes'] as $code) {
                            echo htmlspecialchars($code) . "\n";
                        }
                    ?></pre>

                    <p><a href="/profile/security" class="btn btn-primary">Done</a></p>

                <?php elseif (!empty($page_vars['totp_enabled'])): ?>
                    <p><strong>Status:</strong> Enabled
                    <?php if (!empty($page_vars['totp_enabled_time'])): ?>
                        (since <?php echo htmlspecialchars(LibraryFunctions::convert_time($page_vars['totp_enabled_time'], 'UTC',
                            SessionControl::get_instance()->get_timezone(), 'M j, Y')); ?>)
                    <?php endif; ?>
                    </p>

                    <h3>Backup codes</h3>
                    <p>Generate a fresh set of 10 single-use codes. This invalidates any previous codes.</p>
                    <form action="/profile/security" method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="regenerate_backup_codes">
                        <button type="submit" class="btn btn-secondary">Regenerate Backup Codes</button>
                    </form>

                    <h3 style="margin-top: var(--jy-space-4);">Disable 2FA</h3>
                    <p>Confirm with a current 6-digit code or an 8-character backup code. Disabling will also invalidate any trusted devices.</p>
                    <form action="/profile/security" method="POST" onsubmit="return confirm('Disable two-factor authentication for your account?');">
                        <input type="hidden" name="action" value="disable">
                        <input type="text" name="confirm_code" placeholder="6-digit or backup code" autocomplete="one-time-code" required>
                        <button type="submit" class="btn btn-danger">Disable 2FA</button>
                    </form>

                    <p style="margin-top: var(--jy-space-4); font-size: 0.9em; color: #666;">
                        <strong>Lost a trusted device?</strong> To revoke trusted-device cookies on other devices,
                        disable and re-enable 2FA — this rotates the device-trust key.
                    </p>

                <?php elseif (!empty($page_vars['setup_in_progress'])): ?>
                    <p>Scan this QR code with your authenticator app
                    (Google Authenticator, Authy, 1Password, etc.):</p>

                    <div style="text-align: center; margin: var(--jy-space-4) 0;">
                        <img src="<?php echo htmlspecialchars($page_vars['qr_uri']); ?>" alt="2FA setup QR code" style="max-width: 240px;">
                    </div>

                    <p>If you can't scan, enter this key manually:</p>
                    <pre style="background: #f5f5f5; padding: 1em; border-radius: 4px; word-break: break-all;"><?php echo htmlspecialchars($page_vars['secret']); ?></pre>

                    <p>Once added to your app, enter the current 6-digit code to confirm:</p>
                    <form action="/profile/security" method="POST">
                        <input type="hidden" name="action" value="confirm_enable">
                        <input type="text" name="totp_code" placeholder="6-digit code" autocomplete="one-time-code" inputmode="numeric" required autofocus>
                        <button type="submit" class="btn btn-primary">Confirm and Enable</button>
                    </form>

                    <form action="/profile/security" method="POST" style="margin-top: var(--jy-space-2);">
                        <input type="hidden" name="action" value="cancel_enable">
                        <button type="submit" class="btn btn-secondary">Cancel Setup</button>
                    </form>

                <?php else: ?>
                    <p><strong>Status:</strong> Not enabled</p>
                    <p>Two-factor authentication adds a second step when logging in: a 6-digit code from an authenticator app on your phone. This protects your account even if your password is compromised.</p>

                    <form action="/profile/security" method="POST">
                        <input type="hidden" name="action" value="start_enable">
                        <button type="submit" class="btn btn-primary">Enable Two-Factor Authentication</button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
