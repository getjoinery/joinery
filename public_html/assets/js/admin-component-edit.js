/*
 * /admin/admin_component_edit: choosing a type (adding) reloads the page for
 * that type's fields, and the advanced-fields toggles show and hide their
 * fields (adm/admin_component_edit.php).
 *
 * #component-edit-config: { type_change_base }, present when adding.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function() {
	// Main advanced fields toggle
	document.querySelectorAll('.advanced-fields-toggle').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var targetId = this.getAttribute('data-target');
			var target = document.getElementById(targetId);
			if (target) {
				var isHidden = target.style.display === 'none';
				target.style.display = isHidden ? 'block' : 'none';
				var count = target.querySelectorAll('.mb-3, .form-group').length;
				if (isHidden) {
					this.innerHTML = '<i class="fas fa-cog me-1"></i>Hide advanced fields';
				} else {
					this.innerHTML = '<i class="fas fa-cog me-1"></i>Show advanced fields (' + count + ')';
				}
			}
		});
	});

	// Repeater advanced fields toggle (use event delegation for dynamic rows)
	document.addEventListener('click', function(e) {
		if (e.target.closest('.repeater-advanced-toggle')) {
			e.preventDefault();
			var link = e.target.closest('.repeater-advanced-toggle');
			var targetId = link.getAttribute('data-target');
			var target = document.getElementById(targetId);
			if (target) {
				var isHidden = target.style.display === 'none';
				target.style.display = isHidden ? 'block' : 'none';
				var count = target.querySelectorAll('.col-md, .col-md-3, .col-md-5').length;
				if (isHidden) {
					link.innerHTML = '<i class="fas fa-cog me-1"></i>Hide advanced';
				} else {
					link.innerHTML = '<i class="fas fa-cog me-1"></i>Advanced (' + count + ')';
				}
			}
		}
	});
});

// Adding: a type chosen reloads the page with that type's fields.
(function () {
	var cfg = window.joineryPageData ? joineryPageData('component-edit-config') : null;
	var s = document.querySelector('[name=pac_com_component_id]');
	if (!cfg || !s || s.type === 'hidden') return;
	s.addEventListener('change', function () {
		if (this.value) window.location.href = cfg.type_change_base + 'component_type=' + encodeURIComponent(this.value);
	});
})();
