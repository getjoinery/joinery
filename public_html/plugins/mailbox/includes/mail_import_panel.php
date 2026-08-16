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
 * @version 1.4
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
		if ($run['skipped'] > 0) { $html .= ', ' . number_format($run['skipped']) . ' left out'; }
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
	var attentionBox = document.getElementById('mail-import-attention');
	var startBox = document.getElementById('mail-import-start');
	var busyBox = document.getElementById('mail-import-busy');
	var polling = null;

	// Calls go through the shared transport (window.joineryApi), which owns the
	// CSRF token and its cookie-first lookup. The panel's own callers read the
	// API envelope, so a refusal is handed back in envelope shape rather than
	// raised — every one of them already turns {error} into a message on screen.
	function post(path, body) {
		return joineryApi.post(path, body).then(function (data) {
			return { data: data };
		}).catch(function (err) {
			return {
				error: err.message,
				errortype: err.errorType,
				validation_errors: err.validationErrors,
				data: err.data || {}
			};
		});
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

	/**
	 * Show either the start form or the reason it is not there.
	 *
	 * The server decides which run holds the one-at-a-time slot and hands it over
	 * as busy_run, so this never has to re-derive the rule from the history table —
	 * a page that let you start a second import the API would refuse is worse than
	 * one that simply waits.
	 */
	function renderStart(busy) {
		if (startBox) { startBox.hidden = !!busy; }
		if (!busyBox) { return; }
		busyBox.hidden = !busy;
		if (!busy) { busyBox.innerHTML = ''; return; }

		var name = busy.source || 'An archive';
		// Same two sentences the server renders, for the same reason: the waiting
		// run's label already contains a dash, so it is said rather than pasted.
		var what = busy.can_choose
			? 'has been read and is waiting for your answer.'
			: '&mdash; ' + escapeHtml(lowerFirst(busy.state_label || '')) + '.';
		var tail = busy.can_choose
			? 'Answer the question above to let it carry on. Starting another import is '
				+ 'offered again once it finishes.'
			: 'Only one import runs at a time. Starting another is offered again as soon as '
				+ 'this one finishes — you do not need to stay on this page.';
		busyBox.innerHTML = '<div class="jy-callout jy-callout-info">'
			+ '<h3 class="jy-callout-title">An import is already going</h3>'
			+ '<p><strong>' + escapeHtml(name) + '</strong> ' + what + '</p>'
			+ '<p class="jy-muted">' + tail + '</p></div>';
	}

	function lowerFirst(s) { return s ? s.charAt(0).toLowerCase() + s.slice(1) : s; }

	function refresh() {
		return api('mail_import_status', {}).then(function (j) {
			var runs = (j && j.data && j.data.runs) || [];
			render(runs);
			renderStart((j && j.data && j.data.busy_run) || null);
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
	// fields afterwards. The waiting-for-you banners are reconciled separately, so
	// the progress numbers keep ticking while a decision is still open.
	function render(runs) {
		if (!runsBox) { return; }

		if (!runs.length) {
			renderAttention(runs);
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

			// The choose action is in the banner at the top, never here.
			if (r.can_undo) {
				html += '<button type="button" class="btn btn-danger" data-import-undo="'
					+ r.id + '">Undo this import</button>';
			}
			if (r.can_discard) {
				html += ' <button type="button" class="btn btn-secondary" data-import-discard="'
					+ r.id + '">Discard archive</button>';
			}
			html += '</td></tr>';
		});

		runsBox.innerHTML = html + '</tbody></table>';
		renderAttention(runs);
	}

	/**
	 * Put every run that is waiting on a decision at the top of the page, with its
	 * folder list already open.
	 *
	 * A banner the user is part-way through answering is left alone — re-rendering
	 * it under them would throw away their ticks. That is why the loaded set is
	 * tracked rather than rebuilt every poll.
	 */
	function renderAttention(runs) {
		if (!attentionBox) { return; }

		var waiting = runs.filter(function (r) { return r.can_choose; });
		var wanted = waiting.map(function (r) { return String(r.id); });

		// Drop banners for runs that have moved on.
		attentionBox.querySelectorAll('[data-attention-for]').forEach(function (el) {
			if (wanted.indexOf(el.getAttribute('data-attention-for')) === -1) { el.remove(); }
		});

		waiting.forEach(function (r) {
			if (attentionBox.querySelector('[data-attention-for="' + r.id + '"]')) { return; }
			var section = document.createElement('section');
			section.className = 'jy-callout jy-callout-action';
			section.setAttribute('data-attention-for', r.id);
			section.setAttribute('role', 'region');
			section.setAttribute('aria-label', 'Import waiting for you');
			section.innerHTML = '<h3 class="jy-callout-title">This import is waiting for you</h3>'
				+ '<p>Read ' + r.total.toLocaleString() + ' messages in <strong>'
				+ escapeHtml(r.source || 'the archive') + '</strong>. '
				+ 'Nothing has been imported yet — tick what to bring across.</p>'
				+ '<div data-chooser-for="' + r.id + '"><p class="jy-muted">Counting...</p></div>';
			attentionBox.appendChild(section);
			openChooser(r.id);
		});
	}

	document.addEventListener('click', function (e) {
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
		var box = attentionBox && attentionBox.querySelector('[data-chooser-for="' + runId + '"]');
		if (!box) { return; }
		box.innerHTML = '<p class="jy-muted">Counting...</p>';

		api('mail_import_status', { run_id: parseInt(runId, 10) }).then(function (j) {
			var preview = j && j.data && j.data.preview;
			if (!preview) { box.innerHTML = '<p>' + errorOf(j) + '</p>'; return; }

			var html = '<fieldset class="jy-fieldset"><legend>Found in this archive</legend>';
			Object.keys(preview.folders).forEach(function (name) {
				html += '<label class="jy-check"><input type="checkbox" checked value="'
					+ escapeAttr(name) + '" data-folder-for="' + runId + '"> '
					+ escapeHtml(name) + ' <span class="jy-muted jy-check-count">'
					+ preview.folders[name].toLocaleString() + '</span></label>';
			});
			// Spam and Trash arrive unticked: an archive's spam folder is usually the
			// biggest thing in it and almost never what anyone meant to keep.
			if (preview.spam > 0) {
				html += '<label class="jy-check"><input type="checkbox" data-spam-for="' + runId
					+ '"> Spam <span class="jy-muted jy-check-count">' + preview.spam.toLocaleString() + '</span></label>';
			}
			if (preview.trash > 0) {
				html += '<label class="jy-check"><input type="checkbox" data-trash-for="' + runId
					+ '"> Trash <span class="jy-muted jy-check-count">' + preview.trash.toLocaleString() + '</span></label>';
			}
			html += '</fieldset><button type="button" class="btn btn-primary" data-import-go="'
				+ runId + '">Import the ticked folders</button>';
			box.innerHTML = html;
		});
	}

	function submitChoice(runId) {
		if (!attentionBox) { return; }
		var folders = [];
		attentionBox.querySelectorAll('[data-folder-for="' + runId + '"]').forEach(function (box) {
			if (box.checked) { folders.push(box.value); }
		});
		var spamBox = attentionBox.querySelector('[data-spam-for="' + runId + '"]');
		var trashBox = attentionBox.querySelector('[data-trash-for="' + runId + '"]');

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
			// Drop the banner BEFORE refreshing. render() deliberately leaves an open
			// chooser alone, so refreshing with this one still up would silently do
			// nothing and the run would look stuck.
			var section = attentionBox.querySelector('[data-attention-for="' + runId + '"]');
			if (section) { section.remove(); }
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

	// Banners rendered by PHP arrive with an empty folder list, because counting
	// them is a query the page render deliberately does not make. Fill them in
	// before anything else, so a waiting import is answerable the moment it paints.
	if (attentionBox) {
		attentionBox.querySelectorAll('[data-chooser-for]').forEach(function (el) {
			openChooser(el.getAttribute('data-chooser-for'));
		});
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
