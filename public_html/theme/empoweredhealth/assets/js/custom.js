/**
 * Empowered Health Theme - Custom JavaScript
 */

(function() {
    'use strict';

    // DOM Ready
    document.addEventListener('DOMContentLoaded', function() {
        initSmoothScroll();
        initActiveNavLinks();
    });

    /**
     * Smooth scroll for anchor links
     */
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                var targetId = this.getAttribute('href');
                if (targetId === '#') return;

                var target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }

    /**
     * Set active class on navigation based on current page
     */
    function initActiveNavLinks() {
        var currentPath = window.location.pathname;

        document.querySelectorAll('.bottom-nav .nav-list a, .mobile-nav-list a').forEach(function(link) {
            var linkPath = link.getAttribute('href');
            if (linkPath === currentPath || (currentPath === '/' && linkPath === '/')) {
                link.classList.add('active');
            }
        });
    }

    /**
     * Handle testimonials carousel touch/drag
     */
    var carousel = document.querySelector('.testimonials-carousel');
    if (carousel) {
        var isDown = false;
        var startX;
        var scrollLeft;

        carousel.addEventListener('mousedown', function(e) {
            isDown = true;
            carousel.classList.add('active');
            startX = e.pageX - carousel.offsetLeft;
            scrollLeft = carousel.scrollLeft;
        });

        carousel.addEventListener('mouseleave', function() {
            isDown = false;
            carousel.classList.remove('active');
        });

        carousel.addEventListener('mouseup', function() {
            isDown = false;
            carousel.classList.remove('active');
        });

        carousel.addEventListener('mousemove', function(e) {
            if (!isDown) return;
            e.preventDefault();
            var x = e.pageX - carousel.offsetLeft;
            var walk = (x - startX) * 2;
            carousel.scrollLeft = scrollLeft - walk;
        });
    }
})();
