<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('event_sessions_course_logic.php', 'logic'));

	$page_vars = process_logic(event_sessions_course_logic($_GET, $_POST));

	if($page_vars['error_message']){
		PublicPage::OutputGenericPublicPage('Not Registered', 'Not Registered', $page_vars['error_message']);
		exit();
	}

	$page = new MemberPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Sessions',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Event' => '',
		),
	);
	$page->member_header($hoptions, NULL);

	$session_name = 'Session ' . $page_vars['event_session']->get('evs_session_number') . ' — ' . $page_vars['event_session']->get('evs_title');
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="jy-container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo htmlspecialchars($page_vars['event']->get('evt_name')); ?></h1>
                <span><?php echo $page_vars['event']->get_time_string($page_vars['session']->get_timezone()); ?></span>
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

<section class="jy-content-section">
    <div class="jy-container">
        <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">

            <!-- Main content -->
            <div style="flex: 2; min-width: 0;">

                <!-- Calendar links -->
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

                <?php
                if($page_vars['event']->get('evt_end_time') > date('Y-m-d H:i:s')):
                ?>
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

                <!-- Current Session -->
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1.5rem;">
                    <div style="background: var(--jy-color-primary); color: #fff; padding: 1rem 1.5rem;">
                        <h5 style="margin: 0; color: #fff;"><?php echo htmlspecialchars($session_name); ?></h5>
                    </div>
                    <div style="padding: 1.5rem;">
                        <?php
                        if(isset($time_string) && $time_string):
                        ?>
                        <p style="margin: 0 0 1rem; font-size: 0.875rem; color: var(--jy-color-text-muted);">&#128197; <?php echo htmlspecialchars($time_string); ?></p>
                        <?php endif; ?>

                        <?php
                        if($page_vars['video']->key && !$page_vars['video']->get('vid_delete_time')){
                            echo $page_vars['video']->get_embed(784, 441);
                        } else if($page_vars['event_session']->get('evs_picture_link')){
                            echo '<img src="' . htmlspecialchars($page_vars['event_session']->get('evs_picture_link')) . '" style="max-width:100%; border-radius: 4px;" alt="">';
                        }
                        ?>

                        <?php if($page_vars['event_session']->get('evs_content')): ?>
                        <div style="margin-top: 1rem;"><?php echo $page_vars['event_session']->get('evs_content'); ?></div>
                        <?php endif; ?>

                        <?php
                        $session_files = $page_vars['event_session']->get_files();
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

                        <?php
                        $next_session = $page_vars['session_number'] + 1;
                        $exists = 0;
                        foreach($page_vars['event_sessions'] as $check_session){
                            if($check_session->get('evs_session_number') == $next_session){
                                $exists = 1;
                            }
                        }
                        if($exists):
                        ?>
                        <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--jy-color-border); text-align: right;">
                            <a href="/profile/event_sessions_course?session_number=<?php echo $next_session; ?>&event_id=<?php echo $page_vars['event']->key; ?>" class="btn btn-primary">Next Session &rarr;</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Sidebar -->
            <div style="flex: 1; min-width: 220px; max-width: 280px;">

                <!-- Sessions nav -->
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1.5rem;">
                    <div style="background: var(--jy-color-surface); padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--jy-color-border);">
                        <h6 style="margin: 0;">All Sessions</h6>
                    </div>
                    <div style="padding: 1rem 1.25rem;">
                        <?php foreach($page_vars['event_sessions'] as $aevent_session): ?>
                        <div style="margin-bottom: 0.5rem;">
                            <a href="/profile/event_sessions_course?session_number=<?php echo $aevent_session->get('evs_session_number'); ?>&event_id=<?php echo $page_vars['event']->key; ?>"
                               style="font-size: 0.875rem;<?php if($aevent_session->get('evs_session_number') == $page_vars['session_number']): ?> font-weight: 600; color: var(--jy-color-primary);<?php endif; ?>">
                                Session <?php echo $aevent_session->get('evs_session_number'); ?> &mdash; <?php echo htmlspecialchars($aevent_session->get('evs_title')); ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Private info -->
                <?php if($page_vars['event']->get('evt_private_info')): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: var(--jy-color-surface); padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--jy-color-border);">
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

</div>
<?php
$page->member_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
