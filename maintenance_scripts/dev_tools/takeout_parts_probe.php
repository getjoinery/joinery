<?php
/**
 * takeout_parts_probe.php - what shape did this export actually arrive in?
 *
 * A mail export requested in parts may cut a single oversized mbox ACROSS parts.
 * If it does, the member inside part two begins mid-message and cannot be read
 * standalone, and identically-named members collide when the parts are extracted
 * into one directory. Whether a given export tool does that is a question about
 * the tool, not about us — so this measures it rather than guessing.
 *
 * It reports, for every archive in a directory:
 *   - each member, with its uncompressed size
 *   - which member NAMES appear in more than one part (the collision signal)
 *   - for each mbox member, whether its first bytes are a "From " separator at
 *     offset 0 (a complete mbox) or something else (a fragment)
 *   - total uncompressed bytes, which is the disk question
 *
 * Every shape production accepts is probed the way that shape allows:
 *   .zip   central directory + a 4 KB prefix per mbox member — near-instant,
 *          and it never expands anything
 *   .tgz   no index exists, so one streaming decompression pass walks the tar
 *          headers; nothing is written to disk
 *   .mbox  a bare file sitting beside the parts is read directly
 *
 * READ-ONLY. It never extracts, never writes, and never touches the database, so
 * it is safe to point at an export wherever it already lives.
 *
 * Usage:
 *   php takeout_parts_probe.php DIRECTORY [--show=N]
 *
 * See specs/mail_import_loss_proof.md § C.
 *
 * @version 1.0
 */

/** Bytes read from the head of an mbox member to judge complete-vs-fragment. */
const PROBE_PREFIX_BYTES = 4096;

/** Members whose names end in these are treated as mbox candidates. */
const MBOX_SUFFIXES = array('.mbox', '.mbx');

$args = array_slice($argv, 1);
$dir = null;
$show = 40;
foreach ($args as $arg) {
	if (strncmp($arg, '--show=', 7) === 0) { $show = max(1, intval(substr($arg, 7))); continue; }
	if ($arg === '--help' || $arg === '-h') { $dir = null; break; }
	if ($dir === null) { $dir = $arg; }
}

if ($dir === null || !is_dir($dir)) {
	fwrite(STDERR, "Usage: php takeout_parts_probe.php DIRECTORY [--show=N]\n\n"
		. "  DIRECTORY  holds the export parts (.zip / .tgz / .tar.gz) and/or bare .mbox files\n"
		. "  --show     how many members to list per part (default 40)\n");
	exit(1);
}

$dir = rtrim($dir, '/');
$entries = scandir($dir);
sort($entries, SORT_NATURAL);

$parts = array();
foreach ($entries as $entry) {
	if ($entry === '.' || $entry === '..') { continue; }
	$path = $dir . '/' . $entry;
	if (!is_file($path)) { continue; }
	$lower = strtolower($entry);
	if (preg_match('/\.zip$/', $lower))                  { $parts[] = array($path, $entry, 'zip'); }
	elseif (preg_match('/\.(tgz|tar\.gz)$/', $lower))    { $parts[] = array($path, $entry, 'tgz'); }
	elseif (is_mbox_name($lower))                        { $parts[] = array($path, $entry, 'mbox'); }
}

if (!count($parts)) {
	fwrite(STDERR, "No .zip, .tgz or .mbox files in $dir\n");
	exit(1);
}

echo "Probing " . count($parts) . " file(s) in $dir\n";

$members_by_name = array();     // member name => list of part names holding it
$total_uncompressed = 0;
$mbox_findings = array();

foreach ($parts as list($path, $label, $kind)) {
	echo "\n=== $label (" . $kind . ', ' . human_bytes(filesize($path)) . " on disk) ===\n";
	$members = array();
	try {
		if ($kind === 'zip')       { $members = probe_zip($path); }
		elseif ($kind === 'tgz')   { $members = probe_tgz($path); }
		else                       { $members = probe_bare_mbox($path, $label); }
	} catch (Throwable $e) {
		echo '  could not read: ' . $e->getMessage() . "\n";
		continue;
	}

	if (!count($members)) {
		echo "  no members\n";
		continue;
	}

	$shown = 0;
	foreach ($members as $m) {
		$members_by_name[$m['name']][] = $label;
		$total_uncompressed += $m['size'];

		if ($shown < $show) {
			echo '  ' . str_pad(human_bytes($m['size']), 10) . $m['name'];
			if ($m['is_mbox']) {
				echo '   [' . ($m['complete'] === null ? 'unreadable'
					: ($m['complete'] ? 'complete mbox' : 'FRAGMENT — does not start at a message')) . ']';
			}
			echo "\n";
			$shown++;
		}
		if ($m['is_mbox']) {
			$mbox_findings[] = array('part' => $label, 'name' => $m['name'],
				'complete' => $m['complete'], 'size' => $m['size']);
		}
	}
	if (count($members) > $show) {
		echo '  … ' . number_format(count($members) - $show) . " more member(s)\n";
	}
}

// ------------------------------------------------------------------ verdicts

echo "\n=== VERDICT ===\n";
echo 'total uncompressed: ' . human_bytes($total_uncompressed)
	. ' (needs that much free disk if anything expands it)' . "\n";

$spanning = array();
foreach ($members_by_name as $name => $holders) {
	if (count($holders) > 1) { $spanning[$name] = $holders; }
}

if (!count($spanning)) {
	echo "member names: no name appears in more than one part — nothing collides on extraction\n";
} else {
	echo 'member names: ' . count($spanning) . " name(s) appear in MORE THAN ONE part:\n";
	$n = 0;
	foreach ($spanning as $name => $holders) {
		echo '  ' . $name . '  in ' . implode(', ', $holders) . "\n";
		if (++$n >= $show) { echo "  …\n"; break; }
	}
	echo "  Extracting these into one directory would have later parts overwrite earlier ones.\n";
}

$fragments = array_values(array_filter($mbox_findings, function ($f) { return $f['complete'] === false; }));
$complete  = array_values(array_filter($mbox_findings, function ($f) { return $f['complete'] === true; }));

echo 'mbox members: ' . count($mbox_findings) . ' found — '
	. count($complete) . ' complete, ' . count($fragments) . " fragment(s)\n";

if (count($fragments)) {
	echo "\nTHE MBOX IS CUT ACROSS PARTS. These do not begin at a message boundary:\n";
	foreach (array_slice($fragments, 0, $show) as $f) {
		echo '  ' . $f['part'] . ' :: ' . $f['name'] . ' (' . human_bytes($f['size']) . ")\n";
	}
	echo "Each of these needs its predecessor's tail to be readable at all; no importer can\n";
	echo "take one standalone. Reassembly is a separate capability and is not built.\n";
	exit(2);
}

if (count($complete)) {
	echo "\nEvery mbox found starts at a message boundary, so each can be read on its own.\n";
}
exit(0);

// ------------------------------------------------------------------- probing

function is_mbox_name(string $name): bool {
	$lower = strtolower($name);
	foreach (MBOX_SUFFIXES as $suffix) {
		if (substr($lower, -strlen($suffix)) === $suffix) { return true; }
	}
	return false;
}

/**
 * Does this prefix begin a complete mbox?
 *
 * mbox separates messages with a "From " line in the first column, so a whole
 * file starts with one. Anything else means the bytes begin part-way through a
 * message — which is exactly what a cross-part cut looks like.
 */
function starts_at_boundary(string $prefix): bool {
	return strncmp($prefix, 'From ', 5) === 0;
}

/** Members of a zip, read from the central directory — no extraction. */
function probe_zip(string $path): array {
	$zip = new ZipArchive();
	if ($zip->open($path) !== TRUE) {
		throw new RuntimeException('not a readable zip');
	}
	$out = array();
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$stat = $zip->statIndex($i);
		if ($stat === false) { continue; }
		$name = (string)$stat['name'];
		if (substr($name, -1) === '/') { continue; }        // directory entry
		$is_mbox = is_mbox_name($name);
		$complete = null;
		if ($is_mbox) {
			// Only the head is decompressed, so a 6 GB member costs nothing.
			$handle = $zip->getStream($name);
			if ($handle) {
				$prefix = (string)fread($handle, PROBE_PREFIX_BYTES);
				fclose($handle);
				$complete = starts_at_boundary($prefix);
			}
		}
		$out[] = array('name' => $name, 'size' => intval($stat['size']),
			'is_mbox' => $is_mbox, 'complete' => $complete);
	}
	$zip->close();
	return $out;
}

/**
 * Members of a gzipped tar. A tar has no index, so this is one streaming pass
 * that reads each 512-byte header and seeks over the payload — except for an
 * mbox member, whose first bytes are kept. Nothing is extracted.
 */
function probe_tgz(string $path): array {
	$handle = @gzopen($path, 'rb');
	if (!$handle) {
		throw new RuntimeException('not a readable gzip stream');
	}
	$out = array();
	try {
		while (!gzeof($handle)) {
			$header = gzread($handle, 512);
			if ($header === false || strlen($header) < 512) { break; }
			if (trim($header) === '') { break; }              // end-of-archive padding

			$name = rtrim(substr($header, 0, 100), "\0");
			$size = octdec(trim(substr($header, 124, 12), " \0"));
			$type = substr($header, 156, 1);
			if ($name === '') { break; }

			$padded = $size > 0 ? (int)(ceil($size / 512) * 512) : 0;

			// '0' and "\0" are regular files; everything else (dirs, links, the
			// long-name extensions) carries no mail we can judge.
			if ($type !== '0' && $type !== "\0") {
				skip_gz($handle, $padded);
				continue;
			}

			$is_mbox = is_mbox_name($name);
			$complete = null;
			if ($is_mbox && $size > 0) {
				$want = (int)min(PROBE_PREFIX_BYTES, $size);
				$prefix = (string)gzread($handle, $want);
				$complete = starts_at_boundary($prefix);
				skip_gz($handle, $padded - $want);
			} else {
				skip_gz($handle, $padded);
			}

			$out[] = array('name' => $name, 'size' => intval($size),
				'is_mbox' => $is_mbox, 'complete' => $complete);
		}
	} finally {
		gzclose($handle);
	}
	return $out;
}

/** gzip streams are not seekable — read forward and discard. */
function skip_gz($handle, int $bytes): void {
	while ($bytes > 0 && !gzeof($handle)) {
		$chunk = gzread($handle, (int)min(262144, $bytes));
		if ($chunk === false || $chunk === '') { break; }
		$bytes -= strlen($chunk);
	}
}

/** A bare .mbox sitting beside the parts — the shape a big export often takes. */
function probe_bare_mbox(string $path, string $label): array {
	$handle = @fopen($path, 'rb');
	if (!$handle) {
		throw new RuntimeException('could not open the file');
	}
	$prefix = (string)fread($handle, PROBE_PREFIX_BYTES);
	fclose($handle);
	return array(array('name' => $label, 'size' => intval(filesize($path)),
		'is_mbox' => true, 'complete' => starts_at_boundary($prefix)));
}

function human_bytes(int $bytes): string {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$i = 0;
	$value = (float)$bytes;
	while ($value >= 1024 && $i < count($units) - 1) { $value /= 1024; $i++; }
	return ($i === 0 ? (string)$bytes : sprintf('%.1f', $value)) . $units[$i];
}
?>
