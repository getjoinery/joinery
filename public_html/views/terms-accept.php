<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('terms_accept_logic.php', 'logic'));

    $page_vars = process_logic(terms_accept_logic(array_merge($_GET, $_POST, $params ?? [])));
    $settings  = Globalvars::get_instance();

    $terms_url   = trim((string)$settings->get_setting('terms_url'));
    $privacy_url = trim((string)$settings->get_setting('privacy_url'));

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => true,
        'title'         => 'Accept Terms',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>One More Step</h3>

        <?php
        $terms_link   = $terms_url   !== '' ? '<a href="' . htmlspecialchars($terms_url,   ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Terms of Use</a>'   : 'Terms of Use';
        $privacy_link = $privacy_url !== '' ? '<a href="' . htmlspecialchars($privacy_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Privacy Policy</a>' : 'Privacy Policy';
        ?>
        <p style="margin-bottom: 1.25rem;">
            Before continuing, please confirm that you agree to our <?php echo $terms_link; ?> and <?php echo $privacy_link; ?>.
        </p>

        <?php
        $formwriter = $page->getFormWriter('form1', ['action' => '/terms-accept', 'method' => 'POST']);
        $formwriter->begin_form();

        echo $formwriter->checkboxinput('accept_terms', 'I agree to the ' . $terms_link . ' and ' . $privacy_link . '.', [
            'required' => true,
        ]);
        ?>

        <div style="margin-top: 1.25rem;">
            <?php echo $formwriter->submitbutton('btn_submit', 'Continue', ['class' => 'btn btn-primary']); ?>
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
