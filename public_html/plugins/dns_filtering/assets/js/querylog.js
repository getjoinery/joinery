/*
 * /profile/dns_filtering/querylog: Clear log empties this device's query log.
 * #querylog-config: { device_id }.
 *
 * @version 1.1 - Clear log asks in the kit's modal
 * @version 1.0
 */
(function () {
	var clearBtn = document.getElementById('scd-clear-btn');
	var statusEl = document.getElementById('scd-clear-status');
	var deviceId = ((window.joineryPageData && joineryPageData('querylog-config')) || {}).device_id | 0;

	if (!clearBtn) return;

	function clearLog() {
		clearBtn.disabled = true;
		statusEl.textContent = 'Clearing\u2026';

		joineryApi.post('dns_filtering/purge_querylog', { device_id: deviceId })
		.then(function () {
			window.location.reload();
		}).catch(function (err) {
			statusEl.textContent = err.message || 'Error clearing log.';
			clearBtn.disabled = false;
		});
	}
	clearBtn.addEventListener('click', function () {
		var msg = 'Are you sure you want to clear the query log for this device? This cannot be undone.';
		if (window.JoineryModal) {
			JoineryModal.confirm(msg, clearLog, { confirmLabel: 'Clear log', confirmStyle: 'danger' });
		} else if (confirm(msg)) {
			clearLog();
		}
	});
})();
