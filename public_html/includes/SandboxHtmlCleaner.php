<?php
/**
 * SandboxHtmlCleaner - core's own cleaner for the body of a sandboxed
 * plugin's plain page (specs/sandboxed_php_plugins.md design 6, WP5).
 *
 * Runs in the parser jail, never in the web worker: SandboxPages hands the
 * body to DocumentText::parseWith('SandboxHtmlCleaner', $html, $options), so
 * a bug in the HTML parser meets a process that holds nothing.
 *
 * It parses and writes with PHP's HTML5 parser (Dom\HTMLDocument), the way a
 * browser parses, so what it keeps is what the browser builds. Everything is
 * an allowlist:
 *
 *   elements    text structure, tables, lists, links, images, forms and their
 *               inputs. Anything else is dropped with its contents: script,
 *               style, iframe, object, embed, link, meta, base, svg, math,
 *               noscript, template, comments, and anything unknown. Only
 *               elements in the HTML namespace are kept.
 *   attributes  each element keeps only the attributes named for it. Never:
 *               id, style, on*, data-*, form, formaction, formmethod,
 *               formtarget, srcset; name only on a form control.
 *   links       href keeps http:, https:, mailto: and relative addresses.
 *   images      src is one of the plugin's own files (assets/NAME, served by
 *               core from src/assets) or an https address on one of its
 *               approved hosts; any other image is dropped.
 *   forms       action is the page's own address, whatever the plugin wrote;
 *               method is GET or POST. No password or file input survives,
 *               nor a hidden _csrf_token (FormWriter mints one in the fence,
 *               which means nothing to core).
 *
 * Then it parses its own output again and cleans that: unless the second
 * pass changes nothing, the page is refused (a mutation, markup that grows
 * new elements when the browser reads it back).
 *
 * A class survives only from the kit's content classes (CLASSES), and
 * autocomplete only as on or off. Core's own control names (plugin, page,
 * action, section) are dropped, and a GET form is given the page's own
 * plugin and page as hidden fields (form_hidden), since a GET submit
 * replaces the action's query.
 *
 * Options: page_url (the form action), asset_url (what assets/NAME becomes:
 * the prefix, NAME url-encoded after it), image_hosts (approved hosts),
 * form_hidden (name => value, added to every GET form).
 *
 * @version 1.1 - reviewer2 H1-H5: core's control names dropped; classes from the kit's content list only; GET
 *                forms carry the page's plugin and page; a leading newline in pre/textarea kept; autocomplete on/off
 * @version 1.0
 */

class SandboxHtmlCleaner implements SandboxParserInterface {

	/** The largest body cleaned. */
	const MAX_BYTES = 1048576;

	const HTML_NS = 'http://www.w3.org/1999/xhtml';

	/** Attributes any kept element may carry. */
	const GLOBAL_ATTRIBUTES = array('class', 'title', 'lang', 'dir');

	/** Kept elements => the attributes each keeps beyond the global ones. */
	const ELEMENTS = array(
		'p' => array(), 'br' => array(), 'hr' => array(), 'div' => array(), 'span' => array(),
		'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
		'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 's' => array(),
		'small' => array(), 'sub' => array(), 'sup' => array(), 'mark' => array(), 'code' => array(),
		'pre' => array(), 'kbd' => array(), 'samp' => array(), 'var' => array(), 'blockquote' => array(),
		'q' => array(), 'cite' => array(), 'abbr' => array(), 'del' => array(), 'ins' => array(),
		'section' => array(), 'article' => array(), 'aside' => array(), 'header' => array(), 'footer' => array(),
		'main' => array(), 'nav' => array(), 'figure' => array(), 'figcaption' => array(),
		'details' => array('open'), 'summary' => array(), 'time' => array('datetime'),
		'ul' => array(), 'ol' => array('start', 'reversed'), 'li' => array('value'),
		'dl' => array(), 'dt' => array(), 'dd' => array(),
		'table' => array(), 'caption' => array(), 'thead' => array(), 'tbody' => array(), 'tfoot' => array(),
		'tr' => array(), 'th' => array('colspan', 'rowspan', 'scope'), 'td' => array('colspan', 'rowspan'),
		'colgroup' => array('span'), 'col' => array('span'),
		'a' => array('href'),
		'img' => array('src', 'alt', 'width', 'height'),
		'form' => array('action', 'method'),
		'fieldset' => array('disabled'), 'legend' => array(), 'label' => array(),
		'input' => array('type', 'name', 'value', 'placeholder', 'required', 'checked', 'disabled', 'readonly',
			'min', 'max', 'step', 'maxlength', 'minlength', 'size', 'multiple', 'pattern', 'autocomplete'),
		'select' => array('name', 'required', 'disabled', 'multiple', 'size'),
		'option' => array('value', 'selected', 'disabled', 'label'), 'optgroup' => array('label', 'disabled'),
		'textarea' => array('name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly', 'maxlength', 'minlength'),
		'button' => array('type', 'name', 'value', 'disabled'),
	);

	/** Input types kept; any other input is dropped (password, file, image among them). */
	const INPUT_TYPES = array('text', 'email', 'number', 'date', 'time', 'datetime-local', 'month', 'week',
		'checkbox', 'radio', 'hidden', 'search', 'tel', 'url', 'range', 'color', 'submit', 'reset', 'button');

	/**
	 * Control names that are core's, never a plugin's: the page logic reads
	 * plugin, page, action and section, and FormWriter's token is _csrf_token.
	 */
	const RESERVED_NAMES = array('_csrf_token', 'plugin', 'page', 'action', 'section');

	/**
	 * The classes a plugin's markup may use: the kit's content classes
	 * (buttons, tables, form rows, alerts, badges, text). Anything else is
	 * dropped: core's stylesheets have classes that cover the screen and
	 * core's scripts bind behaviour by class.
	 */
	const CLASSES = array('jy-btn', 'jy-btn-primary', 'jy-btn-secondary', 'jy-btn-danger', 'jy-btn-small', 'jy-btn-outline',
		'btn', 'btn-primary', 'btn-secondary', 'btn-danger', 'btn-success', 'btn-sm', 'btn-outline-primary', 'btn-outline-secondary',
		'table', 'table-striped', 'table-sm', 'table-bordered', 'jy-table',
		'form-control', 'form-group', 'form-label', 'form-select', 'form-check', 'form-check-input', 'form-check-label', 'form-text',
		'alert', 'alert-info', 'alert-success', 'alert-warning', 'alert-danger',
		'badge', 'text-muted', 'small', 'text-center', 'text-end', 'fw-bold');

	/** autocomplete values kept: whether to offer anything, never which saved value. */
	const AUTOCOMPLETE = array('on', 'off');

	/** @var array the options of this run */
	private $options = array();

	public static function sandboxParse(string $bytes, array $options): string {
		return (new self($options))->clean($bytes);
	}

	public function __construct(array $options = array()) {
		$this->options = $options + array('page_url' => '', 'asset_url' => '', 'image_hosts' => array(), 'form_hidden' => array());
	}

	/**
	 * The body cleaned, stable under a second pass.
	 *
	 * @throws DocumentTextException when it is too large or mutates
	 */
	public function clean(string $html): string {
		if (strlen($html) > self::MAX_BYTES) {
			throw new DocumentTextException('The page is larger than 1 MB.', 'too_large');
		}
		$once = $this->pass($html);
		$twice = $this->pass($once);
		if ($twice !== $once) {
			throw new DocumentTextException('The page changes when it is read back (a mutation), so it is not shown.', 'failed');
		}
		return $once;
	}

	/** One parse, clean and write. */
	private function pass(string $html): string {
		$doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>',
			LIBXML_NOERROR, 'UTF-8');
		$body = $doc->body;
		if ($body === null) {
			return '';
		}
		$this->cleanChildren($body);
		return $body->innerHTML;
	}

	private function cleanChildren(Dom\Node $parent): void {
		foreach (iterator_to_array($parent->childNodes) as $node) {
			if ($node instanceof Dom\Text) {
				continue;
			}
			if (!$node instanceof Dom\Element) {
				// Comments, processing instructions, anything else.
				$parent->removeChild($node);
				continue;
			}
			if (!$this->keepElement($node)) {
				$parent->removeChild($node);
				continue;
			}
			$this->cleanChildren($node);
			if ($node->localName === 'form' && $node->getAttribute('method') === 'get') {
				// A browser replaces the action's query with the form's fields on
				// a GET submit, so the page's own keys go in as fields. Any the
				// plugin wrote were dropped above (RESERVED_NAMES).
				$first = $node->firstChild;
				foreach ((array)$this->options['form_hidden'] as $key => $value) {
					$hidden = $node->ownerDocument->createElement('input');
					$hidden->setAttribute('type', 'hidden');
					$hidden->setAttribute('name', (string)$key);
					$hidden->setAttribute('value', (string)$value);
					$node->insertBefore($hidden, $first);
				}
			}
			if (in_array($node->localName, array('pre', 'textarea', 'listing'), true)) {
				// The parser drops one newline right after these tags, so a
				// leading one must be written twice to be read back.
				$text = $node->firstChild;
				if ($text instanceof Dom\Text && strncmp($text->data, "\n", 1) === 0) {
					$text->data = "\n" . $text->data;
				}
			}
		}
	}

	/** Clean one element's attributes; false when the element goes. */
	private function keepElement(Dom\Element $el): bool {
		$name = $el->localName;
		if ($el->namespaceURI !== self::HTML_NS || !isset(self::ELEMENTS[$name])) {
			return false;
		}
		$allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::ELEMENTS[$name]);
		foreach (iterator_to_array($el->attributes) as $attr) {
			if ($attr->namespaceURI !== null || !in_array($attr->localName, $allowed, true) || preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', $attr->value)) {
				$el->removeAttributeNode($attr);
			}
		}
		if ($el->hasAttribute('class')) {
			$classes = array_values(array_intersect(preg_split('~\s+~', trim((string)$el->getAttribute('class'))) ?: array(), self::CLASSES));
			if ($classes) {
				$el->setAttribute('class', implode(' ', array_unique($classes)));
			} else {
				$el->removeAttribute('class');
			}
		}
		if ($el->hasAttribute('autocomplete') && !in_array(strtolower((string)$el->getAttribute('autocomplete')), self::AUTOCOMPLETE, true)) {
			$el->removeAttribute('autocomplete');
		}
		switch ($name) {
			case 'a':
				if ($el->hasAttribute('href') && !self::linkAllowed($el->getAttribute('href'))) {
					$el->removeAttribute('href');
				}
				return true;
			case 'img':
				$src = $this->imageSource($el->getAttribute('src') ?? '');
				if ($src === null) {
					return false;
				}
				$el->setAttribute('src', $src);
				return true;
			case 'form':
				$method = strtolower(trim((string)$el->getAttribute('method'))) === 'post' ? 'post' : 'get';
				$el->setAttribute('action', (string)$this->options['page_url']);
				$el->setAttribute('method', $method);
				return true;
			case 'input':
				$type = strtolower(trim((string)($el->getAttribute('type') ?? 'text')));
				if ($type === '') {
					$type = 'text';
				}
				if (!in_array($type, self::INPUT_TYPES, true)) {
					return false;
				}
				$el->setAttribute('type', $type);
				return $this->nameAllowed($el);
			case 'select':
			case 'textarea':
			case 'button':
				return $this->nameAllowed($el);
		}
		return true;
	}

	/** A control's name: kept when plain, the element dropped when it is one of core's. */
	private function nameAllowed(Dom\Element $el): bool {
		if (!$el->hasAttribute('name')) {
			return true;
		}
		$name = (string)$el->getAttribute('name');
		if (in_array($name, self::RESERVED_NAMES, true)) {
			return false;
		}
		if (!preg_match('~^[A-Za-z][A-Za-z0-9_\[\]-]{0,63}$~D', $name)) {
			$el->removeAttribute('name');
		}
		return true;
	}

	/** Whether a link may stay: http, https, mailto or a relative address. */
	public static function linkAllowed(string $href): bool {
		// Browsers ignore tabs, newlines and leading spaces in a scheme
		// (java&#9;script:), so the check is on the address without them.
		$plain = preg_replace('~[\x00-\x20\x7F]+~', '', $href);
		if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', (string)$plain, $m)) {
			return in_array(strtolower($m[1]), array('http', 'https', 'mailto'), true);
		}
		// No scheme before the first '/', '?' or '#': relative.
		return !preg_match('~^[^/?#]*:~', (string)$plain);
	}

	/** What an image's src becomes, or null when the image is dropped. */
	private function imageSource(string $src): ?string {
		$src = trim($src);
		$prefix = (string)$this->options['asset_url'];
		// Already one of core's asset addresses (the second pass reads the first's).
		if ($prefix !== '' && strpos($src, $prefix) === 0 && preg_match('~^[A-Za-z0-9_.%\-]{1,600}$~D', substr($src, strlen($prefix)))) {
			return $src;
		}
		if (preg_match('~^(?:\./)?assets/([A-Za-z0-9_\-][A-Za-z0-9_.\-/]{0,199})$~D', $src, $m)
				&& strpos($m[1], '..') === false && strpos($m[1], '//') === false && (string)$this->options['asset_url'] !== '') {
			return (string)$this->options['asset_url'] . rawurlencode($m[1]);
		}
		$parts = parse_url($src);
		if (is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https' && !isset($parts['user']) && !isset($parts['pass'])
				&& (!isset($parts['port']) || (int)$parts['port'] === 443) && !preg_match('~[\x00-\x20\x7F\\\\]~', $src)
				&& in_array(strtolower((string)($parts['host'] ?? '')), (array)$this->options['image_hosts'], true)) {
			return $src;
		}
		return null;
	}
}
