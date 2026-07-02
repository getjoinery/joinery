<?php
/**
 * App Bridge failure page. Successful bridges never render — the logic 302s
 * to the target — so this page only appears for invalid, used, or expired
 * tokens. The app's silent re-bridge mints a fresh token instead of retrying
 * this URL, so a user only sees this by loading a stale link directly.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/app_bridge_logic.php'));

$page_vars = process_logic(app_bridge_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

http_response_code(410);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Sign-in link expired',
	'noindex' => TRUE,
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Sign-in link expired', $hoptions);
?>
<p>This app sign-in link is invalid or has already been used. Return to the app and try again &mdash; it will request a fresh one automatically.</p>
<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
