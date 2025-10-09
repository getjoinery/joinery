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

<script type="text/javascript">

		$(document).ready(function()
		{
			$("#sqlbtn").toggle
			(
				function ()
				{
					$("#sql").show();
				},
				function ()
				{
					$("#sql").hide();
				}
			);

			$("#sql").hide();
		});

</script>

<?php

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form("uniForm", "post", "/admin/admin_analytics_activitybydate");

echo $formwriter->textinput("Start Date", "startdate", "dateinput", 30, $startdate, "", 10);
echo $formwriter->textinput("End Date", "enddate", "dateinput", 30, $enddate, "", 10);
echo $formwriter->checkboxinput("Exclude Disabled Users", "usr_is_disabled", "checkbox", "left", $usrdisabled, 1, "Check to filter out disabled users");

$optionvals = array("Day"=>"0", "Week"=>"1", "Month"=>"2", "Quarter"=>"3", "Year"=>"4");
$grouping = array("Day", "Week", "Month", "Quarter", "Year");
echo $formwriter->dropinput("Group by:", "interval", "radioinput", $optionvals, $interval, "BlockLabel", "", TRUE);
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

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
