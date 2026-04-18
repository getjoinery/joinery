<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('event_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$instance_date = isset($params['date']) ? $params['date'] : null;

$page_vars = process_logic(event_logic($_GET, $_POST, $event, $instance_date));
$event           = $page_vars['event'];
$is_virtual_event = !empty($page_vars['is_virtual']);
$settings        = Globalvars::get_instance();

$evt_get = function($field) use ($event, $is_virtual_event) {
    if ($is_virtual_event) {
        return isset($event->$field) ? $event->$field : null;
    }
    return $event->get($field);
};

$page         = new PublicPage();
$page_options = [
    'is_valid_page' => $is_valid_page,
    'title'         => $evt_get('evt_name'),
];
if ($evt_get('evt_short_description')) {
    $page_options['meta_description'] = $evt_get('evt_short_description');
}
if (!$is_virtual_event && $event->get_picture_link('hero')) {
    $page_options['preview_image_url'] = $event->get_picture_link('hero');
}
$page->public_header($page_options);
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="jy-container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Event Details</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/events">Events</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Event Single</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<!-- Content -->
<section id="content">
    <div class="content-wrap">
        <div class="jy-container">

            <div class="row gx-5 col-mb-80">

                <!-- Left column - Main Content -->
                <main class="postcontent col-lg-8">
                    <div class="single-event">

                        <!-- Event Header -->
                        <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
                            <h2 class="mb-3"><?php echo htmlspecialchars($evt_get('evt_name')); ?></h2>

                            <?php
                            $is_cancelled    = (!$is_virtual_event && $event->get('evt_status') == Event::STATUS_CANCELED);
                            $cancelled_badge = $is_cancelled ? ' <span class="badge bg-danger ms-2">Cancelled</span>' : '';

                            $tz = $evt_get('evt_timezone') ?: 'America/New_York';

                            if ($is_virtual_event) {
                                if ($evt_get('evt_start_time')) {
                                    $start_str = LibraryFunctions::convert_time($evt_get('evt_start_time'), 'UTC', $tz, 'D, M j, Y g:i a T');
                                    echo '<p class="mb-2" style="font-size: 1.0625rem; color: var(--jy-color-text-muted);">&#128197; ' . $start_str;
                                    if ($evt_get('evt_end_time')) {
                                        $end_str = LibraryFunctions::convert_time($evt_get('evt_end_time'), 'UTC', $tz, 'g:i a T');
                                        echo ' &ndash; ' . $end_str;
                                    }
                                    echo '</p>';
                                }
                            } else {
                                if ($time_string = $event->get_time_string()) {
                                    echo '<p class="mb-2" style="font-size: 1.0625rem; color: var(--jy-color-text-muted);">&#128197; ' . $time_string . $cancelled_badge . '</p>';
                                }
                                if ($evt_get('evt_timezone') != $page_vars['session']->get_timezone()) {
                                    echo '<p class="mb-2" style="color: var(--jy-color-text-muted);">&#9201; ' . $event->get_time_string($page_vars['session']->get_timezone()) . '</p>';
                                }
                            }

                            if (!$page_vars['location_object'] && $evt_get('evt_location')) {
                                echo '<p class="mb-2" style="color: var(--jy-color-text-muted);">&#128205; ' . htmlspecialchars($evt_get('evt_location')) . '</p>';
                            }

                            if ($evt_get('evt_usr_user_id_leader')) {
                                $leader = new User($evt_get('evt_usr_user_id_leader'), TRUE);
                                echo '<p class="mb-0" style="color: var(--jy-color-text-muted);">&#128100; Led by: ' . $leader->display_name() . '</p>';
                            }
                            ?>
                        </div>

                        <!-- Event Image -->
                        <?php
                        require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
                        if (!$is_virtual_event) {
                            echo ComponentRenderer::render(null, 'image_gallery', [
                                'photos'          => $event->get_photos(),
                                'primary_file_id' => $event->get('evt_fil_file_id'),
                                'alt_text'        => $event->get('evt_name'),
                            ]);
                        } else {
                            $picture_link = null;
                            if ($evt_get('evt_fil_file_id')) {
                                $pic_file     = new File($evt_get('evt_fil_file_id'), TRUE);
                                $picture_link = $pic_file->get_url('content', 'full');
                            } elseif ($evt_get('evt_picture_link')) {
                                $picture_link = $evt_get('evt_picture_link');
                            }
                            if ($picture_link) {
                                echo '<div class="mb-5"><img src="' . htmlspecialchars($picture_link) . '" alt="' . htmlspecialchars($evt_get('evt_name')) . '" style="width: 100%; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>';
                            }
                        }
                        ?>

                        <!-- Event Description -->
                        <?php
                        $event_tier_access = !$is_virtual_event ? $event->authenticate_tier($page_vars['session']) : ['allowed' => true];
                        ?>
                        <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
                            <h3 class="mb-3">Description</h3>
                            <div class="entry-content">
                                <?php if ($event_tier_access['allowed']): ?>
                                <?php echo $evt_get('evt_description'); ?>
                                <?php else:
                                    require_once(PathHelper::getIncludePath('includes/tier_gate_prompt.php'));
                                    // Show short description as preview, gate the full description
                                    if ($evt_get('evt_short_description')) {
                                        echo '<p>' . htmlspecialchars($evt_get('evt_short_description')) . '</p>';
                                    }
                                    render_tier_gate_prompt($event_tier_access);
                                endif; ?>
                            </div>
                        </div>

                    </div>
                </main>

                <!-- Right column - Sidebar -->
                <aside class="sidebar col-lg-4">

                    <!-- Registration Widget -->
                    <?php if (empty($page_vars['is_virtual'])): ?>
                    <div class="widget bg-white rounded-4 shadow-sm p-4 mb-4">
                        <h4 class="mb-3">Registration</h4>

                        <?php if ($event_tier_access['allowed']): ?>
                        <?php if ($page_vars['registration_message']): ?>
                        <p class="mb-3"><?php echo $page_vars['registration_message']; ?></p>
                        <?php endif; ?>

                        <?php foreach ($page_vars['register_urls'] as $register_url): ?>
                        <div class="d-grid mb-2">
                            <a href="<?php echo $register_url['link']; ?>" class="btn btn-primary"><?php echo $register_url['label']; ?></a>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($page_vars['if_registered_message']): ?>
                        <p style="color: var(--jy-color-text-muted); font-size: 0.875rem; margin-top: 1rem; margin-bottom: 0;"><?php echo $page_vars['if_registered_message']; ?></p>
                        <?php endif; ?>
                        <?php else: ?>
                        <p style="color: var(--jy-color-text-muted);">Upgrade your subscription to register for this event.</p>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="widget bg-white rounded-4 shadow-sm p-4 mb-4">
                        <h4 class="mb-3">Registration</h4>
                        <p class="mb-0" style="color: var(--jy-color-text-muted);">Registration is not yet open for this date.</p>
                    </div>
                    <?php endif; ?>

                    <!-- Location Widget -->
                    <?php if ($page_vars['location_object']): ?>
                    <div class="widget bg-white rounded-4 shadow-sm p-4 mb-4">
                        <h4 class="mb-3">Location: <?php echo $page_vars['location_object']->get('loc_name'); ?></h4>

                        <?php if ($page_vars['location_object']->get('loc_address')): ?>
                        <p class="mb-2">&#128205; <?php echo $page_vars['location_object']->get('loc_address'); ?></p>
                        <?php endif; ?>

                        <?php if ($page_vars['location_object']->get('loc_website')): ?>
                        <p class="mb-3">&#127760; <a href="<?php echo $page_vars['location_object']->get('loc_website'); ?>" target="_blank"><?php echo $page_vars['location_object']->get('loc_website'); ?></a></p>
                        <?php endif; ?>

                        <?php if (!empty($page_vars['location_picture'])): ?>
                        <div class="mb-3">
                            <img src="<?php echo $page_vars['location_picture']; ?>" style="width: 100%; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1);" alt="<?php echo htmlspecialchars($page_vars['location_object']->get('loc_name')); ?>">
                        </div>
                        <?php endif; ?>

                        <?php if ($page_vars['location_object']->get('loc_description')): ?>
                        <div>
                            <?php echo $page_vars['location_object']->get('loc_description'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Add to Calendar Widget -->
                    <?php
                    $show_calendar_link = $evt_get('evt_show_add_to_calendar_link');
                    if ($show_calendar_link && $evt_get('evt_start_time')) {
                        $calendar_links = [];
                        if (!$is_virtual_event) {
                            $calendar_links = $event->get_add_to_calendar_links();
                        }
                        $evt_link = $evt_get('evt_link');
                        $ics_url  = '/event/' . $evt_link;
                        if ($is_virtual_event && isset($event->instance_date)) {
                            $ics_url .= '/' . $event->instance_date;
                        }
                        $ics_url .= '.ics';
                    ?>
                    <div class="widget bg-white rounded-4 shadow-sm p-4 mb-4">
                        <h4 class="mb-3">Add to Calendar</h4>
                        <div class="grid-2" style="gap: 0.5rem;">
                            <?php if (!empty($calendar_links['google'])): ?>
                            <a href="<?php echo $calendar_links['google']; ?>" target="_blank" rel="noopener" class="btn btn-outline" style="font-size: 0.875rem;">Google</a>
                            <?php endif; ?>
                            <?php if (!empty($calendar_links['outlook'])): ?>
                            <a href="<?php echo $calendar_links['outlook']; ?>" target="_blank" rel="noopener" class="btn btn-outline" style="font-size: 0.875rem;">Outlook</a>
                            <?php endif; ?>
                            <?php if (!empty($calendar_links['yahoo'])): ?>
                            <a href="<?php echo $calendar_links['yahoo']; ?>" target="_blank" rel="noopener" class="btn btn-outline" style="font-size: 0.875rem;">Yahoo</a>
                            <?php endif; ?>
                            <a href="<?php echo $ics_url; ?>" class="btn btn-outline" style="font-size: 0.875rem;">&#8595; Download</a>
                        </div>
                    </div>
                    <?php } ?>

                    <!-- Sessions Widget -->
                    <?php
                    if (!$is_virtual_event && ($page_vars['show_sessions_block'] || (isset($page_vars['numsessions']) && $page_vars['numsessions'] > 0) || (isset($page_vars['future_numsessions']) && $page_vars['future_numsessions'] > 0) || (isset($page_vars['past_numsessions']) && $page_vars['past_numsessions'] > 0))):
                    ?>
                    <div class="widget bg-white rounded-4 shadow-sm p-4">
                        <h4 class="mb-3">Sessions</h4>

                        <div class="accordion accordion-bg" data-collapsible="true">
                            <?php
                            if ($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE && $page_vars['numsessions'] > 0) {
                                foreach ($page_vars['event_sessions'] as $event_session) {
                                    $session_title = $event_session->get('evs_title');
                                    if ($event_session->get('evs_session_number')) {
                                        $session_title = 'Session ' . $event_session->get('evs_session_number') . ' - ' . $session_title;
                                    }
                                    ?>
                                    <div class="accordion-header">
                                        <div class="accordion-icon">
                                            <span class="accordion-closed">+</span>
                                            <span class="accordion-open">&minus;</span>
                                        </div>
                                        <div class="accordion-title"><?php echo htmlspecialchars($session_title); ?></div>
                                    </div>
                                    <div class="accordion-content">
                                        <?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
                                    </div>
                                    <?php
                                }
                            } else {
                                if ($page_vars['future_numsessions'] > 0) {
                                    foreach ($page_vars['future_event_sessions'] as $event_session) {
                                        $time_string = '';
                                        if ($ts = $event_session->get_time_string($tz)) {
                                            $time_string = ' - ' . $ts;
                                        }
                                        ?>
                                        <div class="accordion-header">
                                            <div class="accordion-icon">
                                                <span class="accordion-closed">+</span>
                                                <span class="accordion-open">&minus;</span>
                                            </div>
                                            <div class="accordion-title"><?php echo htmlspecialchars($event_session->get('evs_title') . $time_string); ?></div>
                                        </div>
                                        <div class="accordion-content">
                                            <?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
                                        </div>
                                        <?php
                                    }
                                }

                                if ($page_vars['past_numsessions'] > 0) {
                                    echo '<h5 style="margin-top: 1.5rem; margin-bottom: 1rem;">Past Sessions</h5>';
                                    foreach ($page_vars['past_event_sessions'] as $event_session) {
                                        $time_string = '';
                                        if ($ts = $event_session->get_time_string($tz)) {
                                            $time_string = ' - ' . $ts;
                                        }
                                        ?>
                                        <div class="accordion-header">
                                            <div class="accordion-icon">
                                                <span class="accordion-closed">+</span>
                                                <span class="accordion-open">&minus;</span>
                                            </div>
                                            <div class="accordion-title"><?php echo htmlspecialchars($event_session->get('evs_title') . $time_string); ?></div>
                                        </div>
                                        <div class="accordion-content">
                                            <?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
                                            <p style="margin-top: 1rem; margin-bottom: 0;"><a href="/profile/event_sessions?evt_event_id=<?php echo $event->key; ?>" style="color: var(--jy-color-primary);">View videos and materials</a></p>
                                        </div>
                                        <?php
                                    }
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </aside>

            </div>

        </div>
    </div>
</section>

</div>
<?php
$page->public_footer(['track' => true]);
?>
