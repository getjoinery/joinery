<?php
    require_once(PathHelper::getThemeFilePath('register_logic.php', 'logic'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

    $page_vars = register_logic($_GET, $_POST);
    if ($page_vars->redirect) {
        LibraryFunctions::redirect($page_vars->redirect);
        exit();
    }
    $page_vars = $page_vars->data;

    $extra = '';
    if (isset($_GET['m'])) {
        $extra = '?m=' . htmlspecialchars($_GET['m']);
    }

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Register',
        'header_only'   => true,
    ]);

    $settings = Globalvars::get_instance();
    $nickname_display = $settings->get_setting('nickname_display_as');

    $formwriter = $page->getFormWriter('form1', [
        'action' => '/register',
    ]);

    $validation_rules = array();
    $validation_rules['usr_first_name']['required']['value'] = 'true';
    $validation_rules['usr_first_name']['minlength']['value'] = 1;
    $validation_rules['usr_first_name']['maxlength']['value'] = 32;
    $validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
    $validation_rules['usr_last_name']['required']['value'] = 'true';
    $validation_rules['usr_last_name']['minlength']['value'] = 2;
    $validation_rules['usr_last_name']['maxlength']['value'] = 32;
    if ($nickname_display) {
        $validation_rules['usr_nickname']['maxlength']['value'] = 32;
    }
    $validation_rules['usr_email']['required']['value'] = 'true';
    $validation_rules['usr_email']['email']['value'] = 'true';
    $validation_rules['usr_email']['maxlength']['value'] = 64;
    $validation_rules['usr_email']['remote']['value'] = "'/ajax/email_check_ajax'";
    $validation_rules['usr_email']['remote']['message'] = "'This email already exists.'";
    $validation_rules['password']['required']['value'] = 'true';
    $validation_rules['password']['minlength']['value'] = 5;
    $validation_rules['password']['minlength']['message'] = "'Password must be at least {0} characters'";
    $validation_rules['privacy']['required']['value'] = 'true';
    $validation_rules = $formwriter->antispam_question_validate($validation_rules);
?>

<div class="auth-page">
    <div class="auth-card" style="max-width: 540px;">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Register for an Account</h3>

        <?php
        if (isset($_GET['msgtext']) && array_key_exists($_GET['msgtext'], $page_vars['LOGIN_MESSAGES'])) {
            echo PublicPage::alert('Login warning', htmlspecialchars($page_vars['LOGIN_MESSAGES'][$_GET['msgtext']]), 'warn');
        }

        $formwriter->begin_form();
        $formwriter->hiddeninput('prevformname', 'register');
        ?>

        <div class="row g-3">
            <div class="col-md-6">
                <?php $formwriter->textinput('usr_first_name', 'First Name:', [
                    'value'     => @$form_fields->usr_first_name,
                    'maxlength' => 32,
                ]); ?>
            </div>
            <div class="col-md-6">
                <?php $formwriter->textinput('usr_last_name', 'Last Name:', [
                    'value'     => @$form_fields->usr_last_name,
                    'maxlength' => 32,
                ]); ?>
            </div>

            <?php if ($nickname_display): ?>
            <div class="col-12">
                <?php $formwriter->textinput('usr_nickname', htmlspecialchars($nickname_display) . ':', [
                    'value'     => @$form_fields->usr_nickname,
                    'maxlength' => 32,
                ]); ?>
            </div>
            <?php endif; ?>

            <div class="col-12">
                <?php $formwriter->textinput('usr_email', 'Email Address:', [
                    'type'      => 'email',
                    'maxlength' => 64,
                ]); ?>
            </div>

            <div class="col-12">
                <?php $formwriter->passwordinput('password', 'Choose Password:', [
                    'maxlength' => 255,
                ]); ?>
            </div>

            <div class="col-12">
                <?php
                $optionvals = Address::get_timezone_drop_array();
                $default_timezone = $settings->get_setting('default_timezone');
                $formwriter->dropinput('usr_timezone', 'Timezone:', [
                    'options' => $optionvals,
                    'value'   => $default_timezone,
                ]);
                ?>
            </div>

            <div class="col-12">
                <?php $formwriter->antispam_question_input(); ?>
            </div>

            <div class="col-12">
                <?php $formwriter->checkboxinput('privacy', "I have read and agree to the <a href='/privacy' target='_blank'>privacy policy</a>", [
                    'value' => 'yes',
                ]); ?>
            </div>
            <div class="col-12">
                <?php $formwriter->checkboxinput('newsletter', 'Please add me to the mailing list', [
                    'value' => 'yes',
                ]); ?>
            </div>
            <div class="col-12">
                <?php $formwriter->checkboxinput('setcookie', 'Keep me logged in', [
                    'value'   => 'yes',
                    'checked' => true,
                ]); ?>
            </div>

            <div class="col-12">
                <?php
                $formwriter->honeypot_hidden_input();
                $formwriter->captcha_hidden_input();
                ?>
            </div>

            <div class="col-12">
                <?php $formwriter->submitbutton('submit', 'Register Now', ['class' => 'btn btn-primary btn-block']); ?>
            </div>
        </div>

        <?php $formwriter->end_form(true); ?>

        <div class="auth-footer-text">
            Already have an account? <a href="/login<?php echo $extra; ?>">Login to your Account</a>
        </div>

    </div>
</div>

<?php
    $page->public_footer(['header_only' => true, 'track' => true]);
?>
