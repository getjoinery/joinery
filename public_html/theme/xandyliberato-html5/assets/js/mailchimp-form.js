/**
 * Checks the Mailchimp sign-up form pasted into page content before it is sent.
 *
 * The embed code asks for a script on Mailchimp's host, which the site's
 * policy refuses, and marks the form novalidate, so without this file nothing
 * is checked until Mailchimp's own page answers. This file reads the form's own
 * markup: a field with the class "required" must be filled, an email field must
 * hold an address, and a date of birth, when any part is filled, must be a real
 * day, month and year. A problem is written under its field and the form stays;
 * a clean form is sent the ordinary way, to the address the form names.
 *
 * @version 1.0
 */
(function () {
	'use strict';

	function fieldLabel(group) {
		var label = group ? group.querySelector('label') : null;
		if (!label) {
			return 'This field';
		}
		return label.textContent.replace('*', '').replace(/\s+/g, ' ').trim() || 'This field';
	}

	function clearError(group) {
		var old = group.querySelector('.mce_inline_error');
		if (old) {
			old.remove();
		}
		group.querySelectorAll('[aria-invalid]').forEach(function (input) {
			input.removeAttribute('aria-invalid');
			input.removeAttribute('aria-describedby');
		});
	}

	function showError(group, inputs, message, index) {
		clearError(group);
		var note = document.createElement('div');
		note.className = 'mce_inline_error';
		note.id = 'mce-error-' + index;
		note.setAttribute('role', 'alert');
		note.textContent = message;
		group.appendChild(note);
		inputs.forEach(function (input) {
			input.setAttribute('aria-invalid', 'true');
			input.setAttribute('aria-describedby', note.id);
		});
	}

	function dateProblem(parts) {
		var day = parts.day.value.trim(), month = parts.month.value.trim(), year = parts.year.value.trim();
		if (day === '' && month === '' && year === '') {
			return '';
		}
		if (!/^\d{1,2}$/.test(day) || !/^\d{1,2}$/.test(month) || !/^\d{4}$/.test(year)) {
			return 'Enter the date as day, month and four-digit year, or leave all three empty.';
		}
		var d = Number(day), m = Number(month), y = Number(year);
		var made = new Date(Date.UTC(y, m - 1, d));
		if (made.getUTCFullYear() !== y || made.getUTCMonth() !== m - 1 || made.getUTCDate() !== d) {
			return 'That date does not exist.';
		}
		return '';
	}

	function check(form) {
		var first = null;
		form.querySelectorAll('.mc-field-group').forEach(function (group, index) {
			clearError(group);
			var problem = '';
			var bad = [];

			var day = group.querySelector('input[name$="[day]"]');
			var month = group.querySelector('input[name$="[month]"]');
			var year = group.querySelector('input[name$="[year]"]');
			if (day && month && year) {
				problem = dateProblem({ day: day, month: month, year: year });
				if (problem) {
					bad = [day, month, year];
				}
			} else {
				var input = group.querySelector('input:not([type="hidden"]), select, textarea');
				if (!input) {
					return;
				}
				var value = input.value.trim();
				if (value === '' && input.classList.contains('required')) {
					problem = fieldLabel(group) + ' is required.';
				} else if (value !== '' && (input.type === 'email' || input.classList.contains('email'))
					&& !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
					problem = 'Enter an email address like name@example.com.';
				}
				if (problem) {
					bad = [input];
				}
			}

			if (problem) {
				showError(group, bad, problem, index);
				if (!first) {
					first = bad[0];
				}
			}
		});
		return first;
	}

	function attach(form) {
		form.addEventListener('submit', function (event) {
			var first = check(form);
			if (first) {
				event.preventDefault();
				first.focus();
			}
		});
		form.addEventListener('input', function (event) {
			var group = event.target.closest ? event.target.closest('.mc-field-group') : null;
			if (group && group.querySelector('.mce_inline_error')) {
				clearError(group);
			}
		});
	}

	function start() {
		document.querySelectorAll('form#mc-embedded-subscribe-form, #mc_embed_signup form').forEach(attach);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
