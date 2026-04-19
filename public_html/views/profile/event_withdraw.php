<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('event_withdraw_logic.php', 'logic'));

	$page_vars = process_logic(event_withdraw_logic($_GET, $_POST));

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Withdraw from Event/Course',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div style="max-width: 640px; margin: 0 auto;">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Withdraw from Event/Course</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Withdraw</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <div class="jy-panel">

                <?php
                $event_registrant = $page_vars['event_registrant'] ?? null;
                $event = $page_vars['event'] ?? null;
                $evr_event_registrant_id = $page_vars['evr_event_registrant_id'] ?? null;

                if(!$event_registrant){
                    echo '<p>You are not registered for this course, or you have already withdrawn.</p>';
                }
                else if(!$event->get('evt_end_time') || $event->get('evt_end_time') > date('Y-m-d H:i:s')){
                    $settings = Globalvars::get_instance();
                    $formwriter = $page->getFormWriter('form1', [
                        'action' => '/profile/event_withdraw'
                    ]);
                    $formwriter->begin_form();

                    echo '<h4>Confirm withdrawal from ' . htmlspecialchars($event->get('evt_name')) . '</h4>';
                    echo '<p>Withdrawing from the course/event will remove you from the attendee list and the mailing list.</p>';
                    echo '<p><strong>It will NOT refund any payments.</strong> To refund a payment, contact us at <a href="mailto:' . htmlspecialchars($settings->get_setting('defaultemail')) . '">' . htmlspecialchars($settings->get_setting('defaultemail')) . '</a>.</p>';

                    $formwriter->hiddeninput('confirm', '', ['value' => 1]);
                    $formwriter->hiddeninput('evr_event_registrant_id', '', ['value' => $evr_event_registrant_id]);

                    echo '<div style="display: flex; gap: var(--jy-space-4); align-items: center;">';
                    $formwriter->submitbutton('btn_submit', 'Confirm Withdrawal', ['class' => 'btn btn-primary']);
                    echo ' <a href="/profile">Cancel, I changed my mind</a>';
                    echo '</div>';

                    $formwriter->end_form();
                }
                else{
                    echo '<p>You cannot withdraw from an event in the past.</p>';
                }
                ?>

            </div>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer();
?>
