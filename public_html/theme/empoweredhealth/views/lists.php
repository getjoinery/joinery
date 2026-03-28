<?php
// theme/empoweredhealth/views/lists.php
// Theme-specific lists template with empoweredhealth styling

require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('lists_logic.php', 'logic'));

$page_vars = process_logic(lists_logic($_GET, $_POST, $params));
$messages = $page_vars['messages'];
$session = $page_vars['session'];
$mailing_lists = $page_vars['mailing_lists'];
$numlists = $page_vars['numlists'];

$paget = new PublicPage();
$paget->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => 'Newsletter'
));
?>

<!-- Banner Section -->
<section class="banner-area relative about-banner" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row d-flex align-items-center justify-content-center">
            <div class="about-content col-lg-12">
                <h1 class="text-white">Newsletter</h1>
                <p class="text-white link-nav">
                    <a href="/">Home </a><span class="lnr lnr-arrow-right"></span>
                    Newsletter
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
                        <p class="text-muted mb-4">Get updates from us.</p>

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

                        <?php if($numlists == 0): ?>
                            <p>There are currently no mailing lists to register for.</p>
                        <?php else: ?>
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

                            $optionvals = $mailing_lists->get_dropdown_array();
                            $checkedvals = $user_subscribed_list ?? [];
                            $readonlyvals = array();
                            $disabledvals = array();

                            $formwriter->checkboxList('new_list_subscribes', 'Check the box to subscribe:', [
                                'options' => $optionvals,
                                'checked' => $checkedvals,
                                'disabled' => $disabledvals,
                                'readonly' => $readonlyvals
                            ]);

                            $formwriter->hiddeninput('form_submitted', '', ['value' => 1]);

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
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$paget->public_footer(array('track' => TRUE));
?>
