/**
 * DriveCrypto — the Drive-specific content-encryption layer on top of the Sealed
 * Vault's shared client-custody module (assets/js/vault-crypto.js).
 *
 * The vault (VaultCrypto + VaultKeyring, scope 'drive') owns the identity: the
 * per-user X25519 keypair and its unlockers. DriveCrypto adds only what Drive
 * needs on top — per-file content keys, chunked authenticated encryption of file
 * bytes, the encrypted per-file metadata blob, the encrypted thumbnail, and the
 * seal/open of a file key to a user's vault public key for sharing.
 *
 * Zero-knowledge end to end: a file key (FK) and every plaintext live only here,
 * in the browser. The server stores and streams opaque ciphertext. This module
 * never fetch()es — callers move ciphertext; DriveCrypto only transforms bytes.
 *
 * Ciphertext container (self-delimiting, so decryption needs no size metadata):
 *   repeated per plaintext chunk:  uint32be(blockLen) || IV[12] || AES-GCM(ct+tag)
 *   where blockLen = 12 + ciphertextLength, AAD = utf8(contentId + ':' + index).
 * The random per-file FK plus the per-chunk AAD mean a chunk can neither be
 * reordered within a file nor transplanted to another (different FK, different
 * contentId).
 *
 * Depends on VaultCrypto (assets/js/vault-crypto.js).
 *
 * @version 1.1
 */
window.DriveCrypto = (function () {
	'use strict';

	var subtle = window.crypto && window.crypto.subtle;

	// Plaintext chunk size. Aligned with the Drive upload chunk size (the spec's
	// open decision — 4 MiB); independent of the transport chunk size (the
	// upload protocol re-slices the finished ciphertext blob for PUTs).
	var CHUNK_BYTES = 4 * 1024 * 1024;

	// Thumbnail longest edge, in px. Small — it is a preview, encrypted per file.
	var THUMB_MAX = 256;

	function enc() { return VaultCrypto; }
	function utf8(s) { return new TextEncoder().encode(s); }

	function randomHex(n) {
		var b = new Uint8Array(n);
		window.crypto.getRandomValues(b);
		var s = '';
		for (var i = 0; i < b.length; i++) s += b[i].toString(16).padStart(2, '0');
		return s;
	}

	function concat(list) {
		var total = 0, i;
		for (i = 0; i < list.length; i++) total += list[i].length;
		var out = new Uint8Array(total), off = 0;
		for (i = 0; i < list.length; i++) { out.set(list[i], off); off += list[i].length; }
		return out;
	}

	function uint32be(n) {
		var b = new Uint8Array(4);
		b[0] = (n >>> 24) & 0xff; b[1] = (n >>> 16) & 0xff; b[2] = (n >>> 8) & 0xff; b[3] = n & 0xff;
		return b;
	}

	function chunkAad(contentId, index) { return utf8(contentId + ':' + index); }

	// A fresh random 256-bit file key. Returns { fkBytes, fkKey }: fkBytes to seal
	// to a public key (a FileKeyGrant), fkKey the AES-GCM CryptoKey for content.
	function newFileKey() {
		var fkBytes = enc().randomBytes(32);
		return enc().importDek(fkBytes).then(function (fkKey) {
			return { fkBytes: fkBytes, fkKey: fkKey };
		});
	}

	function importFileKey(fkBytes) { return enc().importDek(fkBytes); }

	// ---- content ------------------------------------------------------------

	/**
	 * Encrypt a File/Blob into the self-delimiting ciphertext container.
	 * Returns { blob, fkBytes, fkKey, contentId, meta, thumbB64 }:
	 *   blob      — the ciphertext Blob (feed straight into the upload protocol)
	 *   fkBytes   — raw file key (seal to each reader's public key, then discard)
	 *   contentId — random per-file id bound into every chunk's AAD
	 *   meta      — the metadata object to encrypt (name/mime/size/cid/thumb)
	 *   thumbB64  — base64 ciphertext thumbnail, or null (non-image / failure)
	 *
	 * encryptFile mints a fresh key + content id (a NEW file). A new VERSION of
	 * an existing encrypted file must reuse both — every FileKeyGrant wraps the
	 * original key, and prior versions must stay decryptable — so it goes
	 * through encryptFileWith(file, fkBytes, contentId); the server refuses a
	 * version upload that carries a new wrapped key.
	 */
	async function encryptFile(file) {
		var keypair = await newFileKey();
		return _encryptWith(file, keypair.fkBytes, keypair.fkKey, randomHex(16));
	}

	async function encryptFileWith(file, fkBytes, contentId) {
		var fkKey = await importFileKey(fkBytes);
		return _encryptWith(file, fkBytes, fkKey, String(contentId));
	}

	async function _encryptWith(file, fkBytes, fkKey, contentId) {
		var size = file.size;

		var parts = [];
		var index = 0;
		for (var offset = 0; offset < size || (size === 0 && index === 0); offset += CHUNK_BYTES) {
			var end = Math.min(offset + CHUNK_BYTES, size);
			var buf = await file.slice(offset, end).arrayBuffer();
			var iv = enc().randomBytes(12);
			var ct = await subtle.encrypt(
				{ name: 'AES-GCM', iv: iv, additionalData: chunkAad(contentId, index) },
				fkKey, buf
			);
			var block = concat([iv, new Uint8Array(ct)]);
			parts.push(uint32be(block.length));
			parts.push(block);
			index++;
			if (size === 0) break; // a single empty chunk for a 0-byte file
		}

		var thumbB64 = await maybeThumbnail(file, fkKey, contentId);

		var meta = {
			v: 1,
			name: file.name,
			mime: file.type || 'application/octet-stream',
			size: size,
			cid: contentId,
			chunk: CHUNK_BYTES,
			thumb: !!thumbB64
		};

		return {
			blob: new Blob(parts, { type: 'application/octet-stream' }),
			fkBytes: fkBytes,
			fkKey: fkKey,
			contentId: contentId,
			meta: meta,
			thumbB64: thumbB64
		};
	}

	/**
	 * Decrypt a ciphertext container (ArrayBuffer) back to plaintext bytes.
	 * Walks the self-delimiting blocks; the AAD re-derives from contentId + index,
	 * so a reordered or transplanted chunk fails its GCM tag.
	 */
	async function decryptContent(cipherBuf, fkKey, contentId) {
		var u8 = new Uint8Array(cipherBuf);
		var view = new DataView(cipherBuf);
		var out = [];
		var pos = 0, index = 0;
		while (pos < u8.length) {
			var len = view.getUint32(pos); pos += 4;
			var block = u8.subarray(pos, pos + len); pos += len;
			var iv = block.subarray(0, 12);
			var ct = block.subarray(12);
			var pt = await subtle.decrypt(
				{ name: 'AES-GCM', iv: iv, additionalData: chunkAad(contentId, index) },
				fkKey, ct
			);
			out.push(new Uint8Array(pt));
			index++;
		}
		return concat(out);
	}

	// ---- metadata -----------------------------------------------------------

	// The metadata blob is VaultCrypto's plain content contract (IV||ct, no AAD):
	// a per-file unique FK already binds it to this file.
	function encryptMetadata(metaObj, fkKey) { return enc().encrypt(JSON.stringify(metaObj), fkKey); }
	async function decryptMetadata(blob, fkKey) { return JSON.parse(await enc().decrypt(blob, fkKey)); }

	// ---- thumbnail ----------------------------------------------------------

	async function maybeThumbnail(file, fkKey, contentId) {
		var mime = file.type || '';
		if (mime.indexOf('image/') !== 0) return null;
		try {
			var bitmap = await createImageBitmap(file);
			var scale = Math.min(1, THUMB_MAX / Math.max(bitmap.width, bitmap.height));
			var w = Math.max(1, Math.round(bitmap.width * scale));
			var h = Math.max(1, Math.round(bitmap.height * scale));
			var canvas = document.createElement('canvas');
			canvas.width = w; canvas.height = h;
			canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h);
			if (bitmap.close) bitmap.close();
			var blob = await new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.7); });
			if (!blob) return null;
			var buf = await blob.arrayBuffer();
			var iv = enc().randomBytes(12);
			var ct = await subtle.encrypt(
				{ name: 'AES-GCM', iv: iv, additionalData: utf8(contentId + ':thumb') },
				fkKey, buf
			);
			return enc().b64encode(concat([iv, new Uint8Array(ct)]));
		} catch (e) {
			return null; // a thumbnail is best-effort; the file is still encrypted
		}
	}

	// Takes the raw ciphertext bytes (Uint8Array/ArrayBuffer) as fetched from the
	// thumb signed URL — the server stores the decoded bytes, so base64 never
	// appears on the read path.
	async function decryptThumbnail(bytes, fkKey, contentId) {
		var raw = (bytes instanceof Uint8Array) ? bytes : new Uint8Array(bytes);
		var iv = raw.slice(0, 12), ct = raw.slice(12);
		var pt = await subtle.decrypt(
			{ name: 'AES-GCM', iv: iv, additionalData: utf8(contentId + ':thumb') },
			fkKey, ct
		);
		return new Blob([pt], { type: 'image/jpeg' });
	}

	// ---- sharing: seal / open a file key to a vault public key --------------

	// Seal the raw file key to a recipient's drive vault public key (a
	// FileKeyGrant's wrapped_file_key). ECIES over X25519 — anyone can seal.
	function wrapFileKeyTo(fkBytes, recipientPublicKeyB64) {
		return enc().sealToPublicKey(fkBytes, recipientPublicKeyB64);
	}

	// Open a wrapped file key with the unlocked drive vault session, returning raw
	// FK bytes; then importFileKey() for content ops.
	function openWrappedFileKey(session, wrappedBlob) {
		return session.openSealed(wrappedBlob); // resolves to Uint8Array FK bytes
	}

	return {
		CHUNK_BYTES: CHUNK_BYTES,
		THUMB_MAX: THUMB_MAX,
		isSupported: function () { return enc().isSupported(); },
		newFileKey: newFileKey,
		importFileKey: importFileKey,
		encryptFile: encryptFile,
		encryptFileWith: encryptFileWith,
		decryptContent: decryptContent,
		encryptMetadata: encryptMetadata,
		decryptMetadata: decryptMetadata,
		decryptThumbnail: decryptThumbnail,
		wrapFileKeyTo: wrapFileKeyTo,
		openWrappedFileKey: openWrappedFileKey
	};
})();
