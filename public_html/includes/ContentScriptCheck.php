<?php
/**
 * ContentScriptCheck - what in a piece of stored content the site's browser
 * policy will stop.
 *
 * An operator pastes an embed (a sign-up form, a widget) into a page and it
 * looks fine in the editor, but the site's Content-Security-Policy may refuse
 * its script or the address its form sends to, and a visitor pressing its
 * button gets nothing. This reads the policy the site actually sends, for its
 * current settings, and names each thing in the HTML that policy refuses, in
 * plain words, so the admin pages can say so beside the content.
 *
 * Only the enforced policy counts: a report-only one blocks nothing.
 *
 * @version 1.0
 */
class ContentScriptCheck {

	/**
	 * The enforced policy for this site's settings, as directive => sources,
	 * or null when nothing is enforced.
	 */
	public static function enforced_policy(): ?array {
		$settings = Globalvars::get_instance();
		$headers = PublicPageBase::csp_headers(
			(bool)$settings->get_setting('enable_csp', false, true),
			(bool)$settings->get_setting('csp_report_only', false, true),
			(string)$settings->get_setting('csp_strict_scripts', false, true),
			array(),
			PublicPageBase::site_form_origins((string)$settings->get_setting('csp_form_hosts', false, true))
		);
		foreach ($headers as $h) {
			if (strcasecmp($h[0], 'Content-Security-Policy') === 0) {
				return self::parse_policy($h[1]);
			}
		}
		return null;
	}

	/** "a b c; d e" -> ['a' => ['b', 'c'], 'd' => ['e']] */
	public static function parse_policy(string $header): array {
		$policy = array();
		foreach (explode(';', $header) as $part) {
			$words = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
			if (!$words) continue;
			$policy[strtolower(array_shift($words))] = $words;
		}
		return $policy;
	}

	/**
	 * Each thing in $html the policy refuses, as a sentence an admin reads.
	 * Empty when nothing is refused or no policy is enforced.
	 *
	 * @param array|null $policy directive => sources; the site's own when null
	 * @return string[]
	 */
	public static function problems(string $html, ?array $policy = null): array {
		if ($policy === null) {
			$policy = self::enforced_policy();
		}
		if ($policy === null || trim($html) === '') {
			return array();
		}
		$script_src = $policy['script-src'] ?? ($policy['default-src'] ?? array());
		$form_action = $policy['form-action'] ?? null;
		$inline_runs = in_array("'unsafe-inline'", $script_src, true);
		$out = array();

		if (preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $m, PREG_SET_ORDER)) {   // jy-allow-script: a pattern that finds scripts in stored content, not a script
			foreach ($m as $tag) {
				$attrs = $tag[1];
				if (preg_match('#\btype\s*=\s*["\']?application/(ld\+)?json#i', $attrs)) continue;
				if (preg_match('#\bsrc\s*=\s*["\']?([^"\'\s>]+)#i', $attrs, $src)) {
					$origin = self::origin_of($src[1]);
					if ($origin !== null && !self::allowed($origin, $script_src)) {
						$out[] = 'A script loaded from ' . $origin . ' is blocked: this site loads scripts only from itself and the payment and captcha services it uses.';
					}
				} elseif (!$inline_runs && trim($tag[2]) !== '') {
					$out[] = 'A script written into the content (' . self::sample($tag[2]) . ') does not run: this site runs no script written into a page.';
				}
			}
		}
		if (!$inline_runs) {
			if (preg_match_all('#<[a-z][^>]*?\s(on[a-z]+)\s*=#i', $html, $m)) {
				$names = array_unique(array_map('strtolower', $m[1]));
				$out[] = 'An action written into an element (' . implode(', ', $names) . ') does not run: this site runs no script written into a page.';
			}
			if (preg_match('#\shref\s*=\s*["\']?\s*javascript:#i', $html)) {
				$out[] = 'A javascript: link does nothing when clicked: this site runs no script written into a page.';
			}
		}
		if ($form_action !== null && preg_match_all('#<form\b[^>]*\baction\s*=\s*["\']?([^"\'\s>]+)#i', $html, $m)) {
			foreach (array_unique($m[1]) as $action) {
				$origin = self::origin_of(html_entity_decode($action, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				if ($origin !== null && !self::allowed($origin, $form_action)) {
					$out[] = 'A form sends to ' . $origin . ', where this site\'s forms may not send, so pressing its button does nothing.'
						. ' To allow it, add ' . $origin . ' to "Other addresses this site\'s forms may send to" in Settings.';
				}
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Every string in a component's stored body and config, joined, for
	 * problems(). The config is JSON whose text fields may hold HTML.
	 */
	public static function component_html(PageContent $content): string {
		$parts = array((string)$content->get('pac_body'));
		$config = $content->get('pac_config');
		if (is_string($config)) {
			$config = json_decode($config, true);
		}
		if (is_array($config)) {
			array_walk_recursive($config, function ($v) use (&$parts) {
				if (is_string($v) && strpos($v, '<') !== false) $parts[] = $v;
			});
		}
		return implode("\n", $parts);
	}

	/** scheme://host[:port] of an absolute or protocol-relative URL; null for one on this site. */
	private static function origin_of(string $url): ?string {
		$url = trim($url);
		if (strpos($url, '//') === 0) {
			$url = 'https:' . $url;
		}
		if (!preg_match('#^(https?)://([^/?\#:]+)(:\d+)?#i', $url, $m)) {
			return null;
		}
		return strtolower($m[1] . '://' . $m[2] . ($m[3] ?? ''));
	}

	/** Whether a source list lets $origin through ('self' is never this: origin_of() skips local URLs). */
	private static function allowed(string $origin, array $sources): bool {
		foreach ($sources as $s) {
			$s = strtolower($s);
			if ($s === '*' || $s === $origin) return true;
			if (strpos($s, '://*.') !== false) {
				list($scheme, $rest) = explode('://*.', $s, 2);
				if (strpos($origin, $scheme . '://') === 0 && substr($origin, -strlen('.' . $rest)) === '.' . $rest) return true;
			}
		}
		return false;
	}

	/** The first 40 characters of a script, on one line. */
	private static function sample(string $code): string {
		$code = trim(preg_replace('/\s+/', ' ', $code));
		return mb_strlen($code) > 40 ? mb_substr($code, 0, 40) . '…' : $code;
	}
}
