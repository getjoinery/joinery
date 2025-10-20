<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_analytics_users_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$page_vars = process_logic(admin_analytics_users_logic($_GET, $_POST));

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
echo $formwriter->begin_form("uniForm", "post", "/admin/admin_analytics_users");

echo $formwriter->textinput("Start Date", "startdate", "dateinput", 30, $page_vars['startdate'], "", 10);
echo $formwriter->textinput("End Date", "enddate", "dateinput", 30, $page_vars['enddate'], "", 10);
echo $formwriter->textinput("Minimum Total", "mintotal", "filterinput", 20, $page_vars['mintotal'], "", 10);
echo $formwriter->checkboxinput("Exclude Disabled Users", "usr_is_disabled", "checkbox", "left", $page_vars['disabled'], 0, "");

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit', 'formsubmit', 'admin_analytics_users', 'Calculate');
	echo $formwriter->end_buttons();
echo $formwriter->end_form();

	$headers = array("Domain", "Total", "Total Verified", "% Email Verified");
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Email Deliverability"
	);
	$page->tableheader($headers, $box_vars);

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
	array_push($rowvalues, number_format(($values['vtotal']/$values['total'])*100, 2) . '%');

	$grandtotalf += $values['total'];
	$grandtotalvf+= $values['vtotal'];

	$page->disprow($rowvalues);
}

$rowtotals = array();
array_push($rowtotals, '<b>Totals</b>');
array_push($rowtotals, number_format($grandtotal));
array_push($rowtotals, number_format($grandtotalv));
array_push($rowtotals, number_format(($grandtotalv/$grandtotal)*100, 2) . '%');
$page->disprow($rowtotals);

$rowtotals = array();
array_push($rowtotals, '<b>Totals (Excluding Domains Under Minimum)</b>');
array_push($rowtotals, number_format($grandtotalf));
array_push($rowtotals, number_format($grandtotalvf));
array_push($rowtotals, number_format(($grandtotalvf/$grandtotalf)*100, 2) . '%');
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
