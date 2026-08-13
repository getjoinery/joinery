<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getIncludePath('logic/notification_preferences_logic.php'));

	$page_vars = process_logic(notification_preferences_logic(array_merge($_GET, $_POST)));

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Notifications',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Notifications</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li class="active">Notifications</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <div class="jy-panel jy-form-actions">
                <?php
                echo $page->render_messages('notifybox');

                $options = array();
                $sub_checked = array();
                $email_checked = array();
                foreach ($page_vars['signals'] as $name => $meta) {
                    $options[$name] = ($meta['category'] ?? 'Other') . ' — ' . ($meta['label'] ?? $name);
                    $pref = $page_vars['prefs'][$name] ?? null;
                    // No saved row means the signal's default: notify in-app,
                    // email if the signal declares default_email.
                    $subscribed = ($pref !== null) ? $pref['subscribed'] : true;
                    $email      = ($pref !== null) ? $pref['email'] : !empty($meta['notify']['default_email']);
                    if ($subscribed) { $sub_checked[] = $name; }
                    if ($email) { $email_checked[] = $name; }
                }
                asort($options);

                if (empty($options)) {
                    echo '<p>There are no adjustable notification types on this site yet.</p>';
                } else {
                    echo '<p>Choose which events notify you. Tick the same event under the email list to also get an email when it happens.</p>';
                    $formwriter = $page->getFormWriter('form1', [
                        'action' => '/profile/notification_preferences'
                    ]);
                    $formwriter->begin_form();
                    $formwriter->hiddeninput('action', ['value' => 'save']);
                    $formwriter->checkboxList('subscribe', 'Notify me about', [
                        'options' => $options,
                        'checked' => $sub_checked,
                    ]);
                    $formwriter->checkboxList('notify_email', 'Also email me about', [
                        'options' => $options,
                        'checked' => $email_checked,
                    ]);
                    $formwriter->submitbutton('submit', 'Save Preferences');
                    $formwriter->end_form();
                }
                ?>
            </div>
            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer();
?>
