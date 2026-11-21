/**
 * Your Sites: a Copy button beside every DNS name and value a buyer is asked
 * to add at their registrar, and beside the one-time admin password, so
 * nothing is retyped.
 *
 * @version 1.0
 */
document.querySelectorAll('.sms-dns dd > code, .sms-password > code').forEach(function (code) {
	if (!navigator.clipboard) { return; }
	var button = document.createElement('button');
	button.type = 'button';
	button.className = 'sms-copy';
	button.textContent = 'Copy';
	button.setAttribute('aria-label', code.closest('.sms-password') ? 'Copy the password' : 'Copy ' + code.textContent);
	button.addEventListener('click', function () {
		navigator.clipboard.writeText(code.textContent).then(function () {
			button.textContent = 'Copied';
			setTimeout(function () { button.textContent = 'Copy'; }, 2000);
		});
	});
	code.insertAdjacentElement('afterend', button);
});
