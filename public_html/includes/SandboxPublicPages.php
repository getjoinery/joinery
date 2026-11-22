<?php
/**
 * SandboxPublicPages — a sandboxed plugin's pages anyone can open, signed in
 * or not (spec sandboxed_plugin_public_pages).
 *
 * A public page is declared in the manifest's public_pages, approved as a
 * grant (SandboxConsent), and opens at the plugin's own address,
 * /{plugin}/{slug}, where a native plugin's views open; /{plugin} alone is
 * its page "index". RouteHelper sends such an address here only when nothing
 * else on the site answers it, so a plugin can never take a page the site
 * has. Its view is views/sandbox_public_page.php and its logic
 * logic/sandbox_public_page_logic.php, which has no API descriptor.
 *
 * What a visitor not signed in gets, as a native public page gives it:
 *   - the plugin's own approved access for what the page shows, with no
 *     viewer, so own-rows asks and uploads are refused; and only reading,
 *     unless the call answers a form POST that passed the site's spam checks
 *     (SandboxRelay::anonymousGate());
 *   - FormWriter's honeypot, anti-spam question and captcha on every POST
 *     form the site draws or cleans (spamInputs(), at the marker the
 *     cleaner writes in each POST form), checked before the plugin is called
 *     (spamCheck()), each governed by the site's own settings;
 *   - the static page cache for a GET (mayCache()), within the limits the
 *     cost and the correctness of a plugin's page force: the bare address,
 *     or the query names its manifest lists in cache_query; at most
 *     MAX_COPIES copies a page; never a page with a POST form, a framed
 *     page, or one the plugin did not draw; never with "cache": false.
 * Its calls run in SandboxHost's anonymous pool, one at a time per network,
 * PUBLIC_CALLS_PER_MINUTE a network.
 *
 * A signed-in visitor is the page's viewer, as on a member page: full
 * access, no spam checks, never cached (the cache serves only visitors not
 * signed in).
 *
 * @version 1.1 - reviewer2 R1, R3: POST forms are found by the cleaner (SandboxPages::drawPluginPage() post_forms),
 *                not by matching markup; mayCache() judges the request's own query names
 * @version 1.0
 */

class SandboxPublicPages {

	/** The most cached copies one public page may have; past it a request is served uncached (P16). */
	const MAX_COPIES = 200;

	/** The slug /{plugin} alone opens, as a native plugin's /{plugin} opens its index view. */
	const INDEX_SLUG = 'index';

	/** A public page's address. */
	public static function url(string $plugin, string $slug): string {
		return '/' . rawurlencode($plugin) . '/' . rawurlencode($slug);
	}

	/**
	 * The public page a request path names, or null: /{plugin}/{slug}, or
	 * /{plugin} for its index, the plugin an active sandboxed plugin and the
	 * page one an admin approved.
	 *
	 * @return array{plugin:string, page:array}|null
	 */
	public static function forPath(string $path): ?array {
		$segments = explode('/', trim($path, '/'));
		if (count($segments) > 2 || $segments[0] === '') {
			return null;
		}
		$plugin = $segments[0];
		$slug = $segments[1] ?? self::INDEX_SLUG;
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !preg_match(SandboxManifest::SLUG_PATTERN, $slug)) {
			return null;
		}
		$page = self::page($plugin, $slug);
		return $page === null ? null : array('plugin' => $plugin, 'page' => $page);
	}

	/** One of an active plugin's public pages an admin approved, or null. */
	public static function page(string $plugin, string $slug): ?array {
		if (!SandboxPlugins::isActive($plugin)) {
			return null;
		}
		try {
			$manifest = SandboxPlugins::manifest($plugin);
		} catch (SandboxManifestException $e) {
			return null;
		}
		$approved = SandboxConsent::effective($plugin)['public_pages'];
		foreach ($manifest->publicPages() as $page) {
			if ($page['slug'] === $slug && isset($approved[$slug])) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * Drop every cached copy under the plugin's address. On install,
	 * activation, a new approval or files, deactivation, an approval that
	 * lapses, and uninstall: a copy an interrupted uninstall left is never
	 * served as a later same-name plugin's page (design 5).
	 */
	public static function dropCache(string $plugin): void {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			return;
		}
		try {
			StaticPageCache::invalidatePath('/' . $plugin, true);
		} catch (Throwable $e) {
			error_log('[SandboxPublicPages] could not drop the cached pages of ' . $plugin . ': ' . $e->getMessage());
		}
	}

	/**
	 * Whether this response of a public page may be kept in the static page
	 * cache; when not, the cache is told to skip it, leaving no file and no
	 * index entry. Only a GET for a visitor not signed in reaches the cache;
	 * for any other request this answers false and changes nothing.
	 *
	 * @param array $get the request's query as the cache keys it ($_GET, every name; reviewer2 R3)
	 * @param bool $drawn whether the page was drawn as the plugin meant (no refusal, no failure)
	 * @param bool $has_post_form whether the page holds a form that posts (its answer is a session message, P12)
	 */
	public static function mayCache(string $plugin, array $page, array $get, bool $drawn, bool $has_post_form): bool {
		if (SessionControl::get_instance()->is_logged_in() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
			return false;
		}
		unset($get['__route']);
		$query = $get;
		$listed = (array)($page['cache_query'] ?? array());
		$ok = ($page['cache'] ?? true) !== false
			&& $drawn
			&& !$has_post_form
			// A framed page's calls need the visitor's own session (P12).
			&& !SandboxPages::isFramed($page)
			// A name not listed would make a page the key cannot tell apart (P15).
			&& !array_diff(array_keys($query), $listed)
			&& StaticPageCache::countPath(self::url($plugin, $page['slug'])) < self::MAX_COPIES;
		if (!$ok) {
			StaticPageCache::skipThisRequest();
		}
		return $ok;
	}

	/** Whether a manifest page holds a form section (they post). */
	public static function hasFormSection(array $page): bool {
		foreach ($page['sections'] ?? array() as $section) {
			if ($section['type'] === 'form') {
				return true;
			}
		}
		return false;
	}

	/**
	 * The site's spam-check inputs, as a native public form draws them:
	 * FormWriter's anti-spam question, honeypot and captcha, each present
	 * only when the site's settings turn it on.
	 */
	public static function spamInputs(): string {
		$formwriter = new FormWriterV2HTML5('sbxspam');
		ob_start();
		$formwriter->antispam_question_input();
		$formwriter->honeypot_hidden_input();
		$formwriter->captcha_hidden_input();
		return (string)ob_get_clean();
	}

	/**
	 * Run the site's spam checks on a visitor's POST, before the plugin is
	 * called, as native public form code does: '' when it passes,
	 * 'honeypot' when the honeypot was filled (answered with a 404, as
	 * natively), or the message to show.
	 */
	public static function spamCheck(array $post): string {
		$formwriter = new FormWriterV2HTML5('sbxspam');
		if (!$formwriter->honeypot_check($post)) {
			return 'honeypot';
		}
		if (!FormWriterV2HTML5::antispam_question_check($post)) {
			return 'Please type the correct value into the anti-spam field.';
		}
		if (!$formwriter->captcha_check($post)) {
			return 'Please complete the CAPTCHA to submit the form.';
		}
		return '';
	}
}
