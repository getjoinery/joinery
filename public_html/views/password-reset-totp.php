<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('password_reset_totp_logic.php', 'logic'));

    $page_vars = process_logic(password_reset_totp_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Reset with Authenticator',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Reset with your authenticator</h3>
        <p class="text-muted jy-auth-lead">Enter your email and a current code from your authenticator app. A current backup code works too.</p>

        <?php if (!empty($page_vars['error'])): ?>
            <?php echo PublicPage::alert('Try again', $page_vars['error'], 'warn'); ?>
        <?php endif; ?>

        <?php
        $formwriter = $page->getFormWriter('form1', ['action' => '/password-reset-totp', 'method' => 'POST']);
        $formwriter->begin_form();
        $formwriter->textinput('email', 'Email address', [
            'type'         => 'email',
            'required'     => true,
            'maxlength'    => 64,
            'autocomplete' => 'email',
            'value'        => $page_vars['email'] ?? '',
        ]);
        $formwriter->textinput('totp_code', 'Authenticator code', [
            'required'     => true,
            'autocomplete' => 'one-time-code',
            'placeholder'  => '6-digit or backup code',
        ]);
        ?>
            <div class="jy-form-actions jy-mt-2">
                <?php $formwriter->submitbutton('btn_submit', 'Reset Password', ['class' => 'btn btn-primary']); ?>
            </div>
        <?php $formwriter->end_form(); ?>

        <div class="auth-footer-text">
            <a href="/password-reset-1">Other ways to reset</a>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['header_only' => true]);
?>
