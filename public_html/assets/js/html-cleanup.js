/**
 * Joinery HTML cleanup — the "basic editing" tidy for prose fields.
 *
 * One routine, cleanup(html) → html, driven by the RULES table below. It is
 * what the editor's Clean up button runs and what `editor_cleanup: always`
 * applies on load, paste and submit. It runs in the browser only and is an
 * editing convenience for admin authors, not a security control: the platform
 * has no member-facing rich-text field, and one that ships later needs a
 * server-side sanitizer, which is a different tool.
 *
 * Passes run in a fixed order — drop, rename, unwrap, attributes, structure —
 * so each pass sees only tags the earlier passes allowed. The rule table is
 * data: a future profile is another table, not another routine.
 *
 * Loaded on its own by tests/fixtures/joinery_editor/runner.html, so it must
 * not depend on joinery-editor.js.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var RULES = {
		/* Tags that survive, with the attributes each keeps. An empty list
		 * means none, except `style` reduced to text-align (styleKeep). */
		keep: {
			p: [], br: [], h1: [], h2: [], h3: [], h4: [], h5: [], h6: [],
			blockquote: [], pre: [], code: [], strong: [], em: [], del: [],
			sub: [], sup: [], ul: [], ol: [], li: [], hr: [],
			table: [], thead: [], tbody: [], tr: [], th: [], td: [],
			a: ['href', 'title', 'target', 'rel'],
			img: ['src', 'alt', 'width', 'height'],
			iframe: ['src', 'width', 'height', 'allow', 'allowfullscreen'],
			video: ['src', 'controls', 'width', 'height', 'poster']
		},
		/* The only CSS property a kept tag may carry in its style attribute. */
		styleKeep: ['text-align'],
		/* Tag renames; a renamed tag then falls under keep by its new name. */
		rename: { b: 'strong', i: 'em', s: 'del', strike: 'del', div: 'p' },
		/* Removed with their contents. Comments are dropped alongside. */
		drop: ['script', 'style', 'head', 'meta', 'link', 'title', 'object',
			'form', 'input', 'button', 'textarea', 'select', 'template'],
		/* Kept tags that are inline: these get wrapped in p at top level,
		 * removed when empty, and unwrapped when nested in themselves. */
		inline: ['strong', 'em', 'del', 'sub', 'sup', 'a', 'code', 'img', 'br',
			'iframe', 'video'],
		/* Elements whose presence keeps an otherwise text-less p or inline
		 * element alive. */
		content: ['img', 'br', 'hr', 'iframe', 'video'],
		/* Attributes carrying a URL, and the schemes they may not use. */
		urlAttributes: ['href', 'src'],
		badScheme: /^\s*(javascript|data)\s*:/i
	};

	var INLINE_SET = toSet(RULES.inline);
	var CONTENT_SELECTOR = RULES.content.join(',');

	function toSet(list) {
		var set = {};
		list.forEach(function (name) { set[name] = true; });
		return set;
	}

	function tag(el) {
		return el.localName;
	}

	function inPre(el) {
		var p = el.parentElement;
		while (p) {
			if (tag(p) === 'pre') return true;
			p = p.parentElement;
		}
		return false;
	}

	/** Every element under root, in document order, as a plain array. */
	function elements(root) {
		return Array.prototype.slice.call(root.querySelectorAll('*'));
	}

	function unwrap(el) {
		var parent = el.parentNode;
		if (!parent) return;
		while (el.firstChild) parent.insertBefore(el.firstChild, el);
		parent.removeChild(el);
	}

	function rename(el, name) {
		var fresh = el.ownerDocument.createElement(name);
		Array.prototype.slice.call(el.attributes).forEach(function (attr) {
			fresh.setAttribute(attr.name, attr.value);
		});
		while (el.firstChild) fresh.appendChild(el.firstChild);
		el.parentNode.replaceChild(fresh, el);
		return fresh;
	}

	/* ---------------------------------------------------------------- 1 drop */

	function passDrop(body) {
		var doc = body.ownerDocument;
		body.querySelectorAll(RULES.drop.join(',')).forEach(function (el) {
			if (el.parentNode) el.parentNode.removeChild(el);
		});
		var walker = doc.createNodeIterator(body, 128 /* SHOW_COMMENT */);
		var node, comments = [];
		while ((node = walker.nextNode())) comments.push(node);
		comments.forEach(function (c) { c.parentNode.removeChild(c); });
	}

	/* -------------------------------------------------------------- 2 rename */

	function passRename(body) {
		elements(body).forEach(function (el) {
			var to = RULES.rename[tag(el)];
			if (to && !inPre(el)) rename(el, to);
		});
	}

	/* -------------------------------------------------------------- 3 unwrap */

	function passUnwrap(body) {
		elements(body).forEach(function (el) {
			if (!el.parentNode) return;
			if (inPre(el)) return;
			if (!Object.prototype.hasOwnProperty.call(RULES.keep, tag(el))) unwrap(el);
		});
	}

	/* ---------------------------------------------------------- 4 attributes */

	function reducedStyle(value) {
		var out = [];
		value.split(';').forEach(function (decl) {
			var idx = decl.indexOf(':');
			if (idx === -1) return;
			var prop = decl.slice(0, idx).trim().toLowerCase();
			var val = decl.slice(idx + 1).trim();
			if (RULES.styleKeep.indexOf(prop) !== -1 && val !== '') out.push(prop + ': ' + val);
		});
		return out.join('; ');
	}

	function passAttributes(body) {
		elements(body).forEach(function (el) {
			if (inPre(el)) return;
			var allowed = RULES.keep[tag(el)] || [];
			Array.prototype.slice.call(el.attributes).forEach(function (attr) {
				var name = attr.name.toLowerCase();
				if (allowed.indexOf(name) !== -1) {
					if (RULES.urlAttributes.indexOf(name) !== -1 && RULES.badScheme.test(attr.value)) {
						el.removeAttribute(attr.name);
					}
					return;
				}
				if (name === 'style' && allowed.length === 0) {
					var kept = reducedStyle(attr.value);
					if (kept === '') el.removeAttribute(attr.name);
					else el.setAttribute('style', kept);
					return;
				}
				el.removeAttribute(attr.name);
			});
		});
	}

	/* ----------------------------------------------------------- 5 structure */

	function isBlockElement(node) {
		return node.nodeType === 1 && !INLINE_SET[tag(node)];
	}

	function isInlineNode(node) {
		if (node.nodeType === 3) return true;
		return node.nodeType === 1 && !!INLINE_SET[tag(node)];
	}

	/** A p holding a block is unwrapped, until no p holds a block. */
	function unwrapBlockHoldingParagraphs(body) {
		var changed = true, guard = 0;
		while (changed && guard++ < 50) {
			changed = false;
			elements(body).forEach(function (el) {
				if (tag(el) !== 'p' || !el.parentNode || inPre(el)) return;
				for (var i = 0; i < el.childNodes.length; i++) {
					if (isBlockElement(el.childNodes[i])) {
						unwrap(el);
						changed = true;
						return;
					}
				}
			});
		}
	}

	/** Loose text and inline runs directly under root are wrapped in p. */
	function wrapTopLevelRuns(root) {
		var doc = root.ownerDocument;
		var run = [];
		var nodes = Array.prototype.slice.call(root.childNodes);

		function flush() {
			while (run.length && run[run.length - 1].nodeType === 3 && run[run.length - 1].nodeValue.trim() === '') {
				root.removeChild(run.pop());
			}
			if (run.length) {
				var p = doc.createElement('p');
				root.insertBefore(p, run[0]);
				run.forEach(function (n) { p.appendChild(n); });
			}
			run = [];
		}

		nodes.forEach(function (node) {
			if (node.nodeType === 3 && node.nodeValue.trim() === '') {
				// Whitespace between blocks is layout, not content; inside a
				// run it is kept, at the edges it is dropped.
				if (run.length) run.push(node); else root.removeChild(node);
				return;
			}
			if (isInlineNode(node)) { run.push(node); return; }
			flush();
		});
		flush();
	}

	/** Collapse nbsp runs to a space in every text node outside pre. */
	function collapseNbsp(body) {
		var walker = body.ownerDocument.createNodeIterator(body, 4 /* SHOW_TEXT */);
		var node;
		while ((node = walker.nextNode())) {
			if (node.parentElement && (tag(node.parentElement) === 'pre' || inPre(node.parentElement))) continue;
			if (node.nodeValue.indexOf(' ') !== -1) {
				node.nodeValue = node.nodeValue.replace(/ +/g, ' ');
			}
		}
	}

	/** p and inline elements with no text and nothing that counts as content. */
	function removeEmpties(body) {
		elements(body).reverse().forEach(function (el) {
			var name = tag(el);
			if (!el.parentNode || inPre(el)) return;
			if (name !== 'p' && !(INLINE_SET[name] && RULES.content.indexOf(name) === -1)) return;
			if (el.textContent.trim() !== '') return;
			if (el.querySelector(CONTENT_SELECTOR)) return;
			el.parentNode.removeChild(el);
		});
	}

	/** strong inside strong, em inside em: the inner one goes. */
	function unwrapSameTagNesting(body) {
		elements(body).forEach(function (el) {
			var name = tag(el);
			if (!el.parentNode || !INLINE_SET[name] || inPre(el)) return;
			if (RULES.content.indexOf(name) !== -1) return;
			var ancestor = el.parentElement;
			while (ancestor && ancestor !== body) {
				if (tag(ancestor) === name) { unwrap(el); return; }
				ancestor = ancestor.parentElement;
			}
		});
	}

	function passStructure(body) {
		unwrapBlockHoldingParagraphs(body);
		wrapTopLevelRuns(body);
		collapseNbsp(body);
		unwrapSameTagNesting(body);
		removeEmpties(body);
	}

	/* ------------------------------------------------------------------ api */

	/**
	 * Clean a fragment of HTML in place: $root is an element whose children
	 * are the document (the editor's surface, or a parsed body).
	 */
	function cleanupNode(root) {
		passDrop(root);
		passRename(root);
		passUnwrap(root);
		passAttributes(root);
		passStructure(root);
		return root;
	}

	/** cleanup(html) → html. Parsing is inert: nothing loads or runs. */
	function cleanup(html) {
		var doc = new DOMParser().parseFromString('<!doctype html><body>' + String(html), 'text/html');
		cleanupNode(doc.body);
		return doc.body.innerHTML;
	}

	window.joineryHtmlCleanup = { RULES: RULES, cleanup: cleanup, cleanupNode: cleanupNode };
})();
