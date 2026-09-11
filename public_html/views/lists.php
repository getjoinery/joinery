<?php
/**
 * /lists — every public mailing list, with one form to subscribe to any of them.
 *
 * Markup is the .jy-ui kit and every field comes from FormWriter, so the page
 * carries a theme's own look without knowing which theme is active.
 *
 * @version 2.0.0
 */

    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('lists_logic.php', 'logic'));

    $page_vars = process_logic(lists_logic(array_merge($_GET, $_POST, $params ?? [])));
    $messages             = $page_vars['messages'] ?? [];
    $session              = $page_vars['session'];
    $mailing_lists        = $page_vars['mailing_lists'];
    $numlists             = $page_vars['numlists'];
    $user_subscribed_list = $page_vars['user_subscribed_list'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
    ]);
    echo PublicPage::BeginPage('Newsletter Lists', ['subtitle' => 'Get updates from us.']);

    $settings = Globalvars::get_instance();
    $is_logged_in = (bool)$session->get_user_id();
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <?php
        foreach ($messages as $message) {
            echo PublicPage::alert($message['message_title'], $message['message'], $message['message_type']);
        }
        ?>

        <?php if ($numlists == 0): ?>

            <div class="jy-panel">
                <h2>No mailing lists available</h2>
                <p>There are currently no mailing lists to sign up for. Please check back later.</p>
            </div>

        <?php else: ?>

            <div class="jy-panel">
                <?php
                $formwriter = $page->getFormWriter('form1', ['action' => '/lists']);
                $formwriter->antispam_question_validate([]);
                $formwriter->begin_form();

                if (!$is_logged_in) {
                    echo '<h2>Your details</h2>';

                    $formwriter->textinput('usr_first_name', 'First Name', [
                        'maxlength' => 32,
                        'required' => true,
                        'validation' => ['messages' => ['required' => 'Please enter your first name.']],
                    ]);

                    $formwriter->textinput('usr_last_name', 'Last Name', [
                        'maxlength' => 32,
                        'required' => true,
                        'validation' => ['messages' => ['required' => 'Please enter your last name.']],
                    ]);

                    $nickname_display = $settings->get_setting('nickname_display_as');
                    if ($nickname_display) {
                        $formwriter->textinput('usr_nickname', $nickname_display, ['maxlength' => 32]);
                    }

                    $formwriter->textinput('usr_email', 'Email Address', [
                        'maxlength' => 64,
                        'required' => true,
                        'type' => 'email',
                        'value' => $_GET['email'] ?? '',
                        'validation' => ['messages' => ['required' => 'Please enter your email address.']],
                    ]);

                    $formwriter->dropinput('usr_timezone', 'Your Timezone', [
                        'options' => Address::get_timezone_drop_array(),
                        'value' => $settings->get_setting('default_timezone'),
                    ]);

                    $formwriter->checkboxinput('privacy', 'I consent to the privacy policy.', [
                        'required' => true,
                        'checked' => true,
                    ]);

                    $privacy_url = trim((string)$settings->get_setting('privacy_url'));
                    if ($privacy_url !== '') {
                        echo '<p><a href="' . htmlspecialchars($privacy_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Read the privacy policy</a></p>';
                    }
                }
                ?>

                <h2>Available lists</h2>
                <?php
                $formwriter->checkboxList('new_list_subscribes', 'Check the lists you want to receive:', [
                    'options' => $mailing_lists->get_dropdown_array(),
                    'checked' => $user_subscribed_list,
                ]);

                if (!$is_logged_in) {
                    $formwriter->antispam_question_input();
                    $formwriter->honeypot_hidden_input();
                    $formwriter->captcha_hidden_input();
                }

                $formwriter->hiddeninput('form_submitted', '', ['value' => 1]);
                $formwriter->submitbutton('submit_button', 'Subscribe');
                $formwriter->end_form();
                ?>
            </div>

        <?php endif; ?>

    </div>
</section>
</div>
<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
