<?php
/**
 * PackageInstallPage — the upload-to-install flow the Plugins and Themes
 * pages share (specs/package_signing.md WP6).
 *
 * An uploaded package is unpacked and checked by the web user
 * (AbstractExtensionManager::stage()), then handed to root as an
 * install_package request. Root verifies it against the release key: a
 * package we built installs with no ceremony, and the operator watches the
 * request panel say Done. Anything else fails the request with exit 3
 * (RootRequest::EXIT_UNVERIFIED) and the page shows THE WARNING — the
 * owner's words: extremely dangerous, everything on the site including all
 * mail — with the package's name, version, author and file count, the
 * verdict root reached, and one button, Install anyway.
 *
 * That button is a POST behind the second-factor step-up
 * (SessionControl::require_recent_second_factor). Once confirmed, the page
 * mints the acknowledgement (PackageAcknowledgement::mint) and submits
 * install_package again with it; root checks the acknowledgement and installs
 * under the unsigned restrictions. The page never touches the tree, never
 * decides the verdict, and never says installed: the request panel does.
 *
 * REPLACING AN INSTALLED PACKAGE (specs/package_replace_on_upload.md). When
 * the uploaded name is already on disk, nothing is queued: the staged
 * directory stays where it is and the page shows the REPLACE PANEL, the
 * installed copy and the uploaded one side by side, with Replace and
 * Discard. Replace queues the request with `replace: true`, which the
 * dispatcher turns into the installer's --replace: the installed copy is set
 * aside (kept beside the new one) only after the verdict and, for an
 * unsigned page theme or plugin, the acknowledgement — the warning path is
 * exactly as long as for a fresh install. A system extension is refused
 * here: every deploy replaces it with the shipped version, so a replacement
 * would last until the next one.
 *
 * Everything the warning page and the replace panel show about the package
 * is read from the request or the staged directory it names — never from
 * the URL, which carries only a request id or a staged directory name that
 * is checked against staging.
 *
 * @version 1.3 - the warning is the verdict's: an unlogged package gets the unlogged words
 * @version 1.2 - "This plugin is active" reads plg_active (Plugin::is_active()), not plg_status
 * @version 1.1 - the replace panel: upload() returns a pending outcome for an
 *                installed name, pending()/replace_html()/confirmReplace()/
 *                discard(), the acknowledgement carries `replace` through,
 *                a system extension is refused, and staging directories
 *                older than a day are swept on upload
 *                (specs/package_replace_on_upload.md WP2)
 * @version 1.0
 */
class PackageInstallPage {

	/** The form whose token the Install anyway button carries. */
	const FORM_ID = 'package_install_anyway';

	/** The form whose token the Replace and Discard buttons carry. */
	const REPLACE_FORM_ID = 'package_replace';

	/** A staged upload nobody confirmed or discarded is removed after this long. */
	const STAGING_MAX_AGE = 86400;

	/**
	 * Stage an upload and either ask root for its verdict or, when the name is
	 * already installed, hold it for the replace panel.
	 *
	 * @param string $type     'plugin' | 'theme'
	 * @param string $tmp_file The uploaded ZIP ($_FILES[...]['tmp_name'])
	 * @param int    $user_id  Who asked
	 * @return array{outcome:string, name:string, request_id?:string, staged_dir?:string}
	 *         outcome 'queued' carries request_id; 'pending_replace' carries staged_dir
	 * @throws Exception when the archive is not one we will stage, or names a system extension
	 */
	public static function upload(string $type, string $tmp_file, int $user_id): array {
		self::sweepStaging();
		$manager = $type === 'theme' ? new ThemeManager() : new PluginManager();
		$staged = $manager->stage($tmp_file);
		$name = (string)$staged['name'];
		$staged_dir = self::stagedRelative($staged['dir']);

		if (is_dir(self::livePath($type, $name))) {
			if ($manager->isSystemExtension($name)) {
				self::discard($type, $staged_dir);
				throw new Exception("'$name' is a system $type. Every deploy replaces it with the version Joinery ships, "
					. 'so it cannot be replaced or forked here. Nothing was changed.');
			}
			return array('outcome' => 'pending_replace', 'name' => $name, 'staged_dir' => $staged_dir);
		}

		$request_id = RootRequest::submit(RootRequest::PACKAGE_KIND, array(
			'type'       => $type,
			'staged_dir' => $staged_dir,
		), $user_id);
		return array('outcome' => 'queued', 'request_id' => $request_id, 'name' => $name);
	}

	/**
	 * What the replace panel shows for a staged upload whose name is
	 * installed, or null with the reason in $why: the staged directory is
	 * gone, is not a package of this type, or nothing is installed under its
	 * name any more (then it is a plain install: upload it again).
	 *
	 * @return array{type:string, name:string, staged_dir:string, installed:array, uploaded:array,
	 *               older:bool, in_use:string, becomes_fork:bool}|null
	 */
	public static function pending(string $type, string $staged_dir, string &$why = ''): ?array {
		$why = '';
		$dir = self::stagedAbsolute($staged_dir);
		if ($dir === null) {
			$why = 'The staged package is no longer there; upload it again.';
			return null;
		}
		$manifest_name = $type === 'theme' ? 'theme.json' : 'plugin.json';
		$uploaded_manifest = self::manifest($dir . '/' . $manifest_name);
		if ($uploaded_manifest === null) {
			$why = 'The staged directory holds no ' . $manifest_name . '; upload the package again.';
			return null;
		}
		$name = basename($dir);
		$live = self::livePath($type, $name);
		if (!is_dir($live)) {
			$why = "Nothing is installed under the name '$name' now; upload the package again to install it.";
			return null;
		}
		$installed_manifest = self::manifest($live . '/' . $manifest_name) ?? array();

		$facts = function (string $from, array $manifest) use ($type): array {
			$f = array(
				'version' => (string)($manifest['version'] ?? ''),
				'author'  => (string)($manifest['author'] ?? ''),
				'files'   => self::countFiles($from),
			);
			if ($type === 'theme') {
				$f['kind'] = ThemeHelper::styleThemeRefusal($from) === null ? 'Styling' : 'Pages';
			}
			return $f;
		};
		$installed = $facts($live, $installed_manifest);
		$uploaded = $facts($dir, $uploaded_manifest);

		$older = $installed['version'] !== '' && $uploaded['version'] !== ''
			&& version_compare($uploaded['version'], $installed['version'], '<');

		// Whether the site is using the installed copy right now.
		$in_use = '';
		if ($type === 'theme') {
			$settings = Globalvars::get_instance();
			if ((string)$settings->get_setting('theme_template', true, true) === $name) {
				$in_use = 'This is the active page theme.';
			} elseif ((string)$settings->get_setting('theme_look', true, true) === $name) {
				$in_use = 'This is the applied look.';
			}
		} else {
			$row = Plugin::get_by_plugin_name($name);
			if ($row && $row->is_active()) {
				$in_use = 'This plugin is active.';
			}
		}

		$manager = $type === 'theme' ? new ThemeManager() : new PluginManager();
		return array(
			'type'         => $type,
			'name'         => $name,
			'staged_dir'   => $staged_dir,
			'installed'    => $installed,
			'uploaded'     => $uploaded,
			'older'        => $older,
			'in_use'       => $in_use,
			// An uploaded package is a local fork; this says whether that is new.
			'becomes_fork' => !$manager->isLocalFork($name),
		);
	}

	/**
	 * The Replace POST: queue the request with `replace: true`. The verdict
	 * and the acknowledgement are still root's and the warning page's.
	 *
	 * @return string The request id
	 * @throws Exception with the sentence for the operator when the staged package cannot be replaced
	 */
	public static function confirmReplace(string $type, string $staged_dir, int $user_id): string {
		$why = '';
		if (self::pending($type, $staged_dir, $why) === null) {
			throw new Exception($why);
		}
		return RootRequest::submit(RootRequest::PACKAGE_KIND, self::replaceArgs($type, $staged_dir), $user_id);
	}

	/** The install_package arguments a confirmed replacement carries. */
	public static function replaceArgs(string $type, string $staged_dir): array {
		return array(
			'type'       => $type,
			'staged_dir' => $staged_dir,
			'replace'    => true,
		);
	}

	/**
	 * The Discard POST: remove the staged upload, wrapper directory and all.
	 *
	 * @return string The package name that was discarded
	 * @throws Exception when the staged directory is not a live one under staging
	 */
	public static function discard(string $type, string $staged_dir): string {
		$dir = self::stagedAbsolute($staged_dir);
		if ($dir === null) {
			throw new Exception('The staged package is no longer there.');
		}
		$name = basename($dir);
		// <staging id>/<name>: the wrapper the stage() made goes with it.
		self::rmtree(dirname($dir));
		return $name;
	}

	/**
	 * Remove staged uploads older than STAGING_MAX_AGE. Staging belongs to
	 * the web user, so this needs no root request. Returns how many went.
	 */
	public static function sweepStaging(): int {
		$root = PathHelper::getSiteRoot() . '/uploads/staging';
		if (!is_dir($root)) {
			return 0;
		}
		$swept = 0;
		foreach (scandir($root) ?: array() as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $root . '/' . $entry;
			if (!is_dir($path) || is_link($path)) {
				continue;
			}
			if (time() - (int)@filemtime($path) < self::STAGING_MAX_AGE) {
				continue;
			}
			self::rmtree($path);
			$swept++;
		}
		return $swept;
	}

	/**
	 * What the warning page shows for a refused request, or null with the
	 * reason in $why when the request is not one this operator can answer:
	 * not an install_package of this type, not refused as unverified, not
	 * theirs, or its staged directory is gone.
	 *
	 * @return array{request_id:string, type:string, name:string, version:string, author:string,
	 *               file_count:int, verdict:string, staged_dir:string, replace:bool, warning:string}|null
	 */
	public static function refused(string $type, string $request_id, SessionControl $session, string &$why = ''): ?array {
		$why = '';
		$status = RootRequest::status($request_id);
		$request = RootRequest::request($request_id);
		if ($status['state'] !== 'failed' || $request === null || ($request['kind'] ?? '') !== RootRequest::PACKAGE_KIND) {
			$why = 'That request is not a refused package install.';
			return null;
		}
		if ((int)$status['exit_code'] !== RootRequest::EXIT_UNVERIFIED) {
			$why = 'That install failed for a reason other than verification; see its transcript.';
			return null;
		}
		$args = is_array($request['args'] ?? null) ? $request['args'] : array();
		if (($args['type'] ?? '') !== $type) {
			$why = 'That request is not a ' . $type . ' install.';
			return null;
		}
		if ((int)($request['requested_by'] ?? 0) !== (int)$session->get_user_id()) {
			$why = 'Only the superadmin who uploaded a package can answer the warning for it.';
			return null;
		}
		if (isset($args['unsigned_ack'])) {
			$why = 'That request already carried an acknowledgement; upload the package again.';
			return null;
		}
		$staged_dir = (string)($args['staged_dir'] ?? '');
		$dir = self::stagedAbsolute($staged_dir);
		if ($dir === null) {
			$why = 'The staged package is no longer there; upload it again.';
			return null;
		}

		$manifest = self::manifest($dir . '/' . ($type === 'theme' ? 'theme.json' : 'plugin.json')) ?? array();
		$verdict = '';
		foreach (preg_split('/\r\n|\r|\n/', RootRequest::transcript($request_id)) as $line) {
			if (strpos($line, 'verdict: ') === 0) {
				$verdict = substr($line, strlen('verdict: '));
			}
		}
		return array(
			'request_id' => $request_id,
			'type'       => $type,
			'name'       => basename($dir),
			'version'    => (string)($manifest['version'] ?? ''),
			'author'     => (string)($manifest['author'] ?? ''),
			'file_count' => self::countFiles($dir),
			'verdict'    => $verdict,
			'staged_dir' => $staged_dir,
			// A refused replacement is still a replacement once acknowledged.
			'replace'    => (($args['replace'] ?? false) === true),
			'warning'    => PackageAcknowledgement::warning($verdict),
		);
	}

	/**
	 * The Install anyway POST. Returns a LogicResult redirect when the
	 * second factor has to be confirmed first (the button is pressed again
	 * on return), or the new request's id once the acknowledged request is
	 * queued. A request that was a replacement stays one.
	 *
	 * @param string $return_url Where the step-up ceremony returns to — this page, with the warning showing
	 * @return LogicResult|string
	 * @throws Exception with the sentence for the operator when the acknowledgement cannot be minted
	 */
	public static function acknowledge(string $type, string $request_id, SessionControl $session, string $return_url) {
		$why = '';
		$refused = self::refused($type, $request_id, $session, $why);
		if ($refused === null) {
			throw new Exception($why);
		}
		$stepup = $session->require_recent_second_factor($return_url, PackageAcknowledgement::STEP_UP_TTL);
		if ($stepup) {
			return $stepup;
		}
		$ack = PackageAcknowledgement::mint($session);
		$args = array(
			'type'         => $type,
			'staged_dir'   => $refused['staged_dir'],
			'unsigned_ack' => $ack,
		);
		if ($refused['replace']) {
			$args['replace'] = true;
		}
		return RootRequest::submit(RootRequest::PACKAGE_KIND, $args, (int)$session->get_user_id());
	}

	/** The staged directory as the request names it: <staging id>/<name>, relative to uploads/staging. */
	public static function stagedRelative(string $dir): string {
		$root = PathHelper::getSiteRoot() . '/uploads/staging/';
		if (strpos($dir, $root) !== 0) {
			throw new Exception('The staged directory is not under uploads/staging');
		}
		return substr($dir, strlen($root));
	}

	/** The absolute staged directory for a request's staged_dir, or null when it is not a live one under staging. */
	public static function stagedAbsolute(string $staged_dir): ?string {
		if (preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_-]*$~', $staged_dir) !== 1) {
			return null;
		}
		$root = realpath(PathHelper::getSiteRoot() . '/uploads/staging');
		$dir = realpath(PathHelper::getSiteRoot() . '/uploads/staging/' . $staged_dir);
		if ($root === false || $dir === false || strpos($dir, $root . '/') !== 0 || !is_dir($dir)) {
			return null;
		}
		return $dir;
	}

	/** Where an installed extension of this type and name lives in the tree. */
	private static function livePath(string $type, string $name): string {
		return PathHelper::getAbsolutePath(($type === 'theme' ? 'theme/' : 'plugins/') . $name);
	}

	/** A manifest file decoded, or null when it is missing or not JSON. */
	private static function manifest(string $path): ?array {
		if (!is_file($path)) {
			return null;
		}
		$decoded = json_decode((string)@file_get_contents($path), true);
		return is_array($decoded) ? $decoded : null;
	}

	private static function countFiles(string $dir): int {
		$n = 0;
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY);
		foreach ($it as $f) {
			if ($f->isFile()) {
				$n++;
			}
		}
		return $n;
	}

	private static function rmtree(string $path): void {
		if (is_link($path) || !is_dir($path)) {
			@unlink($path);
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $item) {
			$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($path);
	}

	/**
	 * The warning block, for a page's view: the owner's words, the package's
	 * facts, the verdict, and the one button. FormWriter mints the token;
	 * the button is the single-button action form that carries it.
	 *
	 * @param array  $refused From refused()
	 * @param string $post_url Where the button posts (this page)
	 * @param object $page     The AdminPage, for its FormWriter
	 */
	public static function warning_html(array $refused, string $post_url, $page): string {
		$formwriter = $page->getFormWriter(self::FORM_ID);
		$token = (string)$formwriter->getCSRFToken();
		$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
		$facts = array(
			'Name'    => $refused['name'],
			'Version' => $refused['version'] !== '' ? $refused['version'] : 'not stated',
			'Author'  => $refused['author'] !== '' ? $refused['author'] : 'not stated',
			'Files'   => (string)$refused['file_count'],
			'Verdict' => $refused['verdict'] !== '' ? $refused['verdict'] : 'did not verify',
		);
		$rows = '';
		foreach ($facts as $label => $value) {
			$rows .= '<tr><th>' . $e($label) . '</th><td>' . $e($value) . '</td></tr>';
		}
		$replacing = !empty($refused['replace']);
		$button = AdminPage::action_button($replacing ? 'Replace anyway' : 'Install anyway', $post_url, array(
			'hidden' => array(
				'action'      => 'install_anyway',
				'_csrf_token' => $token,
				'request'     => $refused['request_id'],
			),
			'confirm' => ($replacing ? 'Replace the installed ' . $refused['type'] . ' with this unsigned one?'
				: 'Install this unsigned ' . $refused['type'] . '?') . ' It will have access to everything on this site.',
			'class'   => 'btn btn-danger',
		));
		return '<div class="jy-unsigned-warning" role="alert">'
			. '<style>'
			. '.jy-unsigned-warning{margin:1rem 0;padding:1rem 1.2rem;border:2px solid #dc2626;border-radius:6px;background:#fef2f2;color:#7f1d1d}'
			. '.jy-unsigned-warning h4{margin:0 0 .5rem;color:#991b1b}'
			. '.jy-unsigned-warning table{margin:.75rem 0;border-collapse:collapse}'
			. '.jy-unsigned-warning th{text-align:left;padding:.15rem .8rem .15rem 0;font-weight:600}'
			. '.jy-unsigned-warning td{padding:.15rem 0}'
			. '.jy-unsigned-warning__second{font-size:.9rem;color:#7f1d1d;margin:.5rem 0 .75rem}'
			. '</style>'
			. '<h4>This ' . $e($refused['type']) . ' was not built by Joinery</h4>'
			. '<p>' . $e($refused['warning']) . '</p>'
			. '<table>' . $rows . '</table>'
			. ($replacing ? '<p class="jy-unsigned-warning__second">It replaces the installed copy of '
				. $e($refused['name']) . '; the previous copy is kept beside it.</p>' : '')
			. '<p class="jy-unsigned-warning__second">Installing it needs your second-factor confirmation. '
			. 'Its database migrations run as the web server user rather than root, every superadmin is emailed, '
			. 'and it is marked Unsigned on this page for as long as it is installed.</p>'
			. $button
			. '</div>';
	}

	/**
	 * The replace panel, for a page's view: the installed copy and the
	 * uploaded one side by side, what is in use, and Replace and Discard.
	 * FormWriter mints the token; both buttons are single-button action forms
	 * that carry it and the staged directory.
	 *
	 * @param array  $pending  From pending()
	 * @param string $post_url Where the buttons post (this page)
	 * @param object $page     The AdminPage, for its FormWriter
	 */
	public static function replace_html(array $pending, string $post_url, $page): string {
		$formwriter = $page->getFormWriter(self::REPLACE_FORM_ID);
		$token = (string)$formwriter->getCSRFToken();
		$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
		$said = function ($v) { return $v !== '' ? $v : 'not stated'; };
		$type = $pending['type'];
		$name = $pending['name'];

		$rows = '<tr><th></th><th>Installed</th><th>Uploaded</th></tr>'
			. '<tr><th>Version</th><td>' . $e($said($pending['installed']['version'])) . '</td><td>' . $e($said($pending['uploaded']['version'])) . '</td></tr>'
			. '<tr><th>Author</th><td>' . $e($said($pending['installed']['author'])) . '</td><td>' . $e($said($pending['uploaded']['author'])) . '</td></tr>'
			. '<tr><th>Files</th><td>' . (int)$pending['installed']['files'] . '</td><td>' . (int)$pending['uploaded']['files'] . '</td></tr>';
		if ($type === 'theme') {
			$rows .= '<tr><th>Kind</th><td>' . $e($pending['installed']['kind']) . '</td><td>' . $e($pending['uploaded']['kind']) . '</td></tr>';
		}

		$notes = '';
		if ($pending['older']) {
			$notes .= '<p class="jy-replace-panel__note">This is older than what is installed.</p>';
		}
		if ($pending['in_use'] !== '') {
			$notes .= '<p class="jy-replace-panel__note">' . $e($pending['in_use'])
				. ' The site shows the new files as soon as they are in place.</p>';
		}
		if ($pending['becomes_fork']) {
			$notes .= '<p class="jy-replace-panel__note">After this, ' . $e($name) . ' is a local fork: deploys leave it alone, '
				. 'so it gets no updates from Joinery, including security fixes, until Allow upgrade is pressed.</p>';
		}

		$hidden = array(
			'_csrf_token' => $token,
			'staged_dir'  => $pending['staged_dir'],
		);
		$replace = AdminPage::action_button('Replace', $post_url, array(
			'hidden'  => array('action' => 'replace_staged') + $hidden,
			'confirm' => 'Replace the installed ' . $type . ' ' . $name . ' with the uploaded one? The previous copy is kept beside it.',
			'confirm_label' => 'Replace',
			'class'   => 'btn btn-primary',
		));
		$discard = AdminPage::action_button('Discard', $post_url, array(
			'hidden' => array('action' => 'discard_staged') + $hidden,
			'class'  => 'btn btn-soft-default',
		));

		return '<div class="jy-replace-panel" role="status">'
			. '<style>'
			. '.jy-replace-panel{margin:1rem 0;padding:1rem 1.2rem;border:2px solid #2563eb;border-radius:6px;background:#eff6ff;color:#1e3a8a}'
			. '.jy-replace-panel h4{margin:0 0 .5rem;color:#1e40af}'
			. '.jy-replace-panel table{margin:.75rem 0;border-collapse:collapse}'
			. '.jy-replace-panel th{text-align:left;padding:.15rem 1.2rem .15rem 0;font-weight:600}'
			. '.jy-replace-panel td{padding:.15rem 1.2rem .15rem 0}'
			. '.jy-replace-panel__note{font-size:.9rem;margin:.4rem 0}'
			. '.jy-replace-panel__buttons form{margin-right:.5rem}'
			. '</style>'
			. '<h4>' . $e(ucfirst($type)) . ' ' . $e($name) . ' is already installed</h4>'
			. '<p>Replace it with the uploaded one, or discard the upload. Nothing has been changed yet; '
			. 'root still verifies the package before it goes in, exactly as for a new install.</p>'
			. '<table>' . $rows . '</table>'
			. $notes
			. '<div class="jy-replace-panel__buttons">' . $replace . ' ' . $discard . '</div>'
			. '</div>';
	}
}
