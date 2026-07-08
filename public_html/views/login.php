<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('login_logic.php', 'logic'));

    $page_vars = process_logic(login_logic(array_merge($_GET, $_POST, $params ?? [])));
    $settings = $page_vars['settings'];
    $email = $page_vars['email'] ?? null;

    if ($email) {
        $forgot_link = '/password-reset-1?e=' . rawurlencode(htmlspecialchars($email));
    } else {
        $forgot_link = '/password-reset-1';
    }

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Log In',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Login to your Account</h3>

        <?php
        foreach ($page_vars['display_messages'] as $display_message) {
            if ($display_message->identifier == 'loginbox') {
                echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
            }
        }

        $formwriter = $page->getFormWriter('form1', ['action' => '/login', 'method' => 'POST']);
        $formwriter->begin_form();

        $formwriter->textinput('email', 'Email:', [
            'type'     => 'email',
            'required' => true,
        ]);

        $formwriter->passwordinput('password', 'Password:', [
            'required' => true,
        ]);

        $formwriter->checkboxinput('setcookie', 'Remember Me', [
            'value'         => 'yes',
            'checked_value' => 'yes',
        ]);
        ?>

        <div class="jy-form-actions">
            <?php $formwriter->submitbutton('login-form-submit', 'Login', ['class' => 'btn btn-primary']); ?>
        </div>
        <div class="auth-links">
            <a href="<?php echo $forgot_link; ?>">Forgot Password?</a>
        </div>

        <?php $formwriter->end_form(); ?>

        <?php if ($settings->get_setting('passkeys_enabled')): ?>
        <div class="jy-passkey-signin d-none" id="passkey-signin">
            <button type="button" class="btn btn-secondary jy-w-full" id="passkey-signin-btn">Sign in with a passkey</button>
        </div>
        <?php endif; ?>

        <div class="auth-footer-text">
            Don't have an account yet?
            <a href="/register<?php if (isset($_GET['m'])) { echo '?m=' . htmlspecialchars($_GET['m']); } ?>">Register for an Account</a>
        </div>

    </div>
</div>
</div>

<?php if ($settings->get_setting('passkeys_enabled')): ?>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) return;
    var wrap = document.getElementById('passkey-signin');
    var btn = document.getElementById('passkey-signin-btn');
    wrap.classList.remove('d-none');

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        try {
            var emailField = document.querySelector('input[name="email"]');
            var email = emailField ? emailField.value.trim() : '';

            var data = await JoineryPasskeys.runFlow(
                '/api/v1/action/passkey_login_options',
                '/api/v1/action/passkey_login_verify',
                { email: email }
            );
            window.location.href = data.redirect || '/profile';
        } catch (e) {
            alert(e.message || 'Passkey sign-in was not completed.');
            btn.disabled = false;
        }
    });
});
</script>
<?php endif; ?>

<?php
    $page->public_footer(['header_only' => true]);
?>
