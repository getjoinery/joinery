<?php
/** @joinery-admin level=5 */
/**
 * Report a problem, for administrators: the same page as /report_problem
 * (logic/report_problem_logic.php, body includes/report_problem_form.php) in
 * the admin chrome. Reached from the admin header's Help menu, and from
 * /report_problem, which sends an administrator here with its query.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('logic/report_problem_logic.php'));
require_once(PathHelper::getIncludePath('includes/report_problem_form.php'));

$page_vars = process_logic(report_problem_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new AdminPage();
$page->admin_header(array(
	'page_title'     => $page_vars['page_title'],
	'readable_title' => $page_vars['page_title'],
	'breadcrumbs'    => array($page_vars['page_title'] => ''),
	'session'        => SessionControl::get_instance(),
));
?>
<div class="jy-ui">
	<?php report_problem_render_body($page, $page_vars, '/admin/admin_report_problem'); ?>
</div>
<?php
$page->admin_footer();
?>
