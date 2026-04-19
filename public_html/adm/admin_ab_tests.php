<?php
/**
 * A/B Tests — cross-entity list.
 *
 * Global view of every test attached to any testable entity. Rows deep-link to
 * the entity's admin edit page, where AbTestVersionsPanel lives.
 *
 * @see /specs/ab_testing_framework.md
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/abt_tests_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);
$session->set_return();

// Sanity check: any entities with more than one active, non-deleted test?
$dblink = DbConnector::get_instance()->get_db_link();
$duplicate_groups = [];
try {
	$sql = 'SELECT abt_entity_type, abt_entity_id, COUNT(*) AS c
			  FROM abt_tests
			 WHERE abt_status = ? AND abt_delete_time IS NULL
			 GROUP BY abt_entity_type, abt_entity_id
			HAVING COUNT(*) > 1';
	$q = $dblink->prepare($sql);
	$q->execute([AbTest::STATUS_ACTIVE]);
	$duplicate_groups = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
	error_log('admin_ab_tests duplicate check: ' . $e->getMessage());
}

// Load all non-deleted tests
$tests = new MultiAbTest(
	['deleted' => false],
	['abt_test_id' => 'DESC']
);
$tests->load();

$paget = new AdminPage();
$paget->admin_header(array(
	'menu-id' => 'ab_tests',
	'page_title' => 'A/B Tests',
	'readable_title' => 'A/B Tests',
	'breadcrumbs' => array(
		'A/B Tests' => '',
	),
	'session' => $session,
));

if (!empty($duplicate_groups)) {
	echo '<div class="alert alert-danger">';
	echo '<strong>Warning:</strong> ' . count($duplicate_groups) . ' entities have more than one active test. ';
	echo 'This should never happen; it usually indicates a race during activation. Review each and soft-delete the duplicate(s).';
	echo '<ul class="mb-0 mt-2">';
	foreach ($duplicate_groups as $g) {
		$link = get_entity_deep_link($g['abt_entity_type'], (int)$g['abt_entity_id']);
		echo '<li>' . htmlspecialchars($g['abt_entity_type']) . ' #' . (int)$g['abt_entity_id'];
		if ($link) echo ' — <a href="' . htmlspecialchars($link) . '">open</a>';
		echo ' (' . (int)$g['c'] . ' active tests)</li>';
	}
	echo '</ul></div>';
}

// Build duplicate set for per-row badging
$dup_set = [];
foreach ($duplicate_groups as $g) {
	$dup_set[$g['abt_entity_type'] . '#' . (int)$g['abt_entity_id']] = true;
}

$headers = array('Entity', 'Status', 'Trials', 'Leader', 'Rate', 'Started');
$paget->tableheader($headers, array('title' => 'All Tests', 'search_on' => false), null);

foreach ($tests as $test) {
	$entity_class = $test->get('abt_entity_type');
	$entity_id = (int)$test->get('abt_entity_id');
	$dup_key = $entity_class . '#' . $entity_id;

	// Entity cell — deep-link to the entity's edit page
	$link = get_entity_deep_link($entity_class, $entity_id);
	$entity_label = $entity_class . ' #' . $entity_id;
	if (class_exists($entity_class)) {
		$entity = new $entity_class($entity_id, true);
		if ($entity->key) {
			$title_field = $entity_class::$prefix . '_title';
			$title = $entity->get($title_field) ?: $entity_label;
			$entity_label = $title . ' <small class="text-muted">(' . $entity_class . ' #' . $entity_id . ')</small>';
		}
	}
	$entity_cell = $link
		? '<a href="' . htmlspecialchars($link) . '">' . $entity_label . '</a>'
		: $entity_label;
	if (!empty($dup_set[$dup_key])) {
		$entity_cell .= ' <span class="badge bg-danger">DUPLICATE</span>';
	}

	// Leaderboard figures
	$variants = new MultiAbTestVariant(
		['test_id' => (int)$test->key, 'deleted' => false],
		['abv_variant_id' => 'ASC']
	);
	$variants->load();

	$total_trials = 0;
	$leader = null;
	$leader_rate = -1.0;
	foreach ($variants as $v) {
		$total_trials += (int)$v->get('abv_trials');
		$rate = $v->conversion_rate();
		if ($rate !== null && $rate > $leader_rate) {
			$leader_rate = $rate;
			$leader = $v;
		}
	}

	$leader_cell = $leader ? htmlspecialchars($leader->get('abv_name')) : '<span class="text-muted">—</span>';
	$rate_cell = $leader ? number_format($leader_rate * 100, 2) . '%' : '<span class="text-muted">—</span>';

	$status = $test->get('abt_status');
	$status_badge = '<span class="badge bg-' . ab_status_color($status) . '">' . htmlspecialchars($status) . '</span>';

	$started = $test->get('abt_create_time');
	$started_cell = $started ? LibraryFunctions::convert_time($started, 'UTC', $session->get_timezone(), 'M j, Y') : '';

	$paget->disprow(array(
		$entity_cell,
		$status_badge,
		number_format($total_trials),
		$leader_cell,
		$rate_cell,
		$started_cell,
	));
}

$paget->endtable(null);

$paget->admin_footer();

/**
 * Figure out where to deep-link for an entity edit page. Testable entities
 * are expected to follow the /admin/admin_{singular}?prefix_pkey_column=ID
 * convention (Page → admin_page, PageContent → admin_component_edit). Keep
 * this small and explicit — a dispatcher grows better than a smart guess.
 */
function get_entity_deep_link($entity_class, $entity_id) {
	switch ($entity_class) {
		case 'Page':        return '/admin/admin_page?pag_page_id=' . $entity_id;
		case 'PageContent': return '/admin/admin_component_edit?pac_page_content_id=' . $entity_id;
	}
	return null;
}

function ab_status_color($status) {
	switch ($status) {
		case AbTest::STATUS_ACTIVE:  return 'success';
		case AbTest::STATUS_PAUSED:  return 'warning';
		case AbTest::STATUS_CROWNED: return 'primary';
		default: return 'secondary';
	}
}
?>
