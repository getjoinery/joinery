<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('event_sessions_logic.php', 'logic'));

	$page_vars = process_logic(event_sessions_logic($_GET, $_POST));
	$pager = $page_vars['pager'];

	if($page_vars['error_message']){
		PublicPage::OutputGenericPublicPage('Not Registered', 'Not Registered', $page_vars['error_message']);
		exit();
	}

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Sessions',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Event' => '',
		),
	);
	$page->public_header($hoptions, NULL);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo htmlspecialchars($page_vars['event']->get('evt_name')); ?></h1>
                <span><?php echo $page_vars['event']->get_time_string($page_vars['session']->get_timezone()); ?></span>
                <?php if($page_vars['location_string']): ?>
                <span><?php echo htmlspecialchars($page_vars['location_string']); ?></span>
                <?php endif; ?>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Sessions</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">

            <!-- Main content -->
            <div style="flex: 2; min-width: 0;">

                <!-- Event header actions -->
                <?php
                $calendar_text = '';
                if($page_vars['event']->get('evt_status') != 2 && $page_vars['event']->get('evt_status') != 3){
                    $calendar_links = $page_vars['event']->get_add_to_calendar_links();
                    if($calendar_links){
                        $calendar_text .= 'Add to calendar: <a href="'.$calendar_links['google'].'">Google</a> | ';
                        $calendar_text .= '<a href="'.$calendar_links['yahoo'].'">Yahoo</a> | ';
                        $calendar_text .= '<a href="'.$calendar_links['outlook'].'">Outlook</a> | ';
                        $calendar_text .= '<a href="'.$calendar_links['ics'].'">iCal</a>';
                    }
                }
                if($calendar_text):
                ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1rem 1.5rem; margin-bottom: 1.5rem; font-size: 0.875rem;">
                    <?php echo $calendar_text; ?>
                </div>
                <?php endif; ?>

                <?php if(!$page_vars['event']->get('evt_end_time') || $page_vars['event']->get('evt_end_time') > date('Y-m-d H:i:s')): ?>
                <div style="margin-bottom: 1.5rem;">
                    <a href="/profile/event_withdraw?evr_event_registrant_id=<?php echo $page_vars['event_registrant']->key; ?>" class="btn btn-outline" style="font-size: 0.875rem;">Withdraw from Course</a>
                </div>
                <?php endif; ?>

                <!-- Event description -->
                <?php if($page_vars['event']->get('evt_short_description')): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.5rem; margin-bottom: 1.5rem;">
                    <?php echo $page_vars['event']->get('evt_short_description'); ?>
                </div>
                <?php endif; ?>

                <!-- Location -->
                <?php if($page_vars['location_object']): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.5rem; margin-bottom: 1.5rem;">
                    <h5 style="margin: 0 0 0.75rem;">Location: <?php echo htmlspecialchars($page_vars['location_object']->get('loc_name')); ?></h5>
                    <?php if($page_vars['location_object']->get('loc_address')): ?>
                    <p style="margin: 0 0 0.5rem; color: var(--color-muted);">Address: <?php echo htmlspecialchars($page_vars['location_object']->get('loc_address')); ?></p>
                    <?php endif; ?>
                    <?php if($page_vars['location_object']->get('loc_website')): ?>
                    <p style="margin: 0 0 0.75rem;"><a href="<?php echo htmlspecialchars($page_vars['location_object']->get('loc_website')); ?>"><?php echo htmlspecialchars($page_vars['location_object']->get('loc_website')); ?></a></p>
                    <?php endif; ?>
                    <?php if($page_vars['location_picture']): ?>
                    <img src="<?php echo htmlspecialchars($page_vars['location_picture']); ?>" style="max-width: 100%; border-radius: 4px; margin-bottom: 0.75rem;" alt="">
                    <?php endif; ?>
                    <?php echo $page_vars['location_object']->get('loc_description'); ?>
                </div>
                <?php endif; ?>

                <!-- Next Session -->
                <?php if($page_vars['next_session']): ?>
                <?php
                if($page_vars['next_session']->get('evs_title')){
                    $next_name = $page_vars['next_session']->get('evs_title');
                } else {
                    $next_name = 'Session ' . $page_vars['next_session']->get('evs_session_number');
                }
                $next_time = $page_vars['next_session']->get_time_string($page_vars['session']->get_timezone());
                $next_cal = '';
                $next_cal_links = $page_vars['next_session']->get_add_to_calendar_links();
                if($next_cal_links){
                    $next_cal .= 'Add to calendar: <a href="'.$next_cal_links['google'].'">Google</a> | ';
                    $next_cal .= '<a href="'.$next_cal_links['yahoo'].'">Yahoo</a> | ';
                    $next_cal .= '<a href="'.$next_cal_links['outlook'].'">Outlook</a> | ';
                    $next_cal .= '<a href="'.$next_cal_links['ics'].'">iCal</a>';
                }
                ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1.5rem;">
                    <div style="background: var(--color-light, #f8f9fa); padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--color-border, #eee);">
                        <h6 style="margin: 0;">Next Session: <?php echo htmlspecialchars($next_name); ?></h6>
                    </div>
                    <div style="padding: 1.25rem;">
                        <p style="margin: 0 0 0.5rem; font-size: 0.875rem; color: var(--color-muted);">&#128197; <?php echo htmlspecialchars($next_time); ?></p>
                        <?php if($next_cal): ?><p style="margin: 0 0 0.75rem; font-size: 0.875rem;"><?php echo $next_cal; ?></p><?php endif; ?>
                        <p><?php echo $page_vars['next_session']->get('evs_content'); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Sessions List -->
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: var(--color-primary); color: #fff; padding: 1rem 1.5rem;">
                        <h5 style="margin: 0; color: #fff;">Sessions</h5>
                    </div>
                    <?php
                    foreach($page_vars['event_sessions'] as $event_session){
                        if($event_session->get('evs_vid_video_id')){
                            $video = new Video($event_session->get('evs_vid_video_id'), TRUE);
                        } else {
                            $video = new Video(NULL);
                        }

                        $session_name = '';
                        if($event_session->get('evs_session_number')){
                            $session_name .= 'Session ' . $event_session->get('evs_session_number') . ' — ';
                        }
                        if($event_session->get('evs_title')){
                            $session_name .= $event_session->get('evs_title');
                        } else {
                            $session_name .= 'Session ' . $event_session->get('evs_session_number');
                        }

                        if($page_vars['event']->get('evt_timezone') == $page_vars['session']->get_timezone()){
                            $time_string = $event_session->get_time_string($page_vars['event']->get('evt_timezone'));
                        } else {
                            $time_string = $event_session->get_time_string($page_vars['event']->get('evt_timezone')) . ' (Your time: ' . $event_session->get_time_string($page_vars['session']->get_timezone()) . ')';
                        }
                    ?>
                    <div style="border-bottom: 1px solid var(--color-border, #eee); padding: 1.5rem;">
                        <h6 style="margin: 0 0 0.375rem; color: var(--color-primary);"><?php echo htmlspecialchars($session_name); ?></h6>
                        <p style="margin: 0 0 1rem; font-size: 0.875rem; color: var(--color-muted);">&#128197; <?php echo htmlspecialchars($time_string); ?></p>
                        <?php echo $video->get_embed(); ?>
                        <?php if($event_session->get('evs_content')): ?>
                        <div style="margin-top: 0.75rem;"><?php echo $event_session->get('evs_content'); ?></div>
                        <?php endif; ?>
                        <?php
                        $session_files = $event_session->get_files();
                        $file_list = [];
                        foreach($session_files as $sf){ $file_list[] = $sf; }
                        if(!empty($file_list)):
                        ?>
                        <div style="margin-top: 1rem;">
                            <h6 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Materials:</h6>
                            <ul style="margin: 0; padding-left: 1.25rem;">
                                <?php foreach($file_list as $sf): ?>
                                <li><a href="<?php echo $sf->get_url(); ?>"><?php echo htmlspecialchars($sf->get_name()); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php } ?>

                    <?php if($pager->num_records() > 5): ?>
                    <div style="padding: 1rem 1.5rem; text-align: center; font-size: 0.875rem; color: var(--color-muted);">
                        <?php
                        if($page_number = $pager->is_valid_page('+1')){
                            echo '<a href="' . $pager->get_url($page_number) . '">Show next ' . $pager->num_per_page() . ' of ' . $pager->num_records() . ' sessions</a>';
                        } else {
                            echo 'Showing final ' . $pager->num_per_page() . ' of ' . $pager->num_records() . ' sessions';
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Sidebar -->
            <div style="flex: 1; min-width: 240px; max-width: 300px;">
                <?php if($page_vars['event']->get('evt_private_info')): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: var(--color-light, #f8f9fa); padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--color-border, #eee);">
                        <h6 style="margin: 0;">Registrant Info</h6>
                    </div>
                    <div style="padding: 1.25rem;">
                        <?php echo $page_vars['event']->get('evt_private_info'); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<?php
$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
