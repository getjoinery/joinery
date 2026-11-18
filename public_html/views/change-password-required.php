<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('change_password_required_logic.php', 'logic'));

    $page_vars = process_logic(change_password_required_logic(array_merge($_GET, $_POST, $params ?? [])));
    $settings  = Globalvars::get_instance();

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => true,
        'title'         => 'Change Password Required',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Set New Password</h3>

        <div class="alert alert-warning jy-auth-note">
            <strong>Password Change Required</strong>
            <p class="jy-auth-subhead">Choose your own password before you continue. The one you signed in with was only for getting in.</p>
        </div>

        <?php
        $formwriter = $page->getFormWriter('form1', ['action' => '/change-password-required', 'method' => 'POST']);
        $formwriter->begin_form();
        echo $formwriter->passwordinput('new_password', 'New Password', [
            'required'     => true,
            'minlength'    => 8,
            'autocomplete' => 'new-password',
            'validation'   => [
                'required'  => true,
                'minlength' => 8,
                'messages'  => ['minlength' => 'Choose at least 8 characters'],
            ],
        ]);
        echo $formwriter->passwordinput('confirm_password', 'Confirm Password', [
            'required'     => true,
            'autocomplete' => 'new-password',
            'validation'   => [
                'matches'  => 'new_password',
                'messages' => ['matches' => 'Passwords do not match'],
            ],
        ]);
        ?>

        <div class="jy-auth-foot-spaced">
            <?php echo $formwriter->submitbutton('btn_submit', 'Change Password', ['class' => 'btn btn-primary']); ?>
        </div>

        <?php $formwriter->end_form(); ?>

        <?php if (!empty($page_vars['email_included_line'])) { ?>
        <div class="jy-auth-note">
            <p class="jy-auth-subhead"><?php echo $page_vars['email_included_line']; ?>
                Password reset is how you get back into this account, and it works once email does.</p>
        </div>
        <?php } elseif (!empty($page_vars['show_email_setup_hint'])) { ?>
        <div class="jy-auth-note">
            <p class="jy-auth-subhead">
                Once you are in, set up email at
                <a href="/admin/admin_settings_email">Settings &rarr; Email</a>.
                Password reset is how you get back into this account, and it needs
                a mail provider.
            </p>
        </div>
        <?php } ?>

        <div class="auth-footer-text">
            <a href="/logout">Log out</a>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['track' => true, 'header_only' => true]);
?>
