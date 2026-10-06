/**
 * The Contacts page (/profile/mailbox/contacts): add, import, edit, delete, and
 * the unlock for a sealed store. Every change is one mailbox API action —
 * contacts_import, contact_save, contact_delete — and then the page reloads on
 * the same mailbox, so the server-rendered list is always what is stored.
 *
 * Page contract: window.MAILBOX_CONTACTS = {alias_id, edit_id}; the forms
 * #contact-add-form, #contact-import-form and #contact-edit-form (in the
 * [data-contacts-dialog] dialog); one .mbx-cp-row per contact carrying
 * data-contact-id / data-name / data-address; edit_id opens that contact's
 * dialog on arrival (the mail page's pencil).
 *
 * Vanilla JS. @version 1.0
 */
(function () {
	'use strict';

	var cfg = window.MAILBOX_CONTACTS || {};
	var here = '/profile/mailbox/contacts?mailbox=' + encodeURIComponent(cfg.alias_id || '');

	function note(sel, text) {
		var n = document.querySelector(sel);
		if (!n) return;
		n.textContent = text || '';
		n.hidden = !text;
	}

	function reload() { window.location.href = here; }

	function busy(form, on) {
		var btn = form.querySelector('[type="submit"]');
		if (btn) btn.disabled = on;
	}

	document.addEventListener('DOMContentLoaded', function () {
		var unlock = document.querySelector('[data-contacts-unlock]');
		if (unlock) {
			unlock.addEventListener('click', function () {
				if (!window.JoineryVaultLock) { reload(); return; }
				unlock.disabled = true;
				Promise.resolve(window.JoineryVaultLock.unlock()).then(function (ok) {
					if (ok) reload(); else unlock.disabled = false;
				}, function () { unlock.disabled = false; });
			});
		}

		var add = document.getElementById('contact-add-form');
		if (add) {
			add.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var address = add.querySelector('[name="new_contact_email"]').value.trim();
				if (!address) { note('[data-contacts-add-note]', 'Enter an email address.'); return; }
				busy(add, true);
				note('[data-contacts-add-note]', 'Adding…');
				window.joineryApi.post('mailbox/contacts_import', {
					alias_id: String(cfg.alias_id),
					address: address,
					name: add.querySelector('[name="new_contact_name"]').value,
				}).then(reload, function (e) {
					busy(add, false);
					note('[data-contacts-add-note]', (e && e.message) || 'The contact could not be added.');
				});
			});
		}

		var imp = document.getElementById('contact-import-form');
		if (imp) {
			imp.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var file = imp.querySelector('[name="contact_file"]');
				if (!file || !file.files || !file.files.length) { note('[data-contacts-import-note]', 'Choose a .vcf or .csv file first.'); return; }
				var fd = new FormData();
				fd.append('file', file.files[0], file.files[0].name);
				fd.append('alias_id', String(cfg.alias_id));
				busy(imp, true);
				note('[data-contacts-import-note]', 'Importing…');
				window.joineryApi.postForm('mailbox/contacts_import', fd).then(function (d) {
					d = d || {};
					note('[data-contacts-import-note]', 'Imported ' + (d.imported || 0) + ', skipped ' + (d.skipped || 0) + '. Reloading…');
					setTimeout(reload, 900);
				}, function (e) {
					busy(imp, false);
					note('[data-contacts-import-note]', (e && e.message) || 'The contacts could not be imported.');
				});
			});
		}

		var dialog = document.querySelector('[data-contacts-dialog]');
		var edit = document.getElementById('contact-edit-form');
		if (!dialog || !edit) return;
		var idField = edit.querySelector('[name="edit_contact_id"]');
		var nameField = edit.querySelector('[name="edit_contact_name"]');
		var addrField = edit.querySelector('[name="edit_contact_email"]');

		function openFor(row) {
			idField.value = row.getAttribute('data-contact-id');
			nameField.value = row.getAttribute('data-name') || '';
			addrField.value = row.getAttribute('data-address') || '';
			note('[data-contacts-edit-note]', '');
			busy(edit, false);
			dialog.showModal();
			(nameField.value ? addrField : nameField).focus();
		}

		document.querySelectorAll('[data-contacts-edit]').forEach(function (btn) {
			btn.addEventListener('click', function () { openFor(btn.closest('.mbx-cp-row')); });
		});
		dialog.querySelector('[data-contacts-cancel]').addEventListener('click', function () { dialog.close(); });

		edit.addEventListener('submit', function (ev) {
			ev.preventDefault();
			busy(edit, true);
			note('[data-contacts-edit-note]', 'Saving…');
			window.joineryApi.post('mailbox/contact_save', {
				contact_id: idField.value,
				name: nameField.value,
				address: addrField.value.trim(),
			}).then(reload, function (e) {
				busy(edit, false);
				note('[data-contacts-edit-note]', (e && e.message) || 'The contact could not be saved.');
			});
		});

		dialog.querySelector('[data-contacts-delete]').addEventListener('click', function () {
			var who = nameField.value || addrField.value;
			var ask = window.JoineryModal && window.JoineryModal.confirmAsync
				? window.JoineryModal.confirmAsync('Delete ' + who + ' from your contacts?')
				: Promise.resolve(window.confirm('Delete ' + who + ' from your contacts?'));
			ask.then(function (yes) {
				if (!yes) return;
				note('[data-contacts-edit-note]', 'Deleting…');
				window.joineryApi.post('mailbox/contact_delete', { contact_id: idField.value }).then(reload, function (e) {
					note('[data-contacts-edit-note]', (e && e.message) || 'The contact could not be deleted.');
				});
			});
		});

		// Arriving from the mail page's pencil beside a contact.
		if (cfg.edit_id) {
			var row = document.querySelector('.mbx-cp-row[data-contact-id="' + parseInt(cfg.edit_id, 10) + '"]');
			if (row) {
				row.scrollIntoView({ block: 'center' });
				openFor(row);
			}
		}
	});
})();
