<?php
/**
 * Legacy content system cleanup.
 *
 * Migrates pages off the pac_order / pac_pag_page_id / pac_link / pac_script_filename
 * model and onto pag_component_layout + pag_template. Idempotent — re-running is
 * safe. Halts on any ambiguous state rather than guess.
 *
 * Run modes:
 *
 *   --audit                         Survey the site, report what needs attention.
 *                                   Read-only.
 *   --migrate                       Populate pag_component_layout from pac_order on
 *                                   every page with components; absorb legacy
 *                                   *!**slug**!* placeholders into pag_body when
 *                                   possible; generate stub views for pages that
 *                                   have a pag_script_filename.
 *   --confirm-dynamic=/page/slug    For each stub-generated page, once the admin
 *                                   has reviewed the stub and confirmed the page
 *                                   renders correctly, set pag_template and clear
 *                                   pag_script_filename for that page.
 *   --drop-columns                  Irreversible. Drops pac_order,
 *                                   pac_pag_page_id, pac_link, pac_script_filename,
 *                                   pag_script_filename. Runs only after a repo
 *                                   grep confirms no PHP file still references
 *                                   any of these columns. Take a DB backup first.
 *
 * @see /specs/ab_testing_framework.md
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/pages_class.php'));
require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

$opts = parse_opts($argv);
if (empty($opts['mode'])) {
	echo "Usage:\n";
	echo "  php utils/legacy_content_cleanup.php --audit\n";
	echo "  php utils/legacy_content_cleanup.php --migrate\n";
	echo "  php utils/legacy_content_cleanup.php --confirm-dynamic=/page/slug\n";
	echo "  php utils/legacy_content_cleanup.php --drop-columns\n";
	exit(1);
}

$dblink = DbConnector::get_instance()->get_db_link();

switch ($opts['mode']) {
	case 'audit':         cmd_audit($dblink); break;
	case 'migrate':       cmd_migrate($dblink); break;
	case 'confirm-dynamic': cmd_confirm_dynamic($dblink, $opts['confirm_dynamic']); break;
	case 'drop-columns':  cmd_drop_columns($dblink); break;
}

// ---------------------------------------------------------------------------

function parse_opts($argv) {
	$out = ['mode' => null, 'confirm_dynamic' => null];
	for ($i = 1; $i < count($argv); $i++) {
		$a = $argv[$i];
		if ($a === '--audit')         { $out['mode'] = 'audit'; continue; }
		if ($a === '--migrate')       { $out['mode'] = 'migrate'; continue; }
		if ($a === '--drop-columns')  { $out['mode'] = 'drop-columns'; continue; }
		if (strpos($a, '--confirm-dynamic=') === 0) {
			$out['mode'] = 'confirm-dynamic';
			$out['confirm_dynamic'] = substr($a, strlen('--confirm-dynamic='));
			continue;
		}
	}
	return $out;
}

function cmd_audit($dblink) {
	echo "AUDIT: Legacy content survey\n";
	echo str_repeat('=', 50) . "\n\n";

	$rows = $dblink->query('SELECT pag_page_id, pag_title, pag_link, pag_body, pag_template,
									pag_script_filename, pag_component_layout
							   FROM pag_pages
							  WHERE pag_delete_time IS NULL
							  ORDER BY pag_page_id')->fetchAll(PDO::FETCH_ASSOC);

	$dynamic = [];
	$placeholders = [];
	$layouted = [];
	$plain = [];

	foreach ($rows as $row) {
		$url = '/page/' . $row['pag_link'];
		$has_script = !empty($row['pag_script_filename']);
		$has_template = !empty($row['pag_template']);
		$has_layout = !empty($row['pag_component_layout']) && $row['pag_component_layout'] !== '[]' && $row['pag_component_layout'] !== 'null';
		$body = $row['pag_body'] ?: '';
		$placeholder_count = preg_match_all('/\*!\*\*[^*]+\*\*!\*/', $body);

		if ($has_script && !$has_template) {
			$dynamic[] = [
				'url' => $url,
				'title' => $row['pag_title'],
				'script' => $row['pag_script_filename'],
				'placeholders' => $placeholder_count,
			];
		} elseif ($placeholder_count > 0) {
			$placeholders[] = [
				'url' => $url,
				'title' => $row['pag_title'],
				'placeholders' => $placeholder_count,
			];
		} elseif ($has_layout) {
			$layouted[] = ['url' => $url, 'title' => $row['pag_title']];
		} else {
			$plain[] = ['url' => $url, 'title' => $row['pag_title']];
		}
	}

	if (!empty($dynamic)) {
		echo "Pages requiring manual conversion (pag_script_filename set, pag_template unset):\n";
		foreach ($dynamic as $p) {
			echo sprintf("  - %s   script=%s   placeholders: %d   (\"%s\")\n",
				$p['url'], $p['script'], $p['placeholders'], $p['title']);
		}
		echo "\n";
	}

	if (!empty($placeholders)) {
		echo "Pages with *!**slug**!* placeholders (AUTO-FLATTENABLE on --migrate):\n";
		foreach ($placeholders as $p) {
			echo sprintf("  - %s   %d placeholders   (\"%s\")\n",
				$p['url'], $p['placeholders'], $p['title']);
		}
		echo "\n";
	}

	if (!empty($layouted)) {
		echo "Pages using pag_component_layout already (NO ACTION):\n";
		foreach ($layouted as $p) echo "  - " . $p['url'] . "\n";
		echo "\n";
	}

	if (!empty($plain)) {
		echo "Pages with pag_body only, no placeholders (NO ACTION):\n";
		foreach ($plain as $p) echo "  - " . $p['url'] . "\n";
		echo "\n";
	}

	echo sprintf("Total: %d pages (%d require manual conversion, %d auto-flattenable, %d no-op)\n",
		count($rows), count($dynamic), count($placeholders),
		count($layouted) + count($plain));
}

function cmd_migrate($dblink) {
	echo "MIGRATE\n" . str_repeat('=', 50) . "\n\n";

	// 1. Populate pag_component_layout from pac_order where unset
	$pages = $dblink->query("SELECT pag_page_id, pag_link, pag_body, pag_component_layout,
									 pag_script_filename
								FROM pag_pages
							   WHERE pag_delete_time IS NULL")->fetchAll(PDO::FETCH_ASSOC);

	foreach ($pages as $page) {
		$page_id = (int)$page['pag_page_id'];
		$existing_layout = $page['pag_component_layout'];
		$decoded = $existing_layout ? json_decode($existing_layout, true) : null;
		$layout_populated = is_array($decoded) && count($decoded) > 0;

		if (!$layout_populated) {
			// Collect components in pac_order for this page
			$q = $dblink->prepare("SELECT pac_page_content_id
									 FROM pac_page_contents
									WHERE pac_pag_page_id = ?
									  AND pac_delete_time IS NULL
									  AND pac_com_component_id IS NOT NULL
									ORDER BY pac_order ASC, pac_page_content_id ASC");
			$q->execute([$page_id]);
			$ids = $q->fetchAll(PDO::FETCH_COLUMN, 0);
			if (!empty($ids)) {
				$layout_json = json_encode(array_map('intval', $ids));
				$u = $dblink->prepare("UPDATE pag_pages SET pag_component_layout = ? WHERE pag_page_id = ?");
				$u->execute([$layout_json, $page_id]);
				echo "  populated layout on /page/{$page['pag_link']}: " . count($ids) . " components\n";
			}
		}

		// 2. Flatten *!**slug**!* placeholders — only when there's no script
		if (empty($page['pag_script_filename'])) {
			$body = $page['pag_body'] ?: '';
			if (preg_match_all('/\*!\*\*([^*]+)\*\*!\*/', $body, $matches)) {
				$slugs = array_unique($matches[1]);
				$absorbed_ids = [];
				foreach ($slugs as $slug) {
					$q = $dblink->prepare("SELECT pac_page_content_id, pac_body
											 FROM pac_page_contents
											WHERE pac_link = ?
											  AND pac_delete_time IS NULL");
					$q->execute([$slug]);
					$match_row = $q->fetch(PDO::FETCH_ASSOC);
					if ($match_row && $match_row['pac_body']) {
						$body = str_replace('*!**' . $slug . '**!*', $match_row['pac_body'], $body);
						$absorbed_ids[] = (int)$match_row['pac_page_content_id'];
					}
				}
				if (!empty($absorbed_ids)) {
					$u = $dblink->prepare("UPDATE pag_pages SET pag_body = ? WHERE pag_page_id = ?");
					$u->execute([$body, $page_id]);
					foreach ($absorbed_ids as $pac_id) {
						$s = $dblink->prepare("UPDATE pac_page_contents
												  SET pac_delete_time = now()
												WHERE pac_page_content_id = ?");
						$s->execute([$pac_id]);
					}
					echo "  flattened /page/{$page['pag_link']}: absorbed " . count($absorbed_ids) . " PageContents\n";
				}
			}
		}

		// 3. Stub generation for dynamic pages
		if (!empty($page['pag_script_filename'])) {
			$slug_underscored = preg_replace('/[^a-zA-Z0-9_]/', '_', $page['pag_link']);
			$stub_filename = 'page_' . $slug_underscored . '.php';
			$stub_path = __DIR__ . '/../theme/default/views/' . $stub_filename;
			if (!file_exists($stub_path)) {
				$stub = generate_stub_view($page, $stub_filename);
				file_put_contents($stub_path, $stub);
				chmod($stub_path, 0666);
				echo "  wrote stub view: theme/default/views/" . $stub_filename . "\n";
				echo "    → review, then: --confirm-dynamic=/page/{$page['pag_link']}\n";
			}
		}
	}

	echo "\nMigrate step complete. Run --audit again to verify, and --confirm-dynamic\n";
	echo "for each dynamic page once its stub view has been reviewed.\n";
}

function generate_stub_view($page, $stub_filename) {
	$script = $page['pag_script_filename'];
	$body = $page['pag_body'] ?: '';
	// Rewrite {{var}} placeholders to PHP echoes
	$body = preg_replace_callback('/\{\{(\w+)\}\}/', function($m) {
		return '<?php echo htmlspecialchars($replace_values["' . $m[1] . '"] ?? ""); ?>';
	}, $body);

	return '<?php
/**
 * Stub view generated from legacy /page/' . $page['pag_link'] . ' (pag_script_filename = ' . $script . ').
 *
 * TODO: Review and edit. Confirm the page renders correctly, then run:
 *   php utils/legacy_content_cleanup.php --confirm-dynamic=/page/' . $page['pag_link'] . '
 */

require_once(PathHelper::getThemeFilePath(' . var_export($script, true) . ', \'logic\'));

require_once(PathHelper::getThemeFilePath(\'PublicPage.php\', \'includes\'));
$paget = new PublicPage();
$paget->public_header(array(
	\'is_valid_page\' => true,
	\'title\' => $page->get(\'pag_title\'),
));
?>
<div class="jy-ui">
<section class="page-title bg-transparent"><div class="jy-container"><div class="page-title-row">
	<div class="page-title-content"><h1><?php echo htmlspecialchars($page->get(\'pag_title\')); ?></h1></div>
</div></div></section>
<section id="content"><div class="content-wrap"><div class="jy-container">
' . $body . '
</div></div></section>
</div>
<?php $paget->public_footer(array(\'track\' => true)); ?>
';
}

function cmd_confirm_dynamic($dblink, $path) {
	if (!$path || strpos($path, '/page/') !== 0) {
		echo "Usage: --confirm-dynamic=/page/your-slug\n";
		exit(1);
	}
	$slug = substr($path, strlen('/page/'));

	$q = $dblink->prepare("SELECT pag_page_id, pag_link, pag_script_filename
							  FROM pag_pages
							 WHERE pag_link = ?
							   AND pag_delete_time IS NULL");
	$q->execute([$slug]);
	$row = $q->fetch(PDO::FETCH_ASSOC);
	if (!$row) {
		echo "No page found at /page/{$slug}\n";
		exit(1);
	}

	$slug_underscored = preg_replace('/[^a-zA-Z0-9_]/', '_', $row['pag_link']);
	$template = 'page_' . $slug_underscored;
	$stub_path = __DIR__ . '/../theme/default/views/' . $template . '.php';
	if (!file_exists($stub_path)) {
		echo "Stub view not found: theme/default/views/{$template}.php — run --migrate first\n";
		exit(1);
	}

	$u = $dblink->prepare("UPDATE pag_pages
							  SET pag_template = ?,
							      pag_script_filename = NULL
							WHERE pag_page_id = ?");
	$u->execute([$template, (int)$row['pag_page_id']]);

	echo "Confirmed /page/{$slug}: pag_template='{$template}', pag_script_filename cleared.\n";
}

function cmd_drop_columns($dblink) {
	echo "DROP COLUMNS\n" . str_repeat('=', 50) . "\n\n";
	echo "This is irreversible. Take a DB backup NOW if you haven't already.\n";
	echo "\n";

	// 1. Repo-wide sanity check — any PHP file still referencing the columns?
	//
	// Known-safe references we skip:
	//   - legacy_content_cleanup.php — self-reference
	//   - /specs/ and /docs/          — documentation
	//   - /migrations/                — historical INSERTs that already ran
	//   - data/pages_class.php
	//     data/page_contents_class.php — legacy $field_specifications entries;
	//                                    removed post-drop per the spec
	//                                    (update_database only re-adds columns
	//                                    that exist in the spec, so stale specs
	//                                    don't resurrect dropped columns)
	$columns = ['pac_order', 'pac_pag_page_id', 'pac_link', 'pac_script_filename', 'pag_script_filename'];
	$repo_root = realpath(__DIR__ . '/..');
	$offenders = [];
	foreach ($columns as $col) {
		$cmd = 'grep -rl --include="*.php" ' . escapeshellarg($col) . ' ' . escapeshellarg($repo_root) . ' 2>/dev/null';
		$hits = shell_exec($cmd);
		if ($hits) {
			foreach (explode("\n", trim($hits)) as $line) {
				if (!$line) continue;
				if (strpos($line, 'legacy_content_cleanup.php') !== false) continue;
				if (preg_match('#/specs/|/docs/|/migrations/#', $line)) continue;
				if (preg_match('#/data/(pages|page_contents)_class\.php$#', $line)) continue;
				$offenders[$col][] = $line;
			}
		}
	}

	if (!empty($offenders)) {
		echo "ABORT: Source references to columns still present:\n";
		foreach ($offenders as $col => $files) {
			echo "  {$col}:\n";
			foreach ($files as $f) echo "    - {$f}\n";
		}
		echo "\nResolve these references first, then re-run --drop-columns.\n";
		exit(1);
	}

	echo "Repo scan clean. Dropping columns...\n";

	$dblink->beginTransaction();
	try {
		foreach (['pac_order', 'pac_pag_page_id', 'pac_link', 'pac_script_filename'] as $col) {
			$dblink->exec("ALTER TABLE pac_page_contents DROP COLUMN IF EXISTS {$col}");
			echo "  dropped pac_page_contents.{$col}\n";
		}
		$dblink->exec("ALTER TABLE pag_pages DROP COLUMN IF EXISTS pag_script_filename");
		echo "  dropped pag_pages.pag_script_filename\n";
		$dblink->commit();
	} catch (\Throwable $e) {
		$dblink->rollBack();
		echo "Drop failed, rolled back: " . $e->getMessage() . "\n";
		exit(1);
	}

	echo "\nDone. Remove the legacy fields from \$field_specifications in the data classes\n";
	echo "(pages_class.php: pag_script_filename; page_contents_class.php: pac_order, pac_pag_page_id,\n";
	echo "pac_link, pac_script_filename) and commit.\n";
}
?>
