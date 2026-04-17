document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ------- Fullscreen height ------- //
    var fullscreenEls = document.querySelectorAll('.fullscreen');
    if (fullscreenEls.length) {
        fullscreenEls.forEach(function(el) {
            el.style.height = window.innerHeight + 'px';
        });
    }

    // ------- Header Scroll Class ------- //
    window.addEventListener('scroll', function() {
        var header = document.getElementById('header');
        if (header) {
            if (window.scrollY > 100) {
                header.classList.add('header-scrolled');
            } else {
                header.classList.remove('header-scrolled');
            }
        }
    });

    // ------- Mobile Nav ------- //
    var navMenuContainer = document.getElementById('nav-menu-container');
    if (navMenuContainer) {
        // Clone nav for mobile
        var mobileNav = navMenuContainer.cloneNode(true);
        mobileNav.id = 'mobile-nav';
        var innerUl = mobileNav.querySelector('ul');
        if (innerUl) {
            innerUl.className = '';
            innerUl.id = '';
        }
        document.body.appendChild(mobileNav);

        // Add overlay
        var overlay = document.createElement('div');
        overlay.id = 'mobile-body-overly';
        overlay.style.display = 'none';
        document.body.appendChild(overlay);

        // Add chevron toggles to submenu parents
        var menuHasChildren = mobileNav.querySelectorAll('.menu-has-children');
        menuHasChildren.forEach(function(item) {
            var icon = document.createElement('i');
            icon.className = 'lnr lnr-chevron-down';
            item.insertBefore(icon, item.firstChild);
        });

        // Submenu toggle
        document.addEventListener('click', function(e) {
            if (e.target && e.target.matches('#mobile-nav .menu-has-children i')) {
                var icon = e.target;
                var li = icon.parentElement;
                var submenu = li.querySelector('ul');
                li.classList.toggle('menu-item-active');
                if (icon.classList.contains('lnr-chevron-down')) {
                    icon.classList.replace('lnr-chevron-down', 'lnr-chevron-up');
                } else {
                    icon.classList.replace('lnr-chevron-up', 'lnr-chevron-down');
                }
                if (submenu) {
                    submenu.style.display = submenu.style.display === 'block' ? 'none' : 'block';
                }
            }
        });

        // Mobile nav toggle button
        var toggleBtn = document.getElementById('mobile-nav-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                document.body.classList.toggle('mobile-nav-active');
                var icon = toggleBtn.querySelector('i');
                if (icon) {
                    if (icon.classList.contains('lnr-menu')) {
                        icon.classList.replace('lnr-menu', 'lnr-cross');
                    } else {
                        icon.classList.replace('lnr-cross', 'lnr-menu');
                    }
                }
                overlay.style.display = overlay.style.display === 'block' ? 'none' : 'block';
                e.stopPropagation();
            });
        }

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!document.body.classList.contains('mobile-nav-active')) return;
            var mobileNavEl = document.getElementById('mobile-nav');
            var toggleBtnEl = document.getElementById('mobile-nav-toggle');
            if (mobileNavEl && toggleBtnEl) {
                if (!mobileNavEl.contains(e.target) && !toggleBtnEl.contains(e.target)) {
                    document.body.classList.remove('mobile-nav-active');
                    var icon = toggleBtnEl.querySelector('i');
                    if (icon) {
                        icon.className = 'lnr lnr-menu';
                    }
                    overlay.style.display = 'none';
                }
            }
        });
    }

    // ------- Accordion ------- //
    var accordions = document.querySelectorAll('.accordion');
    accordions.forEach(function(accordion) {
        var allDd = accordion.querySelectorAll('dd');
        allDd.forEach(function(dd) { dd.style.display = 'none'; });

        var firstDt = accordion.querySelector('dt > a');
        if (firstDt) {
            firstDt.classList.add('active');
            var firstDd = firstDt.closest('dt').nextElementSibling;
            if (firstDd && firstDd.tagName === 'DD') {
                firstDd.style.display = 'block';
            }
        }

        accordion.addEventListener('click', function(e) {
            var link = e.target.closest('dt > a');
            if (!link) return;
            e.preventDefault();
            var dt = link.parentElement;
            var dd = dt.nextElementSibling;
            var allLinks = accordion.querySelectorAll('dt > a');
            var allDds = accordion.querySelectorAll('dd');
            allLinks.forEach(function(a) { a.classList.remove('active'); });
            allDds.forEach(function(d) { d.style.display = 'none'; });
            link.classList.add('active');
            if (dd && dd.tagName === 'DD') {
                dd.style.display = 'block';
            }
        });
    });

    // ------- Testimonials Carousel ------- //
    var carousels = document.querySelectorAll('.active-review-carusel');
    carousels.forEach(function(carousel) {
        var items = carousel.querySelectorAll('.single-feedback-carusel');
        if (items.length <= 1) return;

        var current = 0;

        // Hide all, show first
        items.forEach(function(item, i) {
            item.style.display = i === 0 ? 'block' : 'none';
        });

        // Build dots
        var dotsContainer = document.createElement('div');
        dotsContainer.className = 'owl-dots';
        items.forEach(function(_, i) {
            var dot = document.createElement('button');
            dot.className = 'owl-dot' + (i === 0 ? ' active' : '');
            dot.innerHTML = '<span></span>';
            dot.addEventListener('click', function() {
                showSlide(i);
            });
            dotsContainer.appendChild(dot);
        });
        carousel.appendChild(dotsContainer);

        function showSlide(idx) {
            items[current].style.display = 'none';
            dotsContainer.querySelectorAll('.owl-dot')[current].classList.remove('active');
            current = (idx + items.length) % items.length;
            items[current].style.display = 'block';
            dotsContainer.querySelectorAll('.owl-dot')[current].classList.add('active');
        }

        // Auto-rotate every 5 seconds
        var autoplay = setInterval(function() {
            showSlide(current + 1);
        }, 5000);

        // Pause on hover
        carousel.addEventListener('mouseenter', function() { clearInterval(autoplay); });
        carousel.addEventListener('mouseleave', function() {
            autoplay = setInterval(function() { showSlide(current + 1); }, 5000);
        });
    });

    // ------- Smooth Scroll for hash links ------- //
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[href^="#"]');
        if (!link) return;
        var hash = link.getAttribute('href');
        if (!hash || hash === '#') return;
        var target = document.querySelector(hash);
        if (!target) return;
        e.preventDefault();
        var header = document.getElementById('header');
        var topSpace = header ? header.offsetHeight : 0;
        var targetTop = target.getBoundingClientRect().top + window.scrollY - topSpace;
        window.scrollTo({ top: targetTop, behavior: 'smooth' });
    });

});
