/*
 * /profile/calendar: import and download, the entry form dialog, occurrence
 * scope, and a protection change carried on (views/profile/calendar.php).
 *
 * #calendar-page-config: { tz, back_url, occurrence_date, level_remaining }.
 *
 * @version 1.0
 */
(function(){
    var CAL = (window.joineryPageData && joineryPageData('calendar-page-config')) || {};
    var USER_TZ = CAL.tz;

    // =========================================================================
    // Import (.ics) — one form, two triggers: the Actions menu and the
    // first-run prompt. Delegated, so both work without per-trigger wiring.
    // JoineryModal.open() moves the node into the dialog; the reference here
    // keeps it valid across opens.
    // =========================================================================
    var importContent = document.getElementById('cal-import-content');

    document.addEventListener('click', function(ev){
        var trigger = ev.target.closest ? ev.target.closest('[data-cal-import]') : null;
        if (!trigger || !importContent) { return; }
        ev.preventDefault();
        importContent.hidden = false;
        JoineryModal.open(importContent, {
            buttons: [{ label: 'Cancel', style: 'secondary' }]
        });
    });

    // =========================================================================
    // Download (.ics) — the member's own entries as one file. A Private calendar
    // read with the window closed answers locked: run the unlock, then ask again.
    // =========================================================================
    var exportBtn = document.querySelector('[data-cal-export]');
    var exportErr = document.getElementById('cal-export-error');
    function downloadCalendar(){
        exportBtn.disabled = true;
        exportErr.hidden = true;
        joineryApi.post('calendar_export', {}).then(function(d){
            exportBtn.disabled = false;
            var url = URL.createObjectURL(new Blob([d.content], { type: d.content_type }));
            var a = document.createElement('a');
            a.href = url;
            a.download = d.filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
        }).catch(function(err){
            exportBtn.disabled = false;
            var message = (err && err.message) || 'Your calendar could not be downloaded.';
            if (err && err.data && err.data.locked && window.JoineryVaultLock) {
                JoineryVaultLock.unlock().then(function(ok){
                    if (ok) { downloadCalendar(); } else { showExportError(message, null); }
                });
                return;
            }
            showExportError(message, err);
        });
    }
    function showExportError(message, err){
        exportErr.textContent = message;
        var link = err ? joineryApi.reportLink(err) : null;
        if (link) { exportErr.appendChild(document.createTextNode(' ')); exportErr.appendChild(link); }
        exportErr.hidden = false;
    }
    if (exportBtn && exportErr) {
        exportBtn.addEventListener('click', function(){
            var menu = exportBtn.closest('details');
            if (menu) { menu.open = false; }
            downloadCalendar();
        });
    }

    // =========================================================================
    // Scope choice — "this occurrence / this and future / all". One builder
    // serves the save dialog, the edit dialog's Delete… and the popover's
    // trash on a recurring occurrence. Returns the content node for
    // JoineryModal.open; read the choice with scopeChosen(node).
    // =========================================================================
    function scopeChoice(heading, date, withThis){
        var wrap = document.createElement('div');
        var h = document.createElement('h3'); h.className = 'cal-scope-heading'; h.textContent = heading; wrap.appendChild(h);
        var opts = document.createElement('div'); opts.className = 'cal-scope-options'; wrap.appendChild(opts);
        var choices = [];
        if (withThis) {
            choices.push(['this',   'This occurrence only',          'Just ' + date + '; other occurrences stay the same.']);
            choices.push(['future', 'This and future occurrences',   'From ' + date + ' onward.']);
        }
        choices.push(['all', 'All occurrences', 'Every occurrence in this series.']);
        choices.forEach(function(c, i){
            var label = document.createElement('label');
            var input = document.createElement('input'); input.type = 'radio'; input.name = 'scope_choice'; input.value = c[0]; input.checked = (i === 0);
            var span = document.createElement('span'); span.textContent = c[1];
            var small = document.createElement('small'); small.textContent = c[2]; span.appendChild(small);
            label.appendChild(input); label.appendChild(span); opts.appendChild(label);
        });
        return wrap;
    }
    function scopeChosen(node){
        var chosen = node.querySelector('input[name="scope_choice"]:checked');
        return chosen ? chosen.value : '';
    }

    // =========================================================================
    // Edit dialog — the full form, opened on load whenever the page carries it.
    // Closing it (Cancel, Esc) returns to the grid on the entry's month.
    // =========================================================================
    var formDialog = document.getElementById('cal-form-dialog');
    var fullForm   = document.getElementById('form1');
    var backUrl    = CAL.back_url || '/profile/calendar';

    if (formDialog) {
        formDialog.addEventListener('close', function(){ window.location.href = backUrl; });
        var cancelBtn = document.getElementById('cal-form-cancel');
        if (cancelBtn) { cancelBtn.addEventListener('click', function(){ formDialog.close(); }); }
        // A locked entry's dialog offers the unlock in place of the form; once
        // the window is open the page reloads with the form filled in.
        var formUnlock = document.getElementById('cal-form-unlock');
        if (formUnlock) {
            formUnlock.addEventListener('click', function(){
                if (!window.JoineryVaultLock) { window.location.href = '/profile/security'; return; }
                formUnlock.disabled = true;
                JoineryVaultLock.unlock().then(function(ok){
                    if (ok) { window.location.reload(); } else { formUnlock.disabled = false; }
                });
            });
        }
        // A click on the backdrop is Cancel. The backdrop reports the dialog
        // itself as the target, so the test is "outside the dialog's box" —
        // a click in its padding stays put. A question stacked on top (the
        // kit dialog) owns its own backdrop, so this never fires under it.
        formDialog.addEventListener('click', function(ev){
            if (ev.target !== formDialog) { return; }
            var r = formDialog.getBoundingClientRect();
            var inside = ev.clientX >= r.left && ev.clientX <= r.right && ev.clientY >= r.top && ev.clientY <= r.bottom;
            if (!inside) { formDialog.close(); }
        });
        formDialog.showModal();
    }

    // Occurrence edit: the first submit is stopped here and the question asked
    // on top; the choice fills the hidden scope field and requestSubmit() runs
    // the real submit (validator included). The submit event fires again on
    // that re-submit, and again on the validator's own re-dispatch, so the
    // guard is the scope field already holding a value.
    var scopeField = document.getElementById('cal-scope-field');
    var occDate    = CAL.occurrence_date || '';

    if (scopeField && fullForm) {
        fullForm.addEventListener('submit', function(ev){
            if (scopeField.value) { return; }
            ev.preventDefault();   // the kit validator stands aside once a listener owns the submit
            var content = scopeChoice('Save changes to…', occDate, true);
            JoineryModal.open(content, {
                buttons: [
                    { label: 'Cancel', style: 'secondary' },
                    { label: 'Save', style: 'primary', onClick: function(){
                        var v = scopeChosen(content);
                        if (!v) { return false; }
                        scopeField.value = v;
                        fullForm.requestSubmit();
                    } }
                ]
            });
        });
    }

    // Delete… on a recurring entry: which occurrences.
    var delScopeField = document.getElementById('del-scope-field');
    var delRecBtn     = document.getElementById('btn-delete-rec');

    if (delRecBtn && delScopeField) {
        delRecBtn.addEventListener('click', function(ev){
            ev.preventDefault();
            var content = scopeChoice('Delete…', occDate, !!occDate);
            JoineryModal.open(content, {
                buttons: [
                    { label: 'Cancel', style: 'secondary' },
                    { label: 'Delete', style: 'danger', onClick: function(){
                        var v = scopeChosen(content);
                        if (!v) { return false; }
                        delScopeField.value = v;
                        var delForm = document.getElementById('delform');
                        if (delForm) { delForm.requestSubmit(); }
                    } }
                ]
            });
        });
    }

    // The full-form all-day toggle and the entire recurrence section are
    // declarative FormWriter inputs driven by visibility_rules — no toggle JS,
    // and "after N occurrences" is converted to an end date server-side
    // (CalendarEntry::nth_occurrence_date). The popover below is a separate
    // surface and keeps its own lightweight all-day handling.
    // =========================================================================
    // Popover — view mode for an existing entry, create mode for an empty day
    // =========================================================================
    var popup    = document.getElementById('cal-popup');
    var titleEl  = document.getElementById('cal-popup-title');
    var errEl    = document.getElementById('cal-popup-error');
    var editBtn  = document.getElementById('cal-popup-edit');
    var deleteBtn= document.getElementById('cal-popup-delete');
    var moreBtn  = document.getElementById('cal-popup-more');
    var closeBtn = document.getElementById('cal-popup-close');
    var form     = document.getElementById('cal-pop-form');
    var viewEl   = document.getElementById('cal-popup-view');
    var timesEl  = document.getElementById('cal-pop-times');
    var allDayEl = form ? form.querySelector('[name="entry_all_day"]') : null;
    var viewed   = null;   // the feed item currently shown in view mode

    if (form) { form.submit = function(){}; }

    function parseUTCDate(s){ return s ? new Date(String(s).replace(' ','T')+'Z') : null; }

    function utcToUserTz(d){
        var fmt = new Intl.DateTimeFormat('en-CA', {
            timeZone: USER_TZ,
            year:'numeric',month:'2-digit',day:'2-digit',
            hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false
        });
        var parts = fmt.formatToParts(d), p = {};
        parts.forEach(function(x){ p[x.type] = x.value; });
        var h = p.hour === '24' ? '00' : p.hour;
        return {date:p.year+'-'+p.month+'-'+p.day, time:h+':'+p.minute+':'+p.second};
    }
    function fmtDay(d){ return new Intl.DateTimeFormat([], {timeZone: USER_TZ, weekday:'short', month:'short', day:'numeric'}).format(d); }
    function fmtClock(d, tz){ return new Intl.DateTimeFormat([], {timeZone: tz || USER_TZ, hour:'numeric', minute:'2-digit'}).format(d); }

    // "9:00 AM – 10:00 AM in Los Angeles (PDT)" — only when the entry was
    // written for a zone other than the viewer's, and only for timed entries
    // (an all-day entry has no clock to shift).
    function zoneText(it){
        if (!it.timezone || it.timezone === USER_TZ || it.all_day) { return ''; }
        var s = parseUTCDate(it.start), e = parseUTCDate(it.end);
        if (!s) { return ''; }
        var abbr = '';
        try {
            var parts = new Intl.DateTimeFormat('en-US', {timeZone: it.timezone, timeZoneName: 'short'}).formatToParts(s);
            parts.forEach(function(x){ if (x.type === 'timeZoneName') { abbr = x.value; } });
            var text = fmtClock(s, it.timezone) + (e ? ' \u2013 ' + fmtClock(e, it.timezone) : '');
        } catch (err) { return ''; }   // a zone this browser does not know
        var city = String(it.timezone).split('/').pop().replace(/_/g, ' ');
        return text + ' in ' + city + (abbr ? ' (' + abbr + ')' : '');
    }

    // "Tue, Sep 22 · 9:00 AM – 10:00 AM", "Tue, Sep 22 · All day",
    // "Tue, Sep 22 – Thu, Sep 24 · All day" (all-day end is exclusive).
    function whenText(it){
        var s = parseUTCDate(it.start), e = parseUTCDate(it.end);
        if (!s) { return ''; }
        if (it.all_day) {
            var last = e ? new Date(e.getTime() - 60000) : s;
            var a = fmtDay(s), b = fmtDay(last);
            return (a === b ? a : a + ' – ' + b) + ' · All day';
        }
        var sd = fmtDay(s), text = sd + ' · ' + fmtClock(s);
        if (e) { text += ' – ' + (fmtDay(e) === sd ? '' : fmtDay(e) + ' ') + fmtClock(e); }
        return text;
    }

    function positionPopup(rect){
        popup.style.visibility = 'hidden'; popup.style.display = 'block';
        var pw=popup.offsetWidth, ph=popup.offsetHeight, vpW=window.innerWidth, vpH=window.innerHeight;
        var left=rect.left, top=rect.bottom+8;
        if(left+pw>vpW-8) left=vpW-pw-8; if(left<8) left=8;
        if(top+ph>vpH-8) top=Math.max(8,rect.top-ph-8); if(top<8) top=8;
        popup.style.left=left+'px'; popup.style.top=top+'px'; popup.style.visibility='visible';
    }

    function syncAllDay(){
        if(!allDayEl||!timesEl) return;
        timesEl.classList.toggle('is-allday', allDayEl.checked);
    }

    function setField(name,val){
        var el=form?form.querySelector('[name="'+name+'"]'):null;
        if(!el) return;
        if(el.type==='checkbox'){ el.checked=!!val; } else { el.value=val; }
    }
    function getField(name){
        var el=form?form.querySelector('[name="'+name+'"]'):null;
        if(!el) return '';
        if(el.type==='checkbox') return el.checked?'1':'';
        return el.value;
    }
    // err: the rejected call; a recorded server error adds a "Report a problem" link.
    function showError(msg, err){
        errEl.textContent=msg;
        var link=joineryApi.reportLink(err);
        if(link){ errEl.appendChild(document.createTextNode(' ')); errEl.appendChild(link); }
        errEl.style.display='block';
    }
    function clearError(){ errEl.textContent=''; errEl.style.display='none'; }

    function setLine(id, text){
        var el = document.getElementById(id);
        el.textContent = text || '';
        el.hidden = !text;
    }

    // Where an edit happens: the page reloads with the entry in the URL and
    // the full form opens in its dialog.
    function editUrl(it){
        return it.occurrence_date
            ? '/profile/calendar/entry/' + encodeURIComponent(it.entry_id) + '/occurrence/' + encodeURIComponent(it.occurrence_date)
            : '/profile/calendar?edit_entry=' + encodeURIComponent(it.entry_id);
    }

    function openView(it, rect){
        clearError();
        viewed = it;
        titleEl.textContent = it.title || 'Busy';
        setLine('cal-popup-when', whenText(it));
        setLine('cal-popup-zone', zoneText(it));
        setLine('cal-popup-repeats', '');
        setLine('cal-popup-location', it.location || '');
        setLine('cal-popup-notes', '');
        var linkEl = document.getElementById('cal-popup-link');
        linkEl.textContent = '';
        if (it.link) {
            var a = document.createElement('a'); a.href = it.link; a.target = '_blank'; a.rel = 'noopener'; a.textContent = it.link;
            linkEl.appendChild(a);
        }
        linkEl.hidden = !it.link;
        var lockedEl = document.getElementById('cal-popup-locked');
        lockedEl.hidden = !it.locked;
        viewEl.hidden = false; form.hidden = true;
        // A locked entry shows its time and the unlock; editing waits for the window.
        editBtn.hidden = !!it.locked; deleteBtn.hidden = !!it.locked;
        positionPopup(rect);
        if (it.locked) { return; }
        // Repeats + notes stay off the feed; fetch them for the one entry shown.
        joineryApi.post('calendar_entry', {entry_id: it.entry_id}).then(function(data){
            var e = data && data.entry;
            if (!e || viewed !== it) { return; }
            setLine('cal-popup-repeats', e.recurrence_description || '');
            setLine('cal-popup-notes', e.notes || '');
            positionPopup(rect);
        }).catch(function(){ /* the feed already told the reader what this is */ });
    }

    function openCreate(opts, rect){
        clearError();
        viewed = null;
        titleEl.textContent = 'New entry';
        setField('action', 'save'); setField('entry_id', '');
        setField('entry_title', ''); setField('entry_date', opts.date||'');
        setField('entry_all_day', true);
        setField('entry_start', ''); setField('entry_end', '');
        setField('entry_blocks', false);
        setField('entry_location', '');
        syncAllDay();
        viewEl.hidden = true; form.hidden = false;
        editBtn.hidden = true; deleteBtn.hidden = true;
        positionPopup(rect);
        var ti = form.querySelector('[name="entry_title"]');
        if(ti){ ti.focus(); ti.select(); }
    }

    function closePopup(){ popup.style.display='none'; viewed = null; clearError(); }

    if(!popup||!form) return;

    // The unlock, from a locked entry's popover: the platform's one ceremony,
    // then the grid redraws from the feed (the lock-chip event below).
    var popUnlock = document.getElementById('cal-popup-unlock');
    if (popUnlock) {
        popUnlock.addEventListener('click', function(){
            if (!window.JoineryVaultLock) { window.location.href = '/profile/security'; return; }
            popUnlock.disabled = true;
            JoineryVaultLock.unlock().then(function(){ popUnlock.disabled = false; });
        });
    }
    // An unlock or lock made anywhere on the page (the lock chip, the popover)
    // changes what the feed may show: redraw the grid and drop a stale popover.
    ['joinery:vault-unlocked', 'joinery:vault-locked'].forEach(function(name){
        document.addEventListener(name, function(){
            closePopup();
            window.dispatchEvent(new CustomEvent('calendarentrychanged'));
        });
    });

    // A level change that stopped part-way (the tab closed mid-way) carries
    // on here, quietly; the grid redraws once it has.
    var levelRemaining = CAL.level_remaining | 0;
    function convergeLevel(){
        if (levelRemaining <= 0) { return; }
        joineryApi.post('calendar_level_batch', {}).then(function(d){
            levelRemaining = (d && d.remaining) | 0;
            if (levelRemaining > 0 && ((d && d.converted) | 0) > 0) { convergeLevel(); return; }
            window.dispatchEvent(new CustomEvent('calendarentrychanged'));
        }).catch(function(){ /* a closed window or a stuck row: the deferred work carries on */ });
    }
    convergeLevel();

    if(allDayEl) allDayEl.addEventListener('change', syncAllDay);
    closeBtn.addEventListener('click', closePopup);
    document.addEventListener('mousedown', function(ev){
        if(popup.style.display==='block'&&!popup.contains(ev.target)) closePopup();
    });
    document.addEventListener('keydown', function(ev){ if(ev.key==='Escape') closePopup(); });

    form.addEventListener('submit', function(ev){
        ev.preventDefault();
        var parsed = titleInputEl ? parseTimePrefix(titleInputEl.value.trim()) : null;
        if(parsed) applyParsedTime(parsed);
        clearError();
        var saveBtn=form.querySelector('.cal-popup-save');
        if(saveBtn){ saveBtn.disabled=true; saveBtn.textContent='Saving…'; }
        // Map the popover fields onto the calendar_entry_save action's names.
        // all_day is sent only when checked; blocks is always sent, because
        // the action treats a missing flag as true and the box starts unchecked.
        var blocksEl = form.querySelector('[name="entry_blocks"]');
        var body = {
            entry_id:   getField('entry_id'),
            date:       getField('entry_date'),
            title:      getField('entry_title'),
            start_time: getField('entry_start'),
            end_time:   getField('entry_end'),
            blocks:     !!(blocksEl && blocksEl.checked),
            // Location only: link and notes live under "More options", and
            // the save action touches only the fields it is sent.
            location:   getField('entry_location')
        };
        if (allDayEl && allDayEl.checked) { body.all_day = true; }
        if(parsed){
            body.title = parsed.title;
            body.start_time = pad2(parsed.h)+':'+pad2(parsed.min);
            body.end_time   = pad2(parsed.h+1<24?parsed.h+1:23)+':'+pad2(parsed.h+1<24?parsed.min:59);
            delete body.all_day;
        }
        joineryApi.post('calendar_entry_save', body)
        .then(function(){
            if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save';}
            closePopup(); window.dispatchEvent(new Event('calendarentrychanged'));
        })
        .catch(function(err){
            if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save';}
            showError(err.message||'Save failed. Please try again.', err);
        });
    });

    editBtn.addEventListener('click', function(){
        if (viewed) { window.location.href = editUrl(viewed); }
    });

    deleteBtn.addEventListener('click', function(){
        var it = viewed;
        if(!it || !it.entry_id) return;
        var done = function(){ closePopup(); window.dispatchEvent(new Event('calendarentrychanged')); };
        var fail = function(err){ showError(err.message||'Delete failed.', err); };
        if (it.occurrence_date) {
            var content = scopeChoice('Delete…', it.occurrence_date, true);
            JoineryModal.open(content, {
                buttons: [
                    { label: 'Cancel', style: 'secondary' },
                    { label: 'Delete', style: 'danger', onClick: function(){
                        var v = scopeChosen(content);
                        if (!v) { return false; }
                        joineryApi.post('calendar_entry_delete', {entry_id: it.entry_id, scope: v, occurrence_date: it.occurrence_date}).then(done).catch(fail);
                    } }
                ]
            });
            return;
        }
        JoineryModal.confirm('Delete this entry?', function(){
            joineryApi.post('calendar_entry_delete', {entry_id: it.entry_id}).then(done).catch(fail);
        },{confirmLabel:'Delete'});
    });

    moreBtn.addEventListener('click', function(){
        window.location.href = '/profile/calendar?d=' + encodeURIComponent(getField('entry_date'));
    });

    // Day cell click → create popover
    document.addEventListener('calendardayclick', function(ev){
        openCreate({date:ev.detail.date}, ev.detail.targetRect);
    });

    // Native chip click → view popover
    document.addEventListener('calendarchipclick', function(ev){
        openView(ev.detail.item || {}, ev.detail.targetRect);
    });

    function parseTimePrefix(str){
        var m=str.match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s+(.+)$/i);
        if(m){ var h=parseInt(m[1],10),min=m[2]?parseInt(m[2],10):0,ap=m[3].toLowerCase();
               if(ap==='pm'&&h!==12)h+=12; if(ap==='am'&&h===12)h=0;
               if(h>23||min>59) return null; return{h:h,min:min,title:m[4].trim()}; }
        var m2=str.match(/^([01]?\d|2[0-3]):([0-5]\d)\s+(.+)$/);
        if(m2) return{h:parseInt(m2[1],10),min:parseInt(m2[2],10),title:m2[3].trim()};
        return null;
    }
    function pad2(n){ return String(n).padStart(2,'0'); }
    function applyParsedTime(parsed){
        var eh=parsed.h+1,em=parsed.min; if(eh>=24){eh=23;em=59;}
        setField('entry_title',parsed.title); setField('entry_start',pad2(parsed.h)+':'+pad2(parsed.min));
        setField('entry_end',  pad2(eh)+':'+pad2(em)); setField('entry_all_day',false); syncAllDay();
    }

    var titleInputEl = form ? form.querySelector('[name="entry_title"]') : null;

    // Quick-add as you type: once the title reads "1pm dentist" / "13:00
    // dentist", the time moves into the Start/End fields, All day comes off,
    // and the title keeps just the words. The submit handler parses again, so
    // a pasted title still works.
    if (titleInputEl) {
        titleInputEl.addEventListener('input', function(){
            var parsed = parseTimePrefix(titleInputEl.value.trim());
            if (parsed) { applyParsedTime(parsed); }
        });
    }

})();
