/**
 * A browser's windows-1252 for Node's TextDecoder.
 *
 * Node resolves every label the WHATWG Encoding Standard maps to
 * windows-1252 (windows-1252, latin1, iso-8859-1, us-ascii, ascii, cp1252 and
 * the rest) to that encoding, but decodes bytes 0x80-0x9F as the C1 controls
 * U+0080-U+009F, as ISO-8859-1 does. A browser uses the WHATWG index there:
 * 0x80 is the euro sign, 0x85 an ellipsis, 0x96 an en dash. The browser
 * modules the node gates run (mailbox_mime.js, email-digest.js) decode with
 * TextDecoder, so a gate without this pins what Node does, not what the
 * owner's browser does. Load it before those modules; it replaces
 * globalThis.TextDecoder with a subclass that decodes windows-1252 with the
 * WHATWG index and leaves every other encoding to Node.
 *
 * @version 1.0
 */
(function () {
	'use strict';
	var Native = globalThis.TextDecoder;
	if (!Native || Native.__whatwg1252) return;

	// WHATWG index-windows-1252, pointers 0-31 (bytes 0x80-0x9F).
	var HIGH = [0x20AC, 0x0081, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0160, 0x2039,
		0x0152, 0x008D, 0x017D, 0x008F, 0x0090, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014,
		0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x009D, 0x017E, 0x0178];

	function bytesOf(input) {
		if (input === undefined || input === null) return new Uint8Array(0);
		if (input instanceof ArrayBuffer) return new Uint8Array(input);
		if (ArrayBuffer.isView(input)) return new Uint8Array(input.buffer, input.byteOffset, input.byteLength);
		throw new TypeError('The "input" argument must be an ArrayBuffer or ArrayBufferView');
	}

	class WhatwgTextDecoder extends Native {
		decode(input, options) {
			if (this.encoding !== 'windows-1252') return super.decode(input, options);
			var b = bytesOf(input);
			var out = '';
			for (var i = 0; i < b.length; i += 0x2000) {
				var n = Math.min(b.length, i + 0x2000);
				var codes = new Array(n - i);
				for (var k = i; k < n; k++) codes[k - i] = (b[k] >= 0x80 && b[k] <= 0x9F) ? HIGH[b[k] - 0x80] : b[k];
				out += String.fromCharCode.apply(null, codes);
			}
			return out;
		}
	}
	WhatwgTextDecoder.__whatwg1252 = true;
	globalThis.TextDecoder = WhatwgTextDecoder;
})();
