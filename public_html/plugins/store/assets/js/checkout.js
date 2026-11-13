/*
 * The checkout page (views/checkout.php): the section accordion, billing,
 * coupons, the sign-in box and the back button. Its controls name what they
 * do in data-co-* attributes:
 *   data-co-toggle            the mobile order summary opens and closes;
 *   data-co-open="key"        a completed section's header reopens it;
 *   data-co-continue="key"    checks a section and moves on;
 *   data-co-apply-coupon / data-co-remove-coupon="code";
 *   data-co-action="login | complete | apply-coupon | close-login | login-submit".
 *
 * @version 1.1 - its messages and the sign-in box show by the hidden property: each starts [hidden], which
 *   beats a style.display, so none of them ever appeared
 * @version 1.0
 */
(function() {
    function openSection(sectionKey) {
        var sections = document.querySelectorAll('.checkout-section');
        sections.forEach(function(el) {
            var body = el.querySelector('.section-body');
            var header = el.querySelector('.section-header');
            if (el.dataset.section === sectionKey) {
                el.dataset.state = 'active';
                header.setAttribute('aria-expanded', 'true');
                body.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                // Focus first input in the section
                var firstInput = body.querySelector('input, select, textarea, button');
                if (firstInput) setTimeout(function() { firstInput.focus(); }, 300);
                // Announce to screen readers
                var statusEl = document.getElementById('checkout-status');
                if (statusEl) statusEl.textContent = header.querySelector('strong').textContent + ' section is now active';
            } else if (el.dataset.state === 'active') {
                el.dataset.state = 'pending';
                header.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function markCompleted(sectionKey, summary) {
        var el = document.querySelector('[data-section="' + sectionKey + '"]');
        if (!el) return;
        el.dataset.state = 'completed';
        var header = el.querySelector('.section-header');
        // A completed header reopens through the delegated data-co-open click.

        // Update number badge to checkmark
        var badge = header.querySelector('.section-badge');
        if (badge) badge.innerHTML = '&#10003;';

        // Show summary
        var existing = header.querySelector('.section-summary');
        if (existing) existing.remove();
        if (summary) {
            var sumDiv = document.createElement('div');
            sumDiv.className = 'section-summary';
            sumDiv.innerHTML = '<span class="jy-checkout-sumtext">' + summary + '</span><span class="jy-checkout-sumedit">Edit</span>';
            header.appendChild(sumDiv);
        }
        updateProgress();
    }

    function getNextSection(currentKey) {
        var keys = [];
        document.querySelectorAll('.checkout-section').forEach(function(el) {
            keys.push(el.dataset.section);
        });
        var idx = keys.indexOf(currentKey);
        return (idx >= 0 && idx < keys.length - 1) ? keys[idx + 1] : null;
    }

    function validateAndContinue(sectionKey) {
        if (sectionKey === 'billing') {
            submitBilling(false);
            return;
        }
    }

    function submitBilling(andComplete) {
        var form = document.createElement('form');
        form.method = 'POST';
        // A free order completes at /cart_charge as a POST; the billing step
        // alone goes back to /checkout.
        form.action = andComplete ? '/cart_charge' : '/checkout';

        var email = document.getElementById('contact_email') ? document.getElementById('contact_email').value.trim() : '';
        var firstName = document.getElementById('billing_first_name') ? document.getElementById('billing_first_name').value.trim() : '';
        var lastName = document.getElementById('billing_last_name') ? document.getElementById('billing_last_name').value.trim() : '';

        var errors = [];
        if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) errors.push('A valid email address is required.');
        if (!firstName) errors.push('First name is required.');
        if (!lastName) errors.push('Last name is required.');

        if (errors.length > 0) {
            var errDiv = document.getElementById('billing_errors');
            if (errDiv) { errDiv.innerHTML = errors.join('<br>'); errDiv.hidden = false; }
            return;
        }

        var fields = {
            'billing_email': email,
            'billing_first_name': firstName,
            'billing_last_name': lastName
        };
        if (andComplete) {
            fields['complete_order'] = '1';
        }
        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
    }

    function submitBillingAndComplete() {
        submitBilling(true);
    }

    function applyCoupon() {
        var code = document.getElementById('coupon_code_input').value.trim();
        if (!code) return;
        applyCouponCode(code);
    }

    // Shared transport (joineryApi), normalized back to this page's
    // { ok, payload } shape.
    function jyApiAction(action, body) {
        return joineryApi.post(action, body).then(
            function(data) { return { ok: true, payload: { data: data } }; },
            function(err) { return { ok: false, payload: { error: err.message } }; }
        );
    }

    function applyCouponCode(code) {
        var errorEl = document.getElementById('coupon_error');
        if (errorEl) errorEl.hidden = true;

        jyApiAction('store/checkout_apply_coupon', { coupon_code: code })
            .then(function(res) {
                if (res.ok) {
                    // Reload to reflect updated prices in order summary
                    window.location.reload();
                } else {
                    if (errorEl) { errorEl.textContent = res.payload.error || 'Unable to apply coupon.'; errorEl.hidden = false; }
                }
            })
            .catch(function() {
                if (errorEl) { errorEl.textContent = 'An error occurred. Please try again.'; errorEl.hidden = false; }
            });
    }

    function removeCoupon(code) {
        jyApiAction('store/checkout_remove_coupon', { coupon_code: code })
            .then(function(res) {
                if (res.ok) {
                    window.location.reload();
                }
            });
    }

    // Email check on blur
    var contactEmail = document.getElementById('contact_email');
    if (contactEmail && contactEmail.type === 'email') {
        contactEmail.addEventListener('blur', function() {
            var email = this.value.trim();
            var existsEl = document.getElementById('contact_email_exists');
            if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                if (existsEl) existsEl.hidden = true;
                return;
            }
            jyApiAction('store/checkout_check_email', { email: email })
                .then(function(res) {
                    if (res.ok && res.payload.data && res.payload.data.exists && existsEl) {
                        existsEl.hidden = false;
                    } else if (existsEl) {
                        existsEl.hidden = true;
                    }
                });
        });
    }

    // Login modal
    function showLoginModal() {
        var modal = document.getElementById('login-modal');
        modal.hidden = false; modal.style.display = 'flex';
        var emailInput = document.getElementById('login_modal_email');
        var contactVal = document.getElementById('contact_email');
        if (emailInput && contactVal) emailInput.value = contactVal.value;
        document.getElementById('login_modal_password').focus();
    }

    function closeLoginModal() {
        var lm = document.getElementById('login-modal'); lm.hidden = true; lm.style.display = '';
    }

    function submitLogin() {
        var email = document.getElementById('login_modal_email').value.trim();
        var password = document.getElementById('login_modal_password').value;
        var errorEl = document.getElementById('login-modal-error');

        if (!email || !password) {
            if (errorEl) { errorEl.textContent = 'Please enter email and password.'; errorEl.hidden = false; }
            return;
        }

        // Submit as a real form POST to /login with redirect back to /cart
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/login';

        var fields = {
            'email': email,
            'password': password,
            'redirect': '/checkout'
        };
        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
    }

    // Progress bar update
    function updateProgress() {
        var sections = document.querySelectorAll('.checkout-section');
        var total = sections.length;
        var completed = 0;
        sections.forEach(function(el) {
            if (el.dataset.state === 'completed') completed++;
        });
        var bar = document.getElementById('progress-bar');
        if (bar) bar.style.width = Math.round((completed / total) * 100) + '%';
    }

    // Exposed for other scripts on the page
    window.openSection = openSection;
    window.validateAndContinue = validateAndContinue;
    window.submitBillingAndComplete = submitBillingAndComplete;
    window.applyCoupon = applyCoupon;
    window.applyCouponCode = applyCouponCode;
    window.removeCoupon = removeCoupon;
    window.showLoginModal = showLoginModal;
    window.closeLoginModal = closeLoginModal;
    window.submitLogin = submitLogin;

    // Disable submit buttons on form submission
    document.addEventListener('submit', function(e) {
        var form = e.target;
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        buttons.forEach(function(btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.innerHTML;
            btn.innerHTML = 'Processing...';
        });
        setTimeout(function() {
            buttons.forEach(function(btn) {
                btn.disabled = false;
                if (btn.dataset.originalText) btn.innerHTML = btn.dataset.originalText;
            });
        }, 10000);
    });

    // Back button: restore accordion state from URL hash
    function saveState() {
        var active = document.querySelector('.checkout-section[data-state="active"]');
        if (active) {
            history.replaceState({ section: active.dataset.section }, '', '#' + active.dataset.section);
        }
    }

    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.section) {
            openSection(e.state.section);
        }
    });

    // On page load, check hash for section to open
    if (window.location.hash) {
        var hashSection = window.location.hash.substring(1);
        var el = document.querySelector('[data-section="' + hashSection + '"]');
        if (el && (el.dataset.state === 'completed' || el.dataset.state === 'active')) {
            openSection(hashSection);
        }
    }

    // Save state when sections change
    var origOpen = openSection;
    openSection = function(key) {
        origOpen(key);
        history.pushState({ section: key }, '', '#' + key);
    };
    // Re-expose
    window.openSection = openSection;

    // The page's controls, by data attribute (no inline handlers).
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target : null;
        if (!t) return;
        var el;
        if ((el = t.closest('[data-co-toggle]'))) { el.classList.toggle('is-open'); return; }
        if ((el = t.closest('[data-co-open]'))) {
            if (el.parentElement && el.parentElement.dataset.state === 'completed') openSection(el.getAttribute('data-co-open'));
            return;
        }
        if ((el = t.closest('[data-co-continue]'))) { validateAndContinue(el.getAttribute('data-co-continue')); return; }
        if ((el = t.closest('[data-co-remove-coupon]'))) { e.preventDefault(); removeCoupon(el.getAttribute('data-co-remove-coupon')); return; }
        if ((el = t.closest('[data-co-apply-coupon]'))) { e.preventDefault(); applyCouponCode(el.getAttribute('data-co-apply-coupon')); return; }
        if (!(el = t.closest('[data-co-action]'))) return;
        switch (el.getAttribute('data-co-action')) {
            case 'login': e.preventDefault(); showLoginModal(); break;
            case 'complete': submitBillingAndComplete(); break;
            case 'apply-coupon': applyCoupon(); break;
            case 'close-login': closeLoginModal(); break;
            case 'login-submit': submitLogin(); break;
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var el = e.target.closest ? e.target.closest('[data-co-open]') : null;
        if (!el) return;
        e.preventDefault();
        if (el.parentElement && el.parentElement.dataset.state === 'completed') openSection(el.getAttribute('data-co-open'));
    });
})();
