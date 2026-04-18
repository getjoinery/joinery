<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_analytics_attribution_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page_vars = process_logic(admin_analytics_attribution_logic($_GET, $_POST));

$settings = Globalvars::get_instance();
$currency = $settings->get_setting('site_currency');
require_once(PathHelper::getIncludePath('data/products_class.php'));
$currency_symbol = isset(Product::$currency_symbols[$currency]) ? Product::$currency_symbols[$currency] : '';

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'web-statistics',
	'breadcrumbs' => array(
		'Statistics'  => '/admin/admin_analytics_stats',
		'Attribution' => '',
	),
	'session' => $session,
));

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();
$formwriter->textinput('startdate', 'Start Date', ['value' => $page_vars['startdate']]);
$formwriter->textinput('enddate',   'End Date',   ['value' => $page_vars['enddate']]);
$formwriter->textinput('source',    'Source (optional)',   ['value' => $page_vars['source']]);
$formwriter->textinput('campaign',  'Campaign (optional)', ['value' => $page_vars['campaign']]);
$formwriter->checkboxinput('include_test', 'Include test orders', [
	'value'   => 1,
	'checked' => $page_vars['include_test'],
]);
$formwriter->submitbutton('btn_submit', 'Submit');
echo $formwriter->end_form();

echo '<br />';

// === Section 1: Channels table ===
$headers = array('Source', 'Visits', 'Signups', 'List Signups', 'Cart-adds', 'Checkouts', 'Purchases', 'Revenue', 'Visit&rarr;Purchase');
$page->tableheader($headers, array('title' => 'Channels'));

foreach ($page_vars['channels'] as $ch) {
	$conv_rate = '-';
	if ($ch->visits > 0) {
		$conv_rate = round(($ch->purchases / $ch->visits) * 100, 2) . '%';
	}
	$row = array(
		htmlspecialchars($ch->src, ENT_QUOTES, 'UTF-8'),
		(int)$ch->visits,
		(int)$ch->signups,
		(int)$ch->list_signups,
		(int)$ch->cart_adds,
		(int)$ch->checkouts,
		(int)$ch->purchases,
		$currency_symbol . number_format((float)$ch->revenue, 2, '.', ','),
		$conv_rate,
	);
	$page->disprow($row);
}
$page->endtable();

// === Section 2: Time-series chart ===
$palette = array(
	'rgb(51, 153, 255)',
	'rgb(255, 99, 132)',
	'rgb(75, 192, 192)',
	'rgb(255, 159, 64)',
	'rgb(153, 102, 255)',
);
$datasets = array();
$i = 0;
foreach ($page_vars['time_series'] as $src => $points) {
	$datasets[] = array(
		'label'           => $src,
		'backgroundColor' => $palette[$i % count($palette)],
		'borderColor'     => $palette[$i % count($palette)],
		'fill'            => false,
		'data'            => array_values($points),
	);
	$i++;
}
?>
<h3>Visits over time — top sources</h3>
<div style="width: 1000px; height: 400px;">
<script src="https://cdn.jsdelivr.net/npm/chart.js@2.8.0"></script>
<canvas id="attributionChart"></canvas>
</div>
<script>
(function(){
	var ctx = document.getElementById('attributionChart').getContext('2d');
	new Chart(ctx, {
		type: 'line',
		data: {
			labels:   <?php echo json_encode($page_vars['xvals']); ?>,
			datasets: <?php echo json_encode($datasets); ?>
		},
		options: {
			scales: { yAxes: [{ ticks: { beginAtZero: true } }] }
		}
	});
})();
</script>
<?php

// === Section 4: Campaign drilldown ===
$headers = array('Source', 'Campaign', 'Visits', 'Signups', 'List Signups', 'Cart-adds', 'Checkouts', 'Purchases');
$page->tableheader($headers, array('title' => 'Campaign Drilldown'));
foreach ($page_vars['campaigns'] as $c) {
	$row = array(
		htmlspecialchars($c->src, ENT_QUOTES, 'UTF-8'),
		htmlspecialchars($c->campaign, ENT_QUOTES, 'UTF-8'),
		(int)$c->visits,
		(int)$c->signups,
		(int)$c->list_signups,
		(int)$c->cart_adds,
		(int)$c->checkouts,
		(int)$c->purchases,
	);
	$page->disprow($row);
}
$page->endtable();

$page->admin_footer();
?>
