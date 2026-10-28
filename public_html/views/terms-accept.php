<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('terms_accept_logic.php', 'logic'));

    $page_vars = process_logic(terms_accept_logic(array_merge($_GET, $_POST, $params ?? [])));
    $owed = $page_vars['owed'];

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

        <p class="jy-auth-note">
            <?php if (!empty($owed['joinery'])): ?>
            getjoinery hosts this site. Before continuing, please confirm that you agree to <?php echo TermsOwed::linksHtml($owed); ?>.
            <?php else: ?>
            Before continuing, please confirm that you agree to <?php echo TermsOwed::linksHtml($owed); ?>.
            <?php endif; ?>
        </p>

        <?php
        $formwriter = $page->getFormWriter('form1', ['action' => '/terms-accept', 'method' => 'POST']);
        $formwriter->begin_form();

        // A label is text (FormWriter escapes it); the links are in the note above.
        echo $formwriter->checkboxinput('accept_terms', TermsOwed::labelText($owed), [
            'required' => true,
        ]);
        ?>

        <div class="jy-auth-foot-spaced">
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
