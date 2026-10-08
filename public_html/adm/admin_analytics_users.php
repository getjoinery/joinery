<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_analytics_users_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$page_vars = process_logic(admin_analytics_users_logic(array_merge($_GET, $_POST)));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'email-deliverability',
	'breadcrumbs' => array(
		'Statistics'=>'/admin/admin_analytics_stats',
		'Email Deliverability' => ''
	),
	'session' => $session,
)
);
?>

<script src="/assets/js/form-visibility-helper.js"></script>

<script type="text/javascript">
(function() {
	'use strict';

	document.addEventListener('DOMContentLoaded', function() {
		var sqlElement = document.getElementById('sql');
		var sqlBtn = document.getElementById('sqlbtn');

		if (!sqlBtn || !sqlElement) return;

		// Initially hide SQL element
		FormVisibility.hide('sql');

		// Toggle visibility on button click
		sqlBtn.addEventListener('click', function(e) {
			e.preventDefault();

			if (sqlElement.style.display === 'none') {
				FormVisibility.show('sql');
			} else {
				FormVisibility.hide('sql');
			}
		});
	});
})();
</script>

<?php

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();

$formwriter->textinput('startdate', 'Start Date', [
	'value' => $page_vars['startdate']
]);
$formwriter->textinput('enddate', 'End Date', [
	'value' => $page_vars['enddate']
]);
$formwriter->textinput('mintotal', 'Minimum Total', [
	'value' => $page_vars['mintotal']
]);
$formwriter->checkboxinput('usr_is_disabled', 'Exclude Disabled Users', [
	'checked' => $page_vars['disabled']
]);

$formwriter->submitbutton('btn_submit', 'Calculate');
echo $formwriter->end_form();

	$headers = array("Domain", "Total", "Total Verified", "% Email Verified");
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Email Deliverability"
	);
	$page->tableheader($headers, $box_vars);

// A share of nothing has no percentage: every domain can fall under the
// minimum, and a site can have no users in the window at all.
$percent = function ($part, $whole) {
	return $whole > 0 ? number_format(($part / $whole) * 100, 2) . '%' : '—';
};

$grandtotal = 0;
$grandtotalv = 0;

$grandtotalf = 0;
$grandtotalvf = 0;

foreach ($page_vars['domaincounts'] as $domain => $values)
{
	$grandtotal += $values['total'];
	$grandtotalv += $values['vtotal'];

	if ($values['total'] < $page_vars['mintotal'])
		continue;

	$rowvalues = array();

	array_push($rowvalues, $domain);
	array_push($rowvalues, $values['total']);
	array_push($rowvalues, $values['vtotal']);
	array_push($rowvalues, $percent($values['vtotal'], $values['total']));

	$grandtotalf += $values['total'];
	$grandtotalvf+= $values['vtotal'];

	$page->disprow($rowvalues);
}

$rowtotals = array();
array_push($rowtotals, '<b>Totals</b>');
array_push($rowtotals, number_format($grandtotal));
array_push($rowtotals, number_format($grandtotalv));
array_push($rowtotals, $percent($grandtotalv, $grandtotal));
$page->disprow($rowtotals);

$rowtotals = array();
array_push($rowtotals, '<b>Totals (Excluding Domains Under Minimum)</b>');
array_push($rowtotals, number_format($grandtotalf));
array_push($rowtotals, number_format($grandtotalvf));
array_push($rowtotals, $percent($grandtotalvf, $grandtotalf));
$page->disprow($rowtotals);

$page->endtable();

?>

<br>
<br>

	<div id="sql">
		<p><b>Domains:</b> <?php echo $page_vars['sql_domains']; ?></p>
		<p><b>Verified Domains:</b> <?php echo $page_vars['sql_verifieds']; ?></p>
	</div>

<?php
$page->admin_footer();
?>
