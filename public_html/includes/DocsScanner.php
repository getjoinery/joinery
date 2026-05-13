<?php
/**
 * DocsScanner - Shared markdown documentation scanner.
 *
 * Used by both the admin help viewer (adm/admin_help.php) and the public
 * documentation page (views/documentation.php). All scanning, title/description
 * extraction, doc loading (with path-traversal protection), and auto-generated
 * landing markup live here so the two viewers stay in lock-step.
 */

class DocsScanner {

	/**
	 * Resolve a markdown link href to a canonical doc key, or null if it
	 * doesn't point at a known doc file inside an allowed root.
	 *
	 * Accepts any path shape:
	 *   - bare filename ("api.md")                — resolved against $current_doc_dir
	 *   - web-root absolute ("/docs/routing.md")  — resolved against public_html
	 *   - relative with .. ("../foo/bar.md")      — resolved against $current_doc_dir
	 *
	 * Security: realpath() canonicalizes the resolved path, then the result
	 * must live inside public_html/docs/ or public_html/plugins/{name}/docs/
	 * (with plugin name matching ^[a-zA-Z0-9_-]+$). Anything else returns null.
	 *
	 * Returns 'foo', 'subfolder/foo', or 'plugin/{name}/{slug}'.
	 */
	public static function path_to_key($href, $current_doc_dir) {
		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) return null;

		if (($hash_pos = strpos($href, '#')) !== false) {
			$href = substr($href, 0, $hash_pos);
		}
		if ($href === '') return null;

		if ($href[0] === '/') {
			$candidate = PathHelper::getIncludePath(ltrim($href, '/'));
		} else {
			$candidate = $current_doc_dir . '/' . $href;
		}

		$canonical = realpath($candidate);
		if ($canonical === false) return null;
		if (substr($canonical, -3) !== '.md') return null;

		$docs_root = realpath(PathHelper::getIncludePath('docs'));
		if ($docs_root !== false && strpos($canonical, $docs_root . DIRECTORY_SEPARATOR) === 0) {
			$relative = substr($canonical, strlen($docs_root) + 1);
			$relative = preg_replace('/\.md$/', '', $relative);
			$segments = explode('/', $relative);
			if (count($segments) > 2) return null;
			foreach ($segments as $seg) {
				if (!preg_match('/^[a-zA-Z0-9_-]+$/', $seg)) return null;
			}
			return $relative;
		}

		$plugins_root = realpath(PathHelper::getIncludePath('plugins'));
		if ($plugins_root !== false && strpos($canonical, $plugins_root . DIRECTORY_SEPARATOR) === 0) {
			$relative = substr($canonical, strlen($plugins_root) + 1);
			$parts = explode('/', $relative);
			if (count($parts) < 3) return null;
			$plugin_name = $parts[0];
			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $plugin_name)) return null;
			if ($parts[1] !== 'docs') return null;
			$slug_parts = array_slice($parts, 2);
			if (count($slug_parts) > 2) return null;
			$last_idx = count($slug_parts) - 1;
			$slug_parts[$last_idx] = preg_replace('/\.md$/', '', $slug_parts[$last_idx]);
			foreach ($slug_parts as $seg) {
				if (!preg_match('/^[a-zA-Z0-9_-]+$/', $seg)) return null;
			}
			return 'plugin/' . $plugin_name . '/' . implode('/', $slug_parts);
		}

		return null;
	}

	/**
	 * Discover plugin docs directories. Returns [plugin_name => absolute_docs_path].
	 */
	public static function discover_plugin_docs() {
		$sources = array();
		$plugins_dir = PathHelper::getIncludePath('plugins');
		if (!is_dir($plugins_dir)) return $sources;

		$entries = scandir($plugins_dir);
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$docs_path = $plugins_dir . '/' . $entry . '/docs';
			if (is_dir($docs_path) && is_readable($docs_path)) {
				$sources[$entry] = $docs_path;
			}
		}
		return $sources;
	}

	/**
	 * Scan core docs plus every plugin's docs/ directory. Plugin docs land in
	 * groups keyed 'plugin/{plugin_name}' with their doc keys prefixed by
	 * 'plugin/{plugin_name}/'.
	 */
	public static function scan_all($core_dir) {
		$tree = self::scan($core_dir);

		foreach (self::discover_plugin_docs() as $plugin_name => $plugin_docs_dir) {
			$plugin_tree = self::scan($plugin_docs_dir);
			$new_group = 'plugin/' . $plugin_name;

			foreach ($plugin_tree as $group => $docs) {
				if (!isset($tree[$new_group])) $tree[$new_group] = array();
				foreach ($docs as $doc) {
					$doc['key'] = 'plugin/' . $plugin_name . '/' . $doc['key'];
					$doc['group'] = $new_group;
					$tree[$new_group][] = $doc;
				}
			}

			if (isset($tree[$new_group])) {
				usort($tree[$new_group], function ($a, $b) {
					return strcasecmp($a['title'], $b['title']);
				});
			}
		}
		return $tree;
	}

	/**
	 * Recursively scan a docs directory for .md files (one level of subdirs).
	 * Returns: ['_top' => [...], 'subfolder' => [...]] where each entry has
	 * keys: key, filename, title, description, group.
	 */
	public static function scan($docs_dir) {
		$tree = array('_top' => array());

		if (!is_dir($docs_dir)) {
			return $tree;
		}

		self::scan_directory($docs_dir, '', $tree, $docs_dir);

		$entries = scandir($docs_dir);
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$subpath = $docs_dir . '/' . $entry;
			if (is_dir($subpath)) {
				$tree[$entry] = array();
				self::scan_directory($subpath, $entry, $tree, $docs_dir);
			}
		}

		foreach ($tree as $group => &$docs) {
			usort($docs, function ($a, $b) {
				return strcasecmp($a['title'], $b['title']);
			});
		}
		unset($docs);

		foreach ($tree as $group => $docs) {
			if (empty($docs)) {
				unset($tree[$group]);
			}
		}

		return $tree;
	}

	private static function scan_directory($dir, $group_key, &$tree, $docs_dir) {
		$target_group = $group_key === '' ? '_top' : $group_key;
		$files = scandir($dir);

		foreach ($files as $file) {
			if ($file === '.' || $file === '..' || $file === 'index.md') continue;
			if (pathinfo($file, PATHINFO_EXTENSION) !== 'md') continue;

			$filepath = $dir . '/' . $file;
			if (!is_readable($filepath)) continue;

			$basename = pathinfo($file, PATHINFO_FILENAME);
			$doc_key = $group_key === '' ? $basename : $group_key . '/' . $basename;

			$tree[$target_group][] = array(
				'key' => $doc_key,
				'filename' => $file,
				'title' => self::extract_title($filepath, $basename),
				'description' => self::extract_description($filepath),
				'group' => $target_group,
			);
		}
	}

	/**
	 * Extract the first H1 from a markdown file. Falls back to a humanized filename.
	 */
	public static function extract_title($filepath, $basename) {
		$handle = @fopen($filepath, 'r');
		if (!$handle) {
			return self::title_from_filename($basename);
		}

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

		return self::title_from_filename($basename);
	}

	public static function title_from_filename($basename) {
		return ucwords(str_replace(array('_', '-'), array(' ', ' '), $basename));
	}

	/**
	 * Pull the first non-heading, non-empty paragraph and trim to ~150 chars.
	 */
	public static function extract_description($filepath) {
		$handle = @fopen($filepath, 'r');
		if (!$handle) return '';

		$lines_read = 0;
		$past_title = false;
		while (($line = fgets($handle)) !== false && $lines_read < 50) {
			$line = trim($line);
			$lines_read++;

			if ($line === '') continue;

			if (preg_match('/^#{1,6} /', $line)) {
				$past_title = true;
				continue;
			}

			if (preg_match('/^[-=*]{3,}$/', $line)) continue;

			if (!$past_title) continue;

			fclose($handle);

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
	 * Validate and load a doc file by its slug. Returns
	 * ['content' => string, 'title' => string, 'description' => string, 'error' => string].
	 *
	 * Core keys: each path segment must match ^[a-zA-Z0-9_-]+$, max one
	 * subdirectory deep, realpath must resolve inside $docs_dir.
	 *
	 * Plugin keys ('plugin/{plugin_name}/{slug}[/{subslug}]'): plugin_name and
	 * each slug segment must match ^[a-zA-Z0-9_-]+$; realpath must resolve
	 * inside that plugin's docs/ directory.
	 */
	public static function load_doc($doc_key, $docs_dir) {
		$segments = explode('/', $doc_key);

		if (count($segments) >= 2 && $segments[0] === 'plugin') {
			$plugin_name = $segments[1];
			$slug_segments = array_slice($segments, 2);

			if (empty($slug_segments) || count($slug_segments) > 2) {
				return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Invalid document path.');
			}

			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $plugin_name)) {
				return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Invalid plugin name.');
			}

			foreach ($slug_segments as $seg) {
				if (!preg_match('/^[a-zA-Z0-9_-]+$/', $seg)) {
					return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Invalid document name.');
				}
			}

			$plugin_docs_dir = PathHelper::getIncludePath('plugins/' . $plugin_name . '/docs');
			$relative_path = implode('/', $slug_segments) . '.md';
			$filepath = $plugin_docs_dir . '/' . $relative_path;

			$real_docs = realpath($plugin_docs_dir);
			$real_file = realpath($filepath);

			if ($real_file === false || $real_docs === false || strpos($real_file, $real_docs . DIRECTORY_SEPARATOR) !== 0) {
				return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Document not found.');
			}

			if (!is_readable($filepath)) {
				return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Document is not readable.');
			}

			$basename = pathinfo($filepath, PATHINFO_FILENAME);
			return array(
				'content' => file_get_contents($filepath),
				'title' => self::extract_title($filepath, $basename),
				'description' => self::extract_description($filepath),
				'error' => '',
			);
		}

		if (count($segments) > 2) {
			return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Invalid document path.');
		}

		foreach ($segments as $seg) {
			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $seg)) {
				return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Invalid document name.');
			}
		}

		$relative_path = implode('/', $segments) . '.md';
		$filepath = $docs_dir . '/' . $relative_path;

		$real_docs = realpath($docs_dir);
		$real_file = realpath($filepath);

		if ($real_file === false || $real_docs === false || strpos($real_file, $real_docs . DIRECTORY_SEPARATOR) !== 0) {
			return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Document not found.');
		}

		if (!is_readable($filepath)) {
			return array('content' => '', 'title' => '', 'description' => '', 'error' => 'Document is not readable.');
		}

		$basename = pathinfo($filepath, PATHINFO_FILENAME);
		return array(
			'content' => file_get_contents($filepath),
			'title' => self::extract_title($filepath, $basename),
			'description' => self::extract_description($filepath),
			'error' => '',
		);
	}

	/**
	 * Render the auto-generated landing HTML when no index.md exists.
	 * Emits semantic vanilla markup (no row/col/card Bootstrap classes).
	 * $base_url should be '/admin/admin_help' or '/documentation' etc.
	 */
	public static function render_landing($doc_tree, $base_url) {
		$html = '<h1>Documentation</h1>';
		$html .= '<p>Browse the available documentation below, or pick a topic from the sidebar.</p>';

		if (!empty($doc_tree['_top'])) {
			$html .= self::render_landing_group($doc_tree['_top'], $base_url);
		}

		foreach ($doc_tree as $group => $docs) {
			if ($group === '_top') continue;

			$display_label = preg_replace('/^plugin\//', '', $group);
			$group_title = ucwords(str_replace(array('_', '-'), array(' ', ' '), $display_label));
			$html .= '<h2 class="docs-landing-group">' . htmlspecialchars($group_title) . '</h2>';
			$html .= self::render_landing_group($docs, $base_url);
		}

		return $html;
	}

	private static function render_landing_group($docs, $base_url) {
		$html = '<ul class="docs-landing-grid">';
		foreach ($docs as $doc) {
			$href = $base_url . '?doc=' . htmlspecialchars($doc['key']);
			$html .= '<li class="docs-landing-item">';
			$html .= '<h3 class="docs-landing-title"><a href="' . $href . '">' . htmlspecialchars($doc['title']) . '</a></h3>';
			if (!empty($doc['description'])) {
				$html .= '<p class="docs-landing-desc">' . htmlspecialchars($doc['description']) . '</p>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * Render the full sidebar + content viewer markup. Shared between the
	 * admin help page and the public documentation page; they only differ
	 * in the surrounding page chrome and the $base_url.
	 */
	public static function render_viewer($doc_tree, $selected_doc, $rendered_html, $error, $base_url) {
		$base = htmlspecialchars($base_url);
		$out = '<div class="docs-layout">';

		$out .= '<aside class="docs-sidebar"><nav aria-label="Documentation navigation">';
		$out .= '<a class="' . (empty($selected_doc) ? 'active' : '') . '" href="' . $base . '">Overview</a>';

		if (!empty($doc_tree['_top'])) {
			$out .= '<div class="docs-group-label">Documentation</div>';
			foreach ($doc_tree['_top'] as $doc) {
				$out .= self::render_sidebar_link($doc, $selected_doc, $base);
			}
		}

		foreach ($doc_tree as $group => $docs) {
			if ($group === '_top') continue;
			$display_label = preg_replace('/^plugin\//', '', $group);
			$group_title = ucwords(str_replace(array('_', '-'), array(' ', ' '), $display_label));
			$out .= '<div class="docs-group-label">' . htmlspecialchars($group_title) . '</div>';
			foreach ($docs as $doc) {
				$out .= self::render_sidebar_link($doc, $selected_doc, $base);
			}
		}

		$out .= '</nav></aside>';

		$out .= '<main class="docs-content markdown-content">';
		if (!empty($error)) {
			$out .= '<div class="docs-error">' . htmlspecialchars($error) . '</div>';
		} else {
			$out .= $rendered_html;
		}
		$out .= '</main>';

		$out .= '</div>';
		return $out;
	}

	private static function render_sidebar_link($doc, $selected_doc, $base) {
		$active = ($selected_doc === $doc['key']) ? ' class="active"' : '';
		return '<a' . $active . ' href="' . $base . '?doc=' . htmlspecialchars($doc['key']) . '">' . htmlspecialchars($doc['title']) . '</a>';
	}

	/**
	 * CSS for the shared layout (sidebar + content + landing grid). Returned
	 * as a string so both viewers can drop it inside a <style> tag.
	 */
	public static function get_layout_css() {
		return '
			.docs-layout { display: grid; grid-template-columns: 240px 1fr; gap: 1.5rem; align-items: start; }
			.docs-sidebar { position: sticky; top: 1rem; max-height: calc(100vh - 2rem); overflow-y: auto; padding: 0.5rem; border: 1px solid #e5e5e5; border-radius: 6px; background: #fafafa; }
			.docs-sidebar nav { display: flex; flex-direction: column; }
			.docs-sidebar a { display: block; padding: 0.35rem 0.6rem; font-size: 0.9rem; color: #444; text-decoration: none; border-radius: 4px; }
			.docs-sidebar a:hover { background: #eee; color: #000; }
			.docs-sidebar a.active { background: #e8f0fe; color: #1a73e8; font-weight: 600; }
			.docs-sidebar .docs-group-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #888; padding: 0.75rem 0.6rem 0.25rem; }
			.docs-sidebar .docs-group-label:first-child { padding-top: 0.25rem; }
			.docs-content { min-width: 0; padding: 1rem 1.5rem; border: 1px solid #e5e5e5; border-radius: 6px; background: #fff; }
			.docs-landing-grid { list-style: none; padding: 0; margin: 1rem 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; }
			.docs-landing-item { padding: 1rem; border: 1px solid #e5e5e5; border-radius: 6px; background: #fff; }
			.docs-landing-title { margin: 0 0 0.5rem; font-size: 1.05rem; }
			.docs-landing-title a { color: #1a73e8; text-decoration: none; }
			.docs-landing-title a:hover { text-decoration: underline; }
			.docs-landing-desc { margin: 0; font-size: 0.875rem; color: #555; }
			.docs-landing-group { margin-top: 2rem; padding-bottom: 0.3em; border-bottom: 1px solid #eee; }
			.docs-error { padding: 1rem; border: 1px solid #f5c2c7; background: #f8d7da; color: #842029; border-radius: 4px; }
			@media (max-width: 900px) {
				.docs-layout { grid-template-columns: 1fr; }
				.docs-sidebar { position: static; max-height: none; }
			}
		';
	}
}
?>
