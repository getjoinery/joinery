<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/admin_analytics_activitybydate_data.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'signups-by-date',
		'breadcrumbs' => array(
			'Statistics'=>'/admin/admin_analytics_stats',
			'Signups by Date' => ''
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
	'value' => $startdate
]);
$formwriter->textinput('enddate', 'End Date', [
	'value' => $enddate
]);
$formwriter->checkboxinput('usr_is_disabled', 'Exclude Disabled Users', [
	'checked' => $usrdisabled,
	'value' => 1
]);

$optionvals = array("0"=>"Day", "1"=>"Week", "2"=>"Month", "3"=>"Quarter", "4"=>"Year");
$grouping = array("Day", "Week", "Month", "Quarter", "Year");
$formwriter->dropinput('interval', 'Group by:', [
	'options' => $optionvals,
	'value' => $interval
]);
$formwriter->submitbutton('btn_submit', 'Submit');

echo $formwriter->end_form();

echo '<br />';

	$headers = array("Date Range: " . $grouping[$interval], "New Users", "Emailed Users", "Number of Logins");
	$altlinks = array();
	//$altlinks += array('Add Group'=> '/admin/admin_group_edit');
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Signups by Date"
	);
	$page->tableheader($headers, $box_vars);

$rowtotals = array("<b>Totals</b>", 0, 0, 0);

foreach ($intervals as $interval => $values)
{
	$rowvalues = array();

	array_push($rowvalues, substr($interval, 0, 10));
	array_push($rowvalues, $values['newusercount']);
	array_push($rowvalues, $values['numemailed']);
	//array_push($rowvalues, $values['numpicposters']);
	//array_push($rowvalues, $values['numvideos']);
	array_push($rowvalues, $values['numactiveusers']);

	$rowtotals[1] += $values['newusercount'];
	$rowtotals[2] += $values['numemailed'];
	//$rowtotals[5] += $values['numpicposters'];
	//$rowtotals[6] += $values['numvideos'];
	$rowtotals[3] += $values['numactiveusers'];

	$page->disprow($rowvalues);
}

$page->disprow($rowtotals);

$page->endtable();
?>

<br>
* All dates are tied to usr_signup_date, regardless of the action taking place.
Number of Logins is the total number of logins (after the initial account creation) from all users.
<br>
<br>

	<div id="sql">
		<p><b>SQL Query:</b> <?php echo $sql; ?></p>
		<p><b>Dev Query:</b> <?php echo $sqldev; ?></p>
	</div>

<?php

$page->admin_footer();

?>
