/**
 * file-thumb.js — degrade a missing thumbnail to a file-type icon.
 *
 * Any <img data-thumb-fallback="/path/icon.svg"> that fails to load swaps to
 * that icon instead of showing the browser's broken-image glyph. Produced by
 * File::thumbnail_html(), so a listing gets this without doing anything.
 *
 * A thumbnail can be absent for several unrelated reasons — the size was never
 * generated and the bytes now live in a bucket, the original is gone, the type
 * isn't one the resizer decodes. None of them are worth a per-row server check,
 * and all of them look the same to a browser, so the failure is handled where
 * it actually surfaces.
 *
 * Delegated from the document with a capturing listener because 'error' does
 * not bubble, and registered here rather than as an inline onerror so the page
 * stays clean under a script-src Content-Security-Policy.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	document.addEventListener('error', function (e) {
		var img = e.target;
		if (!img || img.tagName !== 'IMG') {
			return;
		}

		var fallback = img.getAttribute('data-thumb-fallback');
		if (!fallback) {
			return;
		}

		// Consume the attribute before swapping: if the icon itself fails to
		// load, this must not fire again and reassign the same src forever.
		img.removeAttribute('data-thumb-fallback');
		img.classList.add('jy-thumb-icon');
		img.src = fallback;
	}, true);
})();
