/*
 * The DNS server access panel on the settings page (includes/settings_actions.php):
 * make or remove a server's access secret, shown once.
 *
 * @version 1.0
 */
(function () {
	var status = document.getElementById('dns-access-status');
	var secretBox = document.getElementById('dns-access-secret');
	var secretLine = document.getElementById('dns-access-secret-line');

	function run(action, slot, then) {
		status.textContent = 'Working…';
		joineryApi.post(action, { slot: slot }).then(function (data) {
			status.textContent = '';
			then(data);
		}).catch(function (e) {
			status.textContent = e.message || 'That did not work.';
		});
	}

	document.querySelectorAll('.dns-access-issue').forEach(function (button) {
		button.addEventListener('click', function () {
			var slot = button.getAttribute('data-slot');
			var issue = function () {
				run('dns_filtering/resolver_key_issue', slot, function (data) {
					secretLine.textContent = data.sites_entry;
					secretBox.style.display = '';
				});
			};
			if (button.getAttribute('data-replacing') === '1') {
				JoineryModal.confirm('Issue a new key for this server? Its current key stops working at once, and the server stops updating until its SCD_JOINERY_SITES entry holds the new one.', issue);
			} else {
				issue();
			}
		});
	});

	document.querySelectorAll('.dns-access-revoke').forEach(function (button) {
		button.addEventListener('click', function () {
			var slot = button.getAttribute('data-slot');
			JoineryModal.confirm('Revoke this server\'s key? It stops getting updates from this site and keeps filtering from its cached copy.', function () {
				run('dns_filtering/resolver_key_revoke', slot, function () {
					window.location.reload();
				});
			});
		});
	});

	document.getElementById('dns-access-secret-done').addEventListener('click', function () {
		secretLine.textContent = '';
		secretBox.style.display = 'none';
		window.location.reload();
	});
})();
