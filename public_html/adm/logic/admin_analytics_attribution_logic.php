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
function admin_analytics_attribution_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	// Revenue attribution reads ord_orders, which only exists with the store plugin.
	if (!PluginHelper::isPluginActive('store')) {
		return LogicResult::error('Store plugin is not installed');
	}

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

	$type_cart_add       = VisitorEvent::TYPE_CART_ADD;
	$type_checkout_start = VisitorEvent::TYPE_CHECKOUT_START;
	$type_purchase       = VisitorEvent::TYPE_PURCHASE;
	$type_signup         = VisitorEvent::TYPE_SIGNUP;
	$type_list_signup    = VisitorEvent::TYPE_LIST_SIGNUP;

	// Optional filter fragment applied to every query. Two forms: filter_sql
	// against the raw vse_ columns (the conversion queries, which always read
	// raw), filter_rel against the normalized columns the page-view relation
	// exposes (the visit queries, which span the raw/rollup seam).
	$filter_sql = '';
	$filter_rel = '';
	$filter_bind = array();
	if ($source !== '') {
		$filter_sql .= ' AND LOWER(COALESCE(vse_source, \'\')) = LOWER(:f_source)';
		$filter_rel .= ' AND LOWER(COALESCE(source, \'\')) = LOWER(:f_source)';
		$filter_bind[':f_source'] = $source;
	}
	if ($campaign !== '') {
		$filter_sql .= ' AND LOWER(COALESCE(vse_campaign, \'\')) = LOWER(:f_campaign)';
		$filter_rel .= ' AND LOWER(COALESCE(campaign, \'\')) = LOWER(:f_campaign)';
		$filter_bind[':f_campaign'] = $campaign;
	}

	// Page views span the raw/rollup boundary; conversions never do (conversion
	// rows are kept raw forever). The relation and its visitor measure are used
	// only for the page-view "visits" numbers.
	$pv_relation = AnalyticsRollup::pageview_relation();
	$pv_visitors = AnalyticsRollup::VISITORS;

	// === Section 1: Channels overview ===
	// Single grouped query using conditional aggregates. Joins ord_orders for revenue.
	$sql = "
		WITH
		visits AS (
			SELECT COALESCE(LOWER(NULLIF(source, '')), '(direct)') AS src,
			       {$pv_visitors} AS visit_count
			FROM {$pv_relation}
			WHERE TRUE {$filter_rel}
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
		$q->bindValue(':av_start', $start_ts, PDO::PARAM_STR);
		$q->bindValue(':av_end', $end_ts, PDO::PARAM_STR);
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
			SELECT d AS bucket,
			       COALESCE(LOWER(NULLIF(source, '')), '(direct)') AS src,
			       {$pv_visitors} AS visits
			FROM {$pv_relation}
			WHERE COALESCE(LOWER(NULLIF(source, '')), '(direct)') IN (" . implode(',', $placeholders) . ")
			  {$filter_rel}
			GROUP BY bucket, src
			ORDER BY bucket
		";

		try {
			$q = $dblink->prepare($sql_ts);
			$q->bindValue(':av_start', $start_ts, PDO::PARAM_STR);
			$q->bindValue(':av_end', $end_ts, PDO::PARAM_STR);
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
	// Visits (page views) come from the raw/rollup relation so an old, purely
	// page-view campaign still appears; conversions come from raw rows, which are
	// kept in full forever. A full outer join keeps a (src, campaign) that has one
	// but not the other.
	$campaigns = array();
	$sql_camp = "
		WITH pv AS (
			SELECT COALESCE(LOWER(NULLIF(source, '')), '(direct)') AS src,
			       COALESCE(campaign, '(none)') AS campaign,
			       {$pv_visitors} AS visits
			FROM {$pv_relation}
			WHERE TRUE {$filter_rel}
			GROUP BY src, campaign
		),
		conv AS (
			SELECT COALESCE(LOWER(NULLIF(vse_source, '')), '(direct)') AS src,
			       COALESCE(vse_campaign, '(none)') AS campaign,
			       SUM(CASE WHEN vse_type = :type_signup THEN 1 ELSE 0 END) AS signups,
			       SUM(CASE WHEN vse_type = :type_list_signup THEN 1 ELSE 0 END) AS list_signups,
			       SUM(CASE WHEN vse_type = :type_cart_add THEN 1 ELSE 0 END) AS cart_adds,
			       SUM(CASE WHEN vse_type = :type_checkout_start THEN 1 ELSE 0 END) AS checkouts,
			       SUM(CASE WHEN vse_type = :type_purchase THEN 1 ELSE 0 END) AS purchases
			FROM vse_visitor_events
			WHERE vse_type IN (:type_cart_add, :type_checkout_start, :type_purchase, :type_signup, :type_list_signup)
			  AND vse_timestamp >= :start_ts AND vse_timestamp <= :end_ts
			  {$filter_sql}
			GROUP BY src, campaign
		)
		SELECT COALESCE(pv.src, conv.src) AS src,
		       COALESCE(pv.campaign, conv.campaign) AS campaign,
		       COALESCE(pv.visits, 0) AS visits,
		       COALESCE(conv.signups, 0) AS signups,
		       COALESCE(conv.list_signups, 0) AS list_signups,
		       COALESCE(conv.cart_adds, 0) AS cart_adds,
		       COALESCE(conv.checkouts, 0) AS checkouts,
		       COALESCE(conv.purchases, 0) AS purchases
		FROM pv
		FULL OUTER JOIN conv ON pv.src = conv.src AND pv.campaign = conv.campaign
		ORDER BY purchases DESC, visits DESC
		LIMIT 50
	";
	try {
		$q = $dblink->prepare($sql_camp);
		$q->bindValue(':av_start', $start_ts, PDO::PARAM_STR);
		$q->bindValue(':av_end', $end_ts, PDO::PARAM_STR);
		$q->bindValue(':start_ts', $start_ts, PDO::PARAM_STR);
		$q->bindValue(':end_ts', $end_ts, PDO::PARAM_STR);
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
		'rollup_notice' => AnalyticsRollup::proxy_notice($startdate),
	);
	return $result;
}
?>
