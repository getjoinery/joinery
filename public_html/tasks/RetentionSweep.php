<?php
/**
 * RetentionSweep - Scheduled Task
 *
 * The one task that enforces every retention window on the platform.
 *
 * A rule is not written here. It is declared as $retention_policy on the data
 * class that owns the table, next to the $foreign_key_actions that declare the
 * rest of that table's deletion behavior — retention is deletion on a timer.
 * Adding a rule means adding a declaration and a setting; it never means adding
 * another task, another schedule and another row in the admin list.
 *
 * Two forms:
 *  - age form     age_column + age_unit (+ optional only_where) — a DELETE
 *  - method form  purge_method — a static method on the same class, for rules
 *                 that reclaim attachments, recurse, or touch the filesystem
 *
 * Every window is a setting, so an operator sets it beside the feature it
 * governs rather than inside a task edit form. 0 in any window means never
 * purge: the rule is skipped, not run with some default.
 *
 * A rule that throws is caught and recorded, and the remaining rules still run.
 * One bad table must never leave every other retention window unswept.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/ScheduledTaskRegistry.php'));

class RetentionSweep implements ScheduledTaskInterface {

	/** Units a rule may age by, mapped to the PostgreSQL interval unit. */
	const UNITS = array('days' => 'day', 'hours' => 'hour', 'minutes' => 'minute');

	public function run(array $config) {
		$settings = Globalvars::get_instance();
		$rules = ScheduledTaskRegistry::retentionRules();

		$parts = array();
		$skipped = 0;
		$failed = 0;

		foreach ($rules as $table => $rule) {
			$policy = $rule['policy'];
			$label = $policy['label'] ?? $table;

			try {
				$window = $this->resolveWindow($policy, $settings);
				if ($window === null) {
					// 0 means the operator turned this rule off.
					$skipped++;
					continue;
				}

				$result = isset($policy['purge_method'])
					? $this->runMethodRule($rule, $window)
					: $this->runAgeRule($table, $policy, $window);

				if ((int)($result['removed'] ?? 0) > 0 || !empty($result['message'])) {
					$parts[] = $label . ': ' . ($result['message'] ?? $result['removed']);
				}
			} catch (Throwable $e) {
				// Isolated deliberately. A rule pointing at a table an upgrade
				// dropped, or a purge method that throws, must not cost every
				// other table its sweep.
				$failed++;
				$parts[] = $label . ': FAILED — ' . $e->getMessage();
				error_log('RetentionSweep: rule for ' . $table . ' failed: ' . $e->getMessage());
			}
		}

		$summary = $parts ? implode('; ', $parts) : 'nothing to remove';
		if ($skipped) {
			$summary .= ' (' . $skipped . ' rule(s) off)';
		}

		return array(
			'status' => $failed ? 'error' : 'success',
			'message' => $summary,
		);
	}

	/**
	 * Resolve a rule's window from its setting.
	 *
	 * Returns null when the rule should not run at all, and 0 for the windowless
	 * rules that are unconditional by nature (window_setting declared null).
	 *
	 * @return int|null
	 */
	private function resolveWindow(array $policy, $settings) {
		if (!array_key_exists('window_setting', $policy)) {
			throw new Exception('retention policy declares no window_setting');
		}
		if ($policy['window_setting'] === null) {
			return 0;
		}

		$value = $settings->get_setting($policy['window_setting']);
		if ($value === null || $value === '') {
			throw new Exception('setting "' . $policy['window_setting'] . '" is not declared');
		}
		$window = (int)$value;

		return $window > 0 ? $window : null;
	}

	/** Age form: delete rows older than the window. */
	private function runAgeRule($table, array $policy, $window) {
		$column = $policy['age_column'] ?? null;
		$unit = $policy['age_unit'] ?? null;
		if (!$column || !isset(self::UNITS[$unit])) {
			throw new Exception('retention policy needs age_column and a valid age_unit');
		}

		// Table, column and unit come from a class declaration, never from
		// input; the window is bound.
		$sql = 'DELETE FROM ' . $table
			. ' WHERE ' . $column . " < now() - (INTERVAL '1 " . self::UNITS[$unit] . "' * :window)";
		if (!empty($policy['only_where'])) {
			$sql .= ' AND (' . $policy['only_where'] . ')';
		}

		$q = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$q->execute(array(':window' => (int)$window));
		$removed = $q->rowCount();

		return array(
			'removed' => $removed,
			'message' => $removed ? $removed . ' row(s)' : '',
		);
	}

	/** Method form: hand the window to a static method on the owning class. */
	private function runMethodRule(array $rule, $window) {
		$class = $rule['class'];
		$method = $rule['policy']['purge_method'];

		if (!is_callable(array($class, $method))) {
			throw new Exception($class . '::' . $method . '() is not callable');
		}

		$result = call_user_func(array($class, $method), $window);
		if (!is_array($result)) {
			throw new Exception($class . '::' . $method . '() returned no result array');
		}
		return $result;
	}
}
