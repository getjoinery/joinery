<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('lists_logic.php', 'logic'));

    $page_vars = process_logic(lists_logic($_GET, $_POST, $params));
    $messages            = $page_vars['messages'];
    $session             = $page_vars['session'];
    $mailing_lists       = $page_vars['mailing_lists'];
    $numlists            = $page_vars['numlists'];
    $user_subscribed_list = $page_vars['user_subscribed_list'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Newsletter',
    ]);
    echo PublicPage::BeginPage('Newsletter Lists', ['subtitle' => 'Get updates from us.']);
?>
<div class="jy-ui">

<div class="jy-container">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <?php if (!empty($messages)): ?>
                <?php foreach ($messages as $message): ?>
                <div class="alert alert-<?php echo $message['message_type'] == 'error' ? 'danger' : ($message['message_type'] == 'success' ? 'success' : 'info'); ?> mb-4" role="alert">
                    <?php if ($message['message_title']): ?><h6 class="alert-heading mb-2"><?php echo htmlspecialchars($message['message_title']); ?></h6><?php endif; ?>
                    <?php echo $message['message']; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($numlists == 0): ?>
                <div class="card shadow-sm rounded-4">
                    <div class="card-body p-4 text-center">
                        <h5 class="mb-3">No Mailing Lists Available</h5>
                        <p class="text-muted">There are currently no mailing lists to register for.</p>
                    </div>
                </div>
            <?php else: ?>

                <div class="card shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <?php
                        $settings = Globalvars::get_instance();
                        $formwriter = $page->getFormWriter('form1', ['action' => '/lists']);
                        $formwriter->antispam_question_validate([]);
                        $formwriter->begin_form();
                        ?>

                        <?php if (!$session->get_user_id()): ?>
                        <h5 class="mb-3">Your Information</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="usr_first_name" class="form-label fw-semibold">First Name</label>
                                <input type="text" name="usr_first_name" id="usr_first_name" class="form-control" placeholder="Enter your first name" maxlength="32">
                            </div>
                            <div class="col-md-6">
                                <label for="usr_last_name" class="form-label fw-semibold">Last Name</label>
                                <input type="text" name="usr_last_name" id="usr_last_name" class="form-control" placeholder="Enter your last name" maxlength="32">
                            </div>

                            <?php
                            $nickname_display = $settings->get_setting('nickname_display_as');
                            if ($nickname_display): ?>
                            <div class="col-12">
                                <label for="usr_nickname" class="form-label fw-semibold"><?php echo htmlspecialchars($nickname_display); ?></label>
                                <input type="text" name="usr_nickname" id="usr_nickname" class="form-control" maxlength="32">
                            </div>
                            <?php endif; ?>

                            <div class="col-12">
                                <label for="usr_email" class="form-label fw-semibold">Email Address</label>
                                <input type="email" name="usr_email" id="usr_email" class="form-control" placeholder="Enter your email address"
                                       value="<?php echo htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" maxlength="64">
                            </div>

                            <div class="col-12">
                                <label for="usr_timezone" class="form-label fw-semibold">Your Timezone</label>
                                <?php
                                $optionvals = Address::get_timezone_drop_array();
                                $default_timezone = $settings->get_setting('default_timezone');
                                ?>
                                <select name="usr_timezone" id="usr_timezone" class="form-select">
                                    <?php foreach ($optionvals as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php echo ($val == $default_timezone) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" name="privacy" id="privacy" class="form-check-input" value="1">
                                    <label for="privacy" class="form-check-label">I consent to the privacy policy.</label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <h5 class="mb-2">Available Lists</h5>
                        <p class="text-muted mb-3">Check the box to subscribe:</p>
                        <div class="mb-4">
                            <?php
                            $optionvals = $mailing_lists->get_dropdown_array();
                            $checkedvals = $user_subscribed_list;
                            foreach ($optionvals as $label => $val):
                                $is_checked = in_array($val, (array)$checkedvals);
                            ?>
                            <div class="form-check p-3 bg-light rounded-4 mb-2">
                                <input type="checkbox" name="new_list_subscribes[]" id="list_<?php echo $val; ?>"
                                       class="form-check-input" value="<?php echo $val; ?>" <?php echo $is_checked ? 'checked' : ''; ?>>
                                <label for="list_<?php echo $val; ?>" class="form-check-label fw-semibold">
                                    <?php echo htmlspecialchars($label); ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$session->get_user_id()): ?>
                        <div class="mb-4">
                            <?php
                            echo $formwriter->antispam_question_input();
                            echo $formwriter->honeypot_hidden_input();
                            echo $formwriter->captcha_hidden_input();
                            ?>
                        </div>
                        <?php endif; ?>

                        <input type="hidden" name="form_submitted" value="1">
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Subscribe to Lists</button>
                        </div>

                        <?php echo $formwriter->end_form(); ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

</div>
<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
