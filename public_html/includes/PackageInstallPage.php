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
 * Everything the warning page shows about the package is read from the
 * refused request and the staged directory it names — never from the URL,
 * which carries only a request id.
 *
 * @version 1.0
 */
class PackageInstallPage {

	/** The form whose token the Install anyway button carries. */
	const FORM_ID = 'package_install_anyway';

	/**
	 * Stage an upload and ask root for its verdict. Returns the request id.
	 *
	 * @param string $type     'plugin' | 'theme'
	 * @param string $tmp_file The uploaded ZIP ($_FILES[...]['tmp_name'])
	 * @param int    $user_id  Who asked
	 * @return array{request_id:string, name:string}
	 * @throws Exception when the archive is not one we will stage
	 */
	public static function upload(string $type, string $tmp_file, int $user_id): array {
		$manager = $type === 'theme' ? new ThemeManager() : new PluginManager();
		$staged = $manager->stage($tmp_file);
		$request_id = RootRequest::submit(RootRequest::PACKAGE_KIND, array(
			'type'       => $type,
			'staged_dir' => self::stagedRelative($staged['dir']),
		), $user_id);
		return array('request_id' => $request_id, 'name' => (string)$staged['name']);
	}

	/**
	 * What the warning page shows for a refused request, or null with the
	 * reason in $why when the request is not one this operator can answer:
	 * not an install_package of this type, not refused as unverified, not
	 * theirs, or its staged directory is gone.
	 *
	 * @return array{request_id:string, type:string, name:string, version:string, author:string,
	 *               file_count:int, verdict:string, staged_dir:string, warning:string}|null
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

		$manifest = json_decode((string)@file_get_contents($dir . '/' . ($type === 'theme' ? 'theme.json' : 'plugin.json')), true);
		if (!is_array($manifest)) {
			$manifest = array();
		}
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
			'warning'    => PackageAcknowledgement::warning(),
		);
	}

	/**
	 * The Install anyway POST. Returns a LogicResult redirect when the
	 * second factor has to be confirmed first (the button is pressed again
	 * on return), or the new request's id once the acknowledged request is
	 * queued.
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
		return RootRequest::submit(RootRequest::PACKAGE_KIND, array(
			'type'         => $type,
			'staged_dir'   => $refused['staged_dir'],
			'unsigned_ack' => $ack,
		), (int)$session->get_user_id());
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
		$button = AdminPage::action_button('Install anyway', $post_url, array(
			'hidden' => array(
				'action'      => 'install_anyway',
				'_csrf_token' => $token,
				'request'     => $refused['request_id'],
			),
			'confirm' => 'Install this unsigned ' . $refused['type'] . '? It will have access to everything on this site.',
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
			. '<p class="jy-unsigned-warning__second">Installing it needs your second-factor confirmation. '
			. 'Its database migrations run as the web server user rather than root, every superadmin is emailed, '
			. 'and it is marked Unsigned on this page for as long as it is installed.</p>'
			. $button
			. '</div>';
	}
}
