<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

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
    $upcoming_events = new MultiEvent(
        array('deleted' => false, 'after_date' => date('Y-m-d H:i:s')),
        array('evt_start_time' => 'ASC'),
        6, 0
    );
    $upcoming_events->load();
    $page_vars['upcoming_events'] = $upcoming_events;

    return LogicResult::render($page_vars);
}
?>
