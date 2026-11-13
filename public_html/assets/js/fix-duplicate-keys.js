/*
 * utils/fix_duplicate_keys.php loads no kit, so its Apply Fixes button asks
 * here: a submit button with data-confirm posts only when the browser's
 * confirm says yes.
 *
 * @version 1.0
 */
document.addEventListener('click', function (e) {
	var btn = e.target.closest ? e.target.closest('button[type="submit"][data-confirm]') : null;
	if (btn && !window.confirm(btn.getAttribute('data-confirm'))) e.preventDefault();
});
