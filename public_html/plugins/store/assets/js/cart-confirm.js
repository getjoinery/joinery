/*
 * The order confirmation page (views/cart_confirm.php): a [data-cc-survey]
 * button sends its event survey's answers.
 *
 * @version 1.1 - the button waits while the answers go, and a failed send says so
 * @version 1.0
 */
function submitConfirmSurvey(surveyId, eventId) {
    var form = document.getElementById('survey-form-' + surveyId);
    if (!form) return;

    // Collect all question answers from the form. Inputs named "field[]"
    // (checkbox lists) accumulate into a JSON array under "field" — the same
    // shape PHP produced when this was a form-encoded POST.
    var inputs = form.querySelectorAll('input, select, textarea');
    var payload = { survey_id: surveyId, event_id: eventId };

    inputs.forEach(function(input) {
        if (!input.name) return;
        var isList = input.name.slice(-2) === '[]';
        var key = isList ? input.name.slice(0, -2) : input.name;
        if (isList) {
            if (!(key in payload)) payload[key] = [];
            if ((input.type !== 'checkbox' && input.type !== 'radio') || input.checked) {
                payload[key].push(input.value);
            }
        } else if (input.type === 'checkbox') {
            payload[key] = input.checked ? (input.value || '1') : '';
        } else if (input.type === 'radio') {
            if (input.checked) payload[key] = input.value;
        } else {
            payload[key] = input.value;
        }
    });

    var btn = form.querySelector('[data-cc-survey]');
    if (btn) btn.disabled = true;
    joineryApi.post('event_manager/checkout_submit_survey', payload)
        .then(function() {
            document.getElementById('survey-form-' + surveyId).hidden = true;
            document.getElementById('survey-thanks-' + surveyId).hidden = false;
        })
        .catch(function(err) {
            if (btn) btn.disabled = false;
            var note = form.querySelector('.jy-cc-survey-error');
            if (!note) {
                note = document.createElement('p');
                note.className = 'jy-cc-survey-error';
                note.setAttribute('role', 'alert');
                form.appendChild(note);
            }
            note.textContent = 'Your feedback was not sent' + ((err && err.message) ? ': ' + err.message : '.') + ' Please try again.';
        });
}
document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-cc-survey]') : null;
    if (!b) return;
    submitConfirmSurvey(parseInt(b.getAttribute('data-cc-survey'), 10) || 0, parseInt(b.getAttribute('data-cc-event'), 10) || 0);
});
