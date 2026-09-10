<?php
/**
 * ScanUrlPageResources — lists the resource URLs a fetched web page refers
 * to, inside the parser jail (specs/parser_jail.md), for scan_url_logic to
 * resolve into third-party domains.
 *
 * The page is a stranger's bytes and libxml2 is the C parser that opens it, so
 * the walk happens here — in the extraction subprocess, as a user that holds
 * nothing — and the pool receives JSON: a flat list of the raw URL strings
 * found in script/link/img/iframe/video/audio/form/source attributes, in
 * srcset entries, and in inline stylesheets' url(). Nothing is resolved or
 * deduplicated here; that is the logic's job, with the page's own origin.
 *
 * @version 1.0 - the DOM half of scan_url_logic 1.3, moved behind the jail
 */

class ScanUrlPageResources implements SandboxParserInterface {

	/** Attributes carrying one URL, by tag. */
	const TAG_ATTRS = array(
		'script' => array('src'),
		'link'   => array('href'),
		'img'    => array('src'),
		'iframe' => array('src'),
		'video'  => array('src'),
		'audio'  => array('src'),
		'form'   => array('action'),
		'source' => array('src'),
	);

	public static function sandboxParse(string $bytes, array $options): string {
		$found = array();
		$dom = self::load($bytes);
		if ($dom !== null) {
			foreach (self::TAG_ATTRS as $tag => $attrs) {
				foreach ($dom->getElementsByTagName($tag) as $el) {
					foreach ($attrs as $attr) {
						$val = trim((string)$el->getAttribute($attr));
						if ($val !== '') $found[] = $val;
					}
				}
			}
			// srcset: comma-separated entries; the first whitespace token of each is the URL
			foreach (array('img', 'source') as $tag) {
				foreach ($dom->getElementsByTagName($tag) as $el) {
					$srcset = (string)$el->getAttribute('srcset');
					if ($srcset === '') continue;
					foreach (explode(',', $srcset) as $entry) {
						$tokens = preg_split('/\s+/', trim($entry), 2);
						if (!empty($tokens[0])) $found[] = $tokens[0];
					}
				}
			}
			// Inline <style> blocks: CSS url()
			foreach ($dom->getElementsByTagName('style') as $style) {
				preg_match_all('/url\(\s*[\'"]?([^\'"\)\s]+)[\'"]?\s*\)/i', (string)$style->textContent, $m);
				foreach ($m[1] as $css_url) $found[] = $css_url;
			}
		}
		$json = json_encode(array_values($found), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
		return $json === false ? '[]' : $json;
	}

	/** Parse hostile HTML with no network reach; null when it will not parse at all. */
	private static function load(string $html): ?DOMDocument {
		if (trim($html) === '') return null;
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		return $ok ? $dom : null;
	}
}
?>
