<?php
    require_once(PathHelper::getThemeFilePath('register_logic.php', 'logic'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

    $page_vars = process_logic(register_logic(array_merge($_GET, $_POST, $params ?? [])));
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

    $formwriter = $page->getFormWriter('form1', [
        'action' => '/register',
    ]);

    $formwriter->antispam_question_validate([]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card jy-auth-card-wide">

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

        // Web-only bot defences — these never appear in the shared builder
        $formwriter->antispam_question_input();
        $formwriter->honeypot_hidden_input();
        $formwriter->captcha_hidden_input();

        // Shared form definition — also serves GET /api/v1/form/register
        register_logic_form($formwriter, null, array_merge($_GET, $_POST));

        $formwriter->end_form(); ?>

        <div class="auth-footer-text">
            Already have an account? <a href="/login<?php echo $extra; ?>">Login to your Account</a>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['header_only' => true, 'track' => true]);
?>
