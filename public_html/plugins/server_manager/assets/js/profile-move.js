/*
 * /profile/server_manager: the move card's buttons call the transfer_customer
 * action; the code is fetched only when its button is pressed, and never kept
 * anywhere else. A button with data-confirm asks in the kit's modal first.
 *
 * @version 1.0
 */
/* The move card's buttons call the transfer_customer action; the code is
   fetched only when its button is pressed, and never kept anywhere else. */
document.querySelectorAll('.sms-move [data-do]').forEach(function (button) {
	var card = button.closest('.sms-move');
	var notice = card.querySelector('.sms-move-notice');
	var what = button.getAttribute('data-do');
	button.addEventListener('click', function () {
		if (button.dataset.confirm && window.JoineryModal) {
			JoineryModal.confirm(button.dataset.confirm, go);
		} else if (!button.dataset.confirm || window.confirm(button.dataset.confirm)) {
			go();
		}
	});
	function go() {
		notice.textContent = '';
		button.disabled = true;
		joineryApi.post('server_manager/transfer_customer', {
			'do': what, provision_id: parseInt(card.getAttribute('data-provision'), 10)
		}).then(function (data) {
			if (what === 'reveal_code' && data && data.code) {
				var box = card.querySelector('.sms-code');
				box.querySelector('[data-code]').textContent = data.code;
				box.hidden = false;
				button.hidden = true;
				return;
			}
			window.location.reload();
		}).catch(function (err) {
			button.disabled = false;
			notice.textContent = (err && err.message) ? err.message : 'That did not work. Try again in a minute.';
			var link = joineryApi.reportLink(err);
			if (link) { notice.appendChild(document.createTextNode(' ')); notice.appendChild(link); }
		});
	}
});
document.querySelectorAll('.sms-move [data-copy]').forEach(function (button) {
	button.addEventListener('click', function () {
		var code = button.closest('.sms-code').querySelector('[data-code]').textContent;
		if (navigator.clipboard) { navigator.clipboard.writeText(code); button.textContent = 'Copied'; }
	});
});
