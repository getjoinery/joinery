<?php
/**
 * DatabaseUpkeep — keep PostgreSQL's per-table bookkeeping current, for
 * every table, without a list.
 *
 * Autovacuum waits for 20% of a table to change before it acts. On a large
 * table that changes slowly (a mailbox of 100k messages, marked read a few at a
 * time) that can be weeks, and two things go stale meanwhile:
 *
 *   - the visibility map, which lets a count or lookup answer from an index
 *     alone. Unmarked pages send it to the table instead: a per-mailbox count
 *     that ran in 15 ms ran in 160-260 ms.
 *   - the planner's statistics. Postgres discards all of them after an unclean
 *     restart, and a restore loads none, so every table reads as never analyzed
 *     and autovacuum has no counters to act on.
 *
 * So the plan is read from Postgres's own statistics each run: a table is
 * vacuumed when a large enough share of its pages is not marked all-visible or
 * it has built up dead rows, and analyzed when it has never been or has changed
 * enough since. A table added later is covered the day it exists. The work is
 * throttled like autovacuum's and bounded by a time budget; what the budget
 * does not reach is first in line next run.
 *
 * VACUUM here is always the plain form, which never blocks reads or writes;
 * never VACUUM FULL, which locks the table.
 *
 * @version 1.0
 */
class DatabaseUpkeep {

	/** A table smaller than this (8 kB pages) is left to autovacuum: vacuuming it buys nothing. */
	const MIN_VACUUM_PAGES = 128;

	/** Vacuum when fewer than this share of a table's pages are marked all-visible. */
	const VISIBLE_FLOOR = 0.9;

	/** Rows changed (or dead) before a table is due: this many, plus CHURN_FRACTION of its rows. */
	const CHURN_BASE = 1000;
	const CHURN_FRACTION = 0.02;

	/** Throttle, as autovacuum's own default: pause this long each time the cost limit is spent. */
	const COST_DELAY_MS = 2;

	/** No table is started once this much time has gone. */
	const DEFAULT_BUDGET_SECONDS = 600;

	/**
	 * Every user table with the figures the plan reads. `name` is already
	 * quoted (schema.table), so it can be placed in a statement as it is.
	 */
	public static function tableStats(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$rows = $db->query(
			"SELECT format('%I.%I', s.schemaname, s.relname) AS name,
			        c.relpages, c.relallvisible, c.reltuples,
			        s.n_dead_tup, s.n_mod_since_analyze,
			        (s.last_analyze IS NOT NULL OR s.last_autoanalyze IS NOT NULL) AS analyzed
			 FROM pg_stat_user_tables s
			 JOIN pg_class c ON c.oid = s.relid
			 ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
		$out = array();
		foreach ($rows as $r) {
			$out[] = array(
				'name'                => (string)$r['name'],
				'relpages'            => (int)$r['relpages'],
				'relallvisible'       => (int)$r['relallvisible'],
				'reltuples'           => (float)$r['reltuples'],
				'n_dead_tup'          => (int)$r['n_dead_tup'],
				'n_mod_since_analyze' => (int)$r['n_mod_since_analyze'],
				'analyzed'            => ($r['analyzed'] === true || $r['analyzed'] === 't' || $r['analyzed'] === 1 || $r['analyzed'] === '1'),
			);
		}
		return $out;
	}

	/**
	 * What to do, from tableStats() rows. Vacuums first, the table with the
	 * most unmarked pages first; then analyzes, largest first. Each entry:
	 * ['table', 'action' => 'vacuum'|'analyze', 'reason'].
	 */
	public static function plan(array $tables): array {
		$vacuum = array();
		$analyze = array();
		foreach ($tables as $t) {
			// reltuples is -1 on a table never vacuumed or analyzed.
			$rows = max(0.0, (float)$t['reltuples']);
			$churn = self::CHURN_BASE + self::CHURN_FRACTION * $rows;
			$pages = (int)$t['relpages'];
			$unmarked = max(0, $pages - (int)$t['relallvisible']);

			$why = array();
			if ($pages >= self::MIN_VACUUM_PAGES) {
				if ($pages > 0 && ((int)$t['relallvisible'] / $pages) < self::VISIBLE_FLOOR) {
					$why[] = round(100 * $unmarked / $pages) . '% of pages not marked all-visible';
				}
				if ((int)$t['n_dead_tup'] > $churn) {
					$why[] = (int)$t['n_dead_tup'] . ' dead rows';
				}
			}
			if ($why) {
				$vacuum[] = array('table' => $t['name'], 'action' => 'vacuum', 'reason' => implode(', ', $why),
					'_order' => $unmarked);
				continue;   // VACUUM (ANALYZE) analyzes it too
			}
			if (empty($t['analyzed'])) {
				$analyze[] = array('table' => $t['name'], 'action' => 'analyze', 'reason' => 'never analyzed',
					'_order' => $pages);
			} elseif ((int)$t['n_mod_since_analyze'] > $churn) {
				$analyze[] = array('table' => $t['name'], 'action' => 'analyze',
					'reason' => (int)$t['n_mod_since_analyze'] . ' rows changed since last analyzed', '_order' => $pages);
			}
		}
		$by_order = function ($a, $b) { return $b['_order'] <=> $a['_order'] ?: strcmp($a['table'], $b['table']); };
		usort($vacuum, $by_order);
		usort($analyze, $by_order);
		$out = array();
		foreach (array_merge($vacuum, $analyze) as $step) {
			unset($step['_order']);
			$out[] = $step;
		}
		return $out;
	}

	/**
	 * Carry out plan() within $budget_seconds. Returns ['done' => [steps with
	 * 'ms'], 'failed' => [steps with 'error'], 'deferred' => n].
	 */
	public static function run(float $budget_seconds = self::DEFAULT_BUDGET_SECONDS): array {
		$db = DbConnector::get_instance()->get_db_link();
		$steps = self::plan(self::tableStats());
		$started = microtime(true);
		$done = array();
		$failed = array();
		$deferred = 0;

		// One connection is shared by every task in the runner's pass, so the
		// throttle is set for this work and put back after it.
		$db->exec("SET vacuum_cost_delay = '" . intval(self::COST_DELAY_MS) . "ms'");
		try {
			foreach ($steps as $step) {
				if (microtime(true) - $started >= $budget_seconds) {
					$deferred++;
					continue;
				}
				$t = microtime(true);
				try {
					// The name was quoted by format('%I.%I') in tableStats().
					$db->exec(($step['action'] === 'vacuum' ? 'VACUUM (ANALYZE) ' : 'ANALYZE ') . $step['table']);
					$step['ms'] = (int)round((microtime(true) - $t) * 1000);
					$done[] = $step;
				} catch (\Throwable $e) {
					// A table dropped since the plan was read, or one being
					// vacuumed by autovacuum right now: the next run retries.
					$step['error'] = substr($e->getMessage(), 0, 300);
					$failed[] = $step;
				}
			}
		} finally {
			$db->exec('RESET vacuum_cost_delay');
		}
		return array('done' => $done, 'failed' => $failed, 'deferred' => $deferred);
	}
}
