/*
 * The setup wizard (views/setup.php): the "Finish later" dialog.
 *
 * @version 1.0
 */
(function () {
	var dialog = document.getElementById('setup-dismiss');
	var open = document.getElementById('setup-finish-later');
	var cancel = document.getElementById('setup-dismiss-cancel');
	if (open && dialog) {
		open.addEventListener('click', function () { dialog.showModal(); });
	}
	if (cancel && dialog) {
		cancel.addEventListener('click', function () { dialog.close(); });
	}
})();
