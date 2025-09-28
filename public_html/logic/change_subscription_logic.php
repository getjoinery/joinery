<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function change_subscription_logic($get, $post) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('data/users_class.php'));

    $page_vars = array();

    // Check if user is logged in
    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        // Redirect to login with return URL
        header('Location: /login?return=' . urlencode('/change-subscription'));
        exit;
    }

    // User data for display
    $page_vars['user'] = new User($session->get_user_id(), TRUE);

    return LogicResult::render($page_vars);
}
?>