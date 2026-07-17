<?php
/**
 * MailboxHtmlSanitizer — the server-authoritative allowlist sanitizer for
 * user-composed rich-text mail (specs/mailbox_compose_maturity.md § Phase 1).
 *
 * The compose editor is a contenteditable div whose HTML is sanitized client-side
 * for a clean paste, but the client sanitizer is advisory only: every send routes
 * its body_html through sanitize() here before it is attached to the outgoing
 * message and stored. Signatures pass through the same allowlist (with img
 * excluded — see sanitize()'s $allow_images = false path).
 *
 * The allowlist is intentionally tiny (specs/mailbox_compose_maturity.md § Phase 1):
 *   tags:  p br div b strong i em u a ul ol li blockquote img
 *   attrs: a[href] (http/https/mailto only) — target/rel forced;
 *          img[src] (cid: only) + alt
 * Every other tag is UNWRAPPED (its text kept, structure dropped); a small set of
 * actively dangerous containers (script/style/iframe/…) is dropped WITH contents;
 * every attribute outside the two allowances above is stripped, which removes all
 * inline styles, class/id, and every on* event handler.
 *
 * toPlainText() derives the stored iem_body_plain from the sanitized HTML — tags
 * stripped, links rendered as "text <url>" — so a degraded client (or the FTS
 * indexer, mobile app, snippet builder) always has a faithful plaintext copy.
 *
 * Uses ext-dom (DOMDocument), always present in this deployment; no new dependency.
 *
 * @version 1.0
 */

class MailboxHtmlSanitizer {

	/** Tags kept as-is (attributes still filtered per keepElement()). */
	private static $ALLOWED = array(
		'p' => true, 'br' => true, 'div' => true, 'b' => true, 'strong' => true,
		'i' => true, 'em' => true, 'u' => true, 'a' => true, 'ul' => true,
		'ol' => true, 'li' => true, 'blockquote' => true, 'img' => true,
	);

	/** Tags dropped together with their contents (never unwrapped). */
	private static $DROP = array(
		'script' => true, 'style' => true, 'iframe' => true, 'object' => true,
		'embed' => true, 'form' => true, 'input' => true, 'button' => true,
		'textarea' => true, 'select' => true, 'option' => true, 'link' => true,
		'meta' => true, 'head' => true, 'title' => true, 'noscript' => true,
		'svg' => true, 'math' => true, 'base' => true, 'applet' => true,
		'frame' => true, 'frameset' => true, 'template' => true, 'canvas' => true,
	);

	/**
	 * Sanitize composed HTML against the allowlist. Returns a clean fragment
	 * (no <html>/<body> wrapper). Empty in, empty out.
	 *
	 * @param bool $allow_images Keep inline cid: <img> (compose); false strips
	 *                           them entirely (signatures — no image tags).
	 */
	public static function sanitize(string $html, bool $allow_images = true): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}
		$doc = self::load($html);
		if ($doc === null) {
			return '';
		}
		$body = $doc->getElementsByTagName('body')->item(0);
		if ($body === null) {
			return '';
		}
		$out = new DOMDocument('1.0', 'UTF-8');
		$frag = $out->createDocumentFragment();
		foreach (iterator_to_array($body->childNodes) as $child) {
			foreach (self::cleanNode($child, $out, $allow_images) as $clean) {
				$frag->appendChild($clean);
			}
		}
		if (!$frag->hasChildNodes()) {
			return '';
		}
		$result = $out->saveHTML($frag);
		return $result === false ? '' : trim($result);
	}

	/**
	 * Derive plaintext from HTML (tags stripped, links as "text <url>"), for the
	 * stored iem_body_plain / degraded clients. Sanitizes first so the derivation
	 * only ever walks a known-clean tree.
	 */
	public static function toPlainText(string $html): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}
		$clean = self::sanitize($html, true);
		if ($clean === '') {
			// Nothing survived the allowlist (e.g. a bare data-URI image) — fall
			// back to a naive strip so a plaintext copy is never silently empty.
			return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		$doc = self::load($clean);
		if ($doc === null) {
			return trim(strip_tags($clean));
		}
		$body = $doc->getElementsByTagName('body')->item(0);
		$text = '';
		if ($body !== null) {
			self::renderText($body, $text);
		}
		$text = preg_replace("/[ \t]+\n/", "\n", $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		return trim($text);
	}

	// ── internals ────────────────────────────────────────────────────────────

	/** Parse an HTML fragment as UTF-8, errors suppressed. */
	private static function load(string $html): ?DOMDocument {
		$doc = new DOMDocument('1.0', 'UTF-8');
		$wrapped = '<!DOCTYPE html><html><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
			. $html . '</body></html>';
		$prev = libxml_use_internal_errors(true);
		$ok = $doc->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		return $ok ? $doc : null;
	}

	/**
	 * Recursively clean one source node into node(s) in $out. Returns an array:
	 * a kept element maps to itself (cleaned); an unwrapped element maps to its
	 * cleaned children; a dropped node maps to nothing.
	 *
	 * @return DOMNode[]
	 */
	private static function cleanNode(DOMNode $node, DOMDocument $out, bool $allow_images): array {
		if ($node->nodeType === XML_TEXT_NODE) {
			return array($out->createTextNode($node->nodeValue));
		}
		if ($node->nodeType !== XML_ELEMENT_NODE) {
			return array(); // comments, PIs, CDATA — dropped
		}
		$tag = strtolower($node->nodeName);
		if (isset(self::$DROP[$tag])) {
			return array();
		}

		// Clean children first (needed whether we keep or unwrap this element).
		$children = array();
		foreach (iterator_to_array($node->childNodes) as $child) {
			foreach (self::cleanNode($child, $out, $allow_images) as $c) {
				$children[] = $c;
			}
		}

		if (!isset(self::$ALLOWED[$tag]) || ($tag === 'img' && !$allow_images)) {
			return $children; // unwrap — keep the text, drop the structure
		}

		$elem = $out->createElement($tag);
		if ($tag === 'a') {
			$href = trim($node->getAttribute('href'));
			if (self::allowedHref($href)) {
				$elem->setAttribute('href', $href);
				$elem->setAttribute('target', '_blank');
				$elem->setAttribute('rel', 'noopener noreferrer nofollow');
			}
		} elseif ($tag === 'img') {
			$src = trim($node->getAttribute('src'));
			if (!self::allowedImgSrc($src)) {
				return array(); // an <img> is void — a bad src means drop it outright
			}
			$elem->setAttribute('src', $src);
			$alt = trim($node->getAttribute('alt'));
			if ($alt !== '') {
				$elem->setAttribute('alt', $alt);
			}
		}
		// Every other allowed tag keeps NO attributes (kills style/class/id/on*).

		foreach ($children as $c) {
			$elem->appendChild($c);
		}
		return array($elem);
	}

	/** An <a href> scheme we allow on the wire: http(s) or mailto only. */
	private static function allowedHref(string $href): bool {
		return $href !== '' && (bool)preg_match('#^(https?://|mailto:)#i', $href);
	}

	/** An <img src> we allow: cid: only (inline parts we attach ourselves). */
	private static function allowedImgSrc(string $src): bool {
		return stripos($src, 'cid:') === 0;
	}

	/** Walk a clean tree accumulating plaintext with link/block conventions. */
	private static function renderText(DOMNode $node, string &$out): void {
		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_TEXT_NODE) {
				$out .= preg_replace('/\s+/u', ' ', $child->nodeValue);
				continue;
			}
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			$tag = strtolower($child->nodeName);
			if ($tag === 'br') {
				$out .= "\n";
				continue;
			}
			if ($tag === 'img') {
				continue;
			}
			if ($tag === 'a') {
				$inner = '';
				self::renderText($child, $inner);
				$inner = trim($inner);
				$href = trim($child->getAttribute('href'));
				if (stripos($href, 'mailto:') === 0) {
					$href = substr($href, 7);
				}
				if ($inner !== '' && $href !== '' && strcasecmp($inner, $href) !== 0) {
					$out .= $inner . ' <' . $href . '>';
				} else {
					$out .= ($inner !== '' ? $inner : $href);
				}
				continue;
			}
			$block = in_array($tag, array('p', 'div', 'li', 'blockquote', 'ul', 'ol'), true);
			if ($block) {
				$out .= "\n";
			}
			if ($tag === 'li') {
				$out .= '- ';
			}
			self::renderText($child, $out);
			if ($block) {
				$out .= "\n";
			}
		}
	}
}
?>
