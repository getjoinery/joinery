<?php

function index_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('data/posts_class.php'));
    require_once(PathHelper::getIncludePath('data/events_class.php'));

    $page_vars = array();

    // Get recent blog posts for homepage (4 posts)
    $recent_posts = new MultiPost(
        array('published' => TRUE, 'deleted' => false),
        array('pst_published_time' => 'DESC'),
        4, 0
    );
    $recent_posts->load();
    $page_vars['recent_posts'] = $recent_posts;

    // Get upcoming events for sidebar (6 events)
    // Exclude recurring parents - their virtual instances will be merged in
    $upcoming_events = new MultiEvent(
        array('deleted' => false, 'upcoming' => true, 'exclude_recurring_parents' => true),
        array('evt_start_time' => 'ASC'),
        6, 0
    );
    $upcoming_events->load();
    $all_events = iterator_to_array($upcoming_events);

    // Merge virtual instances from recurring parents
    $parent_searches = array('deleted' => false, 'visibility' => 1, 'only_recurring_parents' => true, 'status' => Event::STATUS_ACTIVE);
    $parents = new MultiEvent($parent_searches, []);
    $parents->load();

    $range_end = date('Y-m-d', strtotime('+6 months'));
    foreach ($parents as $parent) {
        $parent_pic = $parent->get_picture_link();
        $instances = $parent->get_instances_for_range(date('Y-m-d'), $range_end);
        foreach ($instances as $instance) {
            if (is_object($instance) && isset($instance->is_virtual) && $instance->is_virtual) {
                $instance->_picture_link = $parent_pic;
                $all_events[] = $instance;
            } else if ($instance instanceof Event && $instance->get('evt_status') == Event::STATUS_CANCELED) {
                // Cancelled materialized instances are excluded by the main query
                $all_events[] = $instance;
            }
        }
    }

    // Sort by start time and limit to 6
    usort($all_events, function($a, $b) {
        $a_time = (is_object($a) && isset($a->is_virtual) && $a->is_virtual) ? $a->evt_start_time : $a->get('evt_start_time');
        $b_time = (is_object($b) && isset($b->is_virtual) && $b->is_virtual) ? $b->evt_start_time : $b->get('evt_start_time');
        return strcmp($a_time, $b_time);
    });
    $all_events = array_slice($all_events, 0, 6);
    $page_vars['upcoming_events'] = $all_events;

    return LogicResult::render($page_vars);
}
?>
