<?php
/**
 * BackupFetch — how this machine pulls one of its own backups back off the shelf.
 *
 * One place, because two scripts need it (a standalone archive, and every
 * artifact of a chain) and the interesting part must not be written twice: a
 * node never receives a bucket credential, and it never puts bytes into its
 * backup directory that it cannot recognise as its own.
 *
 * WHAT ARRIVES INSTEAD OF A CREDENTIAL. A pre-signed URL — one object key,
 * expiring, signed on the machine that owns the bucket. The node's own stored
 * credential is write-only on purpose (a node that could read the shelf is a
 * node whose compromise reaches every other node's backups), and nothing here
 * widens that: a signature is not a key, it names one object, and the object
 * name is inside the signature so it cannot be re-pointed.
 *
 * WHY EVERY FETCH IS LEDGER-CHECKED. The management node chooses the bucket, the
 * signature and the name a file lands under. The operator approving a restore
 * approves a NAME, never the bytes. So the check that the bytes are this
 * machine's own has to be made by this machine, against something written before
 * the bytes were anywhere a management node could reach: the upload ledger
 * (BackupLedger), written by the backup run itself at upload time. Two attacks
 * die here — an artifact forged wholesale, and this machine's own genuine
 * month-old archive replayed under a fresh-looking name.
 *
 * EVERYTHING LANDS 0600, CREATED THAT WAY. On a container node the backup
 * directory resolves inside the site tree, so a file written with default
 * permissions is readable by the web tier. It is opened restricted before the
 * first byte arrives rather than tightened afterwards — a file made readable and
 * then fixed was readable for the length of a multi-gigabyte transfer, which is
 * long enough for anything on the machine to open it and hold the descriptor
 * after the mode changes. The agent runs with no umask of its own, so the umask
 * is set HERE rather than assumed.
 *
 * NOTHING ARRIVES LARGER THAN THE LEDGER SAYS IT SHOULD. The free-space check
 * below happens before the transfer, and a check before a transfer bounds
 * nothing during it: a response with no Content-Length streams for as long as
 * the deadline allows, which is an hour of writing to a disk the machine needs.
 * The ledger already knows the exact size, so the transfer carries that as a
 * ceiling and aborts the moment it is passed.
 *
 * @version 1.1 - the transfer is capped at the size the ledger recorded, the sink is CREATED
 *                0600 rather than created-then-chmod'd, and an error response's body is no
 *                longer copied into the job transcript
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupLedger.php'));

class BackupFetch {

	/** Connect budget. A signed URL that will not answer quickly will not answer. */
	const CONNECT_TIMEOUT_SECONDS = 15;

	/**
	 * Transfer budget for one object. S3Signer's own per-attempt window: an
	 * archive on a slow link is legitimately an hour of work, and a deadline
	 * under the work is a scheduled way to fail restores.
	 */
	const TRANSFER_TIMEOUT_SECONDS = 3600;

	/**
	 * Headroom demanded beyond the artifact's own size before a transfer
	 * starts: a restore wants room beside the archive to unpack or load it.
	 */
	const MIN_HEADROOM_BYTES = 268435456;   // 256 MiB

	/**
	 * How far past the recorded size a transfer may run before it is abandoned.
	 *
	 * A GET of an object returns exactly the bytes that were put there, so the
	 * honest figure is zero and this is only slack against a provider that
	 * frames or pads a response. It is small on purpose: the number this
	 * defends is the disk, and a percentage of a 40GB archive is gigabytes of
	 * room to run a node out of space in.
	 */
	const SIZE_SLACK_BYTES = 1048576;       // 1 MiB

	/** Is this an https URL? A signature is a bearer token; plaintext leaks it. */
	public static function is_signed_url($url) {
		return is_string($url) && strpos($url, 'https://') === 0;
	}

	/**
	 * Fetch one signed URL to one local file, created 0600 before any bytes
	 * arrive. Returns ['ok' => bool, 'error' => string].
	 *
	 * $max_bytes is a hard ceiling on what may be written. Zero means no
	 * ceiling, which no caller here uses — the ledger always knows the size —
	 * but it keeps the parameter honest for a caller that genuinely cannot.
	 *
	 * The URL is never put in an error message. It carries a signature, and a
	 * job transcript is read by more people than the person who ran the job.
	 */
	public static function fetch($url, $sink_path, $max_bytes = 0) {
		if (!self::is_signed_url($url)) {
			return array('ok' => false, 'error' => 'the download link is not an https URL');
		}

		$fh = self::open_private_sink($sink_path);
		if (!$fh) {
			return array('ok' => false, 'error' => 'cannot open ' . basename($sink_path) . ' for writing');
		}

		$max_bytes = (int)$max_bytes;
		$overran   = false;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		// A redirect would take the fetch somewhere the signature does not name,
		// which is precisely the substitution the signature exists to prevent.
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TRANSFER_TIMEOUT_SECONDS);
		if ($max_bytes > 0) {
			// Two ceilings, because one of them can be avoided. MAXFILESIZE
			// refuses before the first byte when the response ADVERTISES a
			// length, which is the cheap case and the common one; a response
			// that advertises nothing, or lies, is stopped by the progress
			// callback as it goes past. The second is the one that matters —
			// it is what a chunked response cannot talk its way around.
			curl_setopt($ch, CURLOPT_MAXFILESIZE, $max_bytes);
			curl_setopt($ch, CURLOPT_NOPROGRESS, false);
			curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
				function ($handle, $expected_total, $received, $ul_total, $uploaded)
						use ($max_bytes, &$overran) {
					if ($received > $max_bytes || $expected_total > $max_bytes) {
						$overran = true;
						return 1;   // any non-zero aborts the transfer
					}
					return 0;
				});
		}

		$ok     = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$errno  = curl_errno($ch);
		$err    = curl_error($ch);
		curl_close($ch);
		fclose($fh);

		if ($overran || $errno === CURLE_FILESIZE_EXCEEDED) {
			@unlink($sink_path);
			return array('ok' => false, 'error' => 'refusing this download: it is larger than the '
				. self::human($max_bytes) . ' this machine recorded uploading under that name');
		}
		if ($ok === false || $errno) {
			@unlink($sink_path);
			return array('ok' => false, 'error' => 'transfer failed: ' . $err);
		}
		if ($status !== 200) {
			// The status, and NOT the body. A failed response is provider XML in
			// the ordinary case, but the URL is chosen by the management node
			// and can name any https host — so copying the body into a job
			// transcript the plane reads back would make this node a way to read
			// error responses from wherever it can reach, which is the inside of
			// its own network. The status answers the operator's question
			// ("expired signature", "wrong key") without carrying anything.
			@unlink($sink_path);
			return array('ok' => false, 'error' => 'HTTP ' . $status . ' from storage '
				. '(the response body is not repeated here)');
		}
		return array('ok' => true, 'error' => '');
	}

	/**
	 * Open a file for writing that nothing else on this machine can read.
	 *
	 * Its own method because it is a PROPERTY, not a step: on a container node
	 * the backup directory resolves inside the site tree, so a download written
	 * with default permissions is readable by the web tier for the length of a
	 * multi-gigabyte transfer — long enough for anything on the box to open it
	 * and keep the descriptor after a later chmod. A test can therefore check
	 * the property rather than the sequence of calls that produces it.
	 *
	 * Three things have to be true together, and any one alone is not enough:
	 * the umask is set here (the agent sets none, so the inherited one is
	 * whatever started it), any stale file is removed first (fopen on an
	 * existing file keeps the mode it already has), and the mode is restated
	 * afterwards.
	 */
	public static function open_private_sink($path) {
		@unlink($path);
		$prior_umask = umask(0077);
		$fh = @fopen($path, 'wb');
		umask($prior_umask);
		if (!$fh) {
			return false;
		}
		@chmod($path, 0600);
		return $fh;
	}

	/**
	 * How many bytes a download of a $expected-byte artifact may write before it
	 * is abandoned. Zero when there is no recorded size and therefore no ceiling
	 * to enforce.
	 */
	public static function size_ceiling($expected) {
		$expected = (int)$expected;
		return $expected > 0 ? $expected + self::SIZE_SLACK_BYTES : 0;
	}

	/**
	 * The whole of one artifact's journey back: check the ledger, check the
	 * room, fetch, verify the bytes, put it in place.
	 *
	 * The ledger is consulted BEFORE the transfer, not after, and that ordering
	 * is doing two jobs. A name this machine never uploaded is refused without a
	 * byte moving — so a management node cannot fill a node's disk with
	 * something it was never going to be allowed to use. And the ledger records
	 * the size, so the free-space check has a real number to work with instead
	 * of a guess.
	 *
	 * @param string $profile  site | manager — whose shelf the artifact came from
	 * @param string $dir      Absolute directory it lands in
	 * @param string $relname  Ledger key: the name relative to the profile's backup
	 *                         directory, chain subdirectory included
	 * @param string $filename Bare name it lands under, inside $dir
	 * @param string $url      Pre-signed GET URL
	 *
	 * @return array ['ok'=>bool, 'error'=>string, 'bytes'=>int, 'sha256'=>string, 'path'=>string]
	 */
	public static function fetch_artifact($profile, $dir, $relname, $filename, $url) {
		// Is the record worth consulting at all? A ledger anything on this
		// machine can rewrite answers every question with whatever the last
		// writer wanted, so it is not a weaker check than none — it is a check
		// that reports success. The agent refuses such a ledger before it will
		// run a restore; refusing it here too means the answer arrives before
		// the download rather than after it.
		$untrusted = BackupLedger::untrusted($profile);
		if ($untrusted !== '') {
			return array('ok' => false, 'error' => 'refusing to download ' . $relname . ': ' . $untrusted);
		}

		$entry = BackupLedger::lookup($profile, $relname);
		if ($entry === null) {
			$why = BackupLedger::exists($profile)
				? 'this machine has no record of ever uploading it'
				: 'this machine has no upload ledger for the ' . $profile . ' profile, so it cannot '
					. 'confirm any archive is one it made';
			return array('ok' => false,
				'error' => 'refusing to download ' . $relname . ': ' . $why);
		}

		$expected = (int)($entry['bytes'] ?? 0);
		$free = @disk_free_space($dir);
		$needed = $expected + max(self::MIN_HEADROOM_BYTES, (int)($expected * 0.25));
		if ($free !== false && $expected > 0 && $free < $needed) {
			return array('ok' => false, 'error' => 'not enough disk: ' . $relname . ' needs about '
				. self::human($needed) . ' free in ' . $dir . ', and there is ' . self::human((int)$free));
		}

		$final = rtrim($dir, '/') . '/' . $filename;
		$part  = rtrim($dir, '/') . '/.download-' . getmypid() . '-' . $filename . '.part';

		// The ceiling: what this machine recorded, plus slack. The bytes are
		// checked against the ledger after the transfer either way, so this is
		// not about accepting the wrong file — it is about how much disk a
		// wrong file gets to consume before it is rejected.
		$got = self::fetch($url, $part, self::size_ceiling($expected));
		if (!$got['ok']) {
			return array('ok' => false, 'error' => $got['error']);
		}

		// The bytes against the ledger, before anything is put where a restore
		// would find it. A mismatch is not something to retry — it is a
		// different archive.
		$check = BackupLedger::verify($profile, $relname, $part);
		if (!$check['ok']) {
			@unlink($part);
			return array('ok' => false, 'error' => $check['reason']);
		}

		if (!@rename($part, $final)) {
			@unlink($part);
			return array('ok' => false, 'error' => 'could not put ' . $filename . ' in place');
		}
		// rename() carries the 0600 the part file was created with. Restated
		// rather than assumed, because it is the property that makes a recovery
		// key captured on this machine a key with nothing here to open.
		@chmod($final, 0600);

		return array('ok' => true, 'error' => '', 'bytes' => (int)@filesize($final),
			'sha256' => (string)$entry['sha256'], 'path' => $final);
	}

	/**
	 * Byte count for a human. A local copy rather than BackupRunner::human()
	 * because a fetch should not have to boot the backup engine to say how big
	 * something is.
	 */
	public static function human($bytes) {
		$bytes = (float)$bytes;
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$i = 0;
		while ($bytes >= 1024 && $i < count($units) - 1) {
			$bytes /= 1024;
			$i++;
		}
		return round($bytes, ($i === 0) ? 0 : 1) . ' ' . $units[$i];
	}
}
