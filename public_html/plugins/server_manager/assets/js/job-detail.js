/*
 * A running job's page (views/admin/job_detail.php): polls job_status, adds
 * new output, and says how it ended. #job-detail-config: { job_id, offset,
 * total_steps, reload_on_complete, reload_on_end }.
 *
 * @version 1.0
 */
(function() {
	var outputEl = document.getElementById('job-output');
	var statusEl = document.getElementById('job-status');
	var progressEl = document.getElementById('job-progress');
	var CFG = (window.joineryPageData && joineryPageData('job-detail-config')) || {};
	var offset = CFG.offset | 0;
	var polling = true;

	function poll() {
		if (!polling) return;
		smApiPost('job_status', { job_id: CFG.job_id | 0, output_offset: offset })
			.then(function(data) {
				if (!data.success) return;

				if (data.new_output) {
					if (offset === 0 && outputEl.textContent === 'Waiting for output...') {
						outputEl.textContent = '';
					}
					outputEl.textContent += data.new_output;
					offset = data.output_offset;
					outputEl.scrollTop = outputEl.scrollHeight;
				}

				var totalSteps = CFG.total_steps | 0;
				// Translate 0-based raw index to user-facing completed count.
				var doneSteps;
				if (data.status === 'completed') {
					doneSteps = totalSteps;
				} else {
					doneSteps = data.current_step;
				}
				progressEl.textContent = doneSteps;

				if (data.status !== 'queued' && data.status !== 'pending' && data.status !== 'running') {
					polling = false;
					statusEl.textContent = data.status;
					statusEl.className = 'badge bg-' + (data.status === 'completed' ? 'success' : 'danger');
					// A deploy: the page says what became of it, and loading it starts the rollout.
					if (CFG.reload_on_complete && data.status === 'completed') { window.location.reload(); return; }
					// A publish that follows: the page says what became of it.
					if (CFG.reload_on_end) { window.location.reload(); return; }
					if (data.error_message) {
						var errDiv = document.createElement('div');
						errDiv.className = 'alert alert-danger mt-2';
						errDiv.textContent = 'Error: ' + data.error_message;
						statusEl.parentNode.appendChild(errDiv);
					}
					return;
				}

				setTimeout(poll, 2000);
			})
			.catch(function() {
				setTimeout(poll, 5000);
			});
	}

	setTimeout(poll, 2000);
})();
