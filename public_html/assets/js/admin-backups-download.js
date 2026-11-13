/*
 * /admin/admin_backups: the Download a backup button builds one in the
 * background, confirming it is you first (adm/admin_backups.php).
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function () {
	function fail(e) {
		JoineryModal.alert((e && e.message) || 'That did not work.');
	}
	var start = document.getElementById('btn_download_start');
	// A build confirmed here that had to step up on /profile/verify-stepup (codes, no
	// passkey) is remembered for the trip and resumed on return. sessionStorage
	// is this site's own: a link from elsewhere cannot set it, as a URL could.
	var RESUME = 'joinery_backup_download_resume';
	function remembered() {
		// Only the trip just made, landing where the step-up returns (#download):
		// a step-up abandoned and a visit later starts nothing unconfirmed.
		try {
			var v = parseInt(sessionStorage.getItem(RESUME) || '0', 10);
			sessionStorage.removeItem(RESUME);
			return v > 0 && Date.now() - v < 180000 && location.hash === '#download';
		} catch (x) { return false; }
	}
	// resumed: the build the person confirmed before stepping up. If it still
	// asks for a step-up, it says so instead of sending them round again.
	function build(resumed) {
		start.disabled = true;
		var call = function () { return joineryApi.post('backup_download_start', {}); };
		// No return URL: without a usable passkey withStepUp hands the refusal
		// back, so the trip to /profile/verify-stepup below is the one that remembers
		// the build. Given a return URL it would leave for the page itself.
		var run = window.JoineryPasskeys ? JoineryPasskeys.withStepUp(call) : call();
		run.then(function () { window.location.reload(); })
			.catch(function (e) {
				start.disabled = false;
				if (e && e.data && e.data.requires_stepup && !resumed) {
					try { sessionStorage.setItem(RESUME, String(Date.now())); } catch (x) {}
					window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/admin/admin_backups#download');
					return;
				}
				fail(e);
			});
	}
	if (start) {
		start.addEventListener('click', function () {
			JoineryModal.confirm(start.getAttribute('data-confirm'), function () { build(false); }, { confirmLabel: 'Build the backup', confirmStyle: 'primary' });
		});
		if (remembered()) {
			build(true);
		}
	} else {
		remembered();
	}
	var del = document.getElementById('btn_download_delete');
	if (del) {
		del.addEventListener('click', function () {
			JoineryModal.confirm('Delete the backup built for download from this server?', function () {
				joineryApi.post('backup_download_delete', {})
					.then(function () { window.location.reload(); })
					.catch(fail);
			}, { confirmLabel: 'Delete it' });
		});
	}
});
