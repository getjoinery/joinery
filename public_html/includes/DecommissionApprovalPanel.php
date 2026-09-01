<?php
/**
 * DecommissionApprovalPanel — the screen where someone consents to this site
 * being destroyed, permanently.
 *
 * The copy says what is true and says it plainly: this is not a restore, not a
 * move, not recoverable. The site's container, database, files and web
 * presence are deleted; only its offsite backups survive. The panel's
 * load-bearing line is this site's OWN record of its last completed offsite
 * upload — composed by the host's agent from this site's own backup history,
 * labeled as this site's testimony rather than the storage bucket's — because
 * "when did something of me last leave this machine" is the one fact a person
 * must weigh before agreeing to the rest.
 *
 * Everything shown here was composed by the host's root agent from this site's
 * own records and files. Nothing on this screen came from the management node,
 * which is the party being checked.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/DecommissionApproval.php'));

class DecommissionApprovalPanel {

	/** A wait, said the way a person would say it. */
	private static function humanise_wait($seconds) {
		$minutes = (int)ceil(max(0, $seconds) / 60);
		if ($minutes <= 1)  { return 'a minute'; }
		if ($minutes < 45)  { return $minutes . ' minutes'; }
		if ($minutes < 75)  { return 'an hour'; }
		if ($minutes < 105) { return 'an hour and a half'; }
		$hours = (int)round($minutes / 60);
		return $hours . ' hours';
	}

	/**
	 * Render the pending removal approval, if there is one.
	 *
	 * @param object $page    Anything with getFormWriter().
	 * @param array  $pending Pre-read DecommissionApproval::pending().
	 * @return bool Whether anything was rendered.
	 */
	public static function render($page, ?array $pending = null): bool {
		$pending = $pending ?? DecommissionApproval::pending();
		if ($pending === null) {
			return false;
		}

		echo '<div class="alert alert-danger mb-3">';
		echo '<strong>This site is about to be destroyed, permanently — and it will not happen without '
		   . 'your approval.</strong> ';
		echo htmlspecialchars($pending['summary']);
		echo '</div>';

		echo '<p class="text-muted small">This is not a restore and not a move. Approving deletes this '
		   . 'site\'s database, files and container from its server; only its offsite backups survive. '
		   . 'Check the last-upload line below before you decide — it is this site\'s own record of when '
		   . 'something of it last reached offsite storage.</p>';

		echo '<table class="table table-sm mb-3"><tbody>';
		foreach ($pending['facts'] as $fact) {
			if (!is_array($fact) || !isset($fact['label'])) {
				continue;
			}
			echo '<tr><th style="width:14rem">' . htmlspecialchars((string)$fact['label']) . '</th>'
			   . '<td>' . htmlspecialchars((string)($fact['value'] ?? '')) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="text-muted small mb-2">This request expires in about '
		   . htmlspecialchars(self::humanise_wait((int)$pending['seconds_left']))
		   . '. If you are not going to approve it, press <em>Decline</em> rather than closing this '
		   . 'page — declining answers now, and letting it expire leaves the host waiting. Either way '
		   . 'nothing is deleted, and the removal can be asked for again.</p>';

		// The key box, outside the form, exactly as the restore panel does it:
		// the recovery key is used in the page and never submitted; only the
		// recovered one-time sentence is posted.
		echo '<label for="da-privkey" class="form-label"><strong>Paste this site\'s recovery key to '
		   . 'approve its destruction</strong></label>';
		echo '<p class="text-muted small mb-1">The same key that opens this site\'s backups. It is used '
		   . 'in your browser and never sent anywhere — approving proves you hold it, which is the whole '
		   . 'of the check.</p>';
		echo '<input type="password" id="da-privkey" class="form-control" autocomplete="off" spellcheck="false">';
		echo '<button type="button" id="da-open" class="btn btn-danger btn-sm mt-2">Approve — destroy this site permanently</button>';
		echo '<div id="da-status" class="small mt-2"></div>';

		$fw = $page->getFormWriter('decommission_approval_form');
		$fw->begin_form();
		$fw->hiddeninput('action', '', array('value' => 'approve_decommission'));
		$fw->hiddeninput('approval_job_id', '', array('value' => (string)$pending['job_id']));
		$fw->hiddeninput('approval_answer', '', array('value' => '', 'id' => 'da-answer'));
		$fw->end_form();

		$fwd = $page->getFormWriter('decommission_decline_form');
		$fwd->begin_form();
		$fwd->hiddeninput('action', '', array('value' => 'decline_decommission'));
		$fwd->hiddeninput('approval_job_id', '', array('value' => (string)$pending['job_id']));
		$fwd->submitbutton('btn_decline_decommission', 'No — keep this site',
			array('class' => 'btn btn-sm btn-outline-secondary mt-3'));
		$fwd->end_form();

		// window.rrApproval is the ceremony bridge recovery-readiness.js reads.
		// A page never renders two approval panels at once (admin_backups shows
		// the decommission and defers the restore when both are somehow
		// pending), so the single global is safe here.
		echo '<script defer src="/assets/js/recovery-readiness.js?v='
		   . (@filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1') . '"></script>';
		echo '<script>window.rrApproval = ' . json_encode(array(
			'keyInputId' => 'da-privkey',
			'buttonId'   => 'da-open',
			'statusId'   => 'da-status',
			'proofId'    => 'da-answer',
			'challenge'  => $pending['challenge'],
			'publicKey'  => $pending['public_key'],
			'infoPrefix' => $pending['info'],
		), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';

		return true;
	}
}
