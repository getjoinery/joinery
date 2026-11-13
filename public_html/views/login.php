<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('login_logic.php', 'logic'));

    $page_vars = process_logic(login_logic(array_merge($_GET, $_POST, $params ?? [])));
    $settings = $page_vars['settings'];
    $email = $page_vars['email'] ?? null;

    // The typed address never goes into a link: the reset page asks for it.
	$forgot_link = '/password-reset-1';

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
        echo $page->render_messages('loginbox');

        $formwriter = $page->getFormWriter('form1', ['action' => '/login', 'method' => 'POST']);
        $formwriter->begin_form();

        $formwriter->textinput('email', 'Email or username:', [
            'value'        => $email ?? '',
            'required'     => true,
            'autocomplete' => 'username',
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
<?php echo PageScript::src('assets/js/passkeys.js'), PageScript::src('assets/js/passkey-pages.js'); ?>
<?php endif; ?>

<?php
    $page->public_footer(['header_only' => true]);
?>
