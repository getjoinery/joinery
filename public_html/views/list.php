<?php
/**
 * /list/{slug} — the landing page for one mailing list.
 *
 * Sharing this URL is how someone is invited onto a single list, so the page is
 * a straight sign-up: no box to tick before the button does anything. A signed-in
 * subscriber gets the unsubscribe form instead.
 *
 * Markup is the .jy-ui kit and every field comes from FormWriter, so the page
 * carries a theme's own look without knowing which theme is active.
 *
 * @version 2.0.0
 */

    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('list_logic.php', 'logic'));

    $page_vars = process_logic(list_logic(array_merge($_GET, $_POST, $params ?? [])));
    $mailing_list   = $page_vars['mailing_list'];
    $messages       = $page_vars['messages'];
    $member_of_list = $page_vars['member_of_list'];
    $session        = $page_vars['session'];

    $page = new PublicPage();
    $list_header_options = [
        'is_valid_page'    => $is_valid_page,
        'title'            => $mailing_list->get('mlt_name') ?: 'Newsletter',
        'entity_type'      => 'mailing_list',
        'entity_body_html' => $mailing_list->get('mlt_description'),
    ];
    if (method_exists($mailing_list, 'get_picture_link') && $mailing_list->get_picture_link('og_image')) {
        $list_header_options['preview_image_url'] = $mailing_list->get_picture_link('og_image');
    }
    $page->public_header($list_header_options);
    $options['subtitle'] = $mailing_list->get('mlt_description');
    echo PublicPage::BeginPage($mailing_list->get('mlt_name'), $options);

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

        <div class="jy-panel">
            <?php
            $formwriter = $page->getFormWriter('form1', ['action' => $mailing_list->get_url()]);
            $formwriter->antispam_question_validate([]);
            $formwriter->begin_form();

            if ($member_of_list) {
                echo '<p>You are subscribed to <strong>' . htmlspecialchars($mailing_list->get('mlt_name')) . '</strong>.</p>';
                $formwriter->hiddeninput('mlt_mailing_list_id', '', ['value' => $mailing_list->key]);
                $formwriter->hiddeninput('mlt_mailing_list_id_unsubscribe', '', ['value' => 1]);
                $formwriter->submitbutton('submit_button', 'Unsubscribe');
            } else {
                if (!$is_logged_in) {
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

                    $formwriter->antispam_question_input();
                    $formwriter->honeypot_hidden_input();
                    $formwriter->captcha_hidden_input();
                }

                $formwriter->hiddeninput('mlt_mailing_list_id', '', ['value' => $mailing_list->key]);
                $formwriter->hiddeninput('mlt_mailing_list_id_subscribe', '', ['value' => 1]);
                $formwriter->submitbutton('submit_button', 'Subscribe');
            }

            $formwriter->end_form();
            ?>
        </div>

    </div>
</section>
</div>
<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
