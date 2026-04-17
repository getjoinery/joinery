/**
 * Get Joinery Marketing Theme — JavaScript v1.0.0
 */
(function() {
    'use strict';

    // --- Mobile nav toggle ---
    var toggle = document.getElementById('nav-toggle');
    var navLinks = document.getElementById('nav-links');
    if (toggle && navLinks) {
        toggle.addEventListener('click', function() {
            navLinks.classList.toggle('open');
        });
        // Close menu when clicking a link
        navLinks.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                navLinks.classList.remove('open');
            });
        });
    }

    // --- Pricing toggle (annual/monthly) ---
    var billingToggle = document.getElementById('billing-toggle');
    if (billingToggle) {
        var isAnnual = true;
        var labelMonthly = document.getElementById('label-monthly');
        var labelAnnual = document.getElementById('label-annual');

        function updatePricing() {
            var mode = isAnnual ? 'annual' : 'monthly';
            var otherMode = isAnnual ? 'monthly' : 'annual';

            // Update toggle state
            billingToggle.classList.toggle('active', isAnnual);
            if (labelMonthly) labelMonthly.classList.toggle('active', !isAnnual);
            if (labelAnnual) labelAnnual.classList.toggle('active', isAnnual);

            // Update prices
            document.querySelectorAll('.pricing-tier .price').forEach(function(el) {
                var priceSpan = el.querySelector('span[data-annual]');
                if (priceSpan) {
                    priceSpan.textContent = priceSpan.getAttribute('data-' + mode);
                }
            });

            // Update price notes
            document.querySelectorAll('.pricing-tier .price-note').forEach(function(el) {
                if (el.dataset.annual && el.dataset.monthly) {
                    el.textContent = el.getAttribute('data-' + mode);
                }
            });

            // Show/hide monthly strikethrough
            document.querySelectorAll('.pricing-tier .monthly-price').forEach(function(el) {
                el.style.display = isAnnual ? '' : 'none';
            });
        }

        billingToggle.addEventListener('click', function() {
            isAnnual = !isAnnual;
            updatePricing();
        });

        billingToggle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                isAnnual = !isAnnual;
                updatePricing();
            }
        });
    }

    // --- Smooth scroll for anchor links ---
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            var targetId = this.getAttribute('href');
            if (targetId === '#') return;
            var target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // --- Navbar shadow on scroll ---
    var nav = document.querySelector('.site-nav');
    if (nav) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 10) {
                nav.style.boxShadow = '0 1px 8px rgba(0,0,0,0.06)';
            } else {
                nav.style.boxShadow = 'none';
            }
        }, { passive: true });
    }

})();
