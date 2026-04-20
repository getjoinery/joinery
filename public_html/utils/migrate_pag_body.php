<?php
/**
 * Migrate pag_body content to custom_html components.
 *
 * For each page with pag_body content and no pag_component_layout:
 *   - Creates a custom_html component instance containing the body HTML
 *   - Sets pag_component_layout to [new_component_id]
 *   - Clears pag_body
 *
 * For pages that already have pag_component_layout (body already ignored):
 *   - Just clears pag_body
 *
 * Run modes:
 *   --audit    Survey pages, report what will happen. Read-only.
 *   --migrate  Execute the migration.
 *
 * Always run --audit first.
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/pages_class.php'));
require_once(PathHelper::getIncludePath('data/page_contents_class.php'));
require_once(PathHelper::getIncludePath('data/components_class.php'));

$args = array_slice($argv, 1);
$audit   = in_array('--audit', $args);
$migrate = in_array('--migrate', $args);

if (!$audit && !$migrate) {
	echo "Usage: php migrate_pag_body.php --audit\n";
	echo "       php migrate_pag_body.php --migrate\n";
	exit(1);
}

$dblink = DbConnector::get_instance()->get_db_link();

// Find the custom_html component type
$q = $dblink->prepare("SELECT com_component_id FROM com_components WHERE com_type_key = 'custom_html' AND com_delete_time IS NULL LIMIT 1");
$q->execute();
$custom_html_com_id = $q->fetchColumn();

if (!$custom_html_com_id) {
	echo "ERROR: custom_html component type not found. Cannot migrate.\n";
	exit(1);
}

// Load all pages with non-empty pag_body
$q = $dblink->prepare(
	"SELECT pag_page_id, pag_title, pag_link, pag_body, pag_component_layout
	 FROM pag_pages
	 WHERE pag_body IS NOT NULL AND pag_body != '' AND pag_delete_time IS NULL
	 ORDER BY pag_title"
);
$q->execute();
$pages = $q->fetchAll(PDO::FETCH_ASSOC);

if (empty($pages)) {
	echo "No pages with pag_body content found. Nothing to do.\n";
	exit(0);
}

if ($audit) {
	echo "AUDIT: pag_body migration survey\n";
	echo str_repeat('=', 50) . "\n\n";

	$needs_component = [];
	$already_has_layout = [];

	foreach ($pages as $row) {
		$layout = $row['pag_component_layout'];
		if (!empty($layout) && $layout !== 'null' && $layout !== '[]') {
			$already_has_layout[] = $row;
		} else {
			$needs_component[] = $row;
		}
	}

	if ($needs_component) {
		echo "Will create custom_html component and set layout (" . count($needs_component) . " pages):\n";
		foreach ($needs_component as $row) {
			echo "  /page/{$row['pag_link']}  ({$row['pag_title']}, " . strlen($row['pag_body']) . " chars)\n";
		}
		echo "\n";
	}

	if ($already_has_layout) {
		echo "Already have layout, will only clear pag_body (" . count($already_has_layout) . " pages):\n";
		foreach ($already_has_layout as $row) {
			echo "  /page/{$row['pag_link']}  ({$row['pag_title']})\n";
		}
		echo "\n";
	}

	echo "Total: " . count($pages) . " pages (" . count($needs_component) . " need component, " . count($already_has_layout) . " just need body cleared)\n";
	exit(0);
}

// --migrate
echo "MIGRATE: pag_body → custom_html components\n";
echo str_repeat('=', 50) . "\n\n";

$created = 0;
$cleared = 0;
$errors  = 0;

foreach ($pages as $row) {
	$page_id = (int)$row['pag_page_id'];
	$layout  = $row['pag_component_layout'];
	$has_layout = !empty($layout) && $layout !== 'null' && $layout !== '[]';

	try {
		if ($has_layout) {
			// pag_body already ignored — just clear it
			$q = $dblink->prepare("UPDATE pag_pages SET pag_body = NULL WHERE pag_page_id = ?");
			$q->execute([$page_id]);
			echo "  Cleared pag_body (had layout): /page/{$row['pag_link']}\n";
			$cleared++;
		} else {
			// Create a custom_html component instance
			$config = json_encode(['html' => $row['pag_body']]);
			$title  = ($row['pag_title'] ?: 'Page') . ' content';

			$q = $dblink->prepare(
				"INSERT INTO pac_page_contents (pac_com_component_id, pac_title, pac_config)
				 VALUES (?, ?, ?)
				 RETURNING pac_page_content_id"
			);
			$q->execute([$custom_html_com_id, $title, $config]);
			$new_pac_id = (int)$q->fetchColumn();

			// Set layout and clear body atomically
			$new_layout = json_encode([$new_pac_id]);
			$q = $dblink->prepare(
				"UPDATE pag_pages SET pag_component_layout = ?, pag_body = NULL WHERE pag_page_id = ?"
			);
			$q->execute([$new_layout, $page_id]);

			echo "  Migrated: /page/{$row['pag_link']}  → component #$new_pac_id\n";
			$created++;
		}
	} catch (PDOException $e) {
		echo "  ERROR on /page/{$row['pag_link']}: " . $e->getMessage() . "\n";
		$errors++;
	}
}

echo "\nDone. Created: $created, Cleared: $cleared, Errors: $errors\n";
if ($errors > 0) {
	exit(1);
}
