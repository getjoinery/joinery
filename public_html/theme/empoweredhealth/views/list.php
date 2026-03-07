<?php
// theme/empoweredhealth/views/list.php
// Theme-specific single list template with empoweredhealth styling

require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('list_logic.php', 'logic'));

$page_vars = process_logic(list_logic($_GET, $_POST, $mailing_list, $params));
$messages = $page_vars['messages'];
$member_of_list = $page_vars['member_of_list'];
$session = $page_vars['session'];

$list_name = $mailing_list->get('mlt_name');
$list_description = $mailing_list->get('mlt_description');

$paget = new PublicPage();
$paget->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $list_name
));
?>

<!-- Banner Section -->
<section class="banner-area relative about-banner" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row d-flex align-items-center justify-content-center">
            <div class="about-content col-lg-12">
                <h1 class="text-white"><?php echo htmlspecialchars($list_name); ?></h1>
                <p class="text-white link-nav">
                    <a href="/">Home </a><span class="lnr lnr-arrow-right"></span>
                    <?php echo htmlspecialchars($list_name); ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Content Section -->
<section class="offered-service-area dep-offred-service">
    <div class="container">
        <div class="row offred-wrap section-gap">
            <div class="col-lg-8 offset-lg-2">
                <div class="card">
                    <div class="card-body p-4">
                        <?php if($list_description): ?>
                            <p class="text-muted mb-4"><?php echo htmlspecialchars($list_description); ?></p>
                        <?php endif; ?>

                        <?php if(!empty($messages)): ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="alert alert-<?php echo $message['message_type'] == 'error' ? 'danger' : $message['message_type']; ?> mb-3">
                                    <?php if($message['message_title']): ?>
                                        <strong><?php echo htmlspecialchars($message['message_title']); ?></strong><br>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($message['message']); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php
                        $settings = Globalvars::get_instance();
                        $formwriter = $paget->getFormWriter('form1');

                        $formwriter->begin_form();

                        if(!$session->get_user_id()){
                            $formwriter->textinput('usr_first_name', 'First Name', [
                                'maxlength' => 32,
                                'required' => true,
                                'minlength' => 1,
                                'data-msg-required' => 'Please enter your first name.'
                            ]);

                            $formwriter->textinput('usr_last_name', 'Last Name', [
                                'maxlength' => 32,
                                'required' => true
                            ]);

                            $nickname_display = $settings->get_setting('nickname_display_as');
                            if($nickname_display){
                                $formwriter->textinput('usr_nickname', $nickname_display, [
                                    'maxlength' => 32
                                ]);
                            }

                            $formwriter->textinput('usr_email', 'Email', [
                                'maxlength' => 64,
                                'value' => strip_tags($_GET['email'] ?? ''),
                                'required' => true,
                                'type' => 'email'
                            ]);

                            $optionvals = Address::get_timezone_drop_array();
                            $default_timezone = $settings->get_setting('default_timezone');
                            $formwriter->dropinput('usr_timezone', 'Your timezone', [
                                'options' => $optionvals,
                                'value' => $default_timezone
                            ]);

                            $formwriter->checkboxinput('privacy', 'I consent to the privacy policy.', [
                                'required' => true,
                                'checked' => true
                            ]);
                        }

                        if(!$member_of_list){
                            $formwriter->hiddeninput('mlt_mailing_list_id', ['value' => $mailing_list->key]);
                            $formwriter->checkboxinput('mlt_mailing_list_id_subscribe', 'Subscribe to this list.', [
                                'checked' => true
                            ]);
                        }
                        else{
                            $formwriter->checkboxinput('mlt_mailing_list_id_unsubscribe', 'Unsubscribe from this list.', [
                                'checked' => true
                            ]);
                        }

                        if(!$session->get_user_id()){
                            $formwriter->antispam_question_input();
                            $formwriter->honeypot_hidden_input();
                            $formwriter->captcha_hidden_input();
                        }

                        $formwriter->submitbutton('btn_submit', 'Submit', [
                            'class' => 'btn btn-primary'
                        ]);

                        $formwriter->end_form();
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$paget->public_footer(array('track' => TRUE));
?>
