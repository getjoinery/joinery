<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('events_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(events_logic($_GET, $_POST));
$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page,
    'title'         => $page_vars['events_label'],
]);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo $page_vars['events_label']; ?></h1>
                <span>Browse our upcoming events and register today</span>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Events</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<!-- Content -->
<section id="content">
    <div class="content-wrap">
        <div class="container">

            <div class="grid-filter-wrap">
                <ul class="grid-filter grid-filter-links" style="position: relative;">
                    <?php
                    foreach ($page_vars['tab_menus'] as $id => $name) {
                        if ($id == ($_REQUEST['type'] ?? '')) {
                            echo '<li class="activeFilter"><a href="/events?type=' . $id . '">' . $name . '</a></li>';
                        } else {
                            echo '<li><a href="/events?type=' . $id . '">' . $name . '</a></li>';
                        }
                    }
                    ?>
                </ul>
            </div>

            <div style="text-align: right; margin-bottom: 1rem;">
                <a href="/events/calendar.ics" style="color: var(--color-muted, #6c757d); font-size: 0.875rem;">&#128197; Subscribe to Calendar</a>
            </div>

            <!-- Mobile Dropdown for Categories -->
            <div style="display: none;" class="mobile-category-select">
                <select class="form-select" onchange="window.location.href=this.value;" style="margin-bottom: 1.5rem;">
                    <?php
                    foreach ($page_vars['tab_menus'] as $id => $name) {
                        $selected = ($id == ($_REQUEST['type'] ?? '')) ? 'selected' : '';
                        echo '<option value="/events?type=' . $id . '" ' . $selected . '>' . $name . '</option>';
                    }
                    ?>
                </select>
            </div>
            <style>
            @media (max-width: 576px) {
                .mobile-category-select { display: block !important; }
                .grid-filter-wrap { display: none; }
            }
            </style>

            <!-- Event Items Grid -->
            <div id="portfolio" class="portfolio row grid-container gutter-30" data-layout="fitRows">

                <?php
                foreach ($page_vars['events'] as $event) {
                    $now_utc    = gmdate('Y-m-d H:i:s');
                    $is_virtual = (is_object($event) && isset($event->is_virtual) && $event->is_virtual);
                    $is_cancelled = (!$is_virtual && $event instanceof Event && $event->get('evt_status') == Event::STATUS_CANCELED);

                    $evt_name       = $is_virtual ? $event->evt_name       : $event->get('evt_name');
                    $evt_start_time = $is_virtual ? $event->evt_start_time : $event->get('evt_start_time');
                    $evt_link       = $is_virtual ? $event->evt_link       : $event->get('evt_link');
                    $evt_leader_id  = $is_virtual ? $event->evt_usr_user_id_leader : $event->get('evt_usr_user_id_leader');

                    if ($is_virtual) {
                        $event_url = '/event/' . $evt_link . '/' . $event->instance_date;
                        $pic = null;
                        if ($event->evt_fil_file_id) {
                            $pic_file = new File($event->evt_fil_file_id, TRUE);
                            $pic = $pic_file->get_url('profile_card', 'full');
                        } elseif ($event->evt_picture_link) {
                            $pic = $event->evt_picture_link;
                        }
                    } else {
                        $event_url = $event->get_url();
                        $pic = $event->get_picture_link('profile_card');
                    }
                    ?>
                    <article class="portfolio-item col-md-4 col-sm-6 col-12">
                        <div class="grid-inner">
                            <div class="portfolio-image">
                                <?php if ($pic): ?>
                                    <a href="<?php echo $event_url; ?>">
                                        <img src="<?php echo $pic; ?>" alt="<?php echo htmlspecialchars($evt_name); ?>">
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo $event_url; ?>">
                                        <div style="height: 250px; background: var(--color-light, #f8f9fa); display: flex; align-items: center; justify-content: center; font-size: 4rem; color: var(--color-border, #ddd);">
                                            &#128197;
                                        </div>
                                    </a>
                                <?php endif; ?>
                                <div class="bg-overlay">
                                    <div class="bg-overlay-content dark" data-hover-animate="fadeIn">
                                        <a href="<?php echo $event_url; ?>" class="overlay-trigger-icon bg-light text-dark" data-hover-animate="fadeInDownSmall" data-hover-animate-out="fadeOutUpSmall" data-hover-speed="350">+</a>
                                        <a href="<?php echo $event_url; ?>" class="overlay-trigger-icon bg-light text-dark" data-hover-animate="fadeInDownSmall" data-hover-animate-out="fadeOutUpSmall" data-hover-speed="350">&#8942;</a>
                                    </div>
                                    <div class="bg-overlay-bg dark" data-hover-animate="fadeIn"></div>
                                </div>
                            </div>
                            <div class="portfolio-desc">
                                <h3><a href="<?php echo $event_url; ?>"><?php echo htmlspecialchars($evt_name); ?></a></h3>
                                <?php if ($is_cancelled): ?>
                                <span class="badge bg-danger mb-2">Cancelled</span><br>
                                <?php endif; ?>
                                <span>
                                    <?php
                                    $date_str       = '';
                                    $instructor_str = '';

                                    if ($evt_start_time && $evt_start_time > $now_utc) {
                                        if ($is_virtual) {
                                            $date_str = date('M j, Y', strtotime($evt_start_time));
                                        } else {
                                            $date_str = $event->get_event_start_time($tz, 'M j, Y');
                                        }
                                    } elseif (!$is_virtual && ($next_session = $event->get_next_session())) {
                                        $date_str = $next_session->get_start_time($tz, 'M j, Y');
                                    }

                                    if ($evt_leader_id) {
                                        $leader = new User($evt_leader_id, TRUE);
                                        $instructor_str = $leader->display_name();
                                    } else {
                                        $instructor_str = 'Various instructors';
                                    }

                                    if ($date_str) {
                                        echo '<a href="#">' . $date_str . '</a>';
                                    }
                                    if ($instructor_str) {
                                        if ($date_str) echo ', ';
                                        echo '<a href="#">' . $instructor_str . '</a>';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </article>
                    <?php
                }
                ?>

            </div><!-- #portfolio end -->

        </div>
    </div>
</section>

<?php
$page->public_footer(['track' => true]);
?>
