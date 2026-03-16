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

    // Get upcoming events with recurring series expanded into individual dates
    $page_vars['upcoming_events'] = MultiEvent::getWithRepeatingEvents(
        ['deleted' => false, 'upcoming' => true, 'visibility' => 1],
        null, 6
    );

    return LogicResult::render($page_vars);
}
?>
