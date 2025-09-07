<?php
// Main ControlD dashboard - works with any theme
PathHelper::requireOnce('includes/ThemeHelper.php');
$page_title = 'ControlD Dashboard';

// Use theme's PublicPage class through ThemeHelper
$result = ThemeHelper::includeThemeFile('includes/PublicPage.php');
if ($result === false) {
    PathHelper::requireOnce('includes/PublicPageBase.php');
    $page = new PublicPageBase();
} else {
    $page = new PublicPage();
}

$page->public_header(['title' => $page_title]);
echo $page->BeginPage($page_title);

// Add main dashboard content using plugin data
PathHelper::requireOnce('plugins/controld/data/ctldaccount_class.php');
PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');

// Dashboard content here...
echo '<div class="container mt-4">';
echo '<h1>' . $page_title . '</h1>';
echo '<p>Welcome to the ControlD DNS Filtering dashboard.</p>';

// Check if user has ControlD account
$session = SessionControl::get_instance();
if ($session->is_user_logged_in()) {
    $user = $session->get_user();
    
    // Look for existing ControlD account
    $accounts = new MultiCtldAccount(['cta_usr_user_id' => $user->get('usr_user_id')]);
    if ($accounts->count_all() > 0) {
        $accounts->load();
        $account = $accounts->get(0);
        
        echo '<div class="alert alert-success">';
        echo '<h4>Your ControlD Account</h4>';
        echo '<p>Account ID: ' . htmlspecialchars($account->get('cta_account_id')) . '</p>';
        echo '<p><a href="/profile/devices" class="btn btn-primary">Manage Devices</a> ';
        echo '<a href="/profile/rules" class="btn btn-secondary">Manage Rules</a></p>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-info">';
        echo '<h4>Get Started with ControlD</h4>';
        echo '<p>Set up your ControlD DNS filtering account to protect your devices.</p>';
        echo '<p><a href="/profile/ctld_activation" class="btn btn-primary">Activate ControlD</a> ';
        echo '<a href="/pricing" class="btn btn-outline-primary">View Pricing</a></p>';
        echo '</div>';
    }
} else {
    echo '<div class="alert alert-warning">';
    echo '<h4>Login Required</h4>';
    echo '<p>Please login to access your ControlD dashboard.</p>';
    echo '<p><a href="/login" class="btn btn-primary">Login</a> ';
    echo '<a href="/pricing" class="btn btn-outline-primary">View Pricing</a></p>';
    echo '</div>';
}

echo '</div>';

echo $page->EndPage();