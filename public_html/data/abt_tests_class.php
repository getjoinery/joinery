<?php
/**
 * AbTest — a multi-armed bandit (epsilon-greedy) A/B test attached to a single entity.
 *
 * This file also hosts the static runtime (assignment, flushing, cache invalidation,
 * crown) and the AbTestVersionsPanel admin UI component. That follows the standard
 * Joinery idiom of carrying static helpers alongside the data class.
 *
 * @see /specs/ab_testing_framework.md
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
require_once(PathHelper::getIncludePath('data/abv_variants_class.php'));
require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));

class AbTestException extends SystemBaseException {}

class AbTest extends SystemBase {
	public static $prefix = 'abt';
	public static $tablename = 'abt_tests';
	public static $pkey_column = 'abt_test_id';

	const STATUS_DRAFT    = 'draft';
	const STATUS_ACTIVE   = 'active';
	const STATUS_PAUSED   = 'paused';
	const STATUS_CROWNED  = 'crowned';

	public static $field_specifications = array(
		'abt_test_id'                  => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'abt_entity_type'              => array('type' => 'varchar(64)', 'is_nullable' => false),
		'abt_entity_id'                => array('type' => 'int8', 'is_nullable' => false),
		'abt_status'                   => array('type' => 'varchar(16)', 'default' => 'draft'),
		'abt_conversion_event_type'    => array('type' => 'int2', 'is_nullable' => true),
		'abt_epsilon'                  => array('type' => 'decimal(4,3)', 'default' => 0.100),
		'abt_cold_start_threshold'     => array('type' => 'int4', 'default' => 100),
		'abt_winner_abv_variant_id'    => array('type' => 'int8', 'is_nullable' => true),
		'abt_create_time'              => array('type' => 'timestamp(6)', 'default' => 'now()'),
		'abt_modified_time'            => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'abt_delete_time'              => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	/**
	 * Request-scoped stash of assignments made during this request.
	 * Shape: [ [test_id, variant_id], ... ] — flushed to the DB inside
	 * SessionControl::save_visitor_event() after its bot filter passes.
	 */
	private static $request_assignments = array();

	// ---- Runtime: variant selection + rendering hook ------------------------

	/**
	 * Public-render hook. Call this after loading an entity and before reading
	 * any of its testable fields. Picks a variant, stashes the assignment for
	 * reward accounting, and mutates the entity's fields in memory.
	 *
	 * Does nothing if the entity's class hasn't opted in or no active test
	 * exists for the entity.
	 */
	public static function apply_variant($entity) {
		if (!is_object($entity)) return;
		$class = get_class($entity);
		if (empty($class::$ab_testable)) return;

		$test = self::get_active_test_for_entity($class, (int)$entity->key);
		if (!$test) return;
		if ($test->get('abt_status') !== self::STATUS_ACTIVE) return;

		// Bust the static cache on first render after activation, and ensure
		// this URL is flagged nostatic so future requests skip the cache and
		// run PHP (where assignment happens).
		if (isset($_SERVER['REQUEST_URI'])) {
			StaticPageCache::markAsNostatic($_SERVER['REQUEST_URI']);
		}

		// Load variants for this test
		$variants = new MultiAbTestVariant(
			['test_id' => $test->key, 'deleted' => false],
			['abv_variant_id' => 'ASC']
		);
		$variants->load();
		if ($variants->count() === 0) return;

		// Cookie-sticky assignment — preserves attribution across visits.
		$cookie_name = 'ab_' . $test->key;
		$chosen = null;
		if (isset($_COOKIE[$cookie_name])) {
			$stored_id = (int)$_COOKIE[$cookie_name];
			foreach ($variants as $v) {
				if ((int)$v->key === $stored_id) {
					$chosen = $v;
					break;
				}
			}
		}

		if (!$chosen) {
			$chosen = self::select_variant($test, $variants);
			if ($chosen) {
				setcookie($cookie_name, (string)$chosen->key, [
					'expires'  => time() + 30 * 86400,
					'path'     => '/',
					'secure'   => true,
					'samesite' => 'Lax',
					'httponly' => true,
				]);
				// Reflect into $_COOKIE so subsequent calls within the same
				// request see the value.
				$_COOKIE[$cookie_name] = (string)$chosen->key;
			}
		}

		if (!$chosen) return;

		// Stash for the visitor-event flush. Dedup by (test_id, variant_id).
		$pair = [(int)$test->key, (int)$chosen->key];
		$already = false;
		foreach (self::$request_assignments as $a) {
			if ($a[0] === $pair[0] && $a[1] === $pair[1]) { $already = true; break; }
		}
		if (!$already) self::$request_assignments[] = $pair;

		// Apply overrides in memory. Absence = inherit; present = explicit (even if empty).
		$overrides = $chosen->get_json_decoded('abv_overrides');
		if (is_array($overrides)) {
			$allowed = isset($class::$ab_testable_fields) ? $class::$ab_testable_fields : [];
			foreach ($overrides as $field => $value) {
				if (in_array($field, $allowed, true)) {
					$entity->set($field, $value);
				}
			}
		}
	}

	/**
	 * Epsilon-greedy selection with cold-start guard.
	 */
	protected static function select_variant($test, $variants) {
		$cold_threshold = (int)$test->get('abt_cold_start_threshold');
		$epsilon = (float)$test->get('abt_epsilon');

		$any_cold = false;
		foreach ($variants as $v) {
			if ((int)$v->get('abv_trials') < $cold_threshold) { $any_cold = true; break; }
		}

		$list = [];
		foreach ($variants as $v) $list[] = $v;
		if (empty($list)) return null;

		if ($any_cold || mt_rand(0, 999) / 1000.0 < $epsilon) {
			return $list[mt_rand(0, count($list) - 1)];
		}

		// Exploit: argmax(rewards / trials)
		$best = null;
		$best_rate = -1.0;
		foreach ($list as $v) {
			$trials = (int)$v->get('abv_trials');
			$rate = $trials > 0 ? ((int)$v->get('abv_rewards')) / $trials : 0.0;
			if ($rate > $best_rate) {
				$best_rate = $rate;
				$best = $v;
			}
		}
		return $best ?: $list[0];
	}

	// ---- Runtime: DB accounting (called from save_visitor_event) ------------

	/**
	 * Commit trial + reward increments for this request. Invoked by
	 * SessionControl::save_visitor_event() *after* its bot filter passes and
	 * after the visitor event row has been inserted — so every counter update
	 * inherits the platform's canonical bot filter for free.
	 *
	 * Trials: one per stashed (test_id, variant_id) for this request.
	 * Rewards: for every active test whose abt_conversion_event_type matches
	 *          $vse_type, increments the cookied variant (if it belongs to
	 *          that test).
	 */
	public static function flush_request_accounting($vse_type) {
		$dblink = DbConnector::get_instance()->get_db_link();

		// Trials — one UPDATE per stashed assignment
		foreach (self::$request_assignments as $pair) {
			list($test_id, $variant_id) = $pair;
			try {
				$sql = 'UPDATE abv_variants
						   SET abv_trials = abv_trials + 1,
						       abv_modified_time = now()
						 WHERE abv_variant_id = ? AND abv_abt_test_id = ?';
				$q = $dblink->prepare($sql);
				$q->execute([$variant_id, $test_id]);
			} catch (\Throwable $e) {
				error_log('AbTest::flush_request_accounting trial error: ' . $e->getMessage());
			}
		}
		self::$request_assignments = array();

		// Rewards — active tests whose conversion type matches this event
		$vse_type = (int)$vse_type;
		if ($vse_type <= 0) return;

		try {
			$sql = 'SELECT abt_test_id
					  FROM abt_tests
					 WHERE abt_status = ?
					   AND abt_delete_time IS NULL
					   AND abt_conversion_event_type = ?';
			$q = $dblink->prepare($sql);
			$q->execute([self::STATUS_ACTIVE, $vse_type]);
			$test_rows = $q->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			error_log('AbTest::flush_request_accounting lookup error: ' . $e->getMessage());
			return;
		}

		foreach ($test_rows as $row) {
			$test_id = (int)$row['abt_test_id'];
			$cookie_name = 'ab_' . $test_id;
			if (empty($_COOKIE[$cookie_name])) continue;
			$variant_id = (int)$_COOKIE[$cookie_name];
			if ($variant_id <= 0) continue;

			try {
				$sql = 'UPDATE abv_variants
						   SET abv_rewards = abv_rewards + 1,
						       abv_modified_time = now()
						 WHERE abv_variant_id = ? AND abv_abt_test_id = ?';
				$q = $dblink->prepare($sql);
				$q->execute([$variant_id, $test_id]);
			} catch (\Throwable $e) {
				error_log('AbTest::flush_request_accounting reward error: ' . $e->getMessage());
			}
		}
	}

	// ---- Lookups ------------------------------------------------------------

	/**
	 * Find the non-deleted test attached to a given entity, if any. Returns
	 * the test regardless of status (draft/active/paused/crowned); callers
	 * decide how to react based on abt_status.
	 */
	public static function get_active_test_for_entity($entity_class, $entity_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		try {
			$sql = 'SELECT abt_test_id FROM abt_tests
					 WHERE abt_entity_type = ?
					   AND abt_entity_id = ?
					   AND abt_delete_time IS NULL
					 ORDER BY abt_test_id DESC
					 LIMIT 1';
			$q = $dblink->prepare($sql);
			$q->execute([$entity_class, (int)$entity_id]);
			$row = $q->fetch(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			error_log('AbTest::get_active_test_for_entity error: ' . $e->getMessage());
			return null;
		}
		if (!$row) return null;
		$test = new AbTest($row['abt_test_id'], true);
		return $test->key ? $test : null;
	}

	// ---- Crown --------------------------------------------------------------

	/**
	 * Copy the winner variant's overrides onto the parent entity and save it.
	 * Does not commit or roll back — the crown caller wraps this in a DB
	 * transaction along with the status update and winner assignment.
	 */
	public static function copy_winner_onto_parent(AbTest $test) {
		$winner_id = (int)$test->get('abt_winner_abv_variant_id');
		if (!$winner_id) return;

		$variant = new AbTestVariant($winner_id, true);
		if (!$variant->key) return;

		$class = $test->get('abt_entity_type');
		if (!class_exists($class)) return;
		$entity_id = (int)$test->get('abt_entity_id');
		$entity = new $class($entity_id, true);
		if (!$entity->key) return;

		$allowed = isset($class::$ab_testable_fields) ? $class::$ab_testable_fields : [];
		$overrides = $variant->get_json_decoded('abv_overrides');
		if (is_array($overrides)) {
			foreach ($overrides as $field => $value) {
				if (in_array($field, $allowed, true)) {
					$entity->set($field, $value);
				}
			}
		}
		$entity->save();
	}

	// ---- Cache invalidation dispatcher --------------------------------------

	/**
	 * Single dispatcher for every lifecycle event that needs the cache busted.
	 * Prefers per-URL invalidation when the entity declares get_tested_cache_urls();
	 * falls back to clearAll() otherwise.
	 */
	public static function invalidate_cache_for_test(AbTest $test) {
		$class = $test->get('abt_entity_type');
		if (!class_exists($class)) {
			StaticPageCache::clearAll();
			return;
		}
		$entity = new $class((int)$test->get('abt_entity_id'), true);
		if (!$entity->key) {
			StaticPageCache::clearAll();
			return;
		}
		if (method_exists($entity, 'get_tested_cache_urls')) {
			$urls = $entity->get_tested_cache_urls();
			if (is_array($urls)) {
				foreach ($urls as $url) {
					StaticPageCache::invalidateUrl($url);
				}
				return;
			}
		}
		StaticPageCache::clearAll();
	}
}

class MultiAbTest extends SystemMultiBase {
	protected static $model_class = 'AbTest';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['entity_type'])) {
			$filters['abt_entity_type'] = array($this->options['entity_type'], PDO::PARAM_STR);
		}

		if (isset($this->options['entity_id'])) {
			$filters['abt_entity_id'] = array($this->options['entity_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['status'])) {
			$filters['abt_status'] = array($this->options['status'], PDO::PARAM_STR);
		}

		if (isset($this->options['conversion_event_type'])) {
			$filters['abt_conversion_event_type'] = array($this->options['conversion_event_type'], PDO::PARAM_INT);
		}

		if (isset($this->options['deleted'])) {
			$filters['abt_delete_time'] = $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL';
		}

		return $this->_get_resultsv2('abt_tests', $filters, $this->order_by, $only_count, $debug);
	}
}


/**
 * Admin UI: A/B Test panel rendered on a testable entity's edit page.
 *
 * Mount it from any testable entity's admin edit surface:
 *
 *   AbTestVersionsPanel::render('Page', $page_id);
 *
 * Handles its own POST actions (create, edit, delete variants; activate / pause /
 * crown / reset counters; create test) so the host page does not have to know
 * anything about the framework.
 */
class AbTestVersionsPanel {

	/**
	 * The public entry point. Handles POST → redirect and then renders the panel.
	 */
	public static function render($entity_class, $entity_id) {
		if (!class_exists($entity_class)) return;
		if (empty($entity_class::$ab_testable)) return;

		$entity_id = (int)$entity_id;
		if ($entity_id <= 0) return;

		$session = SessionControl::get_instance();
		$session->check_permission(5);

		// Handle POST actions — all actions are namespaced with abtest_action.
		if (!empty($_POST['abtest_action'])
			&& (string)($_POST['abtest_entity_class'] ?? '') === $entity_class
			&& (int)($_POST['abtest_entity_id'] ?? 0) === $entity_id) {
			self::handle_post($entity_class, $entity_id);
			// Any action redirects; if we return, something went wrong and we
			// fall through to render the panel with a user-visible message.
		}

		$test = AbTest::get_active_test_for_entity($entity_class, $entity_id);
		$entity = new $entity_class($entity_id, true);

		echo '<div class="card mt-3"><div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">';
		echo '<h5 class="mb-0">A/B Test</h5>';
		if ($test) {
			echo '<span class="badge bg-' . self::status_color($test->get('abt_status')) . '">' . htmlspecialchars($test->get('abt_status')) . '</span>';
		}
		echo '</div><div class="card-body">';

		self::render_shared_entity_disclosure($entity);

		if (!$test) {
			self::render_start_form($entity_class, $entity_id);
		} else {
			self::render_test_panel($entity_class, $entity_id, $test, $entity);
		}

		echo '</div></div>';
	}

	// ---- POST routing -------------------------------------------------------

	protected static function handle_post($entity_class, $entity_id) {
		$action = $_POST['abtest_action'];
		$test = AbTest::get_active_test_for_entity($entity_class, $entity_id);

		try {
			switch ($action) {
				case 'create_test':
					self::action_create_test($entity_class, $entity_id);
					break;
				case 'activate':
					self::action_set_status($test, AbTest::STATUS_ACTIVE);
					break;
				case 'pause':
					self::action_set_status($test, AbTest::STATUS_PAUSED);
					break;
				case 'crown':
					self::action_crown($test);
					break;
				case 'reset_counters':
					self::action_reset_counters($test);
					break;
				case 'save_settings':
					self::action_save_settings($test);
					break;
				case 'save_variant':
					self::action_save_variant($entity_class, $test);
					break;
				case 'delete_variant':
					self::action_delete_variant($test);
					break;
			}
		} catch (\Throwable $e) {
			error_log('AbTestVersionsPanel action error: ' . $e->getMessage());
			$_SESSION['abtest_flash_error'] = $e->getMessage();
		}

		$redirect = $_POST['abtest_redirect'] ?? ($_SERVER['REQUEST_URI'] ?? '/admin/admin_ab_tests');
		header('Location: ' . $redirect);
		exit();
	}

	protected static function action_create_test($entity_class, $entity_id) {
		$existing = AbTest::get_active_test_for_entity($entity_class, $entity_id);
		if ($existing) return; // idempotent — one live test per entity
		$test = new AbTest(null);
		$test->set('abt_entity_type', $entity_class);
		$test->set('abt_entity_id', $entity_id);
		$test->set('abt_status', AbTest::STATUS_DRAFT);
		$test->set('abt_epsilon', 0.100);
		$test->set('abt_cold_start_threshold', 100);
		$test->save();

		// Seed with a control variant that inherits the parent's current values
		$variant = new AbTestVariant(null);
		$variant->set('abv_abt_test_id', $test->key);
		$variant->set('abv_name', 'control');
		$variant->set('abv_overrides', []);
		$variant->save();
	}

	protected static function action_set_status($test, $status) {
		if (!$test) return;
		$test->set('abt_status', $status);
		$test->set('abt_modified_time', 'now()');
		$test->save();
		AbTest::invalidate_cache_for_test($test);
	}

	protected static function action_crown($test) {
		if (!$test) return;
		$winner_id = isset($_POST['winner_abv_variant_id']) ? (int)$_POST['winner_abv_variant_id'] : 0;
		if (!$winner_id) throw new Exception('No winner selected.');

		$dblink = DbConnector::get_instance()->get_db_link();
		$dblink->beginTransaction();
		try {
			$test->set('abt_status', AbTest::STATUS_CROWNED);
			$test->set('abt_winner_abv_variant_id', $winner_id);
			$test->set('abt_modified_time', 'now()');
			$test->save();
			AbTest::copy_winner_onto_parent($test);
			$dblink->commit();
		} catch (\Throwable $e) {
			$dblink->rollBack();
			throw $e;
		}
		AbTest::invalidate_cache_for_test($test);
	}

	protected static function action_reset_counters($test) {
		if (!$test) return;
		$dblink = DbConnector::get_instance()->get_db_link();
		$sql = 'UPDATE abv_variants
				   SET abv_trials = 0, abv_rewards = 0, abv_modified_time = now()
				 WHERE abv_abt_test_id = ?';
		$q = $dblink->prepare($sql);
		$q->execute([(int)$test->key]);
	}

	protected static function action_save_settings($test) {
		if (!$test) return;
		if (isset($_POST['abt_conversion_event_type'])) {
			$val = $_POST['abt_conversion_event_type'];
			$test->set('abt_conversion_event_type', $val === '' ? null : (int)$val);
		}
		if (isset($_POST['abt_epsilon'])) {
			$test->set('abt_epsilon', (float)$_POST['abt_epsilon']);
		}
		if (isset($_POST['abt_cold_start_threshold'])) {
			$test->set('abt_cold_start_threshold', (int)$_POST['abt_cold_start_threshold']);
		}
		$test->set('abt_modified_time', 'now()');
		$test->save();
	}

	protected static function action_save_variant($entity_class, $test) {
		if (!$test) return;
		$variant_id = (int)($_POST['abv_variant_id'] ?? 0);
		$variant = $variant_id ? new AbTestVariant($variant_id, true) : new AbTestVariant(null);
		if ($variant_id && !$variant->key) return;

		$variant->set('abv_abt_test_id', (int)$test->key);
		$variant->set('abv_name', trim((string)($_POST['abv_name'] ?? '')) ?: 'variant');

		$allowed = isset($entity_class::$ab_testable_fields) ? $entity_class::$ab_testable_fields : [];
		$overrides = [];
		foreach ($allowed as $field) {
			$checkbox_key = 'override_' . $field;
			if (empty($_POST[$checkbox_key])) continue; // unchecked = inherit
			$raw = $_POST['abv_override'][$field] ?? '';
			$overrides[$field] = self::maybe_decode_json($entity_class, $field, $raw);
		}
		$variant->set('abv_overrides', $overrides);
		$variant->set('abv_modified_time', 'now()');
		$variant->save();
	}

	protected static function action_delete_variant($test) {
		if (!$test) return;
		$variant_id = (int)($_POST['abv_variant_id'] ?? 0);
		if (!$variant_id) return;
		$variant = new AbTestVariant($variant_id, true);
		if ($variant->key && (int)$variant->get('abv_abt_test_id') === (int)$test->key) {
			$variant->soft_delete();
		}
	}

	/**
	 * For JSON-typed testable fields (pag_component_layout, pac_config, etc.),
	 * the admin form submits a JSON string which we decode once so the value
	 * stored in abv_overrides is a real array — not a double-encoded string.
	 */
	protected static function maybe_decode_json($entity_class, $field, $raw_value) {
		$spec = $entity_class::$field_specifications[$field] ?? null;
		if (!$spec) return $raw_value;
		$type = $spec['type'] ?? '';
		if ($type !== 'json' && $type !== 'jsonb') return $raw_value;
		if ($raw_value === '' || $raw_value === null) return null;
		if (is_array($raw_value)) return $raw_value;
		$decoded = json_decode($raw_value, true);
		return $decoded !== null ? $decoded : $raw_value;
	}

	// ---- Rendering helpers --------------------------------------------------

	protected static function status_color($status) {
		switch ($status) {
			case AbTest::STATUS_ACTIVE:  return 'success';
			case AbTest::STATUS_PAUSED:  return 'warning';
			case AbTest::STATUS_CROWNED: return 'primary';
			default: return 'secondary';
		}
	}

	protected static function render_shared_entity_disclosure($entity) {
		if (!method_exists($entity, 'get_test_contexts')) return;
		$contexts = $entity->get_test_contexts();
		if (!is_array($contexts) || empty($contexts)) return;

		if (count($contexts) === 1) {
			echo '<p class="text-muted small mb-3">Appears on: ';
			echo '<a href="' . htmlspecialchars($contexts[0]['url']) . '">' . htmlspecialchars($contexts[0]['label']) . '</a>';
			echo '</p>';
			return;
		}

		echo '<div class="alert alert-warning">';
		echo '<strong>This component appears on multiple pages:</strong><ul class="mb-0">';
		foreach ($contexts as $ctx) {
			echo '<li><a href="' . htmlspecialchars($ctx['url']) . '">' . htmlspecialchars($ctx['label']) . '</a></li>';
		}
		echo '</ul>';
		echo '<small>Launching a test will affect all of them.</small>';
		echo '</div>';
	}

	protected static function render_start_form($entity_class, $entity_id) {
		echo '<p>No A/B test attached to this entity yet.</p>';
		echo '<form method="post">';
		self::hidden_keys($entity_class, $entity_id);
		echo '<input type="hidden" name="abtest_action" value="create_test">';
		echo '<button type="submit" class="btn btn-sm btn-primary">Start A/B test</button>';
		echo '</form>';
	}

	protected static function render_test_panel($entity_class, $entity_id, AbTest $test, $entity) {
		if (!empty($_SESSION['abtest_flash_error'])) {
			echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['abtest_flash_error']) . '</div>';
			unset($_SESSION['abtest_flash_error']);
		}

		// Variants
		$variants = new MultiAbTestVariant(
			['test_id' => (int)$test->key, 'deleted' => false],
			['abv_variant_id' => 'ASC']
		);
		$variants->load();

		self::render_status_actions($entity_class, $entity_id, $test, $variants);
		self::render_leaderboard($test, $variants);
		self::render_variants_crud($entity_class, $entity_id, $test, $entity, $variants);
		self::render_settings($entity_class, $entity_id, $test);
	}

	protected static function render_status_actions($entity_class, $entity_id, AbTest $test, $variants) {
		$status = $test->get('abt_status');
		echo '<div class="mb-3">';

		if ($status === AbTest::STATUS_DRAFT || $status === AbTest::STATUS_PAUSED) {
			echo self::action_form($entity_class, $entity_id, 'activate', 'Activate', 'btn-success btn-sm me-2');
		}
		if ($status === AbTest::STATUS_ACTIVE) {
			echo self::action_form($entity_class, $entity_id, 'pause', 'Pause', 'btn-warning btn-sm me-2');
		}

		if ($status !== AbTest::STATUS_CROWNED && $variants->count() > 0) {
			echo '<form method="post" class="d-inline-block me-2" onsubmit="return confirm(\'Crown this variant? This copies its values onto the parent entity and ends the test.\')">';
			self::hidden_keys($entity_class, $entity_id);
			echo '<input type="hidden" name="abtest_action" value="crown">';
			echo '<div class="input-group input-group-sm">';
			echo '<select name="winner_abv_variant_id" class="form-select form-select-sm" required>';
			echo '<option value="">-- Crown variant --</option>';
			foreach ($variants as $v) {
				$label = $v->get('abv_name') ?: ('#' . $v->key);
				$rate = $v->conversion_rate();
				$rate_str = $rate === null ? 'no data' : number_format($rate * 100, 2) . '%';
				echo '<option value="' . (int)$v->key . '">' . htmlspecialchars($label) . ' (' . $rate_str . ')</option>';
			}
			echo '</select>';
			echo '<button type="submit" class="btn btn-primary btn-sm">Crown</button>';
			echo '</div></form>';
		}

		echo '<form method="post" class="d-inline-block" onsubmit="return confirm(\'Zero trials and rewards for every variant of this test?\')">';
		self::hidden_keys($entity_class, $entity_id);
		echo '<input type="hidden" name="abtest_action" value="reset_counters">';
		echo '<button type="submit" class="btn btn-outline-secondary btn-sm">Reset Counters</button>';
		echo '</form>';

		echo '</div>';
	}

	protected static function render_leaderboard(AbTest $test, $variants) {
		echo '<h6 class="mt-3">Leaderboard</h6>';
		if ($variants->count() === 0) {
			echo '<p class="text-muted">No variants yet.</p>';
			return;
		}

		// Identify the leader (highest conversion rate among variants with trials)
		$leader_id = null;
		$leader_rate = -1.0;
		foreach ($variants as $v) {
			$rate = $v->conversion_rate();
			if ($rate !== null && $rate > $leader_rate) {
				$leader_rate = $rate;
				$leader_id = (int)$v->key;
			}
		}

		$winner_id = (int)$test->get('abt_winner_abv_variant_id');

		echo '<table class="table table-sm mb-3"><thead><tr>';
		echo '<th>Variant</th><th class="text-end">Trials</th><th class="text-end">Rewards</th><th class="text-end">Rate</th>';
		echo '</tr></thead><tbody>';
		foreach ($variants as $v) {
			$id = (int)$v->key;
			$is_leader = $id === $leader_id;
			$is_winner = $id === $winner_id;
			$name = $v->get('abv_name') ?: ('#' . $v->key);
			$rate = $v->conversion_rate();
			$rate_str = $rate === null ? '—' : number_format($rate * 100, 2) . '%';

			echo '<tr' . ($is_winner ? ' class="table-primary"' : ($is_leader ? ' class="table-success"' : '')) . '>';
			echo '<td>' . htmlspecialchars($name);
			if ($is_winner) echo ' <span class="badge bg-primary">Winner</span>';
			elseif ($is_leader) echo ' <span class="badge bg-success">Leader</span>';
			echo '</td>';
			echo '<td class="text-end">' . (int)$v->get('abv_trials') . '</td>';
			echo '<td class="text-end">' . (int)$v->get('abv_rewards') . '</td>';
			echo '<td class="text-end">' . $rate_str . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	protected static function render_variants_crud($entity_class, $entity_id, AbTest $test, $entity, $variants) {
		$fields = $entity_class::$ab_testable_fields ?? [];

		// Render the edit form (one card per existing variant + an add form)
		echo '<h6 class="mt-3">Variants</h6>';
		foreach ($variants as $v) {
			self::render_variant_form($entity_class, $entity_id, $test, $entity, $fields, $v);
		}
		echo '<div class="mb-2"><strong>Add a variant</strong></div>';
		self::render_variant_form($entity_class, $entity_id, $test, $entity, $fields, null);
	}

	protected static function render_variant_form($entity_class, $entity_id, AbTest $test, $entity, $fields, $variant) {
		$is_edit = $variant && $variant->key;
		$overrides = $is_edit && is_array($variant->get_json_decoded('abv_overrides'))
			? $variant->get_json_decoded('abv_overrides')
			: [];

		echo '<div class="card mb-2"><div class="card-body py-2">';
		echo '<form method="post">';
		self::hidden_keys($entity_class, $entity_id);
		echo '<input type="hidden" name="abtest_action" value="save_variant">';
		if ($is_edit) echo '<input type="hidden" name="abv_variant_id" value="' . (int)$variant->key . '">';

		echo '<div class="row g-2 align-items-center mb-2">';
		echo '<div class="col-md-6"><input type="text" name="abv_name" class="form-control form-control-sm" placeholder="Variant name (e.g. short_copy)" value="' . htmlspecialchars($is_edit ? $variant->get('abv_name') : '') . '"></div>';
		echo '<div class="col-md-6 text-end">';
		echo '<button type="submit" class="btn btn-sm btn-primary me-1">' . ($is_edit ? 'Save' : 'Create') . '</button>';
		if ($is_edit) {
			echo '<button type="submit" formaction="" name="abtest_action" value="delete_variant" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Delete this variant?\')">Delete</button>';
		}
		echo '</div></div>';

		foreach ($fields as $field) {
			$has_override = array_key_exists($field, $overrides);
			$stored = $has_override ? $overrides[$field] : null;
			$label = ucwords(str_replace('_', ' ', preg_replace('/^' . preg_quote($entity_class::$prefix ?? '', '/') . '_/', '', $field)));
			$field_spec = $entity_class::$field_specifications[$field] ?? [];
			$field_type = $field_spec['type'] ?? '';
			$display_value = is_array($stored) || is_object($stored) ? json_encode($stored, JSON_PRETTY_PRINT) : ($stored ?? '');

			echo '<div class="mb-2 border-start border-2 ps-2">';
			echo '<label class="form-check-label small">';
			echo '<input type="checkbox" class="form-check-input me-1" name="override_' . htmlspecialchars($field) . '"' . ($has_override ? ' checked' : '') . '>';
			echo '<strong>' . htmlspecialchars($label) . '</strong> <code class="small">' . htmlspecialchars($field) . '</code>';
			echo '</label>';

			if ($field_type === 'json' || $field_type === 'jsonb') {
				echo '<textarea name="abv_override[' . htmlspecialchars($field) . ']" class="form-control form-control-sm font-monospace mt-1" rows="3" placeholder="JSON (e.g. [17, 42])">' . htmlspecialchars($display_value) . '</textarea>';
			} elseif ($field_type === 'text') {
				echo '<textarea name="abv_override[' . htmlspecialchars($field) . ']" class="form-control form-control-sm mt-1" rows="3">' . htmlspecialchars($display_value) . '</textarea>';
			} else {
				echo '<input type="text" name="abv_override[' . htmlspecialchars($field) . ']" class="form-control form-control-sm mt-1" value="' . htmlspecialchars($display_value) . '">';
			}
			echo '</div>';
		}

		echo '</form></div></div>';
	}

	protected static function render_settings($entity_class, $entity_id, AbTest $test) {
		echo '<details class="mt-3"><summary class="text-muted small">Test settings</summary>';
		echo '<form method="post" class="mt-2">';
		self::hidden_keys($entity_class, $entity_id);
		echo '<input type="hidden" name="abtest_action" value="save_settings">';

		$conversion_type = $test->get('abt_conversion_event_type');
		$event_types = [
			''                                    => '-- Pick a conversion event --',
			VisitorEvent::TYPE_CART_ADD           => 'Cart add',
			VisitorEvent::TYPE_CHECKOUT_START     => 'Checkout start',
			VisitorEvent::TYPE_PURCHASE           => 'Purchase',
			VisitorEvent::TYPE_SIGNUP             => 'Signup',
			VisitorEvent::TYPE_LIST_SIGNUP        => 'Mailing list signup',
		];
		echo '<div class="row g-2">';
		echo '<div class="col-md-4"><label class="form-label small">Conversion event</label>';
		echo '<select name="abt_conversion_event_type" class="form-select form-select-sm">';
		foreach ($event_types as $val => $label) {
			$sel = ((string)$conversion_type === (string)$val) ? ' selected' : '';
			echo '<option value="' . htmlspecialchars((string)$val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
		}
		echo '</select></div>';

		echo '<div class="col-md-4"><label class="form-label small">Epsilon</label>';
		echo '<input type="number" step="0.001" min="0" max="1" name="abt_epsilon" class="form-control form-control-sm" value="' . htmlspecialchars((string)$test->get('abt_epsilon')) . '"></div>';

		echo '<div class="col-md-4"><label class="form-label small">Cold-start threshold</label>';
		echo '<input type="number" step="1" min="0" name="abt_cold_start_threshold" class="form-control form-control-sm" value="' . htmlspecialchars((string)$test->get('abt_cold_start_threshold')) . '"></div>';
		echo '</div>';

		echo '<button type="submit" class="btn btn-sm btn-outline-primary mt-2">Save settings</button>';
		echo '</form></details>';
	}

	protected static function action_form($entity_class, $entity_id, $action, $label, $button_class) {
		$out = '<form method="post" class="d-inline-block">';
		$out .= '<input type="hidden" name="abtest_entity_class" value="' . htmlspecialchars($entity_class) . '">';
		$out .= '<input type="hidden" name="abtest_entity_id" value="' . (int)$entity_id . '">';
		$out .= '<input type="hidden" name="abtest_action" value="' . htmlspecialchars($action) . '">';
		$out .= '<button type="submit" class="btn ' . $button_class . '">' . htmlspecialchars($label) . '</button>';
		$out .= '</form>';
		return $out;
	}

	protected static function hidden_keys($entity_class, $entity_id) {
		echo '<input type="hidden" name="abtest_entity_class" value="' . htmlspecialchars($entity_class) . '">';
		echo '<input type="hidden" name="abtest_entity_id" value="' . (int)$entity_id . '">';
	}
}
?>
