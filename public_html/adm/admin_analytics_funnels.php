<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_analytics_funnels_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page_vars = process_logic(admin_analytics_funnels_logic($_GET, $_POST));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'funnels',
	'breadcrumbs' => array(
		'Statistics'=>'/admin/admin_analytics_stats',
		'Funnels'=>'',
	),
	'session' => $session,
)
);

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();
$formwriter->textinput('startdate', 'Start Date', ['value' => $page_vars['startdate']]);
$formwriter->textinput('enddate', 'End Date', ['value' => $page_vars['enddate']]);

$type_options = array('page' => 'Page URL', 'event' => 'Event Type');
for ($i = 1; $i <= 5; $i++) {
	$step      = $page_vars['steps'][$i];
	$step_type = $step['type'] ?: 'page';
	$options   = ($step_type === 'event') ? $page_vars['event_optionvals'] : $page_vars['page_optionvals'];
	echo '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">';
	echo '<div style="flex:0 0 140px;">';
	$formwriter->dropinput("step_{$i}_type", "Step {$i} type", [
		'options' => $type_options,
		'value'   => $step_type,
	]);
	echo '</div>';
	echo '<div style="flex:1;">';
	$formwriter->dropinput("step_{$i}", "Step {$i}", [
		'options' => $options,
		'value'   => $step['value'],
	]);
	echo '</div>';
	echo '</div>';
}

$formwriter->submitbutton('btn_submit', 'Submit');
echo $formwriter->end_form();

?>
<script>
// Toggle the step's value dropdown when the step's type changes.
// Page list vs Event list comes from the logic payload so the UI stays in sync.
(function(){
	var pageOptions  = <?php echo json_encode($page_vars['page_optionvals']); ?>;
	var eventOptions = <?php echo json_encode($page_vars['event_optionvals']); ?>;
	for (var i = 1; i <= 5; i++) {
		(function(n){
			var typeSel = document.querySelector('[name="step_' + n + '_type"]');
			var valSel  = document.querySelector('[name="step_' + n + '"]');
			if (!typeSel || !valSel) return;
			typeSel.addEventListener('change', function(){
				var opts = this.value === 'event' ? eventOptions : pageOptions;
				valSel.innerHTML = '';
				Object.keys(opts).forEach(function(k){
					var o = document.createElement('option');
					o.value = k;
					o.textContent = opts[k];
					valSel.appendChild(o);
				});
			});
		})(i);
	}
})();
</script>
<?php

echo '<br />';

if (!empty($page_vars['funnel_stats'])) {
	$headers = array("Step", "Visits", "Conversion from prev", "Total conversion");
	$page->tableheader($headers, array('title' => 'Funnel'));

	$prev_count = NULL;
	$initial_count = 0;
	foreach ($page_vars['funnel_stats'] as $stat) {
		$rowvalues = array();
		array_push($rowvalues, $stat->funnel_step);
		array_push($rowvalues, $stat->visitors);
		if ($prev_count === NULL) {
			array_push($rowvalues, '-');
			$initial_count = $stat->visitors;
		} else {
			$pct = ($prev_count > 0) ? round(($stat->visitors / $prev_count) * 100, 2) : 0;
			array_push($rowvalues, $pct . '%');
		}
		$pct_total = ($initial_count > 0) ? round(($stat->visitors / $initial_count) * 100, 2) : 0;
		array_push($rowvalues, $pct_total . '%');
		$prev_count = $stat->visitors;
		$page->disprow($rowvalues);
	}
	$page->endtable();
}

$page->admin_footer();

?>
