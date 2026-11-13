/*
 * Every [data-relay-grant] button (includes/relay_section.php): open its
 * hidden step in the system modal, copying the fields it names across.
 *
 * @version 1.0
 */
document.addEventListener('click', function (e) {
	var b = e.target.closest ? e.target.closest('[data-relay-grant]') : null;
	if (!b || !window.JoineryModal) return;
	e.preventDefault();
	var holder = document.getElementById(b.getAttribute('data-relay-grant'));
	if (!holder || !holder.firstElementChild) return;
	var node = holder.firstElementChild;
	var from = b.getAttribute('data-copy-from');
	var src = from ? document.getElementById(from) : null;
	if (src) {
		node.querySelectorAll('input[data-copy]').forEach(function (i) {
			var f = src.querySelector('[name="' + i.getAttribute('data-copy') + '"]');
			if (f) i.value = f.value;
		});
	}
	var m = JoineryModal.open(node, { buttons: [{ label: 'Cancel', style: 'secondary' }] });
	// Back into its holder when the modal closes, so it opens again.
	m.dialog.addEventListener('close', function () { holder.appendChild(node); }, { once: true });
});
