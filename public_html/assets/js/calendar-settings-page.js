/*
 * /profile/calendar_settings: the protection level cards and the rest of the
 * page's controls (views/profile/calendar_settings.php).
 *
 * #calendar-settings-config: { protection_level, level_remaining }.
 *
 * @version 1.0
 */
(function(){
    // ----- Protection level -----
    // A card click changes the level at once (ProtectionLevelChange: the
    // promise flips first), with a passkey confirmation when the account has
    // a second factor, then the entries converge in bounded batches. Lowering
    // needs the vault window; the one unlock ceremony opens it and the change
    // runs again.
    var levelForm = document.getElementById('cal-level-form');
    var levelAlert = document.getElementById('cal-level-alert');
    var CS = (window.joineryPageData && joineryPageData('calendar-settings-config')) || {};
    var current = CS.protection_level;
    var levelRemaining = CS.level_remaining | 0;
    function levelNote(msg, kind) {
        levelAlert.hidden = !msg;
        levelAlert.className = 'alert alert-' + (kind || 'info');
        levelAlert.textContent = msg || '';
    }
    function setRadios(level) {
        if (!levelForm) { return; }
        Array.prototype.forEach.call(levelForm.querySelectorAll('[name="protection_level"]'), function(r){ r.checked = (r.value === level); });
    }
    function converge(remaining, done) {
        if (remaining <= 0) { done(0); return; }
        joineryApi.post('calendar_level_batch', {}).then(function(d){
            var left = (d && d.remaining) | 0;
            if (left > 0 && ((d && d.converted) | 0) > 0) { converge(left, done); return; }
            done(left);
        }).catch(function(err){
            var left = (err && err.data && err.data.remaining) | 0;
            if (err && err.data && err.data.locked) {
                levelNote('Unlock your vault to finish converting your calendar.', 'warning');
            }
            done(left || remaining);
        });
    }
    function changeLevel(level, retried) {
        levelNote('Changing your calendar’s protection…', 'info');
        var call = function(){ return joineryApi.post('calendar_level_change', { level: level }); };
        var run = window.JoineryPasskeys && window.JoineryPasskeys.withStepUp
            ? window.JoineryPasskeys.withStepUp(call, location.pathname + location.search)
            : call();
        run.then(function(d){
            current = (d && d.level) || level;
            setRadios(current);
            var remaining = (d && d.remaining) | 0;
            if (d && d.locked && remaining > 0) {
                levelNote('Your calendar is ' + current.charAt(0).toUpperCase() + current.slice(1)
                    + '. Unlock your vault to finish converting ' + remaining + ' entr' + (remaining === 1 ? 'y' : 'ies') + '.', 'warning');
                return;
            }
            if (remaining > 0) { levelNote('Converting ' + remaining + ' entr' + (remaining === 1 ? 'y' : 'ies') + '…', 'info'); }
            converge(remaining, function(left){
                if (left > 0) {
                    if (levelAlert.hidden) {
                        levelNote(left + ' entr' + (left === 1 ? 'y' : 'ies') + ' could not be converted yet. They are tried again the next time you are here with your vault unlocked.', 'warning');
                    }
                    return;
                }
                levelNote('Your calendar is now ' + current.charAt(0).toUpperCase() + current.slice(1) + '.', 'success');
            });
        }).catch(function(err){
            var msg = (err && err.message) || 'The change could not be made.';
            // Lowering needs the window: one unlock, then the same change again.
            if (!retried && window.JoineryVaultLock && /unlock your vault/i.test(msg)) {
                JoineryVaultLock.unlock().then(function(ok){
                    if (ok) { changeLevel(level, true); } else { setRadios(current); levelNote(msg, 'danger'); }
                });
                return;
            }
            setRadios(current);
            levelNote(msg, 'danger');
        });
    }
    if (levelForm) {
        Array.prototype.forEach.call(levelForm.querySelectorAll('[name="protection_level"]'), function(radio){
            radio.addEventListener('change', function(){
                if (radio.checked && radio.value !== current) { changeLevel(radio.value, false); }
            });
        });
        // A change that stopped part-way (the tab closed) carries on here.
        if (levelRemaining > 0) {
            levelNote('Finishing an earlier protection change — ' + levelRemaining + ' entr' + (levelRemaining === 1 ? 'y' : 'ies') + ' left…', 'info');
            converge(levelRemaining, function(left){
                if (left === 0) { levelNote('Your calendar is now ' + current.charAt(0).toUpperCase() + current.slice(1) + '.', 'success'); }
            });
        }
    }

    // ----- Email preferences -----
    var form  = document.getElementById('cal-settings-form');
    var alertBox = document.getElementById('cal-settings-alert');
    if (!form) { return; }

    function note(msg, ok) {
        alertBox.hidden = false;
        alertBox.className = ok ? 'alert alert-success' : 'alert alert-danger';
        alertBox.textContent = msg;
    }

    form.addEventListener('submit', function(ev){
        ev.preventDefault();
        var btn = form.querySelector('[type="submit"]');
        if (btn) { btn.disabled = true; }
        joineryApi.post('calendar_settings', {
            action:                   'save',
            summary_frequency:        (form.querySelector('[name="summary_frequency"]') || {}).value || 'none',
            summary_hour:             (form.querySelector('[name="summary_hour"]') || {}).value || '7',
            reminder_default_minutes: (form.querySelector('[name="reminder_default_minutes"]') || {}).value || '0'
        })
        .then(function(){
            if (btn) { btn.disabled = false; }
            note('Your calendar preferences have been saved.', true);
        })
        .catch(function(err){
            if (btn) { btn.disabled = false; }
            note((err && err.message) || 'Save failed. Please try again.', false);
        });
    });
})();
