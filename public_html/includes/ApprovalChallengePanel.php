<?php
/**
 * ApprovalChallengePanel — the screen where someone decides whether a
 * destructive act happens to this site. One panel for every ApprovalChallenge
 * scope; the scope supplies every word.
 *
 * It is deliberately the loudest thing on the Backups page when it is there,
 * and completely absent when it is not. An act is waiting for a person; a
 * person who has to notice a subtle notice is a person who approves without
 * reading.
 *
 * WHAT IS ON THE SCREEN AND WHY IT IS IN THIS ORDER:
 *
 *   1. What will happen, in one sentence, in words. Not the operation's name —
 *      "restore_chain" tells an administrator nothing about what they are
 *      about to lose.
 *   2. The specifics the agent itself composed, with the one fact no automatic
 *      check can weigh (an archive's age; the last offsite upload) among them.
 *   3. The key box, and only then.
 *
 * Everything shown here was composed by a root agent from this site's own
 * records. Nothing on this screen came from the management node, which is the
 * party being checked.
 *
 * @version 1.1 - an answered request shows that it was answered, not the form again: the page
 *                reloaded before the agent took the answer, and the owner approved a second time
 * @version 1.0 - one panel for every scope; restore and decommission were two copies
 */

class ApprovalChallengePanel {

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

	/**
	 * Render the pending approval in one scope, if there is one.
	 *
	 * @param object $page    Anything with getFormWriter() — AdminPage or PublicPage.
	 * @param string $scope   An ApprovalChallenge scope name.
	 * @param array  $pending Pre-read ApprovalChallenge::pending($scope), when the page has it.
	 * @return bool Whether anything was rendered.
	 */
	public static function render($page, string $scope, ?array $pending = null): bool {
		$s = ApprovalChallenge::scope($scope);
		$pending = $pending ?? ApprovalChallenge::pending($scope);
		if ($pending === null) {
			return false;
		}
		$id = $s['id_prefix'];

		// Answered, and the agent has not taken it yet: say so instead of
		// asking again. The page reloads until the request is gone.
		if ($pending['answered'] !== null) {
			echo '<div class="alert alert-info mb-3">';
			echo '<strong>' . htmlspecialchars($s['headline']) . '</strong> ';
			echo htmlspecialchars($pending['answered'] === 'declined'
				? 'You declined this. This machine\'s agent is picking up the answer and will report the job refused.'
				: 'You approved this. This machine\'s agent is picking up the answer and carries on in a few seconds.');
			echo '</div>';
			echo '<script>setTimeout(function () { window.location.reload(); }, 4000);</script>';
			return true;
		}

		echo '<div class="alert alert-danger mb-3">';
		echo '<strong>' . htmlspecialchars($s['headline']) . '</strong> ';
		echo htmlspecialchars($pending['summary']);
		echo '</div>';

		echo '<p class="text-muted small">' . htmlspecialchars($s['intro']) . '</p>';

		echo '<table class="table table-sm mb-3"><tbody>';
		foreach ($pending['facts'] as $fact) {
			if (!is_array($fact) || !isset($fact['label'])) {
				continue;
			}
			echo '<tr><th style="width:14rem">' . htmlspecialchars((string)$fact['label']) . '</th>'
			   . '<td>' . htmlspecialchars((string)($fact['value'] ?? '')) . '</td></tr>';
		}
		echo '</tbody></table>';

		// expiry_tail is the scope's own markup, written in ApprovalChallenge.
		echo '<p class="text-muted small mb-2">This request expires in about '
		   . htmlspecialchars(self::humanise_wait((int)$pending['seconds_left']))
		   . $s['expiry_tail'] . '</p>';

		// The key box. Outside the form on purpose, exactly as the possession
		// ceremony does it: the recovery key is used in the page and never
		// submitted, and only the recovered sentence — which is a one-time
		// secret for this job and useless for anything else — is posted.
		echo '<label for="' . $id . '-privkey" class="form-label"><strong>'
		   . htmlspecialchars($s['key_label']) . '</strong></label>';
		echo '<p class="text-muted small mb-1">' . htmlspecialchars($s['key_help']) . '</p>';
		echo '<input type="password" id="' . $id . '-privkey" class="form-control" autocomplete="off" spellcheck="false">';
		echo '<button type="button" id="' . $id . '-open" class="btn btn-danger btn-sm mt-2">'
		   . htmlspecialchars($s['approve_button']) . '</button>';
		echo '<div id="' . $id . '-status" class="small mt-2"></div>';

		$fw = $page->getFormWriter($s['approve_form']);
		$fw->begin_form();
		$fw->hiddeninput('action', '', array('value' => $s['approve_action']));
		$fw->hiddeninput('approval_job_id', '', array('value' => (string)$pending['job_id']));
		$fw->hiddeninput('approval_answer', '', array('value' => '', 'id' => $id . '-answer'));
		$fw->end_form();

		// Declining is a first-class answer, not a matter of walking away. The
		// agent stops waiting and reports the job refused, so the management
		// node's job list says a person said no rather than showing a job that
		// timed out for reasons nobody recorded.
		$fwd = $page->getFormWriter($s['decline_form']);
		$fwd->begin_form();
		$fwd->hiddeninput('action', '', array('value' => $s['decline_action']));
		$fwd->hiddeninput('approval_job_id', '', array('value' => (string)$pending['job_id']));
		$fwd->submitbutton('btn_' . $s['decline_action'], $s['decline_button'],
			array('class' => 'btn btn-sm btn-outline-secondary mt-3'));
		$fwd->end_form();

		// Its own global rather than window.rrPanel: the recovery setup panel
		// owns that one, and a page showing both would have the second
		// assignment silently replace the first. A page renders one approval
		// at a time (ApprovalChallenge::PRECEDENCE), so one global serves every
		// scope.
		echo '<script defer src="/assets/js/recovery-readiness.js?v='
		   . (@filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1') . '"></script>';
		echo '<script>window.rrApproval = ' . json_encode(array(
			'keyInputId' => $id . '-privkey',
			'buttonId'   => $id . '-open',
			'statusId'   => $id . '-status',
			'proofId'    => $id . '-answer',
			'challenge'  => $pending['challenge'],
			'publicKey'  => $pending['public_key'],
			'infoPrefix' => $pending['info'],
		), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';

		return true;
	}
}
