<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('test_authenticator_logic.php', 'logic'));

	$page_vars = process_logic(test_authenticator_logic(array_merge($_GET, $_POST, $params ?? [])));

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Test Authenticator App',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Test Authenticator App</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li><a href="/profile/security">Security</a></li>
                            <li class="active">Test Authenticator App</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start('/profile/security'); ?>

            <div class="jy-panel jy-mt-4">

                <?php if ($page_vars['result'] === 'match'): ?>
                    <div class="jy-alert jy-alert-success">
                        <strong>That code matched.</strong>
                        Your app is the one this account expects. It has been used up — your app will show a fresh code shortly.
                    </div>
                <?php elseif ($page_vars['result'] === 'no_match'): ?>
                    <div class="jy-alert jy-alert-danger">
                        <strong>That code did not match.</strong>
                        Either the app is not the one enrolled here, or the phone's clock has drifted. Try one more code first —
                        if it also fails, turn the authenticator app off from
                        <a href="/profile/security">Security</a> and set it up again while you are signed in.
                    </div>
                <?php endif; ?>

                <p>Enter the 6-digit code your app is showing now. Nothing changes either way.</p>

                <?php if (!empty($page_vars['totp_enabled_time'])): ?>
                    <p><strong>Enrolled:</strong>
                        <?php echo htmlspecialchars(LibraryFunctions::convert_time($page_vars['totp_enabled_time'], 'UTC',
                            SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A T')); ?></p>
                <?php endif; ?>

                <?php
                $test_formwriter = $page->getFormWriter('test_totp_form', ['action' => '/profile/test-authenticator', 'method' => 'POST']);
                $test_formwriter->begin_form();
                $test_formwriter->hiddeninput('action', '', ['value' => 'test_totp']);
                $test_formwriter->textinput('totp_code', 'Code from your app', [
                    'required'     => true,
                    'maxlength'    => 6,
                    'autocomplete' => 'one-time-code',
                    'inputmode'    => 'numeric',
                    'pattern'      => '[0-9]{6}',
                    'placeholder'  => '6-digit code',
                    'autofocus'    => true,
                    'helptext'     => 'Backup codes are not accepted here — each one works only once, and spending one to test it destroys it.',
                ]);
                $test_formwriter->submitbutton('btn_test', 'Check Code', ['class' => 'btn btn-primary']);
                $test_formwriter->end_form();
                ?>

                <p class="jy-mt-3"><a href="/profile/security">Back to Security</a></p>

            </div>

            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
	$page->public_footer();
?>
