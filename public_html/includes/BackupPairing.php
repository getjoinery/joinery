<?php
/**
 * BackupPairing — does an offsite backup come with the key that opens it?
 *
 * An encrypted backup is two files, not one: the archive, and a small JSON
 * envelope beside it holding the archive's data key sealed to the site's
 * recovery key. The envelope is the ONLY copy of that key. It is not in the
 * database — bkh_backup_history records that a run was encrypted and which
 * recovery key it used, never the sealed key itself — so an archive whose
 * envelope did not travel with it is an archive nobody can open. Not the
 * operator, not support, not the person holding the recovery key.
 *
 * That is worse than having no offsite copy at all, because it looks like
 * protection. It sits in the listing, it has a size and a date, and the fact
 * that restoring from it is impossible is discovered on the day it is needed.
 *
 * The whole-run paths already keep the pair together: BackupRunner uploads the
 * envelope as a second artifact of the same run. The gap is the per-file action
 * on a node's Backups tab, where an operator re-uploads ONE archive that was
 * stranded locally by a failed transfer. That action names an archive, and
 * nothing has ever asked where its key is.
 *
 * This class answers that, and the two halves are kept apart on purpose:
 *   - classify() is pure. Given names, it says which archives are missing an
 *     envelope and which envelopes have outlived their archive.
 *   - cloud_state() does the listing, so the policy can be tested without a
 *     bucket.
 *   - verdict() is the policy, and it is one rule: an encrypted archive may
 *     only be sent offsite alone when its envelope is already proven to be
 *     there. Otherwise the envelope travels with it, and where it cannot, the
 *     operator is told BEFORE the upload rather than on the day of the restore.
 *
 * @version 1.1 - cloud_state() accepts a listing the caller already has, so the check
 *                costs nothing on a page that has just listed the target
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

class BackupPairing {

	/** An archive that can be restored from: either unencrypted, or paired. */
	const PROCEED = 'proceed';
	/** The envelope has to travel with the archive for this copy to be worth anything. */
	const PAIR = 'pair';
	/** Nothing available can open this copy. Refuse, and say so in those terms. */
	const BLOCKED = 'blocked';

	/**
	 * Sort a set of names into paired archives, archives missing their key, and
	 * envelopes whose archive is gone.
	 *
	 * Pure — it takes names, not a bucket — so the same rules serve a cloud
	 * listing, a node's local listing, and a test. Names may be bare filenames
	 * or full object keys; only the last path segment is read.
	 *
	 * Only ENCRYPTED archives can be missing an envelope. A plaintext .tar.gz
	 * has no key to lose, and reporting one as unpaired would train operators to
	 * ignore the warning that matters.
	 */
	public static function classify(array $names) {
		$archives  = array();
		$envelopes = array();

		foreach ($names as $name) {
			$base = basename((string)$name);
			if ($base === '') { continue; }
			if (BackupNaming::is_sidecar($base)) {
				$envelopes[$base] = true;
			} elseif (BackupNaming::is_backup($base)) {
				$archives[$base] = true;
			}
		}

		$paired = array();
		$missing_envelope = array();
		foreach (array_keys($archives) as $archive) {
			if (!BackupNaming::is_encrypted($archive)) {
				continue; // nothing to pair; not a finding either way
			}
			if (isset($envelopes[BackupEnvelope::sidecar_name($archive)])) {
				$paired[] = $archive;
			} else {
				$missing_envelope[] = $archive;
			}
		}

		$orphan_envelope = array();
		foreach (array_keys($envelopes) as $envelope) {
			$archive = BackupNaming::artifact_for_sidecar($envelope);
			if ($archive === '' || !isset($archives[$archive])) {
				$orphan_envelope[] = $envelope;
			}
		}

		sort($paired); sort($missing_envelope); sort($orphan_envelope);
		return array(
			'paired'           => $paired,
			'missing_envelope' => $missing_envelope,
			'orphan_envelope'  => $orphan_envelope,
		);
	}

	/**
	 * What a cloud target already holds for one archive.
	 *
	 * One prefix-scoped listing answers both questions, because the envelope's
	 * key is the archive's key plus a suffix. A target that cannot be read
	 * returns checked => FALSE, and a caller must never read that as "the
	 * envelope is not there" — not knowing and knowing-it-is-absent are
	 * different facts, and the policy below treats them differently.
	 *
	 * $complete_listing lets a caller that has ALREADY listed this node's
	 * objects answer from what it holds, with no second request. Pass it only
	 * when the listing is complete for this node's prefix: a truncated one — a
	 * capped listing such as TargetLister's 500-object limit — would report a
	 * stored envelope as absent, turning a safety check into a source of false
	 * alarms. When in doubt, pass nothing and let this make its own request.
	 *
	 * The request it makes when it must is a small control call, but it is a
	 * NETWORK call on a path where an operator is waiting: S3Signer allows a
	 * 15-second attempt and up to MAX_ATTEMPTS of them with backoff, so an
	 * unreachable bucket costs about a minute before it answers "unknown". That
	 * is bounded, not fast. It is also the same cost the page render already
	 * pays through BackupListHelper's live listing, so the fix — a short-deadline
	 * control mode used by every interactive path — belongs in S3Signer rather
	 * than here, where it would only ever cover one caller.
	 */
	public static function cloud_state($target, $slug, $artifact_name, ?array $complete_listing = null) {
		$artifact_name = basename((string)$artifact_name);
		$key = TargetBackups::base_prefix($target) . $slug . '/' . $artifact_name;

		$state = array(
			'checked'          => false,
			'reason'           => '',
			'artifact_present' => false,
			'envelope_present' => false,
			'artifact_key'     => $key,
			'envelope_key'     => BackupEnvelope::sidecar_name($key),
		);

		if ($complete_listing !== null) {
			$state['checked'] = true;
			foreach ($complete_listing as $entry) {
				$found = is_array($entry) ? (string)($entry['key'] ?? '') : (string)$entry;
				if ($found === $key) {
					$state['artifact_present'] = true;
				} elseif ($found === $state['envelope_key']) {
					$state['envelope_present'] = true;
				}
			}
			return $state;
		}

		try {
			$creds  = $target->get_credentials();
			$bucket = (string)$target->get('bkt_bucket');
			if (empty($creds) || $bucket === '') {
				$state['reason'] = 'this backup target has no usable credentials';
				return $state;
			}
			$objects = S3Signer::list($creds, $bucket, $key);
		} catch (Exception $e) {
			$state['reason'] = $e->getMessage();
			return $state;
		}

		$state['checked'] = true;
		foreach ($objects as $object) {
			$found = (string)($object['key'] ?? '');
			if ($found === $key) {
				$state['artifact_present'] = true;
			} elseif ($found === $state['envelope_key']) {
				$state['envelope_present'] = true;
			}
		}
		return $state;
	}

	/**
	 * Whether this archive may be sent offsite on its own.
	 *
	 * $envelope_on_node is TRUE when the node is known to hold the envelope,
	 * FALSE when it is known not to, and NULL when nobody has asked. NULL
	 * resolves to "send it too": attempting costs one small transfer, and if the
	 * envelope is not there the attempt fails saying exactly that — which is the
	 * fact the operator needs. Guessing the other way produces a silent
	 * unrecoverable copy, which is the one outcome not on the table.
	 */
	public static function verdict(array $cloud_state, $artifact_name, $envelope_on_node = null) {
		$artifact_name = basename((string)$artifact_name);

		if (!BackupNaming::is_encrypted($artifact_name)) {
			return array(
				'verdict' => self::PROCEED,
				'message' => '',
			);
		}

		$caveat = empty($cloud_state['checked'])
			? ' The cloud target could not be read'
				. (($cloud_state['reason'] ?? '') !== '' ? ' (' . $cloud_state['reason'] . ')' : '')
				. ', so what is already stored there is unknown.'
			: '';

		if (!empty($cloud_state['checked']) && !empty($cloud_state['envelope_present'])) {
			return array('verdict' => self::PROCEED, 'message' => '');
		}

		if ($envelope_on_node === false) {
			return array(
				'verdict' => self::BLOCKED,
				'message' => 'This backup is encrypted and its key file ('
					. BackupEnvelope::sidecar_name($artifact_name) . ') is not stored offsite '
					. 'and is not on the node either. Uploading the archive on its own would '
					. 'produce an offsite copy that nobody can restore from — including you. '
					. 'That is worse than no copy, because it looks like protection.' . $caveat,
			);
		}

		return array(
			'verdict' => self::PAIR,
			'message' => 'This backup is encrypted, so its key file ('
				. BackupEnvelope::sidecar_name($artifact_name) . ') is being sent with it. '
				. 'The archive cannot be opened without it.' . $caveat,
		);
	}

	/** cloud_state() and verdict() in one call, for a caller with a live target. */
	public static function upload_verdict($target, $slug, $artifact_name, $envelope_on_node = null,
	                                      ?array $complete_listing = null) {
		$state = self::cloud_state($target, $slug, $artifact_name, $complete_listing);
		$out = self::verdict($state, $artifact_name, $envelope_on_node);
		$out['cloud'] = $state;
		$out['envelope_name'] = BackupEnvelope::sidecar_name(basename((string)$artifact_name));
		return $out;
	}
}
