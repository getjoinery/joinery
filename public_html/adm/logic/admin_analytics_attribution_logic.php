<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Attribution reporting — slices vse_visitor_events by UTM source/campaign and
 * joins conversion rows to ord_orders for revenue. Every query enumerates
 * specific vse_type values — defensive against the grab-bag event-log schema.
 *
 * Treatment: implicit last-touch (the session UTM at the moment the event fired).
 * Multi-touch models are out of scope — see FUTURE_attribution_models.md.
 */
function admin_analytics_attribution_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$today = date('Y-m-d');
	$startdate    = LibraryFunctions::fetch_variable('startdate', date('Y-m-d', strtotime('-30 days')), 0, '');
	$enddate      = LibraryFunctions::fetch_variable('enddate', $today, 0, '');
	$source       = LibraryFunctions::fetch_variable('source', '', 0, '');
	$campaign     = LibraryFunctions::fetch_variable('campaign', '', 0, '');
	$include_test = LibraryFunctions::fetch_variable('include_test', '', 0, '') ? true : false;

	// Normalize to datetime bounds the DB query will accept directly.
	$start_ts = $startdate . ' 00:00:00';
	$end_ts   = $enddate . ' 23:59:59';

	$dbhelper = DbConnector::get_instance();
	$dblink   = $dbhelper->get_db_link();

	$type_page_view      = VisitorEvent::TYPE_PAGE_VIEW;
	$type_cart_add       = VisitorEvent::TYPE_CART_ADD;
	$type_checkout_start = VisitorEvent::TYPE_CHECKOUT_START;
	$type_purchase       = VisitorEvent::TYPE_PURCHASE;
	$type_signup         = VisitorEvent::TYPE_SIGNUP;
	$type_list_signup    = VisitorEvent::TYPE_LIST_SIGNUP;

	// Optional filter fragment applied to every query.
	$filter_sql = '';
	$filter_bind = array();
	if ($source !== '') {
		$filter_sql .= ' AND LOWER(COALESCE(vse_source, \'\')) = LOWER(:f_source)';
		$filter_bind[':f_source'] = $source;
	}
	if ($campaign !== '') {
		$filter_sql .= ' AND LOWER(COALESCE(vse_campaign, \'\')) = LOWER(:f_campaign)';
		$filter_bind[':f_campaign'] = $campaign;
	}

	// === Section 1: Channels overview ===
	// Single grouped query using conditional aggregates. Joins ord_orders for revenue.
	$sql = "
		WITH
		visits AS (
			SELECT COALESCE(LOWER(NULLIF(vse_source, '')), '(direct)') AS src,
			       COUNT(DISTINCT vse_visitor_id) AS visit_count
			FROM vse_visitor_events
			WHERE vse_type = :type_page_view
			  AND vse_timestamp >= :start_ts AND vse_timestamp <= :end_ts
			  {$filter_sql}
			GROUP BY src
		),
		conversions AS (
			SELECT COALESCE(LOWER(NULLIF(v.vse_source, '')), '(direct)') AS src,
			       SUM(CASE WHEN v.vse_type = :type_cart_add THEN 1 ELSE 0 END) AS cart_adds,
			       SUM(CASE WHEN v.vse_type = :type_checkout_start THEN 1 ELSE 0 END) AS checkouts,
			       SUM(CASE WHEN v.vse_type = :type_purchase THEN 1 ELSE 0 END) AS purchases,
			       SUM(CASE WHEN v.vse_type = :type_signup THEN 1 ELSE 0 END) AS signups,
			       SUM(CASE WHEN v.vse_type = :type_list_signup THEN 1 ELSE 0 END) AS list_signups
			FROM vse_visitor_events v
			WHERE v.vse_type IN (:type_cart_add, :type_checkout_start, :type_purchase, :type_signup, :type_list_signup)
			  AND v.vse_timestamp >= :start_ts AND v.vse_timestamp <= :end_ts
			  {$filter_sql}
			GROUP BY src
		),
		revenue AS (
			SELECT COALESCE(LOWER(NULLIF(v.vse_source, '')), '(direct)') AS src,
			       COALESCE(SUM(o.ord_total_cost), 0) AS revenue
			FROM vse_visitor_events v
			JOIN ord_orders o ON v.vse_ref_type = 'order' AND v.vse_ref_id = o.ord_order_id
			WHERE v.vse_type = :type_purchase
			  AND v.vse_timestamp >= :start_ts AND v.vse_timestamp <= :end_ts
			  AND (o.ord_refund_amount IS NULL OR o.ord_refund_amount = 0)
			  " . ($include_test ? '' : 'AND (o.ord_test_mode IS NULL OR o.ord_test_mode = FALSE)') . "
			  {$filter_sql}
			GROUP BY src
		)
		SELECT COALESCE(vi.src, co.src, re.src) AS src,
		       COALESCE(vi.visit_count, 0) AS visits,
		       COALESCE(co.cart_adds, 0) AS cart_adds,
		       COALESCE(co.checkouts, 0) AS checkouts,
		       COALESCE(co.purchases, 0) AS purchases,
		       COALESCE(co.signups, 0) AS signups,
		       COALESCE(co.list_signups, 0) AS list_signups,
		       COALESCE(re.revenue, 0) AS revenue
		FROM visits vi
		FULL OUTER JOIN conversions co ON vi.src = co.src
		FULL OUTER JOIN revenue re ON COALESCE(vi.src, co.src) = re.src
		ORDER BY revenue DESC, visits DESC
	";

	$channels = array();
	try {
		$q = $dblink->prepare($sql);
		$q->bindValue(':start_ts', $start_ts, PDO::PARAM_STR);
		$q->bindValue(':end_ts', $end_ts, PDO::PARAM_STR);
		$q->bindValue(':type_page_view', $type_page_view, PDO::PARAM_INT);
		$q->bindValue(':type_cart_add', $type_cart_add, PDO::PARAM_INT);
		$q->bindValue(':type_checkout_start', $type_checkout_start, PDO::PARAM_INT);
		$q->bindValue(':type_purchase', $type_purchase, PDO::PARAM_INT);
		$q->bindValue(':type_signup', $type_signup, PDO::PARAM_INT);
		$q->bindValue(':type_list_signup', $type_list_signup, PDO::PARAM_INT);
		foreach ($filter_bind as $k => $v) {
			$q->bindValue($k, $v, PDO::PARAM_STR);
		}
		$q->execute();
		$channels = $q->fetchAll(PDO::FETCH_OBJ);
	} catch (PDOException $e) {
		error_log('Attribution channels query failed: ' . $e->getMessage());
		return LogicResult::error('A database error occurred while loading attribution data.');
	}

	// === Section 2: Time-series (daily buckets, top 5 sources by visits) ===
	$top_sources = array();
	foreach ($channels as $ch) {
		$top_sources[] = $ch->src;
		if (count($top_sources) >= 5) break;
	}

	$xvals = array();
	$dt = new DateTime($startdate);
	$endDt = new DateTime($enddate);
	while ($dt <= $endDt) {
		$xvals[] = $dt->format('Y-m-d');
		$dt->modify('+1 day');
	}

	$time_series = array();
	if (!empty($top_sources)) {
		$placeholders = array();
		$src_bind = array();
		foreach ($top_sources as $idx => $s) {
			$k = ':src_' . $idx;
			$placeholders[] = $k;
			$src_bind[$k] = $s;
		}
		$sql_ts = "
			SELECT DATE(vse_timestamp) AS bucket,
			       COALESCE(LOWER(NULLIF(vse_source, '')), '(direct)') AS src,
			       COUNT(DISTINCT vse_visitor_id) AS visits
			FROM vse_visitor_events
			WHERE vse_type = :type_page_view
			  AND vse_timestamp >= :start_ts AND vse_timestamp <= :end_ts
			  AND COALESCE(LOWER(NULLIF(vse_source, '')), '(direct)') IN (" . implode(',', $placeholders) . ")
			  {$filter_sql}
			GROUP BY bucket, src
			ORDER BY bucket
		";

		try {
			$q = $dblink->prepare($sql_ts);
			$q->bindValue(':start_ts', $start_ts, PDO::PARAM_STR);
			$q->bindValue(':end_ts', $end_ts, PDO::PARAM_STR);
			$q->bindValue(':type_page_view', $type_page_view, PDO::PARAM_INT);
			foreach ($src_bind as $k => $v) {
				$q->bindValue($k, $v, PDO::PARAM_STR);
			}
			foreach ($filter_bind as $k => $v) {
				$q->bindValue($k, $v, PDO::PARAM_STR);
			}
			$q->execute();
			$rows = $q->fetchAll(PDO::FETCH_OBJ);

			// Pre-initialize series for each top source at 0 for every date bucket.
			foreach ($top_sources as $s) {
				$time_series[$s] = array_fill_keys($xvals, 0);
			}
			foreach ($rows as $r) {
				if (isset($time_series[$r->src][$r->bucket])) {
					$time_series[$r->src][$r->bucket] = (int)$r->visits;
				}
			}
		} catch (PDOException $e) {
			error_log('Attribution time-series query failed: ' . $e->getMessage());
		}
	}

	// === Section 4: Campaign drilldown (source + campaign) ===
	$campaigns = array();
	$sql_camp = "
		SELECT COALESCE(LOWER(NULLIF(vse_source, '')), '(direct)') AS src,
		       COALESCE(vse_campaign, '(none)') AS campaign,
		       SUM(CASE WHEN vse_type = :type_page_view THEN 1 ELSE 0 END) AS visits,
		       SUM(CASE WHEN vse_type = :type_signup THEN 1 ELSE 0 END) AS signups,
		       SUM(CASE WHEN vse_type = :type_list_signup THEN 1 ELSE 0 END) AS list_signups,
		       SUM(CASE WHEN vse_type = :type_cart_add THEN 1 ELSE 0 END) AS cart_adds,
		       SUM(CASE WHEN vse_type = :type_checkout_start THEN 1 ELSE 0 END) AS checkouts,
		       SUM(CASE WHEN vse_type = :type_purchase THEN 1 ELSE 0 END) AS purchases
		FROM vse_visitor_events
		WHERE vse_type IN (:type_page_view, :type_cart_add, :type_checkout_start, :type_purchase, :type_signup, :type_list_signup)
		  AND vse_timestamp >= :start_ts AND vse_timestamp <= :end_ts
		  {$filter_sql}
		GROUP BY src, campaign
		ORDER BY purchases DESC, visits DESC
		LIMIT 50
	";
	try {
		$q = $dblink->prepare($sql_camp);
		$q->bindValue(':start_ts', $start_ts, PDO::PARAM_STR);
		$q->bindValue(':end_ts', $end_ts, PDO::PARAM_STR);
		$q->bindValue(':type_page_view', $type_page_view, PDO::PARAM_INT);
		$q->bindValue(':type_cart_add', $type_cart_add, PDO::PARAM_INT);
		$q->bindValue(':type_checkout_start', $type_checkout_start, PDO::PARAM_INT);
		$q->bindValue(':type_purchase', $type_purchase, PDO::PARAM_INT);
		$q->bindValue(':type_signup', $type_signup, PDO::PARAM_INT);
		$q->bindValue(':type_list_signup', $type_list_signup, PDO::PARAM_INT);
		foreach ($filter_bind as $k => $v) {
			$q->bindValue($k, $v, PDO::PARAM_STR);
		}
		$q->execute();
		$campaigns = $q->fetchAll(PDO::FETCH_OBJ);
	} catch (PDOException $e) {
		error_log('Attribution campaign query failed: ' . $e->getMessage());
	}

	$result = new LogicResult();
	$result->data = array(
		'startdate'    => $startdate,
		'enddate'      => $enddate,
		'source'       => $source,
		'campaign'     => $campaign,
		'include_test' => $include_test,
		'channels'     => $channels,
		'top_sources'  => $top_sources,
		'xvals'        => $xvals,
		'time_series'  => $time_series,
		'campaigns'    => $campaigns,
	);
	return $result;
}
?>
