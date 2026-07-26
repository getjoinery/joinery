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
//   type ('password' for passphrases).
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
        const prev = btn.textContent;
        btn.textContent = 'Copied';
        btn.disabled = true;
        setTimeout(() => { btn.textContent = prev; btn.disabled = false; }, 1200);
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

    function prompt(message, onConfirm, options) {
        const opts = Object.assign({ confirmStyle: 'primary' }, options);
        const confirmBtn = _open(message, opts, true, true);
        const submit = () => { dialog.close(); onConfirm(inputEl.value); };
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
            const submit = () => { result = inputEl.value; dialog.close(); };
            confirmBtn.onclick = submit;
            inputEl.onkeydown  = (e) => { if (e.key === 'Enter') submit(); };
            dialog.addEventListener('close', () => resolve(result), { once: true });
        });
    }

    return { confirm, confirmTyped, alert, prompt, open, alertAsync, confirmAsync, promptAsync };
})();

// Actions dropdowns (<details class="jy-actions-dropdown">): close any open
// menu when the click lands outside it, and after a menu item is clicked.
document.addEventListener('click', function (e) {
    document.querySelectorAll('details.jy-actions-dropdown[open]').forEach(function (dd) {
        if (!dd.contains(e.target) || e.target.closest('.jy-actions-menu button')) {
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
