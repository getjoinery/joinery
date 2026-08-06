<?php
/**
 * SealedFileContainer — chunked authenticated encryption for whole files, read
 * and written a chunk at a time.
 *
 * This is the platform's large-blob sealing primitive. It exists because the
 * Sealed Vault's column helpers (VaultCrypto::sealField/openField) are the wrong
 * tool for file bytes: they are whole-string, base64url (~1.34x size), and hold
 * the entire plaintext in memory. Mail survives that under a 25 MB message cap.
 * A Drive file does not.
 *
 * FORMAT — the browser's Fortress container (assets/js/drive-crypto.js), with a
 * header in front. The chunk framing is byte-for-byte the same scheme, so both
 * custody modes share one set of overhead math (DriveHelper::encrypted_size_ceiling)
 * and one mental model:
 *
 *   header:  "JSFC" | uint8 version | uint8 flags | uint8 cid_len
 *            | uint32be plaintext_chunk_bytes | cid_len bytes of content id
 *   chunks:  repeated, one per plaintext chunk:
 *            uint32be block_len | IV[12] | AES-256-GCM(ciphertext || tag[16])
 *            where block_len = 12 + strlen(ciphertext||tag)
 *            and AAD = "{content_id}:{chunk_index}"
 *
 * The browser writes no header — its content id and chunk size live in the
 * FK-encrypted metadata blob it also uploads. A server-custody file has no such
 * blob (its name, size and type stay plaintext), so the container carries those
 * two facts itself. Everything after the header is what the browser produces,
 * which is what makes the cross-check in tests/vault/sealed_file_container_test.php
 * possible: PHP opens a browser-made body given its content id and chunk size.
 *
 * WHY THE AAD — a chunk cannot be reordered inside its file (the index is bound
 * in) nor transplanted into another file (the content id is). Tampering with any
 * chunk fails its GCM tag, and a failure is an exception, never a short read.
 *
 * SEEKING — every chunk but the last holds exactly one full plaintext chunk, so
 * a block is always plaintext_chunk_bytes + 32 bytes on disk. The ciphertext
 * offset of chunk i is therefore header + i * (chunk + 32), computed rather than
 * scanned: a Range request for the tail of a 2 GB video reads two chunks, not
 * two gigabytes. The length prefix at that offset is still validated, so a
 * truncated or corrupt container raises instead of decrypting garbage.
 *
 * The key: a 32-byte per-file key (VaultCrypto::newItemDek()), wrapped to the
 * owner's vault public key by the caller. This class never sees a vault, a
 * window, or a user — it takes raw key bytes and moves bytes.
 *
 * @version 1.0.0
 */
class SealedFileContainerException extends RuntimeException {}

class SealedFileContainer {

	const MAGIC        = 'JSFC';
	const VERSION      = 1;
	const KEY_BYTES    = 32;
	const IV_BYTES     = 12;
	const TAG_BYTES    = 16;
	const LEN_BYTES    = 4;   // the uint32be block-length prefix

	/** Plaintext bytes per chunk. Must match DriveCrypto.CHUNK_BYTES. */
	const CHUNK_BYTES  = 4194304; // 4 MiB

	/** Per-chunk on-disk overhead: length prefix + IV + GCM tag. */
	const CHUNK_OVERHEAD = self::LEN_BYTES + self::IV_BYTES + self::TAG_BYTES; // 32

	const CIPHER = 'aes-256-gcm';

	// ------------------------------------------------------------------
	// Write
	// ------------------------------------------------------------------

	/**
	 * Seal $src_path into $dest_path under $fk, one chunk at a time.
	 *
	 * A 0-byte source is sealed as a single empty chunk (the browser does the
	 * same), so an empty file is still an authenticated container rather than an
	 * empty one that any writer could forge.
	 *
	 * @param string      $src_path   plaintext bytes to read
	 * @param string      $dest_path  container to write (truncated if it exists)
	 * @param string      $fk         raw 32-byte file key
	 * @param string|null $content_id hex id bound into every chunk's AAD; minted
	 *                                when omitted
	 * @return array{content_id:string, plain_size:int, cipher_size:int, chunk_bytes:int}
	 * @throws SealedFileContainerException
	 */
	public static function sealStream($src_path, $dest_path, $fk, $content_id = null) {
		self::assertKey($fk);
		$content_id = ($content_id === null) ? bin2hex(random_bytes(16)) : (string)$content_id;
		self::assertContentId($content_id);

		$in = @fopen($src_path, 'rb');
		if ($in === false) {
			throw new SealedFileContainerException('sealStream: cannot read ' . $src_path);
		}
		$out = @fopen($dest_path, 'wb');
		if ($out === false) {
			fclose($in);
			throw new SealedFileContainerException('sealStream: cannot write ' . $dest_path);
		}

		try {
			$header = self::buildHeader($content_id, self::CHUNK_BYTES);
			if (fwrite($out, $header) !== strlen($header)) {
				throw new SealedFileContainerException('sealStream: short write on the header.');
			}

			$plain_size = 0;
			$index = 0;
			while (true) {
				$plain = self::readExactly($in, self::CHUNK_BYTES);
				if ($plain === '' && $index > 0) {
					break; // source exhausted on a chunk boundary
				}
				$block = self::sealChunk($plain, $fk, $content_id, $index);
				if (fwrite($out, $block) !== strlen($block)) {
					throw new SealedFileContainerException('sealStream: short write on chunk ' . $index . '.');
				}
				$plain_size += strlen($plain);
				$index++;
				if (strlen($plain) < self::CHUNK_BYTES) {
					break; // short read means end of source
				}
			}

			$cipher_size = ftell($out);
		} finally {
			fclose($in);
			fclose($out);
		}

		return array(
			'content_id'  => $content_id,
			'plain_size'  => $plain_size,
			'cipher_size' => (int)$cipher_size,
			'chunk_bytes' => self::CHUNK_BYTES,
		);
	}

	/** Seal in-memory bytes — for small payloads (a thumbnail), never file content. */
	public static function sealBytes($plain, $fk, $content_id = null) {
		self::assertKey($fk);
		$content_id = ($content_id === null) ? bin2hex(random_bytes(16)) : (string)$content_id;
		self::assertContentId($content_id);

		$out = self::buildHeader($content_id, self::CHUNK_BYTES);
		$plain = (string)$plain;
		$index = 0;
		$offset = 0;
		do {
			$piece = substr($plain, $offset, self::CHUNK_BYTES);
			$out .= self::sealChunk($piece === false ? '' : $piece, $fk, $content_id, $index);
			$offset += self::CHUNK_BYTES;
			$index++;
		} while ($offset < strlen($plain));

		return $out;
	}

	// ------------------------------------------------------------------
	// Read
	// ------------------------------------------------------------------

	/**
	 * Decrypt a byte range of the plaintext, handing each decrypted piece to
	 * $sink as it is produced. Constant memory: one chunk is held at a time, and
	 * a caller streaming to the browser never buffers the file.
	 *
	 * @param string      $path   the container
	 * @param string      $fk     raw 32-byte file key
	 * @param callable    $sink   function(string $bytes): void
	 * @param int         $offset first plaintext byte wanted
	 * @param int|null    $length how many bytes; null = to the end
	 * @return int bytes emitted
	 * @throws SealedFileContainerException on a bad container or a failed tag
	 */
	public static function openRange($path, $fk, callable $sink, $offset = 0, $length = null) {
		self::assertKey($fk);
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			throw new SealedFileContainerException('openRange: cannot read ' . $path);
		}
		try {
			$header = self::readHeaderFrom($fh, $path);
			return self::openWith($fh, $path, $fk, $header, $sink, $offset, $length);
		} finally {
			fclose($fh);
		}
	}

	/**
	 * Read a HEADERLESS body — the exact bytes the browser produces for a
	 * Fortress file (assets/js/drive-crypto.js), whose content id and chunk size
	 * travel in the FK-encrypted metadata blob instead of in the file.
	 *
	 * The server has no business holding a Fortress file key, so nothing in the
	 * product calls this. It exists because format compatibility is only real if
	 * something exercises it: the container test opens a browser-made fixture
	 * through this door, and a drift in either implementation fails there rather
	 * than in a member's Drive.
	 */
	public static function openBrowserBody($path, $fk, $content_id, $chunk_bytes, callable $sink, $offset = 0, $length = null) {
		self::assertKey($fk);
		self::assertContentId((string)$content_id);
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			throw new SealedFileContainerException('openBrowserBody: cannot read ' . $path);
		}
		$header = array(
			'version'     => self::VERSION,
			'content_id'  => (string)$content_id,
			'chunk_bytes' => (int)$chunk_bytes,
			'header_len'  => 0,
		);
		try {
			return self::openWith($fh, $path, $fk, $header, $sink, $offset, $length);
		} finally {
			fclose($fh);
		}
	}

	/** The shared reader: identical work whether the framing came with a header or not. */
	private static function openWith($fh, $path, $fk, array $header, callable $sink, $offset, $length) {
		$chunk = $header['chunk_bytes'];
		$plain_total = self::plainSizeFrom($path, $header);

		$offset = max(0, (int)$offset);
		if ($length === null) {
			$length = max(0, $plain_total - $offset);
		}
		$length = max(0, (int)$length);
		$length = min($length, max(0, $plain_total - $offset));
		if ($length === 0) {
			return 0;
		}

		$first_index = intdiv($offset, $chunk);
		$last_index  = intdiv($offset + $length - 1, $chunk);
		$emitted = 0;

		for ($index = $first_index; $index <= $last_index; $index++) {
			$plain = self::readChunkAt($fh, $path, $header, $index, $fk);

			// Trim the first and last chunks down to the requested window; the
			// ones between are emitted whole.
			$chunk_start = $index * $chunk;
			$take_from = max(0, $offset - $chunk_start);
			$take_to   = min(strlen($plain), ($offset + $length) - $chunk_start);
			if ($take_to > $take_from) {
				$piece = substr($plain, $take_from, $take_to - $take_from);
				$sink($piece);
				$emitted += strlen($piece);
			}
			unset($plain);
		}

		return $emitted;
	}

	/**
	 * Decrypt the whole container to a string. For payloads known to be small
	 * (a thumbnail, an extracted text blob) — file content uses openRange().
	 */
	public static function openString($path, $fk, $offset = 0, $length = null) {
		$out = '';
		self::openRange($path, $fk, function ($bytes) use (&$out) { $out .= $bytes; }, $offset, $length);
		return $out;
	}

	/** Decrypt a whole in-memory container (the sealBytes twin). */
	public static function openBytes($cipher, $fk) {
		$tmp = tempnam(sys_get_temp_dir(), 'sfc_');
		if ($tmp === false || @file_put_contents($tmp, $cipher) === false) {
			if ($tmp !== false) { @unlink($tmp); }
			throw new SealedFileContainerException('openBytes: cannot stage the container.');
		}
		try {
			return self::openString($tmp, $fk);
		} finally {
			@unlink($tmp);
		}
	}

	/** Decrypt to an open stream handle. */
	public static function openStream($path, $fk, $dest_handle) {
		return self::openRange($path, $fk, function ($bytes) use ($dest_handle) {
			if (fwrite($dest_handle, $bytes) === false) {
				throw new SealedFileContainerException('openStream: short write to the destination.');
			}
		});
	}

	// ------------------------------------------------------------------
	// Introspection
	// ------------------------------------------------------------------

	/** Read a container's header. */
	public static function readHeader($path) {
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			throw new SealedFileContainerException('readHeader: cannot read ' . $path);
		}
		try {
			return self::readHeaderFrom($fh, $path);
		} finally {
			fclose($fh);
		}
	}

	/** Does this file start with a container header? Cheap, and never throws. */
	public static function looksSealed($path) {
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return false;
		}
		$magic = fread($fh, strlen(self::MAGIC));
		fclose($fh);
		return $magic === self::MAGIC;
	}

	/**
	 * The plaintext size a container holds, derived from its length. Every block
	 * but the last is a full chunk, so the arithmetic is exact — no scan, no
	 * decryption, and no separate size field that could disagree with the bytes.
	 */
	public static function plainSize($path) {
		return self::plainSizeFrom($path, self::readHeader($path));
	}

	/** The on-disk size a plaintext of $plain_size will seal to. */
	public static function cipherSizeFor($plain_size, $content_id_length = 32) {
		$plain_size = max(0, (int)$plain_size);
		$chunks = max(1, (int)ceil($plain_size / self::CHUNK_BYTES));
		return self::headerLength($content_id_length) + $plain_size + ($chunks * self::CHUNK_OVERHEAD);
	}

	/** Header length for a container whose content id is $cid_length bytes. */
	public static function headerLength($cid_length) {
		return 4 /* magic */ + 1 /* version */ + 1 /* flags */ + 1 /* cid_len */
			+ 4 /* chunk */ + (int)$cid_length;
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	private static function buildHeader($content_id, $chunk_bytes) {
		return self::MAGIC
			. chr(self::VERSION)
			. chr(0) // flags, reserved
			. chr(strlen($content_id))
			. pack('N', (int)$chunk_bytes)
			. $content_id;
	}

	private static function readHeaderFrom($fh, $path) {
		rewind($fh);
		$fixed = self::readExactly($fh, 11);
		if (strlen($fixed) < 11 || substr($fixed, 0, 4) !== self::MAGIC) {
			throw new SealedFileContainerException('Not a sealed file container: ' . $path);
		}
		$version = ord($fixed[4]);
		if ($version !== self::VERSION) {
			throw new SealedFileContainerException('Unsupported container version ' . $version . ' in ' . $path);
		}
		$cid_len = ord($fixed[6]);
		$chunk   = unpack('N', substr($fixed, 7, 4))[1];
		if ($cid_len <= 0 || $chunk <= 0) {
			throw new SealedFileContainerException('Corrupt container header in ' . $path);
		}
		$cid = self::readExactly($fh, $cid_len);
		if (strlen($cid) !== $cid_len) {
			throw new SealedFileContainerException('Truncated container header in ' . $path);
		}
		return array(
			'version'     => $version,
			'content_id'  => $cid,
			'chunk_bytes' => (int)$chunk,
			'header_len'  => self::headerLength($cid_len),
		);
	}

	private static function plainSizeFrom($path, array $header) {
		$size = @filesize($path);
		if ($size === false) {
			throw new SealedFileContainerException('Cannot size the container ' . $path);
		}
		$body = (int)$size - $header['header_len'];
		if ($body < 0) {
			throw new SealedFileContainerException('Truncated container ' . $path);
		}
		$block = $header['chunk_bytes'] + self::CHUNK_OVERHEAD;
		$full  = intdiv($body, $block);
		$rem   = $body % $block;
		if ($rem === 0) {
			return $full * $header['chunk_bytes'];
		}
		if ($rem < self::CHUNK_OVERHEAD) {
			throw new SealedFileContainerException('Corrupt trailing block in ' . $path);
		}
		return ($full * $header['chunk_bytes']) + ($rem - self::CHUNK_OVERHEAD);
	}

	/**
	 * Read, verify and decrypt one chunk. The block offset is computed (every
	 * block but the last is the same size) and then CHECKED against the length
	 * prefix stored there, so a corrupt container fails loudly rather than
	 * feeding misaligned bytes to the cipher.
	 */
	private static function readChunkAt($fh, $path, array $header, $index, $fk) {
		$block_size = $header['chunk_bytes'] + self::CHUNK_OVERHEAD;
		$at = $header['header_len'] + ($index * $block_size);
		if (fseek($fh, $at) !== 0) {
			throw new SealedFileContainerException('Cannot seek to chunk ' . $index . ' of ' . $path);
		}
		$len_raw = self::readExactly($fh, self::LEN_BYTES);
		if (strlen($len_raw) !== self::LEN_BYTES) {
			throw new SealedFileContainerException('Missing chunk ' . $index . ' in ' . $path);
		}
		$block_len = unpack('N', $len_raw)[1];
		$max_block = self::IV_BYTES + $header['chunk_bytes'] + self::TAG_BYTES;
		if ($block_len < self::IV_BYTES + self::TAG_BYTES || $block_len > $max_block) {
			throw new SealedFileContainerException('Corrupt chunk length at chunk ' . $index . ' of ' . $path);
		}
		$block = self::readExactly($fh, $block_len);
		if (strlen($block) !== $block_len) {
			throw new SealedFileContainerException('Truncated chunk ' . $index . ' in ' . $path);
		}

		$iv     = substr($block, 0, self::IV_BYTES);
		$body   = substr($block, self::IV_BYTES);
		$cipher = substr($body, 0, strlen($body) - self::TAG_BYTES);
		$tag    = substr($body, strlen($body) - self::TAG_BYTES);

		$plain = openssl_decrypt($cipher, self::CIPHER, $fk, OPENSSL_RAW_DATA, $iv,
			$tag, self::chunkAad($header['content_id'], $index));
		if ($plain === false) {
			// A failed tag is tampering, the wrong key, or a chunk moved between
			// files. None of them have a safe partial answer.
			throw new SealedFileContainerException(
				'Chunk ' . $index . ' of ' . $path . ' failed authentication.');
		}
		return $plain;
	}

	private static function sealChunk($plain, $fk, $content_id, $index) {
		$iv = random_bytes(self::IV_BYTES);
		$tag = '';
		$cipher = openssl_encrypt($plain, self::CIPHER, $fk, OPENSSL_RAW_DATA, $iv,
			$tag, self::chunkAad($content_id, $index), self::TAG_BYTES);
		if ($cipher === false) {
			throw new SealedFileContainerException('Failed to seal chunk ' . $index . '.');
		}
		$block = $iv . $cipher . $tag;
		return pack('N', strlen($block)) . $block;
	}

	/** The AAD binding a chunk to its file and its position. */
	private static function chunkAad($content_id, $index) {
		return $content_id . ':' . $index;
	}

	/**
	 * fread() may return less than asked for on a pipe or a slow filesystem even
	 * when more is coming; loop until the request is filled or the source ends.
	 */
	private static function readExactly($fh, $want) {
		$out = '';
		while (strlen($out) < $want) {
			$piece = fread($fh, $want - strlen($out));
			if ($piece === false || $piece === '') {
				break;
			}
			$out .= $piece;
		}
		return $out;
	}

	private static function assertKey($fk) {
		if (!is_string($fk) || strlen($fk) !== self::KEY_BYTES) {
			throw new SealedFileContainerException(
				'A sealed file container needs a raw ' . self::KEY_BYTES . '-byte key.');
		}
	}

	private static function assertContentId($content_id) {
		if ($content_id === '' || strlen($content_id) > 255) {
			throw new SealedFileContainerException('Content id must be 1-255 bytes.');
		}
	}
}
?>
