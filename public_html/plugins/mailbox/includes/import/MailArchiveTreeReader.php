<?php
/**
 * MailArchiveTreeReader - the shared walk for every format that is a TREE of
 * members rather than one long file: a folder of saved messages, a maildir, a
 * zip, an expanded tar.
 *
 * One algorithm serves all of them because the only thing that differs is how a
 * member is listed and opened. Subclasses answer those two questions; everything
 * else — deciding what a member is, splitting an mbox found inside the tree,
 * picking up provider sidecars, resuming mid-archive — happens here once.
 *
 * A member is one of four things:
 *   a saved message   (.eml, .emlx, or an extensionless maildir file)
 *   an mbox           (an archive within the archive, split in place)
 *   a sidecar         (Proton's .metadata.json — read WITH its message, not as one)
 *   a nested container (a zip inside a zip: reported, never followed)
 *
 * Nested containers are deliberately not recursed. Following them is how an
 * archive of a few hundred kilobytes becomes a full disk, and no real export tool
 * produces one.
 *
 * Locators are "f|member" for a whole file and "m|member|offset:length" for a
 * message inside an mbox member.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MboxSplitter.php'));

abstract class MailArchiveTreeReader extends MailArchiveReader {

	/** Nested container names remembered for the run report. */
	const MAX_REPORTED_NESTED = 20;

	/** Set by prepare(); where a member that cannot be read in place is expanded. */
	protected $work_dir = '';

	/** Cached id => name from the export's label manifest; null until looked for. */
	private $label_map = null;

	public function prepare(string $path, string $workDir): string {
		$this->work_dir = $workDir;
		return $path;
	}

	// ------------------------------------------------- subclass responsibilities

	/**
	 * Every member's path relative to the archive root, in a STABLE order —
	 * resuming a scan is an index into this list, so two passes must agree on it.
	 *
	 * @return string[]
	 */
	abstract protected function listMembers(string $path): array;

	/** One member's bytes, or just its first $maxBytes when that is all we need. */
	abstract protected function readMember(string $path, string $member, ?int $maxBytes = null): string;

	/** True if the member exists in this archive. */
	abstract protected function hasMember(string $path, string $member): bool;

	/**
	 * A seekable local path for a member, expanding it into the work directory if
	 * the container cannot be seeked (zip streams cannot). Only ever asked for an
	 * mbox member, which is the one case where random access matters.
	 */
	abstract protected function seekablePath(string $path, string $member): string;

	// --------------------------------------------------------------- the walk

	public function scan(string $path, callable $emit, array $state, float $deadline): array {
		$members = $this->listMembers($path);
		$index   = intval($state['index'] ?? 0);
		$offset  = intval($state['offset'] ?? 0);   // position inside an mbox member
		$ordinal = intval($state['ordinal'] ?? 0);
		$nested  = (array)($state['nested'] ?? array());
		$limit   = intval($state['limit'] ?? 20000);
		$emitted = 0;

		$total = count($members);
		while ($index < $total) {
			if ($emitted >= $limit || microtime(true) >= $deadline) {
				return array('index' => $index, 'offset' => $offset, 'ordinal' => $ordinal,
					'nested' => $nested, 'limit' => $limit, 'done' => false);
			}

			$member = $members[$index];
			$base   = basename($member);
			$folder = self::folderOf($member);

			if (self::isSidecar($base) || !self::isCandidate($base)) {
				if (self::isNestedContainer($base) && count($nested) < self::MAX_REPORTED_NESTED) {
					$nested[] = $member;
				}
				$index++;
				continue;
			}

			// An mbox inside the tree is split in place and can span several passes,
			// so the member index does not advance until it is exhausted.
			if (self::looksLikeMbox($base, $this->peek($path, $member))) {
				$local = $this->seekablePath($path, $member);
				$handle = fopen($local, 'rb');
				if (!$handle) {
					$index++; $offset = 0;
					continue;
				}
				try {
					$result = MboxSplitter::split($handle, $offset,
						function (array $msg) use ($emit, &$ordinal, &$emitted, $member, $folder) {
							$emit(self::describe(
								'm|' . $member . '|' . $msg['offset'] . ':' . $msg['length'],
								$ordinal++,
								$folder !== '' ? $folder : MboxReader::folderFromFilename($member),
								$msg['headers']
							));
							$emitted++;
						},
						$deadline,
						max(1, $limit - $emitted)
					);
				} finally {
					fclose($handle);
				}
				if ($result['next'] === null) {
					$index++;
					$offset = 0;
				} else {
					$offset = $result['next'];
				}
				continue;
			}

			// A saved message: read the head for its headers, and its sidecar if the
			// export wrote one.
			try {
				$head = $this->peek($path, $member);
				$headers = self::parseHeaders(self::headerBlock(self::stripEmlx($head)));
				$meta = self::protonMetadata($this->sidecarFor($path, $member), $this->labelMap($path));
				if ($meta === null && self::header($headers, 'x-gmail-labels') !== '') {
					$meta = self::gmailLabels($headers);
				}
				if ($meta === null) {
					// No provider record at all: the maildir flag suffix is the last
					// thing that knows whether this was read.
					$flags = self::maildirFlags($base);
					$meta = array('labels' => array(), 'is_read' => $flags['read'],
						'is_starred' => $flags['starred'],
						'class' => $flags['trashed'] ? 'trash' : 'normal', 'folder' => null);
				}
				$emit(self::describe('f|' . $member, $ordinal++, $folder, $headers, $meta));
				$emitted++;
			} catch (Throwable $e) {
				// A member that will not open is not a reason to abandon the archive.
				// It is skipped here and simply never becomes an entry.
				error_log('MailArchiveTreeReader: skipping unreadable member ' . $member . ' — ' . $e->getMessage());
			}
			$index++;
		}

		return array('index' => $index, 'offset' => 0, 'ordinal' => $ordinal,
			'nested' => $nested, 'limit' => $limit, 'done' => true);
	}

	public function read(string $path, string $locator): string {
		if (strncmp($locator, 'm|', 2) === 0) {
			$rest = substr($locator, 2);
			$split = strrpos($rest, '|');
			if ($split === false) {
				throw new RuntimeException('Unreadable position in the archive.');
			}
			$member = substr($rest, 0, $split);
			list($offset, $length) = MboxReader::parseLocator(substr($rest, $split + 1));
			$handle = fopen($this->seekablePath($path, $member), 'rb');
			if (!$handle) {
				throw new RuntimeException('The archive member could not be opened.');
			}
			try {
				return MboxSplitter::readMessage($handle, $offset, $length);
			} finally {
				fclose($handle);
			}
		}

		if (strncmp($locator, 'f|', 2) === 0) {
			return self::stripEmlx($this->readMember($path, substr($locator, 2)));
		}

		throw new RuntimeException('Unreadable position in the archive.');
	}

	// --------------------------------------------------------------- internals

	/** The first bytes of a member, for sniffing and header parsing. */
	protected function peek(string $path, string $member): string {
		try {
			$bytes = $this->readMember($path, $member, self::HEADER_PEEK_BYTES);
		} catch (Throwable $e) {
			return '';
		}
		return $bytes;
	}

	/**
	 * The export's label manifest, as id => name, or an empty map when there is
	 * none.
	 *
	 * A Proton export ships `labels.json` naming every folder and label in the
	 * account. The per-message sidecars reference those by opaque id only, so
	 * without this a folder somebody called "Meditation" arrives as an unreadable
	 * base64 string. Looked up once per reader and cached, since it is one small
	 * file consulted for every message.
	 *
	 * Absent for a bare folder of .eml files, which still imports — just without
	 * custom label names, because there are none to be had.
	 */
	protected function labelMap(string $path): array {
		if ($this->label_map !== null) {
			return $this->label_map;
		}
		$this->label_map = array();
		foreach ($this->listMembers($path) as $member) {
			if (strtolower(basename($member)) !== 'labels.json') {
				continue;
			}
			try {
				$this->label_map = self::protonLabelMap($this->readMember($path, $member));
			} catch (Throwable $e) {
				error_log('MailArchiveTreeReader: could not read the label manifest ' . $member
					. ' — ' . $e->getMessage());
			}
			break;
		}
		return $this->label_map;
	}

	/**
	 * The provider sidecar sitting beside a message, or null. Proton writes
	 * "<id>.metadata.json" next to "<id>.eml"; some versions append instead of
	 * replacing the extension, so both spellings are tried.
	 */
	protected function sidecarFor(string $path, string $member): ?string {
		$withoutExt = preg_replace('/\.[^.\/]+$/', '', $member);
		foreach (array($withoutExt . '.metadata.json', $member . '.json', $withoutExt . '.json') as $candidate) {
			if ($candidate !== $member && $this->hasMember($path, $candidate)) {
				try {
					return $this->readMember($path, $candidate);
				} catch (Throwable $e) {
					return null;
				}
			}
		}
		return null;
	}

	/** The member's containing directory, which is the folder it was filed under. */
	public static function folderOf(string $member): string {
		$dir = trim(str_replace('\\', '/', dirname($member)), './');
		if ($dir === '' || $dir === '.') {
			return '';
		}
		// Export tools wrap everything in one top-level directory named for the
		// account. That is not a folder anybody filed mail into, so the deepest
		// segment is the folder and a single-segment path means the root.
		$parts = array_values(array_filter(explode('/', $dir), 'strlen'));
		return count($parts) > 1 ? end($parts) : '';
	}

	/** Proton's sidecars are read with their message, never imported as one. */
	public static function isSidecar(string $base): bool {
		return substr($base, -14) === '.metadata.json' || substr($base, -5) === '.json';
	}

	/** A container inside a container — reported in the run notes, never followed. */
	public static function isNestedContainer(string $base): bool {
		return (bool)preg_match('/\.(zip|tar|tgz|gz|bz2|7z|rar|pst|olm)$/i', $base);
	}

	/** Anything that might hold a message. */
	public static function isCandidate(string $base): bool {
		if ($base === '' || $base[0] === '.') {
			return false;
		}
		return self::looksLikeMessageFile($base) || self::looksLikeMbox($base);
	}
}
?>
