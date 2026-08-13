<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('verify_totp_logic.php', 'logic'));

    $page_vars = process_logic(verify_totp_logic(array_merge($_GET, $_POST, $params ?? [])));

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Confirm it\'s you',
        'header_only'   => true,
    ]);
    $has_totp    = !empty($page_vars['has_totp']);
    $has_passkey = !empty($page_vars['has_passkey']);
    $trust_days  = (int)($page_vars['trust_days'] ?? 0);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Confirm it&rsquo;s you</h3>
        <?php if ($has_totp): ?>
            <p>Enter the 6-digit code from your authenticator app, or an 8-character backup code.</p>
        <?php else: ?>
            <p>Confirm your identity with your passkey to finish signing in.</p>
        <?php endif; ?>

        <?php
        // This page shows both the sign-in slot and the page-top slot.
        echo $page->render_messages('loginbox');
        echo $page->render_messages('topbox');

        if ($has_totp) {
            $formwriter = $page->getFormWriter('form1', ['action' => '/verify-totp', 'method' => 'POST']);
            $formwriter->begin_form();

            $formwriter->textinput('totp_code', 'Code', [
                'required'     => true,
                'autocomplete' => 'one-time-code',
                'inputmode'    => 'text',
                'autofocus'    => true,
            ]);
            if ($trust_days > 0) {
                $formwriter->checkboxinput('trust_device', 'Trust this device for ' . $trust_days . ' days', [
                    'id'      => 'trust_device',
                    'checked' => true,
                ]);
            }
            ?>
            <div class="jy-form-actions">
                <?php $formwriter->submitbutton('verify-form-submit', 'Verify', ['class' => 'btn btn-primary']); ?>
            </div>
            <?php
            $formwriter->end_form();
        }
        ?>

        <?php if ($has_passkey): ?>
            <?php if ($has_totp): ?><p class="jy-auth-hint jy-mt-2">or</p><?php endif; ?>
            <?php if (!$has_totp && $trust_days > 0): ?>
                <label class="jy-block jy-mt-2">
                    <input type="checkbox" id="trust_device" checked>
                    Trust this device for <?php echo $trust_days; ?> days
                </label>
            <?php endif; ?>
            <div class="jy-mt-2">
                <button type="button" class="btn btn-secondary jy-w-full" id="login-2fa-passkey-btn">Use a passkey</button>
            </div>
        <?php endif; ?>

        <div class="auth-links jy-mt-2">
            <a href="/logout">Cancel</a>
        </div>

        <?php if ($has_totp): ?>
        <div class="auth-footer-text jy-auth-hint">
            Lost access to your authenticator?
            Use one of your backup codes, or contact your administrator to reset 2FA.
        </div>
        <?php endif; ?>

    </div>
</div>
</div>

<?php if ($has_passkey): ?>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) return;
    var btn = document.getElementById('login-2fa-passkey-btn');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        btn.disabled = true;
        try {
            var trustEl = document.getElementById('trust_device');
            var data = await JoineryPasskeys.runFlow(
                '/api/v1/action/login_2fa_passkey_options',
                '/api/v1/action/login_2fa_passkey_verify',
                (trustEl && trustEl.checked) ? { trust_device: 1 } : {}
            );
            window.location.href = data.redirect || '/profile';
        } catch (e) {
            alert(e.message || 'Passkey verification was not completed.');
            btn.disabled = false;
        }
    });
});
</script>
<?php endif; ?>

<?php
    $page->public_footer(['header_only' => true]);
?>
