/**
 * Get Joinery Marketing Theme — JavaScript v1.1.0
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

    // --- Dropdown nav (mobile click, desktop hover via CSS) ---
    document.querySelectorAll('.nav-dropdown-toggle').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var dropdown = btn.closest('.nav-dropdown');
            var isOpen = dropdown.classList.toggle('open');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-dropdown')) {
            document.querySelectorAll('.nav-dropdown.open').forEach(function(d) {
                d.classList.remove('open');
                d.querySelector('.nav-dropdown-toggle').setAttribute('aria-expanded', 'false');
            });
        }
    });

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


// ===== JoineryModal =====
const JoineryModal = (() => {
    let dialog, msgEl, inputEl, confirmBtn, cancelBtn;

    function init() {
        if (dialog) return;
        dialog = document.createElement('dialog');
        dialog.innerHTML =
            '<p class="dialog-message"></p>' +
            '<input class="dialog-input" type="text">' +
            '<div class="dialog-actions">' +
            '<button class="dialog-btn-cancel">Cancel</button>' +
            '<button class="dialog-btn-confirm">Confirm</button>' +
            '</div>';
        document.body.appendChild(dialog);
        msgEl      = dialog.querySelector('.dialog-message');
        inputEl    = dialog.querySelector('.dialog-input');
        confirmBtn = dialog.querySelector('.dialog-btn-confirm');
        cancelBtn  = dialog.querySelector('.dialog-btn-cancel');
        cancelBtn.addEventListener('click', () => dialog.close());
    }

    function _open(message, options, showInput, showCancel) {
        init();
        msgEl.textContent = message;
        inputEl.style.display = showInput ? 'block' : 'none';
        if (showInput) {
            inputEl.value       = (options && options.defaultValue)  || '';
            inputEl.placeholder = (options && options.placeholder)   || '';
        }
        cancelBtn.style.display = showCancel ? '' : 'none';
        cancelBtn.textContent   = (options && options.cancelLabel)  || 'Cancel';
        confirmBtn.textContent  = (options && options.confirmLabel) || 'Confirm';
        const style = (options && options.confirmStyle) || 'danger';
        confirmBtn.className = 'dialog-btn-confirm dialog-btn-' + style;
        dialog.showModal();
        if (showInput) inputEl.focus();
    }

    function confirm(message, onConfirm, options) {
        _open(message, options, false, true);
        confirmBtn.onclick = () => { dialog.close(); onConfirm(); };
    }

    function alert(message, onClose, options) {
        const opts = Object.assign({ confirmLabel: 'OK', confirmStyle: 'primary' }, options);
        _open(message, opts, false, false);
        confirmBtn.onclick = () => { dialog.close(); if (onClose) onClose(); };
    }

    function prompt(message, onConfirm, options) {
        const opts = Object.assign({ confirmStyle: 'primary' }, options);
        _open(message, opts, true, true);
        function submit() { dialog.close(); onConfirm(inputEl.value); }
        confirmBtn.onclick  = submit;
        inputEl.onkeydown   = (e) => { if (e.key === 'Enter') submit(); };
    }

    return { confirm, alert, prompt };
})();
