/*
 * An event's admin page (admin/admin_event.php): a long description is cut
 * short, with Show all / Show less.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function(){
	var wrap = document.getElementById('evt-description-wrap');
	var inner = document.getElementById('evt-description-inner');
	var toggle = document.getElementById('evt-description-toggle');
	if (!wrap || !inner || !toggle) return;
	if (inner.scrollHeight > wrap.clientHeight) {
		toggle.style.display = 'inline-block';
	}
	var expanded = false;
	toggle.addEventListener('click', function(e){
		e.preventDefault();
		expanded = !expanded;
		wrap.style.maxHeight = expanded ? 'none' : '6em';
		wrap.style.overflow = expanded ? 'visible' : 'hidden';
		toggle.textContent = expanded ? 'Show less' : 'Show all';
	});
});
