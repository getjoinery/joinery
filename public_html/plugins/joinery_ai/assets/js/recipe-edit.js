/*
 * The recipe editor (views/admin/edit.php): the job's default prompt, the
 * schedule's arrival and manual choices, and the untrusted-content warning.
 * The page's #recipe-edit-config carries what PHP knows: default prompts,
 * arrival labels, the pipeline, arrival and manual mode names, the write and
 * web tools, and which models and jobs read untrusted content.
 *
 * @version 1.1 - one wrapper: RCP is not a global
 * @version 1.0
 */
(function () {
var RCP = (window.joineryPageData && joineryPageData('recipe-edit-config')) || {};
(function () {
    // Built-in instructions per pipeline job. The job select can change without a
    // round trip, so the text is swapped client-side rather than rendered once.
    var PROMPTS = RCP.prompts || {};
    var PIPELINE = RCP.pipeline;

    var modeEl    = document.querySelector('[name="rcp_mode"]');
    var jobEl     = document.querySelector('[name="rcp_pipeline_job"]');
    var promptEl  = document.querySelector('[name="rcp_prompt"]');
    var readWrap  = document.getElementById('rcp_prompt_builtin_wrap');
    var readText  = document.getElementById('rcp_prompt_builtin_text');
    var editWrap  = document.getElementById('rcp_prompt_edit_wrap');
    var customBtn = document.getElementById('rcp_prompt_customize');
    var revertBtn = document.getElementById('rcp_prompt_revert');
    if (!promptEl || !readWrap || !editWrap) return;

    // A non-empty rcp_prompt IS the customization — no separate flag to persist.
    var customized = !!RCP.prompt_is_custom;

    function builtinFor() {
        var mode = modeEl ? modeEl.value : '';
        var job  = jobEl ? jobEl.value : '';
        if (mode !== PIPELINE || !job) return null;
        return Object.prototype.hasOwnProperty.call(PROMPTS, job) ? PROMPTS[job] : null;
    }

    function sync() {
        var builtin = builtinFor();
        var showRead = (builtin !== null && !customized);
        readWrap.style.display = showRead ? '' : 'none';
        editWrap.style.display = showRead ? 'none' : '';
        if (showRead) readText.textContent = builtin;
        if (revertBtn) revertBtn.style.display = (!showRead && builtin !== null) ? '' : 'none';
    }

    if (customBtn) {
        customBtn.addEventListener('click', function () {
            // Start from the built-in text rather than a blank box: customizing
            // usually means adjusting a line, not writing from scratch.
            var builtin = builtinFor();
            if (builtin !== null && promptEl.value.trim() === '') promptEl.value = builtin;
            customized = true;
            sync();
            promptEl.focus();
        });
    }

    if (revertBtn) {
        revertBtn.addEventListener('click', function () {
            var builtin = builtinFor();
            var edited = promptEl.value.trim() !== '' && promptEl.value !== builtin;
            var revert = function () { promptEl.value = ''; customized = false; sync(); };
            if (!edited) { revert(); return; }
            var msg = 'Discard your prompt and go back to the built-in instructions?';
            if (window.JoineryModal && JoineryModal.confirm) JoineryModal.confirm(msg, revert);
            else if (window.confirm(msg)) revert();
        });
    }

    if (modeEl) modeEl.addEventListener('change', sync);
    if (jobEl)  jobEl.addEventListener('change', sync);
    sync();
})();

(function () {
    // The arrival option belongs to the selected job, so it appears, disappears
    // and changes wording with the job select — the same client-side swap the
    // built-in prompt text gets.
    //
    // One exception, and it is the reason this is not two lines: a recipe ALREADY
    // saved on 'arrival' whose job has since stopped offering one (a plugin
    // upgrade, a removed job) keeps the option on screen, so the value round-trips
    // and the save path refuses it by name. Dropping it silently instead would
    // turn an unrelated edit — renaming the recipe — into switching it off.
    var ARRIVAL_LABELS = RCP.arrival_labels || {};
    var ARRIVAL   = RCP.arrival;
    var MANUAL    = RCP.manual;
    var PIPELINE  = RCP.pipeline;

    var runsEl = document.querySelector('[name="rcp_runs"]');
    var modeEl = document.querySelector('[name="rcp_mode"]');
    var jobEl  = document.querySelector('[name="rcp_pipeline_job"]');
    if (!runsEl) return;

    var arrivalOpt = null;
    for (var i = 0; i < runsEl.options.length; i++) {
        if (runsEl.options[i].value === ARRIVAL) { arrivalOpt = runsEl.options[i]; break; }
    }
    if (!arrivalOpt) return;
    // Detached rather than hidden: a disabled option still posts if it is the
    // selection, and `hidden` is not honoured everywhere.
    var slot = document.createComment('arrival-option');
    var attached = true;
    var strandedText = arrivalOpt.textContent;
    var stranded = false;   // set below, once labelFor() is defined

    function labelFor() {
        var mode = modeEl ? modeEl.value : PIPELINE;
        var job  = jobEl ? jobEl.value : '';
        if (mode !== PIPELINE || !job) return null;
        return Object.prototype.hasOwnProperty.call(ARRIVAL_LABELS, job) ? ARRIVAL_LABELS[job] : null;
    }

    function sync() {
        var label = labelFor();
        if (label === null && stranded && runsEl.value === ARRIVAL) {
            // Saved on a value its job no longer offers. Keep it selectable so
            // the server gets the chance to say so.
            arrivalOpt.textContent = strandedText;
            return;
        }
        stranded = false;
        if (label === null) {
            if (attached) {
                // Detach BEFORE moving the selection: the change event below
                // re-enters here, and a second detach of an already-detached
                // option would throw on a null parent.
                arrivalOpt.parentNode.replaceChild(slot, arrivalOpt);
                // A detached option keeps its selected flag, and re-inserting a
                // selected option steals the selection back — so a later job
                // that DOES offer arrivals would silently re-pick it.
                arrivalOpt.selected = false;
                attached = false;
                if (runsEl.value !== MANUAL) {
                    runsEl.value = MANUAL;
                    runsEl.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            return;
        }
        arrivalOpt.textContent = label;
        if (!attached) {
            slot.parentNode.replaceChild(arrivalOpt, slot);
            attached = true;
        }
    }

    stranded = (runsEl.value === ARRIVAL && labelFor() === null);

    if (modeEl)  modeEl.addEventListener('change', sync);
    if (jobEl)   jobEl.addEventListener('change', sync);
    runsEl.addEventListener('change', sync);
    sync();
})();

(function () {
    // Live mirror of TaintGate::evaluate(). Answers "what happens to this
    // recipe's changes?" before the admin has to reason about the checkbox.
    var WRITE_TOOLS      = RCP.write_tools || [];
    var WEB_TOOLS        = RCP.web_tools || [];
    var UNTRUSTED_MODELS = RCP.untrusted_models || [];
    var UNTRUSTED_JOBS   = RCP.untrusted_jobs || {};
    var PIPELINE         = RCP.pipeline;

    var box   = document.getElementById('rcp_taint_state');
    var check = document.querySelector('[name="rcp_allow_tainted_writes"]');
    var modeEl = document.querySelector('[name="rcp_mode"]');
    var jobEl  = document.querySelector('[name="rcp_pipeline_job"]');
    var wsEl   = document.querySelector('[name="rcp_workspace"]');
    if (!box || !check) return;

    function checkedValues(name) {
        return Array.prototype.map.call(
            document.querySelectorAll('input[name="' + name + '"]:checked'),
            function (el) { return el.value; });
    }

    function evaluate() {
        var mode = modeEl ? modeEl.value : '';
        if (mode === PIPELINE) {
            var job = jobEl ? jobEl.value : '';
            if (job && UNTRUSTED_JOBS[job]) {
                return { required: true, why: 'This job reads text written by whoever sent the item.' };
            }
            return { required: false, why: 'This job only reads content you control.' };
        }
        var allTools = checkedValues('rcp_allowed_tools[]');
        var tools = allTools.filter(function (t) { return WRITE_TOOLS.indexOf(t) !== -1; });
        if (!tools.length) {
            return { required: false, why: 'This recipe cannot change anything — it has no write tools.' };
        }
        var models = checkedValues('rcp_allowed_models[]').filter(function (m) {
            return UNTRUSTED_MODELS.indexOf(m) !== -1; });
        var web = allTools.filter(function (t) { return WEB_TOOLS.indexOf(t) !== -1; });
        var ws = wsEl && wsEl.value.trim() !== '';
        var reasons = [];
        if (models.length) reasons.push('reads records holding text other people wrote: ' + models.join(', '));
        if (web.length) reasons.push('reads pages from the web (' + web.join(', ') + ')');
        if (ws) reasons.push('carries notes the AI wrote to itself on earlier runs');
        if (reasons.length) {
            return { required: false, queued: true, why: 'It can write (' + tools.join(', ') + ') and '
                + reasons.join(' and ') + ', so each change it wants to make becomes a card you '
                + 'approve or decline. Nothing is applied on its own.' };
        }
        return { required: false, why: 'Nothing it reads was written by anyone else, so its changes apply directly.' };
    }

    function render() {
        var state = evaluate();
        var cls, text;
        if (state.queued) {
            cls = 'alert alert-info py-2 mb-2';
            text = '<strong>Changes wait for your approval.</strong> ' + state.why
                 + ' The box below does not apply to this recipe.';
        } else if (!state.required) {
            cls = 'alert alert-secondary py-2 mb-2';
            text = '<strong>Not needed for this recipe.</strong> ' + state.why
                 + ' Leaving the box below ticked does no harm; it just does not apply.';
        } else if (check.checked) {
            cls = 'alert alert-success py-2 mb-2';
            text = '<strong>Needed, and you have allowed it.</strong> ' + state.why;
        } else {
            cls = 'alert alert-warning py-2 mb-2';
            text = '<strong>Needed before this recipe can run.</strong> ' + state.why
                 + ' Saving is refused until you tick the box below.';
        }
        box.className = cls;
        box.innerHTML = text;
    }

    document.addEventListener('change', function (e) {
        if (!e.target || !e.target.name) return;
        if (['rcp_mode', 'rcp_pipeline_job', 'rcp_allowed_tools[]', 'rcp_allowed_models[]',
             'rcp_workspace', 'rcp_allow_tainted_writes'].indexOf(e.target.name) !== -1) render();
    });
    if (wsEl) wsEl.addEventListener('input', render);
    render();
})();
})();
