/*
 * One mailbox's editor (admin/admin_mailbox_imap_edit.php). The page's
 * #mailbox-imap-config says which parts apply:
 *   receipt_title  the raise receipt's heading says when sealing is done;
 *   unseal_loop    a lowering's unseal passes for this mailbox run here.
 *
 * @version 1.0
 */
(function () {
'use strict';
var CFG = (window.joineryPageData && joineryPageData('mailbox-imap-config')) || {};
if (CFG.receipt_title) {
document.addEventListener('ceremony:done', function () {
	var card = document.getElementById('raise-receipt');
	var title = document.getElementById('receipt-title');
	if (card && title && card.dataset.titleDone) { title.textContent = card.dataset.titleDone; }
});
}
if (CFG.unseal_loop) {
(function () {
	var card = document.getElementById('lowering-receipt');
	if (!card || card.dataset.windowOpen !== '1') return;
	var aliasId = parseInt(card.dataset.aliasId, 10) || 0;
	function pass() {
		joineryApi.post('mailbox/unseal_batch', { alias_id: aliasId }).then(function (d) {
			d = d || {};
			if (d.locked || !d.unsealed || !(d.own_remaining > 0)) {
				window.location.reload();
				return;
			}
			pass();
		}).catch(function () { /* the next visit resumes */ });
	}
	pass();
})();
}
})();
