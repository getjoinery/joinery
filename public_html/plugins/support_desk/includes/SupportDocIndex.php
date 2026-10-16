<?php
/**
 * SupportDocIndex — what the support desk knows, and how a turn finds it
 * (support_chatbot.md, The knowledge index).
 *
 * One function builds a release's index from whatever this node holds of it:
 * buildFromArchive() reads a release archive this node serves (it published
 * the release, or received it to republish), buildFromTree() reads the tree
 * this node runs. sweep() is the one caller of both: it indexes every release
 * this node holds that has no index yet, so a publisher indexes what it makes,
 * a republisher what it receives, and any node what it upgrades to, without a
 * hook in publishing or upgrading. A release's index is built once and never
 * edited.
 *
 * What a release's index holds: every Markdown file under docs/ (the shipped
 * FAQ in docs/faq/ among them) and every published plugin's docs/, split by
 * heading into rows of sds_doc_sections. A section
 * longer than SECTION_CAP is split at paragraph boundaries with its heading
 * chain repeated. Beside the rows, a SupportDocRelease row carries the
 * release's docs map and its shipped FAQ, which the fixed prefix of every
 * thread on that release carries whole.
 *
 * Rows tagged `current` belong to no release: this node's public pages
 * (indexMarketing(), rebuilt on every sweep) and the operator's local FAQ
 * (indexLocalFaq(), on every save). A thread searches its release's rows plus
 * `current`.
 *
 * The documents are this platform's own, read from its own archives; nothing
 * here reads a path a request named.
 *
 * @version 0.2.0 - documents come from docs/ alone: the root-file list (INSTALL_README.md) is gone with that file's guide
 * @version 0.1.0
 */
class SupportDocIndex {

	/** Longest section body kept as one row, in characters. */
	const SECTION_CAP = 6000;

	/** How many of the newest held release archives a sweep indexes. */
	const ARCHIVES_KEPT = 5;

	/** Results search() returns at most. */
	const SEARCH_LIMIT = 8;

	/** Longest section read_doc_section returns, in characters. */
	const READ_CAP = 14000;

	/** Characters of a search hit's excerpt. */
	const SNIPPET_LENGTH = 260;

	// ------------------------------------------------------------- building

	/**
	 * Index a release from an archive this node holds. Returns
	 * ['built' => bool, 'sections' => int, 'message' => string].
	 */
	public static function buildFromArchive(string $version): array {
		if (!self::isVersion($version)) {
			return array('built' => false, 'sections' => 0, 'message' => "'$version' is not a release name.");
		}
		if (SupportDocRelease::forRelease($version) !== null) {
			return array('built' => false, 'sections' => 0, 'message' => "Release $version is already indexed.");
		}
		$archive = self::archivePath($version);
		if ($archive === null) {
			return array('built' => false, 'sections' => 0, 'message' => "This node holds no archive of $version.");
		}
		$work = self::scratchDir();
		try {
			self::extract($archive, $work . '/core', array('./public_html/docs'));
			$files = self::collect($work . '/core/public_html');
			// Plugin documentation ships in each plugin's own archive; the ones
			// this node serves are the set that goes with its releases.
			foreach (self::heldPluginArchives() as $name => $plugin_archive) {
				self::extract($plugin_archive, $work . '/plugins', array($name . '/docs'));
				foreach (self::markdownUnder($work . '/plugins/' . $name . '/docs') as $rel => $abs) {
					$files['plugins/' . $name . '/docs/' . $rel] = $abs;
				}
			}
			return self::store($version, SupportDocRelease::SOURCE_ARCHIVE, $files);
		} finally {
			self::removeDir($work);
		}
	}

	/** Index a release from the tree this node runs (its own release). */
	public static function buildFromTree(string $version, ?string $public_html = null): array {
		if (!self::isVersion($version)) {
			return array('built' => false, 'sections' => 0, 'message' => "'$version' is not a release name.");
		}
		if (SupportDocRelease::forRelease($version) !== null) {
			return array('built' => false, 'sections' => 0, 'message' => "Release $version is already indexed.");
		}
		$public_html = rtrim($public_html ?? PathHelper::getRootDir(), '/');
		$files = self::collect($public_html);
		foreach (glob($public_html . '/plugins/*', GLOB_ONLYDIR) ?: array() as $plugin_dir) {
			$name = basename($plugin_dir);
			if (!preg_match('/^[a-z0-9_]+$/', $name) || !self::published($plugin_dir)) {
				continue;
			}
			foreach (self::markdownUnder($plugin_dir . '/docs') as $rel => $abs) {
				$files['plugins/' . $name . '/docs/' . $rel] = $abs;
			}
		}
		return self::store($version, SupportDocRelease::SOURCE_TREE, $files);
	}

	/**
	 * Index what this node holds and has not indexed: its own release, then the
	 * newest ARCHIVES_KEPT release archives it serves; and rebuild the public
	 * pages. Returns one message per thing done.
	 */
	public static function sweep(): array {
		$messages = array();
		$own = (string)LibraryFunctions::get_joinery_version();
		if (self::isVersion($own) && SupportDocRelease::forRelease($own) === null) {
			// A node that serves its own release from an archive reads the
			// archive (exactly what sites receive); any other node reads its tree.
			$r = self::archivePath($own) !== null ? self::buildFromArchive($own) : self::buildFromTree($own);
			$messages[] = $r['message'];
		}
		foreach (array_slice(self::heldReleases(), 0, self::ARCHIVES_KEPT) as $version) {
			if (SupportDocRelease::forRelease($version) === null) {
				$r = self::buildFromArchive($version);
				$messages[] = $r['message'];
			}
		}
		$pages = self::indexMarketing();
		$messages[] = 'Public pages indexed: ' . $pages . '.';
		return $messages;
	}

	/** Store a release's files as sections, its map and its FAQ, in one transaction. */
	private static function store(string $version, string $source, array $files): array {
		ksort($files);
		$db = DbConnector::get_instance()->get_db_link();
		$count = 0;
		$map_lines = array();
		$faq_parts = array();
		$db->beginTransaction();
		try {
			// The release row first: its unique release name makes a second
			// sweep running at the same moment wait here and then fail, rather
			// than write every section twice.
			$release = new SupportDocRelease(NULL);
			$release->set('sdr_release', $version);
			$release->set('sdr_source', $source);
			$release->save();

			$insert = $db->prepare('INSERT INTO sds_doc_sections
				(sds_release, sds_kind, sds_path, sds_heading, sds_part, sds_body, sds_search_text)
				VALUES (?, ?, ?, ?, ?, ?, ?)');
			foreach ($files as $path => $abs) {
				$markdown = @file_get_contents($abs);
				if (!is_string($markdown) || trim($markdown) === '') {
					continue;
				}
				$kind = self::isFaqPath($path) ? SupportDocSection::KIND_FAQ : SupportDocSection::KIND_DOC;
				$sections = self::sectionsOf($path, $markdown);
				foreach ($sections as $s) {
					$insert->execute(array($version, $kind, $path, $s['heading'], $s['part'], $s['body'],
						$s['heading'] . "\n" . $s['body']));
					$count++;
				}
				if ($kind === SupportDocSection::KIND_FAQ) {
					$faq_parts[] = self::faqBlock($path, $sections);
				} else {
					$map_lines[] = self::mapLines($path, $markdown, $sections);
				}
			}
			$release->set('sdr_sections', $count);
			$release->set('sdr_map', implode("\n", $map_lines));
			$release->set('sdr_faq', implode("\n\n", array_filter($faq_parts)));
			$release->save();
			$db->commit();
		} catch (\Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			return array('built' => false, 'sections' => 0, 'message' => "Release $version was not indexed: " . $e->getMessage());
		}
		return array('built' => true, 'sections' => $count,
			'message' => "Release $version indexed from its " . $source . ': ' . count($files) . ' files, ' . $count . ' sections.');
	}

	/**
	 * A Markdown document as sections: [heading, part, body] each, the heading
	 * the chain of headings above the section joined with " > ". Headings inside
	 * fenced code are text. The text before the first heading belongs to the
	 * document's title. A section with no body of its own is left out (its
	 * heading is still in the chain of the sections under it).
	 */
	public static function sectionsOf(string $path, string $markdown): array {
		$lines = preg_split('/\r?\n/', str_replace("\r\n", "\n", $markdown));
		$title = self::titleOf($path, $markdown);
		$chain = array();          // level => heading text
		$current_chain = array($title);
		$buffer = array();
		$sections = array();
		$in_fence = false;
		$fence = '';

		$flush = function () use (&$buffer, &$current_chain, &$sections) {
			$body = trim(implode("\n", $buffer));
			$buffer = array();
			if ($body === '') {
				return;
			}
			$heading = implode(' > ', $current_chain);
			foreach (self::splitBody($body) as $i => $part) {
				$sections[] = array('heading' => mb_substr($heading, 0, 1000), 'part' => $i, 'body' => $part);
			}
		};

		foreach ($lines as $line) {
			if (preg_match('/^\s{0,3}(```+|~~~+)/', $line, $m)) {
				if (!$in_fence) {
					$in_fence = true;
					$fence = $m[1][0];
				} elseif ($m[1][0] === $fence) {
					$in_fence = false;
				}
				$buffer[] = $line;
				continue;
			}
			if (!$in_fence && preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
				$flush();
				$level = strlen($m[1]);
				$text = trim($m[2]);
				foreach (array_keys($chain) as $l) {
					if ($l >= $level) {
						unset($chain[$l]);
					}
				}
				$chain[$level] = $text;
				ksort($chain);
				$current_chain = array_values($chain);
				// A level-one heading is the title; keep it at the head of the chain.
				if (!isset($chain[1])) {
					array_unshift($current_chain, $title);
				}
				continue;
			}
			$buffer[] = $line;
		}
		$flush();
		return $sections;
	}

	/** A body longer than SECTION_CAP, cut at paragraph boundaries. */
	private static function splitBody(string $body): array {
		if (mb_strlen($body) <= self::SECTION_CAP) {
			return array($body);
		}
		$parts = array();
		$current = '';
		foreach (preg_split('/\n{2,}/', $body) as $para) {
			if ($current !== '' && mb_strlen($current) + mb_strlen($para) + 2 > self::SECTION_CAP) {
				$parts[] = $current;
				$current = '';
			}
			// One paragraph longer than the cap is cut where it must be.
			while (mb_strlen($para) > self::SECTION_CAP) {
				$parts[] = mb_substr($para, 0, self::SECTION_CAP);
				$para = mb_substr($para, self::SECTION_CAP);
			}
			$current = $current === '' ? $para : $current . "\n\n" . $para;
		}
		if ($current !== '') {
			$parts[] = $current;
		}
		return $parts;
	}

	/** A document's title: its first level-one heading, else its file name. */
	private static function titleOf(string $path, string $markdown): string {
		if (preg_match('/^#\s+(.+?)\s*#*\s*$/m', $markdown, $m)) {
			return trim($m[1]);
		}
		return ucfirst(str_replace('_', ' ', basename($path, '.md')));
	}

	/**
	 * A document's lines in the docs map: its path, title and first sentence,
	 * then its second-level headings, so the model's first search can name the
	 * words a section uses.
	 */
	private static function mapLines(string $path, string $markdown, array $sections): string {
		$title = self::titleOf($path, $markdown);
		$summary = '';
		foreach ($sections as $s) {
			$text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$s['body'])));
			if ($text !== '' && strpos($text, '|') !== 0) {
				$summary = self::firstSentence($text);
				break;
			}
		}
		$out = '- ' . $path . ' — ' . $title . ($summary !== '' ? ': ' . $summary : '');
		$seen = array();
		foreach ($sections as $s) {
			$parts = explode(' > ', (string)$s['heading']);
			if (count($parts) >= 2) {
				$h2 = $parts[1];
				if (!isset($seen[$h2])) {
					$seen[$h2] = true;
					$out .= "\n    - " . $h2;
				}
			}
		}
		return $out;
	}

	private static function firstSentence(string $text): string {
		if (preg_match('/^(.{20,220}?[.!?])(\s|$)/u', $text, $m)) {
			return $m[1];
		}
		return mb_substr($text, 0, 160) . (mb_strlen($text) > 160 ? '…' : '');
	}

	/** One FAQ file as the fixed prefix carries it: each question with its answer. */
	private static function faqBlock(string $path, array $sections): string {
		$out = array();
		foreach ($sections as $s) {
			$parts = explode(' > ', (string)$s['heading']);
			$question = end($parts);
			$out[] = '### ' . $question . "\n(" . $path . ")\n" . trim((string)$s['body']);
		}
		return implode("\n\n", $out);
	}

	private static function isFaqPath(string $path): bool {
		return (bool)preg_match('#(^|/)docs/faq/[^/]+\.md$#', $path);
	}

	/**
	 * Whether a plugin in the tree goes out with releases: the same test the
	 * publisher applies (included_in_publish, true unless it says false). A
	 * plugin kept to this node, or with no manifest, is not documentation a
	 * site or a visitor can be pointed to.
	 */
	private static function published(string $plugin_dir): bool {
		$manifest = json_decode((string)@file_get_contents($plugin_dir . '/plugin.json'), true);
		return is_array($manifest) && ($manifest['included_in_publish'] ?? true) !== false;
	}

	/** The documentation files under a release's public_html. */
	private static function collect(string $public_html): array {
		$files = array();
		foreach (self::markdownUnder($public_html . '/docs') as $rel => $abs) {
			$files['docs/' . $rel] = $abs;
		}
		return $files;
	}

	/** Every .md file under $dir, keyed by its path relative to $dir. Symlinks are not followed. */
	private static function markdownUnder(string $dir): array {
		$out = array();
		if (!is_dir($dir) || is_link($dir)) {
			return $out;
		}
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'md') {
				continue;
			}
			$rel = ltrim(substr($file->getPathname(), strlen($dir)), '/');
			$out[$rel] = $file->getPathname();
		}
		ksort($out);
		return $out;
	}

	// ------------------------------------------------------- what is held

	/** Where this node serves release archives from. */
	private static function servedDir(): string {
		return rtrim(PathHelper::getSiteRoot(), '/') . '/static_files';
	}

	/** The core archive of $version this node serves, or null. */
	public static function archivePath(string $version): ?string {
		if (!self::isVersion($version)) {
			return null;
		}
		$path = self::servedDir() . '/joinery-core-' . $version . '.tar.gz';
		return is_file($path) && !is_link($path) ? $path : null;
	}

	/** Releases this node holds an archive of, newest first. */
	public static function heldReleases(): array {
		$out = array();
		foreach (glob(self::servedDir() . '/joinery-core-*.tar.gz') ?: array() as $path) {
			if (preg_match('/joinery-core-(.+)\.tar\.gz$/', basename($path), $m) && self::isVersion($m[1])) {
				$out[] = $m[1];
			}
		}
		usort($out, function ($a, $b) { return version_compare($b, $a); });
		return $out;
	}

	/** The newest archive of each plugin this node serves: name => path. */
	private static function heldPluginArchives(): array {
		$out = array();
		foreach (glob(self::servedDir() . '/plugins/*.tar.gz') ?: array() as $path) {
			if (!preg_match('/^([a-z0-9_]+)-([0-9][0-9A-Za-z.+\-]*)\.tar\.gz$/', basename($path), $m)) {
				continue;
			}
			if (!isset($out[$m[1]]) || version_compare($m[2], $out[$m[1]]['v'], '>')) {
				$out[$m[1]] = array('v' => $m[2], 'path' => $path);
			}
		}
		return array_map(function ($e) { return $e['path']; }, $out);
	}

	private static function extract(string $archive, string $to, array $members): void {
		if (!is_dir($to) && !@mkdir($to, 0700, true)) {
			throw new RuntimeException('Could not make a working directory.');
		}
		$cmd = 'tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($to)
			. ' --no-same-owner --no-same-permissions --wildcards';
		foreach ($members as $m) {
			$cmd .= ' ' . escapeshellarg($m);
		}
		// A member the archive lacks is not an error worth failing for (an older
		// release has no FAQ); tar says so and the files it did find stay.
		exec($cmd . ' 2>/dev/null', $out, $status);
	}

	private static function scratchDir(): string {
		$dir = sys_get_temp_dir() . '/support_desk_index_' . bin2hex(random_bytes(6));
		if (!@mkdir($dir, 0700, true)) {
			throw new RuntimeException('Could not make a working directory.');
		}
		return $dir;
	}

	private static function removeDir(string $dir): void {
		if ($dir !== '' && strpos($dir, sys_get_temp_dir() . '/support_desk_index_') === 0 && is_dir($dir)) {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}

	public static function isVersion(string $v): bool {
		return (bool)preg_match('/^[0-9][0-9A-Za-z.+\-]{0,63}$/', $v);
	}

	// ------------------------------------------------- current: pages, FAQ

	/** This node's published public pages, as `current` rows. Returns how many. */
	public static function indexMarketing(): int {
		$db = DbConnector::get_instance()->get_db_link();
		$pages = array();
		try {
			$q = $db->query("SELECT pag_page_id FROM pag_pages
				WHERE pag_delete_time IS NULL AND pag_published_time IS NOT NULL
				  AND pag_tier_min_level IS NULL AND pag_link IS NOT NULL AND pag_link <> ''
				ORDER BY pag_page_id");
			$ids = $q->fetchAll(PDO::FETCH_COLUMN);
		} catch (\Throwable $e) {
			return 0;
		}
		foreach ($ids as $id) {
			try {
				$page = new Page((int)$id, TRUE);
				$html = (string)$page->get_filled_content();
			} catch (\Throwable $e) {
				continue;
			}
			$text = trim(preg_replace("/[ \t]+/", ' ', html_entity_decode(strip_tags(
				preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', str_replace(array('</p>', '<br>', '<br/>', '</h1>', '</h2>', '</h3>', '</li>'), "\n", $html))),
				ENT_QUOTES, 'UTF-8')));
			$text = preg_replace("/\n{3,}/", "\n\n", $text);
			if ($text === '') {
				continue;
			}
			$pages[] = array('id' => (int)$id, 'path' => 'page/' . $page->get('pag_link'),
				'title' => (string)$page->get('pag_title'), 'text' => $text);
		}
		$db->beginTransaction();
		try {
			$del = $db->prepare('DELETE FROM sds_doc_sections WHERE sds_release = ? AND sds_kind = ?');
			$del->execute(array(SupportDocSection::CURRENT, SupportDocSection::KIND_PAGE));
			$insert = $db->prepare('INSERT INTO sds_doc_sections
				(sds_release, sds_kind, sds_path, sds_heading, sds_part, sds_body, sds_search_text, sds_ref_id)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
			foreach ($pages as $p) {
				foreach (self::splitBody($p['text']) as $i => $part) {
					$heading = 'Public page: ' . ($p['title'] !== '' ? $p['title'] : $p['path']);
					$insert->execute(array(SupportDocSection::CURRENT, SupportDocSection::KIND_PAGE, $p['path'],
						$heading, $i, $part, $heading . "\n" . $part, $p['id']));
				}
			}
			$db->commit();
		} catch (\Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			return 0;
		}
		return count($pages);
	}

	/** Put one local FAQ entry in the index as it now reads (or out, if it is deleted). */
	public static function indexLocalFaq(SupportFaqEntry $entry): void {
		$id = (int)$entry->key;
		if ($id <= 0) {
			return;
		}
		self::removeLocalFaq($id);
		if ($entry->get('sfq_delete_time')) {
			return;
		}
		$question = (string)$entry->get('sfq_question');
		$body = (string)$entry->get('sfq_answer');
		$release = (string)$entry->get('sfq_release');
		if ($release !== '') {
			$body .= "\n\n(Written by this desk's operator on release " . $release . '.)';
		}
		$heading = 'FAQ (this desk) > ' . $question;
		$insert = DbConnector::get_instance()->get_db_link()->prepare('INSERT INTO sds_doc_sections
			(sds_release, sds_kind, sds_path, sds_heading, sds_part, sds_body, sds_search_text, sds_ref_id)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
		foreach (self::splitBody($body) as $i => $part) {
			$insert->execute(array(SupportDocSection::CURRENT, SupportDocSection::KIND_LOCAL_FAQ,
				$entry->indexPath(), mb_substr($heading, 0, 1000), $i, $part, $heading . "\n" . $part, $id));
		}
	}

	public static function removeLocalFaq(int $id): void {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'DELETE FROM sds_doc_sections WHERE sds_release = ? AND sds_kind = ? AND sds_ref_id = ?');
		$stmt->execute(array(SupportDocSection::CURRENT, SupportDocSection::KIND_LOCAL_FAQ, $id));
	}

	// ------------------------------------------------------------- reading

	/**
	 * The indexed release a site on $version reads: its own when indexed, else
	 * the newest indexed release not newer than it, else the oldest indexed.
	 * Returns [release, exact] or [null, false] when nothing is indexed.
	 */
	public static function releaseFor(string $version): array {
		$all = SupportDocRelease::releases();
		if (!$all) {
			return array(null, false);
		}
		if (in_array($version, $all, true)) {
			return array($version, true);
		}
		foreach ($all as $r) {           // newest first
			if (self::isVersion($version) && version_compare($r, $version, '<=')) {
				return array($r, false);
			}
		}
		return array(end($all), false);
	}

	/**
	 * Search a release's rows and the `current` rows. Every word must match;
	 * when none does, any word may. Returns rows of
	 * [path, heading, part, kind, snippet].
	 */
	public static function search(string $release, string $query, int $limit = self::SEARCH_LIMIT): array {
		if (!FullTextSearch::isSearchable($query)) {
			return array();
		}
		$limit = max(1, min(20, $limit));
		$rows = self::runSearch($release, $query, $limit);
		if (!$rows) {
			$terms = FullTextSearch::terms($query);
			if (count($terms) > 1) {
				$rows = self::runSearch($release, implode(' or ', $terms), $limit);
			}
		}
		$out = array();
		foreach ($rows as $r) {
			$out[] = array(
				'path'    => $r['sds_path'],
				'heading' => $r['sds_heading'],
				'part'    => (int)$r['sds_part'],
				'kind'    => $r['sds_kind'],
				'snippet' => html_entity_decode(strip_tags(FullTextSearch::snippet((string)$r['sds_body'], $query, self::SNIPPET_LENGTH)), ENT_QUOTES, 'UTF-8'),
			);
		}
		return $out;
	}

	private static function runSearch(string $release, string $query, int $limit): array {
		list($match_sql, $match_params) = FullTextSearch::match('sds_search_text', $query);
		// The score divides by the log of the section's length (ts_rank
		// normalization 1), so a long section that repeats the words does not
		// bury a short one that is about them. A question matches a question
		// better than a reference heading, and a FAQ answer is the content most
		// likely to answer outright: FAQ rows count double.
		$rank_sql = 'ts_rank(' . FullTextSearch::expression('sds_search_text') . ", websearch_to_tsquery('"
			. FullTextSearch::CONFIG . "', ?), 1)";
		$rank_params = $match_params;
		$sql = 'SELECT sds_path, sds_heading, sds_part, sds_kind, sds_body,
				' . $rank_sql . " * CASE WHEN sds_kind IN ('faq', 'local_faq') THEN 2 ELSE 1 END AS score
			FROM sds_doc_sections
			WHERE sds_release IN (?, ?) AND " . $match_sql . '
			ORDER BY score DESC, sds_doc_section_id ASC
			LIMIT ' . (int)$limit;
		$stmt = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$stmt->execute(array_merge($rank_params, array($release, SupportDocSection::CURRENT), $match_params));
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * One section by path and heading: the parts of the section whose heading
	 * chain is $heading, or whose last heading is, joined in order and capped.
	 * Null when the path has no such section.
	 */
	public static function readSection(string $release, string $path, string $heading): ?array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT sds_heading, sds_part, sds_body, sds_kind, sds_ref_id FROM sds_doc_sections
			WHERE sds_release IN (?, ?) AND sds_path = ? ORDER BY sds_doc_section_id ASC');
		$stmt->execute(array($release, SupportDocSection::CURRENT, $path));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		if (!$rows) {
			return null;
		}
		$want = mb_strtolower(trim($heading));
		$picked = array();
		foreach ($rows as $r) {
			$chain = mb_strtolower((string)$r['sds_heading']);
			$parts = explode(' > ', $chain);
			if ($want === '' || $chain === $want || end($parts) === $want) {
				$picked[] = $r;
			}
		}
		if (!$picked) {
			return array('found' => false, 'headings' => array_values(array_unique(array_column($rows, 'sds_heading'))));
		}
		$by_heading = array();
		foreach ($picked as $r) {
			$by_heading[$r['sds_heading']][] = $r['sds_body'];
		}
		$text = '';
		foreach ($by_heading as $h => $bodies) {
			$text .= ($text !== '' ? "\n\n" : '') . '## ' . $h . "\n\n" . implode("\n\n", $bodies);
		}
		$truncated = mb_strlen($text) > self::READ_CAP;
		return array(
			'found'     => true,
			'text'      => $truncated ? mb_substr($text, 0, self::READ_CAP) : $text,
			'truncated' => $truncated,
			'kind'      => $picked[0]['sds_kind'],
			'ref_id'    => $picked[0]['sds_ref_id'] !== null ? (int)$picked[0]['sds_ref_id'] : null,
		);
	}

	/** The docs map of a release ('' when the release is not indexed). */
	public static function mapFor(string $release): string {
		$row = SupportDocRelease::forRelease($release);
		return $row ? (string)$row->get('sdr_map') : '';
	}

	/** The shipped FAQ of a release, whole ('' when it has none). */
	public static function shippedFaqFor(string $release): string {
		$row = SupportDocRelease::forRelease($release);
		return $row ? (string)$row->get('sdr_faq') : '';
	}

	/** The operator's local FAQ, whole, oldest first, as the fixed prefix carries it. */
	public static function localFaq(): string {
		$out = array();
		foreach (new MultiSupportFaqEntry(array('deleted' => false), array('sfq_faq_entry_id' => 'ASC')) as $e) {
			$block = '### ' . $e->get('sfq_question') . "\n(" . $e->indexPath()
				. ($e->get('sfq_release') ? ', written on release ' . $e->get('sfq_release') : '') . ")\n"
				. trim((string)$e->get('sfq_answer'));
			$out[] = $block;
		}
		return implode("\n\n", $out);
	}
}
