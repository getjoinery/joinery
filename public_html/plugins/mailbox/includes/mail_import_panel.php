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
 * Everything after the first render talks to /api/v1 with the browser-session
 * credential, including progress polling. The start form posts as multipart so an
 * archive can be uploaded in the same request that starts the run.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailImportService.php'));

if (!function_exists('mailbox_render_import_panel')) {

/**
 * @param object $page  AdminPage or PublicPage — anything with getFormWriter()
 * @param array  $vars  aliases, files, suggested_addresses, runs, is_operator
 */
function mailbox_render_import_panel($page, array $vars): void {
	$aliases   = (array)($vars['aliases'] ?? array());
	$files     = (array)($vars['files'] ?? array());
	$suggested = implode("\n", (array)($vars['suggested_addresses'] ?? array()));
	$runs      = (array)($vars['runs'] ?? array());

	if (!$aliases) {
		echo '<p class="jy-muted">There are no mailboxes to import into yet.</p>';
		return;
	}

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

	$formwriter = $page->getFormWriter('mail_import_form', array(
		'enctype' => 'multipart/form-data',
	));

	echo $formwriter->begin_form();

	$formwriter->dropinput('alias_id', 'Import into', array(
		'options' => $alias_options,
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

	$formwriter->fileinput('archive', 'Archive file', array(
		'helptext' => 'A mailbox file (.mbox), a zip or tar of saved messages, or a single .eml. '
			. 'Outlook .pst and .olm files cannot be read — connect that account as an IMAP feed instead.',
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

	echo '<div id="mail-import-feedback" class="jy-mt-2" role="status" aria-live="polite"></div>';
	echo '<div id="mail-import-runs" class="jy-mt-3">' . mailbox_import_runs_html($runs) . '</div>';

	mailbox_import_panel_script();
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
		if ($run['skipped'] > 0) { $html .= ', ' . number_format($run['skipped']) . ' left out'; }
		if ($run['failed'] > 0)  { $html .= ', ' . number_format($run['failed']) . ' failed'; }
		$html .= '</td>';

		$html .= '<td>';
		if (!empty($run['can_choose'])) {
			$html .= '<button type="button" class="jy-btn jy-btn-primary" data-import-choose="'
				. intval($run['id']) . '">Choose what to bring</button>';
		}
		if (!empty($run['can_undo'])) {
			$html .= '<button type="button" class="jy-btn jy-btn-danger" data-import-undo="'
				. intval($run['id']) . '">Undo this import</button>';
		}
		$html .= '</td></tr>';

		$html .= '<tr class="mail-import-choose-row" data-choose-for="' . intval($run['id'])
			. '" hidden><td colspan="5"></td></tr>';
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
 * The panel's behaviour. Vanilla, no framework — start the run, poll while
 * anything is moving, render the choose step when a run is waiting, undo.
 */
function mailbox_import_panel_script(): void {
	?>
<script>
(function () {
	var csrf = (document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '';
	var form = document.getElementById('mail_import_form');
	var feedback = document.getElementById('mail-import-feedback');
	var runsBox = document.getElementById('mail-import-runs');
	var polling = null;

	function api(action, body, isForm) {
		var opts = { method: 'POST', headers: { 'X-Joinery-Csrf': csrf } };
		if (isForm) {
			opts.body = body;
		} else {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body || {});
		}
		return fetch('/api/v1/action/mailbox/' + action, opts).then(function (r) { return r.json(); });
	}

	function say(text, bad) {
		if (!feedback) { return; }
		feedback.textContent = text || '';
		feedback.className = 'jy-mt-2 ' + (bad ? 'jy-alert jy-alert-error' : 'jy-alert jy-alert-info');
		if (!text) { feedback.className = 'jy-mt-2'; }
	}

	function errorOf(json) {
		return (json && (json.error_message || json.message)) ||
			(json && json.validation_errors && Object.values(json.validation_errors)[0]) ||
			'Something went wrong.';
	}

	// The start form posts as multipart so the archive rides along with the run's
	// details. An interceptor rather than a normal submit, because the answer is
	// rendered in place instead of navigating away.
	//
	// The submit event fires MORE THAN ONCE: the platform's validation layer
	// intercepts, validates, then re-dispatches natively, and this handler sees
	// both. Without the guard that starts two import runs from one click — and the
	// same guard covers an impatient second click while the upload is in flight.
	var starting = false;
	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (starting) { return; }
			starting = true;

			var data = new FormData(form);
			// Only one source is meaningful; sending both would be ambiguous.
			if (data.get('source') === 'pick') { data.delete('archive'); } else { data.delete('file_id'); }
			say('Uploading and checking the archive...');
			api('mail_import_start', data, true).then(function (j) {
				if (!j || !j.data || !j.data.run) { say(errorOf(j), true); return; }
				say(j.data.message);
				form.reset();
				refresh();
			}).catch(function () {
				say('The import could not be started.', true);
			}).then(function () { starting = false; });
		});
	}

	function refresh() {
		return api('mail_import_status', {}).then(function (j) {
			var runs = (j && j.data && j.data.runs) || [];
			render(runs);
			var moving = runs.some(function (r) {
				return ['queued', 'scanning', 'importing'].indexOf(r.state) !== -1;
			});
			if (moving && !polling) {
				polling = setInterval(refresh, 4000);
			} else if (!moving && polling) {
				clearInterval(polling);
				polling = null;
			}
		}).catch(function () { /* a dropped poll is not worth a message */ });
	}

	// The table is server-rendered on first paint and rebuilt here from the same
	// fields afterwards. A chooser the user has open is left alone — replacing it
	// mid-decision would throw away their ticks.
	function render(runs) {
		if (!runsBox) { return; }
		var open = runsBox.querySelector('.mail-import-choose-row:not([hidden])');
		if (open) { return; }

		if (!runs.length) {
			runsBox.innerHTML = '<p class="jy-muted">No imports yet.</p>';
			return;
		}

		var html = '<table class="jy-table"><thead><tr>'
			+ '<th>Archive</th><th>Status</th><th>Progress</th><th>Result</th><th></th>'
			+ '</tr></thead><tbody>';

		runs.forEach(function (r) {
			html += '<tr data-run-id="' + r.id + '">';
			html += '<td>' + escapeHtml(r.source || 'Archive') + '</td>';
			html += '<td>' + escapeHtml(r.state_label)
				+ (r.error ? '<br><small class="jy-muted">' + escapeHtml(r.error) + '</small>' : '') + '</td>';
			html += '<td>' + (r.total > 0
				? '<progress value="' + r.percent + '" max="100"></progress> '
					+ r.processed.toLocaleString() + ' / ' + r.total.toLocaleString()
				: '<span class="jy-muted">&mdash;</span>') + '</td>';

			var result = r.stored.toLocaleString() + ' imported';
			if (r.dedup > 0)   { result += ', ' + r.dedup.toLocaleString() + ' already here'; }
			if (r.skipped > 0) { result += ', ' + r.skipped.toLocaleString() + ' left out'; }
			if (r.failed > 0)  { result += ', ' + r.failed.toLocaleString() + ' failed'; }
			html += '<td>' + result + '</td><td>';

			if (r.can_choose) {
				html += '<button type="button" class="jy-btn jy-btn-primary" data-import-choose="'
					+ r.id + '">Choose what to bring</button>';
			}
			if (r.can_undo) {
				html += '<button type="button" class="jy-btn jy-btn-danger" data-import-undo="'
					+ r.id + '">Undo this import</button>';
			}
			html += '</td></tr>';
			html += '<tr class="mail-import-choose-row" data-choose-for="' + r.id
				+ '" hidden><td colspan="5"></td></tr>';
		});

		runsBox.innerHTML = html + '</tbody></table>';
	}

	document.addEventListener('click', function (e) {
		var choose = e.target.closest && e.target.closest('[data-import-choose]');
		if (choose) { openChooser(choose.getAttribute('data-import-choose')); return; }

		var undo = e.target.closest && e.target.closest('[data-import-undo]');
		if (undo) {
			if (!window.confirm('Permanently delete every message this import brought in? '
					+ 'Mail that was already here, and anything that arrived since, is left alone.')) {
				return;
			}
			say('Reversing...');
			api('mail_import_undo', { run_id: parseInt(undo.getAttribute('data-import-undo'), 10) })
				.then(function (j) {
					if (!j || !j.data) { say(errorOf(j), true); return; }
					say(j.data.message);
					location.reload();
				});
			return;
		}

		var go = e.target.closest && e.target.closest('[data-import-go]');
		if (go) { submitChoice(go.getAttribute('data-import-go')); }
	});

	function openChooser(runId) {
		var row = runsBox.querySelector('[data-choose-for="' + runId + '"]');
		if (!row) { return; }
		row.hidden = false;
		row.firstElementChild.innerHTML = '<p class="jy-muted">Counting...</p>';

		api('mail_import_status', { run_id: parseInt(runId, 10) }).then(function (j) {
			var preview = j && j.data && j.data.preview;
			if (!preview) { row.firstElementChild.innerHTML = '<p>' + errorOf(j) + '</p>'; return; }

			var html = '<fieldset class="jy-fieldset"><legend>Found in this archive</legend>';
			Object.keys(preview.folders).forEach(function (name) {
				html += '<label class="jy-check"><input type="checkbox" checked value="'
					+ escapeAttr(name) + '" data-folder-for="' + runId + '"> '
					+ escapeHtml(name) + ' <span class="jy-muted">'
					+ preview.folders[name].toLocaleString() + '</span></label>';
			});
			// Spam and Trash arrive unticked: an archive's spam folder is usually the
			// biggest thing in it and almost never what anyone meant to keep.
			if (preview.spam > 0) {
				html += '<label class="jy-check"><input type="checkbox" data-spam-for="' + runId
					+ '"> Spam <span class="jy-muted">' + preview.spam.toLocaleString() + '</span></label>';
			}
			if (preview.trash > 0) {
				html += '<label class="jy-check"><input type="checkbox" data-trash-for="' + runId
					+ '"> Trash <span class="jy-muted">' + preview.trash.toLocaleString() + '</span></label>';
			}
			html += '</fieldset><button type="button" class="jy-btn jy-btn-primary" data-import-go="'
				+ runId + '">Import the ticked folders</button>';
			row.firstElementChild.innerHTML = html;
		});
	}

	function submitChoice(runId) {
		var folders = [];
		runsBox.querySelectorAll('[data-folder-for="' + runId + '"]').forEach(function (box) {
			if (box.checked) { folders.push(box.value); }
		});
		var spamBox = runsBox.querySelector('[data-spam-for="' + runId + '"]');
		var trashBox = runsBox.querySelector('[data-trash-for="' + runId + '"]');

		say('Starting the import...');
		api('mail_import_select', {
			run_id: parseInt(runId, 10),
			// One folder per line rather than a JSON list: the action schema takes
			// scalars, and a folder name never contains a newline.
			folders: folders.join('\n'),
			include_spam: spamBox ? spamBox.checked : false,
			include_trash: trashBox ? trashBox.checked : false
		}).then(function (j) {
			if (!j || !j.data) { say(errorOf(j), true); return; }
			say(j.data.message);
			location.reload();
		});
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function escapeAttr(s) { return escapeHtml(s); }

	// One status call on load decides whether to poll at all, so a page with
	// nothing underway makes no further requests.
	refresh();
})();
</script>
	<?php
}

}
?>
