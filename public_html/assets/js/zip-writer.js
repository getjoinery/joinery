/**
 * zip-writer.js — zip archives built in the browser, the twin of
 * includes/ZipStreamWriter.php.
 *
 * For what only the browser can read: a Fortress Drive folder (and, later, a
 * Fortress mailbox) is decrypted here and zipped here, so the server only ever
 * sends ciphertext (specs/export_mail_contacts_calendar_drive.md, P3 and Q3).
 *
 * Each file is handed over whole, so its checksum and sizes are known before
 * its header is written: no data descriptors. Compression is the browser's own
 * CompressionStream('deflate-raw'); without it, files are stored. Names are
 * UTF-8, times are UTC with an extended timestamp field, and ZIP64 is used for
 * any size or offset past 4 GiB.
 *
 *   var w = new JoineryZip.Writer({ write: function (u8) {...}, close: function () {...} });
 *   await w.addDirectory('Photos/', date);
 *   await w.addFile('Photos/a.jpg', bytes, { date: date });
 *   await w.finish();
 *
 * JoineryZip.Parts writes one archive as several whole zips of about
 * `partBytes` each (JoineryZip.PART_BYTES unless given), for a tab that cannot
 * hold the whole thing; each part extracts on its own into the same tree.
 *
 * @version 1.1 - JoineryZip.PART_BYTES, the part size a caller reads
 * @version 1.0
 */
(function (root) {
	'use strict';

	var MAX32 = 0xFFFFFFFF;
	var MAX16 = 0xFFFF;

	var STORED_EXTENSIONS = [
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif',
		'mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi',
		'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac',
		'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub',
		'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'jar', 'apk'
	];

	var CRC_TABLE = (function () {
		var t = new Uint32Array(256);
		for (var n = 0; n < 256; n++) {
			var c = n;
			for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
			t[n] = c >>> 0;
		}
		return t;
	})();

	function crc32(u8) {
		var c = 0xFFFFFFFF;
		for (var i = 0; i < u8.length; i++) c = CRC_TABLE[(c ^ u8[i]) & 0xFF] ^ (c >>> 8);
		return (c ^ 0xFFFFFFFF) >>> 0;
	}

	/** Should a file with this name and type be deflated? Already-compressed formats are stored. */
	function compressible(name, mime) {
		var m = /\.([^.\/]+)$/.exec(name || '');
		if (m && STORED_EXTENSIONS.indexOf(m[1].toLowerCase()) !== -1) return false;
		mime = (mime || '').toLowerCase();
		if (/^(image|video|audio)\//.test(mime)) {
			return mime === 'image/svg+xml' || mime === 'image/bmp' || mime === 'image/tiff';
		}
		return true;
	}

	/** One name as a single path segment: no separators, no control characters, never '.' or '..'. */
	function cleanName(name) {
		name = String(name == null ? '' : name).replace(/[\/\\]/g, '_').replace(/[\x00-\x1F\x7F]/g, '_').trim();
		return (name === '' || name === '.' || name === '..') ? '_' : name;
	}

	/** $name, or the name with ' (2)', ' (3)'… before its extension when a sibling holds it in any case. */
	function uniqueName(name, taken) {
		var candidate = name, n = 1;
		while (taken[candidate.toLowerCase()]) {
			n++;
			var dot = name.lastIndexOf('.');
			candidate = dot > 0 ? name.slice(0, dot) + ' (' + n + ')' + name.slice(dot) : name + ' (' + n + ')';
		}
		taken[candidate.toLowerCase()] = true;
		return candidate;
	}

	var encoder = new TextEncoder();

	function dosTime(date) {
		var d = date instanceof Date && !isNaN(date) ? date : new Date();
		var y = d.getUTCFullYear();
		if (y < 1980) { d = new Date(Date.UTC(1980, 0, 1)); y = 1980; }
		if (y > 2107) { d = new Date(Date.UTC(2107, 11, 31, 23, 59, 58)); y = 2107; }
		return {
			time: (d.getUTCHours() << 11) | (d.getUTCMinutes() << 5) | (d.getUTCSeconds() >> 1),
			date: ((y - 1980) << 9) | ((d.getUTCMonth() + 1) << 5) | d.getUTCDate(),
			unix: Math.floor(d.getTime() / 1000)
		};
	}

	// A little-endian byte builder.
	function Bytes() { this.parts = []; this.length = 0; }
	Bytes.prototype.u16 = function (v) { this.parts.push([2, v]); this.length += 2; return this; };
	Bytes.prototype.u32 = function (v) { this.parts.push([4, v]); this.length += 4; return this; };
	Bytes.prototype.u64 = function (v) { this.parts.push([8, v]); this.length += 8; return this; };
	Bytes.prototype.u8 = function (v) { this.parts.push([1, v]); this.length += 1; return this; };
	Bytes.prototype.raw = function (u8) { this.parts.push([0, u8]); this.length += u8.length; return this; };
	Bytes.prototype.done = function () {
		var out = new Uint8Array(this.length), view = new DataView(out.buffer), at = 0;
		this.parts.forEach(function (p) {
			if (p[0] === 0) { out.set(p[1], at); at += p[1].length; return; }
			if (p[0] === 1) view.setUint8(at, p[1]);
			else if (p[0] === 2) view.setUint16(at, p[1], true);
			else if (p[0] === 4) view.setUint32(at, p[1] >>> 0, true);
			else { view.setUint32(at, p[1] % 4294967296, true); view.setUint32(at + 4, Math.floor(p[1] / 4294967296), true); }
			at += p[0];
		});
		return out;
	};

	async function toBytes(data) {
		if (data instanceof Uint8Array) return data;
		if (data instanceof ArrayBuffer) return new Uint8Array(data);
		if (typeof Blob !== 'undefined' && data instanceof Blob) return new Uint8Array(await data.arrayBuffer());
		if (typeof data === 'string') return encoder.encode(data);
		throw new Error('zip-writer: unsupported file data');
	}

	async function deflateRaw(u8) {
		if (typeof CompressionStream === 'undefined') return null;
		var stream = new Blob([u8]).stream().pipeThrough(new CompressionStream('deflate-raw'));
		return new Uint8Array(await new Response(stream).arrayBuffer());
	}

	/**
	 * Everything one file entry needs, worked out before any of it is written
	 * (so JoineryZip.Parts can tell whether it fits the current part).
	 */
	async function prepareFile(path, data, opts) {
		opts = opts || {};
		var body = await toBytes(data);
		var crc = crc32(body);
		var method = 0, stored = body;
		var compress = opts.compress != null ? !!opts.compress : compressible(path, opts.mime);
		if (compress && body.length > 0) {
			var deflated = await deflateRaw(body);
			if (deflated && deflated.length < body.length) { method = 8; stored = deflated; }
		}
		var name = encoder.encode(path);
		return {
			name: name, dir: false, method: method, crc: crc, size: body.length, csize: stored.length,
			body: stored, dos: dosTime(opts.date), external: (0x81A4 << 16) >>> 0,
			total: 30 + name.length + 9 + 20 + stored.length
		};
	}

	function prepareDirectory(path, date) {
		if (path.slice(-1) !== '/') path += '/';
		var name = encoder.encode(path);
		return {
			name: name, dir: true, method: 0, crc: 0, size: 0, csize: 0, body: new Uint8Array(0),
			dos: dosTime(date), external: ((0x41ED << 16) | 0x10) >>> 0, total: 30 + name.length + 9
		};
	}

	function Writer(sink, options) {
		this.sink = sink;
		this.offset = 0;
		this.central = [];
		this.finished = false;
		this.forceZip64 = !!(options && options.forceZip64);
	}

	Writer.prototype.entryCount = function () { return this.central.length; };

	Writer.prototype._emit = async function (u8) {
		this.offset += u8.length;
		await this.sink.write(u8);
	};

	Writer.prototype.addDirectory = function (path, date) {
		return this.writePrepared(prepareDirectory(path, date));
	};

	Writer.prototype.addFile = async function (path, data, opts) {
		return this.writePrepared(await prepareFile(path, data, opts));
	};

	Writer.prototype.writePrepared = async function (e) {
		if (this.finished) throw new Error('zip-writer: the archive is finished');
		var zip64 = this.forceZip64 || e.size >= MAX32 || e.csize >= MAX32;
		e.offset = this.offset;
		e.zip64 = zip64;
		var extra = new Bytes().u16(0x5455).u16(5).u8(1).u32(e.dos.unix);
		if (zip64) extra.u16(0x0001).u16(16).u64(e.size).u64(e.csize);
		var extraBytes = extra.done();
		var head = new Bytes()
			.u32(0x04034b50).u16(zip64 ? 45 : 20).u16(0x0800).u16(e.method)
			.u16(e.dos.time).u16(e.dos.date).u32(e.crc)
			.u32(zip64 ? MAX32 : e.csize).u32(zip64 ? MAX32 : e.size)
			.u16(e.name.length).u16(extraBytes.length).raw(e.name).raw(extraBytes).done();
		await this._emit(head);
		if (e.body.length) await this._emit(e.body);
		e.body = null; // only the directory record is kept
		this.central.push(e);
	};

	Writer.prototype.finish = async function () {
		if (this.finished) return this.offset;
		var self = this, cdOffset = this.offset;
		for (var i = 0; i < this.central.length; i++) {
			var e = this.central[i];
			var bigOffset = e.offset >= MAX32 || self.forceZip64;
			var x64 = new Bytes();
			if (e.zip64) x64.u64(e.size).u64(e.csize);
			if (bigOffset) x64.u64(e.offset);
			var extra = new Bytes().u16(0x5455).u16(5).u8(1).u32(e.dos.unix);
			if (x64.length) extra.u16(0x0001).u16(x64.length).raw(x64.done());
			var extraBytes = extra.done();
			var version = (e.zip64 || bigOffset) ? 45 : 20;
			await self._emit(new Bytes()
				.u32(0x02014b50).u16((3 << 8) | version).u16(version).u16(0x0800).u16(e.method)
				.u16(e.dos.time).u16(e.dos.date).u32(e.crc)
				.u32(e.zip64 ? MAX32 : e.csize).u32(e.zip64 ? MAX32 : e.size)
				.u16(e.name.length).u16(extraBytes.length).u16(0).u16(0).u16(0)
				.u32(e.external).u32(bigOffset ? MAX32 : e.offset)
				.raw(e.name).raw(extraBytes).done());
		}
		var cdSize = this.offset - cdOffset, count = this.central.length;
		if (this.forceZip64 || count > MAX16 || cdOffset >= MAX32 || cdSize >= MAX32) {
			var end64 = this.offset;
			await this._emit(new Bytes().u32(0x06064b50).u64(44).u16((3 << 8) | 45).u16(45).u32(0).u32(0)
				.u64(count).u64(count).u64(cdSize).u64(cdOffset).done());
			await this._emit(new Bytes().u32(0x07064b50).u32(0).u64(end64).u32(1).done());
		}
		await this._emit(new Bytes().u32(0x06054b50).u16(0).u16(0)
			.u16(Math.min(count, MAX16)).u16(Math.min(count, MAX16))
			.u32(Math.min(cdSize, MAX32)).u32(Math.min(cdOffset, MAX32)).u16(0).done());
		this.finished = true;
		return this.offset;
	};

	/**
	 * One archive as several whole zips. `openPart(n)` (1-based) returns a sink
	 * ({write, close}) for part n; a part is closed when the next file would
	 * take it past `partBytes` (a single larger file gets a part to itself).
	 */
	function Parts(options) {
		this.partBytes = options.partBytes || api.PART_BYTES;
		this.openPart = options.openPart;
		this.writerOptions = options.writerOptions || null;
		this.index = 0;
		this.writer = null;
		this.sink = null;
	}

	Parts.prototype._ensure = async function () {
		if (this.writer) return;
		this.index++;
		this.sink = await this.openPart(this.index);
		this.writer = new Writer(this.sink, this.writerOptions);
	};

	Parts.prototype._close = async function () {
		await this.writer.finish();
		if (this.sink.close) await this.sink.close();
		this.writer = null;
	};

	Parts.prototype.addDirectory = async function (path, date) {
		await this._ensure();
		await this.writer.addDirectory(path, date);
	};

	Parts.prototype.addFile = async function (path, data, opts) {
		var e = await prepareFile(path, data, opts);
		await this._ensure();
		if (this.writer.entryCount() > 0 && this.writer.offset + e.total > this.partBytes) {
			await this._close();
			await this._ensure();
		}
		await this.writer.writePrepared(e);
	};

	/** Close the last part. Returns how many parts there are. */
	Parts.prototype.finish = async function () {
		await this._ensure();
		await this._close();
		return this.index;
	};

	var api = {
		/** About how big each part of a zip held in a tab may grow. */
		PART_BYTES: 500 * 1024 * 1024,
		Writer: Writer,
		Parts: Parts,
		crc32: crc32,
		compressible: compressible,
		cleanName: cleanName,
		uniqueName: uniqueName
	};
	if (typeof module !== 'undefined' && module.exports) module.exports = api;
	root.JoineryZip = api;
})(typeof window !== 'undefined' ? window : globalThis);
