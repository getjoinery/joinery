<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Labels for the "Event Type" dropdown — kept in sync with VisitorEvent::TYPE_* constants.
 * Returned as part of the result so the view can render both the dropdown
 * options and the human-readable funnel step label.
 */
function _admin_funnel_event_type_labels() {
	require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
	return array(
		VisitorEvent::TYPE_PAGE_VIEW      => 'Page View',
		VisitorEvent::TYPE_CART_ADD       => 'Cart Add',
		VisitorEvent::TYPE_CHECKOUT_START => 'Checkout Start',
		VisitorEvent::TYPE_PURCHASE       => 'Purchase',
		VisitorEvent::TYPE_SIGNUP         => 'Signup',
		VisitorEvent::TYPE_LIST_SIGNUP    => 'List Signup',
	);
}

function admin_analytics_funnels_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$today = date("m-d-Y");
	$startdate = LibraryFunctions::fetch_variable('startdate', date("m-d-Y", strtotime("-1 months")), 0, '');
	$enddate   = LibraryFunctions::fetch_variable('enddate', $today, 0, '');

	$steps = array();
	for ($i = 1; $i <= 5; $i++) {
		$steps[$i] = array(
			'type'  => LibraryFunctions::fetch_variable("step_{$i}_type", 'page', 0, ''),
			'value' => LibraryFunctions::fetch_variable("step_{$i}", NULL, 0, ''),
		);
	}

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	// Get available pages for the page-URL dropdown
	$sql = "SELECT DISTINCT vse_page FROM vse_visitor_events WHERE vse_type = :page_view GROUP BY vse_page HAVING COUNT(*) > 5 ORDER BY vse_page ASC";

	try {
		$q = $dblink->prepare($sql);
		$q->bindValue(':page_view', VisitorEvent::TYPE_PAGE_VIEW, PDO::PARAM_INT);
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	} catch (PDOException $e) {
		return LogicResult::error('A database error occurred while loading analytics data.');
	}
	$results = $q->fetchAll();

	$page_optionvals = array('' => '-- Choose Page --');
	foreach ($results as $row) {
		$page_optionvals[$row->vse_page] = $row->vse_page;
	}

	$event_labels = _admin_funnel_event_type_labels();
	$event_optionvals = array('' => '-- Choose Event --');
	foreach ($event_labels as $tid => $label) {
		$event_optionvals[$tid] = $label;
	}

	$funnel_stats = array();

	// Require at least steps 1 and 2 filled
	$step_values = array();
	foreach ($steps as $i => $s) {
		if ($s['value'] === NULL || $s['value'] === '') {
			continue;
		}
		$step_values[$i] = $s;
	}

	if (count($step_values) >= 2 && $startdate && $enddate && isset($step_values[1]) && isset($step_values[2])) {

		// Helper — the WHERE clause that identifies step N
		$step_where = function($i) use ($step_values) {
			$s = $step_values[$i];
			if ($s['type'] === 'event') {
				return "v.vse_type = :step{$i}";
			}
			return "v.vse_page = :step{$i} AND v.vse_type = :page_view";
		};
		$step_label = function($i) use ($step_values, $event_labels) {
			$s = $step_values[$i];
			if ($s['type'] === 'event') {
				return isset($event_labels[(int)$s['value']]) ? $event_labels[(int)$s['value']] : ('Event ' . $s['value']);
			}
			return $s['value'];
		};

		$first_where = str_replace('v.', '', $step_where(1));
		$sql = "WITH step1 AS (
			SELECT vse_visitor_id, MIN(vse_timestamp) AS step1_ts
			FROM vse_visitor_events
			WHERE {$first_where}
			  AND vse_timestamp >= :startdate AND vse_timestamp <= :enddate
			GROUP BY vse_visitor_id
		)";

		foreach ($step_values as $i => $s) {
			if ($i === 1) continue;
			$w = $step_where($i);
			$sql .= ", step{$i} AS (
				SELECT v.vse_visitor_id, MIN(v.vse_timestamp) AS step{$i}_ts
				FROM vse_visitor_events v
				JOIN step1 s ON v.vse_visitor_id = s.vse_visitor_id
				WHERE {$w}
				  AND v.vse_timestamp > s.step1_ts
				  AND v.vse_timestamp >= :startdate AND v.vse_timestamp <= :enddate
				GROUP BY v.vse_visitor_id
			)";
		}

		$union_parts = array();
		foreach ($step_values as $i => $s) {
			$union_parts[] = "SELECT :label{$i} AS funnel_step, COUNT(*) AS visitors FROM step{$i}";
		}
		$sql .= ' ' . implode(' UNION ALL ', $union_parts);

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(':startdate', $startdate, PDO::PARAM_STR);
			$q->bindValue(':enddate', $enddate, PDO::PARAM_STR);
			$page_view_bound = false;
			foreach ($step_values as $i => $s) {
				if ($s['type'] === 'event') {
					$q->bindValue(":step{$i}", (int)$s['value'], PDO::PARAM_INT);
				} else {
					$q->bindValue(":step{$i}", $s['value'], PDO::PARAM_STR);
					$page_view_bound = true;
				}
				$q->bindValue(":label{$i}", $step_label($i), PDO::PARAM_STR);
			}
			if ($page_view_bound) {
				$q->bindValue(':page_view', VisitorEvent::TYPE_PAGE_VIEW, PDO::PARAM_INT);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch (PDOException $e) {
			return LogicResult::error('A database error occurred while processing funnel data.');
		}

		$funnel_stats = $q->fetchAll();
	}

	$result = new LogicResult();
	$result->data = array(
		'startdate'        => $startdate,
		'enddate'          => $enddate,
		'steps'            => $steps,
		'page_optionvals'  => $page_optionvals,
		'event_optionvals' => $event_optionvals,
		'funnel_stats'     => $funnel_stats,
	);

	return $result;
}
?>
