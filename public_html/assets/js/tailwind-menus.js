/*
 * The Tailwind themes' header menus (PublicPageTailwindHTML5): dropdowns
 * (.js-clickable-menu opens the .js-clicked-menu after it), the user menu,
 * the mobile menu, and closing them on a click elsewhere.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function() {
	// Dropdown menus
	document.querySelectorAll('.js-clickable-menu').forEach(function(btn) {
		btn.addEventListener('click', function(e) {
			var clickedMenu = null;
			var sibling = btn.nextElementSibling;
			while (sibling) {
				if (sibling.classList.contains('js-clicked-menu')) { clickedMenu = sibling; break; }
				sibling = sibling.nextElementSibling;
			}
			if (clickedMenu) {
				clickedMenu.classList.toggle('invisible');
				document.querySelectorAll('.js-clicked-menu').forEach(function(m) {
					if (m !== clickedMenu) m.classList.add('invisible');
				});
			}
			e.stopPropagation();
		});
	});

	// User menu button
	var userMenuBtn = document.getElementById('user-menu-button');
	if (userMenuBtn) {
		userMenuBtn.addEventListener('click', function(e) {
			var menu = document.getElementById('user-menu');
			if (menu) menu.classList.toggle('invisible');
			e.stopPropagation();
		});
	}

	// Mobile menu open/close
	var mobileToggle = document.getElementById('mobile-toggle-button');
	var mobileClose = document.getElementById('mobile-close-button');
	var mobileMenu = document.getElementById('mobile-menu');
	if (mobileToggle && mobileMenu) {
		mobileToggle.addEventListener('click', function() { mobileMenu.classList.remove('invisible'); });
	}
	if (mobileClose && mobileMenu) {
		mobileClose.addEventListener('click', function() { mobileMenu.classList.add('invisible'); });
	}

	// Close dropdowns on outside click
	document.addEventListener('click', function() {
		document.querySelectorAll('.js-clicked-menu').forEach(function(m) { m.classList.add('invisible'); });
	});
});
