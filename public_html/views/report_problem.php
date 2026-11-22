<?php
/**
 * Report a problem, for members.
 *
 * The body (where the report goes, the form, everything it will carry) is
 * includes/report_problem_form.php, shared with the administrators' page,
 * /admin/admin_report_problem. An administrator (permission 5 and up) opening
 * this page is sent there, carrying the query (ref, from, msg). Everyone else
 * gets the platform's member chrome, so it looks the same in every theme and
 * inside the mobile apps' webview, whose native picker handles the image.
 *
 * See specs/implemented/bug_reports.md.
 *
 * @version 1.1.0 - the body is shared (report_problem_form.php); administrators go to the admin page
 * @version 1.0.3 - a form posted here natively is still sent (report_problem_logic 1.1.0); the script is not deferred
 * @version 1.0.2 - the form's script is assets/js/report-problem.js (no inline script)
 * @version 1.0.1
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/report_problem_logic.php'));
require_once(PathHelper::getIncludePath('includes/report_problem_form.php'));

$session = SessionControl::get_instance();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $session->is_logged_in() && Access::permits(Access::staffLevel(), $session)) {
	// Only the page's own fields: the raw query string carries the router's __route.
	$query = http_build_query(array_intersect_key($_GET, array_flip(array('ref', 'from', 'msg'))));
	process_logic(LogicResult::redirect('/admin/admin_report_problem' . ($query !== '' ? '?' . $query : '')));
}

$page_vars = process_logic(report_problem_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
	'title' => $page_vars['page_title'],
]);
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<div class="jy-settings-shell">

			<div class="jy-page-header">
				<div class="jy-page-header-bar">
					<h1><?php echo htmlspecialchars($page_vars['page_title']); ?></h1>
				</div>
			</div>

			<?php report_problem_render_body($page, $page_vars, '/report_problem'); ?>

		</div>
	</div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
