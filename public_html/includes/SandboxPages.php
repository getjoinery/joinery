<?php
/**
 * SandboxPages — what a sandboxed plugin's admin and profile pages share.
 *
 * Both are drawn from the manifest (door 4): adm/admin_sandbox_page.php for
 * admin pages, views/profile/sandbox_page.php for a member's. This class
 * reads a submitted form by its declared fields, shows what the plugin
 * answered and reads a table's rows. A member's page's logic is
 * logic/sandbox_page_logic.php, which is also the API action the apps call.
 *
 * @version 1.5 - framePrelude(): core's first script in the frame removes the WebRTC constructors, which
 *                Chromium's policy does not block (V6, in a real browser)
 * @version 1.4 - the frame's policy blocks WebRTC where the browser knows the directive (reviewer2 K3)
 * @version 1.3 - framed pages (design 9): the frame's markup, its route's headers and access, the outer page's frame source
 * @version 1.2 - plugin-drawn plain pages (specs/sandboxed_php_plugins.md design 6): drawPluginPage(), the body
 *                cleaned in the parser jail, redirects to its own pages only, the provenance line, its assets
 * @version 1.1 - uiOutcome() as plain text; reportUiResult() escapes for the renderer it reaches
 * @version 1.0
 */

class SandboxPages {

	/**
	 * A form section's submitted values, by the fields its manifest declares and
	 * nothing else: a boolean is true or false, a select one of its options, the
	 * rest text of at most 10000 characters.
	 *
	 * @return array<string,mixed>
	 */
	public static function formValues(array $section, array $input): array {
		$values = array();
		foreach ($section['fields'] ?? array() as $field) {
			$name = $field['name'];
			$raw = $input['sbx_' . $name] ?? null;
			$type = $field['type'] ?? 'text';
			if ($type === 'boolean') {
				$values[$name] = !empty($raw);
			} elseif ($type === 'select') {
				$values[$name] = is_scalar($raw) && array_key_exists((string)$raw, $field['options']) ? (string)$raw : '';
			} else {
				$values[$name] = is_scalar($raw) && !is_bool($raw) ? mb_substr(trim((string)$raw), 0, 10000) : '';
			}
		}
		return $values;
	}

	/**
	 * What a UI call came to, as plain text. A plugin answers {message,
	 * errors, status}; anything else it returns is shown as nothing more than
	 * done or not done.
	 *
	 * @return array{ok:bool, status:string, lines:string[], errors:array<string,string>}
	 */
	public static function uiOutcome(array $result): array {
		$ok = $result['status'] === SandboxLog::STATUS_OK;
		$output = is_array($result['output'] ?? null) ? $result['output'] : array();
		$errors = array();
		foreach (is_array($output['errors'] ?? null) ? $output['errors'] : array() as $field => $message) {
			if (is_scalar($message) && count($errors) < 20) {
				$errors[mb_substr((string)$field, 0, 60)] = mb_substr((string)$message, 0, 500);
			}
		}
		if ($ok && ($output['status'] ?? 'ok') === 'error') {
			$ok = false;
		}
		if ($ok && empty($errors)) {
			$text = is_string($output['message'] ?? null) && $output['message'] !== '' ? $output['message'] : 'Done.';
			return array('ok' => true, 'status' => $result['status'], 'lines' => array(mb_substr($text, 0, 1000)), 'errors' => array());
		}
		$lines = array();
		if ($result['status'] !== SandboxLog::STATUS_OK) {
			$lines[] = mb_substr((string)$result['message'], 0, 500);
		} elseif (is_string($output['message'] ?? null) && $output['message'] !== '') {
			$lines[] = mb_substr($output['message'], 0, 500);
		}
		foreach ($errors as $field => $message) {
			$lines[] = $field . ': ' . $message;
		}
		if (empty($lines)) {
			$lines[] = 'The plugin could not do that.';
		}
		return array('ok' => false, 'status' => $result['status'], 'lines' => $lines, 'errors' => $errors);
	}

	/**
	 * Show what a UI call came to, one message per line. The admin renderer
	 * escapes a message; the member renderer prints it as HTML, so for a
	 * member each line is escaped here.
	 */
	public static function reportUiResult(SessionControl $session, array $result, bool $admin): void {
		$outcome = self::uiOutcome($result);
		$type = $outcome['ok'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_WARNING;
		foreach ($outcome['lines'] as $line) {
			$session->save_message(new DisplayMessage($admin ? $line : htmlspecialchars($line), '', NULL,
				$type, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
	}

	/**
	 * A table section's rows, newest first unless it says otherwise.
	 *
	 * @return array{rows: SystemMultiBase, columns: array<string,string>, prefix: string}
	 */
	public static function tableRows(string $plugin, SandboxManifest $manifest, array $section): array {
		$model_name = $section['model'];
		$class = SandboxManifest::classFor($plugin, $model_name);
		$prefix = SandboxManifest::prefixFor($plugin, $model_name);
		$fields = array();
		foreach ($manifest->models() as $model) {
			if ($model['name'] === $model_name) {
				foreach ($model['fields'] as $field) {
					$fields[$field['name']] = $field;
				}
			}
		}
		$columns = array();
		foreach ($section['columns'] as $column) {
			$columns[$column] = $fields[$column]['label'] ?? ucfirst(str_replace('_', ' ', $column));
		}
		$order = (string)($section['order'] ?? '-create_time');
		$direction = substr($order, 0, 1) === '-' ? 'DESC' : 'ASC';
		$order_column = $prefix . '_' . ltrim($order, '-');
		$multi = 'Multi' . $class;
		$rows = new $multi(array('deleted' => false), array($order_column => $direction), (int)($section['limit'] ?? 50));
		return array('rows' => $rows, 'columns' => $columns, 'prefix' => $prefix, 'fields' => $fields);
	}

	/** Image types an asset may be, by extension. Never SVG: it can carry script. */
	const ASSET_TYPES = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp');

	/** The address of the plugin's assets, before the file's url-encoded name. */
	public static function assetUrl(string $plugin): string {
		return '/sandbox_asset?plugin=' . rawurlencode($plugin) . '&file=';
	}

	/**
	 * A plugin's own asset file, or null: a regular file, no link anywhere on
	 * the way, its real path inside the plugin's src/assets/, an image type.
	 *
	 * @return array{path:string, type:string}|null
	 */
	public static function assetFile(string $plugin, string $file): ?array {
		if (!SandboxPlugins::isActive($plugin) || !preg_match('~^[A-Za-z0-9_\-][A-Za-z0-9_.\-/]{0,199}$~D', $file)
				|| strpos($file, '..') !== false || strpos($file, '//') !== false) {
			return null;
		}
		$type = self::ASSET_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
		$root = realpath(SandboxPlugins::dirFor($plugin) . '/' . SandboxPlugins::SOURCE_DIR . '/assets');
		if ($type === null || $root === false) {
			return null;
		}
		$path = $root . '/' . $file;
		$walk = $root;
		foreach (explode('/', $file) as $part) {
			$walk .= '/' . $part;
			if (is_link($walk)) {
				return null;
			}
		}
		$real = realpath($path);
		if ($real === false || $real !== $path || strpos($real, $root . '/') !== 0 || !is_file($real)) {
			return null;
		}
		return array('path' => $real, 'type' => $type);
	}

	/**
	 * Whether this site runs the strict script policy, enforced (design 6): a
	 * plain page is shown only there, since the cleaner is then not the only
	 * guard against script.
	 */
	public static function strictScriptsEnforced(): bool {
		$settings = Globalvars::get_instance();
		return (bool)$settings->get_setting('enable_csp') && !$settings->get_setting('csp_report_only')
			&& (string)$settings->get_setting('csp_strict_scripts') === 'enforce';
	}

	/** What a plain page says where it cannot be shown for the script policy. */
	const NO_STRICT_POLICY = 'This page is drawn by a sandboxed plugin, and this site does not enforce the strict script policy, so it is not shown.';

	/** The route that serves a framed page's document (design 9). */
	const FRAME_PATH = '/sandbox_frame';

	/** The most a framed page's document may be: script and styles inline. */
	const MAX_FRAME_BYTES = 4194304;

	/**
	 * What core writes ahead of a framed page's own document: a doctype and
	 * one script that runs before any of the plugin's, removing the WebRTC
	 * constructors. A content security policy does not cover WebRTC in every
	 * browser (Chromium ignores the webrtc directive), and an RTCPeerConnection
	 * reaches any STUN or TURN host, which would carry what the frame shows
	 * past the approved outbound hosts. The frame has no other window to fetch
	 * them back from: it may not load a frame, open a popup or start a worker.
	 */
	const FRAME_RTC_NAMES = array('RTCPeerConnection', 'webkitRTCPeerConnection', 'mozRTCPeerConnection',
		'RTCDataChannel', 'RTCIceTransport', 'RTCDtlsTransport', 'RTCSctpTransport',
		'RTCRtpSender', 'RTCRtpReceiver', 'RTCRtpTransceiver', 'RTCRtpScriptTransform');

	public static function framePrelude(): string {
		return '<!doctype html><script>(function(){var n=' . json_encode(self::FRAME_RTC_NAMES)   // jy-allow-script: the sandboxed frame's own document (its policy allows inline script), never this site's page
			. ';for(var i=0;i<n.length;i++){try{Object.defineProperty(window,n[i],{value:undefined,writable:false,configurable:false});}catch(e){}}})();</script>' . "\n";
	}

	/** Whether a page is framed: the plugin's own document in a sealed frame. */
	public static function isFramed(array $page): bool {
		return !empty($page['frame']);
	}

	/** The framed route's address for one page. */
	public static function frameUrl(string $plugin, string $kind, string $slug): string {
		return self::FRAME_PATH . '?' . http_build_query(array('plugin' => $plugin, 'kind' => $kind === 'admin' ? 'admin' : 'member', 'page' => $slug));
	}

	/** The one frame source an outer page allows: this site's framed route (PublicPageBase::restrict_frames_to()). */
	public static function frameSource(): string {
		$host = explode('/', (string)preg_replace('~^https?://~i', '', (string)Globalvars::get_instance()->get_setting('webDir')))[0];
		return 'https://' . $host . self::FRAME_PATH;
	}

	/**
	 * The outer page's frame for a framed page, and core's bridge script: the
	 * frame's one way to the site (a call of the plugin's own handler, and its
	 * height). The frame is sandboxed by its own response header too.
	 */
	public static function frameMarkup(SandboxManifest $manifest, string $kind, array $page): string {
		return self::provenance($manifest)
			. '<iframe class="jy-sandbox-frame" src="' . htmlspecialchars(self::frameUrl($manifest->name(), $kind, $page['slug'])) . '"'
			. ' sandbox="allow-scripts" referrerpolicy="no-referrer" title="' . htmlspecialchars((string)$page['title']) . '"'
			. ' data-sbx-plugin="' . htmlspecialchars($manifest->name()) . '" data-sbx-kind="' . ($kind === 'admin' ? 'admin' : 'member') . '"'
			. ' data-sbx-page="' . htmlspecialchars((string)$page['slug']) . '"'
			. ' style="width: 100%; height: 600px; border: 0; display: block;"></iframe>'
			. '<script src="/assets/js/sandbox-frame.js?v=' . (int)@filemtime(PathHelper::getIncludePath('assets/js/sandbox-frame.js')) . '"></script>';
	}

	/**
	 * A framed page the signed-in viewer may open, or why not: the plugin
	 * active, the page framed and approved (a member page; a framed admin
	 * page is a grant too), the viewer at the admin pages' class for an admin
	 * page and passing design 11's check.
	 *
	 * @return array|string the page, or the refusal
	 */
	public static function framedPage(string $plugin, string $kind, string $slug, SessionControl $session) {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !SandboxPlugins::isActive($plugin) || !$session->is_logged_in()) {
			return 'That page does not exist.';
		}
		$manifest = SandboxPlugins::manifest($plugin);
		$effective = SandboxConsent::effective($plugin);
		$level = (int)$session->get_permission();
		if ($kind === 'admin') {
			if (!Access::allows('config:read', $level)) {
				return 'That page does not exist.';
			}
			foreach (array_keys($effective['records']) as $model) {
				if (class_exists((string)$model) && is_subclass_of((string)$model, 'SystemBase') && !$model::$api_public_read
						&& !Access::allows($model::access_level('read'), $level)) {
					return 'This plugin can read every ' . $model . ' record, which your account may not read, so its pages are not shown to you.';
				}
			}
			$pages = $manifest->adminPages();
			$approved = $effective['framed_admin_pages'];
		} else {
			$pages = $manifest->profilePages();
			$approved = $effective['member_pages'];
		}
		foreach ($pages as $page) {
			if ($page['slug'] === $slug && self::isFramed($page) && isset($approved[$slug])) {
				return $page;
			}
		}
		return 'That page does not exist.';
	}

	/**
	 * The headers of a framed page's document (design 9): the browser treats
	 * it as another site with no network. Inline script and style only;
	 * images from data: and the plugin's own assets; the site's kit
	 * stylesheet, the one file it may load; nothing else, no form posting,
	 * no base, and only this site may frame it.
	 *
	 * @return array<string,string>
	 */
	public static function frameHeaders(string $plugin): array {
		$host = explode('/', (string)preg_replace('~^https?://~i', '', (string)Globalvars::get_instance()->get_setting('webDir')))[0];
		$site = 'https://' . $host;
		return array(
			'Content-Security-Policy' => "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline' "
				. $site . '/assets/css/joinery-styles.css; img-src data: ' . $site . '/sandbox_asset; font-src data:; '
				. "connect-src 'none'; media-src 'none'; frame-src 'none'; child-src 'none'; worker-src 'none'; form-action 'none'; base-uri 'none'; "
				. "frame-ancestors 'self'; webrtc 'block'",
			'Content-Type' => 'text/html; charset=utf-8',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy' => 'no-referrer',
			'Cross-Origin-Resource-Policy' => 'same-origin',
			'Cache-Control' => 'no-store',
			'X-Frame-Options' => 'SAMEORIGIN',
		);
	}

	/** Whether a page is drawn by the plugin's own view (no sections in its manifest). */
	public static function isPluginDrawn(array $page): bool {
		return !isset($page['sections']);
	}

	/**
	 * Draw a plugin-drawn page: call the plugin's view in the fence, then
	 * clean what it returned in the parser jail (SandboxHtmlCleaner).
	 *
	 * @param string $kind 'admin' or 'member'
	 * @param string $here the page's own address (every form posts to it)
	 * @return array{ok:bool, html?:string, title?:string, redirect?:string, message?:string}
	 */
	public static function drawPluginPage(string $plugin, string $kind, array $page, string $here, array $query, array $fields, string $method, ?User $user): array {
		if (!self::strictScriptsEnforced()) {
			return array('ok' => false, 'message' => self::NO_STRICT_POLICY);
		}
		$result = SandboxHost::page($plugin, $kind, $page['slug'], $method, $query, $fields, $user);
		if ($result['status'] !== SandboxLog::STATUS_OK) {
			return array('ok' => false, 'message' => (string)$result['message']);
		}
		$out = is_array($result['output'] ?? null) ? $result['output'] : array();
		if (isset($out['redirect'])) {
			$target = self::redirectTarget($plugin, $kind, (array)$out['redirect']);
			if ($target === null) {
				return array('ok' => false, 'message' => 'The plugin tried to send you somewhere other than one of its own pages.');
			}
			return array('ok' => true, 'redirect' => $target);
		}
		$body = is_string($out['body'] ?? null) ? $out['body'] : '';
		$clean = DocumentText::parseWith('SandboxHtmlCleaner', $body, array(
			'page_url'    => $here,
			'asset_url'   => self::assetUrl($plugin),
			'image_hosts' => SandboxConsent::effective($plugin)['outbound_hosts'],
			'form_hidden' => array('plugin' => $plugin, 'page' => (string)$page['slug']),
		));
		if ($clean['status'] !== DocumentText::OK && $clean['status'] !== DocumentText::EMPTY) {
			error_log('[SandboxPages] ' . $plugin . '/' . $page['slug'] . ': the body was refused: ' . (string)$clean['detail']);
			return array('ok' => false, 'message' => 'The plugin\'s page could not be shown safely.');
		}
		$title = is_string($out['title'] ?? null) && trim($out['title']) !== '' ? mb_substr(trim($out['title']), 0, 80) : (string)$page['title'];
		return array('ok' => true, 'html' => $clean['text'], 'title' => $title);
	}

	/**
	 * A redirect the plugin asked for, as an address: only to one of its own
	 * pages of the same kind, with a plain query, or null.
	 */
	public static function redirectTarget(string $plugin, string $kind, array $redirect): ?string {
		$slug = (string)($redirect['page'] ?? '');
		$manifest = SandboxPlugins::manifest($plugin);
		$pages = $kind === 'admin' ? $manifest->adminPages() : $manifest->profilePages();
		$found = false;
		foreach ($pages as $p) {
			$found = $found || $p['slug'] === $slug;
		}
		if (!$found) {
			return null;
		}
		$url = $kind === 'admin' ? SandboxPluginManager::adminPageUrl($plugin, $slug) : self::profilePageUrl($plugin, $slug);
		$query = array();
		foreach ((array)($redirect['query'] ?? array()) as $k => $v) {
			if (is_string($k) && preg_match('~^[a-z][a-z0-9_]{0,39}$~D', $k) && $k !== 'plugin' && $k !== 'page' && is_scalar($v)) {
				$query[$k] = mb_substr((string)$v, 0, 500);
			}
		}
		return $query ? $url . '&' . http_build_query($query) : $url;
	}

	/**
	 * The box a drawn page's body sits in: nothing in it can be laid outside
	 * it (contain and overflow make it the containing block even of a fixed
	 * child), whatever class the markup carries.
	 */
	public static function bodyOpen(): string {
		return '<div class="jy-sandbox-page" style="contain: layout paint; overflow: hidden; position: relative; isolation: isolate;">';
	}

	/** The line that names who drew the page, so a plugin cannot pass its page off as core's. */
	public static function provenance(SandboxManifest $manifest): string {
		return '<p class="jy-sandbox-provenance">This page is drawn by the sandboxed plugin <strong>'
			. htmlspecialchars($manifest->title()) . '</strong> (' . htmlspecialchars($manifest->name()) . '), not by this site.</p>';
	}

	/**
	 * What a plugin-drawn page's call carries from the request: the query
	 * without core's own keys, and the posted fields, plain values only.
	 *
	 * @return array{0:array,1:array}
	 */
	public static function requestValues(array $get, array $post): array {
		$clean = function (array $in) {
			$out = array();
			foreach ($in as $k => $v) {
				if (!is_string($k) || in_array($k, array('plugin', 'page'), true) || count($out) >= 100) {
					continue;
				}
				if (is_scalar($v)) {
					$out[mb_substr($k, 0, 64)] = mb_substr((string)$v, 0, 10000);
				} elseif (is_array($v)) {
					$out[mb_substr($k, 0, 64)] = array_map(function ($x) { return is_scalar($x) ? mb_substr((string)$x, 0, 10000) : ''; }, array_slice($v, 0, 100));
				}
			}
			return $out;
		};
		return array($clean($get), $clean($post));
	}

	public static function profilePageUrl(string $plugin, string $slug): string {
		return '/profile/sandbox_page?plugin=' . rawurlencode($plugin) . '&page=' . rawurlencode($slug);
	}

	/** Draw a form section's fields, named sbx_{field}. */
	public static function renderFormFields($formwriter, array $section): void {
		foreach ($section['fields'] ?? array() as $field) {
			$name = 'sbx_' . $field['name'];
			$options = array();
			if (!empty($field['help'])) {
				$options['helptext'] = $field['help'];
			}
			if (!empty($field['required'])) {
				$options['validation'] = array('required' => true);
			}
			switch ($field['type'] ?? 'text') {
				case 'textarea': $formwriter->textbox($name, $field['label'], $options); break;
				case 'number':   $formwriter->numberinput($name, $field['label'], $options); break;
				case 'boolean':  $formwriter->checkboxinput($name, $field['label'], $options); break;
				case 'date':     $formwriter->dateinput($name, $field['label'], $options); break;
				case 'select':   $formwriter->dropinput($name, $field['label'], $options + array('options' => $field['options'])); break;
				default:         $formwriter->textinput($name, $field['label'], $options);
			}
		}
	}
}
