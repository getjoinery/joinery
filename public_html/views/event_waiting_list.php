<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('event_waiting_list_logic.php', 'logic'));

    $event_id = LibraryFunctions::fetch_variable('event_id', 0, 1, 'You must pass an event.', true, 'int');
    $page_vars = process_logic(event_waiting_list_logic($_GET, $_POST, $event_id));
    $event = $page_vars['event'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Waiting List',
    ]);
    $options = ['subtitle' => 'Add yourself to the waiting list, and we will notify you as soon as registration is available.'];
    echo PublicPage::BeginPage('Waiting list for ' . $event->get('evt_name'), $options);
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <div class="text-center mb-4">
                <h3 class="h5 text-primary mb-2"><?php echo htmlspecialchars($event->get('evt_name')); ?></h3>
                <p class="text-muted">Add yourself to the waiting list, and we will notify you as soon as registration is available.</p>
            </div>

            <?php if ($page_vars['display_message'] ?? false): ?>
                <div class="alert alert-<?php echo $page_vars['message_type'] == 'error' ? 'danger' : ($page_vars['message_type'] == 'success' ? 'success' : 'info'); ?>" role="alert">
                    <h6 class="alert-heading mb-2">Success</h6>
                    <?php echo $page_vars['display_message']; ?>
                </div>
                <div class="text-center mt-4">
                    <a href="/events" class="btn btn-primary">View All Events</a>
                </div>
            <?php else: ?>

                <div class="card shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <?php
                        $settings = Globalvars::get_instance();
                        $formwriter = $page->getFormWriter('form1', ['action' => '/event_waiting_list']);
                        $formwriter->antispam_question_validate([]);
                        $formwriter->begin_form();
                        $formwriter->hiddeninput('event_id', $event->key);
                        ?>

                        <?php if ($page_vars['session']->get_user_id()): ?>
                            <div class="text-center mb-4">
                                <h5 class="mb-2">Join Waiting List</h5>
                                <p class="text-muted">Click the button below to be added to this waiting list.</p>
                            </div>
                        <?php else: ?>
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
                                $nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
                                if ($nickname_display): ?>
                                <div class="col-12">
                                    <label for="usr_nickname" class="form-label fw-semibold"><?php echo htmlspecialchars($nickname_display); ?></label>
                                    <input type="text" name="usr_nickname" id="usr_nickname" class="form-control" maxlength="32">
                                </div>
                                <?php endif; ?>

                                <div class="col-12">
                                    <label for="usr_email" class="form-label fw-semibold">Email Address</label>
                                    <input type="email" name="usr_email" id="usr_email" class="form-control" placeholder="Enter your email address" maxlength="64">
                                </div>

                                <div class="col-12">
                                    <label for="usr_timezone" class="form-label fw-semibold">Your Timezone</label>
                                    <?php
                                    $optionvals = Address::get_timezone_drop_array();
                                    $default_timezone = $page_vars['settings']->get_setting('default_timezone');
                                    ?>
                                    <select name="usr_timezone" id="usr_timezone" class="form-select">
                                        <?php foreach ($optionvals as $val => $label): ?>
                                        <option value="<?php echo $val; ?>" <?php echo ($val == $default_timezone) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <div class="form-check mb-2">
                                        <input type="checkbox" name="privacy" id="privacy" class="form-check-input" value="1">
                                        <label for="privacy" class="form-check-label">I consent to the privacy policy.</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" name="newsletter" id="newsletter" class="form-check-input" value="1">
                                        <label for="newsletter" class="form-check-label">Add me to the newsletter</label>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <?php
                                    echo $formwriter->antispam_question_input();
                                    echo $formwriter->honeypot_hidden_input();
                                    echo $formwriter->captcha_hidden_input();
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning">Add Me to the Waiting List</button>
                        </div>

                        <?php echo $formwriter->end_form(); ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
