/*
 * The Auto-notes button (includes/ReleaseNotesDrafter.php): drafts release
 * notes into the box its data-target names. data-through, when set, is the
 * version the draft runs to.
 *
 * @version 1.0
 */
(function () {
	var btn = document.getElementById('auto_notes_btn');
	var status = document.getElementById('auto_notes_status');
	if (!btn) return;
	var box = document.querySelector(btn.getAttribute('data-target'));
	btn.addEventListener('click', function () {
		btn.disabled = true;
		status.textContent = 'Writing a draft…';
		window.joineryApi.post('server_manager/release_notes_draft', btn.getAttribute('data-through') ? { through: btn.getAttribute('data-through') } : {}).then(function (data) {
			if (data && data.ok) {
				box.value = data.notes;
				status.textContent = 'Drafted from ' + data.source + ' by ' + data.model + '. Read it over before publishing.';
			} else {
				status.textContent = (data && data.message) || 'No draft came back.';
			}
		}).catch(function (err) {
			status.textContent = 'The draft failed: ' + err.message;
		}).then(function () {
			btn.disabled = false;
		});
	});
})();
