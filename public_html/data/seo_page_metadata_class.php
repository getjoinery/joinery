<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class SeoPageMetadataException extends SystemBaseException {}

class SeoPageMetadata extends SystemBase {
	public static $prefix = 'spm';
	public static $tablename = 'spm_seo_page_metadata';
	public static $pkey_column = 'spm_seo_page_metadata_id';

	public static $ai_readable    = true;
	public static $ai_owner_field = false; // ownerless catalog — members read all rows
	public static $ai_description = 'SEO and social-card overrides keyed by canonical request path.';

	public static $field_specifications = array(
		'spm_seo_page_metadata_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'spm_path'                 => array('type'=>'varchar(255)', 'is_nullable'=>false, 'unique'=>true),
		'spm_entity_type'          => array('type'=>'varchar(50)', 'is_nullable'=>true),
		'spm_entity_id'            => array('type'=>'int4', 'is_nullable'=>true),
		'spm_title'                => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'spm_meta_description'     => array('type'=>'text', 'is_nullable'=>true),
		'spm_og_title'             => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'spm_og_description'       => array('type'=>'text', 'is_nullable'=>true),
		'spm_preview_image_url'    => array('type'=>'varchar(500)', 'is_nullable'=>true),
		'spm_og_type'              => array('type'=>'varchar(50)', 'is_nullable'=>true),
		'spm_noindex'              => array('type'=>'bool', 'default'=>false),
		'spm_create_time'          => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'spm_modify_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'spm_delete_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	const TITLE_FORMAT = '{title} | {site_name}';

	// Which entity types have public, indexable pages (used by the sitemap,
	// public-path enumeration, and the admin SEO pages) is contributed by the
	// active plugins: core registers post/page/video/mailing_list, store
	// registers product, event_manager registers event/location. Each
	// registration carries the model class + Multi class, the file that defines
	// them, the URL namespace, the admin edit-URL prefix (id appended), and the
	// Open Graph type.
	private static $entity_classes = array();

	/**
	 * Register a public entity type. Idempotent (last-wins by type).
	 *
	 * @param string $admin_edit_url id-append prefix, e.g. '/admin/admin_post_edit?pst_post_id='
	 * @param string $og_type        Open Graph type for the entity's pages
	 */
	public static function register_entity_class(string $type, string $class, string $multi, string $file, string $namespace, string $admin_edit_url, string $og_type = 'website'): void {
		self::$entity_classes[$type] = array(
			'class'         => $class,
			'multi'         => $multi,
			'file'          => $file,
			'namespace'     => $namespace,
			'admin_edit_url'=> $admin_edit_url,
			'og_type'       => $og_type,
		);
	}

	/** All registered entity types, keyed by type. */
	public static function entity_classes(): array {
		return self::$entity_classes;
	}

	/** Register the core-owned public entity types. */
	public static function register_core_entity_classes(): void {
		self::register_entity_class('post',         'Post',        'MultiPost',        'data/posts_class.php',         'post', '/admin/admin_post_edit?pst_post_id=', 'article');
		self::register_entity_class('page',         'Page',        'MultiPage',        'data/pages_class.php',         'page', '/admin/admin_page_edit?pag_page_id=', 'website');
		self::register_entity_class('video',        'Video',       'MultiVideo',       'data/videos_class.php',        'video', '/admin/admin_video_edit?vid_video_id=', 'article');
		self::register_entity_class('mailing_list', 'MailingList', 'MultiMailingList', 'data/mailing_lists_class.php', 'list', '/admin/admin_list_edit?mlt_mailing_list_id=', 'website');
		// MOVED-TO-PLUGIN (phase 4): event/location move to event_manager
		// serve.php once that plugin owns the tables. Kept here while the events
		// code is still in core. (product moved to the store's serve.php in phase 3.)
		self::register_entity_class('event',        'Event',       'MultiEvent',       'data/events_class.php',        'event', '/admin/admin_event_edit?evt_event_id=', 'article');
		self::register_entity_class('location',     'Location',    'MultiLocation',    'data/locations_class.php',     'location', '/admin/admin_location_edit?loc_location_id=', 'website');
	}

	private static $per_request_lookup_cache = array();

	function save($debug = false) {
		if ($this->key) {
			$this->set('spm_modify_time', 'now()');
		}
		if ($this->get('spm_path') !== null) {
			$this->set('spm_path', self::canonicalize_path($this->get('spm_path')));
		}
		parent::save($debug);
	}

	public static function canonicalize_path($path) {
		if ($path === null || $path === '') {
			return '/';
		}
		$decoded = rawurldecode($path);
		if ($decoded === '/') {
			return '/';
		}
		$decoded = rtrim($decoded, '/');
		if ($decoded === '') {
			return '/';
		}
		return strtolower($decoded);
	}

	public static function absolutize_url($url, $site_url = null) {
		if (!$url) {
			return $url;
		}
		if (preg_match('#^https?://#i', $url) || strpos($url, '//') === 0 || strpos($url, 'data:') === 0) {
			return $url;
		}
		if ($site_url === null) {
			$settings = Globalvars::get_instance();
			$webDir = $settings->get_setting('webDir');
			$site_url = 'https://' . $webDir;
		}
		if (strpos($url, '/') !== 0) {
			$url = '/' . $url;
		}
		return rtrim($site_url, '/') . $url;
	}

	public static function find_for_path($path) {
		$canonical = self::canonicalize_path($path);
		if (array_key_exists($canonical, self::$per_request_lookup_cache)) {
			return self::$per_request_lookup_cache[$canonical];
		}

		try {
			$multi = new MultiSeoPageMetadata(array('path' => $canonical, 'deleted' => false), array(), 1);
			$multi->load();
			$row = null;
			foreach ($multi as $r) {
				$row = $r;
				break;
			}
			self::$per_request_lookup_cache[$canonical] = $row;
			return $row;
		} catch (\Throwable $e) {
			self::$per_request_lookup_cache[$canonical] = null;
			return null;
		}
	}

	public static function lazy_auto_create($path, $options = array()) {
		$canonical = self::canonicalize_path($path);

		if (!self::is_eligible_for_lazy_create($canonical, $options)) {
			return false;
		}

		try {
			$dblink = DbConnector::get_instance()->get_db_link();
			$sql = "INSERT INTO spm_seo_page_metadata (spm_path, spm_create_time)
			        VALUES (?, now())
			        ON CONFLICT (spm_path) DO NOTHING";
			$q = $dblink->prepare($sql);
			$q->execute(array($canonical));
			self::$per_request_lookup_cache[$canonical] = null;
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private static function is_eligible_for_lazy_create($canonical, $options) {
		if (!isset($options['is_valid_page']) || $options['is_valid_page'] === false) {
			return false;
		}
		if (strpos($canonical, '/admin') === 0 || strpos($canonical, '/ajax') === 0
			|| strpos($canonical, '/utils') === 0 || strpos($canonical, '/api') === 0) {
			return false;
		}
		if (self::is_entity_path($canonical)) {
			return false;
		}
		return true;
	}

	public static function is_entity_path($canonical) {
		foreach (self::entity_classes() as $info) {
			$ns = '/' . $info['namespace'] . '/';
			if (strpos($canonical, $ns) === 0) {
				return true;
			}
		}
		return false;
	}

	// --- Inference (Layer 2e) ---

	public static function infer_for_request($path, $options = array()) {
		return array(
			'title'            => self::infer_title($path, $options),
			'meta_description' => self::infer_description($path, $options),
			'preview_image'    => self::infer_preview_image($path, $options),
			'og_type'          => self::infer_og_type($path, $options),
		);
	}

	public static function infer_title($path, $options = array()) {
		if (!empty($options['title'])) {
			return null;
		}
		$canonical = self::canonicalize_path($path);
		if ($canonical === '/') {
			return null;
		}

		$segments = array_values(array_filter(explode('/', $canonical), 'strlen'));
		if (empty($segments)) {
			return null;
		}

		$last = end($segments);
		$humanized = self::humanize_segment($last);

		if (count($segments) > 1) {
			$plugin_segment = $segments[0];
			$plugin_display = self::get_plugin_display_name($plugin_segment);
			if ($plugin_display !== null && strtolower($plugin_display) !== strtolower($humanized)) {
				return $humanized . ' — ' . $plugin_display;
			}
		}

		return $humanized;
	}

	public static function humanize_segment($segment) {
		$replaced = str_replace(array('-', '_'), ' ', $segment);
		$words = preg_split('/\s+/', trim($replaced));
		$acronyms = array('api'=>'API', 'faq'=>'FAQ', 'seo'=>'SEO', 'url'=>'URL',
			'rss'=>'RSS', 'json'=>'JSON', 'sql'=>'SQL', 'css'=>'CSS', 'html'=>'HTML',
			'js'=>'JS', 'ui'=>'UI', 'ux'=>'UX', 'pdf'=>'PDF', 'dns'=>'DNS');
		$out = array();
		foreach ($words as $w) {
			$lower = strtolower($w);
			if (isset($acronyms[$lower])) {
				$out[] = $acronyms[$lower];
			} else {
				$out[] = ucfirst($lower);
			}
		}
		return implode(' ', $out);
	}

	private static function get_plugin_display_name($segment) {
		static $cache = null;
		if ($cache === null) {
			$cache = array();
			$plugins_dir = PathHelper::getIncludePath('plugins');
			if (is_dir($plugins_dir)) {
				foreach (scandir($plugins_dir) as $entry) {
					if ($entry === '.' || $entry === '..') continue;
					$manifest = $plugins_dir . '/' . $entry . '/plugin.json';
					if (file_exists($manifest)) {
						$raw = file_get_contents($manifest);
						$data = json_decode($raw, true);
						if (is_array($data) && !empty($data['display_name'])) {
							$cache[strtolower($entry)] = $data['display_name'];
						}
					}
				}
			}
		}
		$key = strtolower($segment);
		return $cache[$key] ?? null;
	}

	public static function apply_title_format($title, $site_name) {
		if (!$title) {
			return $site_name;
		}
		if (!$site_name) {
			return $title;
		}
		if (strcasecmp(trim($title), trim($site_name)) === 0) {
			return $title;
		}
		return str_replace(array('{title}', '{site_name}'), array($title, $site_name), self::TITLE_FORMAT);
	}

	public static function infer_description($path, $options = array()) {
		if (!empty($options['meta_description'])) {
			return null;
		}
		if (!empty($options['entity_body_html'])) {
			return self::summarize_html($options['entity_body_html'], 160);
		}
		return null;
	}

	public static function summarize_html($html, $max_length = 160) {
		if (!$html) return null;
		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text);
		$text = trim($text);
		if ($text === '') return null;
		if (mb_strlen($text, 'UTF-8') <= $max_length) {
			return $text;
		}
		$truncated = mb_substr($text, 0, $max_length, 'UTF-8');
		$last_space = mb_strrpos($truncated, ' ', 0, 'UTF-8');
		if ($last_space !== false && $last_space > ($max_length / 2)) {
			$truncated = mb_substr($truncated, 0, $last_space, 'UTF-8');
		}
		return rtrim($truncated, " \t\n\r\0\x0B.,;:-") . '…';
	}

	public static function infer_preview_image($path, $options = array()) {
		if (!empty($options['preview_image_url'])) {
			return null;
		}
		if (!empty($options['entity_body_html'])) {
			$body = $options['entity_body_html'];
			if (preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $body, $m)) {
				$src = $m[1];
				if (strpos($src, 'data:') === 0) return null;
				$lower = strtolower($src);
				if (strpos($lower, 'pixel') !== false || strpos($lower, 'tracking') !== false
					|| strpos($lower, '1x1') !== false || strpos($lower, '=1&') !== false
					|| substr($lower, -3) === '=1') {
					return null;
				}
				return $src;
			}
		}
		return null;
	}

	public static function infer_og_type($path, $options = array()) {
		if (!empty($options['og_type'])) {
			return null;
		}
		$type = $options['entity_type'] ?? null;
		if (!$type) return 'website';
		$info = self::entity_classes()[$type] ?? null;
		return $info['og_type'] ?? 'website';
	}

	// --- Enumeration (Layer 2c + sitemap shared source) ---

	public static function enumerate_public_paths() {
		$records = array();

		foreach (self::enumerate_static_paths() as $rec) {
			$records[] = $rec;
		}

		foreach (self::entity_classes() as $type_key => $info) {
			foreach (self::enumerate_entity_records($type_key, $info) as $rec) {
				$records[] = $rec;
			}
		}

		return $records;
	}

	private static function enumerate_static_paths() {
		$records = array();
		$seen = array();
		$settings = Globalvars::get_instance();
		$active_theme = $settings->get_setting('theme_template', false, true);

		$candidate_dirs = array(PathHelper::getIncludePath('views'));
		if ($active_theme) {
			$theme_views = PathHelper::getIncludePath('theme/' . $active_theme . '/views');
			if (is_dir($theme_views)) {
				$candidate_dirs[] = $theme_views;
			}
		}

		$skip_files = array('post.php', 'event.php', 'product.php', 'page.php',
			'location.php', 'video.php', 'list.php', '404.php', 'sitemap.php',
			'robots.php', 'login.php', 'logout.php', 'register.php',
			'change-password-required.php');

		foreach ($candidate_dirs as $dir) {
			if (!is_dir($dir)) continue;
			foreach (scandir($dir) as $entry) {
				if ($entry === '.' || $entry === '..') continue;
				if (substr($entry, -4) !== '.php') continue;
				if (in_array($entry, $skip_files, true)) continue;
				$base = substr($entry, 0, -4);
				if ($base === 'index') {
					$path = '/';
				} else {
					$path = '/' . $base;
				}
				$canonical = self::canonicalize_path($path);
				if (isset($seen[$canonical])) continue;
				$seen[$canonical] = true;
				$records[] = array(
					'path'        => $canonical,
					'entity_type' => null,
					'entity_id'   => null,
					'modify_time' => null,
				);
			}
		}

		$plugins_dir = PathHelper::getIncludePath('plugins');
		if (is_dir($plugins_dir)) {
			foreach (scandir($plugins_dir) as $plugin) {
				if ($plugin === '.' || $plugin === '..') continue;
				$views_dir = $plugins_dir . '/' . $plugin . '/views';
				if (!is_dir($views_dir)) continue;
				foreach (self::scan_plugin_views($views_dir, '') as $sub_path) {
					$canonical = self::canonicalize_path('/' . $plugin . $sub_path);
					if (isset($seen[$canonical])) continue;
					$seen[$canonical] = true;
					$records[] = array(
						'path'        => $canonical,
						'entity_type' => null,
						'entity_id'   => null,
						'modify_time' => null,
					);
				}
			}
		}

		return $records;
	}

	private static function scan_plugin_views($dir, $prefix) {
		$paths = array();
		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$full = $dir . '/' . $entry;
			if (is_dir($full)) {
				foreach (self::scan_plugin_views($full, $prefix . '/' . $entry) as $sub) {
					$paths[] = $sub;
				}
			} elseif (substr($entry, -4) === '.php') {
				$base = substr($entry, 0, -4);
				if ($base === 'index') {
					$paths[] = $prefix === '' ? '' : $prefix;
				} else {
					$paths[] = $prefix . '/' . $base;
				}
			}
		}
		return $paths;
	}

	private static function enumerate_entity_records($type_key, $info) {
		$records = array();
		if (!file_exists(PathHelper::getIncludePath($info['file']))) {
			return $records;
		}
		require_once(PathHelper::getIncludePath($info['file']));
		$class = $info['class'];
		$multi_class = $info['multi'];
		if (!class_exists($class) || !class_exists($multi_class)) {
			return $records;
		}

		$prefix = $class::$prefix;
		$pkey = $class::$pkey_column;
		$link_col = $prefix . '_link';
		$delete_col = $prefix . '_delete_time';
		$tablename = $class::$tablename;

		$dblink = DbConnector::get_instance()->get_db_link();
		$specs = $class::$field_specifications;
		$select_cols = array($pkey);
		if (isset($specs[$link_col])) $select_cols[] = $link_col;
		$modify_col = null;
		foreach (array($prefix.'_modify_time', $prefix.'_published_time', $prefix.'_create_time') as $candidate) {
			if (isset($specs[$candidate])) {
				$modify_col = $candidate;
				break;
			}
		}
		if ($modify_col) $select_cols[] = $modify_col;

		$where = $delete_col . ' IS NULL';
		$sql = 'SELECT ' . implode(', ', $select_cols) . ' FROM ' . $tablename . ' WHERE ' . $where;
		try {
			$q = $dblink->prepare($sql);
			$q->execute();
			while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
				$slug = isset($row[$link_col]) ? trim((string)$row[$link_col]) : '';
				if ($slug === '') continue;
				$path = self::canonicalize_path('/' . $info['namespace'] . '/' . $slug);
				$records[] = array(
					'path'        => $path,
					'entity_type' => $type_key,
					'entity_id'   => (int)$row[$pkey],
					'modify_time' => $modify_col ? ($row[$modify_col] ?? null) : null,
				);
			}
		} catch (\Throwable $e) {
			error_log('SeoPageMetadata enumerate_entity_records failed for ' . $type_key . ': ' . $e->getMessage());
		}
		return $records;
	}

	public static function sync_inventory() {
		$result = array(
			'inserted_static'  => 0,
			'inserted_entity'  => 0,
			'updated_path'     => 0,
			'soft_deleted'     => 0,
			'errors'           => array(),
		);

		$dblink = DbConnector::get_instance()->get_db_link();
		$records = self::enumerate_public_paths();

		$entity_ids_seen = array();
		foreach (self::entity_classes() as $type_key => $_info) {
			$entity_ids_seen[$type_key] = array();
		}

		foreach ($records as $rec) {
			try {
				if ($rec['entity_type'] !== null) {
					$entity_ids_seen[$rec['entity_type']][] = (int)$rec['entity_id'];

					$sql = "SELECT spm_seo_page_metadata_id, spm_path
					        FROM spm_seo_page_metadata
					        WHERE spm_entity_type = ? AND spm_entity_id = ? AND spm_delete_time IS NULL";
					$q = $dblink->prepare($sql);
					$q->execute(array($rec['entity_type'], $rec['entity_id']));
					$existing = $q->fetch(PDO::FETCH_ASSOC);

					if ($existing) {
						if ($existing['spm_path'] !== $rec['path']) {
							$upd = $dblink->prepare("UPDATE spm_seo_page_metadata
								SET spm_path = ?, spm_modify_time = now()
								WHERE spm_seo_page_metadata_id = ?");
							$upd->execute(array($rec['path'], $existing['spm_seo_page_metadata_id']));
							$result['updated_path']++;
						}
					} else {
						$ins = $dblink->prepare("INSERT INTO spm_seo_page_metadata
							(spm_path, spm_entity_type, spm_entity_id, spm_create_time)
							VALUES (?, ?, ?, now())
							ON CONFLICT (spm_path) DO NOTHING");
						$ins->execute(array($rec['path'], $rec['entity_type'], $rec['entity_id']));
						if ($ins->rowCount() > 0) {
							$result['inserted_entity']++;
						}
					}
				} else {
					$ins = $dblink->prepare("INSERT INTO spm_seo_page_metadata
						(spm_path, spm_create_time)
						VALUES (?, now())
						ON CONFLICT (spm_path) DO NOTHING");
					$ins->execute(array($rec['path']));
					if ($ins->rowCount() > 0) {
						$result['inserted_static']++;
					}
				}
			} catch (\Throwable $e) {
				$result['errors'][] = $e->getMessage();
			}
		}

		foreach ($entity_ids_seen as $type_key => $ids) {
			if (empty($ids)) continue;
			try {
				$placeholders = implode(',', array_fill(0, count($ids), '?'));
				$sql = "UPDATE spm_seo_page_metadata
				        SET spm_delete_time = now()
				        WHERE spm_entity_type = ?
				          AND spm_delete_time IS NULL
				          AND spm_entity_id NOT IN ($placeholders)";
				$q = $dblink->prepare($sql);
				$q->execute(array_merge(array($type_key), array_map('intval', $ids)));
				$result['soft_deleted'] += $q->rowCount();
			} catch (\Throwable $e) {
				$result['errors'][] = $e->getMessage();
			}
		}

		return $result;
	}

	public static function find_orphans() {
		$orphans = array();
		$dblink = DbConnector::get_instance()->get_db_link();

		$current_records = self::enumerate_public_paths();
		$current_static_paths = array();
		foreach ($current_records as $rec) {
			if ($rec['entity_type'] === null) {
				$current_static_paths[$rec['path']] = true;
			}
		}

		$sql = "SELECT spm_seo_page_metadata_id, spm_path, spm_entity_type, spm_entity_id
		        FROM spm_seo_page_metadata
		        WHERE spm_delete_time IS NULL
		          AND spm_entity_type IS NULL";
		$q = $dblink->query($sql);
		while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($current_static_paths[$row['spm_path']])) {
				$orphans[] = array(
					'id'          => (int)$row['spm_seo_page_metadata_id'],
					'path'        => $row['spm_path'],
					'entity_type' => null,
					'reason'      => 'Static path no longer resolves to a view',
				);
			}
		}

		$known_types = array_keys(self::entity_classes());
		$placeholders = implode(',', array_fill(0, count($known_types), '?'));
		$sql = "SELECT spm_seo_page_metadata_id, spm_path, spm_entity_type, spm_entity_id
		        FROM spm_seo_page_metadata
		        WHERE spm_delete_time IS NULL
		          AND spm_entity_type IS NOT NULL
		          AND spm_entity_type NOT IN ($placeholders)";
		$q = $dblink->prepare($sql);
		$q->execute($known_types);
		while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
			$orphans[] = array(
				'id'          => (int)$row['spm_seo_page_metadata_id'],
				'path'        => $row['spm_path'],
				'entity_type' => $row['spm_entity_type'],
				'reason'      => 'Tagged with entity type "' . $row['spm_entity_type'] . '" — outside core enumeration loop',
			);
		}

		return $orphans;
	}
}

class MultiSeoPageMetadata extends SystemMultiBase {
	protected static $model_class = 'SeoPageMetadata';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['path'])) {
			$filters['spm_path'] = array($this->options['path'], PDO::PARAM_STR);
		}

		if (isset($this->options['entity_type'])) {
			if ($this->options['entity_type'] === null) {
				$filters['spm_entity_type'] = 'IS NULL';
			} else {
				$filters['spm_entity_type'] = array($this->options['entity_type'], PDO::PARAM_STR);
			}
		}

		if (isset($this->options['entity_id'])) {
			$filters['spm_entity_id'] = array((int)$this->options['entity_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['noindex'])) {
			$filters['spm_noindex'] = $this->options['noindex'] ? '= TRUE' : '= FALSE';
		}

		if (isset($this->options['deleted'])) {
			$filters['spm_delete_time'] = $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL';
		} else {
			$filters['spm_delete_time'] = 'IS NULL';
		}

		if (isset($this->options['has_overrides']) && $this->options['has_overrides']) {
			$filters['(spm_title'] = "IS NOT NULL
				OR spm_meta_description IS NOT NULL
				OR spm_og_title IS NOT NULL
				OR spm_og_description IS NOT NULL
				OR spm_preview_image_url IS NOT NULL
				OR spm_og_type IS NOT NULL
				OR spm_noindex = TRUE)";
		}

		if (isset($this->options['searchterm']) && $this->options['searchterm']) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$term = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $this->options['searchterm']) . '%';
			$filters['spm_path'] = 'ILIKE ' . $dblink->quote($term);
		}

		return $this->_get_resultsv2('spm_seo_page_metadata', $filters, $this->order_by, $only_count, $debug);
	}
}

// Register the core-owned public entity types when this file is loaded.
// Store and event_manager add their own from their serve.php.
SeoPageMetadata::register_core_entity_classes();

?>
