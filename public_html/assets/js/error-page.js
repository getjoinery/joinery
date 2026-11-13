/*
 * The error pages (includes/ErrorClasses.php): Go Back is the browser's own
 * Back when there is a page to go back to, so a form keeps what was typed in
 * it. Otherwise the link's own address (the referring page on this site, or
 * the home page) is followed.
 *
 * @version 1.0
 */
document.addEventListener('click', function (e) {
	var a = e.target.closest ? e.target.closest('a[data-jy-history-back]') : null;
	if (!a || window.history.length < 2) return;
	e.preventDefault();
	window.history.back();
});
