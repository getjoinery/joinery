<?php
/**
 * Shared "Import old mail" panel.
 *
 * One rendering for both surfaces, so the member page and the admin page cannot
 * drift apart. They differ only in which mailboxes the picker offers, and that
 * difference is decided by MailImportService, not here.
 *
 * The panel is three things stacked:
 *
 *   the start form   - mailbox, archive, and the addresses that were yours
 *   the run list     - live progress, polled while anything is moving
 *   the choose step  - shown inline on a run that has finished scanning
 *
 * One import at a time. While the caller has a run going the start form is not on
 * the page at all, replaced by a line saying what is holding it; it comes back by
 * itself when that run finishes, without a reload. Which run counts is decided by
 * MailImportService, and the API refuses a second start regardless of what the
 * page is showing.
 *
 * Everything after the first render talks to /api/v1 with the browser-session
 * credential, including progress polling. An uploaded archive goes up through the
 * platform's resumable chunk transport under the mail_import_archive upload
 * purpose, so its size is not bounded by any single-request limit.
 *
 * @version 1.8 - the panel's script is assets/mail_import_panel.js (no inline script)
 * @version 1.7
 * @changelog 1.7 - the archive upload opens and finishes through upload_init / upload_complete, so it
 *   works with Drive off.
 * @changelog 1.6 - the finished-run summary calls the skipped bucket 'in
 *   excluded folders' instead of 'left out'.
 * @changelog 1.5 - a moving run shows a live countdown to the worker's next
 *   pass and how many messages it takes, from the status action's next_batch;
 *   a worker that has gone silent is called out instead of counted down to.
 * @changelog 1.4 - a finished run with something worth checking says so on its
 *   own row; a clean one stays a single line.
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailImportService.php'));

if (!function_exists('mailbox_render_import_panel')) {

/**
 * @param object $page  AdminPage or PublicPage — anything with getFormWriter()
 * @param array  $vars  aliases, alias_id, files, own_addresses, active_run, runs, is_operator
 */
function mailbox_render_import_panel($page, array $vars): void {
	$aliases   = (array)($vars['aliases'] ?? array());
	$files     = (array)($vars['files'] ?? array());
	$suggested = (string)($vars['own_addresses'] ?? '');
	$alias_id  = intval($vars['alias_id'] ?? 0);
	$active    = $vars['active_run'] ?? null;
	$runs      = (array)($vars['runs'] ?? array());

	if (!$aliases) {
		echo '<p class="jy-muted">There are no mailboxes to import into yet.</p>';
		return;
	}

	// Say up front when nothing will actually process the import. A run that sits
	// at "Waiting to start" forever is indistinguishable from a broken feature, and
	// the person looking at it cannot tell that the cause is one switch elsewhere.
	$warning = $vars['scheduler_warning'] ?? null;
	if ($warning) {
		echo '<div class="jy-callout jy-callout-warning">' . htmlspecialchars($warning) . '</div>';
	}

	// A run that has finished scanning is STOPPED until somebody answers it, and
	// that answer has to be the first thing on the page. Below the start form it
	// reads as history, and an import that is merely waiting looks identical to one
	// that is broken — which is exactly how it goes unnoticed for an hour.
	echo '<div id="mail-import-attention">' . mailbox_import_attention_html($runs) . '</div>';

	$alias_options = array();
	foreach ($aliases as $id => $address) {
		$alias_options[(string)$id] = $address;
	}

	$file_options = array('' => '-- Choose a file --');
	$disabled_files = array();
	foreach ($files as $file) {
		$label = $file['name'] . ' (' . mailbox_import_bytes($file['size']) . ')';
		if ($file['encrypted']) {
			$label .= ' — encrypted, cannot be read by the server';
			$disabled_files[] = (string)$file['id'];
		}
		$file_options[(string)$file['id']] = $label;
	}

	// The one-at-a-time notice and the start form are both always rendered, one of
	// them hidden. The poller flips them as runs come and go, so a member who
	// leaves the tab open sees the form return the moment their import lands
	// instead of wondering whether the page is stale.
	echo '<div id="mail-import-busy"' . ($active ? '' : ' hidden') . '>'
		. mailbox_import_busy_html($active) . '</div>';

	echo '<div id="mail-import-start"' . ($active ? ' hidden' : '') . '>';

	// No enctype: the form is never posted. The file is read from the input and
	// sent in chunks, and the rest of the fields ride as JSON on the action call.
	$formwriter = $page->getFormWriter('mail_import_form');

	echo $formwriter->begin_form();

	$formwriter->dropinput('alias_id', 'Import into', array(
		'options' => $alias_options,
		'value' => (string)$alias_id,
		'validation' => array('required' => true),
		'helptext' => 'The mail lands in this mailbox. It still records the address each message '
			. 'was actually delivered to.',
	));

	$formwriter->radioinput('source', 'Where is the archive', array(
		'options' => array(
			'upload' => 'Upload it now',
			'pick'   => 'Use a file already in my files',
		),
		'value' => 'upload',
		'visibility_rules' => array(
			'upload' => array('show' => array('archive'), 'hide' => array('file_id')),
			'pick'   => array('show' => array('file_id'), 'hide' => array('archive')),
		),
	));

	// No size ceiling to warn about: the archive goes up in chunks, so it never
	// rides in a single request and the server's upload limits do not apply.
	$formwriter->fileinput('archive', 'Archive file', array(
		'helptext' => 'A mailbox file (.mbox), a zip or tar of saved messages, or a single .eml. '
			. 'Any size — it uploads in pieces and picks up where it left off if the connection '
			. 'drops. Outlook .pst and .olm files cannot be read; connect that account as an '
			. 'IMAP feed instead.',
	));

	$formwriter->dropinput('file_id', 'File', array(
		'options' => $file_options,
		'helptext' => $disabled_files
			? 'Files in an encrypted folder are listed but cannot be used: only your browser can read them. '
				. 'Put a copy in an unencrypted folder to import it.'
			: 'Only files that look like mail archives are listed.',
	));

	$formwriter->textarea('own_addresses', 'Addresses that were yours', array(
		'value' => $suggested,
		'validation' => array('required' => true),
		'rows' => 3,
		'helptext' => 'One per line. Without these, mail you sent cannot be told from mail you received, '
			. 'and there is no way to know which of your addresses a message reached.',
	));

	$formwriter->submitbutton('btn_import', 'Read the archive');

	echo $formwriter->end_form();
	echo '</div>';

	echo '<div id="mail-import-feedback" class="jy-mt-2" role="status" aria-live="polite"></div>';
	// Filled by the script while a run is moving: the countdown to the next
	// batch. Empty markup here on purpose — the numbers it needs (the runner's
	// cadence) are the status action's to answer, not this render's.
	echo '<div id="mail-import-next" class="jy-mt-2" aria-live="polite"></div>';
	echo '<div id="mail-import-runs" class="jy-mt-3">' . mailbox_import_runs_html($runs) . '</div>';

	mailbox_import_panel_script();
}

/**
 * The banner for every run that is waiting on a decision.
 *
 * Rendered empty when nothing needs the user, so the page is quiet in the normal
 * case and the banner keeps its force for when it appears. The folder list itself
 * is filled in by the script as soon as it loads — the counts come from the run's
 * entries, which is a query this render deliberately does not make.
 */
function mailbox_import_attention_html(array $runs): string {
	$html = '';
	foreach ($runs as $run) {
		if (empty($run['can_choose'])) {
			continue;
		}
		$name = $run['source'] !== '' ? $run['source'] : 'the archive';
		$html .= '<section class="jy-callout jy-callout-action" data-attention-for="'
			. intval($run['id']) . '" role="region" aria-label="Import waiting for you">'
			. '<h3 class="jy-callout-title">This import is waiting for you</h3>'
			. '<p>Read ' . number_format($run['total']) . ' messages in '
			. '<strong>' . htmlspecialchars($name) . '</strong>. '
			. 'Nothing has been imported yet — tick what to bring across.</p>'
			. '<div data-chooser-for="' . intval($run['id']) . '">'
			. '<p class="jy-muted">Counting...</p></div>'
			. '</section>';
	}
	return $html;
}

/**
 * What stands in for the start form while an import is going.
 *
 * Says which archive is holding the slot and what it is doing, because "the form
 * is gone" with no reason reads as a broken page. A run waiting on a decision gets
 * pointed at the banner above rather than repeating the question here.
 */
function mailbox_import_busy_html(?array $run): string {
	if (!$run) {
		return '';
	}
	$name = ($run['source'] ?? '') !== '' ? $run['source'] : 'An archive';
	// The state label carries its own punctuation on the run that is waiting to be
	// answered ("Ready — choose what to bring"), so that case says it in a sentence
	// instead of pasting a label after a dash.
	$html = '<div class="jy-callout jy-callout-info">'
		. '<h3 class="jy-callout-title">An import is already going</h3>'
		. '<p><strong>' . htmlspecialchars($name) . '</strong> '
		. (empty($run['can_choose'])
			? '&mdash; ' . htmlspecialchars(lcfirst((string)$run['state_label'])) . '.'
			: 'has been read and is waiting for your answer.') . '</p>';
	$html .= empty($run['can_choose'])
		? '<p class="jy-muted">Only one import runs at a time. Starting another is offered again '
			. 'as soon as this one finishes &mdash; you do not need to stay on this page.</p>'
		: '<p class="jy-muted">Answer the question above to let it carry on. Starting another '
			. 'import is offered again once it finishes.</p>';
	return $html . '</div>';
}

/** The run list, rendered server-side first and re-rendered by the poller after. */
function mailbox_import_runs_html(array $runs): string {
	if (!$runs) {
		return '<p class="jy-muted">No imports yet.</p>';
	}

	$html = '<table class="jy-table"><thead><tr>'
		. '<th>Archive</th><th>Status</th><th>Progress</th><th>Result</th><th></th>'
		. '</tr></thead><tbody>';

	foreach ($runs as $run) {
		$html .= '<tr data-run-id="' . intval($run['id']) . '">';
		$html .= '<td>' . htmlspecialchars($run['source'] !== '' ? $run['source'] : 'Archive') . '</td>';
		$html .= '<td>' . htmlspecialchars($run['state_label']);
		if ($run['error'] !== '') {
			$html .= '<br><small class="jy-muted">' . htmlspecialchars($run['error']) . '</small>';
		}
		$html .= '</td>';

		$html .= '<td>';
		if ($run['total'] > 0) {
			$html .= '<progress value="' . intval($run['percent']) . '" max="100"></progress> '
				. number_format($run['processed']) . ' / ' . number_format($run['total']);
		} else {
			$html .= '<span class="jy-muted">&mdash;</span>';
		}
		$html .= '</td>';

		$html .= '<td>' . number_format($run['stored']) . ' imported';
		if ($run['dedup'] > 0)   { $html .= ', ' . number_format($run['dedup']) . ' already here'; }
		if ($run['skipped'] > 0) { $html .= ', ' . number_format($run['skipped']) . ' in excluded folders'; }
		if ($run['failed'] > 0)  { $html .= ', ' . number_format($run['failed']) . ' failed'; }

		// The reconciliation line. It appears only when there IS something to
		// look at, so its presence is the signal — a run with nothing to say
		// stays a single tidy line (specs/mail_import_loss_proof.md).
		$attention = (array)($run['attention'] ?? array());
		if ($attention) {
			$notes = array();
			if (isset($attention['unaccounted'])) {
				$notes[] = number_format(abs($attention['unaccounted']))
					. ' unaccounted for';
			}
			// Covers all three flagged reasons — collided with another mailbox,
			// unresolvable, and a stored copy listing no attachments — so the
			// label stays generic; the CLI report names each one.
			if (isset($attention['flagged'])) {
				$notes[] = number_format($attention['flagged'])
					. ' suspicious duplicate(s)';
			}
			$html .= '<br><span class="jy-warning">Needs checking: '
				. htmlspecialchars(implode('; ', $notes)) . '</span>';
		}
		$html .= '</td>';

		$html .= '<td>';
		// No "choose" button here on purpose. The decision lives in the banner at
		// the top of the page; offering it twice puts the important one in the
		// easier place to miss.
		if (!empty($run['can_undo'])) {
			$html .= '<button type="button" class="btn btn-danger" data-import-undo="'
				. intval($run['id']) . '">Undo this import</button>';
		}
		if (!empty($run['can_discard'])) {
			$html .= ' <button type="button" class="btn btn-secondary" data-import-discard="'
				. intval($run['id']) . '">Discard archive</button>';
		}
		$html .= '</td></tr>';
	}

	return $html . '</tbody></table>';
}

/** Bytes as something a person reads without counting digits. */
function mailbox_import_bytes(int $bytes): string {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$i = 0;
	$n = max(0, $bytes);
	while ($n >= 1024 && $i < count($units) - 1) {
		$n /= 1024;
		$i++;
	}
	return ($i === 0 ? (int)$n : number_format($n, 1)) . ' ' . $units[$i];
}

/**
 * The panel's behaviour, in assets/mail_import_panel.js. Vanilla, no framework — start the run, poll while
 * anything is moving, render the choose step when a run is waiting, undo.
 */
function mailbox_import_panel_script(): void {
	?>
<?php echo PageScript::src('plugins/mailbox/assets/mail_import_panel.js'); ?>
	<?php
}

}
?>
