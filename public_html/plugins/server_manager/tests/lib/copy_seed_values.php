<?php
/**
 * copy_seed_values.php - the made-up values seed_copy_source.php writes and
 * check_copy_target.php expects, each derived from the seed's id, so no value
 * is ever printed or carried between the two.
 *
 * @version 1.0
 */

/** The made-up value of one item of seed $id. */
function copy_seed_value(string $id, string $item): string {
	return 'copy-seed-' . $item . '-' . substr(hash_hmac('sha256', $item, 'copy-seed:' . $id), 0, 32);
}

/** The raw message seed $id keeps in storage/. */
function copy_seed_raw(string $id): string {
	return "From: sender@seed.invalid\r\nTo: seed@seed-{$id}.invalid\r\nSubject: copy seed {$id}\r\n"
		. "Message-ID: <{$id}@seed.invalid>\r\nDate: Sun, 18 Oct 2026 00:00:00 +0000\r\n\r\n" . copy_seed_value($id, 'raw') . "\r\n";
}
