<?php
/**
 * Visitor statistics rollup — the small, permanent summary of old page-view
 * traffic.
 *
 * Once page-view rows in vse_visitor_events age past the analytics retention
 * window they are collapsed into these two tables and the individual rows are
 * deleted (see VisitorEvent::rollupAndPrunePageViews and its $retention_policy).
 * Conversion rows are never rolled up — they stay raw forever — so nothing here
 * carries revenue, an order link, or a per-visitor sequence.
 *
 * Two tables because a distinct-visitor count cannot be recovered from a summed
 * rollup (you cannot re-distinct across pre-summed rows):
 *
 *  - VisitorStatsRollup  — one row per day per dimension combination, carrying an
 *    event count. Reproduces every SUM/COUNT(*) page-view report exactly.
 *  - VisitorDailyUniques — one row per day per type, carrying the distinct
 *    visitor count. Preserves the "unique visitors per day" headline; finer
 *    unique counts (per page, per source) on old data are given up, and reports
 *    fall back to the event count as the stand-in — see AnalyticsRollup.
 *
 * Both are read only through raw SQL in the analytics reports and the reporting
 * helper; the Multi classes exist to satisfy discovery and the model contract.
 *
 * @version 1.0.0
 */

class VisitorStatsRollupException extends SystemBaseException {}

class VisitorStatsRollup extends SystemBase {
	public static $prefix = 'vsr';
	public static $tablename = 'vsr_visitor_stats_rollup';
	public static $access_class = 'content';
	public static $pkey_column = 'vsr_visitor_stats_rollup_id';

	public static $field_specifications = array(
		'vsr_visitor_stats_rollup_id' => array('type' => 'int8', 'serial' => true, 'is_primary_key' => true, 'is_nullable' => false),
		// One rolled-up day. Indexed with the type because every report scans a
		// date range and filters to page views.
		'vsr_day'         => array('type' => 'date', 'is_nullable' => false, 'index_with' => array('vsr_type')),
		'vsr_type'        => array('type' => 'int2', 'is_nullable' => false),
		// Path only — the query string is stripped before grouping, so
		// /product?id=1 and /product?id=2 collapse to one /product row.
		'vsr_page'        => array('type' => 'text'),
		'vsr_source'      => array('type' => 'varchar(255)'),
		'vsr_campaign'    => array('type' => 'varchar(255)'),
		'vsr_medium'      => array('type' => 'varchar(255)'),
		'vsr_content'     => array('type' => 'varchar(255)'),
		'vsr_is_404'      => array('type' => 'bool'),
		'vsr_event_count' => array('type' => 'int8', 'is_nullable' => false),
	);
}

class MultiVisitorStatsRollup extends SystemMultiBase {
	protected static $model_class = 'VisitorStatsRollup';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('vsr_visitor_stats_rollup', array(), $this->order_by, $only_count, $debug);
	}
}

class VisitorDailyUniquesException extends SystemBaseException {}

class VisitorDailyUniques extends SystemBase {
	public static $prefix = 'vsu';
	public static $tablename = 'vsu_visitor_daily_uniques';
	public static $access_class = 'content';
	public static $pkey_column = 'vsu_visitor_daily_uniques_id';

	public static $field_specifications = array(
		'vsu_visitor_daily_uniques_id' => array('type' => 'int8', 'serial' => true, 'is_primary_key' => true, 'is_nullable' => false),
		'vsu_day'             => array('type' => 'date', 'is_nullable' => false, 'index_with' => array('vsu_type')),
		'vsu_type'            => array('type' => 'int2', 'is_nullable' => false),
		'vsu_unique_visitors' => array('type' => 'int8', 'is_nullable' => false),
	);
}

class MultiVisitorDailyUniques extends SystemMultiBase {
	protected static $model_class = 'VisitorDailyUniques';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('vsu_visitor_daily_uniques', array(), $this->order_by, $only_count, $debug);
	}
}
