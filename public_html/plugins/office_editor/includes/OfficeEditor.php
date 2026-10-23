<?php
/**
 * OfficeEditor — what the document editor can open, for whom, and how to reach
 * the editor service on this server.
 *
 * The editor (a LibreOffice-based online editor installed by
 * provisioning/install_office_editor.sh) listens on loopback only. The browser
 * reaches it through Apache at /browser and /cool; the editor reaches the site
 * through a loopback-only listener (WOPI_BASE) that only it can connect to
 * (OfficeEditorWopi).
 *
 * Three things decide whether a file opens in the editor:
 *   - the site: the installer finished here (installed()),
 *   - the member: their tier carries `office_editing`,
 *   - the file: one of EXTENSIONS, a Drive file at the Standard level. A Private
 *     or Fortress file would put its plaintext in the editor's memory and temp
 *     files, outside the promise those levels make.
 *
 * @version 0.1.0
 */
class OfficeEditor {

	/** What the editor opens. */
	const EXTENSIONS = array('docx', 'xlsx', 'odt', 'ods');

	/** The editor service, loopback only. */
	const COOL_URL = 'http://127.0.0.1:9980';

	/** Where the editor reaches the site: the loopback listener the installer adds. */
	const WOPI_BASE = 'http://127.0.0.1:9981';

	/** Written by the installer when it finished, read here to know the editor exists. */
	const INSTALLED_MARK = '/etc/joinery/office_editor/installed';

	/** The one command the web user may run as root (sudoers, installer step 6). */
	const CTL = '/usr/local/sbin/office-editor-ctl';

	/** A parsed discovery the suite stands in for the service with. */
	public static $discovery_override = null;

	/** Memo of per-member tier answers within a request. */
	private static $member_memo = array();

	/** Did the installer finish on this server? */
	public static function installed() {
		return is_file(self::INSTALLED_MARK) && is_file(self::CTL);
	}

	/** May $user_id use the editor (their tier)? */
	public static function memberMay($user_id) {
		$user_id = (int)$user_id;
		if ($user_id <= 0) {
			return false;
		}
		if (!isset(self::$member_memo[$user_id])) {
			self::$member_memo[$user_id] = (bool)SubscriptionTier::getUserFeature($user_id, 'office_editing', false);
		}
		return self::$member_memo[$user_id];
	}

	/** Forget memoized tier answers (tests that change a member's tier). */
	public static function forgetMembers() {
		self::$member_memo = array();
	}

	/** Is $file one the editor opens, by its name? */
	public static function opensExtension($title) {
		return in_array(strtolower(pathinfo((string)$title, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
	}

	/** Is $file at the Standard level, counting the folder it stands in? */
	public static function isStandard($file) {
		return DriveHelper::effective_file_level($file) === ProtectionLevel::STANDARD;
	}

	/** The editor page for $file. */
	public static function pageUrl($file_id) {
		return '/profile/office_editor/edit?file=' . (int)$file_id;
	}

	// ------------------------------------------------------------------
	// The Drive hook (registered from includes/bootstrap.php)
	// ------------------------------------------------------------------

	/** DriveHelper opener `url`: the editor page, or null when it cannot open now. */
	public static function openerUrl($file, $user_id) {
		if (!self::installed() || !self::memberMay($user_id) || !self::isStandard($file)) {
			return null;
		}
		return self::pageUrl($file->key);
	}

	/** DriveHelper opener `explain`: why it cannot, when the member should know. */
	public static function openerExplain($file, $user_id) {
		if (!self::installed()) {
			return null;
		}
		if (!self::isStandard($file)) {
			return 'Private and Fortress files can\'t be opened on the server. Download to edit.';
		}
		if (!self::memberMay($user_id)) {
			return 'Editing documents in Drive isn\'t part of your plan. Download to edit.';
		}
		return null;
	}

	/** DriveHelper creator `available`: true, or why not here. */
	public static function creatorAvailable($folder, $user_id) {
		if (!self::installed() || !self::memberMay($user_id)) {
			return null;
		}
		if ($folder !== null && DriveHelper::folder_level($folder) !== ProtectionLevel::STANDARD) {
			return 'New documents can be made in Standard folders only.';
		}
		if ($folder !== null && !DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id)) {
			return 'You can view this folder but not add to it.';
		}
		return true;
	}

	// ------------------------------------------------------------------
	// The editor service
	// ------------------------------------------------------------------

	/**
	 * The editor's discovery document, or null when the service does not answer
	 * (stopped, or still starting). Read straight from the loopback listener.
	 */
	public static function fetchDiscovery($timeout = 2) {
		$ch = curl_init(self::COOL_URL . '/hosting/discovery');
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT        => $timeout + 2,
			CURLOPT_FOLLOWLOCATION => false,
		));
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($code !== 200 || !is_string($body) || strpos($body, '<wopi-discovery') === false) {
			return null;
		}
		return $body;
	}

	/**
	 * What the site needs from discovery: the editor page path for each
	 * extension, and the proof key. Read with patterns, not an XML parser: the
	 * document is the local service's, and the few attributes wanted are plain.
	 *
	 * @return array{actions: array<string,string>, proof: array{modulus:string,exponent:string}|null}
	 */
	public static function parseDiscovery($xml) {
		$actions = array();
		if (preg_match_all('/<action\b[^>]*>/i', (string)$xml, $m)) {
			foreach ($m[0] as $tag) {
				$a = self::attrs($tag);
				$ext = strtolower($a['ext'] ?? '');
				$name = $a['name'] ?? '';
				if ($ext === '' || !in_array($ext, self::EXTENSIONS, true) || empty($a['urlsrc'])) {
					continue;
				}
				// 'edit' wins over 'view' for the same extension.
				if ($name === 'edit' || !isset($actions[$ext])) {
					$actions[$ext] = html_entity_decode($a['urlsrc'], ENT_QUOTES | ENT_XML1);
				}
			}
		}
		$proof = null;
		if (preg_match('/<proof-key\b[^>]*>/i', (string)$xml, $pm)) {
			$a = self::attrs($pm[0]);
			if (!empty($a['modulus']) && !empty($a['exponent'])) {
				$proof = array('modulus' => $a['modulus'], 'exponent' => $a['exponent']);
				if (!empty($a['oldmodulus']) && !empty($a['oldexponent'])) {
					$proof['oldmodulus'] = $a['oldmodulus'];
					$proof['oldexponent'] = $a['oldexponent'];
				}
			}
		}
		return array('actions' => $actions, 'proof' => $proof);
	}

	private static function attrs($tag) {
		$out = array();
		if (preg_match_all('/([A-Za-z_:-]+)="([^"]*)"/', $tag, $m, PREG_SET_ORDER)) {
			foreach ($m as $pair) {
				$out[$pair[1]] = $pair[2];
			}
		}
		return $out;
	}

	/** Where the parsed discovery is kept between requests. */
	private static function cachePath() {
		return PathHelper::getSiteRoot() . '/cache/office_editor/discovery.json';
	}

	/**
	 * Discovery, from the cache when it is fresh, else from the service (and
	 * cached). $refresh skips the cache: the proof check asks again once when a
	 * signature fails, in case the service was reinstalled with a new key.
	 */
	public static function discovery($refresh = false) {
		if (self::$discovery_override !== null) {
			return self::$discovery_override;
		}
		$path = self::cachePath();
		if (!$refresh && is_file($path) && (time() - (int)@filemtime($path)) < 3600) {
			$cached = json_decode((string)@file_get_contents($path), true);
			if (is_array($cached) && !empty($cached['actions'])) {
				return $cached;
			}
		}
		$xml = self::fetchDiscovery();
		if ($xml === null) {
			return null;
		}
		$parsed = self::parseDiscovery($xml);
		if (!empty($parsed['actions'])) {
			$dir = dirname($path);
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}
			$tmp = $path . '.' . getmypid();
			if (@file_put_contents($tmp, json_encode($parsed)) !== false) {
				@rename($tmp, $path);
			}
		}
		return $parsed;
	}

	/**
	 * The browser's editor URL for $file: the service's page path, served
	 * through this site's own origin, with WOPISrc pointing the service back at
	 * the loopback listener.
	 */
	public static function editorUrl(array $discovery, $file) {
		$ext = strtolower(pathinfo((string)$file->get('fil_title'), PATHINFO_EXTENSION));
		$src = $discovery['actions'][$ext] ?? null;
		if ($src === null) {
			return null;
		}
		$parts = parse_url($src);
		$path = $parts['path'] ?? '';
		if ($path === '' || strpos($path, '/browser/') !== 0) {
			return null;
		}
		$query = isset($parts['query']) ? rtrim($parts['query'], '&') : '';
		// The urlsrc may carry <placeholder> option pairs; none are used here.
		$query = preg_replace('/<[^>]*>/', '', $query);
		$query = trim($query, '&');
		$wopi = self::WOPI_BASE . '/office_editor/wopi/files/' . (int)$file->key;
		$query = ($query !== '' ? $query . '&' : '') . 'WOPISrc=' . rawurlencode($wopi);
		return $path . '?' . $query;
	}

	/**
	 * Most documents open at once, and connections at once. The free edition's
	 * home mode counts these too, but does not refuse past them: it puts an
	 * "unsupported version" banner in front of everyone using it once more than
	 * 10 documents are open or 20 connections are (spec § 6.5). So the site
	 * holds the line itself, one short of the banner, and says the editor is
	 * busy.
	 */
	const MAX_DOCUMENTS = 10;
	const MAX_CONNECTIONS = 20;

	/**
	 * What the editor has open, from its own metrics (loopback only; the
	 * installer turns them on and Apache refuses them from outside): the
	 * documents, as Drive file ids, and how many people are connected. Null
	 * when the service does not answer.
	 *
	 * @return array{documents:int, file_ids:int[], connections:int}|null
	 */
	public static function load($timeout = 2) {
		$ch = curl_init(self::COOL_URL . '/cool/getMetrics');
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT        => $timeout + 2,
		));
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($code !== 200 || !is_string($body)) {
			return null;
		}
		return self::parseMetrics($body);
	}

	/** @return array{documents:int, file_ids:int[], connections:int} */
	public static function parseMetrics($text) {
		$out = array('documents' => 0, 'file_ids' => array(), 'connections' => 0);
		if (preg_match('/^kit_assigned_count (\d+)$/m', $text, $m)) {
			$out['documents'] = (int)$m[1];
		}
		if (preg_match('/^document_active_views_all_count_total (\d+)$/m', $text, $m)) {
			$out['connections'] = (int)$m[1];
		}
		if (preg_match_all('/^doc_info\{[^}]*key="([^"]*)"/m', $text, $mm)) {
			foreach ($mm[1] as $key) {
				if (preg_match('#/office_editor/wopi/files/(\d+)#', rawurldecode($key), $fm)) {
					$out['file_ids'][] = (int)$fm[1];
				}
			}
		}
		return $out;
	}

	/**
	 * Would opening $file_id now take the editor past its limits? Joining a
	 * document already open adds a connection, not a document.
	 */
	public static function wouldOverload(array $load, $file_id) {
		if ($load['connections'] + 1 >= self::MAX_CONNECTIONS) {
			return true;
		}
		$already = in_array((int)$file_id, $load['file_ids'], true);
		return !$already && $load['documents'] + 1 > self::MAX_DOCUMENTS;
	}

	/**
	 * Ask root to start the service (sudoers lets the web user run exactly
	 * `office-editor-ctl start` and `status`). It returns at once; the caller
	 * polls discovery.
	 */
	public static function requestStart() {
		if (!self::installed()) {
			return false;
		}
		$out = array();
		$rc = 1;
		@exec('sudo -n ' . escapeshellarg(self::CTL) . ' start 2>&1', $out, $rc);
		if ($rc !== 0) {
			error_log('[office_editor] start refused (' . $rc . '): ' . implode(' | ', $out));
		}
		return $rc === 0;
	}
}
