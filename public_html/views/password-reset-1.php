<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('password_reset_1_logic.php', 'logic'));

    $page_vars = process_logic(password_reset_1_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Password Reset',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <?php if (!empty($page_vars['message'])): ?>

            <div class="text-center">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#198754" stroke-width="1.5" class="jy-auth-success-icon" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <h3>Check Your Email!</h3>
                <p class="text-muted">An email has been sent to you. Please click on the included link to reset your password.</p>
                <a href="/login" class="btn btn-primary">Return to Login</a>
            </div>

        <?php else: ?>

            <h3>Reset Password</h3>
            <p class="text-muted jy-auth-lead">Enter your email address and we'll send you a link to reset your password.</p>

            <?php
            $formwriter = $page->getFormWriter('form1', ['action' => '/password-reset-1', 'method' => 'POST']);
            $formwriter->begin_form();

            // Shared form definition — also serves GET /api/v1/form/password_reset_1
            password_reset_1_logic_form($formwriter, null, array_merge($_GET, $_POST));

            $formwriter->end_form(); ?>

            <div class="jy-auth-hint jy-mt-2">If your login email is one of your own hosted mailboxes, the link also goes to your verified recovery address.</div>

            <?php $passkeys_enabled = $page_vars['settings']->get_setting('passkeys_enabled'); ?>
            <?php if ($passkeys_enabled): ?>
            <div class="jy-mt-4">
                <p class="jy-auth-hint">Other ways to reset:</p>
                <button type="button" class="btn btn-secondary jy-w-full" id="pwreset-passkey-btn">Reset with your passkey</button>
            </div>
            <?php endif; ?>
            <div class="jy-mt-2">
                <a href="/password-reset-totp">Reset with your authenticator app</a>
            </div>

            <div class="auth-footer-text">
                Remember your password? <a href="/login">Login to your Account</a>
            </div>

        <?php endif; ?>

    </div>
</div>
</div>

<?php if (empty($page_vars['message']) && $page_vars['settings']->get_setting('passkeys_enabled')): ?>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) return;
    var btn = document.getElementById('pwreset-passkey-btn');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        btn.disabled = true;
        try {
            var emailField = document.querySelector('input[name="usr_email"]');
            var email = emailField ? emailField.value.trim() : '';

            // Vault holders finish the second factor first; everyone else goes
            // straight to setting a new password.
            var data = await JoineryPasskeys.runFlow(
                '/api/v1/action/password_reset_passkey_options',
                '/api/v1/action/password_reset_passkey_verify',
                { email: email }
            );
            window.location.href = data.redirect || '/password-reset-2';
        } catch (e) {
            alert(e.message || 'Passkey reset was not completed.');
            btn.disabled = false;
        }
    });
});
</script>
<?php endif; ?>

<?php
    $page->public_footer(['track' => true, 'header_only' => true]);
?>
