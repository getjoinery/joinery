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
 * credential, including progress polling. An uploaded archive goes up through the
 * platform's resumable chunk transport under the mail_import_archive upload
 * purpose, so its size is not bounded by any single-request limit.
 *
 * @version 1.1
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

	// Say up front when nothing will actually process the import. A run that sits
	// at "Waiting to start" forever is indistinguishable from a broken feature, and
	// the person looking at it cannot tell that the cause is one switch elsewhere.
	$warning = $vars['scheduler_warning'] ?? null;
	if ($warning) {
		echo '<div class="jy-alert jy-alert-warning">' . htmlspecialchars($warning) . '</div>';
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

	// No enctype: the form is never posted. The file is read from the input and
	// sent in chunks, and the rest of the fields ride as JSON on the action call.
	$formwriter = $page->getFormWriter('mail_import_form');

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
		if (!empty($run['can_discard'])) {
			$html .= ' <button type="button" class="jy-btn" data-import-discard="'
				. intval($run['id']) . '">Discard archive</button>';
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

	function post(path, body) {
		return fetch('/api/v1/action/' + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
			body: JSON.stringify(body || {})
		}).then(function (r) { return r.json(); });
	}

	/** One of this plugin's actions. */
	function api(action, body) { return post('mailbox/' + action, body); }

	/**
	 * A CORE action. The chunked upload endpoints belong to the platform, not to
	 * mailbox — sending them through the plugin namespace is a 404.
	 */
	function coreApi(action, body) { return post(action, body); }

	function say(text, bad) {
		if (!feedback) { return; }
		feedback.textContent = text || '';
		feedback.className = 'jy-mt-2 ' + (bad ? 'jy-alert jy-alert-error' : 'jy-alert jy-alert-info');
		if (!text) { feedback.className = 'jy-mt-2'; }
	}

	// The API's error envelope is {error, errortype}; a validation failure adds
	// validation_errors. Reading the wrong key here turns every real explanation
	// into a shrug, so take them in the order the API actually sends them.
	function errorOf(json) {
		if (!json) { return 'The server did not respond.'; }
		if (json.error) { return json.error; }
		if (json.validation_errors) {
			var first = Object.keys(json.validation_errors)[0];
			if (first) { return json.validation_errors[first]; }
		}
		return json.error_message || json.message || 'Something went wrong.';
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
			var usePicked = (data.get('source') === 'pick');
			var picked = usePicked ? null : data.get('archive');

			if (!usePicked && (!picked || !picked.size)) {
				say('Choose an archive to upload, or pick one you already have here.', true);
				starting = false;
				return;
			}

			// An archive already here needs no transfer at all.
			if (usePicked) {
				startRun(data.get('file_id'), '', data).then(function () { starting = false; });
				return;
			}

			// Otherwise send it in chunks. There is no size limit on this path — the
			// bytes never ride in one request — so a multi-gigabyte archive is a
			// progress bar rather than a refusal.
			uploadInChunks(picked).then(function (fileId) {
				return startRun(fileId, picked.name, data);
			}).catch(function (err) {
				say(String(err && err.message ? err.message : err), true);
			}).then(function () { starting = false; });
		});
	}

	/**
	 * Send a file through the platform's resumable chunk transport and resolve with
	 * the id of the File it became.
	 *
	 * Chunks go out strictly in order because the server tracks a single received
	 * offset rather than sparse ranges — which is also what makes resuming cheap: a
	 * rejected chunk comes back with the offset to continue from.
	 */
	function uploadInChunks(file) {
		return coreApi('drive_upload_init', {
			purpose: 'mail_import_archive',
			name: file.name,
			size_bytes: file.size,
			mime_type: file.type || 'application/octet-stream'
		}).then(function (j) {
			var d = (j && j.data) || {};
			if (!d.upload_token) { throw new Error(errorOf(j)); }

			var token = d.upload_token;
			var chunkSize = d.chunk_bytes || 8388608;
			var total = file.size;
			var sent = 0;

			function sendNext() {
				if (sent >= total) { return Promise.resolve(); }
				var end = Math.min(sent + chunkSize, total);
				var slice = file.slice(sent, end);
				var range = 'bytes ' + sent + '-' + (end - 1) + '/' + total;

				return fetch('/api/v1/drive_upload/' + encodeURIComponent(token), {
					method: 'PUT',
					headers: { 'X-Joinery-Csrf': csrf, 'Content-Range': range,
						'Content-Type': 'application/octet-stream' },
					body: slice
				}).then(function (r) {
					if (!r.ok) {
						return r.json().catch(function () { return null; }).then(function (err) {
							// On an offset mismatch the server reports where it actually
							// got to (nested under data, as every API response is), so a
							// lost chunk costs that chunk rather than the whole archive.
							var at = err && err.data && err.data.received_bytes;
							if (typeof at === 'number' && at !== sent) {
								sent = at;
								return sendNext();
							}
							throw new Error(errorOf(err) || 'The upload was interrupted.');
						});
					}
					sent = end;
					say('Uploading ' + humanBytes(sent) + ' of ' + humanBytes(total)
						+ ' (' + Math.floor(sent * 100 / total) + '%)...');
					return sendNext();
				});
			}

			say('Uploading 0% of ' + humanBytes(total) + '...');
			return sendNext().then(function () {
				say('Finishing the upload...');
				return coreApi('drive_upload_complete', { upload_token: token });
			}).then(function (j2) {
				var f = (j2 && j2.data && j2.data.file) || null;
				if (!f || !f.id) { throw new Error(errorOf(j2)); }
				return f.id;
			});
		});
	}

	/** Queue the run against a file that is now on the server. */
	function startRun(fileId, sourceName, data) {
		if (!fileId) {
			say('Choose an archive to import — upload one, or pick a file you already have here.', true);
			return Promise.resolve();
		}
		say('Checking the archive...');
		return api('mail_import_start', {
			alias_id: data.get('alias_id'),
			own_addresses: data.get('own_addresses'),
			file_id: fileId
		}).then(function (j) {
			if (!j || !j.data || !j.data.run) { say(errorOf(j), true); return; }
			say(j.data.message);
			// Clear only the chosen file. A full form.reset() puts the mailbox and
			// the declared addresses back to their server-rendered defaults, which
			// reads as though the choices were discarded — and they are exactly what
			// a second import of the same account would want to keep.
			var fileField = form.querySelector('input[type=file]');
			if (fileField) { fileField.value = ''; }
			refresh();
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
			if (r.can_discard) {
				html += ' <button type="button" class="jy-btn" data-import-discard="'
					+ r.id + '">Discard archive</button>';
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
					// Re-render rather than reload: a full reload also throws away
					// whatever the user had typed into the start form.
					refresh();
				});
			return;
		}

		var discard = e.target.closest && e.target.closest('[data-import-discard]');
		if (discard) {
			if (!window.confirm('Delete the uploaded archive? The imported mail and this '
					+ 'report are kept — only the source file goes, and it can no longer be '
					+ 'used to re-run the import.')) {
				return;
			}
			say('Discarding...');
			api('mail_import_discard', { run_id: parseInt(discard.getAttribute('data-import-discard'), 10) })
				.then(function (j) {
					if (!j || !j.data) { say(errorOf(j), true); return; }
					say(j.data.message);
					refresh();
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
			// Close the chooser BEFORE refreshing: render() deliberately leaves the
			// table alone while one is open, so refreshing with it still up would
			// silently do nothing and the run would look stuck.
			var row = runsBox.querySelector('[data-choose-for="' + runId + '"]');
			if (row) { row.hidden = true; }
			refresh();
		});
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function escapeAttr(s) { return escapeHtml(s); }

	function humanBytes(n) {
		var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
		return (i === 0 ? n : Math.round(n * 10) / 10) + ' ' + units[i];
	}

	// One status call on load decides whether to poll at all, so a page with
	// nothing underway makes no further requests.
	refresh();
})();
</script>
	<?php
}

}
?>
