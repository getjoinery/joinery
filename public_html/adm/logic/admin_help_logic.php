<?php
/**
 * Admin Help Documentation Viewer - Logic
 * Version: 2.0
 *
 * Scans docs/ directory for markdown files, extracts titles and descriptions,
 * validates doc parameter, and prepares content for rendering.
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));

function admin_help_logic($get_vars, $post_vars) {

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$docs_dir = PathHelper::getIncludePath('docs');

	// --- File Discovery ---
	$doc_tree = _help_scan_docs($docs_dir);

	// --- Validate and load requested doc ---
	$selected_doc = isset($get_vars['doc']) ? $get_vars['doc'] : '';
	$rendered_html = '';
	$page_title = 'Documentation';
	$error = '';

	if (!empty($selected_doc)) {
		$result = _help_load_doc($selected_doc, $docs_dir);
		if ($result['error']) {
			$error = $result['error'];
		} else {
			$rendered_html = MarkdownRenderer::render($result['content']);
			$rendered_html = MarkdownRenderer::rewrite_doc_links($rendered_html, $docs_dir);
			$page_title = $result['title'];
		}
	} else {
		// Landing page: check for index.md, otherwise auto-generate
		$index_path = $docs_dir . '/index.md';
		if (file_exists($index_path) && is_readable($index_path)) {
			$rendered_html = MarkdownRenderer::render(file_get_contents($index_path));
			$rendered_html = MarkdownRenderer::rewrite_doc_links($rendered_html, $docs_dir);
		} else {
			$rendered_html = _help_generate_landing($doc_tree);
		}
	}

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session'] = $session;
	$page_vars['doc_tree'] = $doc_tree;
	$page_vars['selected_doc'] = $selected_doc;
	$page_vars['rendered_html'] = $rendered_html;
	$page_vars['page_title'] = $page_title;
	$page_vars['error'] = $error;

	return LogicResult::render($page_vars);
}

/**
 * Recursively scan docs directory for .md files.
 * Returns grouped array: ['_top' => [...], 'subfolder' => [...]]
 * Each entry has: key, filename, title, description, group
 */
function _help_scan_docs($docs_dir) {
	$tree = array('_top' => array());

	if (!is_dir($docs_dir)) {
		return $tree;
	}

	// Scan top-level
	_help_scan_directory($docs_dir, '', $tree, $docs_dir);

	// Scan subdirectories (one level only)
	$entries = scandir($docs_dir);
	foreach ($entries as $entry) {
		if ($entry === '.' || $entry === '..') continue;
		$subpath = $docs_dir . '/' . $entry;
		if (is_dir($subpath)) {
			$tree[$entry] = array();
			_help_scan_directory($subpath, $entry, $tree, $docs_dir);
		}
	}

	// Sort each group alphabetically by title
	foreach ($tree as $group => &$docs) {
		usort($docs, function($a, $b) {
			return strcasecmp($a['title'], $b['title']);
		});
	}
	unset($docs);

	// Remove empty groups
	foreach ($tree as $group => $docs) {
		if (empty($docs)) {
			unset($tree[$group]);
		}
	}

	return $tree;
}

/**
 * Scan a single directory for .md files and add to the tree.
 */
function _help_scan_directory($dir, $group_key, &$tree, $docs_dir) {
	$target_group = $group_key === '' ? '_top' : $group_key;
	$files = scandir($dir);

	foreach ($files as $file) {
		if ($file === '.' || $file === '..' || $file === 'index.md') continue;
		if (pathinfo($file, PATHINFO_EXTENSION) !== 'md') continue;

		$filepath = $dir . '/' . $file;
		if (!is_readable($filepath)) continue;

		$basename = pathinfo($file, PATHINFO_FILENAME);
		$doc_key = $group_key === '' ? $basename : $group_key . '/' . $basename;

		// Extract title from first # line
		$title = _help_extract_title($filepath, $basename);

		// Extract description (first non-heading, non-empty paragraph)
		$description = _help_extract_description($filepath);

		$tree[$target_group][] = array(
			'key' => $doc_key,
			'filename' => $file,
			'title' => $title,
			'description' => $description,
			'group' => $target_group,
		);
	}
}

/**
 * Extract the H1 title from a markdown file.
 * Falls back to generating title from filename.
 */
function _help_extract_title($filepath, $basename) {
	$handle = fopen($filepath, 'r');
	if (!$handle) {
		return _help_title_from_filename($basename);
	}

	// Read first 20 lines looking for # heading
	$lines_read = 0;
	while (($line = fgets($handle)) !== false && $lines_read < 20) {
		$line = trim($line);
		if (preg_match('/^# (.+)$/', $line, $matches)) {
			fclose($handle);
			return trim($matches[1]);
		}
		$lines_read++;
	}
	fclose($handle);

	return _help_title_from_filename($basename);
}

/**
 * Generate a readable title from a filename.
 */
function _help_title_from_filename($basename) {
	return ucwords(str_replace(array('_', '-'), array(' ', ' '), $basename));
}

/**
 * Extract a brief description from a markdown file.
 * Takes the first non-heading, non-empty line, truncated to ~150 chars.
 */
function _help_extract_description($filepath) {
	$handle = fopen($filepath, 'r');
	if (!$handle) return '';

	$lines_read = 0;
	$past_title = false;
	while (($line = fgets($handle)) !== false && $lines_read < 50) {
		$line = trim($line);
		$lines_read++;

		// Skip empty lines
		if ($line === '') continue;

		// Skip heading lines
		if (preg_match('/^#{1,6} /', $line)) {
			$past_title = true;
			continue;
		}

		// Skip lines that are just formatting (---, ===, ***)
		if (preg_match('/^[-=*]{3,}$/', $line)) continue;

		// Only take description after we've passed at least the title
		if (!$past_title) continue;

		fclose($handle);

		// Clean markdown formatting for display
		$desc = preg_replace('/\*\*(.+?)\*\*/', '$1', $line);
		$desc = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $desc);
		$desc = preg_replace('/`([^`]+)`/', '$1', $desc);

		if (strlen($desc) > 150) {
			$desc = substr($desc, 0, 147) . '...';
		}

		return $desc;
	}
	fclose($handle);
	return '';
}

/**
 * Validate and load a specific doc file.
 * Returns ['content' => string, 'title' => string, 'error' => string|null]
 */
function _help_load_doc($doc_key, $docs_dir) {
	// Split on / and validate each segment
	$segments = explode('/', $doc_key);

	// Max one level of subdirectory
	if (count($segments) > 2) {
		return array('content' => '', 'title' => '', 'error' => 'Invalid document path.');
	}

	// Validate each segment
	foreach ($segments as $seg) {
		if (!preg_match('/^[a-zA-Z0-9_-]+$/', $seg)) {
			return array('content' => '', 'title' => '', 'error' => 'Invalid document name.');
		}
	}

	// Build filepath
	$relative_path = implode('/', $segments) . '.md';
	$filepath = $docs_dir . '/' . $relative_path;

	// Belt-and-suspenders: realpath check
	$real_docs = realpath($docs_dir);
	$real_file = realpath($filepath);

	if ($real_file === false || strpos($real_file, $real_docs) !== 0) {
		return array('content' => '', 'title' => '', 'error' => 'Document not found.');
	}

	if (!is_readable($filepath)) {
		return array('content' => '', 'title' => '', 'error' => 'Document is not readable.');
	}

	$content = file_get_contents($filepath);
	$basename = pathinfo($filepath, PATHINFO_FILENAME);
	$title = _help_extract_title($filepath, $basename);

	return array('content' => $content, 'title' => $title, 'error' => '');
}

/**
 * Generate an auto-generated landing page when no index.md exists.
 */
function _help_generate_landing($doc_tree) {
	$html = '<h1>Documentation</h1>';
	$html .= '<p>Browse the available documentation below, or select a topic from the sidebar.</p>';

	// Top-level docs first
	if (!empty($doc_tree['_top'])) {
		$html .= '<div class="row">';
		foreach ($doc_tree['_top'] as $doc) {
			$html .= '<div class="col-md-6 mb-3">';
			$html .= '<div class="card h-100"><div class="card-body">';
			$html .= '<h5 class="card-title"><a href="/admin/admin_help?doc=' . htmlspecialchars($doc['key']) . '">' . htmlspecialchars($doc['title']) . '</a></h5>';
			if (!empty($doc['description'])) {
				$html .= '<p class="card-text text-muted small">' . htmlspecialchars($doc['description']) . '</p>';
			}
			$html .= '</div></div>';
			$html .= '</div>';
		}
		$html .= '</div>';
	}

	// Subfolder groups
	foreach ($doc_tree as $group => $docs) {
		if ($group === '_top') continue;

		$group_title = ucwords(str_replace(array('_', '-'), array(' ', ' '), $group));
		$html .= '<h3 class="mt-4 mb-3">' . htmlspecialchars($group_title) . '</h3>';
		$html .= '<div class="row">';
		foreach ($docs as $doc) {
			$html .= '<div class="col-md-6 mb-3">';
			$html .= '<div class="card h-100"><div class="card-body">';
			$html .= '<h5 class="card-title"><a href="/admin/admin_help?doc=' . htmlspecialchars($doc['key']) . '">' . htmlspecialchars($doc['title']) . '</a></h5>';
			if (!empty($doc['description'])) {
				$html .= '<p class="card-text text-muted small">' . htmlspecialchars($doc['description']) . '</p>';
			}
			$html .= '</div></div>';
			$html .= '</div>';
		}
		$html .= '</div>';
	}

	return $html;
}
?>
