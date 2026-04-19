<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('event_sessions_course_logic.php', 'logic'));

	$page_vars = process_logic(event_sessions_course_logic($_GET, $_POST));

	if($page_vars['error_message']){
		PublicPage::OutputGenericPublicPage('Not Registered', 'Not Registered', $page_vars['error_message']);
		exit();
	}

	$page = new PublicPage();
	$page->public_header([
		'is_valid_page' => $is_valid_page ?? false,
		'title' => 'Sessions',
	]);

	$session_name = 'Session ' . $page_vars['event_session']->get('evs_session_number') . ' — ' . $page_vars['event_session']->get('evs_title');
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <div>
                    <h1><?php echo htmlspecialchars($page_vars['event']->get('evt_name')); ?></h1>
                    <span class="muted"><?php echo $page_vars['event']->get_time_string($page_vars['session']->get_timezone()); ?></span>
                </div>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">My Profile</a></li>
                        <li class="active">Sessions</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div style="display: flex; gap: var(--jy-space-6); align-items: flex-start; flex-wrap: wrap;">

            <!-- Main content -->
            <div style="flex: 2; min-width: 0;">

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
                <div class="jy-panel text-sm">
                    <?php echo $calendar_text; ?>
                </div>
                <?php endif; ?>

                <?php if($page_vars['event']->get('evt_end_time') > date('Y-m-d H:i:s')): ?>
                <div style="margin-bottom: var(--jy-space-5);">
                    <a href="/profile/event_withdraw?evr_event_registrant_id=<?php echo $page_vars['event_registrant']->key; ?>" class="btn btn-outline btn-sm">Withdraw from Course</a>
                </div>
                <?php endif; ?>

                <?php if($page_vars['event']->get('evt_short_description')): ?>
                <div class="jy-panel">
                    <?php echo $page_vars['event']->get('evt_short_description'); ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header" style="background: var(--jy-color-primary); color: #fff;">
                        <h5 style="margin: 0; color: #fff;"><?php echo htmlspecialchars($session_name); ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if(isset($time_string) && $time_string): ?>
                        <p class="muted text-sm" style="margin: 0 0 var(--jy-space-4);">&#128197; <?php echo htmlspecialchars($time_string); ?></p>
                        <?php endif; ?>

                        <?php
                        if($page_vars['video']->key && !$page_vars['video']->get('vid_delete_time')){
                            echo $page_vars['video']->get_embed(784, 441);
                        } else if($page_vars['event_session']->get('evs_picture_link')){
                            echo '<img src="' . htmlspecialchars($page_vars['event_session']->get('evs_picture_link')) . '" style="max-width: 100%; border-radius: var(--jy-radius-sm);" alt="">';
                        }
                        ?>

                        <?php if($page_vars['event_session']->get('evs_content')): ?>
                        <div style="margin-top: var(--jy-space-4);"><?php echo $page_vars['event_session']->get('evs_content'); ?></div>
                        <?php endif; ?>

                        <?php
                        $session_files = $page_vars['event_session']->get_files();
                        $file_list = [];
                        foreach($session_files as $sf){ $file_list[] = $sf; }
                        if(!empty($file_list)):
                        ?>
                        <div style="margin-top: var(--jy-space-4);">
                            <h6 class="text-sm" style="text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: var(--jy-space-2);">Materials:</h6>
                            <ul style="margin: 0; padding-left: var(--jy-space-5);">
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
                        <div style="margin-top: var(--jy-space-5); padding-top: var(--jy-space-5); border-top: 1px solid var(--jy-color-border); text-align: right;">
                            <a href="/profile/event_sessions_course?session_number=<?php echo $next_session; ?>&event_id=<?php echo $page_vars['event']->key; ?>" class="btn btn-primary">Next Session &rarr;</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Sidebar -->
            <div style="flex: 1; min-width: 220px; max-width: 280px;">

                <div class="card">
                    <div class="card-header">
                        <h6 style="margin: 0;">All Sessions</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach($page_vars['event_sessions'] as $aevent_session): ?>
                        <div style="margin-bottom: var(--jy-space-2);">
                            <a href="/profile/event_sessions_course?session_number=<?php echo $aevent_session->get('evs_session_number'); ?>&event_id=<?php echo $page_vars['event']->key; ?>"
                               class="text-sm"<?php if($aevent_session->get('evs_session_number') == $page_vars['session_number']): ?> style="font-weight: 600; color: var(--jy-color-primary);"<?php endif; ?>>
                                Session <?php echo $aevent_session->get('evs_session_number'); ?> &mdash; <?php echo htmlspecialchars($aevent_session->get('evs_title')); ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if($page_vars['event']->get('evt_private_info')): ?>
                <div class="card">
                    <div class="card-header">
                        <h6 style="margin: 0;">Registrant Info</h6>
                    </div>
                    <div class="card-body">
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
$page->public_footer(['track' => TRUE, 'show_survey' => TRUE]);
?>
