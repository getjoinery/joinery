/* ============================================================
   Joinery Base JS
   Minimal vanilla JS to handle interactive components
   in base view files. Only needed when the theme doesn't
   include Bootstrap JS.
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {

    // --- Tab/Pill toggle (replaces Bootstrap's data-bs-toggle="pill"/"tab") ---
    document.querySelectorAll('[data-bs-toggle="pill"], [data-bs-toggle="tab"]').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            var targetSelector = this.getAttribute('data-bs-target') || this.getAttribute('href');
            if (!targetSelector) return;

            // Deactivate siblings
            var nav = this.closest('.nav, ul');
            if (nav) {
                nav.querySelectorAll('.nav-link').forEach(function(link) {
                    link.classList.remove('active');
                    link.setAttribute('aria-selected', 'false');
                });
            }

            // Activate this tab
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');

            // Hide all sibling panes
            var tabContent = document.querySelector(targetSelector);
            if (tabContent) {
                var container = tabContent.parentElement;
                if (container) {
                    container.querySelectorAll('.tab-pane').forEach(function(pane) {
                        pane.classList.remove('show', 'active');
                    });
                }
                tabContent.classList.add('show', 'active');
            }
        });
    });

    // --- Dropdown toggle (replaces Bootstrap's data-bs-toggle="dropdown") ---
    // Only activate if Bootstrap JS is NOT loaded (Bootstrap handles its own dropdowns)
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            // If Bootstrap is available, let it handle the dropdown natively
            if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) return;
            e.preventDefault();
            e.stopPropagation();
            var menu = this.nextElementSibling;
            if (menu && menu.classList.contains('dropdown-menu')) {
                var wasOpen = menu.classList.contains('show');
                // Close all open dropdowns first
                document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
                    m.classList.remove('show');
                });
                if (!wasOpen) {
                    menu.classList.add('show');
                }
            }
        });
    });

    // Close dropdowns when clicking outside (only when Bootstrap is not present)
    document.addEventListener('click', function() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) return;
        document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
            m.classList.remove('show');
        });
    });

    // --- Tooltip (replaces Bootstrap's data-bs-toggle="tooltip") ---
    // Simple title-based fallback — no fancy positioning needed
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        if (!el.getAttribute('title') && el.getAttribute('data-bs-original-title')) {
            el.setAttribute('title', el.getAttribute('data-bs-original-title'));
        }
    });
});

// ===== JoineryModal =====
// System modal. Native <dialog>, lazily created on first use and appended to
// <body>. The dialog carries the `jy-ui` class so the kit's .jy-ui-scoped rules
// reach it even though it renders outside any page-level .jy-ui wrapper. Buttons
// are plain kit buttons (.btn / .btn-*), not a bespoke dialog-button family.
//   confirm/alert/prompt — text modes (message + optional input).
//   alertAsync/confirmAsync/promptAsync — the same modes as promises, for
//   async flows: confirmAsync resolves boolean, promptAsync resolves the
//   entered string or null on cancel/Esc. options.inputType sets the input
//   type ('password' for passphrases); options.required keeps a prompt's
//   confirm disabled until something is typed.
//   open(content, { buttons }) — content mode for arbitrary DOM + a custom
//   button set; each button is { label, style, onClick(dialog), close }.
// Form confirms: a <form data-jy-confirm="message"> gets a kit confirm modal
// in place of submitting; confirming submits it for real. Delegated, no
// per-form wiring.
// Click-to-copy: any <button data-jy-copy="text"> copies that text to the
// clipboard and briefly confirms on the button itself. Delegated, so markup
// rendered at any time (including inside modals) works without wiring.
document.addEventListener('click', function (e) {
    const btn = e.target.closest ? e.target.closest('[data-jy-copy]') : null;
    if (!btn) return;
    const text = btn.getAttribute('data-jy-copy') || '';
    const done = () => {
        // The button's own markup comes back, icon and all.
        const prev = btn.innerHTML;
        btn.textContent = 'Copied';
        btn.disabled = true;
        setTimeout(() => { btn.innerHTML = prev; btn.disabled = false; }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, done);
    } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) { /* best effort */ }
        document.body.removeChild(ta);
        done();
    }
});

const JoineryModal = (() => {
    let dialog, msgEl, contentEl, inputEl, actionsEl;

    function init() {
        if (dialog) return;
        dialog = document.createElement('dialog');
        dialog.className = 'jy-ui';
        dialog.innerHTML =
            '<p class="dialog-message"></p>' +
            '<div class="dialog-content"></div>' +
            '<input class="dialog-input" type="text">' +
            '<div class="dialog-actions"></div>';
        document.body.appendChild(dialog);
        msgEl     = dialog.querySelector('.dialog-message');
        contentEl = dialog.querySelector('.dialog-content');
        inputEl   = dialog.querySelector('.dialog-input');
        actionsEl = dialog.querySelector('.dialog-actions');
    }

    function makeButton(label, style, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-' + (style || 'secondary');
        btn.textContent = label;
        btn.onclick = onClick;
        return btn;
    }

    function _open(message, options, showInput, showCancel) {
        init();
        options = options || {};
        msgEl.style.display = '';
        msgEl.textContent = message;
        contentEl.style.display = 'none';
        contentEl.innerHTML = '';

        inputEl.style.display = showInput ? 'block' : 'none';
        if (showInput) {
            inputEl.type        = options.inputType     || 'text';
            inputEl.value       = options.defaultValue || '';
            inputEl.placeholder = options.placeholder   || '';
        }

        actionsEl.innerHTML = '';
        let cancelBtn = null;
        if (showCancel) {
            cancelBtn = makeButton(options.cancelLabel || 'Cancel', 'secondary', () => dialog.close());
            actionsEl.appendChild(cancelBtn);
        }
        const confirmBtn = makeButton(options.confirmLabel || 'Confirm', options.confirmStyle || 'danger', null);
        actionsEl.appendChild(confirmBtn);

        dialog.showModal();
        if (showInput) inputEl.focus();
        return confirmBtn;
    }

    function confirm(message, onConfirm, options) {
        const confirmBtn = _open(message, options, false, true);
        confirmBtn.onclick = () => { dialog.close(); onConfirm(); };
    }

    // Type-to-confirm for irreversible actions: the message states the action,
    // the input demands the exact phrase, and the confirm button stays disabled
    // until it matches. Use for permanent deletes and anything else with no undo.
    function confirmTyped(message, requiredText, onConfirm, options) {
        const opts = Object.assign(
            { confirmLabel: 'I understand', placeholder: requiredText },
            options
        );
        const confirmBtn = _open(
            message + ' Type "' + requiredText + '" to confirm.',
            opts, true, true
        );
        confirmBtn.disabled = true;
        inputEl.value = '';
        const matches = () => inputEl.value.trim() === requiredText;
        inputEl.oninput   = () => { confirmBtn.disabled = !matches(); };
        const submit = () => { if (!matches()) return; dialog.close(); onConfirm(); };
        confirmBtn.onclick = submit;
        inputEl.onkeydown  = (e) => { if (e.key === 'Enter') submit(); };
    }

    function alert(message, onClose, options) {
        const opts = Object.assign({ confirmLabel: 'OK', confirmStyle: 'primary' }, options);
        const confirmBtn = _open(message, opts, false, false);
        confirmBtn.onclick = () => { dialog.close(); if (onClose) onClose(); };
    }

    // options.required: the confirm button stays disabled, and Enter does
    // nothing, until the input holds more than whitespace.
    function prompt(message, onConfirm, options) {
        const opts = Object.assign({ confirmStyle: 'primary' }, options);
        const confirmBtn = _open(message, opts, true, true);
        const ready = () => !opts.required || inputEl.value.trim() !== '';
        confirmBtn.disabled = !ready();
        inputEl.oninput    = () => { confirmBtn.disabled = !ready(); };
        const submit = () => { if (!ready()) return; dialog.close(); onConfirm(inputEl.value); };
        confirmBtn.onclick = submit;
        inputEl.onkeydown  = (e) => { if (e.key === 'Enter') submit(); };
    }

    // Content mode: arbitrary DOM/string + a custom button set. A button's
    // onClick may return false to keep the dialog open (e.g. validation fail);
    // pass close:false to keep it open unconditionally.
    function open(content, options) {
        init();
        options = options || {};
        msgEl.style.display = 'none';
        inputEl.style.display = 'none';
        contentEl.style.display = '';
        contentEl.innerHTML = '';
        if (typeof content === 'string') { contentEl.innerHTML = content; }
        else if (content instanceof Node) { contentEl.appendChild(content); }

        actionsEl.innerHTML = '';
        const buttons = options.buttons || [{ label: 'Close', style: 'secondary' }];
        buttons.forEach((b) => {
            actionsEl.appendChild(makeButton(b.label, b.style, () => {
                const keepOpen = (typeof b.onClick === 'function') && (b.onClick(dialog) === false);
                if (!keepOpen && b.close !== false) dialog.close();
            }));
        });

        dialog.showModal();
        return { dialog: dialog, content: contentEl };
    }

    // Promise variants for async flows. Each resolves exactly once, on the
    // dialog's close event, so Esc and the Cancel button settle the promise
    // too (as false / null) instead of leaving it hanging.
    function alertAsync(message, options) {
        return new Promise((resolve) => {
            const opts = Object.assign({ confirmLabel: 'OK', confirmStyle: 'primary' }, options);
            const confirmBtn = _open(message, opts, false, false);
            confirmBtn.onclick = () => dialog.close();
            dialog.addEventListener('close', () => resolve(), { once: true });
        });
    }

    function confirmAsync(message, options) {
        return new Promise((resolve) => {
            let result = false;
            const confirmBtn = _open(message, options, false, true);
            confirmBtn.onclick = () => { result = true; dialog.close(); };
            dialog.addEventListener('close', () => resolve(result), { once: true });
        });
    }

    function promptAsync(message, options) {
        return new Promise((resolve) => {
            let result = null;
            const opts = Object.assign({ confirmStyle: 'primary' }, options);
            const confirmBtn = _open(message, opts, true, true);
            const ready = () => !opts.required || inputEl.value.trim() !== '';
            confirmBtn.disabled = !ready();
            inputEl.oninput    = () => { confirmBtn.disabled = !ready(); };
            const submit = () => { if (!ready()) return; result = inputEl.value; dialog.close(); };
            confirmBtn.onclick = submit;
            inputEl.onkeydown  = (e) => { if (e.key === 'Enter') submit(); };
            dialog.addEventListener('close', () => resolve(result), { once: true });
        });
    }

    return { confirm, confirmTyped, alert, prompt, open, alertAsync, confirmAsync, promptAsync };
})();
// A top-level const is not a window property; modules that feature-test for
// the modal (vault-lock.js, vault-keyring.js) read it from window.
window.JoineryModal = JoineryModal;

// Actions dropdowns (<details class="jy-actions-dropdown">): close any open
// menu when the click lands outside it, and after a menu item is clicked.
document.addEventListener('click', function (e) {
    document.querySelectorAll('details.jy-actions-dropdown[open]').forEach(function (dd) {
        if (!dd.contains(e.target) || e.target.closest('.jy-actions-menu button, .jy-actions-menu a')) {
            dd.removeAttribute('open');
        }
    });
});

// Field help guides: <button data-jy-help="{template id}"> opens that
// <template>'s content in the kit modal. FormWriter's `help_modal` option emits
// both halves, so a field that asks for a credential can say where to get one
// with nothing wired per form. Delegated, so markup rendered at any time works.
document.addEventListener('click', function (e) {
    const trigger = e.target.closest ? e.target.closest('[data-jy-help]') : null;
    if (!trigger) return;
    const tpl = document.getElementById(trigger.getAttribute('data-jy-help'));
    if (!tpl || !tpl.content) return;
    e.preventDefault();
    JoineryModal.open(tpl.content.cloneNode(true), {
        buttons: [{ label: 'Done', style: 'primary' }],
    });
});

// Kit confirm on a button or link, with no inline handler (the strict script
// policy refuses onclick=):
//   <button type="button" data-jy-confirm="Delete it?" data-jy-submit="delete_form">
//     asks, then submits the form with that id (submit(), as the inline
//     handlers did, so the form's own data-jy-confirm is not asked again);
//   <button type="button" data-jy-confirm="Delete it?" data-jy-submit=""
//     data-jy-submit-self> checks the form's fields, asks, then submits its
//     own form (requestSubmit) with the button's name and value in a hidden
//     input, so they are posted as if it had been the submit button. It is
//     not the submitter, so it may carry no formaction/formmethod (a test
//     holds the tree to that): put those on the form.
//     FormWriter's submitbutton 'confirm' writes it: a type="button" posts
//     nothing before this file has run and the person has said yes;
//   <button type="submit" data-jy-confirm="Delete it?"> asks, then submits as
//     that button too;
//   <a href="/x" data-jy-confirm="Leave?"> asks, then follows the link;
//   data-jy-confirm-typed="DELETE" also asks the person to type that word
//     (JoineryModal.confirmTyped);
//   data-jy-confirm-label="Remove" and data-jy-confirm-style="primary" set
//     the confirm button's words and style;
//   <button type="button" data-jy-alert="Saved."> just shows the message.
document.addEventListener('click', function (e) {
    const el = e.target.closest
        ? e.target.closest('[data-jy-submit], [data-jy-alert], a[data-jy-confirm], button[data-jy-confirm], input[data-jy-confirm]')
        : null;
    if (!el || el.tagName === 'FORM') return;
    e.preventDefault();
    if (el.hasAttribute('data-jy-alert')) {
        JoineryModal.alert(el.getAttribute('data-jy-alert'));
        return;
    }
    let go;
    if (el.tagName === 'A' && !el.hasAttribute('data-jy-submit')) {
        const href = el.getAttribute('href');
        go = function () { if (href) window.location.href = href; };
    } else {
        const id = el.getAttribute('data-jy-submit');
        const form = id ? document.getElementById(id) : (el.form || el.closest('form'));
        if (!form) return;
        const self = !id && el.hasAttribute('data-jy-submit-self');
        if (self && !el.hasAttribute('formnovalidate') && form.reportValidity && !form.reportValidity()) return;
        const asSubmitter = !id && el.type === 'submit' && form.requestSubmit;
        go = function () {
            if (asSubmitter) { form.requestSubmit(el); return; }
            if (!self) { form.submit(); return; }
            // A type="button" that asks first is not a submit button, so it
            // cannot be the submitter: its name and value ride in one hidden
            // input instead (the marker joinery-validate.js uses for the same
            // job), and the form is submitted natively so its submit handlers
            // (the validator, step-up) still run. A button that skips
            // validation tells the validator to stand aside once.
            if (el.name) {
                let carried = form.querySelector('input[type="hidden"][data-joinery-submitter]');
                if (!carried) {
                    carried = document.createElement('input');
                    carried.type = 'hidden';
                    carried.setAttribute('data-joinery-submitter', '1');
                    form.appendChild(carried);
                }
                carried.name = el.name;
                carried.value = el.value || '';
            }
            if (el.hasAttribute('formnovalidate')) form.dataset.jyValidated = '1';
            // Not the submitter, so the data-jy-busy listener cannot see it:
            // mark it here, once the submit has not been stopped.
            // It stays until a submit goes through: the validator stops the
            // first one and submits again once the fields pass.
            // One listener per button, and only a submit this button made
            // (it is never the submitter, so none other is named) marks it.
            if (el.hasAttribute('data-jy-busy') && !el.dataset.jyBusyArmed) {
                el.dataset.jyBusyArmed = '1';
                const markBusy = function (ev) {
                    if (ev.submitter && ev.submitter !== el) return;
                    setTimeout(function () {
                        if (ev.defaultPrevented) return;
                        form.removeEventListener('submit', markBusy);
                        delete el.dataset.jyBusyArmed;
                        el.disabled = true;
                        el.textContent = el.getAttribute('data-jy-busy') || 'Working…';
                    }, 0);
                };
                form.addEventListener('submit', markBusy);
            }
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        };
    }
    const message = el.getAttribute('data-jy-confirm');
    const typed = el.getAttribute('data-jy-confirm-typed');
    const opts = {};
    if (el.getAttribute('data-jy-confirm-label')) opts.confirmLabel = el.getAttribute('data-jy-confirm-label');
    if (el.getAttribute('data-jy-confirm-style')) opts.confirmStyle = el.getAttribute('data-jy-confirm-style');
    if (typed) {
        JoineryModal.confirmTyped(message || '', typed, go, opts);
    } else if (message) {
        JoineryModal.confirm(message, go, opts);
    } else {
        go();
    }
});

// Page-level kit hooks the server writes as data, so no page needs an inline
// script for them (the strict script policy refuses one):
//   <meta name="joinery-body-class" content="jy-app-mode"> adds that class to
//     <body>;
//   <template data-jy-body-insert="beforeend"> (or "afterbegin") puts its
//     content into <body> there: the admin chip, a notice every theme shows;
//   <input data-jy-select-all> selects its whole value when clicked;
//   <button data-jy-dismiss> hides its [data-jy-dismissible] notice;
//   [data-jy-reveal="id"] shows that element; <select data-jy-autosubmit>
//   submits its form on change; a submit button's data-jy-busy="text" shows
//   while its form posts;
//   <input data-jy-current-path> is given this page's path and query, for a
//     form that comes back here (TestMode's Turn off);
//   <input data-jy-browser-timezone> is given the browser's time zone;
//   an element with data-jy-reload-after="30" reloads the page after that
//     many seconds, or, with data-jy-reload-to="/path", goes there instead.
//     The address must be a path on this site ("/x", never "//host"); the
//     first such element on the page sets the timer.
function joinerySameSitePath(to) {
    // A browser drops tabs and line breaks from an address and reads "\" as
    // "/", so "/\t/evil.example" would leave the site: none of those, no
    // spaces or control characters at all, and the address must resolve to
    // this page's own origin.
    if (typeof to !== 'string' || to.charAt(0) !== '/' || /[\x00-\x20\x7f\\]/.test(to) || to.charAt(1) === '/') return false;
    try {
        return new URL(to, window.location.href).origin === window.location.origin;
    } catch (e) {
        return false;
    }
}
// The data a page hands its script file: the JSON block
// <script type="application/json" id="..."> that PageScript::json() writes.
// null when the block is missing or is not JSON.
function joineryPageData(id) {
    const el = document.getElementById(id);
    if (!el || el.tagName !== 'SCRIPT' || el.type !== 'application/json') return null;
    try { return JSON.parse(el.textContent); } catch (e) { return null; }
}
// Whether DOMContentLoaded has fired: base.js loads in the head, before any
// other file, so a file that must run after every deferred file (they all
// run before that event) can tell "wait for it" from "it has passed".
window.joineryDomReady = document.readyState !== 'loading' && document.readyState !== 'interactive';
document.addEventListener('DOMContentLoaded', function () { window.joineryDomReady = true; });
function joineryApplyPageHooks() {
    const timer = document.querySelector('[data-jy-reload-after]');
    if (timer) {
        const secs = parseFloat(timer.getAttribute('data-jy-reload-after'));
        const to = timer.getAttribute('data-jy-reload-to');
        if (secs >= 0 && isFinite(secs) && (to === null || joinerySameSitePath(to))) {
            setTimeout(function () {
                if (to === null) window.location.reload(); else window.location.href = to;
            }, secs * 1000);
        }
    }
    document.querySelectorAll('meta[name="joinery-body-class"]').forEach(function (m) {
        (m.getAttribute('content') || '').split(/\s+/).forEach(function (c) { if (c) document.body.classList.add(c); });
    });
    document.querySelectorAll('template[data-jy-body-insert]').forEach(function (t) {
        const where = t.getAttribute('data-jy-body-insert') === 'afterbegin' ? 'afterbegin' : 'beforeend';
        document.body.insertAdjacentHTML(where, t.innerHTML);
        t.remove();
    });
    document.querySelectorAll('input[data-jy-current-path]').forEach(function (i) {
        i.value = window.location.pathname + window.location.search;
    });
    let tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { tz = ''; }
    document.querySelectorAll('input[data-jy-browser-timezone]').forEach(function (i) {
        i.value = tz || 'UTC';
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', joineryApplyPageHooks);
} else {
    joineryApplyPageHooks();
}
document.addEventListener('click', function (e) {
    const el = e.target;
    if (el && el.tagName === 'INPUT' && el.hasAttribute('data-jy-select-all')) el.select();
});
// <a href="#x" data-jy-reveal="x"> shows the element with that id and hides
// itself, or the element named by data-jy-reveal-hide ("Show advanced options").
document.addEventListener('click', function (e) {
    const el = e.target.closest ? e.target.closest('[data-jy-reveal]') : null;
    if (!el) return;
    e.preventDefault();
    const target = document.getElementById(el.getAttribute('data-jy-reveal'));
    if (!target) return;
    target.hidden = false;
    target.style.display = '';
    const hide = el.getAttribute('data-jy-reveal-hide');
    const gone = hide ? document.getElementById(hide) : el;
    if (gone) gone.style.display = 'none';
});
// <button type="button" data-jy-prompt="Why?" data-jy-prompt-form="form_id"
//   data-jy-prompt-field="reason"> asks in the modal (an answer is required),
//   puts the answer in that field of that form, and submits it.
//   data-jy-confirm-label / -style word and colour the confirm button.
document.addEventListener('click', function (e) {
    const btn = e.target.closest ? e.target.closest('[data-jy-prompt]') : null;
    if (!btn || !window.JoineryModal) return;
    e.preventDefault();
    const form = document.getElementById(btn.getAttribute('data-jy-prompt-form') || '');
    const field = form && form.elements[btn.getAttribute('data-jy-prompt-field') || ''];
    if (!form || !field) return;
    const opts = { required: true };
    if (btn.getAttribute('data-jy-confirm-label')) opts.confirmLabel = btn.getAttribute('data-jy-confirm-label');
    if (btn.getAttribute('data-jy-confirm-style')) opts.confirmStyle = btn.getAttribute('data-jy-confirm-style');
    JoineryModal.prompt(btn.getAttribute('data-jy-prompt'), function (answer) {
        field.value = answer;
        form.submit();
    }, opts);
});
// <select data-jy-autosubmit> submits its form when changed (a filter).
document.addEventListener('change', function (e) {
    const el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-jy-autosubmit') || !el.form) return;
    if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit();
});
// <button type="submit" data-jy-busy="Running…">: once its form is on its way,
// the button says so and cannot be pressed again. Done after the submit
// event, so the button's own name and value still go with the form.
document.addEventListener('submit', function (e) {
    const btn = e.submitter;
    if (!btn || !btn.hasAttribute('data-jy-busy') || e.defaultPrevented) return;
    // Checked again once dispatch is over: a later listener (the kit's form
    // confirm, say) may have cancelled it, and then nothing is on its way.
    setTimeout(function () {
        if (e.defaultPrevented) return;
        btn.disabled = true;
        btn.textContent = btn.getAttribute('data-jy-busy') || 'Working…';
    }, 0);
});
// <button data-jy-dismiss> hides the notice it sits in: its nearest
// [data-jy-dismissible] ancestor, or its parent when it has none.
document.addEventListener('click', function (e) {
    const btn = e.target.closest ? e.target.closest('[data-jy-dismiss]') : null;
    if (!btn) return;
    e.preventDefault();
    const box = btn.closest('[data-jy-dismissible]') || btn.parentElement;
    if (box) box.hidden = true;
});

// <select data-jy-navigate>: choosing an option opens its value, which must be
// a path on this site ("/x", never "//host" or "javascript:"). FormWriter's
// dropinput writes it for navigate_on_change.
document.addEventListener('change', function (e) {
    const el = e.target;
    if (!el || el.tagName !== 'SELECT' || !el.hasAttribute('data-jy-navigate')) return;
    if (joinerySameSitePath(el.value)) window.location.href = el.value;
});

// Kit confirm for plain forms: <form data-jy-confirm="Are you sure?"> shows
// the modal instead of submitting; confirming re-submits with a one-shot
// bypass flag so this handler lets it through.
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form.hasAttribute || !form.hasAttribute('data-jy-confirm')) return;
    if (form.dataset.jyConfirmed === '1') { delete form.dataset.jyConfirmed; return; }
    e.preventDefault();
    JoineryModal.confirm(form.getAttribute('data-jy-confirm'), function () {
        form.dataset.jyConfirmed = '1';
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
});
