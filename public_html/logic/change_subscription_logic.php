<?php
function change_subscription_logic($get, $post) {
    $page_vars = array();

    // Check if user is logged in
    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        // Redirect to login with return URL
        header('Location: /login?return=' . urlencode('/change-subscription'));
        exit;
    }

    // User data for display
    $page_vars['user'] = new User($session->get('usr_user_id'), TRUE);

    return LogicResult::data($page_vars);
}
?>