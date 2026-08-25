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
 * The other entry points read RECEIVED mail, which is arbitrary and hostile
 * and is normally kept behind a sandboxed iframe rather than sanitized at all.
 * toReadableText() reduces it to the words a person would read (list previews);
 * previewText() does the same for a received PLAIN part, which bulk senders
 * generate from their HTML and so arrives carrying literal entities and
 * invisible preheader padding; sanitizeForPrint() keeps received structure —
 * tables, alignment, inline styles — for the one surface that has to render
 * received mail inside a document of ours, the print sheet.
 *
 * Uses ext-dom (DOMDocument), always present in this deployment; no new dependency.
 *
 * @version 1.3 - previewText(): entity-decode + invisible-character collapse
 *                for plain-part previews
 * @version 1.2
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

	/**
	 * Derive the one-line reading text of arbitrary RECEIVED HTML — what a list
	 * row shows as its preview. Distinct from toPlainText(), which is a faithful
	 * plaintext copy of mail we composed: a preview wants only the words a person
	 * would read, so link URLs, image alts and invisible spacing characters are
	 * left out and every block boundary becomes a single space.
	 *
	 * Received marketing mail is the hard case. It carries its stylesheet inside
	 * the document, and strip_tags() removes the <style> TAGS while keeping the
	 * CSS between them — which is how a preview ends up reading "a.cta_button{-moz-
	 * box-sizing...". Parsing instead of pattern-matching drops those containers
	 * with their contents, along with <head>, <title> and comments.
	 *
	 * @param string $html Raw received HTML (untrusted, possibly enormous).
	 * @return string Collapsed single-line text; '' when there is nothing to read.
	 */
	public static function toReadableText(string $html): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}
		// A preview needs the first readable sentence, not the whole newsletter.
		// The cap bounds the parse; it is far past any document's <head>, so the
		// stylesheet is never all that survives truncation.
		if (strlen($html) > self::READABLE_INPUT_LIMIT) {
			$html = substr($html, 0, self::READABLE_INPUT_LIMIT);
		}
		$doc = self::load($html);
		if ($doc === null) {
			// Unparseable — a naive strip is still better than returning nothing.
			return self::collapseReadable(
				html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		$body = $doc->getElementsByTagName('body')->item(0);
		if ($body === null) {
			return '';
		}
		$text = '';
		self::collectReadableText($body, $text);
		return self::collapseReadable($text);
	}

	/**
	 * Clean a received PLAIN-TEXT part for a one-line preview. Bulk senders
	 * generate the plain part from their HTML often enough that it arrives
	 * carrying the HTML's residue as literal text: entities (&zwnj;, &#847;,
	 * &nbsp;) and the invisible characters a preheader is padded with. Decode
	 * the entities, then run the same invisible-character collapse the HTML
	 * preview path uses — what remains is words a person would read, or ''
	 * when the part was nothing but padding (the caller then falls back to the
	 * HTML preview).
	 *
	 * Preview-only on purpose: the stored body is the faithful copy, and a
	 * plain part that legitimately discusses entities ("use &nbsp; here")
	 * should keep them everywhere except this one glanceable line.
	 */
	public static function previewText(string $text): string {
		$text = trim($text);
		if ($text === '') {
			return '';
		}
		return self::collapseReadable(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * Sanitize RECEIVED HTML for rendering inside one of our OWN documents —
	 * the print sheet. Distinct from sanitize(), which is the narrow allowlist
	 * for mail we compose: a printout has to keep looking like the email, and an
	 * email's layout is tables, alignment attributes and inline styles. So the
	 * structure survives here and only the parts that can act are removed.
	 *
	 * What cannot survive: script and every other executable or fetching
	 * container (the $DROP list), <style> blocks, every event handler and
	 * unlisted attribute, any style declaration outside the property allowlist,
	 * and any style value containing url() / expression() / @import — which is
	 * what stops a printed message from phoning home. <img> keeps only http(s)
	 * sources; cid: references are already signed URLs by the time they arrive.
	 *
	 * This is defence in depth, not the only defence: the print sheet is served
	 * under a Content-Security-Policy that forbids script outright, so a hole
	 * here is still not an execution.
	 */
	public static function sanitizeForPrint(string $html): string {
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
			foreach (self::cleanPrintNode($child, $out) as $clean) {
				$frag->appendChild($clean);
			}
		}
		if (!$frag->hasChildNodes()) {
			return '';
		}
		$result = $out->saveHTML($frag);
		return $result === false ? '' : trim($result);
	}

	// ── internals ────────────────────────────────────────────────────────────

	/** Tags kept when rendering received mail for print (attributes still filtered). */
	private static $PRINT_ALLOWED = array(
		'a' => true, 'abbr' => true, 'address' => true, 'article' => true,
		'aside' => true, 'b' => true, 'big' => true, 'blockquote' => true,
		'br' => true, 'caption' => true, 'center' => true, 'cite' => true,
		'code' => true, 'col' => true, 'colgroup' => true, 'dd' => true,
		'del' => true, 'div' => true, 'dl' => true, 'dt' => true, 'em' => true,
		'figcaption' => true, 'figure' => true, 'font' => true, 'footer' => true,
		'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true,
		'h6' => true, 'header' => true, 'hr' => true, 'i' => true, 'img' => true,
		'ins' => true, 'li' => true, 'main' => true, 'mark' => true, 'nav' => true,
		'ol' => true, 'p' => true, 'pre' => true, 'q' => true, 's' => true,
		'section' => true, 'small' => true, 'span' => true, 'strike' => true,
		'strong' => true, 'sub' => true, 'sup' => true, 'table' => true,
		'tbody' => true, 'td' => true, 'tfoot' => true, 'th' => true,
		'thead' => true, 'time' => true, 'tr' => true, 'u' => true, 'ul' => true,
	);

	/** Attributes allowed on any print tag. */
	private static $PRINT_GLOBAL_ATTRS = array(
		'style' => true, 'align' => true, 'valign' => true, 'dir' => true,
		'title' => true,
	);

	/** Extra attributes allowed on specific print tags. */
	private static $PRINT_TAG_ATTRS = array(
		'img'      => array('alt' => true, 'width' => true, 'height' => true, 'hspace' => true, 'vspace' => true),
		'table'    => array('width' => true, 'height' => true, 'bgcolor' => true, 'border' => true,
							'cellpadding' => true, 'cellspacing' => true),
		'td'       => array('width' => true, 'height' => true, 'bgcolor' => true,
							'colspan' => true, 'rowspan' => true, 'nowrap' => true),
		'th'       => array('width' => true, 'height' => true, 'bgcolor' => true,
							'colspan' => true, 'rowspan' => true, 'nowrap' => true),
		'tr'       => array('bgcolor' => true, 'height' => true),
		'col'      => array('span' => true, 'width' => true),
		'colgroup' => array('span' => true, 'width' => true),
		'font'     => array('color' => true, 'face' => true, 'size' => true),
		'ol'       => array('start' => true, 'type' => true),
		'hr'       => array('width' => true, 'size' => true, 'noshade' => true),
	);

	/** CSS properties kept from an inline style attribute. */
	private static $PRINT_STYLE_PROPS = array(
		'background-color' => true, 'border' => true, 'border-bottom' => true,
		'border-bottom-color' => true, 'border-bottom-style' => true,
		'border-bottom-width' => true, 'border-collapse' => true,
		'border-color' => true, 'border-left' => true, 'border-radius' => true,
		'border-right' => true, 'border-spacing' => true, 'border-style' => true,
		'border-top' => true, 'border-width' => true, 'clear' => true,
		'color' => true, 'direction' => true, 'display' => true, 'float' => true,
		'font' => true, 'font-family' => true, 'font-size' => true,
		'font-style' => true, 'font-variant' => true, 'font-weight' => true,
		'height' => true, 'letter-spacing' => true, 'line-height' => true,
		'list-style-type' => true, 'margin' => true, 'margin-bottom' => true,
		'margin-left' => true, 'margin-right' => true, 'margin-top' => true,
		'max-height' => true, 'max-width' => true, 'min-height' => true,
		'min-width' => true, 'overflow-wrap' => true, 'padding' => true,
		'padding-bottom' => true, 'padding-left' => true, 'padding-right' => true,
		'padding-top' => true, 'text-align' => true, 'text-decoration' => true,
		'text-indent' => true, 'text-transform' => true, 'vertical-align' => true,
		'white-space' => true, 'width' => true, 'word-break' => true,
	);

	/**
	 * Recursively clean one received node for print. Same contract as
	 * cleanNode(): kept elements map to themselves, unknown ones unwrap to their
	 * children, $DROP containers map to nothing.
	 *
	 * @return DOMNode[]
	 */
	private static function cleanPrintNode(DOMNode $node, DOMDocument $out): array {
		if ($node->nodeType === XML_TEXT_NODE) {
			return array($out->createTextNode($node->nodeValue));
		}
		if ($node->nodeType !== XML_ELEMENT_NODE) {
			return array(); // comments (incl. MSO conditionals), PIs, CDATA
		}
		$tag = strtolower($node->nodeName);
		if (isset(self::$DROP[$tag])) {
			return array();
		}

		$children = array();
		foreach (iterator_to_array($node->childNodes) as $child) {
			foreach (self::cleanPrintNode($child, $out) as $c) {
				$children[] = $c;
			}
		}

		if (!isset(self::$PRINT_ALLOWED[$tag])) {
			return $children; // unwrap — keep the content, drop the container
		}

		$elem = $out->createElement($tag);

		if ($tag === 'img') {
			$src = trim($node->getAttribute('src'));
			// cid: parts arrive already rewritten to signed https URLs; anything
			// else (data:, file:, a bare cid: that never resolved) is dropped, and
			// an <img> is void so there is nothing to unwrap.
			if (!preg_match('#^https?://#i', $src)) {
				return array();
			}
			$elem->setAttribute('src', $src);
		} elseif ($tag === 'a') {
			$href = trim($node->getAttribute('href'));
			if (self::allowedHref($href)) {
				$elem->setAttribute('href', $href);
				$elem->setAttribute('rel', 'noopener noreferrer nofollow');
			}
		}

		$extra = self::$PRINT_TAG_ATTRS[$tag] ?? array();
		foreach ($node->attributes as $attr) {
			$name = strtolower($attr->nodeName);
			if (!isset(self::$PRINT_GLOBAL_ATTRS[$name]) && !isset($extra[$name])) {
				continue; // everything unlisted, which is every on* handler
			}
			$value = (string)$attr->nodeValue;
			if ($name === 'style') {
				$value = self::filterPrintStyle($value);
				if ($value === '') {
					continue;
				}
			}
			$elem->setAttribute($name, $value);
		}

		foreach ($children as $c) {
			$elem->appendChild($c);
		}
		return array($elem);
	}

	/**
	 * Keep the declarations of an inline style that only describe appearance.
	 * A value that fetches (url(), @import), computes (expression()) or escapes
	 * the attribute is dropped whole rather than repaired — the printed message
	 * has no reason to reach the network.
	 */
	private static function filterPrintStyle(string $style): string {
		$kept = array();
		foreach (explode(';', $style) as $declaration) {
			$colon = strpos($declaration, ':');
			if ($colon === false) {
				continue;
			}
			$prop = strtolower(trim(substr($declaration, 0, $colon)));
			$value = trim(substr($declaration, $colon + 1));
			if ($prop === '' || $value === '' || !isset(self::$PRINT_STYLE_PROPS[$prop])) {
				continue;
			}
			if (preg_match('#url\s*\(|expression\s*\(|javascript\s*:|@import|\\\\|[<>"\']#i', $value)) {
				continue;
			}
			$kept[] = $prop . ':' . $value;
		}
		return implode(';', $kept);
	}


	/** Ceiling on the HTML fed to toReadableText(), in bytes. */
	const READABLE_INPUT_LIMIT = 200000;

	/**
	 * Tags whose edges are a word boundary in the reading text. Without them a
	 * table-built email reads as "benefitReturn ProtectionTerms apply" — the
	 * cells hold separate sentences that HTML never joins with whitespace.
	 */
	private static $READABLE_BREAK = array(
		'p' => true, 'div' => true, 'br' => true, 'li' => true, 'ul' => true,
		'ol' => true, 'dl' => true, 'dt' => true, 'dd' => true, 'table' => true,
		'thead' => true, 'tbody' => true, 'tfoot' => true, 'tr' => true,
		'td' => true, 'th' => true, 'h1' => true, 'h2' => true, 'h3' => true,
		'h4' => true, 'h5' => true, 'h6' => true, 'blockquote' => true,
		'section' => true, 'article' => true, 'aside' => true, 'header' => true,
		'footer' => true, 'nav' => true, 'main' => true, 'hr' => true,
		'pre' => true, 'address' => true, 'figure' => true, 'figcaption' => true,
		'center' => true, 'fieldset' => true, 'legend' => true, 'caption' => true,
	);

	/** Accumulate readable text, honouring $DROP containers and block boundaries. */
	private static function collectReadableText(DOMNode $node, string &$out): void {
		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_TEXT_NODE) {
				$out .= $child->nodeValue;   // DOM has already decoded entities
				continue;
			}
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				continue;                    // comments (incl. MSO conditionals), PIs
			}
			$tag = strtolower($child->nodeName);
			if (isset(self::$DROP[$tag])) {
				continue;                    // style/script/head/title — contents and all
			}
			$breaks = isset(self::$READABLE_BREAK[$tag]);
			if ($breaks) {
				$out .= ' ';
			}
			self::collectReadableText($child, $out);
			if ($breaks) {
				$out .= ' ';
			}
		}
	}

	/** One line of readable text: invisible spacing gone, whitespace collapsed. */
	private static function collapseReadable(string $text): string {
		// Senders pad a preheader out to the length a client shows using invisible
		// characters — zero-width joiners, soft hyphens, and the combining grapheme
		// joiner, typically interleaved with non-breaking spaces. All spacing, no
		// words, and it will fill a whole preview line if left in.
		$text = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}\x{034F}\x{2800}]/u',
			'', (string)$text);
		// \s alone misses the Unicode spaces that padding leans on (NBSP above all),
		// so name them: otherwise the collapse leaves the runs it was meant to remove.
		$text = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u',
			' ', (string)$text);
		return trim((string)$text);
	}

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
