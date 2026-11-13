/*
 * /admin/server_manager/transfers: each [data-transfer-do] button calls the
 * transfer_operator action, asking in the kit's modal first when it has
 * data-confirm.
 *
 * @version 1.0
 */
document.querySelectorAll('[data-transfer-do]').forEach(function (button) {
	button.addEventListener('click', function () {
		if (button.dataset.confirm && window.JoineryModal) { JoineryModal.confirm(button.dataset.confirm, go); return; }
		if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) { return; }
		go();
	});
	function go() {
		var notice = document.getElementById('transferNotice');
		var params = { 'do': button.getAttribute('data-transfer-do') };
		if (button.dataset.provision) { params.provision_id = parseInt(button.dataset.provision, 10); }
		if (button.dataset.transfer) { params.transfer_id = parseInt(button.dataset.transfer, 10); }
		button.disabled = true;
		joineryApi.post('server_manager/transfer_operator', params).then(function () {
			window.location.reload();
		}).catch(function (err) {
			button.disabled = false;
			notice.hidden = false;
			notice.className = 'alert alert-danger';
			notice.textContent = (err && err.message) ? err.message : 'That did not work.';
		});
	}
});
