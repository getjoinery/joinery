<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('change_password_required_logic.php', 'logic'));

    $page_vars = process_logic(change_password_required_logic($_GET, $_POST));
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

        <div class="alert alert-warning" style="margin-bottom: 1.25rem;">
            <strong>Password Change Required</strong>
            <p style="margin: 0.5rem 0 0;">For security reasons, you must change your password before continuing. The default password should not be used in production.</p>
        </div>

        <?php
        $formwriter = $page->getFormWriter('form1', ['action' => '/change-password-required', 'method' => 'POST']);
        $formwriter->begin_form();
        echo $formwriter->passwordinput('new_password', 'New Password', [
            'required'     => true,
            'minlength'    => 8,
            'autocomplete' => 'new-password',
        ]);
        echo $formwriter->passwordinput('confirm_password', 'Confirm Password', [
            'required'         => true,
            'autocomplete'     => 'new-password',
            'data-rule-equalTo' => '#new_password',
            'data-msg-equalTo'  => 'Passwords do not match',
        ]);
        ?>

        <div style="margin-top: 1.25rem;">
            <?php echo $formwriter->submitbutton('btn_submit', 'Change Password', ['class' => 'btn btn-primary']); ?>
        </div>

        <?php $formwriter->end_form(); ?>

        <div class="auth-footer-text">
            <a href="/logout">Log out</a>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['track' => true, 'header_only' => true]);
?>
