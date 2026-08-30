<?php
/**
 * RestoreApprovalPanel — the screen where someone decides whether this machine
 * destroys its own data.
 *
 * It is deliberately the loudest thing on the Backups page when it is there,
 * and completely absent when it is not. A restore is waiting for a person; a
 * person who has to notice a subtle notice is a person who approves without
 * reading.
 *
 * WHAT IS ON THE SCREEN AND WHY IT IS IN THIS ORDER:
 *
 *   1. What will be destroyed, in one sentence, in words. Not the operation's
 *      name — "restore_chain" tells an administrator nothing about what they are
 *      about to lose.
 *   2. The specifics the machine itself composed: the database, the archive, its
 *      SIZE, and its AGE.
 *   3. The key box, and only then.
 *
 * THE AGE LINE IS THE POINT OF THE WHOLE PANEL. Every other check in this
 * mechanism is a machine checking a machine, and all of them pass against the
 * attack that matters most: a management node that had been compromised serving
 * this machine its OWN genuine month-old archive under a fresh-looking name.
 * Every signature verifies, every envelope opens, the ledger matches — because
 * it really is this machine's backup. The only thing wrong with it is the date,
 * and the only thing that can notice is a person. So the date is on the screen,
 * spelled out as an age, above the key box rather than below it.
 *
 * Everything shown here was composed by this machine's own root agent from its
 * own records. Nothing on this screen came from the management node, which is
 * the party being checked.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/RestoreApproval.php'));

class RestoreApprovalPanel {

	/**
	 * Render the pending approval, if there is one.
	 *
	 * @param object $page    Anything with getFormWriter() — AdminPage or PublicPage.
	 * @param array  $pending Pre-read RestoreApproval::pending(), when the page has it.
	 * @return bool Whether anything was rendered.
	 */
	/**
	 * A wait, said the way a person would say it. "about 60 minutes" is what
	 * the arithmetic produces and not what anybody means.
	 */
	private static function humanise_wait($seconds) {
		$minutes = (int)ceil(max(0, $seconds) / 60);
		if ($minutes <= 1)  { return 'a minute'; }
		if ($minutes < 45)  { return $minutes . ' minutes'; }
		if ($minutes < 75)  { return 'an hour'; }
		if ($minutes < 105) { return 'an hour and a half'; }
		$hours = (int)round($minutes / 60);
		return $hours . ' hours';
	}

	public static function render($page, ?array $pending = null): bool {
		$pending = $pending ?? RestoreApproval::pending();
		if ($pending === null) {
			return false;
		}

		echo '<div class="alert alert-danger mb-3">';
		echo '<strong>A restore is waiting for your approval.</strong> ';
		echo htmlspecialchars($pending['summary']);
		echo '</div>';

		echo '<p class="text-muted small">Everything below was written by this machine, from its own '
		   . 'records — not by whoever asked for the restore. Check the date of the archive before you '
		   . 'approve: an archive that is older than you expect is the one thing no automatic check can '
		   . 'catch for you.</p>';

		echo '<table class="table table-sm mb-3"><tbody>';
		foreach ($pending['facts'] as $fact) {
			if (!is_array($fact) || !isset($fact['label'])) {
				continue;
			}
			echo '<tr><th style="width:14rem">' . htmlspecialchars((string)$fact['label']) . '</th>'
			   . '<td>' . htmlspecialchars((string)($fact['value'] ?? '')) . '</td></tr>';
		}
		echo '</tbody></table>';

		// The deadline, and the thing an operator cannot otherwise know: this
		// machine is holding its whole job queue open while it waits. The agent
		// runs one job at a time, so nothing else — no backup, no status check,
		// no upgrade — happens until this is answered or expires.
		//
		// That makes DECLINING strictly better than walking away, and nothing
		// said so. Someone who has decided not to approve, or who cannot find
		// their key, releases the machine immediately by pressing Decline;
		// closing the tab leaves it waiting for the rest of the window.
		echo '<p class="text-muted small mb-2">This request expires in about '
		   . htmlspecialchars(self::humanise_wait((int)$pending['seconds_left']))
		   . '. Until you answer it, <strong>this machine runs nothing else</strong> — no backup, no '
		   . 'status check, no upgrade. If you are not going to approve it, press <em>Decline</em> '
		   . 'rather than closing this page: declining frees the machine now, and letting it expire '
		   . 'leaves it waiting. Either way nothing is restored, and the restore can be asked for '
		   . 'again.</p>';

		// The key box. Outside the form on purpose, exactly as the possession
		// ceremony does it: the recovery key is used in the page and never
		// submitted, and only the recovered sentence — which is a one-time
		// secret for this job and useless for anything else — is posted.
		echo '<label for="ra-privkey" class="form-label"><strong>Paste your recovery key to approve</strong></label>';
		echo '<p class="text-muted small mb-1">The same key that opens this machine\'s backups. It is used '
		   . 'in your browser and never sent anywhere — approving proves you hold it, which is the whole '
		   . 'of the check.</p>';
		echo '<input type="password" id="ra-privkey" class="form-control" autocomplete="off" spellcheck="false">';
		echo '<button type="button" id="ra-open" class="btn btn-danger btn-sm mt-2">Approve this restore</button>';
		echo '<div id="ra-status" class="small mt-2"></div>';

		$fw = $page->getFormWriter('restore_approval_form');
		$fw->begin_form();
		$fw->hiddeninput('action', '', array('value' => 'approve_restore'));
		$fw->hiddeninput('approval_job_id', '', array('value' => (string)$pending['job_id']));
		$fw->hiddeninput('approval_answer', '', array('value' => '', 'id' => 'ra-answer'));
		$fw->end_form();

		// Declining is a first-class answer, not a matter of walking away. The
		// agent stops waiting and reports the job refused, so the management
		// node's job list says a person said no rather than showing a restore
		// that timed out for reasons nobody recorded.
		$fwd = $page->getFormWriter('restore_decline_form');
		$fwd->begin_form();
		$fwd->hiddeninput('action', '', array('value' => 'decline_restore'));
		$fwd->hiddeninput('approval_job_id', '', array('value' => (string)$pending['job_id']));
		$fwd->submitbutton('btn_decline_restore', 'No — do not restore',
			array('class' => 'btn btn-sm btn-outline-secondary mt-3'));
		$fwd->end_form();

		// Its own global rather than window.rrPanel: the recovery setup panel
		// owns that one, and a page showing both would have the second
		// assignment silently replace the first. They rarely coexist — an
		// approval needs a proven key, and a proven key hides the setup panel —
		// but "rarely" is not a thing to build an approval screen on.
		echo '<script defer src="/assets/js/recovery-readiness.js?v='
		   . (@filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1') . '"></script>';
		echo '<script>window.rrApproval = ' . json_encode(array(
			'keyInputId' => 'ra-privkey',
			'buttonId'   => 'ra-open',
			'statusId'   => 'ra-status',
			'proofId'    => 'ra-answer',
			'challenge'  => $pending['challenge'],
			'publicKey'  => $pending['public_key'],
			'infoPrefix' => $pending['info'],
		), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';

		return true;
	}
}
