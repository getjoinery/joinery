<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('login_logic.php', 'logic'));

    $page_vars = process_logic(login_logic($_GET, $_POST));
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

        <div class="d-flex justify-content-between align-items-center" style="margin-top: 1rem;">
            <?php $formwriter->submitbutton('login-form-submit', 'Login', ['class' => 'btn btn-primary']); ?>
            <a href="<?php echo $forgot_link; ?>">Forgot Password?</a>
        </div>

        <?php $formwriter->end_form(); ?>

        <div class="auth-footer-text">
            Don't have an account yet?
            <a href="/register<?php if (isset($_GET['m'])) { echo '?m=' . htmlspecialchars($_GET['m']); } ?>">Register for an Account</a>
        </div>

    </div>
</div>

<?php
    $page->public_footer(['header_only' => true]);
?>
