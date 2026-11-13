/*
 * The resolve note (IncidentViews::resolve_note_script): before a form with a
 * resolve_note_field() resolves, a modal asks what fixed it, and the answer
 * goes into the form's note.
 *
 * @version 1.0
 */
(function () {
	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		var note = form.querySelector('input[data-resolve-note]');
		if (!note || form.dataset.resolveNoteAsked === '1' || ev.defaultPrevented) { return; }
		var pick = form.querySelector('[name="do"]');
		if (pick && pick.value !== 'resolved') { return; }
		var count = parseInt(note.getAttribute('data-resolve-note'), 10) || 0;
		if (count === 0 && form.id) {
			count = document.querySelectorAll('input[form="' + form.id + '"][name="ids[]"]:checked').length;
			// Nothing ticked: let the page say so.
			if (count === 0) { return; }
		}
		ev.preventDefault();
		var submitter = ev.submitter || null;
		var box = document.createElement('div');
		box.innerHTML = '<label class="form-label" for="incident_resolve_note">What fixed it? <span class="text-muted">(optional)</span></label>'
			+ '<textarea id="incident_resolve_note" class="form-control" rows="4" maxlength="4000"'
			+ ' placeholder="What was wrong and what fixed it. A commit or release number helps."></textarea>'
			+ '<p class="small text-muted mt-2 mb-0">It goes on the timeline of '
			+ (count === 1 ? 'this incident' : 'each of the ' + count + ' incidents') + ' as a note.</p>';
		var text = box.querySelector('textarea');
		JoineryModal.open(box, {buttons: [
			{label: 'Cancel', style: 'secondary'},
			{label: count === 1 ? 'Resolve' : 'Resolve ' + count, style: 'success', onClick: function () {
				note.value = text.value;
				form.dataset.resolveNoteAsked = '1';
				form.requestSubmit(submitter);
			}}
		]});
		text.focus();
	});
})();
