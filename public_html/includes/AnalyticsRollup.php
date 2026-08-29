<?php
/**
 * The reporting seam between raw visitor events and their rolled-up summary.
 *
 * Recent page views live as individual rows in vse_visitor_events; page views
 * older than the retention window live as daily totals in vsr_visitor_stats_rollup
 * (see VisitorEvent::rollupAndPrunePageViews). A report that spans the boundary
 * must read both. This class hands it one relation that unions the two, so each
 * report is written against a single shape instead of branching by hand.
 *
 * The union is seamless because the purge maintains the invariant that a day is
 * in exactly one side: it rolls a day up and deletes that day's raw rows in the
 * same transaction, so no day is ever counted twice and none falls in a gap. No
 * report needs to know the retention setting to read across the boundary.
 *
 * Counting visitors across the seam:
 *   Raw rows carry a real visitor id; rollup rows carry only an event count and a
 *   NULL visitor id. VISITORS counts distinct real visitors where the rows are
 *   raw and falls back to the event count where they are rolled up. The ranking a
 *   report shows is preserved; only the absolute number changes meaning — page
 *   views, not unique people — for the rolled-up portion. proxy_boundary() gives
 *   the date before which that substitution applies, for a report that labels it.
 */
class AnalyticsRollup {

	/**
	 * Visitor measure across the raw/rollup seam. Distinct real visitors for the
	 * raw (recent) rows, plus the event-count stand-in for the rolled-up rows.
	 * Select it from pageview_relation(), which exposes vid and cnt.
	 */
	const VISITORS = 'COUNT(DISTINCT vid) + COALESCE(SUM(CASE WHEN vid IS NULL THEN cnt ELSE 0 END), 0)';

	/**
	 * A page-view relation spanning raw rows and rollup rows over [:av_start,
	 * :av_end] (both bound as 'Y-m-d H:i:s' timestamp strings; a date column
	 * compares against them cleanly). Only true page views (TYPE_PAGE_VIEW) are
	 * included — the cookie-consent and coupon-attempt rows that share the purge
	 * are not visitor traffic.
	 *
	 * Exposed columns: d (date), page, source, campaign, medium, content,
	 * is_404 (bool), vid (visitor id, NULL for rolled-up rows), cnt (1 raw / the
	 * event count rolled up).
	 *
	 * Wrap it in an outer query that GROUPs by whatever key the report needs and
	 * measures with VISITORS. Bind :av_start and :av_end once.
	 */
	public static function pageview_relation() {
		$pv = (int) VisitorEvent::TYPE_PAGE_VIEW;
		return "(
			SELECT date(vse_timestamp) AS d, vse_page AS page, vse_source AS source,
			       vse_campaign AS campaign, vse_medium AS medium, vse_content AS content,
			       COALESCE(vse_is_404, FALSE) AS is_404, vse_visitor_id AS vid, 1::bigint AS cnt
			FROM vse_visitor_events
			WHERE vse_type = {$pv}
			  AND vse_timestamp >= :av_start AND vse_timestamp <= :av_end
			UNION ALL
			SELECT vsr_day AS d, vsr_page AS page, vsr_source AS source,
			       vsr_campaign AS campaign, vsr_medium AS medium, vsr_content AS content,
			       COALESCE(vsr_is_404, FALSE) AS is_404, NULL::varchar AS vid, vsr_event_count AS cnt
			FROM vsr_visitor_stats_rollup
			WHERE vsr_type = {$pv}
			  AND vsr_day >= :av_start AND vsr_day <= :av_end
		) pv";
	}

	/**
	 * The date before which visitor counts are the event-count stand-in rather
	 * than distinct people — i.e. the oldest day still kept raw. NULL when
	 * retention is off (window 0), because then nothing is ever rolled up.
	 *
	 * A report compares its requested start against this to decide whether to
	 * show the "counts before X are page views, not unique visitors" note.
	 *
	 * @return string|null  'Y-m-d', or NULL when retention is disabled
	 */
	public static function proxy_boundary() {
		$days = (int) Globalvars::get_instance()->get_setting('analytics_retention_days');
		if ($days <= 0) {
			return null;
		}
		return date('Y-m-d', strtotime('-' . $days . ' days'));
	}

	/**
	 * The one-line note a report shows when its range reaches back before the
	 * boundary, or '' when it does not (or retention is off). Centralised so the
	 * wording is identical on every analytics page.
	 *
	 * @param string $startdate  the report's requested start, any strtotime form
	 * @return string
	 */
	public static function proxy_notice($startdate) {
		$boundary = self::proxy_boundary();
		if ($boundary === null || !$startdate) {
			return '';
		}
		if (strtotime($startdate) >= strtotime($boundary)) {
			return '';
		}
		return 'Visitor counts before ' . $boundary . ' are page-view counts, not unique visitors — '
			. 'individual visitor detail is not kept past the analytics retention window. '
			. 'Conversion totals and revenue are exact for the whole range.';
	}
}
