<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('event_withdraw_logic.php', 'logic'));

	$page_vars = process_logic(event_withdraw_logic($_GET, $_POST));

	$page = new MemberPage();
	$hoptions=array(
		'title'=>'Withdraw from Event/Course'
	);
	$page->member_header($hoptions);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Withdraw from Event/Course</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Withdraw</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 640px; margin: 0 auto;">
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2rem;">

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

                    echo '<h4 style="margin-bottom: 1rem;">Confirm withdrawal from ' . htmlspecialchars($event->get('evt_name')) . '</h4>';
                    echo '<p style="margin-bottom: 0.75rem;">Withdrawing from the course/event will remove you from the attendee list and the mailing list.</p>';
                    echo '<p style="margin-bottom: 1.5rem;"><strong>It will NOT refund any payments.</strong> To refund a payment, contact us at <a href="mailto:' . htmlspecialchars($settings->get_setting('defaultemail')) . '">' . htmlspecialchars($settings->get_setting('defaultemail')) . '</a>.</p>';

                    $formwriter->hiddeninput('confirm', '', ['value' => 1]);
                    $formwriter->hiddeninput('evr_event_registrant_id', '', ['value' => $evr_event_registrant_id]);

                    echo '<div style="display: flex; gap: 1rem; align-items: center;">';
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

<?php
$page->member_footer();
?>
