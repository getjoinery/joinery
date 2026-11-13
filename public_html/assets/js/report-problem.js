/*
 * /report_problem: the report form posts through the API and says what came back
 * (views/report_problem.php).
 *
 * @version 1.0
 */
(function () {
	var form = document.getElementById('report_problem_form');
	if (!form) return;
	var errorBox = document.getElementById('report-problem-error');
	var doneBox = document.getElementById('report-problem-done');
	var panel = document.getElementById('report-problem-form-panel');
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var button = form.querySelector('[type="submit"]');
		if (button) { button.disabled = true; }
		errorBox.hidden = true;
		window.joineryApi.postForm('report_problem_submit', new FormData(form)).then(function (data) {
			doneBox.textContent = data.message || 'Thank you. Your report is saved.';
			doneBox.hidden = false;
			panel.hidden = true;
			doneBox.scrollIntoView({ block: 'nearest' });
		}).catch(function (err) {
			errorBox.textContent = (err && err.message) || 'The report could not be saved. Please try again.';
			errorBox.hidden = false;
			if (button) { button.disabled = false; }
		});
	});
})();
